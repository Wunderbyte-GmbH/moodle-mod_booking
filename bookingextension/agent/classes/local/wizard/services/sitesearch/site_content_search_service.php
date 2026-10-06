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
 * Access-gated semantic retrieval over the site-content index.
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
use bookingextension_agent\local\wizard\services\retrieval\embedding_hit;
use bookingextension_agent\local\wizard\services\retrieval\embeddings_store_factory;
use bookingextension_agent\local\wizard\services\retrieval\site_content_row_mapper;
use bookingextension_agent\local\wizard\wb_action_names;

/**
 * Retrieves site content for the CURRENT user, enforcing access with two independent, fail-safe gates:
 *
 *  1. An engine-free context prefilter ({@see site_access_context_lister}) narrows candidates by the
 *     user's visible module contexts (recall/perf only — never an allow-list). It fail-closes: a user
 *     with no visible contexts gets an empty filter → zero rows.
 *  2. The AUTHORITATIVE per-hit gate: for every candidate the owning core_search area's
 *     `check_access()` is run and only `ACCESS_GRANTED` survives. This is the sole authority.
 *
 * Both gates evaluate the current `$USER`. The service is hard-guarded to the DB backend: the CSV
 * backend ignores the retrieval filter and would return cross-user content, so it returns nothing there.
 * The query embedder is injectable for provider-free testing.
 */
class site_content_search_service {
    /** Minimum cosine similarity to include a result. */
    private const MIN_SCORE = 0.30;

    /** Candidate over-fetch multiplier before the authoritative access gate. */
    private const OVERFETCH = 5;

    /**
     * Over-fetch multiplier when an area restriction is active: the area filter discards
     * candidates before the access gate, so more headroom is needed to still fill K results.
     */
    private const OVERFETCH_AREA_RESTRICTED = 8;

    /** Display budget for a snippet, in characters (multibyte-safe). */
    private const SNIPPET_CHARS = 300;

    /** Budget for the assembled document text per hit, in characters (multibyte-safe). */
    private const DOCUMENT_TEXT_CHARS = 6000;

    /** @var callable Query embedder: fn(string $text, int $contextid, int $userid, int $dims): ?array. */
    private $embedder;

    /** @var site_content_area_registry Area enumeration + enablement. */
    private site_content_area_registry $registry;

    /** @var site_access_context_lister Engine-free context prefilter. */
    private site_access_context_lister $lister;

    /** @var sitesearch_scope_resolver Effective-rule resolver (governance transition hardening). */
    private sitesearch_scope_resolver $resolver;

    /**
     * Constructor.
     *
     * @param callable|null $embedder Injectable query embedder (defaults to the Wunderbyte provider).
     */
    public function __construct(?callable $embedder = null) {
        $this->registry = new site_content_area_registry();
        $this->lister = new site_access_context_lister();
        $this->resolver = new sitesearch_scope_resolver();
        $this->embedder = $embedder ?? [$this, 'default_embed'];
    }

    /**
     * Default query embedder: the Wunderbyte embeddings provider via the LLM call service.
     *
     * @param string $text
     * @param int $contextid
     * @param int $userid
     * @param int $dims
     * @return float[]|null
     */
    public function default_embed(string $text, int $contextid, int $userid, int $dims): ?array {
        $llm = new llm_call_service(new conversation_store());
        $call = $llm->invoke_embeddings_for_context(0, $contextid, $userid, 'site_search', $text, $dims);
        if (empty($call['success']) || empty($call['embedding'])) {
            return null;
        }
        return (array)$call['embedding'];
    }

    /**
     * Whether retrieval may run: MUST be the DB backend (the CSV backend ignores the access filter),
     * and the embeddings provider must be present.
     *
     * @return bool
     */
    public function is_ready(): bool {
        if (get_config('bookingextension_agent', 'embeddingsstore') !== 'db') {
            return false;
        }
        return class_exists(wb_action_names::GENERATE_EMBEDDINGS);
    }

