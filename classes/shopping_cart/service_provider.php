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
use mod_booking\bo_availability\conditions\bookitbutton;
use mod_booking\booking;
use mod_booking\booking_answers\booking_answers;
use mod_booking\booking_bookit;
use mod_booking\booking_option;
use mod_booking\enrollink;
use mod_booking\event\booking_failed;
use mod_booking\local\slotbooking\slot_answer;
use mod_booking\local\slotbooking\slot_move_store;
use mod_booking\local\slotbooking\slot_mover;
use mod_booking\local\slotbooking\slot_price;
use mod_booking\option\dates_handler;
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

        global $CFG, $USER;
        require_once($CFG->dirroot . '/mod/booking/lib.php');

        $effectiveuserid = empty($userid) ? (int)$USER->id : $userid;

        if ($area === 'option') {
            // First, we need to check if we have the right to actually load the item.
            $settings = singleton_service::get_instance_of_booking_option_settings($itemid);
            $ignoredconditionids = self::get_cart_book_intent_ignored_condition_ids($settings, $userid);

            $boinfo = new bo_info($settings);
            [$id, $isavailable, $description] = $boinfo->is_available(
                $itemid,
                $userid,
                true,
                false,
                $ignoredconditionids
            );

            // The blocking ID has to be the price id.
            // If its already in the cart, we can also just proceed.
            // Else, we abort.
            if (
                $id != MOD_BOOKING_BO_COND_PRICEISSET
                && $id != MOD_BOOKING_BO_COND_ALREADYRESERVED
            ) {
                if (!has_capability('local/shopping_cart:cashier', context_system::instance())) {
                    return ['error' => 'nopermissiontobook'];
                }
            }

            $item = booking_bookit::answer_booking_option($area, $itemid, MOD_BOOKING_STATUSPARAM_RESERVED, $userid);

            if (empty($item)) {
                return ['error' => 'novalidarea'];
            }
            // Initialize.
            $serviceperiodstart = $item['coursestarttime'];
            $serviceperiodend = $item['courseendtime'];

            if ($settings->type == MOD_BOOKING_OPTIONTYPE_SELFLEARNINGCOURSE) {
                // For self learning courses, we use the booking time as service period.
                $serviceperiodstart = time();
                $serviceperiodend = time() + $settings->duration;
            } else if (
                // If cancellation is dependent on semester start...
                // We also use semester start and end dates for the service period.
                get_config('booking', 'canceldependenton') == "semesterstart"
                && !empty($settings->semesterid)
            ) {
                // We switched here from booking settings to option settings.

                if (!empty($settings->semesterid)) {
                    $semester = new semester($settings->semesterid);
                    // Now we override.
                    $serviceperiodstart = $semester->startdate;
                    $serviceperiodend = $semester->enddate;
                }
            } else if (
                get_config('booking', 'canceldependenton') == "bookingopeningtime"
                || get_config('booking', 'canceldependenton') == "bookingclosingtime"
            ) {
                // If cancellation is either dependent on bookingopeningtime or bookingclosingtime...
                // ...the service period may only start at booking registration start (bookingopeningtime).
                $serviceperiodstart = $settings->bookingopeningtime ?? $item['coursestarttime'];
            }

            // Make sure we have a valid cost center.
            $costcenter = $settings->costcenter ?? '';
            if (is_array($costcenter)) {
                $costcenter = reset($costcenter);
            }

            $ba = singleton_service::get_instance_of_booking_answers($settings);
            $users = $ba->get_usersreserved();
            $answer = $users[$effectiveuserid] ?? [];
            $bookinginformation = $ba->return_all_booking_information($effectiveuserid);
            $nritems = enrollink::return_number_of_booked_licenses_from_booking_answer((object)$answer);

            $numberofitems = empty($nritems) ? 1 : $nritems;
            $multipliable = empty($nritems) ? 0 : 1;

            $item = self::apply_reserved_slotbooking_price($settings, $item, $answer);
            $description = self::build_cartitem_description(
                $settings,
                $item,
                $answer,
                $bookinginformation,
                $numberofitems
            );

            $cartitem = new cartitem(
                $item['itemid'],
                $item['title'],
                $item['price'],
                $item['currency'],
                'mod_booking',
                'option',
                $description,
                $item['imageurl'],
                $item['canceluntil'],
                $serviceperiodstart,
                $serviceperiodend,
                'A',
                0,
                $costcenter,
                null,
                null,
                $numberofitems,
                $multipliable
            );

            return ['cartitem' => $cartitem];
        } else if (strpos($area, 'subbooking') === 0) {
            // As a subbooking can have different slots, we use the area to provide the subbooking id.
            // The syntax is "subbooking-1" for the subbooking id 1.
            $item = booking_bookit::answer_subbooking_option($area, $itemid, MOD_BOOKING_STATUSPARAM_RESERVED, $userid);

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

            $cartitem = new cartitem(
                $item['itemid'],
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
        } else if ($area === 'moveslot') {
            // Slot move with a price difference (upgrade). The itemid is the OPTION id, so the
            // shopping_cart ledger is option-traceable; the pending move (which holds the target
            // slots and the fixed price delta) is resolved from (optionid, user). The target slot
            // is already locked via the capacity counter (slot_availability counts the hold).
            $move = slot_move_store::get_pending_for_option_user($itemid, $effectiveuserid);
            if (empty($move)) {
                return ['error' => 'novalidarea'];
            }

            $settings = singleton_service::get_instance_of_booking_option_settings($itemid);
            if (empty($settings)) {
                return ['error' => 'novalidarea'];
            }

            $newslots = slot_move_store::decode_slots($move->newslots);
            if (empty($newslots)) {
                return ['error' => 'novalidarea'];
            }
            $serviceperiodstart = (int)$newslots[0]['start'];
            $serviceperiodend = (int)$newslots[count($newslots) - 1]['end'];

            // Currency comes from the slot price context of the option.
            $pricedata = slot_price::calculate_slot_price_data(
                (int)$move->optionid,
                $serviceperiodstart,
                $serviceperiodend,
                $effectiveuserid
            );
            $currency = $pricedata['currency'] ?? (get_config('local_shopping_cart', 'globalcurrency') ?: 'EUR');

            $costcenter = $settings->costcenter ?? '';
            if (is_array($costcenter)) {
                $costcenter = reset($costcenter);
            }

            $cartitem = new cartitem(
                $itemid,
                get_string('slotmove_cartitem_title', 'mod_booking', $settings->get_title_with_prefix()),
                round((float)$move->pricedelta, 2),
                $currency,
                'mod_booking',
                'moveslot',
                self::build_moveslot_description($move),
                '',
                $settings->canceluntil ?? 0,
                $serviceperiodstart,
                $serviceperiodend,
                'A',
                0,
                $costcenter
            );

            return ['cartitem' => $cartitem];
        } else {
            return ['error' => 'novalidarea'];
        }
    }

    /**
     * Human readable "given up -> taken" description for a slot move cart item / receipt.
     *
     * Only the slots that actually changed are shown: reselected (kept) slots are excluded so a
     * partial move (e.g. keep 2 slots, swap 1) reads as "slot A -> slot B", not the whole booking.
     *
     * @param \stdClass $move booking_slot_moves row
     * @return string
     */
    private static function build_moveslot_description(\stdClass $move): string {
        // Use the booking date formatter so a same-day range collapses to one date plus the
        // time span, e.g. "Wednesday, 24 June 2026, 10:00 AM - 11:00 AM".
        $format = static function (array $slots): string {
            $parts = [];
            foreach ($slots as $slot) {
                $parts[] = dates_handler::prettify_optiondates_start_end(
                    (int)$slot['start'],
                    (int)$slot['end'],
                    current_language()
                );
            }
            return implode(', ', $parts);
        };
        $keyof = static fn(array $slot): string => $slot['start'] . ':' . $slot['end'];

        $oldslots = slot_move_store::decode_slots($move->oldslots);
        $newslots = slot_move_store::decode_slots($move->newslots);
        $oldkeys = array_map($keyof, $oldslots);
        $newkeys = array_map($keyof, $newslots);

        // Given-up = old minus kept; taken = new minus kept.
        $removed = array_values(array_filter($oldslots, static fn(array $s): bool => !in_array($keyof($s), $newkeys, true)));
        $added = array_values(array_filter($newslots, static fn(array $s): bool => !in_array($keyof($s), $oldkeys, true)));

        return get_string('slotmove_cartitem_description', 'mod_booking', (object)[
            'old' => $format($removed),
            'new' => $format($added),
        ]);
    }

    /**
     * Override cart item price from reserved slotbooking answer data when available.
     *
     * @param object $settings
     * @param array $item
     * @param mixed $answer
     * @return array
     */
    private static function apply_reserved_slotbooking_price(object $settings, array $item, $answer): array {
        if ((int)($settings->type ?? 0) !== MOD_BOOKING_OPTIONTYPE_SLOTBOOKING) {
            return $item;
        }

        if (empty($answer)) {
            return $item;
        }

        $slotdata = slot_answer::get_slot_data((object)$answer);
        if (!is_array($slotdata) || !isset($slotdata['price']) || !is_numeric($slotdata['price'])) {
            return $item;
        }

        $item['price'] = round((float)$slotdata['price'], 2);
        return $item;
    }

    /**
     * Build cartitem description with resolved placeholders and slot booking context.
     *
     * @param object $settings
     * @param array $item
     * @param mixed $answer
     * @param array $bookinginformation
     * @param int $numberofitems
     * @return string
     */
    private static function build_cartitem_description(
        object $settings,
        array $item,
        $answer,
        array $bookinginformation,
        int $numberofitems
    ): string {
        $description = (string)($item['description'] ?? '');
        $slotdata = slot_answer::get_slot_data((object)$answer) ?? [];
        $flatbookinginformation = self::flatten_booking_information($bookinginformation);

        $modifieddescription = get_config('booking', 'sccartdescription');
        if (!empty($modifieddescription)) {
            $replacements = [];
            $placeholdervalues = self::build_slotbooking_placeholder_values(
                $item,
                $slotdata,
                $flatbookinginformation,
                $numberofitems,
                !empty($settings->useprice)
            );

            preg_match_all('/\{(.*?)\}/', $modifieddescription, $matches);
            foreach ($matches[1] as $match) {
                if (array_key_exists($match, $placeholdervalues)) {
                    $value = $placeholdervalues[$match];
                } else {
                    $value = $settings->$match ?? get_string('invalidplaceholder', 'mod_booking');
                }
                if (is_numeric($value)) {
                    $value = userdate(time(), get_string('strftimedaydate', 'core_langconfig'));
                }
                $replacements['{' . $match . '}'] = (string)$value;
            }

            $description = str_replace(array_keys($replacements), array_values($replacements), $modifieddescription);
        }

        if (empty($settings->useprice)) {
            $description = self::remove_price_information_from_description($description);
        }

        return self::append_slotbooking_context_to_description(
            $settings,
            $description,
            $slotdata,
            $flatbookinginformation,
            $numberofitems,
            (int)($answer->userid ?? 0)
        );
    }

    /**
     * Flatten wrapped booking information (e.g. iambooked/iamreserved/notbooked key).
     *
     * @param array $bookinginformation
     * @return array
     */
    private static function flatten_booking_information(array $bookinginformation): array {
        if (empty($bookinginformation)) {
            return [];
        }

        $rootkeys = ['iambooked', 'iamreserved', 'onwaitinglist', 'notbooked'];
        foreach ($rootkeys as $rootkey) {
            if (isset($bookinginformation[$rootkey]) && is_array($bookinginformation[$rootkey])) {
                return $bookinginformation[$rootkey];
            }
        }

        return $bookinginformation;
    }

    /**
     * Build placeholder map for slot booking related values.
     *
     * @param array $item
     * @param array $slotdata
     * @param array $bookinginformation
     * @param int $numberofitems
     * @param bool $useprice
     * @return array<string, string|int|float>
     */
    private static function build_slotbooking_placeholder_values(
        array $item,
        array $slotdata,
        array $bookinginformation,
        int $numberofitems,
        bool $useprice
    ): array {
        $slots = is_array($slotdata['slots'] ?? null) ? $slotdata['slots'] : [];
        $firstslot = !empty($slots) ? reset($slots) : [];
        $lastslot = !empty($slots) ? end($slots) : [];

        $slotstart = !empty($firstslot['start']) ? (int)$firstslot['start'] : 0;
        $slotend = !empty($lastslot['end']) ? (int)$lastslot['end'] : 0;

        $slotlines = [];
        foreach ($slots as $slot) {
            if (empty($slot['start']) || empty($slot['end'])) {
                continue;
            }

            $slotlines[] = dates_handler::prettify_optiondates_start_end(
                (int)$slot['start'],
                (int)$slot['end'],
                current_language()
            );
        }

        return [
            'slot_num_slots' => (int)($slotdata['num_slots'] ?? count($slots)),
            'slot_price' => $useprice ? (float)($slotdata['price'] ?? ($item['price'] ?? 0)) : '',
            'slot_start' => $slotstart > 0 ? userdate($slotstart, get_string('strftimedatetime', 'langconfig')) : '',
            'slot_end' => $slotend > 0 ? userdate($slotend, get_string('strftimedatetime', 'langconfig')) : '',
            'slot_dates' => implode(', ', $slotlines),
            'booking_booked' => (int)($bookinginformation['booked'] ?? 0),
            'booking_waiting' => (int)($bookinginformation['waiting'] ?? 0),
            'booking_reserved' => (int)($bookinginformation['reserved'] ?? 0),
            'booking_freeonlist' => (int)($bookinginformation['freeonlist'] ?? 0),
            'booking_fullybooked' => !empty($bookinginformation['fullybooked']) ? '1' : '0',
            'numberofitems' => $numberofitems,
        ];
    }

    /**
     * Remove rendered price fragments from cart description.
     *
     * @param string $description
     * @return string
     */
    private static function remove_price_information_from_description(string $description): string {
        return preg_replace('/<div class="bo_price">.*?<\/div>/si', '', $description) ?? $description;
    }

    /**
     * Append selected slot details and booking context to description for slot booking options.
     *
     * @param object $settings
     * @param string $description
     * @param array $slotdata
     * @param array $bookinginformation
     * @param int $numberofitems
     * @param int $userid booking owner (for per-slot price resolution)
     * @return string
     */
    private static function append_slotbooking_context_to_description(
        object $settings,
        string $description,
        array $slotdata,
        array $bookinginformation,
        int $numberofitems,
        int $userid = 0
    ): string {
        if ((int)($settings->type ?? 0) !== MOD_BOOKING_OPTIONTYPE_SLOTBOOKING) {
            return $description;
        }

        $slots = is_array($slotdata['slots'] ?? null) ? $slotdata['slots'] : [];
        if (empty($slots)) {
            return $description;
        }

        // One line per slot: a same-day-collapsed date range plus the slot price (no tax
        // breakdown — tax handling may change and would make this fragile). The per-slot prices
        // sum to the cart item total, so mixed-price slot bookings stay transparent in one item.
        $optionid = (int)$settings->id;
        $slotlines = [];
        foreach ($slots as $slot) {
            if (empty($slot['start']) || empty($slot['end'])) {
                continue;
            }

            $line = dates_handler::prettify_optiondates_start_end(
                (int)$slot['start'],
                (int)$slot['end'],
                current_language()
            );

            if (!empty($settings->useprice) && $userid > 0) {
                $pricedata = slot_price::calculate_slot_price_data(
                    $optionid,
                    (int)$slot['start'],
                    (int)$slot['end'],
                    $userid
                );
                if (isset($pricedata['price']) && is_numeric($pricedata['price'])) {
                    $line .= ': ' . format_float((float)$pricedata['price'], 2) . ' ' . ($pricedata['currency'] ?? '');
                }
            }

            $slotlines[] = $line;
        }

        if (empty($slotlines)) {
            return $description;
        }

        $slotcount = (int)($slotdata['num_slots'] ?? count($slotlines));
        $visiblecontext = [];
        if ($slotcount > 1) {
            $visiblecontext[] = 'Anzahl der Slots: ' . $slotcount;
        }

        $contextpayload = [
            'slot' => $slotdata,
            'booking' => $bookinginformation,
            'numberofitems' => $numberofitems,
        ];

        $payloadjson = json_encode($contextpayload);
        $payloadnode = '';
        if (is_string($payloadjson)) {
            $payloadnode = '<div class="d-none booking-cart-context" data-booking-context="' .
                s($payloadjson) . '"></div>';
        }

        $contexthtml = '';
        if (!empty($visiblecontext)) {
            $contexthtml = '<br>' . implode('<br>', array_map('s', $visiblecontext));
        }

        $slotdetailsnode = '<div class="booking-cart-slot-details"><strong>' .
            s(get_string('slot_calendar_slots_header', 'mod_booking')) . ':</strong><br>' .
            implode('<br>', array_map('s', $slotlines)) .
            $contexthtml .
            '</div>';

        return $description . $slotdetailsnode . $payloadnode;
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
        global $CFG, $USER;

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
        } else if ($area === 'moveslot') {
            // Abort / expiry of a held slot move (itemid = option id): cancel the pending move so
            // the target slot is released. The booked answer was never touched.
            $move = slot_move_store::get_pending_for_option_user($itemid, empty($userid) ? (int)$USER->id : $userid);
            if (!empty($move)) {
                slot_move_store::cancel((int)$move->id);
            }
            return [
                'success' => 1,
                'itemstounload' => [],
            ];
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
        } else if ($area === 'moveslot') {
            // The upgrade was paid (itemid = option id): resolve the held move for this option +
            // user and commit it onto the booking answer (the single UPDATE through the shared
            // move core). If nothing is pending or the target became unavailable, report failure.
            $move = slot_move_store::get_pending_for_option_user($itemid, empty($userid) ? (int)$USER->id : $userid);
            if (empty($move)) {
                return false;
            }
            try {
                slot_mover::commit_pending_move((int)$move->id);
            } catch (\moodle_exception $e) {
                debugging('Slot move commit failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
                return false;
            }
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
        global $CFG, $USER;

        require_once($CFG->dirroot . '/mod/booking/lib.php');

        if ($area === 'option') {
            booking_bookit::answer_booking_option(
                $area,
                $itemid,
                MOD_BOOKING_STATUSPARAM_DELETED,
                $userid,
                true
            );
            return true;
        } else if (strpos($area, 'subbooking') === 0) {
            // As a subbooking can have different slots, we use the area to provide the subbooking id.
            // The syntax is "subbooking-1" for the subbooking id 1.

            // We actually book this subbooking option.
            subbookings_info::save_response($area, $itemid, MOD_BOOKING_STATUSPARAM_DELETED, $userid);

            return true;
        } else if ($area === 'moveslot') {
            // Cancelling the move line (itemid = option id) just voids the pending move record;
            // the booking itself is cancelled through the 'option' area.
            $move = slot_move_store::get_pending_for_option_user($itemid, empty($userid) ? (int)$USER->id : $userid);
            if (!empty($move)) {
                slot_move_store::cancel((int)$move->id);
            }
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
            if (
                booking_option::get_value_of_json_by_key($itemid, 'disablecancel') ||
                booking::get_value_of_json_by_key($bookingid, 'disablecancel')
            ) {
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
     * Return ignored condition ids for cart checks in book-again intent only.
     *
     * @param object $settings
     * @param int $userid
     * @return int[]
     */
    private static function get_cart_book_intent_ignored_condition_ids(object $settings, int $userid): array {
        global $USER;

        if (empty($settings->jsonobject->multiplebookings)) {
            return [];
        }

        $effectiveuserid = empty($userid) ? (int)$USER->id : $userid;
        if (empty($effectiveuserid)) {
            return [];
        }

        $bookinganswer = singleton_service::get_instance_of_booking_answers($settings);
        $bookinginformation = $bookinganswer->return_all_booking_information($effectiveuserid);

        if (empty($bookinginformation['iambooked'])) {
            return [];
        }

        return bookitbutton::get_book_intent_override_condition_ids();
    }

    /**
     * Callback to check if adding item to cart is allowed.
     *
     * @param string $area
     * @param int $itemid
     * @param int $userid
     * @return array
     */
    public static function allow_add_item_to_cart(
        string $area,
        int $itemid,
        int $userid = 0
    ): array {

        if ($area == "option") {
            booking_option::purge_cache_for_answers($itemid);

            $settings = singleton_service::get_instance_of_booking_option_settings($itemid);
            $ignoredconditionids = self::get_cart_book_intent_ignored_condition_ids($settings, $userid);

            $boinfo = new bo_info($settings);
            // There are two cases where we can actually book.
            // We call thefunction with hadblock set to true.
            // This means that we only get those blocks that actually should prevent booking.
            [$id, $isavailable, $description] = $boinfo->is_available(
                $itemid,
                $userid,
                true,
                true,
                $ignoredconditionids
            );

            // These conditions are allowed, so we need a check.
            $allowedconditions = [
                MOD_BOOKING_BO_COND_PRICEISSET,
                MOD_BOOKING_BO_COND_ALREADYRESERVED,
                MOD_BOOKING_BO_COND_BOOKITBUTTON,
                MOD_BOOKING_BO_COND_ONWAITINGLIST,
            ];

            if ($id > 0 && !in_array($id, $allowedconditions)) {
                switch ($id) {
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

            // Todo: Dont call allow_add_item_to_cart when NOT adding to cart!
            if (
                !(
                    $id === MOD_BOOKING_BO_COND_BOOKITBUTTON
                    || $id === MOD_BOOKING_BO_COND_ASKFORCONFIRMATION
                )
            ) {
                $user = singleton_service::get_instance_of_user($userid);
                $item = $settings->return_booking_option_information($user, false);
                // Without a resolvable price (no price records or no matching price
                // category) the option cannot be sold — deny instead of letting the
                // cartitem constructor fail on the null price.
                if (!isset($item['price']) || !is_numeric($item['price'])) {
                    return [
                        'allow' => false,
                        'info' => 'cannotbebooked',
                        'itemname' => $settings->get_title_with_prefix() ?? '',
                    ];
                }
                $cartitem = new cartitem(
                    $itemid,
                    $item['title'],
                    (float)$item['price'],
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

    /**
     * Callback to adjust the number of items currently bought.
     *
     * @param string $area
     * @param int $itemid
     * @param int $nritems
     * @param int $userid
     * @return bool
     */
    public static function adjust_number_of_items(string $area, int $itemid, int $nritems, int $userid = 0): bool {
        // Currently, only one option of booking can have multiple items.
        if ($area === 'option') {
            $settings = singleton_service::get_instance_of_booking_option_settings($itemid);
            $ba = singleton_service::get_instance_of_booking_answers($settings);

            $users = $ba->get_usersreserved();
            if ($answer = $users[$userid] ?? null) {
                if (!empty($settings->maxanswers)) {
                    $currentlybooked = enrollink::return_number_of_booked_licenses_from_booking_answer((object)$answer);
                    $freeonlist = $settings->maxanswers - booking_answers::count_places($ba->get_usersonlist());
                    // User may adjust up to what's free plus what they already hold.
                    if ($nritems > $freeonlist + $currentlybooked) {
                        return false;
                    }
                }
                // Adjust the number of items in the booking answer.
                enrollink::update_number_of_booked_licenses_for_booking_answer($answer, $nritems);
            }
        }
        return true;
    }

    /**
     * Resolve human-readable item names for a list of item ids.
     *
     * This optional adapter callback is used by local_shopping_cart coupon UI.
     *
     * @param int[] $itemids
     * @param string $area
     * @return array<int, string>
     */
    public static function resolve_item_names(array $itemids, string $area = 'option'): array {
        global $DB;

        // Coupon bindings in booking are currently option-based.
        if ($area !== 'option' || empty($itemids)) {
            return [];
        }

        $itemids = array_values(array_unique(array_map('intval', $itemids)));
        if (empty($itemids)) {
            return [];
        }

        [$insql, $inparams] = $DB->get_in_or_equal($itemids, SQL_PARAMS_NAMED);
        $records = $DB->get_records_select('booking_options', "id $insql", $inparams, '', 'id, text');

        $names = [];
        foreach ($records as $record) {
            $names[(int)$record->id] = (string)$record->text;
        }

        return $names;
    }

    /**
     * Resolve view links for a list of item ids.
     *
     * This optional adapter callback is used by local_shopping_cart coupon UI.
     *
     * @param int[] $itemids
     * @param string $area
     * @return array<int, string>
     */
    public static function resolve_item_links(array $itemids, string $area = 'option'): array {
        global $DB;

        // Coupon bindings in booking are currently option-based.
        if ($area !== 'option' || empty($itemids)) {
            return [];
        }

        $itemids = array_values(array_unique(array_map('intval', $itemids)));
        if (empty($itemids)) {
            return [];
        }

        [$insql, $inparams] = $DB->get_in_or_equal($itemids, SQL_PARAMS_NAMED);
        $sql = "SELECT bo.id, cm.id AS cmid
                  FROM {booking_options} bo
                  JOIN {course_modules} cm ON bo.bookingid = cm.instance
                  JOIN {modules} m ON m.id = cm.module
                 WHERE m.name = 'booking'
                   AND bo.id $insql";
        $records = $DB->get_records_sql($sql, $inparams);

        $links = [];
        foreach ($records as $record) {
            $links[(int)$record->id] = (new \moodle_url('/mod/booking/view.php', [
                'id' => (int)$record->cmid,
                'optionid' => (int)$record->id,
                'whichview' => 'showonlyone',
            ]))->out(false);
        }

        return $links;
    }
}
