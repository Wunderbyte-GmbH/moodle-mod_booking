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
 * Documentation lookup service — semantic primary, lexical fallback.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\lookup;

use bookingextension_agent\local\wizard\embeddings_action_config_resolver;
use bookingextension_agent\local\wizard\orchestrator;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\services\llm\llm_call_service;
use bookingextension_agent\local\wizard\services\retrieval\docs_row_mapper;
use bookingextension_agent\local\wizard\services\retrieval\embeddings_store_factory;

/**
 * Provides semantic and lexical search over registered documentation corpora.
 *
 * Retrieval strategy (in priority order):
 *  1. Semantic search via pre-built embeddings index (language-agnostic, preferred).
 *  2. Lexical token search over on-disk markdown files (fallback when index not ready).
 *
 * Line-windowed reading allows incremental document consumption without loading
 * full files into the LLM context on each call.
 */
class docs_lookup_service {
    /** Line count that asks for the whole page in one window. */
    public const WHOLE_PAGE = 0;

    /** Characters a first read may carry: a page up to this size is read whole (70 of 90 pages, 2026-09-26). */
    public const FIRST_READ_CHAR_BUDGET = 6000;

    /** Default number of lines to return per read window. */
    private const DEFAULT_LINE_COUNT = 80;

    /** Maximum number of result candidates from semantic search. */
    private const SEMANTIC_TOP_K = 5;

    /** Minimum cosine similarity score (0–1) to include a semantic result. */
    private const SEMANTIC_MIN_SCORE = 0.30;

    /** Relative path of the per-corpus root entry document. */
    private const ROOT_DOC = 'README.md';

    /** @var docs_corpus_registry The corpus_id → absolute root authority. */
    private docs_corpus_registry $registry;

    /**
     * Constructor.
     *
     * The service is corpus-agnostic: it searches across every registered corpus and resolves each
     * hit's absolute root through the registry by the hit's own corpus_id. There is no single
     * "primary root" baked into the instance.
     *
     * @param docs_corpus_registry|null $registry Corpus registry (defaults to the discovered set).
     */
    public function __construct(?docs_corpus_registry $registry = null) {
        $this->registry = $registry ?? new docs_corpus_registry();
    }

    // -------------------------------------------------------------------------
    // Public API
    // Separator.