    /**
     * Search the site-content index for the current user, access-gated.
     *
     * @param string $query
     * @param int $contextid Context for the query-embedding provider call (0 = system).
     * @param int $k Maximum results.
     * @param array|null $areaids Optional restriction to already-normalized area ids
     *              ({@see site_content_area_registry::normalize_area_refs()}); hits owned by other
     *              areas are skipped before the access gate. Null = no restriction.
     * @param int|null $courseid Optional restriction to one course: the access prefilter only
     *              collects that course's contexts — for site admins too (see
     *              {@see site_access_context_lister::allowed_context_filter()}).
     * @param bool $includedocumenttext Also carry the assembled full document text per hit
     *              ('documenttext', capped at DOCUMENT_TEXT_CHARS) — nearly free, since the source
     *              document is already re-read for the snippet.
     * @return array[] Each: area, docid, courseid, contextid, title, url, score, snippet, stale,
     *              chunktext (the full matched chunk), documenttext ('' unless requested).
     */
    public function search(
        string $query,
        int $contextid = 0,
        int $k = 5,
        ?array $areaids = null,
        ?int $courseid = null,
        bool $includedocumenttext = false
    ): array {
        global $USER;

        $query = trim($query);
        if ($query === '' || $k < 1 || !$this->is_ready()) {
            return [];
        }

        // An empty restriction set means "no restriction" (mirrors normalize_area_refs(): the
        // lenient normalizer returns null, never [], when nothing matches). Same for a
        // non-positive course id.
        if ($areaids === []) {
            $areaids = null;
        }
        if ($courseid !== null && $courseid <= 0) {
            $courseid = null;
        }

        // A 0/invalid context id would make context::instance_by_id() throw inside the embedding call
        // (caught → silent empty result). Fall back to the system context for the provider call.
        if ($contextid <= 0) {
            $contextid = (int)\context_system::instance()->id;
        }

        $resolved = (new embeddings_action_config_resolver())->resolve();
        $model = (string)$resolved['model'];
        $dims = (int)$resolved['dimensions'];

        $userid = (int)$USER->id;
        $vector = ($this->embedder)($query, $contextid, $userid, $dims);
        if ($vector === null || empty($vector)) {
            return [];
        }

        $area = site_content_row_mapper::AREA;
        $store = embeddings_store_factory::instance();
        $filter = $this->lister->allowed_context_filter($userid, $this->registry->enabled_access_descriptor(), $courseid);

        // Over-fetch candidates: the prefilter is deliberately permissive (it omits, e.g., group
        // separation), so we score more than we need and let the authoritative gate trim. An area
        // restriction discards additional candidates, so it gets extra headroom.
        $multiplier = ($areaids === null) ? self::OVERFETCH : self::OVERFETCH_AREA_RESTRICTED;
        $overfetch = max($k * $multiplier, $k);
        $hits = $store->search_top_k($area, $model, $dims, $vector, $overfetch, self::MIN_SCORE, $filter);

        // A disabled (but still-indexed) area must not be served, even before the next rebuild prunes
        // its rows — this closes the window for a site admin (who gets the global filter).
        $enabledareas = $this->registry->enabled_area_keys();

        $results = [];
        $snippetjobs = [];
        foreach ($hits as $hit) {
            // The caller's area restriction: cheap skip BEFORE the per-hit access check.
            if ($areaids !== null && !in_array($hit->owner, $areaids, true)) {
                continue;
            }
            if (!in_array($hit->owner, $enabledareas, true)) {
                continue;
            }
            // Governance transition hardening (context-governance blueprint §4.3): a course whose
            // rule was JUST disabled may still have rows in the index until the delta-sync prune
            // runs — drop such hits here so just-disabled content vanishes from results at once.
            // Cheap: the resolver memoizes per (area, course). The effective includefiles flag is
            // carried into the snippet re-extraction so query-time chunking matches index-time.
            $effective = $this->resolver->effective($hit->owner, (int)$hit->courseid);
            if (!$effective['enabled']) {
                continue;
            }
            $areaobj = $this->registry->area_instance($hit->owner);
            if ($areaobj === null) {
                continue;
            }
            // AUTHORITATIVE, engine-free per-hit access check for the current user.
            if ((int)$areaobj->check_access((int)$hit->docid) !== \core_search\manager::ACCESS_GRANTED) {
                continue;
            }
            $results[] = [
                'area' => $hit->owner,
                'docid' => (int)$hit->docid,
                'courseid' => (int)$hit->courseid,
                'contextid' => (int)$hit->contextid,
                'title' => $hit->title,
                'url' => $this->deep_link($hit),
                'score' => (int)round($hit->score * 1000),
                'snippet' => '',
                'stale' => false,
                'chunktext' => '',
                'documenttext' => '',
            ];
            $snippetjobs[] = [
                'index' => count($results) - 1,
                'hit' => $hit,
                'area' => $areaobj,
                'includefiles' => $effective['includefiles'],
            ];
            if (count($results) >= $k) {
                break;
            }
        }

        // Query-time snippet re-extraction for the final <=K results (blueprint §5 Storage-Note /
        // §11.26): the store holds no content, so the snippet is re-read from the live source.
        $this->add_snippets($results, $snippetjobs, $model, $dims, $includedocumenttext);
        return $results;
    }

