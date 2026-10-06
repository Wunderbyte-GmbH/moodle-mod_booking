<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Engine-agnostic embeddings store contract (Layer 0 of the retrieval foundation).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\retrieval;

/**
 * The single retrieval/persistence contract shared by all embedding areas (docs, skills and,
 * later, site content) and every backend (CSV today, DB next, ANN later).
 *
 * Design rules this contract encodes (see the retrieval-foundation blueprint):
 *  - {@see search_top_k()} is THE public retrieval method; the cosine-vs-ANN choice lives behind it
 *    per area, so a server-side ANN implementation can be swapped in without touching any caller.
 *  - Every row/query carries a retrieval_filter: docs/skills pass null (global); site
 *    content narrows by allowed context ids. Resolving the filter is NOT a permission grant — the
 *    caller still applies the authoritative per-document access check.
 *  - Rebuilds are atomic via a generation swap: write a new generation, then commit it; readers only
 *    ever see the committed generation, never a half-built one.
 *
 * A variant is the (model, dimensions) pair — embeddings for different models live side by side and a
 * model switch never invalidates the others.
 */
interface embeddings_store {
    // -------------------------------------------------------------------------
    // Retrieval — the ANN-swap seam.

    /**
     * Return the top-k rows for one area/variant, already scored (cosine) and above the minimum score.
     *
     * Resolves the committed generation for (area, model, dims) internally and searches only within it.
     * The default (CSV/DB) implementation streams rows and scores in PHP; an ANN-backed area overrides
     * this with a server-side top-k. Never returns the raw vector.
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @param float[] $queryvector
     * @param int $k
     * @param float $minscore
     * @param retrieval_filter|null $filter Access/context narrowing; null = no narrowing (global).
     * @return embedding_hit[] Descending by score.
     */
    public function search_top_k(
        string $area,
        string $emodel,
        int $edims,
        array $queryvector,
        int $k,
        float $minscore,
        ?retrieval_filter $filter = null
    ): array;

    // -------------------------------------------------------------------------
    // Presence / readiness.

    /**
     * Whether a committed index exists for this area/variant.
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @return bool
     */
    public function exists(string $area, string $emodel, int $edims): bool;

    /**
     * Number of committed rows for this area/variant.
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @return int
     */
    public function count_rows(string $area, string $emodel, int $edims): int;

    /**
     * Committed row counts grouped by owner (e.g. per content area of the site_content store).
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @return array<string,int> owner => committed row count
     */
    public function count_rows_by_owner(string $area, string $emodel, int $edims): array;

    /**
     * Read the stored source fingerprint the index was last built from (empty when unknown).
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @return string
     */
    public function fingerprint(string $area, string $emodel, int $edims): string;

    /**
     * Store the source fingerprint the index was just built from.
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @param string $fingerprint
     * @return void
     */
    public function set_fingerprint(string $area, string $emodel, int $edims, string $fingerprint): void;

    // -------------------------------------------------------------------------
    // Rebuild — atomic generation swap.

    /**
     * Open a new (uncommitted) generation for this area/variant and return its id.
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @return int
     */
    public function begin_generation(string $area, string $emodel, int $edims): int;

    /**
     * Add one row to an open generation.
     *
     * @param string $area
     * @param int $generation
     * @param embedding_row $row
     * @return void
     */
    public function upsert(string $area, int $generation, embedding_row $row): void;

    /**
     * Return an existing committed row by its identity key (for hash-based reuse on rebuild), or null.
     *
     * The caller compares the returned row's content hash to decide whether to reuse it (skip re-embed)
     * or embed afresh.
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @param string $key Identity key as produced by the area's row mapper.
     * @return embedding_row|null
     */
    public function reuse_existing(string $area, string $emodel, int $edims, string $key): ?embedding_row;

    /**
     * Commit an open generation: make it the active one for this area/variant and prune older ones.
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @param int $generation
     * @return int Number of committed rows.
     */
    public function commit_generation(string $area, string $emodel, int $edims, int $generation): int;

