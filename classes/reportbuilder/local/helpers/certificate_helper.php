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

namespace mod_booking\reportbuilder\local\helpers;

use stdClass;

/**
 * Resolves the certificates a booking option grants, for Report Builder columns.
 *
 * A booking option can grant a certificate in two independent ways, both of which are live
 * regardless of the "certificateoptions" site setting (that setting only switches the UI):
 * - the legacy template stored in the option's JSON under the key "certificate";
 * - active certificate conditions targeting the option, either directly (item area "bookingoption",
 *   used by the bookingoption and taggedoptions logic) or through its booking instance (item area
 *   "bookinginstance", used by the instance logic). The template is the "certid" of the action.
 *
 * All lookups are served from a request-level cache that is built with two queries, so a report
 * row only performs array lookups.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class certificate_helper {
    /** @var array<int, stdClass[]>|null Active conditions indexed by targeted option id. */
    private static ?array $optiontargets = null;

    /** @var array<int, stdClass[]> Active conditions indexed by targeted booking instance id. */
    private static array $instancetargets = [];

    /** @var array<int, string>|null Certificate template names indexed by template id. */
    private static ?array $templatenames = null;

    /**
     * Active certificate conditions applying to a booking option, sorted by name.
     *
     * Each record carries id, name and certid (template id of the createcertificate action, 0 if none).
     *
     * @param int $optionid
     * @param int $bookingid
     * @return stdClass[]
     */
    public static function get_conditions_for_option(int $optionid, int $bookingid): array {
        self::build_conditions_cache();

        $conditions = [];
        foreach (self::$optiontargets[$optionid] ?? [] as $condition) {
            $conditions[$condition->id] = $condition;
        }
        foreach (self::$instancetargets[$bookingid] ?? [] as $condition) {
            $conditions[$condition->id] = $condition;
        }

        usort($conditions, static fn(stdClass $a, stdClass $b): int => strcmp($a->name, $b->name));
        return $conditions;
    }

    /**
     * Names of the certificate conditions applying to a booking option.
     *
     * @param int $optionid
     * @param int $bookingid
     * @return string[]
     */
    public static function get_condition_names_for_option(int $optionid, int $bookingid): array {
        return array_map(
            static fn(stdClass $condition): string => $condition->name,
            self::get_conditions_for_option($optionid, $bookingid)
        );
    }

    /**
     * Names of all certificate templates a booking option grants: the legacy template from the
     * option JSON followed by the templates of all applying certificate conditions.
     *
     * Unknown template ids (template deleted, tool_certificate missing) are rendered as "#<id>".
     *
     * @param int $optionid
     * @param string|null $json Raw JSON column of the booking option
     * @param int $bookingid
     * @return string[]
     */
    public static function get_template_names_for_option(int $optionid, ?string $json, int $bookingid): array {
        $templateids = [];

        if (!empty($json)) {
            $decoded = json_decode($json);
            if (!empty($decoded->certificate)) {
                $templateids[] = (int) $decoded->certificate;
            }
        }

        foreach (self::get_conditions_for_option($optionid, $bookingid) as $condition) {
            if (!empty($condition->certid)) {
                $templateids[] = (int) $condition->certid;
            }
        }

        $names = [];
        foreach (array_unique($templateids) as $templateid) {
            $names[] = self::get_template_name($templateid);
        }
        return $names;
    }

    /**
     * Reset the request-level caches (used by unit tests).
     *
     * @return void
     */
    public static function reset_caches(): void {
        self::$optiontargets = null;
        self::$instancetargets = [];
        self::$templatenames = null;
    }

    /**
     * Load all active certificate conditions together with the options and instances they target.
     *
     * @return void
     */
    private static function build_conditions_cache(): void {
        global $DB;

        if (self::$optiontargets !== null) {
            return;
        }
        self::$optiontargets = [];
        self::$instancetargets = [];

        $sql = "SELECT i.id AS itemrowid, i.area, i.itemid, c.id, c.name, c.actionjson
                  FROM {booking_cert_cond_item} i
                  JOIN {booking_cert_cond} c ON c.id = i.conditionid
                 WHERE c.isactive = 1
                   AND i.component = 'mod_booking'
                   AND i.area IN ('bookingoption', 'bookinginstance')";
        $records = $DB->get_records_sql($sql);

        $conditions = [];
        foreach ($records as $record) {
            if (!isset($conditions[$record->id])) {
                $action = empty($record->actionjson) ? null : json_decode($record->actionjson);
                $conditions[$record->id] = (object) [
                    'id' => (int) $record->id,
                    'name' => (string) ($record->name ?? ''),
                    'certid' => (int) ($action->certid ?? 0),
                ];
            }
            $condition = $conditions[$record->id];
            $itemid = (int) $record->itemid;
            if ($itemid <= 0) {
                continue;
            }
            if ($record->area === 'bookinginstance') {
                self::$instancetargets[$itemid][$condition->id] = $condition;
            } else {
                self::$optiontargets[$itemid][$condition->id] = $condition;
            }
        }
    }

    /**
     * Resolve a certificate template id to its name.
     *
     * @param int $templateid
     * @return string
     */
    private static function get_template_name(int $templateid): string {
        global $DB;

        if (self::$templatenames === null) {
            self::$templatenames = [];
            if (class_exists('tool_certificate\certificate')) {
                self::$templatenames = $DB->get_records_menu('tool_certificate_templates', null, '', 'id, name');
            }
        }

        return self::$templatenames[$templateid] ?? '#' . $templateid;
    }
}
