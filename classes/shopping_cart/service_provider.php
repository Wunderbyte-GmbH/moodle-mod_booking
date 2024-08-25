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

use context_system;
use local_shopping_cart\local\entities\cartitem;
use local_shopping_cart\shopping_cart;
use mod_booking\bo_availability\bo_info;
use mod_booking\booking;
use mod_booking\booking_bookit;
use mod_booking\booking_option;
use mod_booking\event\booking_failed;
use mod_booking\semester;
use mod_booking\singleton_service;
use mod_booking\subbookings\subbookings_info;

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
            list($id, $isavailable, $description) = $boinfo->is_available($itemid, $userid, true);

            // The blocking ID has to be the price id.
            // If its already in the cart, we can also just proceed.
            // Else, we abort.
            if ($id != MOD_BOOKING_BO_COND_PRICEISSET
                && $id != MOD_BOOKING_BO_COND_ALREADYRESERVED) {

                if (!has_capability('local/shopping_cart:cashier', context_system::instance())) {
                    return ['error' => 'nopermissiontobook'];
                }
            }

            $item = booking_bookit::answer_booking_option($area, $itemid, MOD_BOOKING_STATUSPARAM_RESERVED, $userid);

            // Initialize.
            $serviceperiodstart = $item['coursestarttime'];
            $serviceperiodend = $item['courseendtime'];

            // If cancellation is dependent on semester start, we also use semester start and end dates for the service period.
            if (get_config('booking', 'canceldependenton') == "semesterstart"
                && !empty($settings->semesterid)) {
                // We switched here from booking settings to option settings.

                if (!empty($settings->semesterid)) {
                    $semester = new semester($settings->semesterid);
                    // Now we override.
                    $serviceperiodstart = $semester->startdate;
                    $serviceperiodend = $semester->enddate;
                }
            } else if (get_config('booking', 'canceldependenton') == "bookingopeningtime"
                || get_config('booking', 'canceldependenton') == "bookingclosingtime") {
                // If cancellation is either dependent on bookingopeningtime or bookingclosingtime...
                // ...the service period may only start at booking registration start (bookingopeningtime).
                $serviceperiodstart = $settings->bookingopeningtime ?? $item['coursestarttime'];
            }

            // Make sure we have a valid cost center.
            $costcenter = $settings->costcenter ?? '';
            if (is_array($costcenter)) {
                $costcenter = reset($costcenter);
            }

            $cartitem = new cartitem(
                $item['itemid'],
                $item['title'],
                $item['price'],
                $item['currency'],
                'mod_booking',
                'option',
                $item['description'],
                $item['imageurl'],
                $item['canceluntil'],
                $serviceperiodstart,
                $serviceperiodend,
                'A',
                0,
                $costcenter
            );

            return ['cartitem' => $cartitem];
        } else if (strpos($area, 'subbooking') === 0) {
            // As a subbooking can have different slots, we use the area to provide the subbooking id.
            // The syntax is "subbooking-1" for the subbooking id 1.
            $item = booking_bookit::answer_subbooking_option($area, $itemid, $userid);

            // Initialize.
            $serviceperiodstart = $item['coursestarttime'];
            $serviceperiodend = $item['courseendtime'];

            // If cancellation is dependent on semester start, we also use semester start and end dates for the service period.
            if (get_config('booking', 'canceldependenton')) {
                $subbooking = subbookings_info::get_subbooking_by_area_and_id($area, $itemid);
                $settings = singleton_service::get_instance_of_booking_option_settings($subbooking->optionid);
                $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($settings->cmid);
                if (!empty($bookingsettings->semesterid)) {
                    $semester = new semester($bookingsettings->semesterid);
                    // Now we override.
                    $serviceperiodstart = $semester->startdate;
                    $serviceperiodend = $semester->enddate;
                }
            }

            // Make sure we have a valid cost center.
            $costcenter = $settings->costcenter ?? '';
            if (is_array($costcenter)) {
                $costcenter = reset($costcenter);
            }

            $cartitem = new cartitem($item['itemid'],
                $item['name'],
                $item['price'],
                $item['currency'],
                'mod_booking',
                $area,
                $item['description'],
                $item['imageurl'] ?? '',
                $item['canceluntil'],
                $serviceperiodstart,
                $serviceperiodend,
                'A',
                0,
                $costcenter,
                null,
                'option_' . $settings->id // This is the form of the settings identifier area_itemid.
            );

            return ['cartitem' => $cartitem];
        } else {
            return ['error' => 'novalidarea'];
        }

    }

    /**
     * This function unloads item from card. Plugin has to make sure it's available again.
     *
     * @param string $area
     * @param int $itemid
     * @param int $userid
     * @return array
     */
    public static function unload_cartitem(string $area, int $itemid, int $userid = 0): array {
        global $CFG;

        require_once($CFG->dirroot . '/mod/booking/lib.php');

        if ($area === 'option') {
            // It might be possible that booking options are already deleted at this point...
            // ... e.g. when called by delete_item_task.
            // That's why we check if the booking option really exists.
            if (!$bookingoption = booking_option::create_option_from_optionid($itemid)) {
                return [
                    'success' => 0,
                    'itemstounload' => [],
                ];
            }

            // First, get an array of all depending subbookings.
            $subbookings = subbookings_info::return_array_of_subbookings($itemid);

            booking_bookit::answer_booking_option($area, $itemid, MOD_BOOKING_STATUSPARAM_NOTBOOKED, $userid);

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
            ];
        }
    }

    /**
     * Callback function that handles inscripiton after fee was paid.
     * @param string $area
     * @param int $itemid
     * @param int $paymentid
     * @param int $userid
     * @return bool
     */
    public static function successful_checkout(string $area, int $itemid, int $paymentid, int $userid): bool {
        global $USER, $CFG;

        require_once($CFG->dirroot . '/mod/booking/lib.php');

        if ($area === 'option') {

            $bookingoption = booking_option::create_option_from_optionid($itemid);
            if ($userid == 0) {
                $user = $USER;
            } else {
                $user = singleton_service::get_instance_of_user($userid);
            }

            // If this returns false, the reason most like is that the reserveration was deleted before.
            // Most likely because the item wasnt reserved anymore.
            if (!$bookingoption->user_confirm_response($user)) {
                // So, we noticed that the item was not reserved anymore.
                // What we can do is to try to book anyways.

                // If the booking is not successful, we return false and trigger the payment unsuccessful event.
                // This will happen, when the booking is full.
                $user = singleton_service::get_instance_of_user($userid);
                if (!$bookingoption->user_submit_response($user, 0, 0, 0, MOD_BOOKING_VERIFIED)) {

                    // Log cancellation of user.
                    $event = booking_failed::create([
                        'objectid' => $itemid,
                        'context' => \context_module::instance($bookingoption->cmid),
                        'userid' => $USER->id, // The user who did cancel.
                        'relateduserid' => $userid, // Affected user - the user who was cancelled.
                    ]);
                    $event->trigger(); // This will trigger the observer function and delete calendar events.

                    return false;
                }

            }
            return true;

        } else if (strpos($area, 'subbooking') === 0) {
            // As a subbooking can have different slots, we use the area to provide the subbooking id.
            // The syntax is "subbooking-1" for the subbooking id 1.

            // We actually book this subbooking option.
            subbookings_info::save_response($area, $itemid, MOD_BOOKING_STATUSPARAM_BOOKED, $userid);

            return true;
        } else {
            return false;
        }
    }


    /**
     * This cancels an already booked course.
     * @param string $area
     * @param int $itemid
     * @param int $userid
     * @return bool
     */
    public static function cancel_purchase(string $area, int $itemid, int $userid = 0): bool {
        global $CFG;

        require_once($CFG->dirroot . '/mod/booking/lib.php');

        if ($area === 'option') {
            booking_bookit::answer_booking_option($area, $itemid, MOD_BOOKING_STATUSPARAM_DELETED, $userid);
            return true;

        } else if (strpos($area, 'subbooking') === 0) {
            // As a subbooking can have different slots, we use the area to provide the subbooking id.
            // The syntax is "subbooking-1" for the subbooking id 1.

            // We actually book this subbooking option.
            subbookings_info::save_response($area, $itemid, MOD_BOOKING_STATUSPARAM_DELETED, $userid);

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
     * Callback function to check if an item can be cancelled.
     *
     * @param string $area
     * @param int $itemid An identifier that is known to the plugin
     *
     * @return bool true if cancelling is allowed, else false
     */
    public static function allowed_to_cancel(string $area, int $itemid): bool {
        $allowedtocancel = true;
        // Currently, we only check this for options.
        // Maybe we will need additional areas in the future.
        if ($area == 'option') {

            if (empty($itemid)) {
                return false;
            }

            // Whenever we don't find the right optionid or the booking id, we return false.
            if (!$optionsettings = singleton_service::get_instance_of_booking_option_settings($itemid)) {
                return false;
            }

            if (empty($optionsettings->bookingid)) {
                return false;
            }

            $bookingid = $optionsettings->bookingid;

            // Check if cancelling was disabled for the booking option or for the whole booking instance.
            if (booking_option::get_value_of_json_by_key($itemid, 'disablecancel') ||
                booking::get_value_of_json_by_key($bookingid, 'disablecancel')) {
                $allowedtocancel = false;
            }
            /* IMPORTANT: We had to remove this check as it has to be possible to override this
            by setting a canceluntil date in shopping_cart_history table directly. */
            // Check if the option has its own canceluntil date and if it has already passed.
            // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
            /* $now = time();
            $canceluntil = booking_option::get_value_of_json_by_key($itemid, 'canceluntil');
            if (!empty($canceluntil) && $now > $canceluntil) {
                $allowedtocancel = false;
            } */
        }
        return $allowedtocancel;
    }

    /**
     * Function to unload subbooking from cart.
     *
     * @param string $area
     * @param int $itemid
     * @param int $userid
     * @return array
     */
    private static function unload_subbooking(string $area, int $itemid, int $userid = 0): array {

        // We unreserve this subbooking option.
        subbookings_info::save_response($area, $itemid, MOD_BOOKING_STATUSPARAM_NOTBOOKED, $userid);

        return [
            'success' => 1,
            'itemstounload' => [],
        ];
    }

    /**
     * Callback to check if adding item to cart is allowed.
     *
     * @param string $area
     * @param int $itemid
     * @param int $userid
     * @return array
     */
    public static function allow_add_item_to_cart(string $area, int $itemid,
        int $userid = 0): array {

        if ($area == "option") {

            booking_option::purge_cache_for_answers($itemid);

            $settings = singleton_service::get_instance_of_booking_option_settings($itemid);

            $boinfo = new bo_info($settings);
            // There are two cases where we can actually book.
            // We call thefunction with hadblock set to true.
            // This means that we only get those blocks that actually should prevent booking.
            list($id, $isavailable, $description) = $boinfo->is_available($itemid, $userid, true, true);

            // These conditions are allowed, so we need a check.
            $allowedconditions = [
                MOD_BOOKING_BO_COND_PRICEISSET,
                MOD_BOOKING_BO_COND_ALREADYRESERVED,
                MOD_BOOKING_BO_COND_BOOKITBUTTON,
                MOD_BOOKING_BO_COND_ONWAITINGLIST,
            ];

            if ($id > 0 && !in_array($id, $allowedconditions)) {
                switch($id) {
                    case MOD_BOOKING_BO_COND_FULLYBOOKED:
                        return [
                            'allow' => false,
                            'info' => 'fullybooked',
                            'itemname' => $settings->get_title_with_prefix() ?? '',
                        ];
                    case MOD_BOOKING_BO_COND_ALREADYBOOKED:
                        return [
                            'allow' => false,
                            'info' => 'alreadybooked',
                            'itemname' => $settings->get_title_with_prefix() ?? '',
                        ];
                    default:
                        return [
                            'allow' => false,
                            'info' => 'cannotbebooked',
                            'itemname' => $settings->get_title_with_prefix() ?? '',
                        ];
                }
            }

            // TODO: Dont call allow_add_item_to_cart when NOT adding to cart!
            if (
                !(
                    $id === MOD_BOOKING_BO_COND_BOOKITBUTTON
                    || $id === MOD_BOOKING_BO_COND_ASKFORCONFIRMATION
                )
            ) {
                $user = singleton_service::get_instance_of_user($userid);
                $item = $settings->return_booking_option_information($user);
                $cartitem = new cartitem($itemid,
                    $item['title'],
                    $item['price'],
                    $item['currency'],
                    'mod_booking',
                    'option',
                    $item['description'],
                    $item['imageurl'],
                    $item['canceluntil'],
                    $item['coursestarttime'],
                    $item['courseendtime'],
                    'A',
                    0,
                    $item['costcenter']
                );
                return $cartitem->as_array() ?? [];
            }
        }
        return [
            'allow' => true,
            'info' => 'notabookingoption',
            'itemname' => '',
        ];
    }
}
