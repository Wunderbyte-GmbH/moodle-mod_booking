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
 * Service that moves a booked slot to a different time range.
 *
 * Single implementation shared by the move-slot page and the move-slot webservice
 * (and, later, the move-slot modal). Owns validation, persistence, event and
 * notifications so all callers behave identically.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\slotbooking;

use context_module;
use core_user;
use mod_booking\booking_option;
use mod_booking\event\bookinganswer_slotcancelled;
use mod_booking\event\bookinganswer_slotmoved;
use mod_booking\option\dates_handler;
use mod_booking\option\fields\multiplebookings;
use mod_booking\singleton_service;
use moodle_exception;
use stdClass;

/**
 * Move a booked slot answer to a new slot range.
 */
class slot_mover {
    /**
     * Load the move context for a booking answer: the answer, its current slots,
     * the required slot count and the selectable target slots (next 30 days).
     *
     * @param int $optionid booking option id
     * @param int $baid booking answer id
     * @return array{answer: stdClass, currentslots: array, currentslotkeys: array,
     *               requiredslotcount: int, targetslots: array}
     */
    public static function get_move_context(int $optionid, int $baid): array {
        global $DB;

        $answer = $DB->get_record('booking_answers', ['id' => $baid, 'optionid' => $optionid], '*', MUST_EXIST);

        $currentslots = self::extract_current_slots($answer);
        if (empty($currentslots)) {
            throw new moodle_exception('invaliddata');
        }

        usort($currentslots, static fn(array $a, array $b): int => $a['start'] <=> $b['start']);

        $currentslotkeys = [];
        foreach ($currentslots as $slot) {
            $currentslotkeys[] = $slot['start'] . ':' . $slot['end'];
        }

        return [
            'answer' => $answer,
            'currentslots' => $currentslots,
            'currentslotkeys' => $currentslotkeys,
            'requiredslotcount' => max(1, count($currentslots)),
            'targetslots' => self::available_target_slots($optionid, (int)$answer->userid, $currentslots, $currentslotkeys),
        ];
    }

    /**
     * Extract the answer's current slot ranges from the answer JSON.
     *
     * @param stdClass $answer booking answer record
     * @return array<int, array{start: int, end: int}>
     */
    private static function extract_current_slots(stdClass $answer): array {
        $slotdata = slot_answer::get_slot_data($answer);
        $currentslots = [];

        if (!empty($slotdata['slots']) && is_array($slotdata['slots'])) {
            foreach ($slotdata['slots'] as $slot) {
                if (!is_array($slot) || !isset($slot['start']) || !isset($slot['end'])) {
                    continue;
                }
                $start = (int)$slot['start'];
                $end = (int)$slot['end'];
                if ($end <= $start) {
                    continue;
                }
                $currentslots[] = ['start' => $start, 'end' => $end];
            }
        }

        return $currentslots;
    }

    /**
     * Build the list of selectable target slots (open slots in the next 30 days plus
     * the answer's current slots), labelled for the calendar picker.
     *
     * @param int $optionid booking option id
     * @param int $userid the booking owner
     * @param array $currentslots current slot ranges
     * @param array $currentslotkeys current slot keys
     * @return array<int, array<string, mixed>>
     */
    public static function available_target_slots(
        int $optionid,
        int $userid,
        array $currentslots,
        array $currentslotkeys
    ): array {
        // Offer every selectable slot within the option's validity range (the same range the
        // booking picker uses), not just the next 30 days — so far-future options (e.g. June 2035)
        // can be rebooked too.
        $slots = slot_availability::get_slots_with_status($optionid, $userid);

        $calendarslots = [];
        $calendarslotkeyset = [];
        foreach ($slots as $slot) {
            if (($slot['status'] ?? '') !== 'open') {
                continue;
            }
            $start = (int)$slot['start'];
            $end = (int)$slot['end'];
            $key = $start . ':' . $end;
            $calendarslotkeyset[$key] = true;
            $calendarslots[] = self::target_slot_entry($optionid, $userid, $start, $end, $key);
        }

        foreach ($currentslots as $slot) {
            $key = $slot['start'] . ':' . $slot['end'];
            if (!empty($calendarslotkeyset[$key])) {
                continue;
            }
            $calendarslots[] = self::target_slot_entry($optionid, $userid, (int)$slot['start'], (int)$slot['end'], $key);
        }

        usort($calendarslots, static fn(array $a, array $b): int => $a['start'] === $b['start']
            ? ($a['end'] <=> $b['end'])
            : ($a['start'] <=> $b['start']));

        return $calendarslots;
    }

