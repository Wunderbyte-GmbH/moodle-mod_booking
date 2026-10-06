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
 * Readiness service for documentation embeddings index.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\lookup;

use bookingextension_agent\local\wizard\embeddings_action_config_resolver;
use bookingextension_agent\local\wizard\services\embeddings\embeddings_rebuild_scheduler;
use bookingextension_agent\local\wizard\services\retrieval\docs_row_mapper;
use bookingextension_agent\local\wizard\services\retrieval\embeddings_store_factory;

/**
 * Determines whether the docs embeddings index is ready and triggers async rebuilds.
 */
class docs_embeddings_readiness_service {
    /** Fully qualified class name of the rebuild adhoc task. */
    private const REBUILD_TASK_CLASS = '\\bookingextension_agent\\task\\rebuild_docs_embeddings_adhoc';

    /**
     * Check if the wunderbyte embeddings provider is available.
     *
     * @return bool
     */
    public function is_embeddings_provider_available(): bool {
        return class_exists(\bookingextension_agent\local\wizard\wb_action_names::GENERATE_EMBEDDINGS);
    }

    /**
     * The active embeddings variant (model, dimensions) the docs index is scoped to.
     *
     * @return array
     */
    private function active_variant(): array {
        $resolved = (new embeddings_action_config_resolver())->resolve();
        return [(string)$resolved['model'], (int)$resolved['dimensions']];
    }

    /**
     * Check if the docs embeddings index is present (committed) for the active variant.
     *
     * Does NOT verify content hashes — for a fast runtime readiness check.
     *
     * @return bool
     */
    public function is_index_ready(): bool {
        if (!$this->is_embeddings_provider_available()) {
            return false;
        }

        [$model, $dims] = $this->active_variant();
        return embeddings_store_factory::instance()->exists(docs_row_mapper::AREA, $model, $dims);
    }

    /**
     * Cheap coverage check used on the synchronous skill-use path.
     *
     * Unlike {@see is_index_ready()} (schema only), this also asks "does every currently resolvable
     * corpus have at least one row?". It detects a freshly added corpus without any per-file hashing,
     * so the expensive diff/prune can be deferred to the adhoc task (eventual consistency).
     *
     * @return bool
     */
    public function is_index_covered(): bool {
        return $this->get_status()['ready'];
    }

    /**
     * Return full readiness status including provider, schema and corpus-coverage checks.
     *
     * @return array{ready:bool,status:string,reason:string}
     */
    public function get_status(): array {
        if (!$this->is_embeddings_provider_available()) {
            return ['ready' => false, 'status' => 'unavailable', 'reason' => 'embeddings_provider_missing'];
        }

        [$model, $dims] = $this->active_variant();
        $store = embeddings_store_factory::instance();
        if (!$store->exists(docs_row_mapper::AREA, $model, $dims)) {
            return ['ready' => false, 'status' => 'missing', 'reason' => 'index_csv_not_found'];
        }

        // Single streaming pass: tally which resolvable corpora are present — never holding the catalog
        // in memory. Each row's identity carries its corpus in `owner` and its path in `refkey`. Coverage
        // is measured against the *resolvable* set (existing roots), not the full *declared* set: a
        // declared-but-unreadable corpus can never be indexed, so requiring a row for it would reschedule
        // the rebuild forever (pruning, by contrast, uses the declared set — see
        // docs_embeddings_index_service::rebuild()).
        $resolvable = array_keys((new docs_corpus_registry())->list());
        $present = [];
        $seen = 0;
        foreach ($store->stream_rows(docs_row_mapper::AREA, $model, $dims) as $row) {
            if (trim($row->owner) === '' || trim($row->refkey) === '') {
                return ['ready' => false, 'status' => 'invalid', 'reason' => 'index_csv_invalid_schema'];
            }
            $seen++;
            $present[trim($row->owner)] = true;
        }

        if ($seen === 0) {
            return ['ready' => false, 'status' => 'invalid', 'reason' => 'index_csv_invalid_schema'];
        }

        foreach ($resolvable as $cid) {
            if (empty($present[$cid])) {
                return ['ready' => false, 'status' => 'incomplete', 'reason' => 'corpora_not_covered'];
            }
        }

        // Drift + removal detector: compare the live source fingerprint (cheap stat scan) against the
        // one the last full rebuild stamped. Any added/edited/removed doc — or a removed corpus — flips
        // it, which coverage alone (rows-per-corpus) cannot see. An index built before fingerprints
        // existed reads '' here and is treated as stale once → it self-heals on the next rebuild.
        $live = (new docs_embeddings_index_service())->compute_source_fingerprint();
        if ($live !== $store->fingerprint(docs_row_mapper::AREA, $model, $dims)) {
            return ['ready' => false, 'status' => 'stale', 'reason' => 'source_changed'];
        }

        return ['ready' => true, 'status' => 'ready', 'reason' => ''];
    }

