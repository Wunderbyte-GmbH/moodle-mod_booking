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

namespace mod_booking;
use mod_booking\booking;
use mod_booking\booking_answers;
use mod_booking\booking_option;
use mod_booking\booking_option_settings;
use mod_booking\booking_settings;

/**
 * Shortcodes for mod booking
 *
 * @package mod_booking
 * @subpackage db
 * @since Moodle 3.11
 * @copyright 2021 Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Singleton Service to

class singleton_service {
    // Hold the class instance of the singleton service.
    private static $instance = null;

    private $bookinganswers = [];

    private $bookings = [];

    private $bookingsettings = [];

    private $bookingoptions = [];

    private $bookingoptionsettings = [];




    // The constructor is private
    // to prevent initiation with outer code.
    private function __construct() {
        // The expensive process (e.g.,db connection) goes here.
    }

    // The object is created from within the class itself
    // only if the class has no instance.
    public static function get_instance() {
        if (self::$instance == null) {
            self::$instance = new singleton_service();
        }

        return self::$instance;
    }

    /**
     * Service to create and return singleton instance of booking answers.
     * @param booking_option_settings $settings
     * @param integer $userid
     * @return booking_answers
     */
    public static function get_instance_of_booking_answers($settings, int $userid = 0) {

        $instance = self::get_instance();

        if (isset($instance->bookinganswers[$settings->id][$userid])) {
            return $instance->bookinganswers[$settings->id][$userid];
        } else {
            $bookinganswers = new booking_answers($settings, $userid);
            $instance->bookinganswers[$settings->id][$userid] = $bookinganswers;
            return $bookinganswers;
        }
    }

    /**
     * Service to create and return singleton instance of booking.
     *
     * @param int $cmid
     * @return booking
     */
    public static function get_instance_of_booking(int $cmid) {

        $instance = self::get_instance();

        if (isset($instance->bookings[$cmid])) {
            return $instance->bookings[$cmid];
        } else {
            $booking = new booking($cmid);
            $instance->bookings[$cmid] = $booking;
            return $booking;
        }
    }

    /**
     * Service to create and return singleton instance of booking.
     *
     * @param int $cmid
     * @return booking_settings
     */
    public static function get_instance_of_booking_settings($cmid) {
        $instance = self::get_instance();

        if (isset($instance->bookingsettings[$cmid])) {
            return $instance->bookingsettings[$cmid];
        } else {
            $settings = new booking_settings($cmid);
            $instance->bookingsettings[$cmid] = $settings;
            return $settings;
        }
    }

    /**
     * Service to create and return singleton instance of booking_option.
     *
     * @param int $cmid
     * @param int $optionid
     * @return booking_option
     */
    public static function get_instance_of_booking_option(int $cmid, int $optionid) {
        $instance = self::get_instance();

        if (isset($instance->bookingoptions[$optionid])) {
            return $instance->bookingoptions[$optionid];
        } else {
            $option = new booking_option($cmid, $optionid);
            $instance->bookingoptions[$optionid] = $option;
            return $option;
        }
    }

    /**
     * Service to create and return singleton instance of booking_option_settings.
     *
     * @param int $optionid
     * @return booking_option_settings
     */
    public static function get_instance_of_booking_option_settings($optionid) {
        $instance = self::get_instance();

        if (isset($instance->bookingoptionsettings[$optionid])) {
            return $instance->bookingoptionsettings[$optionid];
        } else {
            $settings = new booking_option_settings($optionid);
            $instance->bookingoptionsettings[$optionid] = $settings;
            return $settings;
        }
    }

}
