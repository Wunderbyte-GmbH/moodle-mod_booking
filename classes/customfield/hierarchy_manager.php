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
 * Manager for hierarchical option lists of mod_booking dynamicformat custom fields.
 *
 * @package     mod_booking
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      David Ala
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\customfield;

use cache_helper;
use coding_exception;
use core_customfield\api;
use core_customfield\category_controller;
use core_customfield\field_controller;
use moodle_exception;

/**
 * Loads and persists a parent/child option hierarchy for a dynamicformat booking custom field.
 *
 * No dedicated database table is used. The structured hierarchy is stored in the field's
 * configdata under CONFIGKEY_HIERARCHY, and an equivalent read-only "SELECT ... UNION ALL ..."
 * query is generated into configdata[dynamicsql] so the dynamicformat field renders the options.
 *
 * Option ids are stable (they are the values stored on booking options), so they are never
 * reused: a monotonically increasing counter is kept in configdata[CONFIGKEY_NEXTID].
 */
class hierarchy_manager {
    /** @var string Configdata key holding the structured parent/child hierarchy. */
    public const CONFIGKEY_HIERARCHY = 'taskflow_hierarchy';

    /** @var string Configdata key holding the next free option id. */
    public const CONFIGKEY_NEXTID = 'taskflow_nextid';

    /** @var string The only custom field type this manager supports. */
    public const FIELD_TYPE = 'dynamicformat';

    /**
     * Returns all mod_booking custom field categories as [categoryid => name].
     *
     * @return array
     */
    public static function get_categories(): array {
        $handler = booking_handler::create();
        $categories = [];
        foreach ($handler->get_categories_with_fields() as $cat) {
            $categories[(int) $cat->get('id')] = format_string($cat->get('name'));
        }
        return $categories;
    }

    /**
     * Returns the mod_booking dynamicformat custom fields, as [fieldid => name].
     *
     * @return array
     */
    public static function get_manageable_fields(): array {
        $fields = [];
        foreach (booking_handler::get_customfields() as $field) {
            if ($field->type === self::FIELD_TYPE) {
                $fields[(int) $field->id] = format_string($field->name);
            }
        }
        return $fields;
    }

    /**
     * Returns true if a mod_booking custom field with this shortname already exists.
     *
     * @param string $shortname
     * @return bool
     */
    public static function shortname_exists(string $shortname): bool {
        foreach (booking_handler::get_customfields() as $field) {
            if ($field->shortname === $shortname) {
                return true;
            }
        }
        return false;
    }

    /**
     * Loads the stored hierarchy rows for a field.
     *
     * Each returned row is ['id' => int, 'label' => string, 'parentid' => int] (parentid 0 = top).
     * When the field has no stored hierarchy yet, its current dynamic options (if the SQL already
     * returns any) are seeded as flat top level rows so they can be edited.
     *
     * @param int $fieldid
     * @return array numerically indexed list of rows
     */
    public static function load_rows(int $fieldid): array {
        $field = self::load_field($fieldid);
        $configdata = self::get_configdata($field);

        if (!empty($configdata[self::CONFIGKEY_HIERARCHY]) && is_array($configdata[self::CONFIGKEY_HIERARCHY])) {
            $rows = [];
            foreach ($configdata[self::CONFIGKEY_HIERARCHY] as $row) {
                $row = (array) $row;
                $rows[] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'label' => (string) ($row['label'] ?? ''),
                    'parentid' => (int) ($row['parentid'] ?? 0),
                ];
            }
            return $rows;
        }