    /**
     * Build a single labelled target-slot entry.
     *
     * Reuses slot_dto's label and price helpers so move targets carry the same fields as the
     * booking picker's slots — the calendar widget is shared, so this makes the move picker show
     * the same price dots, price legend and per-slot price (previously the price data was missing).
     *
     * @param int $optionid booking option id
     * @param int $userid booking owner (price-category resolution)
     * @param int $start slot start
     * @param int $end slot end
     * @param string $key slot key
     * @return array<string, mixed>
     */
    private static function target_slot_entry(int $optionid, int $userid, int $start, int $end, string $key): array {
        $pricedata = slot_dto::price_data($optionid, $start, $end, $userid);
        return [
            'key' => $key,
            'start' => $start,
            'end' => $end,
            'daylabel' => slot_dto::day_label($start),
            'timelabel' => slot_dto::time_range_label($start, $end),
            'price' => $pricedata['price'],
            'currency' => $pricedata['currency'],
            'priceformatted' => $pricedata['priceformatted'],
        ];
    }

    /**
     * Move a booking answer to the selected target slots.
     *
     * Validates the selection, persists the new slot data, triggers the slot-moved
     * event and notifies the student and assigned teachers. Throws on invalid input.
     *
     * @param int $optionid booking option id
     * @param int $baid booking answer id
     * @param array $selectedslotkeys selected target slot keys ("start:end")
     * @param string $reason optional move reason
     * @return array{newstart: int, newend: int, slotcount: int}
     */
    public static function move(int $optionid, int $baid, array $selectedslotkeys, string $reason = ''): array {
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $context = context_module::instance($settings->cmid);
        if (
            !has_capability('mod/booking:moveslots', $context)
            && !has_capability('mod/booking:updatebooking', $context)
        ) {
            require_capability('mod/booking:moveslots', $context);
        }

        return self::move_validated($optionid, $baid, $selectedslotkeys, $reason, 'manager');
    }

    /**
     * Self-service rebooking: a participant moves their own booked slot(s).
     *
     * Enforces ownership, the per-option opt-in setting, the moveslotsself capability,
     * the rebooking deadline and that every given-up slot still lies in the future,
     * then delegates to the shared move core. No move logic is duplicated here.
     *
     * @param int $optionid booking option id
     * @param int $baid booking answer id (must belong to the current user)
     * @param array $selectedslotkeys selected target slot keys ("start:end")
     * @param string $reason optional rebooking reason
     * @return array{newstart: int, newend: int, slotcount: int}
     */
    public static function move_self(int $optionid, int $baid, array $selectedslotkeys, string $reason = ''): array {
        global $DB, $USER;

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $context = context_module::instance($settings->cmid);
        require_capability('mod/booking:moveslotsself', $context);

        $answer = $DB->get_record('booking_answers', ['id' => $baid, 'optionid' => $optionid], '*', MUST_EXIST);

        // Ownership: a user may only move their own answer (move() never checks this).
        if ((int)$answer->userid !== (int)$USER->id) {
            throw new moodle_exception('slot_rebook_not_allowed', 'mod_booking');
        }

        // Only an active booking can be rebooked (not deleted/reserved/waitinglist).
        if ((int)$answer->waitinglist !== MOD_BOOKING_STATUSPARAM_BOOKED) {
            throw new moodle_exception('slot_rebook_not_allowed', 'mod_booking');
        }

        // Setting opt-in plus deadline (single source of truth, also used for UI visibility).
        if (!self::self_rebooking_allowed($optionid, $answer)) {
            throw new moodle_exception('slot_rebook_not_allowed', 'mod_booking');
        }

        // Every slot the user gives up (current minus reselected) must still be actionable.
        self::guard_given_up_slots_actionable($answer, $selectedslotkeys);

        return self::move_validated($optionid, $baid, $selectedslotkeys, $reason, 'self');
    }