    /**
     * Per-corpus index summary for the admin UI (read-only — never triggers a rebuild).
     *
     * Combines the configured corpora (registry) with what is actually stored in the index CSV,
     * so the settings page can show exactly which documentation is indexed, which configured
     * corpus is still waiting to be embedded, and which indexed corpus is no longer configured.
     *
     * @return array{
     *     provideravailable: bool,
     *     indexready: bool,
     *     documents: int,
     *     chunks: int,
     *     corpora: array<int, array{
     *         corpusid: string, state: string, indexed: bool, resolvable: bool,
     *         declared: bool, documents: int, chunks: int
     *     }>
     * }
     */
    public function get_corpus_index_summary(): array {
        $registry = new docs_corpus_registry();
        $resolvable = $registry->list();              // Corpus_id => absolute root (root exists now).
        $declared = $registry->declared_corpus_ids(); // Every syntactically valid corpus_id.

        $provideravailable = $this->is_embeddings_provider_available();
        [$model, $dims] = $this->active_variant();
        $store = embeddings_store_factory::instance();

        // Stream the index once, tallying chunks + distinct documents per corpus — never holding the
        // catalog in memory. Each row's corpus is in `owner`, its path in `refkey`. A missing or
        // malformed index yields zero counts (everything then shows as not-yet-indexed).
        $indexready = false;
        $chunksbycorpus = [];
        $docsbycorpus = [];
        if ($provideravailable && $store->exists(docs_row_mapper::AREA, $model, $dims)) {
            $seen = 0;
            $schemaok = true;
            foreach ($store->stream_rows(docs_row_mapper::AREA, $model, $dims) as $row) {
                $seen++;
                $cid = trim($row->owner);
                $path = trim($row->refkey);
                if ($cid === '' || $path === '') {
                    $schemaok = false;
                    if ($cid === '') {
                        continue;
                    }
                }
                $chunksbycorpus[$cid] = ($chunksbycorpus[$cid] ?? 0) + 1;
                if ($path !== '') {
                    $docsbycorpus[$cid][$path] = true;
                }
            }
            $indexready = $seen > 0 && $schemaok;
            if (!$indexready) {
                // An empty or malformed index counts as not-indexed (mirrors the previous behaviour).
                $chunksbycorpus = [];
                $docsbycorpus = [];
            }
        }

        // One entry per declared corpus, plus any indexed corpus that is no longer declared so an
        // orphaned-but-still-stored corpus stays visible until the next rebuild prunes it.
        $ids = $declared;
        foreach (array_keys($chunksbycorpus) as $cid) {
            if (!in_array($cid, $ids, true)) {
                $ids[] = $cid;
            }
        }

        $corpora = [];
        foreach ($ids as $cid) {
            $isdeclared = in_array($cid, $declared, true);
            $isresolvable = isset($resolvable[$cid]);
            $chunks = $chunksbycorpus[$cid] ?? 0;
            $documents = isset($docsbycorpus[$cid]) ? count($docsbycorpus[$cid]) : 0;
            $indexed = $chunks > 0;

            if (!$isdeclared) {
                $state = 'orphaned';        // Indexed but no longer configured: pruned next rebuild.
            } else if (!$isresolvable) {
                $state = 'unresolvable';    // Configured but directory missing: cannot be indexed.
            } else if ($indexed) {
                $state = 'indexed';
            } else {
                $state = 'pending';         // Configured and resolvable but not in the index yet.
            }

            $corpora[] = [
                'corpusid' => $cid,
                'state' => $state,
                'indexed' => $indexed,
                'resolvable' => $isresolvable,
                'declared' => $isdeclared,
                'documents' => $documents,
                'chunks' => $chunks,
            ];
        }

        usort($corpora, static fn(array $a, array $b): int => strcmp($a['corpusid'], $b['corpusid']));

        return [
            'provideravailable' => $provideravailable,
            'indexready' => $indexready,
            'documents' => array_sum(array_map(static fn(array $c): int => $c['documents'], $corpora)),
            'chunks' => array_sum($chunksbycorpus),
            'corpora' => $corpora,
        ];
    }

    /**
     * Settings updated-callback: schedule a rebuild when the corpus textarea changes.
     *
     * This is only the proactive fast path so a freshly added corpus does not have to wait for the
     * next skill invocation; the cheap skill-use coverage check remains the safety net, and the
     * expensive per-file diff/prune happens inside the task. The scheduling itself is gated on the
     * docs skill being active (see {@see ensure_rebuild_scheduled_if_needed()}).
     *
     * @param string $name The full setting name Moodle passes to updated-callbacks (unused).
     * @return void
     */
    public static function on_corpus_setting_updated(string $name = ''): void {
        (new self())->ensure_rebuild_scheduled_if_needed();
    }

    /**
     * Trigger an async rebuild adhoc task if the index is not ready.
     *
     * Uses a simple file-based debounce: if a task was queued in the last
     * $debounceseconds, skip queuing another one.
     *
     * @param int $debounceseconds Minimum seconds between task enqueues.
     * @return bool True when a task was queued.
     */
    public function ensure_rebuild_scheduled_if_needed(int $debounceseconds = 300): bool {
        // E1 gate: when the docs skill is inactive, never schedule any embedding work. This covers
        // every trigger that routes through here — skill use (B3) and the settings save (A5).
        if (!docs_embeddings_gate::is_docs_skill_active()) {
            return false;
        }

        $status = $this->get_status();
        if ($status['ready']) {
            return false;
        }

        if (!class_exists(self::REBUILD_TASK_CLASS)) {
            return false;
        }

        // Single scheduling path (shared with the skill catalog): config-marker debounce + deduped
        // queue_adhoc_task.
        return embeddings_rebuild_scheduler::queue_if_due(
            new \bookingextension_agent\task\rebuild_docs_embeddings_adhoc(),
            'docs_embeddings_rebuild_queued_at',
            $debounceseconds
        );
    }
}
