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
 * Live indexing-effort estimator for the site-search governance page.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\sitesearch;

/**
 * Estimates the indexing effort of a search area x scope WITHOUT a single embedding call and
 * WITHOUT any price lookup — the estimate is chunk-count-only, surfaced as a traffic light
 * (blueprint §5b.4/§5b.5; no €/price dimension by explicit decision).
 *
 * Method (§5b.4 KORREKTUR — core_search offers no generic COUNT(*), and a direct base-table count
 * is wrong for most areas):
 *  - doccount: foreach over the area's PUBLIC `get_document_recordset(0, $scopecontext)` WITHOUT
 *    calling `get_document()` per row (the expensive path), closed in `finally`. Counting ABORTS
 *    once the red threshold is reached — each document yields at least one chunk, so the traffic
 *    light is red regardless of the average — and the result is flagged `capped` (display ">N").
 *  - Ø-chunks: `get_document()` on a sample of min(30, doccount) documents inside a short
 *    {@see task_search_session} bracket (§11.26 — the estimator runs on the admin-only governance
 *    page), content length / ~2000 characters (≈500 tokens), no tokenizer, no API call. When the
 *    estimated scope carries the file-indexing flag and the area uses file indexing and an
 *    extractor is available, the sample instead counts the SHARED pipeline's chunks per document —
 *    file chunks included, so the traffic light honestly reflects file-inclusive chunk counts.
 *    DELIBERATELY keyed on the raw flag (not flag AND enabled): the estimate is the decision
 *    number BEFORE enabling a scope, so it must already show what the flag implies once the scope
 *    is switched on. Up to SAMPLE_SIZE bounded extractions, fail-soft per file inside the
 *    pipeline; without an extractor the content-only figure is used.
 *  - estchunks = doccount × Ø-chunks; ampel purely from estchunks against the two admin-set
 *    thresholds (defaults green < 2000, red > 20000).
 *
 * Scopes (context governance, sitesearch_context_governance_2026-07-02.md §5):
 *  - 'site': the whole area (today's behaviour).
 *  - 'course': one context-scoped recordset via `get_document_recordset(0, context_course)` —
 *    areas that do not implement context restriction throw in core and land in the fail-soft
 *    null path (estimate honestly "unavailable" instead of a wrong site-wide figure).
 *  - 'category': SUM over the courses on the category PATH (the category and all descendants),
 *    bounded twice: exact per-course figures for the first SCOPE_SUM_COURSE_LIMIT courses, and an
 *    abort once the accumulated estchunks reach the red threshold — both surface as `capped`
 *    (display ">N"). Per-course figures reuse the per-course MUC entries; the aggregate itself is
 *    NOT cached (it is a cheap loop over cached entries and must follow rule changes instantly).
 *
 * Raw counts are MUC-cached (MODE_APPLICATION, ttl 600) keyed area + scopetype + scopeid + red
 * threshold + file mode. Only the raw counts (doccount/capped/Ø) are cached; estchunks and the
 * traffic light are derived fresh so a changed green threshold shows immediately. The red
 * threshold participates in the cache key because the counting abort depends on it, and so does
 * the file mode because it changes Ø-chunks.
 */
class index_scope_estimator {
    /** Default green threshold: below this many estimated chunks an area is a small, safe enable. */
    public const DEFAULT_GREEN_THRESHOLD = 2000;

    /** Default red threshold: above this many estimated chunks PHP-cosine retrieval degrades. */
    public const DEFAULT_RED_THRESHOLD = 20000;

    /** Approximate characters per chunk (≈500 tokens via len/4). */
    private const CHARS_PER_CHUNK = 2000;

    /** Maximum documents sampled with get_document() for the Ø-chunk figure. */
    private const SAMPLE_SIZE = 30;

    /**
     * Maximum courses summed exactly for a category estimate or an effective-coverage figure;
     * beyond it the sum aborts and is flagged capped (">N" display — bounded, never silent).
     */
    private const SCOPE_SUM_COURSE_LIMIT = 20;

    /** MUC cache area (db/caches.php). */
    private const CACHE_AREA = 'sitesearchestimates';

