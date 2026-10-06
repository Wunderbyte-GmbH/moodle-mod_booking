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
 * Effective-rule resolver for the context-scoped site-search governance.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\sitesearch;

/**
 * Resolves the EFFECTIVE indexing rule of an area for a course from the governance scope rows
 * (context-governance blueprint §3, frozen contract §3.1).
 *
 * Cascade with specificity precedence — the most specific rule row wins COMPLETELY, i.e. with its
 * `enabled` AND its `includefiles` value as a pair (never a per-flag cascade):
 *
 *   course row  >  DEEPEST category row on the course's category PATH  >  site row  >  default OFF
 *
 * Wildcard rows (area '*', blueprint §3.0) extend the cascade with a LEXICOGRAPHIC tiebreaker —
 * scope specificity first, then area specificity: at every scope level the CONCRETE area row is
 * checked first, then the wildcard row; the first hit wins as a pair. So a wildcard course rule
 * beats a concrete site row (scope wins), while a concrete course row beats the wildcard course
 * row of the same course (same scope, concrete area wins). Wildcard rows apply exactly to the
 * areas of {@see site_content_area_registry::wildcard_covered_area_keys()} (course dimension,
 * i.e. contextsupport module|course); for site-only areas they are inert (§9). The wildcard rows
 * are read once per request (the '*' key of the request-static rules cache), never per area.
 *
 * Category inheritance is path-based: a rule on category K applies to every course in K and in all
 * of K's subcategories, until a deeper category row or a course row overrides it (resolved via the
 * `path` column of `{course_categories}`, never just the exact level). Without any mode switch this
 * covers both governance patterns: ALLOWLIST (site off + selected scopes on) and BLOCKLIST (site on
 * + selected exclusions off).
 *
 * Areas whose contextsupport is 'other' (user/message/block — no courseid dimension; concept doc
 * §9) are SITE-ONLY: only the site row applies to them, category/course rows are inert. Unknown
 * (not enumerated) areas are treated the same way, which keeps their scoped rows inert too.
 *
 * Source of truth is always `{bx_agent_search_scope}`; everything here is request-static caching on
 * top ({@see reset_request_cache()} — the scope repository resets it around every rule mutation).
 * The full-course classification paths ({@see allowed_courseids()}, {@see coverage_map()}, the
 * blocklist exclusions of {@see shape()}) are BOUNDED at O(courses): one minimal-field pass over
 * `{course}` (plus one over `{course_categories}` when category rules exist), classifying each
 * course through the in-memory cascade — no per-course queries. {@see effective()} for a single
 * course stays cheap (one rules read per area; course/category lookups only when scoped rules
 * exist, memoized).
 */
final class sitesearch_scope_resolver {
    /** The default pair when no rule anywhere grants anything. */
    private const OFF = ['enabled' => false, 'includefiles' => false];

    /** The empty rules group — the wildcard contribution for areas the wildcard does not cover. */
    private const NO_RULES = ['site' => null, 'category' => [], 'course' => []];

    /**
     * Request-static rule cache: area => ['site' => array|null, 'category' => array, 'course' => array]
     * ('category'/'course' map scopeid => pair).
     *
     * @var array
     */
    private static array $rules = [];

    /**
     * Request-static effective-pair memo: area => [courseid => pair].
     *
     * @var array
     */
    private static array $effective = [];

    /**
     * Request-static course => category id map (partial until {@see load_all_courses()} ran).
     *
     * @var array
     */
    private static array $coursecategories = [];

    /** @var bool Whether $coursecategories holds EVERY course (bulk load done). */
    private static bool $allcoursesloaded = false;

    /**
     * Request-static category id => path ids (root..self) memo.
     *
     * @var array
     */
    private static array $categorypaths = [];

    /** @var bool Whether $categorypaths holds EVERY category (bulk load done). */
    private static bool $allcategoriesloaded = false;

