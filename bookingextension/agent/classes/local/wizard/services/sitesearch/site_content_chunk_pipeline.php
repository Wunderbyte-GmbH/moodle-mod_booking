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
 * Shared document-to-chunk pipeline for site-content indexing and query-time re-extraction.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\sitesearch;

use bookingextension_agent\local\wizard\services\attachment\pdf_text_extractor;

/**
 * Produces the FULL ordered chunk list of one core_search document — content chunks first
 * (title + content + description1, via the shared {@see site_content_chunker} helpers), then,
 * when file indexing is active for the document's area, one chunk run per attached PDF
 * (blueprint §14.2, files v1).
 *
 * GOVERNANCE: there is no global file toggle. File indexing is governed per area x scope like
 * enablement itself — the `includefiles` flag on `{bx_agent_search_scope}`, resolved per course by
 * {@see sitesearch_scope_resolver} (the effective pair) — and additionally requires the area to
 * `uses_file_indexing()` plus an available PDF extractor. Default OFF everywhere (cost-sensitive).
 * Callers that know the document's course pass the resolved per-course flag into
 * {@see document_chunks()}; {@see files_active_for_area()} remains the coarse area-level default.
 *
 * THIS IS THE SINGLE CHUNK-LIST AUTHORITY: the indexer writes chunk rows addressed by their
 * position in this list (refindex), and the search service re-runs the SAME pipeline at query
 * time to resolve `chunk[refindex]` and verify the stored content hash (§5 Storage-Note).
 * Index-time and query-time chunk lists must therefore be byte-identical for unchanged sources —
 * every step here is deterministic:
 *  - content chunks exactly as before (unchanged for areas without active file indexing);
 *  - attached files in ascending file-id order (stable across runs);
 *  - only `application/pdf` files up to {@see MAX_FILE_BYTES}, extracted with a fixed character
 *    cap and WITHOUT the localized truncation note (see pdf_text_extractor::extract());
 *  - each file's text prefixed with a plain `FILE: <filename>` header line;
 *  - chunked by the same overlap chunker as the content.
 *
 * File handling is FAIL-SOFT per file (skip + debugging(), never abort the document). Index/query
 * alignment of the file stage: flag flips run through the scope-governance delta sync (the adhoc
 * backfill recomputes the affected documents' chunk sets), and the query-time re-extraction
 * resolves the SAME per-course effective flag the indexer used — {@see fingerprint()} carries only
 * the pipeline version.
 *
 * Must run inside a {@see task_search_session} bracket (the document comes from the engine-gated
 * `get_document()`; `attach_files()` itself is plain File-API logic).
 */
class site_content_chunk_pipeline {
    /** Maximum size of an attached file considered for extraction (bytes). */
    public const MAX_FILE_BYTES = 20 * 1024 * 1024;

    /** Character cap per extracted file (~25k tokens) — far above the attachment default. */
    public const MAX_FILE_CHARS = 100000;

    /**
     * Request-level cache of the extractor availability probe (it may shell out).
     *
     * @var bool|null
     */
    private static ?bool $extractoravailable = null;

    /**
     * Whether a PDF extraction method (pdftotext binary or bundled parser) is available —
     * probed once per request.
     *
     * @return bool
     */
    public static function extractor_available(): bool {
        if (self::$extractoravailable === null) {
            self::$extractoravailable = (new pdf_text_extractor())->is_available();
        }
        return self::$extractoravailable;
    }

    /**
     * Whether the file pipeline is active for one area at the COARSE area level: the area uses
     * file indexing, an extractor is available, and the area has ANY file-indexing coverage
     * (some enabled + flagged governance row). This is only the default for callers without a
     * course dimension — indexer and query-time re-extraction pass the per-course flag from
     * {@see sitesearch_scope_resolver::effective()} into {@see document_chunks()} instead.
     *
     * @param \core_search\base $areaobj
     * @return bool
     */
    public static function files_active_for_area(\core_search\base $areaobj): bool {
        if (!$areaobj->uses_file_indexing() || !self::extractor_available()) {
            return false;
        }
        $filesenabled = (new site_content_area_registry())->files_enabled_area_keys();
        return in_array($areaobj->get_area_id(), $filesenabled, true);
    }

