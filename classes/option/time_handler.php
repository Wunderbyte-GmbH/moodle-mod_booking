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
 * Handles if the timeintervalls for inputs.
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\option;


/**
 * Prettify time display and set time.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author David Ala
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class time_handler {
    /**
     * Sets timeintervall according to setting.
     *
     * @return array
     *
     */
    public static function set_timeintervall() {
        return !empty((get_config('booking', 'timeintervalls'))) ? ['step' => 5] : [];
    }
    /**
     * Makes the minutes always to be zero.
     *
     * @param int $timestamp
     * @param bool $nextfullhour
     *
     * @return int
     *
     */
    public static function prettytime(int $timestamp, bool $nextfullhour = true) {
        $timestamp = $nextfullhour ? $timestamp + 3600 : $timestamp;
        $prettytimestamp = make_timestamp(
            (int)date('Y', $timestamp),
            (int)date('n', $timestamp),
            (int)date('j', $timestamp),
            (int)date('H', $timestamp),
            0,
        );
        return $prettytimestamp;
    }
}
