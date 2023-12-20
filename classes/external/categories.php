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
 * This class contains a webservice function returns bookings categories.
 *
 * @package    mod_booking
 * @copyright  2022 Georg Maißer <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_booking\external;

use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * Mod booking show sub categories.
 *
 * @param int $catid
 * @param mixed $DB
 * @param int $courseid
 *
 * @return array
 *
 */
function mod_booking_showsubcategories($catid, $DB, $courseid) {
    $returns = [];
    $categories = $DB->get_records('booking_category', ['cid' => $catid]);
    if (count((array) $categories) > 0) {
        foreach ($categories as $category) {
            $cat = [];

            $cat['id'] = $category->id;
            $cat['cid'] = $category->cid;
            $cat['name'] = $category->name;

            $returns[] = $cat;

            $returns = array_merge($returns, mod_booking_showsubcategories($category->id, $DB, $courseid));
        }
    }

    return $returns;
}

/**
 * External Service for return bookings categories.
 *
 * @package   mod_booking
 * @copyright 2022 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Georg Maißer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class categories extends external_api {

    /**
     * Describes the parameters for bookings categories.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_TEXT, 'Course id', VALUE_DEFAULT, ''),
            ]
        );
    }

    /**
     * Webservice for return bookings categories.
     *
     * @param int $courseid
     *
     * @return array
     */
    public static function execute($courseid = '0'): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
        ]);

        $returns = [];

        $categories = $DB->get_records('booking_category', ['course' => $courseid, 'cid' => 0]);

        foreach ($categories as $category) {
            $cat = [];

            $cat['id'] = $category->id;
            $cat['cid'] = $category->cid;
            $cat['name'] = $category->name;

            $returns[] = $cat;

            $subcategories = $DB->get_records('booking_category', ['course' => $courseid, 'cid' => $category->id]);
            if (count((array)$subcategories) < 0) {
                foreach ($subcategories as $subcat) {
                    $cat = [];

                    $cat['id'] = $subcat->id;
                    $cat['cid'] = $subcat->cid;
                    $cat['name'] = $subcat->name;

                    $returns[] = $cat;

                    $returns = array_merge($returns, mod_booking_showsubcategories($subcat->id, $DB, $courseid));
                }
            }
        }

        return $returns;
    }

    /**
     * Returns description of method result value.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure(
                [
                    'id' => new external_value(PARAM_INT, 'Category ID'),
                    'cid' => new external_value(PARAM_INT, 'Subcategory ID'),
                    'name' => new external_value(PARAM_TEXT, 'Category name'),
                ]
            )
        );
    }
}