    /**
     * Search using semantic embeddings (primary path, language-agnostic).
     *
     * Generates an embedding for $question, finds the closest doc chunks in the
     * pre-built index via cosine similarity, and returns enriched result records.
     *
     * Returns an empty array when the embeddings index is not ready — callers
     * should fall back to search_multi() in that case.
     *
     * @param string $question  User question in any language.
     * @param int    $contextid Moodle context id (for the embedding API call).
     * @param int    $userid    User id (for the embedding API call).
     * @param int    $limit     Maximum results to return.
     * @return array[]
     */
    public function search_semantic(
        string $question,
        int $contextid,
        int $userid,
        int $limit = self::SEMANTIC_TOP_K
    ): array {
        $readiness = new docs_embeddings_readiness_service();
        if (!$readiness->is_index_ready()) {
            return [];
        }

        // Resolve the active embeddings variant (only same-model vectors are ever compared) and
        // generate the query embedding first (single call); skip the catalog scan on failure.
        // is_index_ready() above already confirmed the index is present and non-empty.
        $settings = (new embeddings_action_config_resolver())->resolve();
        $model = (string)($settings['model'] ?? orchestrator::EMBEDDINGS_DEFAULT_MODEL);
        $dimensions = (int)($settings['dimensions'] ?? orchestrator::EMBEDDINGS_DEFAULT_DIMENSIONS);

        $llm = new llm_call_service(new conversation_store());
        $embeddingcall = $llm->invoke_embeddings_for_context(
            0,
            $contextid,
            $userid,
            'core_explain_docs|semantic_search',
            $question,
            $dimensions
        );

        if (empty($embeddingcall['success']) || empty($embeddingcall['embedding'])) {
            return [];
        }

        $queryvec = (array)$embeddingcall['embedding'];

        // Rank across ALL corpora via the embeddings store (CSV or DB backend, selected by config).
        // search_top_k resolves the committed generation, streams + scores one row at a time (bounded
        // O(k) memory) and applies the minimum score internally — returning typed hits, never vectors.
        $store = embeddings_store_factory::instance();
        $hits = $store->search_top_k(
            docs_row_mapper::AREA,
            $model,
            $dimensions,
            $queryvec,
            max(1, $limit),
            self::SEMANTIC_MIN_SCORE
        );

        $results = [];
        $dangling = false;
        foreach ($hits as $hit) {
            // Typed store hit: cosine score (0–1), corpus in owner, chunk path in refkey.
            $score = $hit->score;
            $corpusid = trim($hit->owner);
            $relpath = $hit->refkey;
            $root = $this->registry->resolve_root($corpusid);
            if ($root === null) {
                // The index references a corpus that no longer resolves — a stale row. Drop it AND
                // flag for a rebuild so the index self-heals; never surface a broken reference.
                $dangling = true;
                continue;
            }
            $doc = $this->load_doc_meta($root, $relpath);
            if ($doc === null) {
                // The source file behind this hit is gone/unreadable (a removed doc whose row lingers).
                $dangling = true;
                continue;
            }

            $results[] = array_merge($doc, [
                'corpus_id' => $corpusid,
                'score' => (int)round($score * 1000),
                'search_method' => 'semantic',
            ]);
        }

        // Query-time defensive self-heal: a dangling hit means the index is out of sync with the
        // on-disk docs. Schedule a rebuild (debounced, gated, deduped via the shared scheduler) so the
        // next cron prunes the orphan row — without ever returning the broken reference to the caller.
        if ($dangling) {
            $readiness->ensure_rebuild_scheduled_if_needed();
        }

        return $results;
    }

    /**
     * Lexical multi-query search over on-disk markdown files.
     *
     * Runs each query independently, merges results keeping best score per path,
     * applies a small cross-query bonus for docs that appear in multiple queries.
     *
     * Used as fallback when the semantic index is not available, and optionally
     * as a second-opinion signal alongside semantic results for short/exact terms.
     *
     * @param string[] $queries
     * @param int      $limit
     * @return array[]
     */
    public function search_multi(array $queries, int $limit = 3): array {
        $queries = array_values(array_unique(array_filter(array_map('trim', $queries))));
        if (empty($queries)) {
            return [];
        }

        $merged = [];
        $hitcounts = [];

        foreach ($queries as $query) {
            $singleresults = $this->search_lexical($query, max($limit * 2, 10));
            foreach ($singleresults as $doc) {
                $path = (string)($doc['path'] ?? '');
                if ($path === '') {
                    continue;
                }
                // Key by corpus + path so identically named files in different corpora never collide.
                $key = (string)($doc['corpus_id'] ?? '') . '||' . $path;

                if (!isset($merged[$key])) {
                    $merged[$key] = $doc;
                    $hitcounts[$key] = 1;
                } else {
                    if ((int)($doc['score'] ?? 0) > (int)($merged[$key]['score'] ?? 0)) {
                        $merged[$key] = $doc;
                    }
                    $hitcounts[$key]++;
                }
            }
        }

        // Cross-query bonus: +15 per additional hit, capped at 2 extra = +30.
        foreach ($merged as $key => $doc) {
            $extrahits = min(2, ($hitcounts[$key] ?? 1) - 1);
            $merged[$key]['score'] = (int)($merged[$key]['score'] ?? 0) + ($extrahits * 15);
        }

        usort($merged, static function (array $a, array $b): int {
            $cmp = ((int)($b['score'] ?? 0)) <=> ((int)($a['score'] ?? 0));
            return $cmp !== 0 ? $cmp : strcmp((string)($a['path'] ?? ''), (string)($b['path'] ?? ''));
        });

        return array_slice(array_values($merged), 0, max(1, $limit));
    }

