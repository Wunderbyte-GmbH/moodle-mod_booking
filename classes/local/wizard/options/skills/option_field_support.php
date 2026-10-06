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

namespace mod_booking\local\wizard\options\skills;

use core_component;
use mod_booking\customfield\booking_handler;

/**
 * Shared helpers for the booking option field skills.
 *
 * Booking option fields are site wide custom fields (component mod_booking, area booking). All three
 * skills that read or write them need the same facts: which field types this site has installed, what
 * a field looks like, and whether a shortname may be used at all. The shortname rule itself lives on
 * booking_handler, so the management page and the skills decide identically.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class option_field_support {
    /** @var string Capability that allows maintaining the site-wide booking option field list. */
    public const CAPABILITY = 'mod/booking:managecustomfields';

    /** @var string Shortname pattern accepted by core's custom field configuration form. */
    public const SHORTNAME_PATTERN = '/^[a-z0-9_]+$/';

    /**
     * The custom field types installed on this site, as [type => human readable name].
     *
     * Read from the installed plugins, never from a hardcoded list, so a field type that is
     * installed later works without a code change.
     *
     * @return array<string,string>
     */
    public static function get_installed_types(): array {
        $types = [];
        foreach (array_keys(core_component::get_plugin_list('customfield')) as $type) {
            $type = (string)$type;
            $component = 'customfield_' . $type;
            $types[$type] = get_string_manager()->string_exists('pluginname', $component)
                ? get_string('pluginname', $component)
                : $type;
        }
        ksort($types);
        return $types;
    }

    /**
     * Whether this custom field type is installed on this site.
     *
     * @param string $type
     * @return bool
     */
    public static function type_is_installed(string $type): bool {
        return array_key_exists(trim($type), self::get_installed_types());
    }

    /**
     * The booking option field categories of this site, as [id => name].
     *
     * @return array<int,string>
     */
    public static function get_categories(): array {
        $categories = [];
        foreach (booking_handler::create()->get_categories_with_fields() as $category) {
            $categories[(int)$category->get('id')] = (string)$category->get('name');
        }
        return $categories;
    }

    /**
     * Load one booking option field by shortname, or null when no field has this shortname.
     *
     * @param string $shortname
     * @return \stdClass|null raw field record including its category
     */
    public static function get_field_by_shortname(string $shortname): ?\stdClass {
        foreach (self::get_field_records() as $record) {
            if ($record->shortname === trim($shortname)) {
                return $record;
            }
        }
        return null;
    }

    /**
     * Load one booking option field by id, or null when it does not exist or is not a booking field.
     *
     * @param int $fieldid
     * @return \stdClass|null
     */
    public static function get_field_by_id(int $fieldid): ?\stdClass {
        foreach (self::get_field_records() as $record) {
            if ((int)$record->id === $fieldid) {
                return $record;
            }
        }
        return null;
    }

    /**
     * All booking option fields of this site with their category, ordered as they are shown.
     *
     * @return array<int,\stdClass>
     */
    public static function get_field_records(): array {
        global $DB;

        $sql = "SELECT cff.id, cff.name, cff.shortname, cff.type, cff.description, cff.configdata,
                       cff.sortorder, cff.categoryid, cfc.name AS categoryname
                  FROM {customfield_field} cff
                  JOIN {customfield_category} cfc ON cfc.id = cff.categoryid
                 WHERE cfc.component = 'mod_booking'
                   AND cfc.area = 'booking'
              ORDER BY cfc.sortorder ASC, cff.sortorder ASC";

        return array_values($DB->get_records_sql($sql));
    }

    /**
     * How many booking options currently hold a value for this field.
     *
     * @param int $fieldid
     * @return int
     */
    public static function count_options_with_value(int $fieldid): int {
        global $DB;

        $sql = "SELECT COUNT(DISTINCT cfd.instanceid)
                  FROM {customfield_data} cfd
                 WHERE cfd.fieldid = :fieldid
                   AND cfd.value IS NOT NULL
                   AND " . $DB->sql_compare_text('cfd.value') . " <> :emptyvalue";

        return (int)$DB->count_records_sql($sql, ['fieldid' => $fieldid, 'emptyvalue' => '']);
    }

    /**
     * Build the preview descriptor of one field: everything the management page shows about it,
     * plus the number of options that hold a value.
     *
     * @param \stdClass $record field record from get_field_records()
     * @param bool $withusage whether to count the options holding a value (one query per field)
     * @return array<string,mixed>
     */
    public static function describe_field(\stdClass $record, bool $withusage = true): array {
        $configdata = json_decode((string)($record->configdata ?? ''), true);
        if (!is_array($configdata)) {
            $configdata = [];
        }

        $types = self::get_installed_types();
        $descriptor = [
            'id' => (int)$record->id,
            'shortname' => (string)$record->shortname,
            'name' => (string)$record->name,
            'type' => (string)$record->type,
            'typename' => (string)($types[(string)$record->type] ?? (string)$record->type),
            'typeinstalled' => array_key_exists((string)$record->type, $types),
            'categoryid' => (int)$record->categoryid,
            'categoryname' => (string)$record->categoryname,
            'required' => !empty($configdata['required']),
            'uniquevalues' => !empty($configdata['uniquevalues']),
            'locked' => !empty($configdata['locked']),
            'visibility' => isset($configdata['visibility']) ? (int)$configdata['visibility'] : null,
            'defaultvalue' => $configdata['defaultvalue'] ?? null,
            'configdata' => $configdata,
            'shadowsoptionproperty' => booking_handler::is_reserved_shortname((string)$record->shortname),
        ];

        if ($withusage) {
            $descriptor['optionswithvalue'] = self::count_options_with_value((int)$record->id);
        }

        return $descriptor;
    }

    /**
     * Normalise the select options a caller passed: a list, or one option per line.
     *
     * @param mixed $options
     * @return array<int,string>
     */
    public static function normalize_options($options): array {
        if (is_string($options)) {
            $options = preg_split('/\r\n|\r|\n/', $options) ?: [];
        }
        if (!is_array($options)) {
            return [];
        }

        $normalized = [];
        foreach ($options as $option) {
            $option = trim((string)$option);
            if ($option !== '') {
                $normalized[] = $option;
            }
        }
        return array_values(array_unique($normalized));
    }
}
