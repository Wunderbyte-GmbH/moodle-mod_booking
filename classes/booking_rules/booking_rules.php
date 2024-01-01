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
 * Base class for booking rules information.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\booking_rules;

use mod_booking\output\ruleslist;

/**
 * Class to handle display and management of rules.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_rules {

    /** @var array $rules */
    public $rules = [];

    /**
     * Returns the rendered html for a list of rules.
     *
     * @param int $bookingid
     * @return string
     */
    public static function return_rendered_list_of_saved_rules($bookingid = 0) {
        global $PAGE;

        $rules = self::get_list_of_saved_rules($bookingid);

        $data = new ruleslist($rules);
        $output = $PAGE->get_renderer('booking');
        return $output->render_ruleslist($data);
    }

    /**
     * Returns the rendered html for a list of rules for an instance or globally.
     *
     * @param int $bookingid
     * @return array
     */
    private static function get_list_of_saved_rules($bookingid = 0):array {
        global $DB;

        // If the bookingid is 0, we are dealing with global rules.
        $params = ['bookingid' => $bookingid];

        if (!$rules = $DB->get_records('booking_rules', $params)) {
            $rules = [];
        }

        return $rules;
    }
}
