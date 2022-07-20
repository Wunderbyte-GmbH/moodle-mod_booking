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
 * Base class for a single booking option availability condition.
 *
 * All bo condition types must extend this class.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

 namespace mod_booking\bo_availability;

use mod_booking\booking_option_settings;
use mod_booking\singleton_service;

/**
 * Base class for a single bo availability condition.
 *
 * All bo condition types must extend this class.
 *
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class max_number_of_bookings implements bo_condition {

    /**
     * Determines whether a particular item is currently available
     * according to this availability condition.
     * @param booking_option_settings $settings Item we're checking
     * @param int $userid User ID to check availability for
     * @param bool $not Set true if we are inverting the condition
     * @return bool True if available
     */
    public static function is_available(booking_option_settings $settings, $userid, $not = false):bool {

        global $Db;

        $isavailable = false;

        $booking = singleton_service::get_instance_of_booking_by_optionid($settings->id);

        if (empty($booking->maxperuser)) {
            $isavailable = true;
        } else {
            $bookinganswer = singleton_service::get_instance_of_booking_answers($settings, $userid);
        }

        return $isavailable;
    }

    /**
     * Obtains a string describing this restriction (whether or not
     * it actually applies). Used to obtain information that is displayed to
     * students if the activity is not available to them, and for staff to see
     * what conditions are.
     *
     * The $full parameter can be used to distinguish between 'staff' cases
     * (when displaying all information about the activity) and 'student' cases
     * (when displaying only conditions they don't meet).
     *
     * @param bool $full Set true if this is the 'full information' view
     * @param booking_option_settings $settings Item we're checking
     * @param int $userid User ID to check availability for
     * @param bool $not Set true if we are inverting the condition
     * @return string Information string (for admin) about all restrictions on
     *   this item
     */
    public static function get_description($full = false, booking_option_settings $settings, $userid = null, $not = false):string {

        $description = '';

        if (self::is_available($settings, $not, $userid, $not)) {
            $description = $full ? get_string('bo_cond_normal_booking_time_available', $settings) :
                get_string('bo_condition_normal_booking_time_full_available', $settings);
        } else {
            $description = $full ? get_string('bo_cond_normal_booking_time_not_available', $settings) :
                get_string('bo_condition_normal_booking_time_full_not_available', $settings);
        }

        return $description;
    }
}
