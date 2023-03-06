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
use mod_booking\bo_availability\bo_info;
use mod_booking\booking_bookit;
use mod_booking\booking_option;
use mod_booking\output\bookingoption_description;
use mod_booking\price;
use mod_booking\singleton_service;
use mod_booking\subbookings\subbookings_info;
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
     * @param int $itemid
     * @param int $userid
     * @return array
     */
    public static function load_cartitem(string $area, int $itemid, int $userid = 0): array {

        global $CFG;
        require_once($CFG->dirroot . '/mod/booking/lib.php');

        if ($area === 'option') {

            // First, we need to check if we have the right to actually load the item.
            $settings = singleton_service::get_instance_of_booking_option_settings($itemid);
            $boinfo = new bo_info($settings);
            list($id, $isavailable, $description) = $boinfo->is_available($optionid, $userid, true);

            // The blocking ID has to be the price id. Else, we abort.
            if ($id != BO_COND_PRICEISSET) {
                return ['error' => 'nopermissiontobook'];
            }

            $item = booking_bookit::answer_booking_option($area, $itemid, STATUSPARAM_RESERVED, $userid);

            $cartitem = new cartitem($item['itemid'],
                $item['title'],
                $item['price'],
                $item['currency'],
                'mod_booking',
                'option',
                $item['description'],
                $item['imageurl'],
                $item['canceluntil'],
                $item['coursestarttime'],
                $item['courseendtime']);

            return ['cartitem' => $cartitem];
        } else if (strpos($area, 'subbooking') === 0) {
            // As a subbooking can have different slots, we use the area to provide the subbooking id.
            // The syntax is "subbooking-1" for the subbooking id 1.
            $item = booking_bookit::answer_subbooking_option($area, $itemid, $userid);

            $cartitem = new cartitem($item['itemid'],
                $item['name'],
                $item['price'],
                $item['currency'],
                'mod_booking',
                $area,
                $item['description'],
                $item['imageurl'] ?? '',
                $item['canceluntil'],
                $item['coursestarttime'],
                $item['courseendtime']);

            return ['cartitem' => $cartitem];
        } else {
            return ['error' => 'novalidarea'];
        }

    }

    /**
     * This function unloads item from card. Plugin has to make sure it's available again.
     *
     * @param string $area
     * @param integer $itemid
     * @param integer $userid
     * @return array
     */
    public static function unload_cartitem( string $area, int $itemid, int $userid = 0): array {
        global $CFG;

        require_once($CFG->dirroot . '/mod/booking/lib.php');

        if ($area === 'option') {

            // First, get an array of all depending subbookings.

            $subbookings = subbookings_info::return_array_of_subbookings($itemid);

            booking_bookit::answer_booking_option($area, $itemid, STATUSPARAM_NOTBOOKED, $userid);

            return [
                'success' => 1,
                'itemstounload' => $subbookings,
            ];
        } else if (strpos($area, 'subbooking') === 0) {
            // As a subbooking can have different slots, we use the area to provide the subbooking id.
            // The syntax is "subbooking-1" for the subbooking id 1.
            return self::unload_subbooking($area, $itemid, $userid);
        } else {
            return [
                'success' => 0,
                'itemstounload' => [],
            ];;
        }
    }

    /**
     * Callback function that handles inscripiton after fee was paid.
     * @param string $area
     * @param integer $itemid
     * @param integer $paymentid
     * @param integer $userid
     * @return boolean
     */
    public static function successful_checkout(string $area, int $itemid, int $paymentid, int $userid):bool {
        global $USER, $CFG;

        require_once($CFG->dirroot . '/mod/booking/lib.php');

        if ($area === 'option') {

            $bookingoption = booking_option::create_option_from_optionid($itemid);
            if ($userid == 0) {
                $user = $USER;
            } else {
                $user = singleton_service::get_instance_of_user($userid);
            }
            $bookingoption->user_confirm_response($user);
            return true;

        } else if (strpos($area, 'subbooking') === 0) {
            // As a subbooking can have different slots, we use the area to provide the subbooking id.
            // The syntax is "subbooking-1" for the subbooking id 1.

            // We actually book this subbooking option.
            subbookings_info::save_response($area, $itemid, STATUSPARAM_BOOKED, $userid);

            return true;
        } else {
            return false;
        }
    }


    /**
     * This cancels an already booked course.
     * @param string $area
     * @param integer $itemid
     * @param integer $userid
     * @return boolean
     */
    public static function cancel_purchase(string $area, int $itemid, int $userid = 0): bool {
        global $CFG;

        require_once($CFG->dirroot . '/mod/booking/lib.php');

        if ($area === 'option') {
            booking_bookit::answer_booking_option($area, $itemid, STATUSPARAM_DELETED, $userid);
            return true;

        } else if (strpos($area, 'subbooking') === 0) {
            // As a subbooking can have different slots, we use the area to provide the subbooking id.
            // The syntax is "subbooking-1" for the subbooking id 1.

            // We actually book this subbooking option.
            subbookings_info::save_response($area, $itemid, STATUSPARAM_DELETED, $userid);

            return true;
        } else {
            return false;
        }
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

    /**
     * Function to unload subbooking from cart.
     *
     * @param string $area
     * @param integer $itemid
     * @param integer $userid
     * @return array
     */
    private static function unload_subbooking(string $area, int $itemid, int $userid = 0):array {

        // We unreserve this subbooking option.
        subbookings_info::save_response($area, $itemid, STATUSPARAM_NOTBOOKED, $userid);

        return [
            'success' => 1,
            'itemstounload' => [],
        ];
    }
}
