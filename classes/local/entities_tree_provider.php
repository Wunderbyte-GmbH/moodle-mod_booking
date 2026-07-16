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
 * Tree provider that backs the multilevel location (entity) filter for booking options.
 *
 * @package     mod_booking
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local;

use local_entities\entities;
use local_wunderbyte_table\filters\base;
use local_wunderbyte_table\filters\types\standardfilter;
use local_wunderbyte_table\filters\types\treefilter;
use local_wunderbyte_table\filters\types\tree_provider;
use local_wunderbyte_table\wunderbyte_table;

/**
 * Supplies the booking-options location filter with its live entity tree and its SQL condition.
 *
 * The filter deliberately keys on the OPTION-level entity only (component 'mod_booking', area
 * 'option'); the option ↔ optiondate fallback/override logic in mod_booking is left untouched, and a
 * location that lives only on an optiondate is intentionally not matched here (see the plan, BC-4a).
 *
 * mod_booking depends on local_entities only softly (class_exists), so every entry point guards on the
 * class being present and on the opt-in setting; when either is missing the caller falls back to the
 * historical plain-text location standardfilter (BC-2/BC-3).
 */
class entities_tree_provider implements tree_provider {
    /** @var string The relations component used by mod_booking. */
    private const COMPONENT = 'mod_booking';

    /** @var string The option-level relation area (the one the displayed location is loaded from). */
    private const AREA = 'option';

    /**
     * Whether the multilevel entity tree filter is active (opt-in AND local_entities available).
     *
     * When false, callers keep the historical plain-text location filter, so existing installations are
     * completely unchanged until the setting is switched on.
     *
     * @return bool
     */
    public static function is_active(): bool {
        // Probe entitiesrelation_handler, NOT local_entities\entities: the latter's file-scope
        // require of lib/externallib.php throws require_phpunit_isolation() the moment it is
        // autoloaded in a non-isolated PHPUnit run (older local_entities), whereas
        // entitiesrelation_handler is safe to load. The entities:: helpers used below are reached
        // only once this guard passes AND the tree filter is actually exercised. See
        // {@see \mod_booking\local\entities_compat::has_capacity_support()}.
        return class_exists('local_entities\\entitiesrelation_handler')
            && (bool) get_config('booking', 'entitytreefilter');
    }

    /**
     * Returns the location filter to register: the multilevel treefilter when active, otherwise the
     * unchanged plain-text location standardfilter.
     *
     * @param string $label localized filter label
     * @return base
     */
    public static function get_location_filter(string $label): base {
        if (self::is_active()) {
            $treefilter = new treefilter('entityid', $label);
            $treefilter->set_treeprovider(self::class);
            return $treefilter;
        }
        return new standardfilter('location', $label);
    }

    /**
     * Present option-level entity ids in the current result set, as entityid => option count.
     *
     * Mirrors {@see \local_wunderbyte_table\filter::get_db_filter_column()} by reusing the table's own
     * base query (from/where/params) and joining the option-level relation to it, so the counts respect
     * every other active filter and the table's scope. Runs a single grouped query (no N+1).
     *
     * @param wunderbyte_table $table
     * @param string $columnidentifier
     * @return array entityid => count
     */
    public static function get_present_counts(wunderbyte_table $table, string $columnidentifier): array {
        global $DB;

        if (!class_exists('local_entities\\entitiesrelation_handler') || empty($table->sql->from ?? null)) {
            return [];
        }

        $params = $table->sql->params ?? [];
        $compparam = self::unique_param($params, self::COMPONENT);
        $areaparam = self::unique_param($params, self::AREA);
        $where = empty($table->sql->where) ? '1=1' : $table->sql->where;

        $sql = "SELECT ler.entityid AS entityid, COUNT(DISTINCT s1.id) AS keycount
                  FROM {$table->sql->from}
                  JOIN {local_entities_relations} ler
                    ON ler.component = :{$compparam} AND ler.area = :{$areaparam} AND ler.instanceid = s1.id
                 WHERE {$where}
              GROUP BY ler.entityid";

        $counts = [];
        foreach ($DB->get_records_sql($sql, $params) as $record) {
            if (!empty($record->entityid)) {
                $counts[(int)$record->entityid] = (int)$record->keycount;
            }
        }
        return $counts;
    }

    /**
     * Builds the nested tree of occupied entities from the present counts (live from local_entities).
     *
     * @param array $presentcounts entityid => count
     * @return array tree of entity objects
     */
    public static function build_tree(array $presentcounts): array {
        if (!class_exists('local_entities\\entitiesrelation_handler')) {
            return [];
        }
        // The entities::get_filter_tree() helper takes a flat list where repetition drives the counts.
        $flat = [];
        foreach ($presentcounts as $id => $count) {
            for ($i = 0; $i < (int)$count; $i++) {
                $flat[] = (int)$id;
            }
        }
        return entities::get_filter_tree($flat);
    }