    /**
     * Validate a self-service move request and resolve the target slots WITHOUT committing.
     *
     * Runs the same gate as move_self() (capability, ownership, booked state, opt-in/deadline,
     * given-up-slots actionable) and validates the selected target slots for availability, but
     * does not touch the booking answer. Used by slot_update_service to compute the price
     * delta and to feed a pending move (upgrade) before payment.
     *
     * @param int $optionid booking option id
     * @param int $baid booking answer id (must belong to the current user)
     * @param array $selectedslotkeys selected target slot keys ("start:end")
     * @return array{currentslots: array, newslots: array, newstart: int, newend: int, slotcount: int}
     */
    public static function resolve_self_target_slots(int $optionid, int $baid, array $selectedslotkeys): array {
        global $DB, $USER;

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        require_capability('mod/booking:moveslotsself', context_module::instance($settings->cmid));

        $answer = $DB->get_record('booking_answers', ['id' => $baid, 'optionid' => $optionid], '*', MUST_EXIST);
        if ((int)$answer->userid !== (int)$USER->id) {
            throw new moodle_exception('slot_rebook_not_allowed', 'mod_booking');
        }
        if ((int)$answer->waitinglist !== MOD_BOOKING_STATUSPARAM_BOOKED) {
            throw new moodle_exception('slot_rebook_not_allowed', 'mod_booking');
        }
        if (!self::self_rebooking_allowed($optionid, $answer)) {
            throw new moodle_exception('slot_rebook_not_allowed', 'mod_booking');
        }
        self::guard_given_up_slots_actionable($answer, $selectedslotkeys);

        $movecontext = self::get_move_context($optionid, $baid);
        $currentslots = $movecontext['currentslots'];
        $currentslotkeyset = array_fill_keys($movecontext['currentslotkeys'], true);
        $requiredslotcount = $movecontext['requiredslotcount'];

        $selectedslotkeys = array_values(array_unique(array_filter(array_map('trim', $selectedslotkeys))));
        if (count($selectedslotkeys) !== $requiredslotcount) {
            throw new moodle_exception('slot_move_select', 'mod_booking');
        }

        $newslots = [];
        foreach ($selectedslotkeys as $key) {
            $parts = explode(':', $key);
            if (count($parts) !== 2) {
                continue;
            }
            $newstart = (int)$parts[0];
            $newend = (int)$parts[1];
            if ($newend <= $newstart) {
                continue;
            }
            $issameascurrent = !empty($currentslotkeyset[$key]);
            if (
                !$issameascurrent && !slot_availability::is_slot_available(
                    $optionid,
                    $newstart,
                    $newend,
                    (int)$answer->userid,
                    [],
                    $baid
                )
            ) {
                continue;
            }
            $newslots[] = ['start' => $newstart, 'end' => $newend];
        }

        if (count($newslots) !== $requiredslotcount) {
            throw new moodle_exception('slot_move_select', 'mod_booking');
        }

        usort($newslots, static fn(array $a, array $b): int => $a['start'] <=> $b['start']);

        return [
            'currentslots' => $currentslots,
            'newslots' => $newslots,
            'newstart' => (int)$newslots[0]['start'],
            'newend' => (int)$newslots[count($newslots) - 1]['end'],
            'slotcount' => count($newslots),
        ];
    }

    /**
     * Commit a held slot move at checkout (the upgrade path).
     *
     * The move was authorised and its target slots reserved when the pending row was created
     * (see slot_move_store / slot_update_service), so this does NOT re-check the self/manager
     * gate. It replays the stored target slots onto the booking answer through the shared move
     * core, excluding the move's own capacity hold from the re-validation, then marks the move
     * committed. Throws if the move is not a fresh pending row.
     *
     * @param int $moveid booking_slot_moves row id
     * @return array{newstart:int, newend:int, slotcount:int}
     */
    public static function commit_pending_move(int $moveid): array {
        $move = slot_move_store::get($moveid);
        if (empty($move) || (int)$move->status !== slot_move_store::STATUS_PENDING) {
            throw new moodle_exception('slot_move_notpending', 'mod_booking');
        }

        $keys = [];
        foreach (slot_move_store::decode_slots($move->newslots) as $slot) {
            $keys[] = $slot['start'] . ':' . $slot['end'];
        }

        $result = self::move_validated((int)$move->optionid, (int)$move->baid, $keys, '', 'self', $moveid);

        slot_move_store::commit($moveid);

        return $result;
    }

