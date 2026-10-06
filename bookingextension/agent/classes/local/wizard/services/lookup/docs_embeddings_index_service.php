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
 * Index service for documentation chunk embeddings.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\lookup;

use bookingextension_agent\local\wizard\embeddings_action_config_resolver;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\services\llm\llm_call_service;
use bookingextension_agent\local\wizard\services\retrieval\docs_row_mapper;
use bookingextension_agent\local\wizard\services\retrieval\embedding_row;
use bookingextension_agent\local\wizard\services\retrieval\embeddings_store_factory;
use context_system;

/**
 * Builds and persists the documentation embeddings index.
 *
 * Each registered corpus (identified by corpus_id + docs root path) is scanned
 * for .md files. Each file becomes one chunk. Content hashes prevent redundant
 * re-embedding on unchanged files.
 *
 * Corpus registry is driven by docs_corpus_registry, which parses the admin "documentation
 * corpora" textarea (docscorpora) into the resolvable corpus_id → root map.
 */
class docs_embeddings_index_service {
    /** Maximum characters per chunk before an oversized section is split on a size budget. */
    private const MAX_CHUNK_CHARS = 4000;

    /**
     * Rebuild the documentation embeddings index (incremental, non-destructive).
     *
     * Scans the in-scope corpus roots, computes content hashes for .md files, reuses unchanged
     * chunks (hash match + existing embedding), and embeds new/changed files. Pruning is measured
     * against the *declared* corpus set: a row is removed only when its corpus_id is no longer
     * declared, or its file vanished from a scanned corpus. A declared corpus whose root is
     * momentarily unreadable is left untouched — never wiped.
     *
     * @param string|null $corpusid   Restrict the rebuild to this corpus (fast path); null = all.
     * @param string|null $model      Override embedding model (uses config if null).
     * @param int|null    $dimensions Override dimensions (uses config if null).
     * @param bool        $force      Force re-embedding of all scanned chunks.
     * @param callable|null $progress Optional sink for human-readable progress lines (#2343).
     * @return array  Summary: status (ok, or partial when chunks failed), embedded, reused, deleted,
     *                written, failed, corpora.
     */
    public function rebuild(
        ?string $corpusid = null,
        ?string $model = null,
        ?int $dimensions = null,
        bool $force = false,
        ?callable $progress = null
    ): array {
        $note = static function (string $line) use ($progress): void {
            if ($progress !== null) {
                $progress($line);
            }
        };
        // E3 gate (defense-in-depth): any direct caller — tests, future callers — is covered too.
        if (!docs_embeddings_gate::is_docs_skill_active()) {
            return [
                'status' => 'skipped',
                'reason' => 'skill_inactive',
                'written' => 0, 'embedded' => 0, 'reused' => 0, 'deleted' => 0,
            ];
        }

        if (!class_exists(\bookingextension_agent\local\wizard\wb_action_names::GENERATE_EMBEDDINGS)) {
            return [
                'status' => 'skipped',
                'reason' => 'embeddings_provider_unavailable',
                'written' => 0, 'embedded' => 0, 'reused' => 0, 'deleted' => 0,
            ];
        }

        $resolved = (new embeddings_action_config_resolver())->resolve_with_overrides($model, $dimensions);
        $resolvedmodel = $resolved['model'];
        $resolveddimensions = $resolved['dimensions'];

        $registry = new docs_corpus_registry();
        $resolvable = $registry->list();
        $declared = array_flip($registry->declared_corpus_ids());

        // A full (unscoped) rebuild rewrites the entire index, so it is the only run allowed to
        // re-stamp the source fingerprint that drives readiness. A scoped fast-path run touches just
        // one corpus and must NOT claim the whole index is back in sync.
        $isfullrebuild = ($corpusid === null || trim((string)$corpusid) === '');

        // Pick the corpora to (re)scan this run. A scoped fast-path run only touches its own corpus;
        // an unscoped run scans every currently resolvable corpus.
        if ($corpusid !== null && trim($corpusid) !== '') {
            $corpusid = trim($corpusid);
            $scan = isset($resolvable[$corpusid]) ? [$corpusid => $resolvable[$corpusid]] : [];
        } else {
            $scan = $resolvable;
        }

        // Variant-scoped store (CSV or DB, selected by config): embeddings for the active model live
        // under their own variant, so a model switch never invalidates the others (F2). Respects the
        // model/dimensions overrides above.
        $store = embeddings_store_factory::instance();
        $area = docs_row_mapper::AREA;

        // STREAMING rebuild (bounded memory): never hold the whole catalog in RAM. A fresh generation
        // is written row-by-row — unchanged chunks are reused by identity + content hash (no re-embed),
        // only changed/new chunks are embedded — then published atomically by the generation swap. Peak
        // memory stays at ~one row, independent of catalog size. Identity = (corpus, path, start line)
        // so each chunk of a multi-chunk file reuses independently.
        $existingtotal = $store->count_rows($area, $resolvedmodel, $resolveddimensions);

        // Nothing to scan and nothing on disk yet → genuinely empty.
        if (empty($scan) && $existingtotal === 0) {
            return [
                'status' => 'empty',
                'reason' => 'no_corpora_registered',
                'written' => 0, 'embedded' => 0, 'reused' => 0, 'deleted' => 0,
            ];
        }

        $context = context_system::instance();
        $admin = get_admin();
        $userid = !empty($admin->id) ? (int)$admin->id : 2;
        $llm = new llm_call_service(new conversation_store());

        $embedded = 0;
        $reused = 0;
        $kept = 0;
        // Chunks whose embedding call failed (e.g. provider busy / rate limited). They are missing
        // from the new generation, so a run with failures is not a complete index (#2549).
        $failed = 0;

        $gen = $store->begin_generation($area, $resolvedmodel, $resolveddimensions);
        try {
            // Pass 1 — scanned corpora: rewrite every current chunk (reuse by offset, else embed).
            // A file that vanished from a scanned corpus simply is not re-emitted (naturally pruned).
            $corporasummary = [];
            foreach ($scan as $scancorpusid => $docsroot) {
                $files = $this->scan_md_files($docsroot);
                $cembedded = 0;
                $creused = 0;
                $cfailed = 0;
                foreach ($files as $abspath) {
                    $relpath = ltrim(substr($abspath, strlen($docsroot)), '/\\');
                    $content = @file_get_contents($abspath);
                    if ($content === false) {
                        continue;
                    }

                    // Split into heading/size-bounded chunks so large docs are fully embedded (no
                    // 6000-char truncation) and retrieval is per-section precise.
                    foreach (markdown_chunker::chunk($content, self::MAX_CHUNK_CHARS) as $chunk) {
                        $chunktext = (string)$chunk['text'];
                        $contenthash = sha1($chunktext . '|m=' . $resolvedmodel . '|d=' . $resolveddimensions);
                        $key = $scancorpusid . '|' . $relpath . '|' . (string)$chunk['line_start'];

                        if (!$force) {
                            $oldrow = $store->reuse_existing($area, $resolvedmodel, $resolveddimensions, $key);
                            if ($oldrow !== null && $oldrow->contenthash === $contenthash && !empty($oldrow->embedding)) {
                                $store->upsert($area, $gen, $oldrow);
                                $reused++;
                                $creused++;
                                continue;
                            }
                        }

                        $inputtext = $this->build_embedding_input(
                            $scancorpusid,
                            $relpath,
                            (string)$chunk['title'],
                            $chunktext
                        );
                        $embeddingcall = $llm->invoke_embeddings_for_context(
                            0,
                            (int)$context->id,
                            $userid,
                            'docs_idx|corpus=' . $scancorpusid,
                            $inputtext,
                            $resolveddimensions
                        );

                        if (empty($embeddingcall['success']) || empty($embeddingcall['embedding'])) {
                            $failed++;
                            $cfailed++;
                            continue;
                        }

                        $store->upsert($area, $gen, new embedding_row(
                            $area,
                            $scancorpusid,
                            $relpath,
                            (int)$chunk['line_start'],
                            (string)$chunk['title'],
                            $resolvedmodel,
                            $resolveddimensions,
                            $contenthash,
                            (array)$embeddingcall['embedding'],
                            (int)$chunk['line_end']
                        ));
                        $embedded++;
                        $cembedded++;
                        if ($cembedded % 25 === 0) {
                            $note('corpus ' . $scancorpusid . ': ' . $cembedded . ' chunks embedded...');
                        }
                    }
                }
                $corporasummary[$scancorpusid] = [
                    'files' => count($files),
                    'embedded' => $cembedded,
                    'reused' => $creused,
                    'failed' => $cfailed,
                ];
                $note('corpus ' . $scancorpusid . ': files=' . count($files)
                    . ', embedded=' . $cembedded . ', reused=' . $creused . ', failed=' . $cfailed);
            }

            // Pass 2 — non-destructive merge: copy existing rows of declared corpora that were NOT
            // scanned this run (out-of-scope or momentarily unreadable). Scanned-corpus rows are
            // already rewritten above; rows of no-longer-declared corpora are dropped (pruned). The
            // stream reads the still-committed old generation while the new one is being written.
            foreach ($store->stream_rows($area, $resolvedmodel, $resolveddimensions) as $row) {
                $cid = trim($row->owner);
                if (isset($scan[$cid]) || !isset($declared[$cid])) {
                    continue;
                }
                $store->upsert($area, $gen, $row);
                $kept++;
            }

            $written = $store->commit_generation($area, $resolvedmodel, $resolveddimensions, $gen);

            // Only a full rebuild has just rewritten every corpus, so only it may stamp the source
            // fingerprint as "what the index now reflects". Readiness compares this against a freshly
            // computed live fingerprint, so any later add/edit/remove of a doc flips it back to stale.
            // A run with failed chunks does not reflect the source: without the stamp the index stays
            // stale (searchable, not ready) until a later run has embedded the missing chunks.
            if ($isfullrebuild && $failed === 0) {
                $store->set_fingerprint($area, $resolvedmodel, $resolveddimensions, $this->compute_source_fingerprint());
            }
        } catch (\Throwable $e) {
            $store->discard_generation($area, $resolvedmodel, $resolveddimensions, $gen);
            throw $e;
        }

        $deleted = $existingtotal - $reused - $kept;
        if ($deleted < 0) {
            $deleted = 0;
        }

        return [
            'status' => $failed > 0 ? 'partial' : 'ok',
            'written' => $written,
            'embedded' => $embedded,
            'reused' => $reused,
            'deleted' => $deleted,
            'failed' => $failed,
            'corpora' => $corporasummary,
        ];
    }

