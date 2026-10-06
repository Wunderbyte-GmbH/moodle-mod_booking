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
 * Dynamic enumeration + enablement registry for site-content search areas.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\sitesearch;

/**
 * Enumerates ALL core_search areas dynamically (blueprint §11.27) and knows which of them an admin
 * has switched on.
 *
 * Since `get_document()` became the single content source (§11.26) there is no per-area mapping code
 * left in the agent, so nothing justifies a hardcoded whitelist anymore: every area — including
 * third-party ones — is enumerated via the static, engine-free
 * `\core_search\manager::get_search_areas_list()`. Core's per-area enable flag is DELIBERATELY
 * ignored (`$enabled = false`): it configures Moodle's Global Search, not us — our only gate is the
 * governance page ({@see sitesearch_scope_repository}, `{bx_agent_search_scope}`), default ALL OFF.
 *
 * Each area carries a `contextsupport` classification consumed by the access-context prefilter
 * ({@see site_access_context_lister}):
 *  - 'module': module areas (extend `\core_search\base_mod`) — prefiltered by visible module contexts.
 *  - 'course': non-module areas whose documents live at course context (e.g. core_course-course,
 *    core_course-section) — prefiltered by visible course contexts.
 *  - 'other': everything else. FAIL-CLOSED in the prefilter (it contributes no contexts, so regular
 *    users never see its hits — no leak); still enableable, and marked on the governance page.
 *
 * Areas are instantiated directly (engine-free) — never through the search-engine-gated manager
 * instance — so indexing/retrieval work with no configured global-search engine.
 */
class site_content_area_registry {
    /**
     * Wildcard area id (context-governance blueprint §3.0): one rule row whose `area` column is
     * '*' covers EVERY area with a course dimension ({@see wildcard_covered_area_keys()}) —
     * deliberately a rule about the INTENT "all content areas", never a macro expanding into N
     * concrete rows (newly installed plugin areas are covered automatically).
     */
    public const WILDCARD = '*';

    /** Context-support class: module areas, prefiltered by visible module contexts. */
    public const SUPPORT_MODULE = 'module';

    /** Context-support class: course-context areas, prefiltered by visible course contexts. */
    public const SUPPORT_COURSE = 'course';

    /** Context-support class: any other context level — fail-closed in the prefilter. */
    public const SUPPORT_OTHER = 'other';

    /**
     * Request-level cache of the enumerated area descriptors (get_search_areas_list() scans all
     * plugins, so it must not run more than once per request).
     *
     * @var array|null
     */
    private static ?array $areas = null;

    /**
     * Every installed core_search area, dynamically enumerated and keyed by area id.
     *
     * Descriptor shape per area id:
     *  - 'areaid' (string): the core_search area id, e.g. 'mod_page-activity'.
     *  - 'instance' (\core_search\base): the enumerated area instance (shared; use
     *    {@see area_instance()} for a fresh one).
     *  - 'modname' (string|null): the module name for module areas (e.g. 'page'), null otherwise.
     *  - 'contextsupport' (string): one of the SUPPORT_* classes above.
     *
     * @return array
     */
    public static function all_areas(): array {
        if (self::$areas !== null) {
            return self::$areas;
        }
        $areas = [];
        foreach (\core_search\manager::get_search_areas_list(false) as $areaid => $instance) {
            $component = (string)$instance->get_component_name();
            $ismodule = ($instance instanceof \core_search\base_mod) || (strpos($component, 'mod_') === 0);
            if ($ismodule) {
                $support = self::SUPPORT_MODULE;
                $modname = (strpos($component, 'mod_') === 0) ? substr($component, 4) : null;
            } else if (in_array(CONTEXT_COURSE, $instance::get_levels(), true)) {
                // Non-module areas declaring course-context documents (core_course-course,
                // core_course-section, ...): served through the course-level prefilter extension.
                $support = self::SUPPORT_COURSE;
                $modname = null;
            } else {
                // Any other context level: enableable, but the prefilter contributes nothing for
                // it (fail-closed — invisible to regular users, no leak).
                $support = self::SUPPORT_OTHER;
                $modname = null;
            }
            $areas[(string)$areaid] = [
                'areaid' => (string)$areaid,
                'instance' => $instance,
                'modname' => $modname,
                'contextsupport' => $support,
            ];
        }
        self::$areas = $areas;
        return self::$areas;
    }