    /**
     * Self-service partial cancellation: release individual booked slots (Phase 2, no price).
     *
     * Only still-actionable slots (before their relative deadline) may be released. Locked slots
     * must stay. When every booked slot is released the whole booking answer is cancelled through
     * the standard deletion path; otherwise the remaining slots are persisted and a slot-cancelled
     * event is fired for the released ones. Price/refund handling is intentionally out of scope.
     *
     * @param int $optionid booking option id
     * @param int $baid booking answer id
     * @param array $releaseslotkeys slot keys ("start:end") to release
     * @param string $reason optional reason
     * @return array{released:int, remaining:int, cancelled:bool}
     */
    public static function release_self(int $optionid, int $baid, array $releaseslotkeys, string $reason = ''): array {
        global $DB, $USER;

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $context = context_module::instance($settings->cmid);
        require_capability('mod/booking:moveslotsself', $context);

        $answer = $DB->get_record('booking_answers', ['id' => $baid, 'optionid' => $optionid], '*', MUST_EXIST);

        // Ownership and an active booking are required; opt-in plus deadline are the single gate.
        if ((int)$answer->userid !== (int)$USER->id) {
            throw new moodle_exception('slot_rebook_not_allowed', 'mod_booking');
        }
        if ((int)$answer->waitinglist !== MOD_BOOKING_STATUSPARAM_BOOKED) {
            throw new moodle_exception('slot_rebook_not_allowed', 'mod_booking');
        }
        if (!self::self_rebooking_allowed($optionid, $answer)) {
            throw new moodle_exception('slot_rebook_not_allowed', 'mod_booking');
        }

        $releaseset = array_fill_keys(
            array_values(array_unique(array_filter(array_map('trim', $releaseslotkeys)))),
            true
        );
        if (empty($releaseset)) {
            throw new moodle_exception('slot_rebook_not_allowed', 'mod_booking');
        }

        $offset = slot_change_policy::resolve_deadline_minutes($optionid);
        $now = time();
        $released = [];
        $remaining = [];
        foreach (self::extract_current_slots($answer) as $slot) {
            $key = $slot['start'] . ':' . $slot['end'];
            if (empty($releaseset[$key])) {
                $remaining[] = $slot;
                continue;
            }
            // Only still-actionable slots may be released; locked slots cannot be given up.
            if (!slot_change_policy::slot_actionable((int)$slot['start'], $offset, $now)) {
                throw new moodle_exception('slot_rebook_slot_started', 'mod_booking');
            }
            $released[] = $slot;
            unset($releaseset[$key]);
        }

        // Every requested key must have matched a current slot and at least one must be released.
        if (!empty($releaseset) || empty($released)) {
            throw new moodle_exception('slot_rebook_not_allowed', 'mod_booking');
        }

        // Releasing every slot equals a full cancellation: use the standard deletion path
        // (handles status, completion, waiting list and fires its own slot-cancelled event).
        if (empty($remaining)) {
            $option = singleton_service::get_instance_of_booking_option((int)$settings->cmid, $optionid);
            $option->user_delete_response((int)$answer->userid);
            return ['released' => count($released), 'remaining' => 0, 'cancelled' => true];
        }

        // Partial release: persist the remaining slots. set_slot_data() merges recursively, which
        // would keep a removed slot at a higher index, so replace the slot payload wholesale here.
        usort($remaining, static fn(array $a, array $b): int => $a['start'] <=> $b['start']);
        $slotdata = slot_answer::get_slot_data($answer) ?? [];
        $slotdata['slots'] = array_values($remaining);
        $answer->startdate = (int)$remaining[0]['start'];
        $answer->enddate = (int)$remaining[count($remaining) - 1]['end'];
        $payload = json_decode((string)$answer->json, true);
        $payload = is_array($payload) ? $payload : [];
        $payload['slot'] = $slotdata;
        $answer->json = json_encode($payload);
        $DB->update_record('booking_answers', $answer);

        booking_option::purge_cache_for_answers($optionid);
        singleton_service::destroy_answers_for_user((int)$answer->userid, (int)$settings->bookingid);

        // Fire a slot-cancelled event for the released slots (bookedslots = the cancelled ones,
        // matching the full-cancel payload so booking-rule placeholders render them).
        $event = bookinganswer_slotcancelled::create([
            'objectid' => $baid,
            'context' => $context,
            'relateduserid' => $answer->userid,
            'other' => [
                'optionid' => $optionid,
                'baid' => $baid,
                'bookedslots' => $released,
                'remainingslots' => $remaining,
                'slotcount' => count($released),
                'reason' => $reason,
                'initiatedby' => 'self',
            ],
        ]);
        $event->trigger();

        return ['released' => count($released), 'remaining' => count($remaining), 'cancelled' => false];
    }

