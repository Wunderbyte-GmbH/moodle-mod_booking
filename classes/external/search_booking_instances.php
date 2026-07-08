<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_booking\external;

use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use mod_booking\singleton_service;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * Provides the mod_booking_search_booking_instances external function.
 *
 * @package     mod_booking
 * @category    external
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_booking_instances extends external_api {
    /**
     * Describes the external function parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'query' => new external_value(PARAM_TEXT, 'The search query', VALUE_REQUIRED),
            'cmid' => new external_value(PARAM_INT, 'Optional course module id filter', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Finds booking instances matching the given query.
     *
     * @param string $query The search request.
     * @param int $cmid Optional course module id filter.
     * @return array
     */
    public static function execute(string $query, int $cmid = 0): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), ['query' => $query, 'cmid' => $cmid]);

        $context = \context_system::instance();
        self::validate_context($context);

        $bookingid = 0;
        if (!empty($params['cmid'])) {
            $booking = singleton_service::get_instance_of_booking_settings_by_cmid($params['cmid']);
            $bookingid = $booking->id;
        }

        $searchterm = '%' . $DB->sql_like_escape($params['query']) . '%';
        $sqlparams = ['searchterm' => $searchterm];
        $where = $DB->sql_like('b.name', ':searchterm', false);

        if (!empty($bookingid)) {
            $where .= " AND b.id = :bookingid";
            $sqlparams['bookingid'] = $bookingid;
        }

        $sql = "SELECT b.id, b.name AS text, c.fullname AS coursename, cm.visible AS visible
                FROM {booking} b
                JOIN {course} c ON c.id = b.course
                JOIN {modules} m ON m.name = 'booking'
                JOIN {course_modules} cm ON cm.module = m.id AND cm.instance = b.id
                WHERE $where
                ORDER BY b.name ASC";

        $records = $DB->get_records_sql($sql, $sqlparams);

        $list = [];
        foreach ($records as $record) {
            $list[] = [
                'id' => (int)$record->id,
                'text' => (string)$record->text,
                'coursename' => (string)$record->coursename,
                'visibility' => empty($record->visible)
                    ? get_string('hiddenfromstudents')
                    : get_string('visible'),
            ];
        }

        return ['list' => $list, 'warnings' => ''];
    }

    /**
     * Describes the external function result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'list' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'ID of the booking instance'),
                    'text' => new external_value(PARAM_TEXT, 'Name of the booking instance'),
                    'coursename' => new external_value(PARAM_TEXT, 'Name of the course'),
                    'visibility' => new external_value(PARAM_TEXT, 'Visibility of the booking instance'),
                ])
            ),
            'warnings' => new external_value(PARAM_TEXT, 'Warnings'),
        ]);
    }
}