    /**
     * Compute a cheap, deterministic fingerprint of the COMPLETE current docs source set.
     *
     * Stat-only (no chunking/embedding): for every resolvable corpus it hashes the sorted list of
     * `corpus_id|relpath|filesize|filemtime` plus the declared-corpus id list. Any added, edited
     * (size/mtime) or removed file — and any added/removed corpus — flips the hash. Readiness stores
     * this after a full rebuild and re-compares it on every check to detect drift, including removals
     * that the row-coverage check alone never caught.
     *
     * @return string  40-char sha1 hex.
     */
    public function compute_source_fingerprint(): string {
        $registry = new docs_corpus_registry();
        $resolvable = $registry->list();

        $declared = $registry->declared_corpus_ids();
        sort($declared);

        $entries = [];
        foreach ($resolvable as $cid => $root) {
            foreach ($this->scan_md_files($root) as $abspath) {
                $relpath = ltrim(substr($abspath, strlen($root)), '/\\');
                $size = @filesize($abspath);
                $mtime = @filemtime($abspath);
                $entries[] = $cid . '|' . $relpath . '|'
                    . ($size === false ? '0' : (string)$size) . '|'
                    . ($mtime === false ? '0' : (string)$mtime);
            }
        }
        sort($entries);

        return sha1('declared=' . implode(',', $declared) . "\n" . implode("\n", $entries));
    }

