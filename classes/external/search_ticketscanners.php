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
 * Webservice: search users who may be picked as entry staff (ticket scanners) of a booking option.
 *
 * The result depends on the searching user: who may view every profile on the site (managers) gets
 * everybody, everyone else only users of the instance's course whose profile they may view.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_booking\external;

use context_course;
use context_module;
use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_booking\booking;
use mod_booking\local\ticket\ticket_manager;

/**
 * External service searching pickable entry staff for a booking option.
 *
 * @package   mod_booking
 * @copyright 2026 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_ticketscanners extends external_api {
    /**
     * Describes the external function parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'query' => new external_value(PARAM_TEXT, 'The search query', VALUE_REQUIRED),
            'cmid' => new external_value(PARAM_INT, 'Course module id of the booking instance', VALUE_REQUIRED),
        ]);
    }

    /**
     * Search users the current user may pick as entry staff of an option in this booking instance.
     *
     * @param string $query
     * @param int $cmid
     *
     * @return array
     */
    public static function execute(string $query, int $cmid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['query' => $query, 'cmid' => $cmid]);
        $query = $params['query'];
        $cmid = $params['cmid'];

        $context = context_module::instance($cmid);
        self::validate_context($context);
        if (
            !has_capability('mod/booking:updatebooking', $context)
            && !has_capability('mod/booking:editownoption', $context)
        ) {
            throw new \required_capability_exception($context, 'mod/booking:updatebooking', 'nopermissions', '');
        }

        if (has_capability('moodle/user:viewdetails', context_system::instance())) {
            // May view every profile: anyone can be picked.
            return booking::load_users($query);
        }

        // Everybody else: only users of the course, and among them only those whose profile they may view.
        $course = get_course($context->get_course_context()->instanceid);
        [$enrolledsql, $enrolledparams] = get_enrolled_sql(context_course::instance($course->id));
        $result = booking::load_users($query, $enrolledsql, $enrolledparams);
        $result['list'] = array_filter(
            $result['list'],
            fn($user) => ticket_manager::user_may_pick_scanner($user, $course)
        );
        return $result;
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
                    'id' => new external_value(\core_user::get_property_type('id'), 'ID of the user'),
                    'firstname' => new external_value(PARAM_TEXT, 'Firstname of the user'),
                    'lastname' => new external_value(PARAM_TEXT, 'Lastname of the user', VALUE_OPTIONAL),
                    'email' => new external_value(PARAM_TEXT, 'Email of the user', VALUE_OPTIONAL),
                ])
            ),
            'warnings' => new external_value(PARAM_TEXT, 'Warnings'),
        ]);
    }
}