        return self::seed_rows_from_options($field);
    }

    /**
     * Builds the option list for a local_wunderbyte_table hierarchicalfilter.
     *
     * The returned array has the same shape local_urise hand-codes for its competency filter:
     * a leading 'explode' separator followed by
     * '<optionid>' => ['parent' => <group label>, 'localizedname' => <option label>] entries.
     * Every node in a tree is grouped under its top level ancestor's label, so ticking that
     * ancestor in the filter UI (which ticks all of its children) returns the whole subtree.
     *
     * Returns an empty array when the field has no parent/child links at all, so the caller can
     * fall back to a plain customfieldfilter for genuinely flat option lists.
     *
     * @param int $fieldid
     * @return array
     */
    public static function get_filter_options(int $fieldid): array {
        $rows = self::load_rows($fieldid);
        if (empty($rows)) {
            return [];
        }

        $byid = [];
        $haschildren = [];
        foreach ($rows as $row) {
            $byid[$row['id']] = $row;
            if ($row['parentid'] !== 0) {
                $haschildren[$row['parentid']] = true;
            }
        }

        // No hierarchy: let the caller use a plain (non-hierarchical) filter.
        if (empty($haschildren)) {
            return [];
        }

        $options = ['explode' => ','];
        foreach ($rows as $row) {
            $option = ['localizedname' => format_string($row['label'])];
            // A node is part of the hierarchy if it has a parent or children of its own.
            // Standalone top level options carry no 'parent' and the filter lists them under "other".
            if ($row['parentid'] !== 0 || !empty($haschildren[$row['id']])) {
                $option['parent'] = self::root_label_of($row['id'], $byid);
            }
            $options[(string) $row['id']] = $option;
        }
        return $options;
    }

    /**
     * Returns the label of the topmost ancestor of a row (its own label when top level).
     *
     * @param int $id
     * @param array $byid id => row
     * @return string
     */
    private static function root_label_of(int $id, array $byid): string {
        $cursor = $id;
        $guard = 0;
        while ($guard < 100) {
            $parentid = (int) ($byid[$cursor]['parentid'] ?? 0);
            if ($parentid === 0 || !isset($byid[$parentid])) {
                break;
            }
            $cursor = $parentid;
            $guard++;
        }
        return format_string((string) ($byid[$cursor]['label'] ?? ''));
    }

    /**
     * Returns the next free (never yet used) option id for a field.
     *
     * @param int $fieldid
     * @return int
     */
    public static function get_nextid(int $fieldid): int {
        $field = self::load_field($fieldid);
        $configdata = self::get_configdata($field);

        $nextid = (int) ($configdata[self::CONFIGKEY_NEXTID] ?? 0);
        if ($nextid >= 1) {
            return $nextid;
        }

        // Derive from the stored rows for fields that predate the counter.
        $max = 0;
        foreach (($configdata[self::CONFIGKEY_HIERARCHY] ?? []) as $row) {
            $max = max($max, (int) (((array) $row)['id'] ?? 0));
        }
        return $max + 1;
    }

    /**
     * Persists the given rows into the field's configdata and regenerates its dynamicsql.
     *
     * @param int $fieldid
     * @param array $rows list of ['id','label','parentid','delete']
     * @return void
     */
    public static function save(int $fieldid, array $rows): void {
        $field = self::load_field($fieldid);
        $configdata = self::get_configdata($field);

        [$rows, $nextid] = self::normalise_rows($rows, $configdata);

        $configdata[self::CONFIGKEY_HIERARCHY] = array_values($rows);
        $configdata[self::CONFIGKEY_NEXTID] = $nextid;
        $configdata['dynamicsql'] = self::build_dynamic_sql($rows);

        api::save_field_configuration($field, (object) [
            'id' => $fieldid,
            'configdata' => $configdata,
        ]);

        cache_helper::purge_by_definition('mod_booking', 'customfields');
    }

    /**
     * Creates a new mod_booking dynamicformat custom field and returns its id.
     *
     * The new field starts with an empty option list; manage its options afterwards.
     *
     * @param string $name human readable field name
     * @param string $shortname unique shortname
     * @param int $categoryid target category id; 0 = use first existing or auto-create
     * @return int the new field id
     */
    public static function create_field(string $name, string $shortname, int $categoryid = 0): int {
        $handler = booking_handler::create();

        if ($categoryid > 0) {
            $category = category_controller::create($categoryid);
        } else {
            $categories = $handler->get_categories_with_fields();
            if (!empty($categories)) {
                $category = reset($categories);
            } else {
                // Load the freshly created category directly: the handler's category cache is
                // already populated (empty) at this point, so re-reading it would be stale.
                $categoryid = $handler->create_category();
                $category = category_controller::create($categoryid);
            }
        }

        $field = field_controller::create(0, (object) ['type' => self::FIELD_TYPE], $category);
        api::save_field_configuration($field, (object) [
            'name' => $name,
            'shortname' => $shortname,
            'type' => self::FIELD_TYPE,
            // Store an empty (not null) description: mod_booking renders strlen($field->description).
            'description_editor' => ['text' => '', 'format' => FORMAT_HTML],
            'configdata' => [
                'dynamicsql' => '',
                'autocomplete' => 1,
                'defaultvalue' => '',
                'multiselect' => 1,
                self::CONFIGKEY_HIERARCHY => [],
                self::CONFIGKEY_NEXTID => 1,
            ],
        ]);

        cache_helper::purge_by_definition('mod_booking', 'customfields');

        return (int) $field->get('id');
    }

    /**
     * Validates rows for blank labels, duplicate ids, missing parents and nesting depth.
     *
     * Only a single level of hierarchy is permitted: an option may be a top level option
     * or the direct child of a top level option, but no deeper. A child may therefore never
     * be chosen as another row's parent.
     *
     * @param array $rows list of ['id','label','parentid','delete']
     * @return array errors keyed by row index ($i => message)
     */
    public static function validate_rows(array $rows): array {
        $errors = [];

        // Map id => parentid for non-deleted, labelled rows; flag duplicate ids.
        $parentof = [];
        foreach ($rows as $i => $row) {
            if (!empty($row['delete'])) {
                continue;
            }
            $label = trim((string) ($row['label'] ?? ''));
            $id = (int) ($row['id'] ?? 0);
            if ($label === '' || $id === 0) {
                continue;
            }
            if (isset($parentof[$id])) {
                $errors[$i] = get_string('error_duplicatekey', 'mod_booking');
            }
            $parentof[$id] = (int) ($row['parentid'] ?? 0);
        }

        foreach ($rows as $i => $row) {
            if (!empty($row['delete'])) {
                continue;
            }
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $parentid = (int) ($row['parentid'] ?? 0);
            if ($parentid === 0) {
                continue;
            }
            if (!isset($parentof[$parentid])) {
                $errors[$i] = get_string('error_unknownparent', 'mod_booking');
                continue;
            }
            // Only one level of nesting is allowed, so the chosen parent must itself be a top
            // level option. A parent that already has a parent would make this a grandchild;
            // a row pointing at itself is also caught here (it is its own non top level parent).
            if ($parentof[$parentid] !== 0) {
                $errors[$i] = get_string('error_nestedparent', 'mod_booking');
            }
        }

        return $errors;
    }

    /**
     * Drops deleted/blank rows and assigns stable ids to new rows.
     *
     * @param array $rows
     * @param array $configdata existing configdata (for the next-id counter)
     * @return array [normalised rows, next free id]
     */
    private static function normalise_rows(array $rows, array $configdata): array {
        $nextid = (int) ($configdata[self::CONFIGKEY_NEXTID] ?? 0);
        if ($nextid < 1) {
            $nextid = 1;
        }

        $clean = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            if (!empty($row['delete'])) {
                continue;
            }
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) {
                $id = $nextid;
            }
            if ($id >= $nextid) {
                $nextid = $id + 1;
            }
            $clean[] = [
                'id' => $id,
                'label' => $label,
                'parentid' => (int) ($row['parentid'] ?? 0),
            ];
        }

        return [$clean, $nextid];
    }

    /**
     * Builds the read-only dynamicsql that returns id + data for every option, ordered.
     *
     * @param array $rows normalised rows
     * @return string empty string when there are no rows
     */
    private static function build_dynamic_sql(array $rows): string {
        if (empty($rows)) {
            return '';
        }

        $parentof = [];
        foreach ($rows as $row) {
            $parentof[$row['id']] = $row['parentid'];
        }

        $selects = [];
        foreach (array_values($rows) as $sortorder => $row) {
            $depth = self::depth_of($row['id'], $parentof);
            $label = str_repeat('- ', $depth) . $row['label'];
            $label = self::quote_sql_string($label);
            $selects[] = sprintf('SELECT %d AS sortorder, %d AS id, %s AS data', $sortorder, $row['id'], $label);
        }

        return 'SELECT id, data FROM (' . implode(' UNION ALL ', $selects) . ') optvals ORDER BY sortorder';
    }

    /**
     * Computes the depth of a row by walking its parent chain.
     *
     * @param int $id
     * @param array $parentof id => parentid
     * @return int
     */
    private static function depth_of(int $id, array $parentof): int {
        $depth = 0;
        $cursor = $parentof[$id] ?? 0;
        $guard = 0;
        while ($cursor !== 0 && isset($parentof[$cursor]) && $guard < 100) {
            $depth++;
            $cursor = $parentof[$cursor];
            $guard++;
        }
        return $depth;
    }

    /**
     * Escapes a string for safe inclusion as a single-quoted SQL literal.
     *
     * @param string $value
     * @return string the quoted literal, including the surrounding quotes
     */
    private static function quote_sql_string(string $value): string {
        // Drop control characters and semicolons, then double single quotes.
        $value = preg_replace('/[\x00-\x1F;]/', '', $value);
        return "'" . str_replace("'", "''", $value) . "'";
    }

    /**
     * Seeds flat rows from a field's currently rendered options (fallback for existing fields).
     *
     * @param field_controller $field
     * @return array
     */
    private static function seed_rows_from_options(field_controller $field): array {
        $rows = [];
        if (!class_exists('\customfield_dynamicformat\field_controller')) {
            return $rows;
        }
        $options = \customfield_dynamicformat\field_controller::get_options_array($field);
        $fallbackid = 1;
        foreach ($options as $key => $label) {
            if ((string) $key === '') {
                // Skip the leading "Choose..." entry.
                continue;
            }
            $id = is_numeric($key) ? (int) $key : $fallbackid;
            $rows[] = ['id' => $id, 'label' => (string) $label, 'parentid' => 0];
            $fallbackid = max($fallbackid, $id) + 1;
        }
        return $rows;
    }

    /**
     * Loads a field controller and asserts it is a mod_booking dynamicformat custom field.
     *
     * @param int $fieldid
     * @return field_controller
     */
    private static function load_field(int $fieldid): field_controller {
        if (empty($fieldid)) {
            throw new coding_exception('A valid custom field id is required.');
        }
        $field = field_controller::create($fieldid);
        $handler = $field->get_handler();
        if ($handler->get_component() !== 'mod_booking' || $handler->get_area() !== 'booking') {
            throw new moodle_exception('error_notbookingfield', 'mod_booking');
        }
        if ($field->get('type') !== self::FIELD_TYPE) {
            throw new moodle_exception('error_notdynamicfield', 'mod_booking');
        }
        return $field;
    }

    /**
     * Returns the field's configdata as an array.
     *
     * @param field_controller $field
     * @return array
     */
    private static function get_configdata(field_controller $field): array {
        $configdata = $field->get('configdata');
        if (is_string($configdata)) {
            $configdata = json_decode($configdata, true);
        }
        return is_array($configdata) ? $configdata : [];
    }
}