    /**
     * Whether self-service rebooking is currently possible for this answer.
     *
     * Single source of truth for the UI button visibility and the webservice guard:
     * the option must opt in, the answer must be booked, at least one current slot must
     * still lie in the future, and the optional absolute deadline must not have passed.
     *
     * @param int $optionid booking option id
     * @param stdClass $answer booking answer record
     * @return bool
     */
    public static function self_rebooking_allowed(int $optionid, stdClass $answer): bool {
        $config = self::get_slot_config($optionid);
        if (empty($config) || empty($config->allow_self_rebooking)) {
            return false;
        }

        if ((int)($answer->waitinglist ?? -1) !== MOD_BOOKING_STATUSPARAM_BOOKED) {
            return false;
        }

        // At least one booked slot must still be actionable per the relative per-slot
        // move/cancel deadline (slot_change_policy is the single source of truth).
        return slot_change_policy::answer_has_actionable_slot($answer);
    }

    /**
     * Return the user's own BOOKED answer iff self-service rebooking is allowed for it.
     *
     * Single source of truth for UI gating (alreadybooked step-back + slotmove condition):
     * resolves the booked answer and applies self_rebooking_allowed() in one place.
     *
     * @param int $optionid
     * @param int $userid
     * @return \stdClass|null the booking answer, or null when rebooking is not available
     */
    public static function get_self_rebookable_answer(int $optionid, int $userid): ?\stdClass {
        global $DB;

        if (empty($userid)) {
            return null;
        }

        $answer = $DB->get_record('booking_answers', [
            'optionid' => $optionid,
            'userid' => $userid,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
        ], '*', IGNORE_MULTIPLE);

        if (empty($answer) || !self::self_rebooking_allowed($optionid, $answer)) {
            return null;
        }

        return $answer;
    }

    /**
     * Whether the option is in a "book again" (multiplebookings) state for this booked answer.
     *
     * Thin wrapper over multiplebookings::book_again_due() (the single source of truth shared
     * with alreadybooked::is_available()): multiplebookings must be enabled and its gate
     * (fixed wait time, or the last booked slot having ended) must be satisfied. In that state
     * the normal booking flow owns the prepage and the move is offered as a tab inside it
     * (Fall 2), so the slotmove condition must not block.
     *
     * @param int $optionid
     * @param \stdClass $answer the user's booked answer
     * @return bool
     */
    public static function book_again_active(int $optionid, \stdClass $answer): bool {
        // The multiplebookings field owns the book-again gate (disabled / after-duration /
        // after-last-slot); delegate so the timing logic lives in exactly one place.
        return multiplebookings::book_again_due($optionid, $answer);
    }

    /**
     * End timestamp of the user's latest currently booked slot (0 when none can be derived).
     *
     * Reads the booked slot fragments from the answer JSON and returns the maximum end. Used by the
     * "after the last booked slot" book-again mode.
     *
     * @param stdClass $answer booking answer record
     * @return int unix timestamp of the last slot's end, or 0
     */
    public static function last_booked_slot_end(stdClass $answer): int {
        $end = 0;
        foreach (self::extract_current_slots($answer) as $slot) {
            $end = max($end, (int)$slot['end']);
        }
        return $end;
    }

