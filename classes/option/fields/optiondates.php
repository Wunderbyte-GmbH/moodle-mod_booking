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
 * Control and manage booking dates.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\option\fields;

use mod_booking\dates;
use mod_booking\option\fields;
use stdClass;

/**
 * Class to handle one property of the booking_option_settings class.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class optiondates extends field_base {

    /**
     * This ID is used for sorting execution.
     * @var int
     */
    public $id = MOD_BOOKING_OPTION_FIELD_OPTIONDATES;

    /**
     * Some fields are saved with the booking option...
     * This is normal behaviour.
     * Some can be saved only post save (when they need the option id).
     * @var int
     */
    public $save = MOD_BOOKING_EXECUTION_POSTSAVE;

    /**
     * This identifies the header under which this particular field should be displayed.
     * @var string
     */
    public $header = MOD_BOOKING_HEADER_DATES;

    /**
     * This function interprets the value from the form and, if useful...
     * ... relays it to the new option class for saving or updating.
     * @param stdClass $formdata
     * @param stdClass $newoption
     * @param mixed $returnvalue
     * @return string // If no warning, empty string.
     */
    public static function prepare_save_field(
        stdClass &$formdata,
        stdClass &$newoption,
        int $updateparam,
        mixed $returnvalue = ''): string {

        // Run through all dates to make sure we don't have an array.
        // We need to transform dates to timestamps.
        list($dates, $highesindex) = dates::get_list_of_submitted_dates((array)$formdata);

        foreach ($dates as $date) {

            if (gettype($date['coursestarttime']) == 'array') {
                $newoption->{'coursestarttime_' . $date['index']} = make_timestamp(...$date['coursestarttime']);
                $newoption->{'courseendtime_' . $date['index']} = make_timestamp(...$date['courseendtime']);
            } else {
                $newoption->{'coursestarttime_' . $date['index']} = $date['coursestarttime'];
                $newoption->{'courseendtime_' . $date['index']} = $date['courseendtime'];
            }
        }

        // We can return a warning message here.
        return '';
    }
}
