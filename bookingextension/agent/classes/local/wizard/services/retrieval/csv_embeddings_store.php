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
 * CSV-backed implementation of the embeddings store contract.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\retrieval;

use bookingextension_agent\local\wizard\services\embeddings\embeddings_retrieval_service;

/**
 * Fulfils {@see embeddings_store} by delegating to the existing variant-scoped CSV repositories,
 * one per area (docs, skills). Behaviour is identical to the direct CSV path: retrieval streams the
 * variant file through the same cosine service, and a rebuild maps onto the repository's atomic,
 * round-trip-verified streaming write (which is the CSV equivalent of a generation swap).
 *
 * The DB store (Phase 1) implements the same interface; callers never change.
 */
class csv_embeddings_store implements embeddings_store {
    /** @var embeddings_row_mapper[] Area => mapper. */
    private array $mappers;

    /** @var embeddings_retrieval_service Streaming cosine engine. */
    private embeddings_retrieval_service $retrieval;

    /** @var array Per-area open rebuild state (repo + mapper + lazy reuse index), keyed by area. */
    private array $rebuild = [];

    /**
     * Constructor.
     *
     * @param embeddings_row_mapper[] $mappers Per-area mappers, keyed by area.
     * @param embeddings_retrieval_service|null $retrieval Injectable cosine service.
     */
    public function __construct(array $mappers, ?embeddings_retrieval_service $retrieval = null) {
        $this->mappers = $mappers;
        $this->retrieval = $retrieval ?? new embeddings_retrieval_service();
    }

    /**
     * The mapper for an area.
     *
     * @param string $area
     * @return embeddings_row_mapper
     */
    private function mapper(string $area): embeddings_row_mapper {
        if (!isset($this->mappers[$area])) {
            throw new \coding_exception('csv_embeddings_store: unknown embeddings area "' . $area . '".');
        }
        return $this->mappers[$area];
    }

    /**
     * Return the top-k rows for one area/variant, scored by cosine and above the minimum score.
     *
     * The CSV backend serves only global areas (docs/skills); the filter is accepted for interface
     * parity but not applied here (it drives the SQL pre-narrowing of the DB/site backend).
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @param float[] $queryvector
     * @param int $k
     * @param float $minscore
     * @param retrieval_filter|null $filter
     * @return embedding_hit[]
     */
    public function search_top_k(
        string $area,
        string $emodel,
        int $edims,
        array $queryvector,
        int $k,
        float $minscore,
        ?retrieval_filter $filter = null
    ): array {
        $mapper = $this->mapper($area);
        $repo = $mapper->repo_for_variant($emodel, $edims);
        $toprows = $this->retrieval->search_top_k_streaming($queryvector, $repo->stream_rows(), max(1, $k));

        $hits = [];
        foreach ($toprows as $row) {
            $score = (float)($row['score'] ?? 0.0);
            if ($minscore > 0.0 && $score < $minscore) {
                continue;
            }
            $hits[] = $mapper->to_hit($row, $score);
        }
        return $hits;
    }

    /**
     * Whether a committed index exists for this area/variant.
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @return bool
     */
    public function exists(string $area, string $emodel, int $edims): bool {
        return $this->mapper($area)->repo_for_variant($emodel, $edims)->exists();
    }

    /**
     * Number of committed rows for this area/variant.
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @return int
     */
    public function count_rows(string $area, string $emodel, int $edims): int {
        $repo = $this->mapper($area)->repo_for_variant($emodel, $edims);
        $count = 0;
        foreach ($repo->stream_rows() as $ignored) {
            unset($ignored);
            $count++;
        }
        return $count;
    }

    /**
     * Committed row counts grouped by owner (#2342).
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @return array<string,int>
     */
    public function count_rows_by_owner(string $area, string $emodel, int $edims): array {
        $repo = $this->mapper($area)->repo_for_variant($emodel, $edims);
        $counts = [];
        foreach ($repo->stream_rows() as $row) {
            $owner = (string)($row['owner'] ?? '');
            $counts[$owner] = ($counts[$owner] ?? 0) + 1;
        }
        ksort($counts);
        return $counts;
    }

    /**
     * Read the stored source fingerprint for this area/variant.
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @return string
     */
    public function fingerprint(string $area, string $emodel, int $edims): string {
        return $this->mapper($area)->repo_for_variant($emodel, $edims)->read_fingerprint();
    }

    /**
     * Store the source fingerprint for this area/variant.
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @param string $fingerprint
     * @return void
     */
    public function set_fingerprint(string $area, string $emodel, int $edims, string $fingerprint): void {
        $this->mapper($area)->repo_for_variant($emodel, $edims)->write_fingerprint($fingerprint);
    }

    /**
     * Open a new generation (a streaming CSV write) for this area/variant.
     *
     * The CSV backend has a single active file, so the generation id is a constant; the atomicity
     * comes from the repository's temp-write-then-swap.
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @return int
     */
    public function begin_generation(string $area, string $emodel, int $edims): int {
        $mapper = $this->mapper($area);
        $repo = $mapper->repo_for_variant($emodel, $edims);
        $repo->begin_stream_write();
        $this->rebuild[$area] = (object)['repo' => $repo, 'mapper' => $mapper, 'index' => null];
        return 1;
    }

