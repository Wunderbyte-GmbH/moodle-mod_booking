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
 * Handle fields for booking option.
 *
 * @package mod_booking
 * @copyright 2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\placeholders\placeholders;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Control and manage placeholders for booking instances, options and mails.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Magdalena Holczik
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class numberofinstallment {

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
        int $descriptionparam = MOD_BOOKING_DESCRIPTION_WEBSITE) {

        $n = $installmentnr + 1;
        $value = get_string('numberofinstallmentstring', 'mod_booking', $n);
        return $value;
    }
}
