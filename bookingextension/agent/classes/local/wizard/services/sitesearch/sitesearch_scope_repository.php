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
 * Governance persistence for the site-search area x scope enablement.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\sitesearch;

/**
 * CRUD over `{bx_agent_search_scope}` — the admin-written source of truth for which search
 * area x scope combinations may be indexed (blueprint §5b.3; default = everything off).
 *
 * Scope rows carry the full dimension ('site' | 'course' | 'category' + scopeid) and are resolved
 * per course by {@see sitesearch_scope_resolver} (specificity cascade, context-governance
 * blueprint §3). The `area` column additionally accepts the wildcard '*'
 * ({@see site_content_area_registry::WILDCARD}, blueprint §3.0): one rule row covering every
 * area with a course dimension. Every rule MUTATION here runs through the delta-sync chokepoint
 * ({@see sync_scope_deltas()}): the allowed course coverage of every AFFECTED area (the area
 * itself — or, for a wildcard mutation, every wildcard-covered area) is diffed before/after the
 * write and any change queues an immediate `sitesearch_scope_sync_adhoc` backfill/prune — rule
 * changes never trigger a site rebuild (§4.1 of the concept doc).
 *
 * Legacy migration: enablement briefly lived in the raw `sitesearchareas` plugin config (a
 * multicheckbox, rejected as a substitute by blueprint decision §11.25). Because no plugin version
 * bump is available for an upgrade step, the migration runs LAZILY on first read here: a non-empty
 * legacy value seeds site-scope rows once (only while the scope table is still empty) and is then
 * unset. The check is cheap — get_config() is MUC-cached, so the table is only counted while the
 * legacy value still exists.
 */
class sitesearch_scope_repository {
    /** Scope type: the area toggle applies site-wide. */
    public const SCOPETYPE_SITE = 'site';

    /** Scope type: the area toggle applies to one course. */
    public const SCOPETYPE_COURSE = 'course';

    /** Scope type: the area toggle applies to one course category (path-based inheritance). */
    public const SCOPETYPE_CATEGORY = 'category';

    /** Governance table. */
    private const TABLE = 'bx_agent_search_scope';

    /** Legacy plugin config (comma-separated enabled area ids) that seeded this table once. */
    private const LEGACY_CONFIG = 'sitesearchareas';

    /**
     * Enable or disable an area for a scope (upsert; exactly one row per area x scopetype x scopeid).
     *
     * @param string $area Search area id (e.g. 'mod_page-activity') or the wildcard '*' (§3.0).
     * @param bool $enabled New enablement state.
     * @param string $scopetype One of the SCOPETYPE_* constants (v1 UI only uses 'site').
     * @param int $scopeid Course/category id for course/category scopes; must be 0 for 'site'.
     * @return void
     */
    public function set_enabled(
        string $area,
        bool $enabled,
        string $scopetype = self::SCOPETYPE_SITE,
        int $scopeid = 0
    ): void {
        $this->validate_scope($scopetype, $scopeid);
        $before = $this->coverage_snapshots($this->affected_areas($area));
        $this->write_rule($area, $scopetype, $scopeid, ['enabled' => $enabled ? 1 : 0]);
        $this->sync_scope_deltas($before);
    }

    /**
     * Set the file-indexing flag of an area x scope row (upsert; a missing row is created
     * DISABLED with just the flag — the flag is settable before enabling and only becomes
     * effective while the row is enabled).
     *
     * Also purges the governance effort-estimate cache: the estimator's Ø-chunk figure depends
     * on the flag (file-inclusive counts), so the page must show fresh numbers right after a
     * toggle instead of a stale cached sample.
     *
     * @param string $area Search area id (e.g. 'mod_resource-activity') or the wildcard '*' (§3.0).
     * @param bool $includefiles New file-indexing state.
     * @param string $scopetype One of the SCOPETYPE_* constants (v1 UI only uses 'site').
     * @param int $scopeid Course/category id for course/category scopes; must be 0 for 'site'.
     * @return void
     */
    public function set_includefiles(
        string $area,
        bool $includefiles,
        string $scopetype = self::SCOPETYPE_SITE,
        int $scopeid = 0
    ): void {
        $this->validate_scope($scopetype, $scopeid);
        $before = $this->coverage_snapshots($this->affected_areas($area));
        $this->write_rule($area, $scopetype, $scopeid, ['includefiles' => $includefiles ? 1 : 0]);
        $this->sync_scope_deltas($before);

        \cache::make('bookingextension_agent', 'sitesearchestimates')->purge();
    }

