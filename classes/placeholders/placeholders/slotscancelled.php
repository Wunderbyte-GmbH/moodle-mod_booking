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
 * Placeholder for the cancelled slot(s) carried by a slot-cancelled event.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\placeholders\placeholders;

use mod_booking\local\slotbooking\slot_event_placeholders;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Renders the cancelled slot(s) from a slot-cancelled event payload.
 */
class slotscancelled extends \mod_booking\placeholders\placeholder_base {
    /**
     * Return the formatted cancelled slot list of the triggering event.
     *
     * @param int $cmid course module id
     * @param int $optionid option id
     * @param int $userid user id
     * @param int $installmentnr installment number
     * @param int $duedate due date
     * @param float $price price
     * @param string $text reference to the text being processed
     * @param array $params reference to params
     * @param int $descriptionparam description rendering param
     * @param string $rulejson rule JSON carrying the event payload
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
        return slot_event_placeholders::render($rulejson, ['bookedslots']);
    }

    /**
     * This placeholder is always applicable.
     *
     * @return bool
     */
    public static function is_applicable(): bool {
        return true;
    }
}