    /**
     * Ensure every slot the user gives up still lies in the future.
     *
     * A given-up slot is a currently booked slot whose key is NOT in the new selection;
     * reselected current slots stay untouched and may already have started.
     *
     * @param stdClass $answer booking answer record
     * @param array $selectedslotkeys selected target slot keys
     * @throws moodle_exception
     * @return void
     */
    private static function guard_given_up_slots_actionable(stdClass $answer, array $selectedslotkeys): void {
        $now = time();
        $offset = slot_change_policy::resolve_deadline_minutes((int)$answer->optionid);
        $selectedset = array_fill_keys(
            array_values(array_unique(array_filter(array_map('trim', $selectedslotkeys)))),
            true
        );

        foreach (self::extract_current_slots($answer) as $slot) {
            $key = $slot['start'] . ':' . $slot['end'];
            if (!empty($selectedset[$key])) {
                // Reselected (kept) slot — not given up; a locked slot may always be kept.
                continue;
            }
            // A slot may only be given up while it is still actionable (before its relative deadline).
            if (!slot_change_policy::slot_actionable((int)$slot['start'], $offset, $now)) {
                throw new moodle_exception('slot_rebook_slot_started', 'mod_booking');
            }
        }
    }

    /**
     * Load the slot config row for an option.
     *
     * @param int $optionid booking option id
     * @return ?stdClass
     */
    private static function get_slot_config(int $optionid): ?stdClass {
        global $DB;
        $config = $DB->get_record('booking_slot_config', ['optionid' => $optionid], '*', IGNORE_MISSING);
        return $config ?: null;
    }