    /**
     * Read a documentation file by its relative path with line windowing.
     *
     * @param string $corpusid  Corpus the relpath belongs to (resolved to a root via the registry).
     * @param string $relpath   Relative path within that corpus root.
     * @param int    $linestart First line to return (1-based).
     * @param int    $linecount Maximum lines to return.
     * @return array|null  Doc payload (incl. corpus_id) or null if not found.
     */
    public function read_doc_by_path(
        string $corpusid,
        string $relpath,
        int $linestart = 1,
        int $linecount = self::DEFAULT_LINE_COUNT
    ): ?array {
        $corpusid = trim($corpusid);
        $root = $this->registry->resolve_root($corpusid);
        if ($root === null) {
            return null;
        }

        $relpath = $this->sanitize_rel_path($relpath);
        if ($relpath === '') {
            return null;
        }

        $abspath = $root . '/' . $relpath;
        if (!is_readable($abspath)) {
            return null;
        }

        $content = @file_get_contents($abspath);
        if ($content === false) {
            return null;
        }

        $doc = $this->build_windowed_doc($relpath, $content, $linestart, $linecount);
        $doc['corpus_id'] = $corpusid;
        return $doc;
    }

    /**
     * Read a doc by relative path when the corpus is not known up front, trying each registered
     * corpus in order (used for the planner's direct/candidate path without an explicit corpus_id).
     *
     * @param string $relpath
     * @param int    $linestart
     * @param int    $linecount
     * @return array|null
     */
    public function read_doc_any_corpus(
        string $relpath,
        int $linestart = 1,
        int $linecount = self::DEFAULT_LINE_COUNT
    ): ?array {
        foreach (array_keys($this->registry->list()) as $corpusid) {
            $doc = $this->read_doc_by_path((string)$corpusid, $relpath, $linestart, $linecount);
            if ($doc !== null) {
                return $doc;
            }
        }
        return null;
    }

    /**
     * Read the configured root entry document.
     *
     * @param int $linestart
     * @param int $linecount
     * @return array|null
     */
    public function read_root_doc(int $linestart = 1, int $linecount = self::DEFAULT_LINE_COUNT): ?array {
        $primary = $this->registry->primary();
        if ($primary === null) {
            return null;
        }
        return $this->read_doc_by_path($primary, self::ROOT_DOC, $linestart, $linecount);
    }

    /**
     * Build a short summary string from a doc payload.
     *
     * @param array $doc
     * @param string $outputlang
     * @param string $question
     * @return string
     */
    public function build_summary(array $doc, string $outputlang = '', string $question = ''): string {
        $excerpt = trim((string)($doc['excerpt'] ?? $doc['content'] ?? ''));
        if ($excerpt === '') {
            return '';
        }

        // Return first sentence from excerpt.
        if (preg_match('/^(.+?[.!?])(\s|$)/u', $excerpt, $matches)) {
            return trim($matches[1]);
        }

        return mb_substr($excerpt, 0, 200);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // Separator.

    /**
     * Perform a single-query lexical search over on-disk markdown files.
     *
     * @param string $question
     * @param int    $limit
     * @return array[]
     */
    private function search_lexical(string $question, int $limit = 5): array {
        $tokens = $this->extract_query_tokens($question);
        if (empty($tokens)) {
            return [];
        }

        $docs = [];
        foreach ($this->load_docs() as $doc) {
            $score = $this->score_doc($doc, $tokens, $question);
            if ($score <= 0) {
                continue;
            }
            $doc['score'] = $score;
            $doc['search_method'] = 'lexical';
            $docs[] = $doc;
        }

        usort($docs, static function (array $a, array $b): int {
            $cmp = ((int)($b['score'] ?? 0)) <=> ((int)($a['score'] ?? 0));
            return $cmp !== 0 ? $cmp : strcmp((string)($a['path'] ?? ''), (string)($b['path'] ?? ''));
        });

        return array_slice($docs, 0, max(1, $limit));
    }

    /**
     * Load metadata for all .md files in the docs root (without full content).
     *
     * @return array[]
     */
    private function load_docs(): array {
        $docs = [];

        foreach ($this->registry->list() as $corpusid => $root) {
            if (!is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $fileinfo) {
                if (!$fileinfo->isFile()) {
                    continue;
                }

                $abspath = $fileinfo->getPathname();
                if (strpos(str_replace('\\', '/', $abspath), '/pix/') !== false) {
                    continue;
                }

                if (strtolower($fileinfo->getExtension()) !== 'md') {
                    continue;
                }

                $content = @file_get_contents($abspath);
                if ($content === false) {
                    continue;
                }

                $relpath = ltrim(substr($abspath, strlen($root)), '/\\');
                $title = '';
                if (preg_match('/^#\s+(.+)$/m', $content, $m)) {
                    $title = trim($m[1]);
                }

                $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $content));
                $excerptlines = array_slice($lines, 0, 5);
                $excerpt = implode(' ', array_map('trim', $excerptlines));

                $docs[] = [
                    'corpus_id' => (string)$corpusid,
                    'path' => $relpath,
                    'basename' => strtolower(basename($relpath, '.md')),
                    'title' => $title,
                    'excerpt' => $excerpt,
                    'content' => $content,
                    'score' => 0,
                ];
            }
        }