    /**
     * Raw rule upsert (exactly one row per area x scopetype x scopeid) — the single write path
     * shared by both flag setters and the legacy seeding. A missing row is created with both
     * flags off plus the given fields, so the includefiles flag is settable before enabling.
     *
     * Deliberately WITHOUT the delta chokepoint: the public setters wrap it, the legacy migration
     * bypasses it (see {@see ensure_legacy_config_migrated()}).
     *
     * @param string $area Search area id.
     * @param string $scopetype One of the SCOPETYPE_* constants (already validated).
     * @param int $scopeid Course/category id; 0 for 'site' (already validated).
     * @param array $fields Column => value overrides ('enabled' and/or 'includefiles').
     * @return void
     */
    private function write_rule(string $area, string $scopetype, int $scopeid, array $fields): void {
        global $DB, $USER;

        $params = ['area' => $area, 'scopetype' => $scopetype, 'scopeid' => $scopeid];
        $record = $DB->get_record(self::TABLE, $params);
        if ($record) {
            foreach ($fields as $field => $value) {
                $record->{$field} = $value;
            }
            $record->usermodified = (int)($USER->id ?? 0);
            $record->timemodified = time();
            $DB->update_record(self::TABLE, $record);
            return;
        }
        $DB->insert_record(self::TABLE, (object)($params + $fields + [
            'enabled' => 0,
            'includefiles' => 0,
            'usermodified' => (int)($USER->id ?? 0),
            'timemodified' => time(),
        ]));
    }

    /**
     * Whether an area x scope row carries the file-indexing flag (missing row = off; default off).
     *
     * NOTE: this is the raw flag — whether file indexing actually RUNS additionally requires the
     * row to be enabled (see {@see files_enabled_site_areas()}) plus an available PDF extractor.
     *
     * @param string $area Search area id.
     * @param string $scopetype One of the SCOPETYPE_* constants.
     * @param int $scopeid Course/category id; 0 for 'site'.
     * @return bool
     */
    public function is_includefiles(string $area, string $scopetype = self::SCOPETYPE_SITE, int $scopeid = 0): bool {
        global $DB;

        $this->validate_scope($scopetype, $scopeid);
        $this->ensure_legacy_config_migrated();

        return $DB->record_exists(self::TABLE, [
            'area' => $area,
            'scopetype' => $scopetype,
            'scopeid' => $scopeid,
            'includefiles' => 1,
        ]);
    }

    /**
     * Whether an area is enabled for a scope (missing row = disabled; default off).
     *
     * @param string $area Search area id.
     * @param string $scopetype One of the SCOPETYPE_* constants.
     * @param int $scopeid Course/category id; 0 for 'site'.
     * @return bool
     */
    public function is_enabled(string $area, string $scopetype = self::SCOPETYPE_SITE, int $scopeid = 0): bool {
        global $DB;

        $this->validate_scope($scopetype, $scopeid);
        $this->ensure_legacy_config_migrated();

        return $DB->record_exists(self::TABLE, [
            'area' => $area,
            'scopetype' => $scopetype,
            'scopeid' => $scopeid,
            'enabled' => 1,
        ]);
    }

    /**
     * Every area id with an enabled SITE-WIDE row (site scope only — scoped coverage is served by
     * {@see areas_with_enabled_coverage()} and resolved per course by the resolver).
     *
     * @return string[]
     */
    public function enabled_site_areas(): array {
        global $DB;

        $this->ensure_legacy_config_migrated();

        $records = $DB->get_records(
            self::TABLE,
            ['scopetype' => self::SCOPETYPE_SITE, 'scopeid' => 0, 'enabled' => 1],
            'area ASC',
            'id, area'
        );
        $areas = [];
        foreach ($records as $record) {
            $areas[(string)$record->area] = true;
        }
        return array_keys($areas);
    }

    /**
     * Every area id whose SITE-WIDE row is enabled AND flagged for file indexing — the raw
     * governance input of the file chunk pipeline (mirrors {@see enabled_site_areas()}; the
     * registry additionally intersects with the enumerated areas and `uses_file_indexing()`).
     *
     * @return string[]
     */
    public function files_enabled_site_areas(): array {
        global $DB;

        $this->ensure_legacy_config_migrated();

        $records = $DB->get_records(
            self::TABLE,
            ['scopetype' => self::SCOPETYPE_SITE, 'scopeid' => 0, 'enabled' => 1, 'includefiles' => 1],
            'area ASC',
            'id, area'
        );
        $areas = [];
        foreach ($records as $record) {
            $areas[(string)$record->area] = true;
        }
        return array_keys($areas);
    }

    /**
     * All rule rows of an area (every scope type incl. site, enabled or not) — resolver input and
     * governance display.
     *
     * @param string $area Search area id.
     * @return array Scope records keyed by row id, ordered scopetype/scopeid ASC.
     */
    public function list_rules(string $area): array {
        global $DB;

        $this->ensure_legacy_config_migrated();

        return $DB->get_records(self::TABLE, ['area' => $area], 'scopetype ASC, scopeid ASC');
    }

