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
 * This class contains a list of webservice functions related to the Shopping Cart Module by Wunderbyte.
 *
 * @package    mod_booking
 * @copyright  2024 Georg Maißer <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_booking\external;

use core_plugin_manager;
use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_value;
use external_single_structure;
use context_coursecat;
use mod_booking\coursecategories;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * External Service for shopping cart.
 *
 * @package   mod_booking
 * @copyright 2024 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Georg Maißer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_parent_categories extends external_api {

    /**
     * Describes the paramters for add_item_to_cart.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
          'coursecategoryid'  => new external_value(PARAM_INT, 'coursecategoryid', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Webservice for shopping_cart class to add a new item to the cart.
     *
     * @param int $coursecategoryid
     *
     * @return array
     */
    public static function execute(int $coursecategoryid): array {

        global $DB;

        require_login();

        $params = self::validate_parameters(self::execute_parameters(), [
            'coursecategoryid' => $coursecategoryid,
        ]);

        $records = coursecategories::return_course_categories($params['coursecategoryid']);

        usort($records, fn($a, $b) => strcmp(strtolower($a->name), strtolower($b->name)));

        $coursecount = 0;
        $bookingoptionscount = 0;
        $bookedcount = 0;
        $waitinglistcount = 0;
        $reservedcount = 0;

        if (empty($params['coursecategoryid'])) {
            $returnarray = [
                [
                    'id' => 0,
                    'name' => get_string('dashboardsummary', 'mod_booking'),
                    'contextid' => 1,
                    'coursecount' => $coursecount,
                    'description' => get_string('dashboardsummary_desc', 'mod_booking'),
                    'path' => '',
                    'json' => '',
                ],
            ];
        } else {
            $returnarray = [];
        }

        return $returnarray;
    }

    /**
     * Returns array of items.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure(
                [
                    'id' => new external_value(PARAM_INT, 'Item id', VALUE_DEFAULT, 0),
                    'name' => new external_value(PARAM_RAW, 'Item name', VALUE_DEFAULT, ''),
                    'contextid' => new external_value(PARAM_TEXT, 'Contextid', VALUE_DEFAULT, 1),
                    'coursecount' => new external_value(PARAM_TEXT, 'Coursecount', VALUE_DEFAULT, 0),
                    'bookingoptionscount' => new external_value(PARAM_TEXT, 'Bookingoptions count', VALUE_DEFAULT, 0),
                    'bookedcount' => new external_value(PARAM_TEXT, 'Booked count', VALUE_DEFAULT, 0),
                    'waitinglistcount' => new external_value(PARAM_TEXT, 'Waitinglist count', VALUE_DEFAULT, 0),
                    'reservedcount' => new external_value(PARAM_TEXT, 'Reserved count', VALUE_DEFAULT, 0),
                    'description' => new external_value(PARAM_RAW, 'description', VALUE_DEFAULT, ''),
                    'path' => new external_value(PARAM_TEXT, 'path', VALUE_DEFAULT, ''),
                    'courses' => new external_multiple_structure(
                        new external_single_structure(
                            [
                                'id' => new external_value(PARAM_INT, 'Course ID'),
                                'fullname' => new external_value(PARAM_TEXT, 'Full course name'),
                            ]
                        ), 'List of courses', VALUE_OPTIONAL
                    ),
                    'json' => new external_value(PARAM_RAW, 'json', VALUE_DEFAULT, '{}'),
                ]
            )
        );
    }
}
