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
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use mod_booking\booking;
use mod_booking\permissions;

defined('MOODLE_INTERNAL') || die();


/**
 * Provides the mod_booking_search_users external function.
 *
 * @package     mod_booking
 * @category    external
 * @copyright   2023 Georg Maißer <georg.maisser@wunderbyt.at>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_users extends external_api {
    /**
     * Describes the external function parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {

        return new external_function_parameters([
            'query' => new external_value(PARAM_TEXT, 'The search query', VALUE_REQUIRED),
        ]);
    }

    /**
     * Finds entities with the name matching the given query.
     *
     * @param string $query The search request.
     * @return array
     */
    public static function execute(string $query): array {
        global $DB, $CFG;

        $params = self::validate_parameters(self::execute_parameters(), [
            'query' => $query,
        ]);

        // We can't know for which context the user is searching for users,
        // So we check if they have one of the capabilities to update bookings anywhere in the system.
        self::validate_context(\context_system::instance());
        permissions::require_any_booking_editing_capability();

        return booking::load_users($params['query']);
    }

    /**
     * Describes the external function result value.
     *
     * @return \core_external\external_single_structure
     */
    public static function execute_returns(): \core_external\external_single_structure {

        return new \core_external\external_single_structure([
            'list' => new \core_external\external_multiple_structure(
                new \core_external\external_single_structure([
                    'id' => new \core_external\external_value(\core_user::get_property_type('id'), 'ID of the user'),
                    'firstname' => new \core_external\external_value(PARAM_TEXT, 'Firstname of the user'),
                    'lastname' => new \core_external\external_value(PARAM_TEXT, 'Lastname of the user', VALUE_OPTIONAL),
                    'email' => new \core_external\external_value(PARAM_TEXT, 'Email of the user', VALUE_OPTIONAL),
                ])
            ),
            'warnings' => new \core_external\external_value(PARAM_TEXT, 'Warnings'),
        ]);
    }
}