    /**
     * All scope rows of an area — alias of {@see list_rules()} kept for existing callers.
     *
     * @param string $area Search area id.
     * @return array Scope records keyed by row id.
     */
    public function get_scopes(string $area): array {
        return $this->list_rules($area);
    }

    /**
     * Remove one area x scope rule row entirely (as opposed to disabling it), through the
     * delta-sync chokepoint: courses that lose their enablement through the removal are pruned,
     * courses whose effective pair changes (e.g. the removed row overrode an enabling parent
     * rule) are backfilled.
     *
     * @param string $area Search area id or the wildcard '*' (§3.0).
     * @param string $scopetype One of the SCOPETYPE_* constants.
     * @param int $scopeid Course/category id; 0 for 'site'.
     * @return void
     */
    public function delete_rule(string $area, string $scopetype, int $scopeid): void {
        global $DB;

        $this->validate_scope($scopetype, $scopeid);
        $before = $this->coverage_snapshots($this->affected_areas($area));
        $DB->delete_records(self::TABLE, ['area' => $area, 'scopetype' => $scopetype, 'scopeid' => $scopeid]);
        $this->sync_scope_deltas($before);
    }

    /**
     * Remove one area x scope row — alias of {@see delete_rule()} kept for existing callers
     * (same chokepoint, so no deletion path can bypass the delta sync).
     *
     * @param string $area Search area id.
     * @param string $scopetype One of the SCOPETYPE_* constants.
     * @param int $scopeid Course/category id; 0 for 'site'.
     * @return void
     */
    public function delete_scope(string $area, string $scopetype = self::SCOPETYPE_SITE, int $scopeid = 0): void {
        $this->delete_rule($area, $scopetype, $scopeid);
    }

    /**
     * Coverage index for the registry: every area id that has ANY enabled rule row, with the
     * site/scoped split — area => ['site' => bool, 'scoped' => bool]. 'scoped' means at least one
     * enabled category/course row (the registry decides whether that counts, since scoped rows
     * are inert for 'other'-contextsupport areas).
     *
     * @return array area => ['site' => bool, 'scoped' => bool], ordered by area ASC.
     */
    public function areas_with_enabled_coverage(): array {
        return $this->coverage_index(['enabled' => 1]);
    }

    /**
     * Coverage index of the FILE-indexing enablement: every area id with at least one rule row
     * that is enabled AND flagged includefiles (pair semantics: a flag on a disabled row grants
     * nothing) — same shape as {@see areas_with_enabled_coverage()}.
     *
     * @return array area => ['site' => bool, 'scoped' => bool], ordered by area ASC.
     */
    public function areas_with_files_coverage(): array {
        return $this->coverage_index(['enabled' => 1, 'includefiles' => 1]);
    }

    /**
     * Shared builder of the coverage indexes above.
     *
     * @param array $conditions Row conditions (flag filters).
     * @return array area => ['site' => bool, 'scoped' => bool].
     */
    private function coverage_index(array $conditions): array {
        global $DB;

        $this->ensure_legacy_config_migrated();

        $index = [];
        $records = $DB->get_records(self::TABLE, $conditions, 'area ASC', 'id, area, scopetype');
        foreach ($records as $record) {
            $area = (string)$record->area;
            if (!isset($index[$area])) {
                $index[$area] = ['site' => false, 'scoped' => false];
            }
            if ($record->scopetype === self::SCOPETYPE_SITE) {
                $index[$area]['site'] = true;
            } else {
                $index[$area]['scoped'] = true;
            }
        }
        return $index;
    }

    /**
     * The concrete areas a rule mutation can affect: the area itself — or, for the wildcard '*',
     * EVERY wildcard-covered area (course-dimension areas, §3.0): a wildcard row change can move
     * the effective pair of any of them.
     *
     * @param string $area Search area id or the wildcard '*'.
     * @return string[] Concrete area ids (never contains '*').
     */
    private function affected_areas(string $area): array {
        if (site_content_area_registry::is_wildcard($area)) {
            return (new site_content_area_registry())->wildcard_covered_area_keys();
        }
        return [$area];
    }

    /**
     * The current allowed-course coverage (courseid => includefiles) of each given area, with ONE
     * fresh resolver cache for the whole set — the before/after input of the delta chokepoint.
     * A single reset means the resolver's bulk course/category loads run once per snapshot set,
     * not once per area (relevant for wildcard mutations spanning every covered area).
     *
     * @param array $areas Concrete area ids.
     * @return array area => (courseid => includefiles (bool)).
     */
    private function coverage_snapshots(array $areas): array {
        sitesearch_scope_resolver::reset_request_cache();
        $resolver = new sitesearch_scope_resolver();
        $snapshots = [];
        foreach ($areas as $area) {
            $snapshots[$area] = $resolver->coverage_map($area);
        }
        return $snapshots;
    }

