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
use external_value;
use mod_booking\booking_option;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * Provides the mod_booking_search_booking_options external function.
 *
 * @package     mod_booking
 * @category    external
 * @copyright   2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Bernhard Fischer
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_booking_options extends external_api {

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
     * Finds booking options matching the given query.
     *
     * @param string $query The search request.
     * @return array
     */
    public static function execute(string $query): array {

        $params = self::validate_parameters(self::execute_parameters(), [
            'query' => $query,
        ]);

        return booking_option::load_booking_options($params['query']);
    }

    /**
     * Describes the external function result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): \external_single_structure {

        return new \external_single_structure([
            'list' => new \external_multiple_structure(
                new \external_single_structure([
                    'id' => new \external_value(\core_user::get_property_type('id'), 'ID of the booking option'),
                    'titleprefix' => new \external_value(PARAM_TEXT, 'Prefix of the booking option name'),
                    'text' => new \external_value(PARAM_TEXT, 'Name of the booking option'),
                    'instancename' => new \external_value(PARAM_TEXT, 'Name of the booking instance'),
                ])
            ),
            'warnings' => new \external_value(PARAM_TEXT, 'Warnings'),
        ]);
    }
}