    /**
     * Drop every request-static cache — for tests and for the scope repository's mutation
     * chokepoint (rule writes must be visible to the very next resolution in the same request).
     *
     * @return void
     */
    public static function reset_request_cache(): void {
        self::$rules = [];
        self::$effective = [];
        self::$coursecategories = [];
        self::$allcoursesloaded = false;
        self::$categorypaths = [];
        self::$allcategoriesloaded = false;
    }

    /**
     * The effective rule pair of an area for one course: ['enabled' => bool, 'includefiles' => bool].
     *
     * Site-only areas ('other' contextsupport / unknown areas) and non-positive course ids resolve
     * to the site default (site row pair, or OFF without one).
     *
     * @param string $area core_search area id (e.g. 'mod_page-activity').
     * @param int $courseid Course id (0/negative = no course dimension → site default).
     * @return array ['enabled' => bool, 'includefiles' => bool]
     */
    public function effective(string $area, int $courseid): array {
        if (isset(self::$effective[$area][$courseid])) {
            return self::$effective[$area][$courseid];
        }
        $rules = $this->area_rules($area);
        // Wildcard rows participate only for areas with a course dimension (§3.0/§9); at every
        // scope level the concrete row is checked FIRST, then the wildcard row (tiebreaker).
        $wildcard = $this->is_siteonly($area) ? self::NO_RULES : $this->wildcard_rules();
        $sitepair = $rules['site'] ?? $wildcard['site'] ?? self::OFF;

        if ($courseid <= 0 || $this->is_siteonly($area)) {
            $pair = $sitepair;
        } else {
            // Course level: the concrete course row wins completely (enabled + includefiles as
            // a pair); without one, the wildcard course row of the same course.
            $pair = $rules['course'][$courseid] ?? $wildcard['course'][$courseid] ?? null;
            if ($pair === null && ($rules['category'] !== [] || $wildcard['category'] !== [])) {
                // Deepest category rule on the course's category path wins (path-based
                // inheritance): walk the path leaf-to-root, first hit decides — per path step
                // again concrete before wildcard.
                foreach (array_reverse($this->category_path_for_course($courseid)) as $categoryid) {
                    $candidate = $rules['category'][$categoryid] ?? $wildcard['category'][$categoryid] ?? null;
                    if ($candidate !== null) {
                        $pair = $candidate;
                        break;
                    }
                }
            }
            $pair = $pair ?? $sitepair;
        }

        self::$effective[$area][$courseid] = $pair;
        return $pair;
    }

    /**
     * The rule shape of an area for the indexer's strategy choice (frozen contract §3.1).
     *
     * Returns:
     *  - 'strategy': 'off' (no rule anywhere grants enablement — area completely inactive),
     *    'allowlist' (site default off, selected scopes on) or 'blocklist' (site default on,
     *    selected exclusions off).
     *  - 'allowedcourseids': the complete allowed course set (allowlist only, otherwise []).
     *  - 'excludedcourseids': the excluded course set (blocklist only, otherwise []).
     *  - 'sitedefault': the site row pair (OFF pair when no site row exists).
     *
     * @param string $area core_search area id.
     * @return array
     */
    public function shape(string $area): array {
        $rules = $this->area_rules($area);
        $wildcard = $this->is_siteonly($area) ? self::NO_RULES : $this->wildcard_rules();
        // Site default with the §3.0 tiebreaker: concrete site row first, then the wildcard one.
        $sitepair = $rules['site'] ?? $wildcard['site'] ?? self::OFF;
        $shape = [
            'strategy' => 'off',
            'allowedcourseids' => [],
            'excludedcourseids' => [],
            'sitedefault' => $sitepair,
        ];

        if ($this->is_siteonly($area)) {
            // Site-only area: category/course rows are inert, the site row alone decides. An
            // enabled site row means "index everything" — a blocklist scan with no exclusions.
            if ($sitepair['enabled']) {
                $shape['strategy'] = 'blocklist';
            }
            return $shape;
        }

        if ($sitepair['enabled']) {
            $shape['strategy'] = 'blocklist';
            if (
                $rules['category'] !== [] || $rules['course'] !== []
                || $wildcard['category'] !== [] || $wildcard['course'] !== []
            ) {
                // Only areas WITH scoped rules (own or wildcard) pay the O(courses) classification.
                foreach ($this->classified_courses($area) as $courseid => $pair) {
                    if (!$pair['enabled']) {
                        $shape['excludedcourseids'][] = $courseid;
                    }
                }
            }
            return $shape;
        }

        if (!$this->grants_any_enablement($rules) && !$this->grants_any_enablement($wildcard)) {
            // No row anywhere (own or wildcard) grants enablement → 'off' (default-off governance).
            return $shape;
        }

        $shape['strategy'] = 'allowlist';
        $shape['allowedcourseids'] = $this->allowed_courseids($area);
        return $shape;
    }