    /**
     * SQL condition for the selected node(s): an option matches when its OPTION-level entity is in the
     * live subtree of any selected node. A correlated EXISTS on the outer option id (s1.id) keeps the
     * base query's GROUP BY and row counts untouched (see the plan, BC-4).
     *
     * @param wunderbyte_table $table
     * @param string $columnidentifier
     * @param int[] $selectedids
     * @return string
     */
    public static function filter_sql(wunderbyte_table $table, string $columnidentifier, array $selectedids): string {
        if (!class_exists('local_entities\\entitiesrelation_handler')) {
            return '1=1';
        }

        // Expand each selected node to its whole subtree, live from the entity map.
        $ids = [];
        foreach ($selectedids as $nodeid) {
            foreach (entities::get_descendant_ids((int)$nodeid) as $descendantid) {
                $ids[$descendantid] = $descendantid;
            }
        }
        $ids = array_values($ids);
        if (empty($ids)) {
            // A selected but unknown/removed node matches nothing.
            return '1=0';
        }

        if (!isset($table->sql->params) || !is_array($table->sql->params)) {
            $table->sql->params = [];
        }

        // Register integer bind params directly (correct typing for the entityid IN-list).
        $placeholders = [];
        foreach ($ids as $id) {
            $name = self::add_param($table->sql->params, (int)$id);
            $placeholders[] = ':' . $name;
        }
        $compparam = self::add_param($table->sql->params, self::COMPONENT);
        $areaparam = self::add_param($table->sql->params, self::AREA);
        $inlist = implode(', ', $placeholders);

        return "EXISTS (SELECT 1 FROM {local_entities_relations} ler
                         WHERE ler.component = :{$compparam} AND ler.area = :{$areaparam}
                           AND ler.instanceid = s1.id
                           AND ler.entityid IN ({$inlist}))";
    }

    /**
     * Renders the display name for an option's location entity: byte-identical to the historical output
     * for 1–2 levels ("parent (name)" / "name"), and a full breadcrumb only for 3+ levels (BC-6).
     *
     * Shared by mod_booking and local_musi col_location so the logic exists in exactly one place.
     *
     * @param array $entity the option-settings entity array ({id, name, parentname, ...})
     * @return string
     */
    public static function render_location_name(array $entity): string {
        $entityid = (int)($entity['id'] ?? 0);

        if ($entityid > 0 && class_exists('local_entities\\entitiesrelation_handler')) {
            [, , $names] = entities::get_ancestor_path($entityid);
            if (count($names) >= 3) {
                // Deep hierarchy: show the full path as a breadcrumb.
                return implode(' / ', $names);
            }
        }

        // 1–2 levels (or entities unavailable): exactly the historical rendering.
        if (!empty($entity['parentname'])) {
            return $entity['parentname'] . " (" . ($entity['name'] ?? '') . ")";
        }
        return (string)($entity['name'] ?? '');
    }

    /**
     * Renders the complete location cell for an option's entity.
     *
     * 1–2 levels: byte-identical to the historical output — linked "parent (name)" / "name", plain
     * text when downloading (BC-6). 3+ levels: only the selected entity's name is shown, linked, with
     * the superordinate levels in a CSS hover card (also opened by keyboard focus) and as
     * screenreader text; exports get the full path as plain text. Entity images are rendered small
     * into the card when the showlocationimages setting is on.
     *
     * Shared by mod_booking, local_musi and local_urise col_location so the logic exists in exactly
     * one place. Callers keep their own "no entity → plain location text" fallback.
     *
     * @param array $entity the option-settings entity array ({id, name, parentname, ...})
     * @param bool $isdownloading whether the table is exporting (plain text, no markup)
     * @return string
     */
    public static function render_location_cell(array $entity, bool $isdownloading): string {
        global $OUTPUT;

        $entityid = (int)($entity['id'] ?? 0);
        $ids = [];
        $names = [];
        if ($entityid > 0 && class_exists('local_entities\\entitiesrelation_handler')) {
            [, $ids, $names] = entities::get_ancestor_path($entityid);
        }

        if (count($names) < 3) {
            // 1–2 levels (or entities unavailable): exactly the historical rendering.
            $name = self::render_location_name($entity);
            if ($isdownloading) {
                return $name;
            }
            $url = new \moodle_url('/local/entities/view.php', ['id' => $entityid]);
            return \html_writer::tag('a', $name, ['href' => $url->out(false)]);
        }

        if ($isdownloading) {
            // Exports carry the full path as plain text.
            return implode(' / ', $names);
        }

        $selfname = array_pop($names);
        array_pop($ids);

        $showimages = (bool)get_config('booking', 'showlocationimages');
        $ancestors = [];
        foreach (array_values($ids) as $depth => $ancestorid) {
            $imageurl = $showimages ? entities::get_image_url((int)$ancestorid) : null;
            $ancestors[] = [
                'name' => $names[$depth],
                'indent' => $depth,
                'imageurl' => $imageurl ? $imageurl->out(false) : null,
                'url' => (new \moodle_url('/local/entities/view.php', ['id' => (int)$ancestorid]))->out(false),
            ];
        }

        return $OUTPUT->render_from_template('mod_booking/col_location', [
            'url' => (new \moodle_url('/local/entities/view.php', ['id' => $entityid]))->out(false),
            'name' => $selfname,
            'pathtext' => implode(' / ', $names),
            'ancestors' => $ancestors,
        ]);
    }

    /**
     * Adds a value under a fresh param name into a params array (in place) and returns the name.
     *
     * @param array $params params array, modified in place
     * @param mixed $value
     * @return string the generated param name
     */
    private static function add_param(array &$params, $value): string {
        $i = 0;
        do {
            $name = 'wbtree' . $i++;
        } while (isset($params[$name]));
        $params[$name] = $value;
        return $name;
    }

    /**
     * Like {@see self::add_param()} but for building the read-only counts query params.
     *
     * @param array $params
     * @param mixed $value
     * @return string
     */
    private static function unique_param(array &$params, $value): string {
        return self::add_param($params, $value);
    }
}
