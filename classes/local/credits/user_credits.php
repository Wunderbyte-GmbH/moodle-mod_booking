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
 * "Book with credits": the credits of a user (custom user profile field) and their payments.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\credits;

use cache;
use cache_store;
use mod_booking\booking_option_settings;
use mod_booking\local\mobile\slotbookingstore;
use mod_booking\local\slotbooking\slot_answer;
use mod_booking\singleton_service;
use moodle_exception;
use stdClass;

/**
 * Reads and changes the credits of a user and records what a booking paid with them.
 *
 * The credits live in the custom user profile field of the setting "bookwithcreditsprofilefield".
 * A booking option costs its "credits" value; a slot booking option costs it per slot.
 *
 * The flow of a payment:
 * 1. booking_bookit::bookit() checks the credits on the confirming click and calls confirm_payment().
 * 2. booking_option::write_user_answer_to_db() stores the payment in the answer json
 *    (add_payment_to_answer()) and takes the credits only once the answer is saved (charge_answer()).
 *    A booking that fails on the way (e.g. the slot was taken meanwhile) costs nothing.
 * 3. When slots of a booking paid with credits are given up, refund_removed_slots() gives the
 *    credits of these slots back. A move keeps the number of slots, so it changes nothing.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class user_credits {
    /** @var array Credit payments confirmed in this request: "userid_optionid" => credits per slot. */
    private static array $confirmedpayments = [];

    /**
     * The short name of the profile field that holds the credits ('' if none is set).
     *
     * @return string
     */
    public static function get_profilefield(): string {
        return (string)(get_config('booking', 'bookwithcreditsprofilefield') ?: '');
    }

    /**
     * Whether "book with credits" is switched on and a credits profile field is set.
     *
     * @return bool
     */
    public static function is_active(): bool {
        return !empty(get_config('booking', 'bookwithcreditsactive')) && self::get_profilefield() !== '';
    }

    /**
     * The current credits of a user, read from the database (not from the session).
     *
     * @param int $userid
     * @return float
     */
    public static function get_user_credits(int $userid): float {
        global $DB;

        $profilefield = self::get_profilefield();
        if ($userid <= 0 || $profilefield === '') {
            return 0.0;
        }

        $cache = self::get_request_cache();
        $credits = $cache->get($userid);
        if ($credits !== false) {
            return (float)$credits;
        }

        $value = $DB->get_field_sql(
            "SELECT d.data
               FROM {user_info_data} d
               JOIN {user_info_field} f ON f.id = d.fieldid
              WHERE f.shortname = :shortname AND d.userid = :userid",
            ['shortname' => $profilefield, 'userid' => $userid]
        );
        $credits = is_numeric($value) ? (float)$value : 0.0;
        $cache->set($userid, $credits);

        return $credits;
    }

    /**
     * Save the new credits of a user.
     *
     * @param int $userid
     * @param float $credits
     * @return void
     */
    public static function set_user_credits(int $userid, float $credits): void {
        global $CFG, $USER;

        $profilefield = self::get_profilefield();
        if ($userid <= 0 || $profilefield === '') {
            return;
        }

        require_once($CFG->dirroot . '/user/profile/lib.php');
        $value = self::format_credits($credits);
        profile_save_custom_fields($userid, [$profilefield => $value]);

        self::get_request_cache()->delete($userid);
        singleton_service::destroy_user($userid);
        // The session keeps its own copy of the profile fields.
        if (!empty($USER->id) && (int)$USER->id === $userid && isset($USER->profile) && is_array($USER->profile)) {
            $USER->profile[$profilefield] = $value;
        }
    }

    /**
     * The number of slots the user is booking right now (1 for an option without slots).
     *
     * @param booking_option_settings $settings
     * @param int $userid
     * @return int
     */
    public static function get_slot_count(booking_option_settings $settings, int $userid): int {
        if (empty($settings->slotconfig)) {
            return 1;
        }

        $store = new slotbookingstore($userid, (int)$settings->id);
        $ranges = $store->get_selected_ranges($store->get_slotbooking_data());

        // Before any slot is chosen, show the price of one slot.
        return max(1, count($ranges));
    }

    /**
     * The credits this booking costs: the option's credits, per selected slot for slot options.
     *
     * @param booking_option_settings $settings
     * @param int $userid
     * @param int|null $slotcount number of slots, null = the slots the user has selected
     * @return float
     */
    public static function get_required_credits(
        booking_option_settings $settings,
        int $userid,
        ?int $slotcount = null
    ): float {
        $slotcount = $slotcount ?? self::get_slot_count($settings, $userid);
        return (float)$settings->credits * max(1, $slotcount);
    }

    /**
     * The exception for a user who does not have enough credits.
     *
     * @param booking_option_settings $settings
     * @param int $userid
     * @param int|null $slotcount number of slots, null = the slots the user has selected
     * @return moodle_exception
     */
    public static function not_enough_credits_exception(
        booking_option_settings $settings,
        int $userid,
        ?int $slotcount = null
    ): moodle_exception {
        if (empty($settings->slotconfig)) {
            return new moodle_exception('notenoughcredits', 'mod_booking');
        }
        return new moodle_exception(
            'notenoughcreditsforslots',
            'mod_booking',
            '',
            self::get_not_enough_credits_data($settings, $userid, $slotcount)
        );
    }

    /**
     * The message for a user who does not have enough credits for the selected slots, or null if
     * the credits are enough or the option is not paid with credits only.
     *
     * Only options without a price have to be paid with credits: an option with a price is paid
     * with money when the credits are not enough (same as a normal option).
     *
     * @param booking_option_settings $settings
     * @param int $userid
     * @param int $slotcount
     * @return string|null
     */
    public static function get_not_enough_credits_message(
        booking_option_settings $settings,
        int $userid,
        int $slotcount
    ): ?string {
        if (!self::is_active() || empty($settings->credits) || !empty($settings->jsonobject->useprice)) {
            return null;
        }

        if (self::get_user_credits($userid) >= self::get_required_credits($settings, $userid, $slotcount)) {
            return null;
        }

        return get_string(
            'notenoughcreditsforslots',
            'mod_booking',
            self::get_not_enough_credits_data($settings, $userid, $slotcount)
        );
    }

    /**
     * Placeholder values for the "not enough credits" message.
     *
     * @param booking_option_settings $settings
     * @param int $userid
     * @param int|null $slotcount
     * @return stdClass
     */
    private static function get_not_enough_credits_data(
        booking_option_settings $settings,
        int $userid,
        ?int $slotcount
    ): stdClass {
        $slotcount = $slotcount ?? self::get_slot_count($settings, $userid);
        return (object)[
            'required' => self::format_credits(self::get_required_credits($settings, $userid, $slotcount)),
            'slots' => max(1, $slotcount),
            'available' => self::format_credits(self::get_user_credits($userid)),
        ];
    }

    /**
     * Remember that the user confirmed paying this booking with credits (this request only).
     *
     * @param int $userid
     * @param booking_option_settings $settings
     * @return void
     */
    public static function confirm_payment(int $userid, booking_option_settings $settings): void {
        self::$confirmedpayments[self::get_key($userid, (int)$settings->id)] = (float)$settings->credits;
    }

    /**
     * Forget a confirmed payment, so it cannot be used for a later booking.
     *
     * @param int $userid
     * @param int $optionid
     * @return void
     */
    public static function forget_payment(int $userid, int $optionid): void {
        unset(self::$confirmedpayments[self::get_key($userid, $optionid)]);
    }

    /**
     * Before the answer is saved: record in its json what the booking pays with credits.
     *
     * Only a booked answer of a confirmed payment is paid with credits - not a reservation in the
     * shopping cart and not a place on the waiting list.
     *
     * @param stdClass $newanswer
     * @param int $userid
     * @return void
     */
    public static function add_payment_to_answer(stdClass &$newanswer, int $userid): void {
        $key = self::get_key($userid, (int)($newanswer->optionid ?? 0));
        if (!isset(self::$confirmedpayments[$key]) || (int)($newanswer->waitinglist ?? -1) !== MOD_BOOKING_STATUSPARAM_BOOKED) {
            return;
        }

        $perslot = self::$confirmedpayments[$key];
        $jsonobject = !empty($newanswer->json) ? (json_decode($newanswer->json) ?: new stdClass()) : new stdClass();
        $jsonobject->paidwithcredits = 1;

        $slotdata = slot_answer::get_slot_data($newanswer);
        if ($slotdata !== null) {
            $slotcount = count($slotdata['slots'] ?? []);
            $jsonobject->creditsperslot = $perslot;
            $jsonobject->creditspaid = $perslot * $slotcount;
        } else {
            $jsonobject->creditspaid = $perslot;
        }

        $newanswer->json = json_encode($jsonobject);
    }

    /**
     * After the answer is saved: take the credits the answer pays.
     *
     * @param stdClass $newanswer the saved answer (with id)
     * @return void
     */
    public static function charge_answer(stdClass $newanswer): void {
        $userid = (int)($newanswer->userid ?? 0);
        $key = self::get_key($userid, (int)($newanswer->optionid ?? 0));
        if (!isset(self::$confirmedpayments[$key]) || (int)($newanswer->waitinglist ?? -1) !== MOD_BOOKING_STATUSPARAM_BOOKED) {
            return;
        }
        // One confirmation pays one booking.
        unset(self::$confirmedpayments[$key]);

        $jsonobject = json_decode((string)($newanswer->json ?? ''));
        $creditspaid = (float)($jsonobject->creditspaid ?? 0);
        if ($creditspaid <= 0) {
            return;
        }

        self::set_user_credits($userid, self::get_user_credits($userid) - $creditspaid);
    }

    /**
     * Whether the answer was paid with credits.
     *
     * @param stdClass $answer
     * @return bool
     */
    public static function answer_paid_with_credits(stdClass $answer): bool {
        $jsonobject = json_decode((string)($answer->json ?? ''));
        return !empty($jsonobject->paidwithcredits);
    }

    /**
     * Give back the credits of the slots a booking paid with credits does not hold anymore.
     *
     * Called after slots were released or the booking was cancelled. The answer knows what it
     * paid; whatever it paid for slots it does not hold anymore goes back to the user. A move keeps
     * the number of slots and so gives nothing back. Deadlines are checked by the cancel code
     * before (users), staff may cancel at any time and the credits come back as well.
     *
     * @param int $baid booking answer id
     * @return float the credits given back
     */
    public static function refund_removed_slots(int $baid): float {
        global $DB;

        $answer = $DB->get_record('booking_answers', ['id' => $baid]);
        if (empty($answer)) {
            return 0.0;
        }

        $jsonobject = json_decode((string)$answer->json);
        // Only slot bookings paid with credits know a price per slot.
        if (empty($jsonobject->paidwithcredits) || !isset($jsonobject->creditsperslot)) {
            return 0.0;
        }

        $heldslots = 0;
        if ((int)$answer->waitinglist === MOD_BOOKING_STATUSPARAM_BOOKED) {
            $heldslots = count(slot_answer::get_slot_data($answer)['slots'] ?? []);
        }

        $creditspaid = (float)($jsonobject->creditspaid ?? 0);
        $creditsdue = (float)$jsonobject->creditsperslot * $heldslots;
        $refund = $creditspaid - $creditsdue;
        if ($refund <= 0) {
            return 0.0;
        }

        $jsonobject->creditspaid = $creditsdue;
        $DB->set_field('booking_answers', 'json', json_encode($jsonobject), ['id' => $baid]);

        $userid = (int)$answer->userid;
        self::set_user_credits($userid, self::get_user_credits($userid) + $refund);

        return $refund;
    }

    /**
     * Remove the "book with credits" confirmation marker (first click) of this option.
     *
     * @param int $userid
     * @param int $optionid
     * @return void
     */
    public static function clear_confirm_marker(int $userid, int $optionid): void {
        $cache = cache::make('mod_booking', 'confirmbooking');
        $data = $cache->get($userid);
        $cachekey = $userid . '_' . $optionid . '_bookwithcredits';
        if (is_array($data) && isset($data[$cachekey])) {
            unset($data[$cachekey]);
            $cache->set($userid, $data);
        }
    }

    /**
     * Credits as text for the profile field and messages (no needless decimals).
     *
     * @param float $credits
     * @return string
     */
    public static function format_credits(float $credits): string {
        return format_float($credits, -1, false);
    }

    /**
     * The key of a payment.
     *
     * @param int $userid
     * @param int $optionid
     * @return string
     */
    private static function get_key(int $userid, int $optionid): string {
        return $userid . '_' . $optionid;
    }

    /**
     * Request cache of the credits per user.
     *
     * @return \cache
     */
    private static function get_request_cache() {
        return cache::make_from_params(cache_store::MODE_REQUEST, 'mod_booking', 'usercredits');
    }
}