    /**
     * Move a booking answer to the selected target slots (shared core).
     *
     * Validates the selection, persists the new slot data, triggers the slot-moved
     * event and notifies the student and assigned teachers. Callers own authorization;
     * both move() (manager) and move_self() (participant) delegate here so the move
     * logic exists exactly once.
     *
     * @param int $optionid booking option id
     * @param int $baid booking answer id
     * @param array $selectedslotkeys selected target slot keys ("start:end")
     * @param string $reason optional move reason
     * @param string $initiatedby 'manager' or 'self'
     * @param int $excludemoveid pending move id whose own capacity hold must be ignored when
     *  re-validating the target (used when committing a held upgrade at checkout)
     * @return array{newstart: int, newend: int, slotcount: int}
     */
    private static function move_validated(
        int $optionid,
        int $baid,
        array $selectedslotkeys,
        string $reason,
        string $initiatedby,
        int $excludemoveid = 0
    ): array {
        global $DB;

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $context = context_module::instance($settings->cmid);

        $movecontext = self::get_move_context($optionid, $baid);
        $answer = $movecontext['answer'];
        $currentslots = $movecontext['currentslots'];
        $currentslotkeyset = array_fill_keys($movecontext['currentslotkeys'], true);
        $requiredslotcount = $movecontext['requiredslotcount'];

        $selectedslotkeys = array_values(array_unique(array_filter(array_map('trim', $selectedslotkeys))));
        // An update may keep fewer slots than booked (a reduction / mixed drop+swap), but never more,
        // and never zero (giving up the last slot is a full cancellation handled by release_self).
        if (count($selectedslotkeys) < 1 || count($selectedslotkeys) > $requiredslotcount) {
            throw new moodle_exception('slot_move_select', 'mod_booking');
        }

        $newslots = [];
        foreach ($selectedslotkeys as $key) {
            $parts = explode(':', $key);
            if (count($parts) !== 2) {
                continue;
            }
            $newstart = (int)$parts[0];
            $newend = (int)$parts[1];
            if ($newend <= $newstart) {
                continue;
            }

            $issameascurrent = !empty($currentslotkeyset[$key]);
            if (
                !$issameascurrent && !slot_availability::is_slot_available(
                    $optionid,
                    $newstart,
                    $newend,
                    (int)$answer->userid,
                    [],
                    $baid,
                    $excludemoveid
                )
            ) {
                continue;
            }

            $newslots[] = ['start' => $newstart, 'end' => $newend];
        }

        // Every selected key must have resolved to a valid, available slot.
        if (count($newslots) !== count($selectedslotkeys)) {
            throw new moodle_exception('slot_move_select', 'mod_booking');
        }

        usort($newslots, static fn(array $a, array $b): int => $a['start'] <=> $b['start']);

        $slotdata = slot_answer::get_slot_data($answer);
        $oldstart = (int)($answer->startdate ?? 0);
        $oldend = (int)($answer->enddate ?? 0);
        $newstart = (int)$newslots[0]['start'];
        $newend = (int)$newslots[count($newslots) - 1]['end'];

        $answer->startdate = $newstart;
        $answer->enddate = $newend;

        $oldslots = $slotdata['slots'] ?? [];
        if (!empty($oldslots) && is_array($oldslots)) {
            $first = reset($oldslots);
            $last = end($oldslots);
            if (is_array($first) && is_array($last) && isset($first['start']) && isset($last['end'])) {
                // Append to the move history instead of overwriting, so repeated
                // rebookings stay auditable.
                $history = $slotdata['moved_from'] ?? [];
                $history[] = [
                    'start' => (int)$first['start'],
                    'end' => (int)$last['end'],
                    'movedat' => time(),
                    'initiatedby' => $initiatedby,
                ];
                $slotdata['moved_from'] = array_values($history);
            }
        }

        $slotdata['slots'] = $newslots;

        // Keep per-slot teacher assignments aligned with moved slot ranges.
        if (!empty($slotdata['teachers_per_slot']) && is_array($slotdata['teachers_per_slot'])) {
            $oldteachersperslot = [];
            foreach ($slotdata['teachers_per_slot'] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $teacherids = array_values(array_unique(array_filter(
                    array_map('intval', (array)($entry['teachers'] ?? [])),
                    static fn(int $teacherid): bool => $teacherid > 0
                )));
                $oldteachersperslot[] = ['teachers' => $teacherids];
            }

            $newteachersperslot = [];
            foreach ($newslots as $index => $slot) {
                $newteachersperslot[] = [
                    'start' => (int)$slot['start'],
                    'end' => (int)$slot['end'],
                    'teachers' => $oldteachersperslot[$index]['teachers'] ?? [],
                ];
            }
            $slotdata['teachers_per_slot'] = $newteachersperslot;

            $allteacherids = [];
            foreach ($newteachersperslot as $entry) {
                foreach ((array)($entry['teachers'] ?? []) as $teacherid) {
                    $allteacherids[(int)$teacherid] = true;
                }
            }
            $slotdata['teachers'] = array_values(array_keys($allteacherids));
        }

        // Slot rules may depend on time; recalculate aggregated slot price after moving.
        $movetotalprice = 0.0;
        foreach ($newslots as $slot) {
            $pricedata = slot_price::calculate_slot_price_data(
                $optionid,
                (int)$slot['start'],
                (int)$slot['end'],
                (int)$answer->userid
            );
            $movetotalprice += (float)($pricedata['price'] ?? 0);
        }
        $slotdata['price'] = round($movetotalprice, 2);
        $slotdata['move_reason'] = $reason;

        // Replace the slot payload wholesale instead of set_slot_data() (which merges recursively
        // and would leave a dropped slot behind at a higher index when an update reduces the count).
        $payload = json_decode((string)$answer->json, true);
        $payload = is_array($payload) ? $payload : [];
        $payload['slot'] = $slotdata;
        $answer->json = json_encode($payload);
        $DB->update_record('booking_answers', $answer);

        // Ensure all booking answer caches reflect the moved slot immediately.
        booking_option::purge_cache_for_answers($optionid);
        singleton_service::destroy_answers_for_user((int)$answer->userid, (int)$settings->bookingid);

        // Per-change events: a slot moved in (added) fires slotmoved; a net reduction (fewer slots
        // than before) fires slotcancelled for the given-up slots. A mixed update fires both.
        $newkeyset = [];
        foreach ($newslots as $slot) {
            $newkeyset[$slot['start'] . ':' . $slot['end']] = true;
        }
        $removedslots = array_values(array_filter(
            $currentslots,
            static fn(array $slot): bool => empty($newkeyset[$slot['start'] . ':' . $slot['end']])
        ));
        $addedslots = array_values(array_filter(
            $newslots,
            static fn(array $slot): bool => empty($currentslotkeyset[$slot['start'] . ':' . $slot['end']])
        ));
        $firemoved = !empty($addedslots);
        $firecancelled = count($newslots) < count($currentslots);

        if ($firemoved) {
            $event = bookinganswer_slotmoved::create([
                'objectid' => $baid,
                'context' => $context,
                'relateduserid' => $answer->userid,
                'other' => [
                    'optionid' => $optionid,
                    'baid' => $baid,
                    'oldstart' => $oldstart,
                    'oldend' => $oldend,
                    'newstart' => $newstart,
                    'newend' => $newend,
                    'oldslots' => $currentslots,
                    'newslots' => $newslots,
                    'bookedslots' => $newslots,
                    'slotcount' => count($newslots),
                    'reason' => $reason,
                    'initiatedby' => $initiatedby,
                ],
            ]);
            $event->trigger();
        }

        if ($firecancelled) {
            $cancelevent = bookinganswer_slotcancelled::create([
                'objectid' => $baid,
                'context' => $context,
                'relateduserid' => $answer->userid,
                'other' => [
                    'optionid' => $optionid,
                    'baid' => $baid,
                    'bookedslots' => $removedslots,
                    'remainingslots' => $newslots,
                    'slotcount' => count($removedslots),
                    'reason' => $reason,
                    'initiatedby' => $initiatedby,
                ],
            ]);
            $cancelevent->trigger();
        }

        // The move notification only makes sense when a slot actually moved in.
        if ($firemoved) {
            self::notify($answer, $slotdata, $oldstart, $oldend, $newstart, $newend, $reason, $initiatedby);
        }

        return [
            'newstart' => $newstart,
            'newend' => $newend,
            'slotcount' => count($newslots),
        ];
    }

