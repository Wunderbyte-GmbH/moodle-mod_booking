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
 * Control and manage placeholders for booking instances, options and mails.
 *
 * @package mod_booking
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer, Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_booking\placeholders\placeholders;

use mod_booking\option\dates_handler;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Control and manage placeholders for booking instances, options and mails.
 *
 * @package mod_booking
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer, Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class optiondatefromevent {
    /**
     * Function which takes a text, replaces the placeholders...
     * ... and returns the text with the correct values.
     * @param int $cmid
     * @param int $optionid
     * @param int $userid
     * @param int $installmentnr
     * @param int $duedate
     * @param float $price
     * @param string $text
     * @param array $params
     * @param int $descriptionparam
     * @param string $rulejson
     * @return string
     */
    public static function return_value(
        int $cmid = 0,
        int $optionid = 0,
        int $userid = 0,
        int $installmentnr = 0,
        int $duedate = 0,
        float $price = 0,
        string &$text = '',
        array &$params = [],
        int $descriptionparam = MOD_BOOKING_DESCRIPTION_WEBSITE,
        string $rulejson = ''
    ) {
        $value = ''; // Initialize empty value.
        $rulejson = json_decode($rulejson);
        if (
            !empty($rulejson)
            && !empty($rulejson->datafromevent)
        ) {
            $class = $rulejson->datafromevent->eventname;
            $event = $rulejson->datafromevent;
            if (!empty($event->other) && !empty($event->other->optiondateid)) {
                global $DB;
                $optiondateid = $event->other->optiondateid;
                $optiondate = $DB->get_record('booking_optiondates', ['id' => $optiondateid]);
                if (!empty($optiondate)) {
                    // If an optiondate is found, render it using the prettify function.
                    $value = dates_handler::prettify_optiondates_start_end(
                        $optiondate->coursestarttime,
                        $optiondate->courseendtime,
                        current_language(),
                        true
                    );
                }
            }
        }
        return $value;
    }

    /**
     * Function determine if placeholder class should be called at all.
     *
     * @return bool
     *
     */
    public static function is_applicable(): bool {
        return true;
    }
}
