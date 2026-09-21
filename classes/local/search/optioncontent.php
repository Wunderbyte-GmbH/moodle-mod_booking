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
 * Builds the indexable content of a booking option.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\search;

use mod_booking\customfield\booking_handler;
use mod_booking\singleton_service;
use stdClass;

/**
 * Builds the indexable content of a booking option.
 *
 * The result is a plain text array with the four document fields core search knows. Every
 * source can be switched off in the plugin settings, see searchconfig.
 */
class optioncontent {
    /**
     * Build title, content and the two description fields for one booking option.
     *
     * @param stdClass $record record of booking_options
     * @return array keys title, content, description1, description2
     */
    public static function build(stdClass $record): array {
        return [
            'title' => self::build_title($record),
            'content' => self::build_content($record),
            'description1' => self::build_coordinates($record),
            'description2' => self::build_related_data($record),
        ];
    }

    /**
     * Title of the document: title prefix and option name.
     *
     * @param stdClass $record
     * @return string
     */
    protected static function build_title(stdClass $record): string {
        $parts = [];

        if (!empty($record->titleprefix)) {
            $parts[] = $record->titleprefix;
        }

        if (!empty($record->text)) {
            $parts[] = $record->text;
        }

        return content_to_text(implode(' ', $parts), false);
    }

    /**
     * Main content of the document: the description of the option.
     *
     * @param stdClass $record
     * @return string
     */
    protected static function build_content(stdClass $record): string {
        if (!searchconfig::is_source_enabled(searchconfig::SOURCE_DESCRIPTION)) {
            return '';
        }

        if (empty($record->description)) {
            return '';
        }

        return content_to_text($record->description, $record->descriptionformat ?? FORMAT_HTML);
    }

    /**
     * Where and when the booking option takes place, plus the identifier.
     *
     * @param stdClass $record
     * @return string
     */
    protected static function build_coordinates(stdClass $record): string {
        $parts = [];

        if (searchconfig::is_source_enabled(searchconfig::SOURCE_LOCATION)) {
            $parts[] = $record->location ?? '';
            $parts[] = $record->institution ?? '';
            $parts[] = $record->address ?? '';
        }

        if (searchconfig::is_source_enabled(searchconfig::SOURCE_DAYOFWEEKTIME)) {
            $parts[] = $record->dayofweektime ?? '';
        }

        if (searchconfig::is_source_enabled(searchconfig::SOURCE_IDENTIFIER)) {
            $parts[] = $record->identifier ?? '';
        }

        if (searchconfig::is_source_enabled(searchconfig::SOURCE_ANNOTATION)) {
            $parts[] = $record->annotation ?? '';
        }

        return self::join_parts($parts);
    }

    /**
     * Everything which is not stored in the booking option record itself.
     *
     * @param stdClass $record
     * @return string
     */
    protected static function build_related_data(stdClass $record): string {
        $optionid = (int) $record->id;

        $parts = array_merge(
            self::get_customfield_values($optionid),
            self::get_teacher_names($optionid),
            self::get_entity_names($optionid),
            self::get_connected_course_name($record),
            self::get_competency_names($record),
            self::get_certificate_template_name($record)
        );

        return self::join_parts($parts);
    }

    /**
     * Display values of the custom fields of the booking option.
     *
     * Select and multiselect values are resolved to their labels, so that a search for the
     * label of an option finds the booking option. Fields which are not visible to everybody
     * are never indexed: one document is served to every user who passes check_access.
     *
     * @param int $optionid
     * @return array
     */
    protected static function get_customfield_values(int $optionid): array {
        if (!searchconfig::is_source_enabled(searchconfig::SOURCE_CUSTOMFIELDS)) {
            return [];
        }

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        if (empty($settings->customfieldsfortemplates)) {
            return [];
        }

        $selectedshortnames = searchconfig::get_customfield_shortnames();
        $values = [];

        foreach ($settings->customfieldsfortemplates as $shortname => $customfield) {
            if (!empty($selectedshortnames) && !in_array($shortname, $selectedshortnames, true)) {
                continue;
            }

            if (!self::customfield_is_visible_to_all($customfield)) {
                continue;
            }

            $value = $customfield['value'] ?? '';

            if (is_array($value)) {
                $value = implode(' ', $value);
            }

            if ($value === '' || $value === null) {
                continue;
            }

            $values[] = (string) $value;
        }

        return $values;
    }

    /**
     * Whether a custom field is shown to everybody who can see the booking option.
     *
     * @param array $customfield entry of booking_option_settings::$customfieldsfortemplates
     * @return bool
     */
    protected static function customfield_is_visible_to_all(array $customfield): bool {
        $field = $customfield['field'] ?? null;

        if (empty($field)) {
            return false;
        }

        $configdata = json_decode($field->configdata ?? '', true);
        $visibility = $configdata['visibility'] ?? booking_handler::MOD_BOOKING_VISIBLETOALL;

        return (int) $visibility === booking_handler::MOD_BOOKING_VISIBLETOALL;
    }