    /**
     * The site-content store fingerprint: the chunker/pipeline version ONLY.
     *
     * The former '|files:<areas>' component is gone (context-governance blueprint §4.1 fingerprint
     * rollback): file flags are scope-dependent now and their changes are synchronized by the
     * targeted delta-sync adhoc task (backfill recomputes the affected documents' chunk sets),
     * never by a fingerprint-forced full re-read. The fingerprint carries only true GLOBAL rebuild
     * reasons — a changed chunker/pipeline version. On mismatch the indexer resets every area
     * cursor and re-reads everything (§11.22), which stays cheap because the diff-based
     * `replace_document()` keeps unchanged chunks physically untouched.
     *
     * @return string
     */
    public static function fingerprint(): string {
        return 'chunker:' . site_content_chunker::VERSION;
    }

    /**
     * The full ordered chunk list of one document: content chunks, then file-derived chunks.
     *
     * The array index is the chunk number (refindex) the store rows are addressed by.
     *
     * @param \core_search\base $areaobj The document's owning search area.
     * @param \core_search\document $doc The freshly built document (no files attached yet).
     * @param bool|null $includefiles Whether to run the file stage. Null (default) resolves via
     *              {@see files_active_for_area()}; hot loops (indexer, estimator sample) pass the
     *              per-area value they resolved ONCE to avoid a governance lookup per document.
     * @return array[] List of ['text' => string].
     */
    public static function document_chunks(
        \core_search\base $areaobj,
        \core_search\document $doc,
        ?bool $includefiles = null
    ): array {
        $chunks = site_content_chunker::chunk(site_content_chunker::assemble_document_text($doc));
        $includefiles = $includefiles ?? self::files_active_for_area($areaobj);
        // The caller-supplied value never overrides the hard capability guards.
        if (!$includefiles || !$areaobj->uses_file_indexing() || !self::extractor_available()) {
            return $chunks;
        }
        foreach (self::file_texts($areaobj, $doc) as $filetext) {
            foreach (site_content_chunker::chunk($filetext) as $chunk) {
                $chunks[] = $chunk;
            }
        }
        return $chunks;
    }

    /**
     * The extracted, header-prefixed text of every eligible attached file, in file-id order.
     *
     * Eligible: mimetype application/pdf, size <= MAX_FILE_BYTES (oversized files are skipped
     * silently by policy — that is a filter, not a failure). Extraction failures are fail-soft:
     * the file is skipped with a debugging() note and the document keeps its remaining chunks.
     *
     * @param \core_search\base $areaobj
     * @param \core_search\document $doc
     * @return string[]
     */
    private static function file_texts(\core_search\base $areaobj, \core_search\document $doc): array {
        try {
            // Plain File-API logic in every core implementation; a broken third-party override
            // must cost only its own file chunks, never the document.
            $areaobj->attach_files($doc);
            $files = $doc->get_files();
        } catch (\Throwable $e) {
            debugging(
                'Site-search file indexing: attach_files failed for area ' . $areaobj->get_area_id()
                    . ': ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
            return [];
        }

        // Deterministic order: ascending file id (get_files() is keyed by file id).
        ksort($files);

        $texts = [];
        $extractor = new pdf_text_extractor();
        foreach ($files as $file) {
            if (!($file instanceof \stored_file)) {
                continue;
            }
            if ($file->get_mimetype() !== 'application/pdf' || $file->get_filesize() > self::MAX_FILE_BYTES) {
                continue;
            }
            $temppath = null;
            try {
                $temppath = $file->copy_content_to_temp('bookingextension_agent', 'sitesearch_');
                $text = trim($extractor->extract($temppath, self::MAX_FILE_CHARS));
                if ($text !== '') {
                    $texts[] = 'FILE: ' . $file->get_filename() . "\n" . $text;
                }
            } catch (\Throwable $e) {
                debugging(
                    'Site-search file indexing: extraction failed for file ' . $file->get_filename()
                        . ' (id ' . $file->get_id() . '): ' . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
            } finally {
                if ($temppath !== null && file_exists($temppath)) {
                    @unlink($temppath);
                }
            }
        }
        return $texts;
    }
}
