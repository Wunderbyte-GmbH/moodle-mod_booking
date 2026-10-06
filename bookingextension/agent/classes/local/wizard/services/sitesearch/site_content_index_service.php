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
 * Strictly incremental site-content embeddings indexer (DB store, engine-session based).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\sitesearch;

use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\embeddings_action_config_resolver;
use bookingextension_agent\local\wizard\services\llm\llm_call_service;
use bookingextension_agent\local\wizard\services\retrieval\embedding_row;
use bookingextension_agent\local\wizard\services\retrieval\embeddings_store;
use bookingextension_agent\local\wizard\services\retrieval\embeddings_store_factory;
use bookingextension_agent\local\wizard\services\retrieval\site_content_row_mapper;
use bookingextension_agent\local\wizard\wb_action_names;
use context_system;

/**
 * Keeps the `site_content` area of the embeddings store in sync with the enabled core_search areas —
 * STRICTLY INCREMENTALLY (blueprint §5.2/§11.23): per run, only changed chunks are written. Each
 * area keeps a persisted cursor ({@see site_content_state_repository}, Core's `…_lastindexrun`
 * pattern) and per document the diff-based `replace_document()` store op leaves identical rows
 * physically untouched. A full generation swap per run is explicitly forbidden here (that pattern
 * remains initial-build/repair for docs/skills only).
 *
 * Content source: the area's own `get_document($record)` — made callable without a configured
 * global-search engine by the {@see task_search_session} engine session (§11.26). There is no
 * per-plugin field-mapping code in the agent anymore; whitelisting a new area is governance config.
 *
 * CONTEXT-SCOPED GOVERNANCE (context-governance blueprint §4): per area the
 * {@see sitesearch_scope_resolver}'s shape() picks one of two read strategies — 'allowlist'
 * (per allowed course one context-scoped recordset; nothing outside is ever read) or 'blocklist'
 * (one global recordset + cheap per-record courseid skip before any chunking/embedding). Both
 * feed the same write path. Per DOCUMENT the includefiles flag comes from the resolver's
 * effective(area, courseid) pair, not from a per-area flag. Rule changes are synchronized by the
 * scope-sync adhoc task ({@see update_course()} backfill + `delete_owner_in_course` prune), never
 * by a rebuild.
 *
 * Areas whose shape is 'off' are pruned (`delete_owner` + cursor state removed), so switching an
 * area off does not leave stale rows behind.
 *
 * Hard-guarded to the DB backend — the CSV backend ignores the retrieval filter and could serve
 * cross-user content, so site content must never live there. The embedder is injectable so the
 * whole path can be tested without the LLM provider.
 */
class site_content_index_service {
    /** @var callable Embedder: fn(string $text, int $contextid, int $userid, int $dims): ?array. */
    private $embedder;

    /** @var site_content_area_registry Area enumeration + enablement. */
    private site_content_area_registry $registry;

    /** @var site_content_state_repository Per-area cursor state. */
    private site_content_state_repository $state;

    /** @var sitesearch_scope_resolver Effective-rule resolver (strategy + per-doc includefiles). */
    private sitesearch_scope_resolver $resolver;

    /**
     * Constructor.
     *
     * @param callable|null $embedder Injectable embedder (defaults to the Wunderbyte embeddings provider).
     */
    public function __construct(?callable $embedder = null) {
        $this->registry = new site_content_area_registry();
        $this->state = new site_content_state_repository();
        $this->resolver = new sitesearch_scope_resolver();
        $this->embedder = $embedder ?? [$this, 'default_embed'];
    }

    /**
     * Default embedder: the Wunderbyte embeddings provider via the LLM call service.
     *
     * @param string $text
     * @param int $contextid
     * @param int $userid
     * @param int $dims
     * @return float[]|null
     */
    public function default_embed(string $text, int $contextid, int $userid, int $dims): ?array {
        $llm = new llm_call_service(new conversation_store());
        $call = $llm->invoke_embeddings_for_context(0, $contextid, $userid, 'site_idx', $text, $dims);
        if (empty($call['success']) || empty($call['embedding'])) {
            return null;
        }
        return (array)$call['embedding'];
    }