    /**
     * Re-extract the snippet for each final result in ONE short-clamped engine session.
     *
     * FAIL-SOFT is mandatory (blueprint §5 Storage-Note): any per-hit problem means that hit
     * simply carries no snippet — no exception ever escapes into search().
     *
     * @param array[] $results Result rows (modified in place: 'snippet', 'stale', 'chunktext',
     *              and — when requested — 'documenttext').
     * @param array[] $jobs Per result: index into $results, the hit DTO, the area instance.
     * @param string $model Embedding model (part of the stored content hash).
     * @param int $dims Embedding dimensions (part of the stored content hash).
     * @param bool $includedocumenttext Also fill 'documenttext' (capped assembled document text).
     * @return void
     */
    private function add_snippets(
        array &$results,
        array $jobs,
        string $model,
        int $dims,
        bool $includedocumenttext = false
    ): void {
        if ($jobs === []) {
            return;
        }
        task_search_session::begin();
        try {
            foreach ($jobs as $job) {
                try {
                    $extract = $this->extract_chunk(
                        $job['area'],
                        $job['hit'],
                        $model,
                        $dims,
                        (bool)$job['includefiles']
                    );
                } catch (\Throwable $e) {
                    // Fail-soft: this hit keeps snippet '' / stale false; the result itself stands.
                    continue;
                }
                if ($extract === null) {
                    continue;
                }
                $results[$job['index']]['snippet'] = $this->display_snippet($extract['text']);
                $results[$job['index']]['stale'] = $extract['stale'];
                $results[$job['index']]['chunktext'] = $extract['text'];
                if ($includedocumenttext) {
                    $results[$job['index']]['documenttext'] = \core_text::substr(
                        $extract['documenttext'],
                        0,
                        self::DOCUMENT_TEXT_CHARS
                    );
                }
            }
        } finally {
            task_search_session::end();
        }
    }

    /**
     * Re-extract the embedded chunk of one hit from the live source document.
     *
     * Must run inside the engine session ({@see add_snippets}). Fetches the single source record
     * via the area's context-scoped recordset (K is <=5 and a module context holds one document,
     * so iterating until the docid matches is bounded), then re-runs the SHARED
     * {@see site_content_chunk_pipeline} — content chunks plus, when file indexing is effective
     * for the hit's course, the attached PDFs (§14.2, one extraction per file-carrying hit; the
     * surrounding fail-soft absorbs any extraction problem) — with the same chunker (aligned by
     * the store fingerprint, §11.22) and the same per-course file mode as the indexer (the
     * caller passes the resolver's effective includefiles for the hit's course).
     *
     * Self-heal check: the recomputed content hash equal to the stored one PROVES the snippet is
     * the embedded chunk. On mismatch the current chunk text is still shown (best effort) and the
     * result is flagged stale — deliberately with no extra persistence: changed source content has
     * a newer timemodified, so the incremental indexer cursor picks the document up on its next
     * run anyway (staleness is solved by indexing freshness, blueprint §5 Storage-Note).
     *
     * @param \core_search\base $areaobj The hit's owning search area.
     * @param embedding_hit $hit
     * @param string $model
     * @param int $dims
     * @param bool $includefiles The hit's effective per-course file-indexing state.
     * @return array|null ['text' => string, 'stale' => bool, 'documenttext' => string] (the
     *              UNCAPPED assembled document text), or null when no snippet is possible.
     */
    private function extract_chunk(
        \core_search\base $areaobj,
        embedding_hit $hit,
        string $model,
        int $dims,
        bool $includefiles
    ): ?array {
        if ((int)$hit->contextid <= 0) {
            return null;
        }
        $ctx = \context::instance_by_id((int)$hit->contextid, IGNORE_MISSING);
        if (!$ctx) {
            return null;
        }

        $doc = null;
        $recordset = $areaobj->get_document_recordset(0, $ctx);
        if (!$recordset) {
            return null;
        }
        try {
            foreach ($recordset as $record) {
                $candidate = $areaobj->get_document($record);
                if ($candidate instanceof \core_search\document && (int)$candidate->get('itemid') === (int)$hit->docid) {
                    $doc = $candidate;
                    break;
                }
            }
        } finally {
            $recordset->close();
        }
        if ($doc === null) {
            return null;
        }

        // The 'documenttext' payload stays the assembled CONTENT text (title/content/description1)
        // — file text is chunk-addressable via the pipeline but not part of the document text.
        $documenttext = site_content_chunker::assemble_document_text($doc);
        $chunks = site_content_chunk_pipeline::document_chunks($areaobj, $doc, $includefiles);
        if ($chunks === []) {
            return null;
        }
        // Fail-soft: the embedded chunk number vanished (content shrank) -> first chunk instead.
        $chunk = $chunks[$hit->refindex] ?? $chunks[0];
        $chunktext = (string)$chunk['text'];
        $stale = site_content_chunker::content_hash($chunktext, $model, $dims) !== (string)$hit->contenthash;
        return ['text' => $chunktext, 'stale' => $stale, 'documenttext' => $documenttext];
    }

