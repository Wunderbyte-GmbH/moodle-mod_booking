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
use mod_booking\booking;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * Provides the mod_booking_search_courses external function.
 *
 * @package     mod_booking
 * @category    external
 * @copyright   2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Bernhard Fischer
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_courses extends external_api {

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

        return booking::load_courses($params['query']);
    }

    /**
     * Describes the external function result value.
     *
     * @return \external_single_structure
     */
    public static function execute_returns(): \external_single_structure {

        return new \external_single_structure([
            'list' => new \external_multiple_structure(
                new \external_single_structure([
                    'id' => new \external_value(PARAM_INT, 'ID of the course'),
                    'fullname' => new \external_value(PARAM_TEXT, 'Name of the course'),
                    'shortname' => new \external_value(PARAM_TEXT, 'Shortname of the course'),
                ])
            ),
            'warnings' => new \external_value(PARAM_TEXT, 'Warnings'),
        ]);
    }
}