    /**
     * Whether indexing may run: DB backend, embeddings provider present, Moodle 5+.
     *
     * @return array ['ready' => bool, 'reason' => string]
     */
    public function is_ready(): array {
        global $CFG;
        if (get_config('bookingextension_agent', 'embeddingsstore') !== 'db') {
            return ['ready' => false, 'reason' => 'requires_db_backend'];
        }
        if (!class_exists(wb_action_names::GENERATE_EMBEDDINGS)) {
            return ['ready' => false, 'reason' => 'embeddings_provider_unavailable'];
        }
        if ((int)($CFG->branch ?? 0) < 500) {
            return ['ready' => false, 'reason' => 'requires_moodle_5'];
        }
        return ['ready' => true, 'reason' => ''];
    }

    /**
     * Incrementally update the site-content index for every enumerated area.
     *
     * Per area the resolver's shape() picks the strategy: 'off' areas are pruned (rows + cursor
     * state), 'allowlist'/'blocklist' areas run an incremental pass from their persisted cursor.
     * An error in one area aborts only that area's run (its cursor is never advanced past the
     * failed document, so the next run resumes there) and the remaining areas still get processed.
     *
     * @return array Summary: status, reason, aggregate counters and a per-area breakdown.
     */
    public function update(): array {
        $summary = [
            'status' => 'ok',
            'reason' => '',
            'processed' => 0,
            'embedded' => 0,
            'reused' => 0,
            'inserted' => 0,
            'updated' => 0,
            'deleted' => 0,
            'kept' => 0,
            'skipped' => 0,
            'areas' => [],
        ];

        $ready = $this->is_ready();
        if (!$ready['ready']) {
            $summary['status'] = 'skipped';
            $summary['reason'] = $ready['reason'];
            return $summary;
        }

        $resolved = (new embeddings_action_config_resolver())->resolve();
        $model = (string)$resolved['model'];
        $dims = (int)$resolved['dimensions'];
        $area = site_content_row_mapper::AREA;
        $store = embeddings_store_factory::instance();

        // A long-lived process must not resolve against stale governance state from earlier work.
        sitesearch_scope_resolver::reset_request_cache();

        if (empty($this->registry->enabled_area_keys())) {
            $summary['reason'] = 'no_areas_enabled';
        }

        // Pipeline fingerprint in the store (blueprint §11.22): the search service re-runs the
        // shared chunk pipeline on the live document at query time and addresses chunks by their
        // stored number, so index-time and query-time chunking must always use the same
        // chunker/pipeline version ('chunker:<v>'; scope-dependent file flags are synchronized by
        // the delta-sync adhoc task instead, never by the fingerprint). On mismatch (chunker
        // changed, or first ever run) reset every area cursor to 0 — the incremental pass below
        // then re-reads everything, which stays cheap because replace_document() diffs per
        // (refindex, contenthash): unchanged chunks are physically untouched and their vectors
        // reused, only actually changed rows are written/embedded.
        $fingerprint = site_content_chunk_pipeline::fingerprint();
        $fpmismatch = $store->fingerprint($area, $model, $dims) !== $fingerprint;
        if ($fpmismatch) {
            foreach ($this->registry->all_area_keys() as $areakey) {
                $this->state->delete($areakey, $model, $dims);
            }
        }

        $context = context_system::instance();
        $admin = get_admin();
        $adminid = !empty($admin->id) ? (int)$admin->id : 2;

        foreach ($this->registry->all_area_keys() as $areakey) {
            $shape = $this->resolver->shape($areakey);
            if ($shape['strategy'] === 'off') {
                // No rule grants any coverage: prune the area's rows and its cursor so nothing
                // stale is ever served and a later re-enable starts from a clean slate.
                $store->delete_owner($area, $model, $dims, $areakey);
                $this->state->delete($areakey, $model, $dims);
                $summary['areas'][$areakey] = ['status' => 'disabled_pruned'];
                continue;
            }

            $areaobj = $this->registry->area_instance($areakey);
            if ($areaobj === null) {
                $summary['areas'][$areakey] = ['status' => 'unavailable'];
                continue;
            }

            try {
                $stats = $this->update_area(
                    $store,
                    $area,
                    $areakey,
                    $areaobj,
                    $model,
                    $dims,
                    (int)$context->id,
                    $adminid,
                    $shape
                );
                $summary['areas'][$areakey] = ['status' => 'ok'] + $stats;
                $counters = ['processed', 'embedded', 'reused', 'inserted', 'updated', 'deleted', 'kept', 'skipped'];
                foreach ($counters as $counter) {
                    $summary[$counter] += $stats[$counter];
                }
            } catch (\Throwable $e) {
                // Abort only this area; its cursor was not advanced past the failed document
                // (update_area persists partial progress before rethrowing), so the next run
                // resumes right there.
                mtrace('bookingextension_agent site_content indexing failed for area ' . $areakey
                    . ': ' . $e->getMessage());
                $summary['areas'][$areakey] = [
                    'status' => 'error',
                    'error' => $e->getMessage(),
                    'cursor' => $this->state->get_cursor($areakey, $model, $dims),
                ];
            }
        }

        if ($fpmismatch) {
            // Persist the chunker fingerprint after the pass. Even if an area errored above this
            // is safe: its cursor was never advanced past the failed document, so the next run
            // resumes there regardless of the fingerprint now being current.
            $store->set_fingerprint($area, $model, $dims, $fingerprint);
        }

        return $summary;
    }