    /**
     * Add one row to the open generation for this area.
     *
     * @param string $area
     * @param int $generation
     * @param embedding_row $row
     * @return void
     */
    public function upsert(string $area, int $generation, embedding_row $row): void {
        unset($generation);
        $state = $this->rebuild[$area] ?? null;
        if ($state === null) {
            throw new \coding_exception('csv_embeddings_store: no open generation for area "' . $area . '".');
        }
        embeddings_dimension_guard::assert_row_matches_dims($row);
        $state->repo->stream_write_row($state->mapper->to_csv($row));
    }

    /**
     * Return an existing committed row by identity key (for hash-based reuse), or null.
     *
     * Reads the previously committed file (still present during the temp write) via a lazily-built
     * key-offset index, so reuse costs one index build plus O(1) per lookup.
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @param string $key
     * @return embedding_row|null
     */
    public function reuse_existing(string $area, string $emodel, int $edims, string $key): ?embedding_row {
        unset($emodel, $edims);
        if ($key === '') {
            return null;
        }
        $state = $this->rebuild[$area] ?? null;
        if ($state === null) {
            return null;
        }
        if ($state->index === null) {
            $state->index = $state->repo->build_key_offset_index([$state->mapper, 'identity_key'])['index'];
        }
        if (!isset($state->index[$key])) {
            return null;
        }
        $csvrow = $state->repo->read_row_at((int)$state->index[$key]['offset']);
        return is_array($csvrow) ? $state->mapper->to_row($csvrow) : null;
    }

    /**
     * Commit the open generation: atomically publish it and prune the previous one.
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @param int $generation
     * @return int
     */
    public function commit_generation(string $area, string $emodel, int $edims, int $generation): int {
        unset($emodel, $edims, $generation);
        $state = $this->rebuild[$area] ?? null;
        if ($state === null) {
            throw new \coding_exception('csv_embeddings_store: no open generation for area "' . $area . '".');
        }
        $written = $state->repo->commit_stream_write();
        $state->repo->close_random_reader();
        unset($this->rebuild[$area]);
        return $written;
    }

    /**
     * Discard the open, uncommitted generation for this area.
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @param int $generation
     * @return void
     */
    public function discard_generation(string $area, string $emodel, int $edims, int $generation): void {
        unset($emodel, $edims, $generation);
        $state = $this->rebuild[$area] ?? null;
        if ($state === null) {
            return;
        }
        $state->repo->discard_stream_write();
        $state->repo->close_random_reader();
        unset($this->rebuild[$area]);
    }

    /**
     * Not supported: strictly incremental document writes are DB-only (fail-closed).
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @param string $owner
     * @param string $docid
     * @param embedding_row[] $rows
     * @return array
     */
    public function replace_document(
        string $area,
        string $emodel,
        int $edims,
        string $owner,
        string $docid,
        array $rows
    ): array {
        unset($area, $emodel, $edims, $owner, $docid, $rows);
        throw new \coding_exception('Incremental document operations require the DB embeddings store.');
    }

    /**
     * Not supported: strictly incremental document writes are DB-only (fail-closed).
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @param string $owner
     * @param string $docid
     * @return void
     */
    public function delete_document(string $area, string $emodel, int $edims, string $owner, string $docid): void {
        unset($area, $emodel, $edims, $owner, $docid);
        throw new \coding_exception('Incremental document operations require the DB embeddings store.');
    }

    /**
     * Not supported: strictly incremental document writes are DB-only (fail-closed).
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @param string $owner
     * @return void
     */
    public function delete_owner(string $area, string $emodel, int $edims, string $owner): void {
        unset($area, $emodel, $edims, $owner);
        throw new \coding_exception('Incremental document operations require the DB embeddings store.');
    }

    /**
     * Not supported: strictly incremental document writes are DB-only (fail-closed).
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @param string $owner
     * @param int $courseid
     * @return void
     */
    public function delete_owner_in_course(string $area, string $emodel, int $edims, string $owner, int $courseid): void {
        unset($area, $emodel, $edims, $owner, $courseid);
        throw new \coding_exception('Incremental document operations require the DB embeddings store.');
    }

    /**
     * Yield each committed row for this area/variant as an embedding_row (bounded memory).
     *
     * @param string $area
     * @param string $emodel
     * @param int $edims
     * @return \Generator
     */
    public function stream_rows(string $area, string $emodel, int $edims): \Generator {
        $mapper = $this->mapper($area);
        $repo = $mapper->repo_for_variant($emodel, $edims);
        foreach ($repo->stream_rows() as $csvrow) {
            yield $mapper->to_row($csvrow);
        }
    }

    /**
     * No-op for the CSV backend: docs/skills carry no context id, so nothing is ever matched. Site
     * content (which has context ids) lives in the DB store.
     *
     * @param int $contextid
     * @return void
     */
    public function delete_by_context(int $contextid): void {
        unset($contextid);
    }

    /**
     * Not supported: course-scoped invalidation belongs to the site-content rows, which are DB-only
     * (fail-closed, matching the other incremental document operations).
     *
     * @param int $courseid
     * @return void
     */
    public function delete_by_course(int $courseid): void {
        unset($courseid);
        throw new \coding_exception('Incremental document operations require the DB embeddings store.');
    }
}