    /** @var site_content_area_registry Area enumeration + instantiation. */
    private site_content_area_registry $registry;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->registry = new site_content_area_registry();
    }

    /**
     * The configured green threshold (estchunks below it = green).
     *
     * @return int
     */
    public function green_threshold(): int {
        $value = (int)get_config('bookingextension_agent', 'sitesearchampelgreen');
        return $value > 0 ? $value : self::DEFAULT_GREEN_THRESHOLD;
    }

    /**
     * The configured red threshold (estchunks above it = red; also the doc-count abort cap).
     *
     * @return int
     */
    public function red_threshold(): int {
        $value = (int)get_config('bookingextension_agent', 'sitesearchampelred');
        return $value > 0 ? $value : self::DEFAULT_RED_THRESHOLD;
    }

    /**
     * The traffic light for an estimated chunk count — shared derivation so summary figures
     * (effective coverage, page total) use exactly the same thresholds as single estimates.
     *
     * @param int $estchunks Estimated chunk count.
     * @param bool $capped Whether the count was aborted at a bound (">N" — red by definition).
     * @return string 'green' | 'yellow' | 'red'.
     */
    public function ampel_for(int $estchunks, bool $capped): string {
        if ($capped || $estchunks > $this->red_threshold()) {
            return 'red';
        }
        if ($estchunks < $this->green_threshold()) {
            return 'green';
        }
        return 'yellow';
    }

    /**
     * Estimate one enumerated area SITE-WIDE with the site row's raw file flag (preview
     * semantics — the site figure an admin looks at before flipping the site default).
     *
     * @param string $areakey Search area id (e.g. 'mod_page-activity').
     * @return array|null Null for unknown/unavailable/failing areas; otherwise
     *                    ['doccount' => int, 'capped' => bool, 'avgchunks' => float,
     *                     'estchunks' => int, 'ampel' => string ('green'|'yellow'|'red')].
     */
    public function estimate(string $areakey): ?array {
        $includefiles = (new sitesearch_scope_repository())->is_includefiles($areakey);
        return $this->estimate_for_scope(
            $areakey,
            sitesearch_scope_repository::SCOPETYPE_SITE,
            0,
            $includefiles
        );
    }

    /**
     * Estimate one enumerated area for one scope with an EXPLICIT file flag (the flag of the rule
     * being displayed or about to be saved — per-rule, not the area-global site flag).
     *
     * The wildcard area id '*' (§3.0) is accepted too: its figure is the bounded SUM of the
     * per-area estimates of every wildcard-covered area for the same scope
     * ({@see estimate_wildcard()}).
     *
     * @param string $areakey Search area id, or the wildcard '*'.
     * @param string $scopetype 'site' | 'course' | 'category' (repository SCOPETYPE_* constants).
     * @param int $scopeid Course/category id; 0 for 'site'.
     * @param bool $includefiles Whether the estimate must include file-derived chunks (only
     *                           effective for file-capable areas with an available extractor).
     * @return array|null Null for unknown areas, vanished scope targets and areas that cannot
     *                    deliver the scoped figure; otherwise the {@see estimate()} shape.
     */
    public function estimate_for_scope(string $areakey, string $scopetype, int $scopeid, bool $includefiles): ?array {
        if (site_content_area_registry::is_wildcard($areakey)) {
            return $this->estimate_wildcard($scopetype, $scopeid, $includefiles);
        }
        $areaobj = $this->registry->area_instance($areakey);
        if ($areaobj === null) {
            return null;
        }

        if ($scopetype === sitesearch_scope_repository::SCOPETYPE_CATEGORY) {
            return $this->estimate_category($areakey, $scopeid, $includefiles);
        }
        if (
            $scopetype !== sitesearch_scope_repository::SCOPETYPE_SITE
            && $scopetype !== sitesearch_scope_repository::SCOPETYPE_COURSE
        ) {
            throw new \coding_exception('Invalid site-search estimate scope type: ' . $scopetype);
        }

        $green = $this->green_threshold();
        $red = $this->red_threshold();
        $usefiles = $includefiles
            && $areaobj->uses_file_indexing()
            && site_content_chunk_pipeline::extractor_available();

        $cache = \cache::make('bookingextension_agent', self::CACHE_AREA);
        // Scope + file mode participate in the cache key (per-rule figures differ), and the
        // repository purges this cache on flag changes so the page shows fresh numbers.
        $cachekey = $areakey . '|' . $scopetype . '|' . $scopeid . '|' . $red . '|'
            . ($usefiles ? 'files' : 'nofiles');
        $counts = $cache->get($cachekey);
        if (!is_array($counts)) {
            try {
                $scopecontext = null;
                if ($scopetype === sitesearch_scope_repository::SCOPETYPE_COURSE) {
                    $scopecontext = \context_course::instance($scopeid);
                }
                $counts = $this->measure($areaobj, $scopecontext, $red, $usefiles);
            } catch (\Throwable $e) {
                // With the dynamic enumeration (§11.27) arbitrary third-party areas land here — a
                // single broken area (or one without context-scoped recordset support) must cost
                // only its own estimate, never the governance page.
                debugging(
                    'Site-search estimate failed for area ' . $areakey . ' (' . $scopetype . '/' . $scopeid . '): '
                        . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
                return null;
            }
            $cache->set($cachekey, $counts);
        }

        return $this->derive($counts, $green, $red);
    }

    /**
     * Effective coverage of one area over its ACTIVE governance rules: how many courses the rule
     * cascade currently allows, and the summed per-course chunk estimate — bounded exactly like
     * the category sum (first SCOPE_SUM_COURSE_LIMIT courses exact, abort at the red threshold;
     * both flagged `capped` for ">N" display, no silent caps).
     *
     * Per-course file inclusion follows the CASCADE (`resolver->effective()`), not any single
     * rule — the figure is the honest cost of what the indexer will actually do. For a pure site
     * rule without exclusions the site-wide estimate is reused (better quality AND cheaper than a
     * truncated per-course sum over the whole site).
     *
     * 'other'-support areas have no course dimension: their coverage is the site estimate when
     * the site row is enabled ('courses' stays null there, §9).
     *
     * @param string $areakey Search area id.
     * @return array|null Null when no rule grants any coverage (resolver strategy 'off') or the
     *                    area is unknown; otherwise
     *                    ['courses' => int|null, 'estchunks' => int, 'capped' => bool,
     *                     'measured' => bool, 'ampel' => string] — 'measured' false means the
     *                    area cannot deliver scoped figures (display "unavailable", counts as
     *                    capped in totals).
     */
    public function estimate_effective(string $areakey): ?array {
        if ($this->registry->area_instance($areakey) === null) {
            return null;
        }
        $resolver = new sitesearch_scope_resolver();
        $shape = $resolver->shape($areakey);
        if (($shape['strategy'] ?? 'off') === 'off') {
            return null;
        }

        $sitedefault = (array)($shape['sitedefault'] ?? ['enabled' => false, 'includefiles' => false]);

        // Areas without a course context: only the site row is effective (§9).
        if ($this->registry->contextsupport_for($areakey) === site_content_area_registry::SUPPORT_OTHER) {
            if (empty($sitedefault['enabled'])) {
                return null;
            }
            $estimate = $this->estimate_for_scope(
                $areakey,
                sitesearch_scope_repository::SCOPETYPE_SITE,
                0,
                !empty($sitedefault['includefiles'])
            );
            return $this->effective_from_site($estimate, null);
        }

        // Pure site rule (blocklist without exclusions): the site-wide figure IS the effective
        // figure — exact instead of a truncated per-course sum over every course of the site.
        $courseids = $resolver->allowed_courseids($areakey);
        if (($shape['strategy'] ?? '') === 'blocklist' && empty($shape['excludedcourseids'])) {
            $estimate = $this->estimate_for_scope(
                $areakey,
                sitesearch_scope_repository::SCOPETYPE_SITE,
                0,
                !empty($sitedefault['includefiles'])
            );
            return $this->effective_from_site($estimate, count($courseids));
        }

        return $this->sum_courses($areakey, $courseids, function (int $courseid) use ($areakey, $resolver): bool {
            $effective = $resolver->effective($areakey, $courseid);
            return !empty($effective['includefiles']);
        });
    }

    /**
     * Map a site-wide estimate onto the effective-coverage shape.
     *
     * @param array|null $estimate A {@see estimate_for_scope()} result (site scope).
     * @param int|null $courses Allowed course count, or null for course-less areas.
     * @return array The {@see estimate_effective()} shape.
     */
    private function effective_from_site(?array $estimate, ?int $courses): array {
        if ($estimate === null) {
            return ['courses' => $courses, 'estchunks' => 0, 'capped' => true, 'measured' => false, 'ampel' => 'red'];
        }
        return [
            'courses' => $courses,
            'estchunks' => (int)$estimate['estchunks'],
            'capped' => (bool)$estimate['capped'],
            'measured' => true,
            'ampel' => (string)$estimate['ampel'],
        ];
    }

    /**
     * Wildcard-rule estimate (§3.0): the bounded SUM of the per-area estimates of every
     * wildcard-covered area for one scope — the decision figure of an "all content areas" rule.
     *
     * Each per-area figure is a normal {@see estimate_for_scope()} call, so the per-area MUC
     * entries are reused (and pre-warmed for the rule list). Bounded twice: any capped sub-figure
     * propagates, and the accumulation aborts once it reaches the red threshold (">N" either
     * way). A sub-area that cannot deliver the scoped figure (null — e.g. a third-party area
     * without context-restriction support) is SKIPPED and flagged capped: the sum stays an
     * honest floor instead of one broken area killing the whole wildcard estimate. Only when NO
     * area at all could be measured (e.g. vanished category target) is null returned.
     *
     * @param string $scopetype 'site' | 'course' | 'category' (repository SCOPETYPE_* constants).
     * @param int $scopeid Course/category id; 0 for 'site'.
     * @param bool $includefiles File flag of the wildcard rule (applied to every covered area;
     *                           only effective for file-capable areas, as usual).
     * @return array|null Null when no covered area could be measured; otherwise the
     *                    {@see estimate()} shape.
     */
    private function estimate_wildcard(string $scopetype, int $scopeid, bool $includefiles): ?array {
        $red = $this->red_threshold();
        $doccount = 0;
        $estchunks = 0;
        $capped = false;
        $measuredany = false;

        foreach ($this->registry->wildcard_covered_area_keys() as $areakey) {
            $sub = $this->estimate_for_scope($areakey, $scopetype, $scopeid, $includefiles);
            if ($sub === null) {
                $capped = true;
                continue;
            }
            $measuredany = true;
            $doccount += (int)$sub['doccount'];
            $estchunks += (int)$sub['estchunks'];
            $capped = $capped || !empty($sub['capped']);
            if ($estchunks >= $red) {
                // Red regardless of the remaining areas: abort, flag ">N".
                $capped = true;
                break;
            }
        }

        if (!$measuredany) {
            return null;
        }
        return [
            'doccount' => $doccount,
            'capped' => $capped,
            'avgchunks' => $doccount > 0 ? $estchunks / $doccount : 1.0,
            'estchunks' => $estchunks,
            'ampel' => $this->ampel_for($estchunks, $capped),
        ];
    }

    /**
     * Category estimate: bounded sum of the per-course estimates over the category PATH (the
     * category itself plus all descendant categories — path-based inheritance, blueprint §3).
     *
     * @param string $areakey Search area id.
     * @param int $categoryid Course category id.
     * @param bool $includefiles File flag of the rule being estimated (applied to every course).
     * @return array|null Null when the category vanished or the area cannot deliver scoped
     *                    figures; otherwise the {@see estimate()} shape.
     */
    private function estimate_category(string $areakey, int $categoryid, bool $includefiles): ?array {
        $category = \core_course_category::get($categoryid, IGNORE_MISSING, true);
        if ($category === null) {
            return null;
        }
        $courseids = $this->category_course_ids($category);
        $sum = $this->sum_courses($areakey, $courseids, static function () use ($includefiles): bool {
            return $includefiles;
        });
        if ($sum === null || !$sum['measured']) {
            return null;
        }
        return [
            'doccount' => (int)$sum['doccount'],
            'capped' => (bool)$sum['capped'],
            'avgchunks' => $sum['doccount'] > 0 ? $sum['estchunks'] / $sum['doccount'] : 1.0,
            'estchunks' => (int)$sum['estchunks'],
            'ampel' => (string)$sum['ampel'],
        ];
    }

    /**
     * Bounded per-course sum shared by the category estimate and the effective coverage: exact
     * per-course figures (each one MUC-cached under its own course key) for the first
     * SCOPE_SUM_COURSE_LIMIT courses, aborting early once the accumulated estchunks reach the
     * red threshold. Any truncation surfaces as `capped`.
     *
     * @param string $areakey Search area id.
     * @param array $courseids Course ids to sum over (ints).
     * @param callable $includefilesfor Callback (int courseid): bool — per-course file flag.
     * @return array|null Null when the area is unknown; otherwise
     *                    ['courses' => int, 'doccount' => int, 'estchunks' => int,
     *                     'capped' => bool, 'measured' => bool, 'ampel' => string].
     */
    private function sum_courses(string $areakey, array $courseids, callable $includefilesfor): ?array {
        $red = $this->red_threshold();
        $doccount = 0;
        $estchunks = 0;
        $capped = false;
        $measured = true;
        $summed = 0;

        foreach ($courseids as $courseid) {
            if ($summed >= self::SCOPE_SUM_COURSE_LIMIT) {
                // More courses than the exact-sum bound: an honest ">N", never a silent cap.
                $capped = true;
                break;
            }
            $summed++;
            $sub = $this->estimate_for_scope(
                $areakey,
                sitesearch_scope_repository::SCOPETYPE_COURSE,
                (int)$courseid,
                (bool)$includefilesfor((int)$courseid)
            );
            if ($sub === null) {
                // The area cannot deliver context-scoped figures (no per-course recordset): the
                // whole sum is unavailable — surfacing a partial figure would just be wrong.
                $measured = false;
                $capped = true;
                break;
            }
            $doccount += (int)$sub['doccount'];
            $estchunks += (int)$sub['estchunks'];
            $capped = $capped || !empty($sub['capped']);
            if ($estchunks >= $red) {
                // Red regardless of the remaining courses: abort, flag ">N".
                $capped = true;
                break;
            }
        }

        return [
            'courses' => count($courseids),
            'doccount' => $doccount,
            'estchunks' => $estchunks,
            'capped' => $capped,
            'measured' => $measured,
            'ampel' => $this->ampel_for($estchunks, $capped),
        ];
    }

    /**
     * All course ids of a category and its descendants (path-based, matching the rule cascade).
     *
     * @param \core_course_category $category
     * @return array Course ids (ints, ordered by id for deterministic bounded sums).
     */
    private function category_course_ids(\core_course_category $category): array {
        global $DB;

        $categoryids = array_merge([(int)$category->id], array_map('intval', $category->get_all_children_ids()));
        [$insql, $params] = $DB->get_in_or_equal($categoryids);
        $ids = $DB->get_fieldset_sql(
            'SELECT id FROM {course} WHERE category ' . $insql . ' ORDER BY id ASC',
            $params
        );
        return array_map('intval', $ids);
    }

    /**
     * Derive the displayable estimate from raw (cached) counts — fresh every call, so a changed
     * green threshold shows immediately.
     *
     * @param array $counts ['doccount' => int, 'capped' => bool, 'avgchunks' => float].
     * @param int $green Green threshold.
     * @param int $red Red threshold.
     * @return array The {@see estimate()} shape.
     */
    private function derive(array $counts, int $green, int $red): array {
        $estchunks = (int)ceil($counts['doccount'] * $counts['avgchunks']);
        if ($counts['capped'] || $estchunks > $red) {
            $ampel = 'red';
        } else if ($estchunks < $green) {
            $ampel = 'green';
        } else {
            $ampel = 'yellow';
        }

        return [
            'doccount' => (int)$counts['doccount'],
            'capped' => (bool)$counts['capped'],
            'avgchunks' => (float)$counts['avgchunks'],
            'estchunks' => $estchunks,
            'ampel' => $ampel,
        ];
    }

    /**
     * The uncached measurement: capped document count + sampled Ø-chunks.
     *
     * @param \core_search\base $areaobj
     * @param \context|null $scopecontext Null = site-wide; a course context narrows the recordset.
     * @param int $red Doc-count abort cap (red threshold).
     * @param bool $usefiles Whether the Ø-chunk sample must include file-derived chunks.
     * @return array ['doccount' => int, 'capped' => bool, 'avgchunks' => float]
     */
    private function measure(\core_search\base $areaobj, ?\context $scopecontext, int $red, bool $usefiles): array {
        // Cheap-as-possible counting: iterate the recordset WITHOUT get_document() per row.
        $doccount = 0;
        $capped = false;
        $recordset = $areaobj->get_document_recordset(0, $scopecontext);
        if (!$recordset) {
            return ['doccount' => 0, 'capped' => false, 'avgchunks' => 1.0];
        }
        try {
            foreach ($recordset as $unused) {
                $doccount++;
                if ($doccount >= $red) {
                    // Every document yields >= 1 chunk, so estchunks >= red already: abort.
                    $capped = true;
                    break;
                }
            }
        } finally {
            $recordset->close();
        }

        if ($doccount === 0) {
            return ['doccount' => 0, 'capped' => false, 'avgchunks' => 1.0];
        }

        $avgchunks = $this->sample_average_chunks(
            $areaobj,
            $scopecontext,
            min(self::SAMPLE_SIZE, $doccount),
            $usefiles
        );
        return ['doccount' => $doccount, 'capped' => $capped, 'avgchunks' => $avgchunks];
    }

    /**
     * Ø-chunks over a bounded document sample — get_document() inside a short engine-session
     * bracket (§11.26), synchronous, admin-page scope, released in `finally` even on area errors.
     *
     * With active file indexing the sample runs the shared chunk pipeline per document (bounded:
     * <= $samplesize documents, PDF extraction fail-soft inside the pipeline); otherwise the
     * cheap content-length division is kept.
     *
     * @param \core_search\base $areaobj
     * @param \context|null $scopecontext
     * @param int $samplesize How many documents to sample (>= 1).
     * @param bool $usefiles Whether to count the shared pipeline's file-inclusive chunks.
     * @return float Average chunks per document (>= 1.0).
     */
    private function sample_average_chunks(
        \core_search\base $areaobj,
        ?\context $scopecontext,
        int $samplesize,
        bool $usefiles
    ): float {
        $totalchunks = 0;
        $sampled = 0;

        task_search_session::begin();
        try {
            $recordset = $areaobj->get_document_recordset(0, $scopecontext);
            if (!$recordset) {
                return 1.0;
            }
            try {
                foreach ($recordset as $record) {
                    $doc = $areaobj->get_document($record);
                    if ($doc instanceof \core_search\document) {
                        if ($usefiles) {
                            // File-inclusive truth: count the SHARED pipeline's actual chunks.
                            // The per-rule flag is passed explicitly (preview semantics instead
                            // of flag-AND-enabled).
                            $count = count(site_content_chunk_pipeline::document_chunks($areaobj, $doc, true));
                        } else {
                            $count = (int)ceil($this->content_length($doc) / self::CHARS_PER_CHUNK);
                        }
                        $totalchunks += max(1, $count);
                        $sampled++;
                    }
                    if ($sampled >= $samplesize) {
                        break;
                    }
                }
            } finally {
                $recordset->close();
            }
        } finally {
            task_search_session::end();
        }

        return $sampled > 0 ? $totalchunks / $sampled : 1.0;
    }

    /**
     * Approximate indexable content length of a document — the same field set the indexer embeds
     * (title + content + description1; description2 is deliberately out). Only used for the
     * cheap content-only sample; with active file indexing the pipeline count is used instead.
     *
     * @param \core_search\document $doc
     * @return int
     */
    private function content_length(\core_search\document $doc): int {
        $length = 0;
        foreach (['title', 'content', 'description1'] as $field) {
            if ($doc->is_set($field)) {
                $length += strlen(trim((string)$doc->get($field)));
            }
        }
        return $length;
    }
}
