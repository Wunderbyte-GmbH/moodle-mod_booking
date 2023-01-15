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
 * Shopping_cart subsystem callback implementation for mod_booking.
 *
 * @package mod_booking
 * @copyright  2022 Georg Maißer <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\shopping_cart;

use context_module;
use Exception;
use local_shopping_cart\local\entities\cartitem;
use mod_booking\booking_option;
use mod_booking\output\bookingoption_description;
use mod_booking\price;
use mod_booking\singleton_service;
use moodle_exception;

/**
 * Shopping_cart subsystem callback implementation for mod_booking.
 *
 * @copyright  22022 Georg Maißer <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class service_provider implements \local_shopping_cart\local\callback\service_provider {

    /**
     * Callback function that returns the costs and the accountid
     * for the course that $userid of the buying user.
     *
     * @param string $area
     * @param int $optionid
     * @param int $userid
     * @return array
     */
    public static function load_cartitem(string $area, int $optionid, int $userid = 0): array {
        global $DB, $USER, $PAGE;

        $bookingoption = booking_option::create_option_from_optionid($optionid);

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        // Make sure that we only buy from instance the user has access to.
        // This is just fraud prevention and can not happen ordinarily.
        $cm = get_coursemodule_from_instance('booking', $bookingoption->bookingid);

        // TODO: Find out if the executing user has the right to access this instance.
        // This can lead to problems, rights should be checked further up.
        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /* $context = context_module::instance($cm->id);
        if (!has_capability('mod/booking:choose', $context)) {
            return null;
        } */

        $user = price::return_user_to_buy_for($userid);

        // In booking, we always buy a booking option. Therefore, we have to first find out its price.
        if (!$price = price::get_price('option', $optionid, $user)) {
            throw new moodle_exception('invalidpricecategoryforuser', 'mod_booking', '', '', "Price was empty.
                This was most probably due to invalid price cateogry configuration for the given user $user->id");
        }

        // Now we reserve the place for the user.
        if (!$bookingoption->user_submit_response($user, 0, 0, true)) {
            return [];
        }

        // We need to register this action as a booking answer, where we only reserve, not actually book.

        $user = singleton_service::get_instance_of_user($userid);
        $booking = singleton_service::get_instance_of_booking_by_optionid($optionid);

        if (!isset($PAGE->context)) {
            $PAGE->set_context(context_module::instance($booking->cmid));
        }

        $output = $PAGE->get_renderer('mod_booking');
        $data = new bookingoption_description($optionid, null, DESCRIPTION_WEBSITE, false, null, $user);

        $description = $output->render_bookingoption_description_cartitem($data);

        $optiontitle = $bookingoption->option->text;
        if (!empty($bookingoption->option->titleprefix)) {
            $optiontitle = $bookingoption->option->titleprefix . ' - ' . $optiontitle;
        }

        // The date from which to calculate cancel-date is coursestarttime.
        $coursestarttime = $settings->coursestarttime;

        $allowupdatedays = $booking->settings->allowupdatedays;
        if (!empty($allowupdatedays) && !empty($coursestarttime)) {
            // Different string depending on plus or minus.
            if ($allowupdatedays >= 0) {
                $datestring = " - $allowupdatedays days";
            } else {
                $allowupdatedays = abs($allowupdatedays);
                $datestring = " + $allowupdatedays days";
            }
            $canceluntil = strtotime($datestring, $coursestarttime);
        } else {
            $canceluntil = null;
        }

        $cartitem = new cartitem($optionid,
            $optiontitle,
            $price['price'],
            $price['currency'],
            'mod_booking',
            'option',
            $description,
            $settings->imageurl ?? '',
            $canceluntil,
            $settings->coursestarttime ?? null,
            $settings->courseendtime ?? null);

        return ['cartitem' => $cartitem];
    }

    /**
     * This function unloads item from card. Plugin has to make sure it's available again.
     *
     * @param string $area
     * @param integer $itemid
     * @param integer $userid
     * @return boolean
     */
    public static function unload_cartitem( string $area, int $optionid, int $userid = 0): bool {
        global $USER;

        $bookingoption = booking_option::create_option_from_optionid($optionid);
        $userid = $userid == 0 ? $USER->id : $userid;
        if (!$bookingoption) {
            // This might occure, when the instance was deleted. As we don't want to continue to try, we return true.
            return true;
        }
        try {
            $bookingoption->user_delete_response($userid, true);
        } catch (Exception $e) {
            // If we have a problem with unloading, we just return false.
            // TODO: Set to false.
            return true;
        }

        return true;
    }

    /**
     * Callback function that handles inscripiton after fee was paid.
     * @param string $area
     * @param integer $optionid
     * @param integer $paymentid
     * @param integer $userid
     * @return boolean
     */
    public static function successful_checkout(string $area, int $optionid, int $paymentid, int $userid):bool {
        global $USER;

        $bookingoption = booking_option::create_option_from_optionid($optionid);

        if ($userid == 0) {
            $user = $USER;
        } else {
            $user = singleton_service::get_instance_of_user($userid);
        }

        $bookingoption->user_confirm_response($user);

        return true;
    }


    /**
     * This cancels an already booked course.
     * @param string $area
     * @param integer $itemid
     * @param integer $userid
     * @return boolean
     */
    public static function cancel_purchase(string $area, int $optionid, int $userid = 0): bool {

        global $USER;

        $bookingoption = booking_option::create_option_from_optionid($optionid);

        if ($userid == 0) {
            $user = $USER;
        } else {
            $user = singleton_service::get_instance_of_user($userid);
        }

        $bookingoption->user_delete_response($user->id);

        return true;
    }

    /**
     * Callback function to give back a float value how much of the initially bought item is already consumed.
     * 1 stands for everything, 0.5 for 50%.
     * This is used in cancellation, to know how much of the initial price is returned.
     *
     * @param string $area
     * @param int $itemid An identifier that is known to the plugin
     * @param int $userid
     *
     * @return float
     */
    public static function quota_consumed(string $area, int $itemid, int $userid = 0): float {

        // This function only tests for how much time has already passed.
        // Therefore, we don't need to pass on the userid.
        if ($area == 'option') {
            $consumedquota = booking_option::get_consumed_quota($itemid);
        } else {
            $consumedquota = 0;
        }

        return $consumedquota;
    }
}