    /**
     * Discard an open, uncommitted generation without publishing it.
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @param int $generation
     * @return void
     */
    public function discard_generation(string $area, string $emodel, int $edims, int $generation): void;

    // -------------------------------------------------------------------------
    // Incremental document writes — strictly incremental site indexing (DB-only).
    //
    // These operations write into the currently COMMITTED generation (unlike the generation swap
    // above, which is reserved for initial builds / repairs and the docs/skills areas). Generation
    // bootstrap: when no meta row exists yet for the variant, one is created with
    // committedgeneration = 1 before writing — otherwise search_top_k (which scans
    // WHERE generation = committed and returns [] while committed <= 0) would never see the
    // incrementally written rows. The CSV backend throws on all of them (fail-closed, DB-only).

    /**
     * Replace one document's chunk set in the committed generation (doc-atomic, diff-based).
     *
     * $rows is the document's COMPLETE new chunk set (refindex = chunk number). The implementation
     * diffs it against the document's existing committed rows per (refindex, contenthash):
     * an identical chunk leaves the stored row physically untouched (same DB id), a changed chunk is
     * updated in place, a new chunk number is inserted and a vanished chunk number is deleted.
     * Runs inside one transaction per document. An empty $rows removes every chunk of the document.
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @param string $owner Area id the document belongs to (docid is only unique per area).
     * @param string $docid Document id within the owner area.
     * @param embedding_row[] $rows The document's complete new chunk set.
     * @return array Write stats: ['inserted' => int, 'updated' => int, 'deleted' => int, 'kept' => int].
     */
    public function replace_document(
        string $area,
        string $emodel,
        int $edims,
        string $owner,
        string $docid,
        array $rows
    ): array;

    /**
     * Delete every chunk of one document (events path: the source item was deleted).
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @param string $owner Area id the document belongs to (docid is only unique per area).
     * @param string $docid Document id within the owner area.
     * @return void
     */
    public function delete_document(string $area, string $emodel, int $edims, string $owner, string $docid): void;

    /**
     * Delete every row of one owner (sub-area) — the clean "disable = prune" path, context-independent.
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @param string $owner Area id (e.g. a core_search area) whose rows are removed.
     * @return void
     */
    public function delete_owner(string $area, string $emodel, int $edims, string $owner): void;

    /**
     * Delete the rows of ONE owner (sub-area) within ONE course — the prune op of the scope
     * governance delta sync (context-governance blueprint §4.2): {@see delete_by_course()} is
     * area-crossing and too coarse for a per-area rule flip, {@see delete_owner()} is
     * course-crossing.
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @param string $owner Area id (e.g. a core_search area) whose rows are removed.
     * @param int $courseid Course whose rows of that owner are removed.
     * @return void
     */
    public function delete_owner_in_course(string $area, string $emodel, int $edims, string $owner, int $courseid): void;

    // -------------------------------------------------------------------------
    // Enumeration — diagnostics / rebuild source (NOT the retrieval path; use search_top_k for that).

    /**
     * Yield each committed row for this area/variant, one at a time (bounded memory).
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @return \Generator
     */
    public function stream_rows(string $area, string $emodel, int $edims): \Generator;

    // -------------------------------------------------------------------------
    // Invalidation.

    /**
     * Delete all rows for a context (course/module deleted).
     *
     * Applies across ALL areas; docs/skills rows carry a null context id and are therefore never
     * matched, so this only ever affects site content.
     *
     * @param int $contextid
     * @return void
     */
    public function delete_by_context(int $contextid): void;

    /**
     * Delete all rows for a course (course deleted / course content reset).
     *
     * Mirror of {@see delete_by_context()}, needed by the course_deleted observer: embedding rows
     * carry MODULE context ids, but a course_deleted event only provides the COURSE context — by the
     * time it fires, the per-module contexts are no longer enumerable, so matching on contextid would
     * miss every module row. Rows also carry a courseid column, which this matches instead. Applies
     * across ALL areas; docs/skills rows carry a null course id and are therefore never matched.
     *
     * @param int $courseid
     * @return void
     */
    public function delete_by_course(int $courseid): void;
}