    /**
     * Every enumerated area id (regardless of enablement).
     *
     * @return string[]
     */
    public function all_area_keys(): array {
        return array_keys(self::all_areas());
    }

    /**
     * Whether an area id string is the wildcard area id ('*', §3.0).
     *
     * @param string $areakey Area id string (rule row value or request input).
     * @return bool
     */
    public static function is_wildcard(string $areakey): bool {
        return $areakey === self::WILDCARD;
    }

    /**
     * Every enumerated area id the wildcard covers: all areas with a course dimension
     * (contextsupport 'module' or 'course'). 'other'-support areas (user/message/block — no
     * courseid) stay out per concept doc §9: for them only their own concrete site row applies,
     * wildcard rows are inert.
     *
     * @return string[]
     */
    public function wildcard_covered_area_keys(): array {
        $keys = [];
        foreach (self::all_areas() as $areakey => $descriptor) {
            if ($descriptor['contextsupport'] !== self::SUPPORT_OTHER) {
                $keys[] = (string)$areakey;
            }
        }
        return $keys;
    }

    /**
     * The area ids with ANY enabled coverage, intersected with the enumerated areas (default none).
     *
     * "Any enabled coverage" (context-governance blueprint §3.1) = an enabled site row OR at least
     * one enabled category/course rule row. For 'other'-contextsupport areas only the site row
     * counts — their scoped rows are inert (concept doc §9). The per-course resolution is the
     * {@see sitesearch_scope_resolver}'s job; this list is the coarse "area participates at all"
     * gate. The repository also lazily migrates the retired `sitesearchareas` config on first
     * read, so a site that had areas enabled through the old raw setting keeps them without an
     * upgrade step.
     *
     * @return string[]
     */
    public function enabled_area_keys(): array {
        return $this->covered_area_keys((new sitesearch_scope_repository())->areas_with_enabled_coverage());
    }

    /**
     * The area ids with ANY file-indexing coverage (some rule row enabled AND flagged) that
     * actually use file indexing (`uses_file_indexing()`), intersected with the enumerated areas.
     * Coarse participation gate only — whether a CONCRETE document gets file chunks is resolved
     * per course via the resolver's effective pair (§14.2 + context governance). Sorted (area ASC,
     * from the repository).
     *
     * @return string[]
     */
    public function files_enabled_area_keys(): array {
        $areas = self::all_areas();
        $keys = [];
        foreach ($this->covered_area_keys((new sitesearch_scope_repository())->areas_with_files_coverage()) as $areakey) {
            if ($areas[$areakey]['instance']->uses_file_indexing()) {
                $keys[] = $areakey;
            }
        }
        return $keys;
    }

    /**
     * Reduce a repository coverage index to the enumerated area ids it effectively covers:
     * site coverage always counts, scoped coverage only for areas with a course dimension
     * ('other'-support areas are site-only, their scoped rows are inert).
     *
     * A wildcard ('*') entry in the index grants its coverage to EVERY wildcard-covered area
     * (§3.0) — an area without any own rule row but with an enabled wildcard row anywhere is
     * covered. Wildcard-covered areas are never 'other'-support, so both the site and the scoped
     * wildcard coverage count for all of them.
     *
     * @param array $coverage area => ['site' => bool, 'scoped' => bool] (repository coverage index).
     * @return string[]
     */
    private function covered_area_keys(array $coverage): array {
        $areas = self::all_areas();
        $wildcard = $coverage[self::WILDCARD] ?? null;
        unset($coverage[self::WILDCARD]);
        if ($wildcard !== null && (!empty($wildcard['site']) || !empty($wildcard['scoped']))) {
            foreach ($this->wildcard_covered_area_keys() as $areakey) {
                $cover = $coverage[$areakey] ?? ['site' => false, 'scoped' => false];
                $coverage[$areakey] = [
                    'site' => $cover['site'] || !empty($wildcard['site']),
                    'scoped' => $cover['scoped'] || !empty($wildcard['scoped']),
                ];
            }
            // The merge appends the expanded keys: restore the repository's area ASC ordering.
            ksort($coverage, SORT_STRING);
        }
        $keys = [];
        foreach ($coverage as $areakey => $cover) {
            if (!isset($areas[$areakey])) {
                continue;
            }
            $siteonly = ($areas[$areakey]['contextsupport'] === self::SUPPORT_OTHER);
            if ($cover['site'] || (!$siteonly && $cover['scoped'])) {
                $keys[] = (string)$areakey;
            }
        }
        return $keys;
    }