    /**
     * Full names of the teachers of the booking option.
     *
     * @param int $optionid
     * @return array
     */
    protected static function get_teacher_names(int $optionid): array {
        global $DB;

        if (!searchconfig::is_source_enabled(searchconfig::SOURCE_TEACHERS)) {
            return [];
        }

        $userfields = \core_user\fields::for_name()->get_sql('u')->selects;

        $sql = "SELECT u.id $userfields
                  FROM {booking_teachers} bt
                  JOIN {user} u ON u.id = bt.userid
                 WHERE bt.optionid = :optionid";

        $teachers = $DB->get_records_sql($sql, ['optionid' => $optionid]);

        return array_values(array_map(fn($teacher) => fullname($teacher), $teachers));
    }

    /**
     * Names of the entities of the booking option and of its dates.
     *
     * Degrades gracefully when local_entities is not installed.
     *
     * @param int $optionid
     * @return array
     */
    protected static function get_entity_names(int $optionid): array {
        global $DB;

        if (!searchconfig::is_source_enabled(searchconfig::SOURCE_ENTITIES)) {
            return [];
        }

        if (!class_exists('local_entities\entitiesrelation_handler')) {
            return [];
        }

        $sql = "SELECT DISTINCT e.id, e.name
                  FROM {local_entities_relations} er
                  JOIN {local_entities} e ON e.id = er.entityid
                 WHERE er.component = :component
                   AND (
                        (er.area = :optionarea AND er.instanceid = :optionid)
                        OR (er.area = :optiondatearea AND er.instanceid IN (
                                SELECT bod.id FROM {booking_optiondates} bod WHERE bod.optionid = :dateoptionid
                            ))
                   )";

        $entities = $DB->get_records_sql($sql, [
            'component' => 'mod_booking',
            'optionarea' => 'option',
            'optionid' => $optionid,
            'optiondatearea' => 'optiondate',
            'dateoptionid' => $optionid,
        ]);

        return array_values(array_map(fn($entity) => (string) $entity->name, $entities));
    }

    /**
     * Full name of the course connected to the booking option.
     *
     * @param stdClass $record
     * @return array
     */
    protected static function get_connected_course_name(stdClass $record): array {
        global $DB;

        if (!searchconfig::is_source_enabled(searchconfig::SOURCE_LINKEDCOURSE)) {
            return [];
        }

        if (empty($record->courseid)) {
            return [];
        }

        $fullname = $DB->get_field('course', 'fullname', ['id' => $record->courseid], IGNORE_MISSING);

        return empty($fullname) ? [] : [(string) $fullname];
    }

    /**
     * Names of the competencies of the booking option.
     *
     * @param stdClass $record
     * @return array
     */
    protected static function get_competency_names(stdClass $record): array {
        global $DB;

        if (!searchconfig::is_source_enabled(searchconfig::SOURCE_COMPETENCIES)) {
            return [];
        }

        if (empty($record->competencies)) {
            return [];
        }

        $competencyids = array_filter(array_map('intval', explode(',', (string) $record->competencies)));

        if (empty($competencyids)) {
            return [];
        }

        [$insql, $inparams] = $DB->get_in_or_equal($competencyids, SQL_PARAMS_NAMED);
        $competencies = $DB->get_records_select('competency', "id $insql", $inparams, '', 'id, shortname');

        return array_values(array_map(fn($competency) => (string) $competency->shortname, $competencies));
    }

    /**
     * Name of the certificate template configured on the booking option.
     *
     * Certificates issued to users are personal data and are never indexed.
     *
     * @param stdClass $record
     * @return array
     */
    protected static function get_certificate_template_name(stdClass $record): array {
        global $DB;

        if (!searchconfig::is_source_enabled(searchconfig::SOURCE_CERTIFICATE)) {
            return [];
        }

        if (!class_exists('tool_certificate\certificate')) {
            return [];
        }

        $json = json_decode($record->json ?? '', true);
        $templateid = (int) ($json['certificate'] ?? 0);

        if (empty($templateid)) {
            return [];
        }

        $name = $DB->get_field('tool_certificate_templates', 'name', ['id' => $templateid], IGNORE_MISSING);

        return empty($name) ? [] : [(string) $name];
    }

    /**
     * Join the collected values to one plain text string.
     *
     * @param array $parts
     * @return string
     */
    protected static function join_parts(array $parts): string {
        $parts = array_filter(array_map('trim', array_map('strval', $parts)), fn($part) => $part !== '');

        if (empty($parts)) {
            return '';
        }

        return content_to_text(implode(', ', $parts), false);
    }
}