    /**
     * Scan a directory for all .md files recursively (excluding pix/ subdirs).
     *
     * @param string $rootdir
     * @return string[]  Absolute file paths, sorted.
     */
    private function scan_md_files(string $rootdir): array {
        if (!is_dir($rootdir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($rootdir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $fileinfo) {
            if (!$fileinfo->isFile()) {
                continue;
            }

            $path = $fileinfo->getPathname();

            // Skip pix/ subdirectories (images only).
            if (strpos(str_replace('\\', '/', $path), '/pix/') !== false) {
                continue;
            }

            if (strtolower($fileinfo->getExtension()) !== 'md') {
                continue;
            }

            $files[] = $path;
        }

        sort($files);
        return $files;
    }

    /**
     * Build a rich text input for embedding generation.
     *
     * Prepends corpus, path, and title as context so that the embedding captures
     * both the structural location and the semantic content of the chunk.
     *
     * @param string $corpusid
     * @param string $relpath
     * @param string $title
     * @param string $content
     * @return string
     */
    private function build_embedding_input(string $corpusid, string $relpath, string $title, string $content): string {
        $parts = [];
        $parts[] = 'corpus: ' . $corpusid;
        $parts[] = 'path: ' . $relpath;
        if ($title !== '') {
            $parts[] = 'title: ' . $title;
        }
        // Safety cap (chunks are already size-bounded; this guards the prepended header too).
        $trimmedcontent = mb_substr($content, 0, 6000);
        $parts[] = $trimmedcontent;

        return implode("\n", $parts);
    }
}