    /**
     * The complete allowed course set of an area (for the delta-sync diff and estimator sums).
     *
     * Bounded at O(courses): classifies every course once through the in-memory cascade (see the
     * class docblock). Site-only areas return [] — they have no course dimension, so scoped-rule
     * mutations on them never produce a delta. Fast path: an area without any enabling rule
     * returns [] without touching the course table.
     *
     * @param string $area core_search area id.
     * @return int[] Allowed course ids.
     */
    public function allowed_courseids(string $area): array {
        return array_keys($this->coverage_map($area));
    }

    /**
     * ADDITIVE helper (not part of the frozen §3.1 contract): the allowed course set of an area
     * WITH each course's effective includefiles flag — courseid => bool.
     *
     * This is the delta chokepoint's input: diffing two of these maps yields both the
     * allowed-set changes (backfill/prune) and the files-flag flips (backfill) in one pass.
     * Same O(courses) bound and fast paths as {@see allowed_courseids()}.
     *
     * @param string $area core_search area id.
     * @return array courseid => includefiles (bool), allowed courses only.
     */
    public function coverage_map(string $area): array {
        if ($this->is_siteonly($area)) {
            return [];
        }
        $rules = $this->area_rules($area);
        if (!$this->grants_any_enablement($rules) && !$this->grants_any_enablement($this->wildcard_rules())) {
            // Fast path: neither an own nor a wildcard row grants anything — no course pass.
            return [];
        }
        $map = [];
        foreach ($this->classified_courses($area) as $courseid => $pair) {
            if ($pair['enabled']) {
                $map[$courseid] = $pair['includefiles'];
            }
        }
        return $map;
    }

    /**
     * Whether an area has no courseid dimension: 'other' contextsupport, or not enumerated at all
     * (unknown areas keep their scoped rows inert too — fail-safe).
     *
     * @param string $area core_search area id.
     * @return bool
     */
    private function is_siteonly(string $area): bool {
        $support = (new site_content_area_registry())->contextsupport_for($area);
        return $support === null || $support === site_content_area_registry::SUPPORT_OTHER;
    }