    /**
     * The distinct module names behind the enabled module areas.
     *
     * @return string[]
     */
    public function enabled_modnames(): array {
        $areas = self::all_areas();
        $modnames = [];
        foreach ($this->enabled_area_keys() as $areakey) {
            if ($areas[$areakey]['modname'] !== null) {
                $modnames[$areas[$areakey]['modname']] = true;
            }
        }
        return array_keys($modnames);
    }

    /**
     * The access descriptor for {@see site_access_context_lister::allowed_context_filter()}:
     * the enabled module names plus whether any enabled area needs course-level contexts.
     * Enabled 'other'-support areas deliberately contribute nothing (fail-closed prefilter).
     *
     * @return array ['modnames' => string[], 'includecourselevel' => bool]
     */
    public function enabled_access_descriptor(): array {
        $areas = self::all_areas();
        $includecourselevel = false;
        foreach ($this->enabled_area_keys() as $areakey) {
            if ($areas[$areakey]['contextsupport'] === self::SUPPORT_COURSE) {
                $includecourselevel = true;
                break;
            }
        }
        return [
            'modnames' => $this->enabled_modnames(),
            'includecourselevel' => $includecourselevel,
        ];
    }

    /**
     * LENIENT normalization of free-form area references to enumerated area ids.
     *
     * Accepted per reference (all case-insensitive): the exact area id ('mod_page-activity'), a
     * component name ('mod_page' — every area of that component), a bare module name ('page'), or
     * the area's visible name ({@see \core_search\base::get_visible_name()}). Unknown or
     * unmatchable references are DROPPED silently; when NOTHING matches, null is returned, which
     * means "no restriction" — deliberately non-restrictive: a caller guessing content types
     * badly still searches everything instead of nothing.
     *
     * Matching runs against ALL enumerated areas (not just enabled ones): enablement is enforced
     * at query time by the search service, and a normalized-but-disabled area simply yields no
     * hits there.
     *
     * @param array $refs Free-form area references (strings).
     * @return array|null Matched area ids (deduplicated), or null = no restriction.
     */
    public function normalize_area_refs(array $refs): ?array {
        $lookup = [];
        foreach (self::all_areas() as $areaid => $descriptor) {
            $keys = [
                (string)$areaid,
                (string)$descriptor['instance']->get_component_name(),
            ];
            if ($descriptor['modname'] !== null) {
                $keys[] = (string)$descriptor['modname'];
            }
            try {
                $keys[] = (string)$descriptor['instance']->get_visible_name();
            } catch (\Throwable $e) {
                // A broken third-party area label must not break normalization.
                unset($e);
            }
            foreach ($keys as $key) {
                $key = \core_text::strtolower(trim($key));
                if ($key !== '') {
                    $lookup[$key][$areaid] = true;
                }
            }
        }

        $matched = [];
        foreach ($refs as $ref) {
            if (!is_scalar($ref)) {
                continue;
            }
            $needle = \core_text::strtolower(trim((string)$ref));
            if ($needle === '' || !isset($lookup[$needle])) {
                // Unknown references are dropped silently (lenient by design).
                continue;
            }
            $matched += $lookup[$needle];
        }

        return $matched === [] ? null : array_keys($matched);
    }

    /**
     * The module name behind an enumerated module area id, or null (unknown or non-module area).
     *
     * @param string $areakey
     * @return string|null
     */
    public function modname_for(string $areakey): ?string {
        $areas = self::all_areas();
        return isset($areas[$areakey]) ? $areas[$areakey]['modname'] : null;
    }

    /**
     * The context-support class of an enumerated area id, or null for unknown areas.
     *
     * @param string $areakey
     * @return string|null One of the SUPPORT_* constants, or null.
     */
    public function contextsupport_for(string $areakey): ?string {
        $areas = self::all_areas();
        return isset($areas[$areakey]) ? $areas[$areakey]['contextsupport'] : null;
    }

    /**
     * A fresh core_search area instance for an enumerated area id (engine-free), or null.
     *
     * @param string $areakey
     * @return \core_search\base|null
     */
    public function area_instance(string $areakey): ?\core_search\base {
        $areas = self::all_areas();
        if (!isset($areas[$areakey])) {
            return null;
        }
        $class = get_class($areas[$areakey]['instance']);
        return new $class();
    }
}