    /**
     * Run one incremental pass over a single covered area, using the shape's read strategy.
     *
     * 'allowlist': one context-scoped recordset per allowed course (nothing outside the allowed
     * set is ever read); every course recordset runs from the SAME area cursor — course-set
     * changes are handled by the delta-sync backfill/prune, never by cursor tricks. 'blocklist':
     * one global recordset; records of excluded courses are skipped cheaply per document (before
     * any chunking/embedding) via the resolver.
     *
     * The whole pass runs inside the task-scoped engine session so the area's own `get_document()`
     * works; the session and every recordset are released in `finally` blocks, even when the
     * embedder throws. On failure, the cursor of the last fully processed document is persisted
     * before the exception bubbles up (resumability, never past the failed document).
     *
     * @param embeddings_store $store
     * @param string $area Store area discriminator ('site_content').
     * @param string $areakey Search area id (store owner).
     * @param \core_search\base $areaobj
     * @param string $model
     * @param int $dims
     * @param int $sysctxid Context id for the embedding provider call.
     * @param int $adminid User id for the embedding provider call.
     * @param array $shape The area's resolver shape ({@see sitesearch_scope_resolver::shape()}).
     * @return array processed/embedded/reused/inserted/updated/deleted/kept/skipped/cursor counters.
     */
    private function update_area(
        embeddings_store $store,
        string $area,
        string $areakey,
        \core_search\base $areaobj,
        string $model,
        int $dims,
        int $sysctxid,
        int $adminid,
        array $shape
    ): array {
        $cursor = $this->state->get_cursor($areakey, $model, $dims);
        $stats = ['processed' => 0, 'embedded' => 0, 'reused' => 0,
            'inserted' => 0, 'updated' => 0, 'deleted' => 0, 'kept' => 0, 'skipped' => 0, 'cursor' => $cursor];

        // Overlap against second-boundary races (Core's manager.php:1262 "-1" trick): re-reading
        // the boundary second is free because replace_document() diffs unchanged docs to a no-op.
        $from = max(0, $cursor - 1);

        $maxmodified = 0;
        task_search_session::begin();
        try {
            try {
                if ($shape['strategy'] === 'allowlist') {
                    foreach ($shape['allowedcourseids'] as $courseid) {
                        $coursecontext = \context_course::instance((int)$courseid, IGNORE_MISSING);
                        if (!$coursecontext) {
                            // The course vanished since the rules were written; nothing to read.
                            continue;
                        }
                        $recordset = $areaobj->get_document_recordset($from, $coursecontext);
                        if (!$recordset) {
                            continue;
                        }
                        try {
                            $this->process_recordset(
                                $store,
                                $area,
                                $areakey,
                                $areaobj,
                                $recordset,
                                $model,
                                $dims,
                                $sysctxid,
                                $adminid,
                                $stats,
                                $maxmodified
                            );
                        } finally {
                            $recordset->close();
                        }
                    }
                } else {
                    $recordset = $areaobj->get_document_recordset($from);
                    if ($recordset) {
                        try {
                            $this->process_recordset(
                                $store,
                                $area,
                                $areakey,
                                $areaobj,
                                $recordset,
                                $model,
                                $dims,
                                $sysctxid,
                                $adminid,
                                $stats,
                                $maxmodified
                            );
                        } finally {
                            $recordset->close();
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Persist partial progress: the last fully processed document, never the failed
                // one — the next run resumes exactly there.
                if ($maxmodified > $cursor) {
                    $this->state->set_cursor($areakey, $model, $dims, $maxmodified);
                }
                throw $e;
            }
        } finally {
            task_search_session::end();
        }

        if ($maxmodified > $cursor) {
            $this->state->set_cursor($areakey, $model, $dims, $maxmodified);
            $stats['cursor'] = $maxmodified;
        }
        return $stats;
    }

    /**
     * Drain one document recordset through the indexing pipeline (shared by both read strategies
     * and the per-course backfill).
     *
     * Per document the effective governance pair is resolved (memoized per course): a document of
     * a non-allowed course is SKIPPED before any chunking or embedding (the blocklist strategy's
     * cheap per-record skip; in allowlist/backfill passes the check never triggers by
     * construction), and the includefiles flag handed to the chunk pipeline is the per-course
     * effective one. Skipped documents still advance the cursor watermark: a later rule flip
     * re-reads them via the delta-sync backfill (from 0), never via the cursor.
     *
     * Must run inside the engine session; the CALLER closes the recordset.
     *
     * @param embeddings_store $store
     * @param string $area Store area discriminator ('site_content').
     * @param string $areakey Search area id (store owner).
     * @param \core_search\base $areaobj
     * @param \moodle_recordset $recordset Open document recordset.
     * @param string $model
     * @param int $dims
     * @param int $sysctxid Context id for the embedding provider call.
     * @param int $adminid User id for the embedding provider call.
     * @param array $stats Aggregated counters (by reference).
     * @param int $maxmodified Highest fully processed 'modified' watermark (by reference).
     * @return void
     */
    private function process_recordset(
        embeddings_store $store,
        string $area,
        string $areakey,
        \core_search\base $areaobj,
        \moodle_recordset $recordset,
        string $model,
        int $dims,
        int $sysctxid,
        int $adminid,
        array &$stats,
        int &$maxmodified
    ): void {
        foreach ($recordset as $record) {
            $doc = $areaobj->get_document($record);
            if (!($doc instanceof \core_search\document)) {
                // The area skipped the record (e.g. the module vanished mid-run).
                continue;
            }
            $modified = $doc->is_set('modified') ? (int)$doc->get('modified') : 0;
            $courseid = $doc->is_set('courseid') ? (int)$doc->get('courseid') : 0;
            $effective = $this->resolver->effective($areakey, $courseid);
            if (!$effective['enabled']) {
                // Excluded course (blocklist skip): no chunking, no embedding, no write. The
                // watermark still advances — re-inclusion runs through the delta-sync backfill.
                $stats['skipped']++;
                $maxmodified = max($maxmodified, $modified);
                continue;
            }
            $this->index_document(
                $store,
                $area,
                $areakey,
                $areaobj,
                $doc,
                $effective['includefiles'],
                $model,
                $dims,
                $sysctxid,
                $adminid,
                $stats
            );
            $stats['processed']++;
            $maxmodified = max($maxmodified, $modified);
        }
    }

    /**
     * Backfill ONE course of ONE area through the normal pipeline — the delta-sync work unit
     * (context-governance blueprint §4.1): a context-scoped recordset from cursor 0 feeds the
     * same per-document indexing as the scheduled pass, so `replace_document()` corrects the
     * course's rows idempotently (a files-flag flip recomputes each document's chunk set).
     *
     * Deliberately NEVER touches the area cursor: the backfill covers exactly one course, and
     * advancing the shared area watermark from here could skip other courses' documents.
     *
     * @param string $areakey Search area id (e.g. 'mod_page-activity').
     * @param int $courseid Course to backfill.
     * @return array status + processed/embedded/reused/inserted/updated/deleted/kept/skipped counters.
     */
    public function update_course(string $areakey, int $courseid): array {
        $stats = ['status' => 'ok', 'reason' => '', 'processed' => 0, 'embedded' => 0, 'reused' => 0,
            'inserted' => 0, 'updated' => 0, 'deleted' => 0, 'kept' => 0, 'skipped' => 0];

        $ready = $this->is_ready();
        if (!$ready['ready']) {
            $stats['status'] = 'skipped';
            $stats['reason'] = $ready['reason'];
            return $stats;
        }
        $areaobj = $this->registry->area_instance($areakey);
        if ($areaobj === null) {
            $stats['status'] = 'unavailable';
            return $stats;
        }
        $coursecontext = \context_course::instance($courseid, IGNORE_MISSING);
        if (!$coursecontext) {
            $stats['status'] = 'skipped';
            $stats['reason'] = 'course_missing';
            return $stats;
        }

        $resolved = (new embeddings_action_config_resolver())->resolve();
        $model = (string)$resolved['model'];
        $dims = (int)$resolved['dimensions'];
        $store = embeddings_store_factory::instance();
        $admin = get_admin();
        $adminid = !empty($admin->id) ? (int)$admin->id : 2;
        $sysctxid = (int)context_system::instance()->id;

        $maxmodified = 0;
        task_search_session::begin();
        try {
            $recordset = $areaobj->get_document_recordset(0, $coursecontext);
            if ($recordset) {
                try {
                    $this->process_recordset(
                        $store,
                        site_content_row_mapper::AREA,
                        $areakey,
                        $areaobj,
                        $recordset,
                        $model,
                        $dims,
                        $sysctxid,
                        $adminid,
                        $stats,
                        $maxmodified
                    );
                } finally {
                    $recordset->close();
                }
            }
        } finally {
            task_search_session::end();
        }
        return $stats;
    }

    /**
     * Prune ONE course of ONE area from the store — the delta-sync counterpart of
     * {@see update_course()} for courses that lost their coverage (new store op §4.2).
     *
     * The area cursor stays untouched (it is a read watermark, not a coverage record).
     *
     * @param string $areakey Search area id whose rows are pruned.
     * @param int $courseid Course whose rows of that area are pruned.
     * @return void
     */
    public function prune_course(string $areakey, int $courseid): void {
        $resolved = (new embeddings_action_config_resolver())->resolve();
        embeddings_store_factory::instance()->delete_owner_in_course(
            site_content_row_mapper::AREA,
            (string)$resolved['model'],
            (int)$resolved['dimensions'],
            $areakey,
            $courseid
        );
    }

    /**
     * Chunk + embed one document and hand its full row set to the diff-based store op.
     *
     * Vectors are reused when a chunk's content hash is unchanged; otherwise the injectable
     * embedder runs. An embed failure throws — aborting the area run so the cursor is never
     * advanced past this document.
     *
     * @param embeddings_store $store
     * @param string $area
     * @param string $areakey
     * @param \core_search\base $areaobj The document's owning area (file attachment source).
     * @param \core_search\document $doc
     * @param bool $includefiles The document's effective file-indexing state (per-course pair).
     * @param string $model
     * @param int $dims
     * @param int $sysctxid
     * @param int $adminid
     * @param array $stats Aggregated counters (by reference).
     * @return void
     */
    private function index_document(
        embeddings_store $store,
        string $area,
        string $areakey,
        \core_search\base $areaobj,
        \core_search\document $doc,
        bool $includefiles,
        string $model,
        int $dims,
        int $sysctxid,
        int $adminid,
        array &$stats
    ): void {
        $docid = (string)$doc->get('itemid');
        $title = $doc->is_set('title') ? trim((string)$doc->get('title')) : '';

        // Provenance straight from the document (is_set guards, default 0).
        $contextid = $doc->is_set('contextid') ? (int)$doc->get('contextid') : 0;
        $courseid = $doc->is_set('courseid') ? (int)$doc->get('courseid') : 0;
        $owneruserid = $doc->is_set('owneruserid') ? (int)$doc->get('owneruserid') : 0;

        // The area's own document is the ONLY content source (blueprint §5.3): title + content +
        // description1 (description2 is deliberately skipped) plus — when file indexing is active
        // for this area — the attached PDFs (§14.2). Produced by the SHARED pipeline the
        // query-time snippet re-extraction runs too, so stored chunk numbers never drift.
        $rows = [];
        $chunkno = 0;
        foreach (site_content_chunk_pipeline::document_chunks($areaobj, $doc, $includefiles) as $chunk) {
            $chunktext = (string)($chunk['text'] ?? '');
            if (trim($chunktext) === '') {
                continue;
            }
            $contenthash = site_content_chunker::content_hash($chunktext, $model, $dims);
            $key = $areakey . '|' . $docid . '|' . $chunkno;

            // Reuse the vector when the chunk text is unchanged; provenance is always re-taken
            // from the fresh document so a moved module cannot leave a stale context.
            $old = $store->reuse_existing($area, $model, $dims, $key);
            if ($old !== null && $old->contenthash === $contenthash && !empty($old->embedding)) {
                $vector = $old->embedding;
                $stats['reused']++;
            } else {
                $vector = ($this->embedder)($this->build_input($title, $chunktext), $sysctxid, $adminid, $dims);
                if ($vector === null || empty($vector)) {
                    // Abort the area run: never advance the cursor past a document we could not
                    // fully embed — the next run retries it.
                    throw new \RuntimeException('Embedding failed for ' . $areakey . ' doc ' . $docid
                        . ' chunk ' . $chunkno);
                }
                $stats['embedded']++;
            }

            $rows[] = new embedding_row(
                $area,
                $areakey,
                $docid,
                $chunkno,
                $title,
                $model,
                $dims,
                $contenthash,
                $vector,
                null,
                (int)$docid,
                $contextid,
                $courseid,
                $owneruserid
            );
            $chunkno++;
        }

        // Doc-atomic, diff-based write: identical rows stay physically untouched, vanished chunk
        // numbers are deleted, and an empty row set removes the document entirely.
        $docstats = $store->replace_document($area, $model, $dims, $areakey, $docid, $rows);
        foreach (['inserted', 'updated', 'deleted', 'kept'] as $counter) {
            $stats[$counter] += (int)($docstats[$counter] ?? 0);
        }
    }

    /**
     * Build the embedding input for a chunk (title prepended for context).
     *
     * @param string $title
     * @param string $chunktext
     * @return string
     */
    private function build_input(string $title, string $chunktext): string {
        $parts = [];
        if ($title !== '') {
            $parts[] = $title;
        }
        $parts[] = mb_substr($chunktext, 0, 6000);
        return implode("\n", $parts);
    }
}