    /**
     * Whether ANY rule row of the area grants enablement (site, category or course).
     *
     * @param array $rules Grouped rules as produced by {@see area_rules()}.
     * @return bool
     */
    private function grants_any_enablement(array $rules): bool {
        if (!empty($rules['site']['enabled'])) {
            return true;
        }
        foreach (['category', 'course'] as $scopetype) {
            foreach ($rules[$scopetype] as $pair) {
                if ($pair['enabled']) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * The area's governance rows, grouped by scope type (request-static).
     *
     * @param string $area core_search area id.
     * @return array ['site' => array|null, 'category' => [id => pair], 'course' => [id => pair]]
     */
    private function area_rules(string $area): array {
        if (isset(self::$rules[$area])) {
            return self::$rules[$area];
        }
        $grouped = ['site' => null, 'category' => [], 'course' => []];
        foreach ((new sitesearch_scope_repository())->list_rules($area) as $row) {
            $pair = [
                'enabled' => !empty($row->enabled),
                'includefiles' => !empty($row->includefiles),
            ];
            if ($row->scopetype === sitesearch_scope_repository::SCOPETYPE_SITE) {
                $grouped['site'] = $pair;
            } else if ($row->scopetype === sitesearch_scope_repository::SCOPETYPE_CATEGORY) {
                $grouped['category'][(int)$row->scopeid] = $pair;
            } else if ($row->scopetype === sitesearch_scope_repository::SCOPETYPE_COURSE) {
                $grouped['course'][(int)$row->scopeid] = $pair;
            }
        }
        self::$rules[$area] = $grouped;
        return $grouped;
    }

    /**
     * The wildcard ('*') rule rows, grouped like any area's rules — read ONCE per request: '*'
     * is simply a key of the request-static rules cache, so every area resolution shares the
     * same wildcard read instead of paying one per area.
     *
     * @return array ['site' => array|null, 'category' => [id => pair], 'course' => [id => pair]]
     */
    private function wildcard_rules(): array {
        return $this->area_rules(site_content_area_registry::WILDCARD);
    }

    /**
     * Classify EVERY course of the site for one area — the single O(courses) pass shared by the
     * bulk resolutions (allowed set, coverage map, blocklist exclusions).
     *
     * @param string $area core_search area id.
     * @return array courseid => effective pair.
     */
    private function classified_courses(string $area): array {
        $this->load_all_courses();
        if ($this->area_rules($area)['category'] !== [] || $this->wildcard_rules()['category'] !== []) {
            $this->load_all_category_paths();
        }
        $map = [];
        foreach (array_keys(self::$coursecategories) as $courseid) {
            $map[$courseid] = $this->effective($area, (int)$courseid);
        }
        return $map;
    }

    /**
     * The category-path ids (root..self) of a course's category; [] for the frontpage course
     * (category 0) or an unresolvable course/category.
     *
     * @param int $courseid
     * @return int[]
     */
    private function category_path_for_course(int $courseid): array {
        global $DB;
        if (!array_key_exists($courseid, self::$coursecategories)) {
            if (self::$allcoursesloaded) {
                return [];
            }
            $category = $DB->get_field('course', 'category', ['id' => $courseid], IGNORE_MISSING);
            self::$coursecategories[$courseid] = ($category === false) ? 0 : (int)$category;
        }
        $categoryid = (int)self::$coursecategories[$courseid];
        if ($categoryid <= 0) {
            return [];
        }
        if (!array_key_exists($categoryid, self::$categorypaths)) {
            if (self::$allcategoriesloaded) {
                return [];
            }
            $path = $DB->get_field('course_categories', 'path', ['id' => $categoryid], IGNORE_MISSING);
            self::$categorypaths[$categoryid] = $this->parse_path($path === false ? '' : (string)$path);
        }
        return self::$categorypaths[$categoryid];
    }

    /**
     * Load the course => category map for every course in one minimal-field query.
     *
     * @return void
     */
    private function load_all_courses(): void {
        global $DB;
        if (self::$allcoursesloaded) {
            return;
        }
        self::$coursecategories = [];
        foreach ($DB->get_records('course', null, '', 'id, category') as $record) {
            self::$coursecategories[(int)$record->id] = (int)$record->category;
        }
        self::$allcoursesloaded = true;
    }

    /**
     * Load every category's path ids in one minimal-field query.
     *
     * @return void
     */
    private function load_all_category_paths(): void {
        global $DB;
        if (self::$allcategoriesloaded) {
            return;
        }
        self::$categorypaths = [];
        foreach ($DB->get_records('course_categories', null, '', 'id, path') as $record) {
            self::$categorypaths[(int)$record->id] = $this->parse_path((string)$record->path);
        }
        self::$allcategoriesloaded = true;
    }

    /**
     * Parse a category `path` value ('/1/3/7') into its id list (root..self).
     *
     * @param string $path
     * @return int[]
     */
    private function parse_path(string $path): array {
        $ids = [];
        foreach (explode('/', $path) as $part) {
            if ($part !== '' && (int)$part > 0) {
                $ids[] = (int)$part;
            }
        }
        return $ids;
    }
}