    /**
     * DELTA CHOKEPOINT (concept doc §4.1 + §3.0): diff each affected area's allowed coverage
     * before/after a rule mutation and queue ONE scope-sync adhoc task PER changed area.
     *
     * Backfill = courses that are newly allowed OR whose effective includefiles flag flipped
     * (their chunk set must be recomputed; replace_document is idempotent/diff-based). Prune =
     * courses that are no longer allowed. Queued IMMEDIATELY (decision: whoever flips a rule has
     * seen the traffic light) — never a site rebuild. Areas without a coverage change queue
     * nothing. The snapshots cost O(courses) per area, which is fine on the admin-driven
     * governance write path.
     *
     * Wildcard fan-out DELIBERATELY as one adhoc per affected area (blueprint §3.0 leaves the
     * choice open): it reuses the existing customdata contract {area, backfill, prune} unchanged
     * (task, tests and traces stay area-shaped) and isolates failures per area — a broken area's
     * sync never blocks the others. The alternative (multi-area customdata list) would only save
     * task rows.
     *
     * The task self-guards on readiness, so queueing is unconditional here — a not-ready site
     * turns the task into a traced no-op.
     *
     * @param array $before Coverage snapshots taken before the mutation, keyed by area
     *                      ({@see coverage_snapshots()} — its keys define the diffed area set).
     * @return void
     */
    private function sync_scope_deltas(array $before): void {
        $after = $this->coverage_snapshots(array_keys($before));

        foreach ($before as $area => $beforemap) {
            $aftermap = $after[$area];
            $backfill = [];
            foreach ($aftermap as $courseid => $includefiles) {
                if (!array_key_exists($courseid, $beforemap) || $beforemap[$courseid] !== $includefiles) {
                    $backfill[] = (int)$courseid;
                }
            }
            $prune = array_map('intval', array_keys(array_diff_key($beforemap, $aftermap)));

            if ($backfill === [] && $prune === []) {
                continue;
            }
            $task = new \bookingextension_agent\task\sitesearch_scope_sync_adhoc();
            $task->set_custom_data([
                'area' => (string)$area,
                'backfill' => array_values($backfill),
                'prune' => array_values($prune),
            ]);
            \core\task\manager::queue_adhoc_task($task);
        }
    }

    /**
     * Reject unknown scope types and inconsistent scope ids before they hit the table.
     *
     * @param string $scopetype Candidate scope type.
     * @param int $scopeid Candidate scope id.
     * @return void
     */
    private function validate_scope(string $scopetype, int $scopeid): void {
        $valid = [self::SCOPETYPE_SITE, self::SCOPETYPE_COURSE, self::SCOPETYPE_CATEGORY];
        if (!in_array($scopetype, $valid, true)) {
            throw new \coding_exception('Invalid site-search scope type: ' . $scopetype);
        }
        if ($scopetype === self::SCOPETYPE_SITE && $scopeid !== 0) {
            throw new \coding_exception('Site-wide site-search scope rows must use scopeid 0.');
        }
        if ($scopetype !== self::SCOPETYPE_SITE && $scopeid <= 0) {
            throw new \coding_exception('Course/category site-search scope rows need a positive scopeid.');
        }
    }

    /**
     * One-time lazy migration of the legacy `sitesearchareas` config (see class docblock).
     *
     * Seeds site-scope rows from the legacy value only while the scope table is still empty (rows
     * present = the new model is already in use, the stale config must not overwrite it). The legacy
     * config is unset in both cases so this check degrades to a single cached get_config() miss.
     *
     * @return void
     */
    private function ensure_legacy_config_migrated(): void {
        global $DB;

        $legacy = get_config('bookingextension_agent', self::LEGACY_CONFIG);
        if ($legacy === false || trim((string)$legacy) === '') {
            return;
        }
        if (!$DB->count_records(self::TABLE)) {
            // Seed as-configured: the registry intersects with its whitelist on every read, so a
            // legacy key that is no longer whitelisted stays inert in the table. Deliberately via
            // the RAW write (no delta chokepoint): the migration is a representation change of an
            // enablement that was already effective through the legacy config — queueing a
            // full-site backfill from a lazy READ path would be both wrong and surprising.
            foreach (array_filter(array_map('trim', explode(',', (string)$legacy))) as $area) {
                $this->write_rule($area, self::SCOPETYPE_SITE, 0, ['enabled' => 1]);
            }
            sitesearch_scope_resolver::reset_request_cache();
        }
        unset_config(self::LEGACY_CONFIG, 'bookingextension_agent');
    }
}