        return $docs;
    }

    /**
     * Load minimal metadata for a single doc path (for semantic result enrichment).
     *
     * @param string $root    Absolute corpus root the relpath lives in.
     * @param string $relpath
     * @return array|null
     */
    private function load_doc_meta(string $root, string $relpath): ?array {
        $relpath = $this->sanitize_rel_path($relpath);
        if ($relpath === '') {
            return null;
        }

        $abspath = rtrim($root, '/\\') . '/' . $relpath;
        if (!is_readable($abspath)) {
            return null;
        }

        $content = @file_get_contents($abspath);
        if ($content === false) {
            return null;
        }

        $title = '';
        if (preg_match('/^#\s+(.+)$/m', $content, $m)) {
            $title = trim($m[1]);
        }

        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $content));
        $excerptlines = array_slice($lines, 0, 5);

        return [
            'path' => $relpath,
            'basename' => strtolower(basename($relpath, '.md')),
            'title' => $title,
            'excerpt' => implode(' ', array_map('trim', $excerptlines)),
            'content' => $content,
        ];
    }

    /**
     * Build a line-windowed document payload.
     *
     * @param string $relpath
     * @param string $content
     * @param int    $linestart 1-based start line.
     * @param int    $linecount Lines to include.
     * @return array
     */
    private function build_windowed_doc(string $relpath, string $content, int $linestart, int $linecount): array {
        $alllines = explode("\n", str_replace(["\r\n", "\r"], "\n", $content));
        $totallines = count($alllines);

        $linestart = max(1, $linestart);
        // WHOLE_PAGE (0) returns the page from $linestart to its end in one window (explain_docs whole_page).
        $linecount = $linecount === self::WHOLE_PAGE
            ? $totallines
            : max(10, min($linecount, self::DEFAULT_LINE_COUNT * 2));

        $slicedlines = array_slice($alllines, $linestart - 1, $linecount);
        $windowcontent = implode("\n", $slicedlines);
        $nextlinestart = $linestart + count($slicedlines);
        $hasmore = $nextlinestart <= $totallines;

        $title = '';
        if (preg_match('/^#\s+(.+)$/m', $content, $m)) {
            $title = trim($m[1]);
        }

        return [
            'path' => $relpath,
            'title' => $title,
            'content' => $windowcontent,
            'outline' => self::build_outline($alllines),
            'excerpt' => mb_substr($windowcontent, 0, 300),
            'line_start' => $linestart,
            'line_count' => count($slicedlines),
            'next_line_start' => $hasmore ? $nextlinestart : null,
            'has_more' => $hasmore,
            'total_lines' => $totallines,
            'score' => 0,
        ];
    }

    /**
     * The window of a first read (L43 ED-1, plan B, George 2026-09-26): a page up to the character budget is read
     * whole, a longer one from its start up to the budget. Reading the best-matching section first (plan A) was
     * measured and dropped: on a page about one topic the index ranked the page start first and the section that held
     * the answer fifth to seventh (scores 0.78 to 0.74).
     *
     * @param array $wholedoc A whole-page read (content) from build_windowed_doc.
     * @param int $charbudget
     * @return array{0: int, 1: int} First line and line count; line count WHOLE_PAGE for the whole page.
     */
    public static function first_window(array $wholedoc, int $charbudget): array {
        $content = (string)($wholedoc['content'] ?? '');
        if (mb_strlen($content) <= $charbudget) {
            return [1, self::WHOLE_PAGE];
        }
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $content));
        $chars = 0;
        $last = 1;
        foreach ($lines as $index => $line) {
            $chars += mb_strlen((string)$line) + 1;
            if ($chars > $charbudget && $index > 0) {
                break;
            }
            $last = $index + 1;
        }
        return [1, $last];
    }

    /**
     * The markdown headings of a whole document with their 1-based line numbers (fenced code is skipped).
     *
     * Lets a reader of one window see which sections lie outside it and where they start - the structure of the
     * document, not its words.
     *
     * @param string[] $lines
     * @return array<int, array{line: int, heading: string}>
     */
    private static function build_outline(array $lines): array {
        $outline = [];
        $infence = false;
        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*(\x60{3}|~{3})/', $line)) {
                $infence = !$infence;
                continue;
            }
            if (!$infence && preg_match('/^#{1,6}\s+\S/', $line)) {
                $outline[] = ['line' => $index + 1, 'heading' => rtrim($line)];
            }
        }
        return $outline;
    }

    /**
     * Score a document against query tokens using lexical heuristics.
     *
     * @param array $doc
     * @param string[]            $tokens
     * @param string              $question
     * @return int  Score ≥ 0; 0 means no match.
     */
    private function score_doc(array $doc, array $tokens, string $question): int {
        $path = mb_strtolower((string)($doc['path'] ?? ''));
        $title = mb_strtolower((string)($doc['title'] ?? ''));
        $excerpt = mb_strtolower((string)($doc['excerpt'] ?? ''));
        $content = mb_strtolower((string)($doc['content'] ?? ''));
        $basename = mb_strtolower((string)($doc['basename'] ?? ''));
        $questioncompact = preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($question)) ?? '';

        $score = 0;

        foreach ($tokens as $token) {
            if (mb_strpos($path, $token) !== false) {
                $score += 10;
            }
            if (mb_strpos($title, $token) !== false) {
                $score += 30;
            }
            if (mb_strpos($excerpt, $token) !== false) {
                $score += 20;
            }
            if (mb_strpos($content, $token) !== false) {
                $score += 5;
            }
        }

        // Exact basename match with full question.
        if (
            $questioncompact !== '' && mb_strpos(
                preg_replace('/[^\p{L}\p{N}]+/u', '', $basename) ?? '',
                $questioncompact
            ) !== false
        ) {
            $score += 50;
        }

        return $score;
    }

    /**
     * Extract significant tokens from a query (language-safe, min 3 chars).
     *
     * @param string $question
     * @return string[]
     */
    private function extract_query_tokens(string $question): array {
        $normalized = mb_strtolower($question);
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalized) ?? $normalized;
        $parts = preg_split('/\s+/', trim($normalized)) ?: [];

        $tokens = [];
        foreach ($parts as $part) {
            if ($part !== '' && mb_strlen($part) >= 3) {
                $tokens[] = $part;
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * Sanitize a relative path to prevent directory traversal.
     *
     * @param string $relpath
     * @return string  Sanitized path, or empty string if rejected.
     */
    private function sanitize_rel_path(string $relpath): string {
        $relpath = trim($relpath, '/\\');
        // Reject traversal attempts.
        if (strpos($relpath, '..') !== false) {
            return '';
        }

        // Only allow .md files.
        if (!preg_match('/\.md$/i', $relpath)) {
            return '';
        }

        return $relpath;
    }
}
