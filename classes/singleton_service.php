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

use core_user;
use Exception;
use mod_booking\booking;
use mod_booking\booking_answers;
use mod_booking\booking_option;
use mod_booking\booking_option_settings;
use mod_booking\booking_settings;
use stdClass;

/**
 * Singleton Service to improve performance.
 *
 * @package mod_booking
 * @since Moodle 3.11
 * @copyright 2021 Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class singleton_service {
    // Hold the class instance of the singleton service.
    private static $instance = null;

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
     * @return booking_answers
     */
    public static function get_instance_of_booking_answers($settings) {

        $instance = self::get_instance();

        if (isset($instance->bookinganswers[$settings->id])) {
            return $instance->bookinganswers[$settings->id];
        } else {
            $bookinganswers = new booking_answers($settings);
            $instance->bookinganswers[$settings->id] = $bookinganswers;
            return $bookinganswers;
        }
    }

    /**
     * When invalidating the cache, we need to also destroy the booking_answer_object.
     * As we batch handle a lot of users, they always need a "clean" booking answers object.
     *
     * @param integer $optionid
     * @return void
     */
    public static function destroy_booking_answers($optionid) {
        $instance = self::get_instance();

        if (isset($instance->bookinganswers[$optionid])) {
            unset($instance->bookinganswers[$optionid]);
            return true;
        } else {
            return false;
        }
    }

    /**
     * Service to create and return singleton instance of booking by cmid.
     *
     * @param int $cmid
     * @return booking
     */
    public static function get_instance_of_booking_by_cmid(int $cmid) {

        $instance = self::get_instance();

        if (isset($instance->bookingsbycmid[$cmid])) {
            return $instance->bookingsbycmid[$cmid];
        } else {

            // Before instating the new booking, we need to make sure that it already exists.
            try {
                $booking = new booking($cmid);
                $instance->bookingsbycmid[$cmid] = $booking;
                return $booking;
            } catch (Exception $e) {
                return null;
            }
        }
    }

    /**
     * Service to create and return singleton instance of booking by bookingid.
     *
     * @param int $bookingid
     * @return booking
     */
    public static function get_instance_of_booking_by_bookingid(int $bookingid) {

        $instance = self::get_instance();

        if (isset($instance->bookingsbybookingid[$bookingid])) {
            return $instance->bookingsbybookingid[$bookingid];
        } else {
            $cm = get_coursemodule_from_instance('booking', $bookingid);
            $booking = new booking($cm->id);
            $instance->bookingsbybookingid[$bookingid] = $booking;
            return $booking;
        }
    }

    /**
     * Service to create and return singleton instance of booking.
     *
     * @param int $cmid
     * @return booking
     */
    public static function get_instance_of_booking_by_optionid(int $optionid) {

        $instance = self::get_instance();

        $bookingoptionsettings = self::get_instance_of_booking_option_settings($optionid);

        $cm = get_coursemodule_from_instance('booking', $bookingoptionsettings->bookingid);

        if (isset($instance->bookings[$cm->id])) {
            return $instance->bookings[$cm->id];
        } else {
            $booking = new booking($cm->id);
            $instance->bookings[$cm->id] = $booking;
            return $booking;
        }
    }

    /**
     * Service to create and return singleton instance of booking by cmid.
     *
     * @param int $cmid
     * @return booking_settings
     */
    public static function get_instance_of_booking_settings_by_cmid(int $cmid): booking_settings {
        $instance = self::get_instance();

        if (isset($instance->bookingsettingsbycmid[$cmid])) {
            return $instance->bookingsettingsbycmid[$cmid];
        } else {
            $settings = new booking_settings($cmid);
            $instance->bookingsettingsbycmid[$cmid] = $settings;
            return $settings;
        }
    }

    /**
     * Service to create and return singleton instance of booking by bookingid.
     *
     * @param int $bookingid
     * @return booking_settings
     */
    public static function get_instance_of_booking_settings_by_bookingid(int $bookingid): booking_settings {
        $instance = self::get_instance();

        if (isset($instance->bookingsettingsbybookingid[$bookingid])) {
            return $instance->bookingsettingsbybookingid[$bookingid];
        } else {
            $cm = get_coursemodule_from_instance('booking', $bookingid);
            $settings = new booking_settings($cm->id);
            $instance->bookingsettingsbybookingid[$bookingid] = $settings;
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
            try {
                $option = new booking_option($cmid, $optionid);
                $instance->bookingoptions[$optionid] = $option;
                return $option;
            } catch (Exception $e) {
                return null;
            }

        }
    }

    /**
     * Service to create and return singleton instance of booking_option_settings.
     *
     * @param int $optionid
     * @param stdClass $dbrecord
     * @return booking_option_settings
     */
    public static function get_instance_of_booking_option_settings($optionid, stdClass $dbrecord = null) {
        $instance = self::get_instance();

        if (isset($instance->bookingoptionsettings[$optionid])) {
            return $instance->bookingoptionsettings[$optionid];
        } else {
            $settings = new booking_option_settings($optionid, $dbrecord);
            $instance->bookingoptionsettings[$optionid] = $settings;
            return $settings;
        }
    }

    /**
     * Service to create and return singleton instance of Moodle user.
     *
     * @param int $userid
     * @return stdClass
     */
    public static function get_instance_of_user($userid) {
        $instance = self::get_instance();

        if (isset($instance->users[$userid])) {
            return $instance->users[$userid];
        } else {
            $user = core_user::get_user($userid);
            $instance->users[$userid] = $user;
            return $user;
        }
    }

    /**
     * Service to create and return singleton instance of price class.
     *
     * @param int $optionid
     * @return user
     */
    public static function get_instance_of_price($optionid) {
        $instance = self::get_instance();

        if (isset($instance->prices[$optionid])) {
            return $instance->prices[$optionid];
        } else {
            $price = new price($optionid);
            $instance->prices[$optionid] = $price;
            return $price;
        }
    }

    /**
     * Get pricecategory from singleton service.
     * This function does not automatically get the right category but needs the setter function below to be useful.
     *
     * @param string $identifier
     * @return stdClass
     */
    public static function get_price_category($identifier) {
        $instance = self::get_instance();

        if (isset($instance->pricecategory[$identifier])) {
            return $instance->pricecategory[$identifier];
        } else {
            return null;
        }
    }

    /**
     * Set pricecategory to singleton service.
     *
     * @param string $identifier
     * @param stdClass $pricecategory
     * @return bool
     */
    public static function set_price_category($identifier, $pricecategory) {
        $instance = self::get_instance();

        $instance->pricecategory[$identifier] = $pricecategory;
        return true;
    }
}