    /**
     * Notify the student and any assigned teachers about the moved slot.
     *
     * @param stdClass $answer booking answer record
     * @param array $slotdata updated slot data
     * @param int $oldstart previous aggregated start timestamp
     * @param int $oldend previous aggregated end timestamp
     * @param int $newstart new start timestamp
     * @param int $newend new end timestamp
     * @param string $reason move reason
     * @param string $initiatedby 'manager' or 'self'
     * @return void
     */
    private static function notify(
        stdClass $answer,
        array $slotdata,
        int $oldstart,
        int $oldend,
        int $newstart,
        int $newend,
        string $reason,
        string $initiatedby
    ): void {
        global $DB;

        $user = $DB->get_record('user', ['id' => $answer->userid], '*', MUST_EXIST);

        // Same-day-collapsed ranges ("Wed, 24 June 2026, 10:00 AM - 11:00 AM").
        $newtime = dates_handler::prettify_optiondates_start_end($newstart, $newend, current_language());
        $oldtime = dates_handler::prettify_optiondates_start_end($oldstart, $oldend, current_language());

        if ($initiatedby === 'self') {
            $usersubject = get_string('slot_rebook_notification_user_subject', 'mod_booking');
            $userbody = get_string('slot_rebook_notification_user_body', 'mod_booking', (object)[
                'newtime' => $newtime,
            ]);
            $teachersubject = get_string('slot_rebook_notification_teacher_subject', 'mod_booking');
            $teacherbody = get_string('slot_rebook_notification_teacher_body', 'mod_booking', (object)[
                'participant' => fullname($user),
                'oldtime' => $oldtime,
                'newtime' => $newtime,
            ]);
        } else {
            $usersubject = get_string('slot_move_notification_subject', 'mod_booking');
            $userbody = get_string('slot_move_notification_body', 'mod_booking', (object)[
                'newtime' => $newtime,
                'reason' => $reason,
            ]);
            $teachersubject = $usersubject;
            $teacherbody = $userbody;
        }

        email_to_user($user, core_user::get_noreply_user(), $usersubject, $userbody);

        $teacherids = [];
        if (!empty($slotdata['teachers']) && is_array($slotdata['teachers'])) {
            $teacherids = array_values(array_unique(array_filter(array_map('intval', $slotdata['teachers']))));
        }

        if (!empty($teacherids)) {
            $teachers = $DB->get_records_list('user', 'id', $teacherids);
            foreach ($teachers as $teacher) {
                email_to_user($teacher, core_user::get_noreply_user(), $teachersubject, $teacherbody);
            }
        }
    }
}