    /**
     * Truncate a snippet for display (multibyte-safe, preferring a word boundary).
     *
     * @param string $text Full chunk text.
     * @return string
     */
    private function display_snippet(string $text): string {
        $text = trim($text);
        if (\core_text::strlen($text) <= self::SNIPPET_CHARS) {
            return $text;
        }
        $cut = \core_text::substr($text, 0, self::SNIPPET_CHARS);
        $space = \core_text::strrpos($cut, ' ');
        if ($space !== false && (int)$space >= (int)(self::SNIPPET_CHARS * 0.6)) {
            $cut = \core_text::substr($cut, 0, (int)$space);
        }
        return rtrim($cut) . '…';
    }

    /**
     * Build a deep link to the hit's source, preferring the area's own `get_doc_url()`.
     *
     * Search-API route (blueprint §11.26 scope): a `\core_search\document` is constructed DIRECTLY
     * — the constructor is public and engine-free (document.php:216), so no factory and no engine
     * session are needed — populated with the fields `base_activity::get_context_url()` reads
     * (itemid via the constructor, plus courseid) and handed to the area. Any failure falls back
     * to the hand-built module URL.
     *
     * @param embedding_hit $hit
     * @return string
     */
    private function deep_link(embedding_hit $hit): string {
        try {
            $url = $this->search_api_link($hit);
            if ($url !== null) {
                return $url;
            }
        } catch (\Throwable $e) {
            // Fall through to the hand-built URL.
            unset($e);
        }
        return $this->fallback_link($hit);
    }

    /**
     * The area-owned deep link via a directly constructed document, or null when not resolvable.
     *
     * @param embedding_hit $hit
     * @return string|null
     */
    private function search_api_link(embedding_hit $hit): ?string {
        if ((int)$hit->docid <= 0) {
            return null;
        }
        $areaobj = $this->registry->area_instance($hit->owner);
        if ($areaobj === null) {
            return null;
        }
        // The owner IS the core_search area id '<component>-<areaname>'. A component frankenstyle
        // name never contains a dash, so the FIRST dash separates the two — any further dash
        // belongs to the area name (extract_areaid_parts is a plain explode, so re-join the tail).
        $parts = \core_search\manager::extract_areaid_parts($hit->owner);
        if (count($parts) < 2) {
            return null;
        }
        $component = (string)array_shift($parts);
        $areaname = implode('-', $parts);

        $doc = new \core_search\document((int)$hit->docid, $component, $areaname);
        if ((int)$hit->courseid > 0) {
            // The area's get_context_url() reads itemid (set by the constructor) + courseid.
            $doc->set('courseid', (int)$hit->courseid);
        }
        if ((int)$hit->contextid > 0) {
            $doc->set('contextid', (int)$hit->contextid);
        }
        $url = $areaobj->get_doc_url($doc);
        return ($url instanceof \moodle_url) ? $url->out(false) : null;
    }

    /**
     * Hand-built fallback link to the hit's module view page (from the stored module context).
     *
     * Only module areas have such a hand-buildable URL; for non-module hits (modname null — e.g.
     * course/section areas) the primary search-API path via `get_doc_url()` is the only link
     * source and this fallback yields '' by design.
     *
     * @param embedding_hit $hit
     * @return string
     */
    private function fallback_link(embedding_hit $hit): string {
        $modname = $this->registry->modname_for($hit->owner);
        if ($modname === null || (int)$hit->contextid <= 0) {
            return '';
        }
        $ctx = \context::instance_by_id((int)$hit->contextid, IGNORE_MISSING);
        if (!$ctx || (int)$ctx->contextlevel !== CONTEXT_MODULE) {
            return '';
        }
        return (new \moodle_url('/mod/' . $modname . '/view.php', ['id' => (int)$ctx->instanceid]))->out(false);
    }
}
