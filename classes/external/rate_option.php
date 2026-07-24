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
 * This class contains a webservice function related to the Booking Module by Wunderbyte.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_booking\singleton_service;
use stdClass;

/**
 * External service to rate a booking option.
 *
 * @package   mod_booking
 * @copyright 2026 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rate_option extends external_api {
    /**
     * Describes the parameters for rate_option.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'course module id of the booking instance'),
            'optionid' => new external_value(PARAM_INT, 'booking option id'),
            'rate' => new external_value(PARAM_INT, 'rating value'),
        ]);
    }

    /**
     * Stores the current user's rating for a booking option and returns the new average.
     *
     * @param int $cmid
     * @param int $optionid
     * @param int $rate
     *
     * @return array
     */
    public static function execute(int $cmid, int $optionid, int $rate): array {
        global $DB, $USER;

        $params = self::validate_parameters(
            self::execute_parameters(),
            ['cmid' => $cmid, 'optionid' => $optionid, 'rate' => $rate]
        );

        // Includes the login and course access check (like require_course_login in the old rating_rest.php).
        self::validate_context(\context_module::instance($params['cmid']));

        $bookingoption = singleton_service::get_instance_of_booking_option($params['cmid'], $params['optionid']);

        $duplicate = false;
        if ($bookingoption->can_rate()) {
            $conditions = ['userid' => $USER->id, 'optionid' => $params['optionid']];
            if ($DB->record_exists('booking_ratings', $conditions)) {
                // Ratings cannot be changed once given.
                $duplicate = true;
            } else {
                $record = new stdClass();
                $record->userid = $USER->id;
                $record->optionid = $params['optionid'];
                $record->rate = $params['rate'];
                $DB->insert_record('booking_ratings', $record, false);
            }
        }

        $avg = $DB->get_field_sql(
            'SELECT AVG(rate) FROM {booking_ratings} WHERE optionid = ?',
            [$params['optionid']]
        );

        return [
            'rate' => (int) round((float) ($avg ?? 1)),
            'duplicate' => $duplicate,
        ];
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'rate' => new external_value(PARAM_INT, 'average rating of the booking option'),
            'duplicate' => new external_value(PARAM_BOOL, 'true if the user had already rated this option'),
        ]);
    }
}
