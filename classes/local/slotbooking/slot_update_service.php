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
 * Form-independent service that applies a self-service "update booking" (move / cancel / change).
 *
 * This is the commit + routing engine behind the "Update booking" DynamicForm and the
 * moveslot.php / rebookslot.php host pages (see docs/blueprints/SLOTBOOKING_UPDATE_BOOKING_BLUEPRINT.md). It
 * computes the net price delta of the new slot selection against the user's current slots and
 * routes accordingly:
 *   - price-neutral  -> commit directly, no payment
 *   - downgrade (<0) -> commit directly and refund the difference as cart credit
 *   - upgrade (>0)   -> hold the target via a pending move + put the difference into the cart
 *                       (committed only on a successful checkout, service_provider 'moveslot')
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_booking\local\slotbooking;

use context_module;
use local_shopping_cart\shopping_cart;
use mod_booking\booking_option_settings;
use mod_booking\singleton_service;
use moodle_exception;

/**
 * Routes and commits a self-service slot update by its net price delta.
 */
class slot_update_service {
    /** @var float Price deltas below this absolute value are treated as price-neutral. */
    private const PRICE_EPSILON = 0.005;

    /**
     * Dry-run: describe what a new slot selection would do to the user's current booking, without
     * any side effect. This is the read side the "Update booking" DynamicForm uses for its live
     * price, its confirmation summary and its validation; the commit side (apply()) follows the
     * same routing. Access (ownership / opt-in / deadline) is enforced; selection-rule violations
     * are returned as `errors` (lang string ids) so the form can surface them rather than throw.
     *
     * Routing by net delta: empty selection -> 'cancel' (full booking cancellation); price-neutral
     * -> 'direct'; cheaper -> 'refund'; more expensive -> 'cart'.
     *
     * @param int $optionid booking option id
     * @param int $baid booking answer id
     * @param int $userid acting (owning) user id
     * @param array $newkeys target slot keys ("start:end") for the new selection
     * @param string $actor 'self' (participant, priced + deadline/locked rules) or 'manager'
     *               (capability-gated, price-agnostic, deadline/locked bypassed)
     * @return array the plan: currentkeys, lockedkeys, newkeys, kept, removed, added, netdelta,
     *               route, ismove, newstart, newend, slotcount, errors
     */
    public static function plan(int $optionid, int $baid, int $userid, array $newkeys, string $actor = 'self'): array {
        $ctx = slot_mover::get_move_context($optionid, $baid);
        $answer = $ctx['answer'];
        $isself = $actor !== 'manager';

        if (!$isself) {
            // Manager update: the moveslots/updatebooking capability replaces the ownership/opt-in
            // gate; the deadline/locked and price-routing rules below are self-service only.
            $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
            $context = context_module::instance($settings->cmid);
            if (
                !has_capability('mod/booking:moveslots', $context)
                && !has_capability('mod/booking:updatebooking', $context)
            ) {
                require_capability('mod/booking:moveslots', $context);
            }
        } else if (
            // Access guard: own, active booking, opt-in + at least one actionable slot.
            (int)$answer->userid !== $userid
            || (int)$answer->waitinglist !== MOD_BOOKING_STATUSPARAM_BOOKED
            || !slot_mover::self_rebooking_allowed($optionid, $answer)
        ) {
            throw new moodle_exception('slot_rebook_not_allowed', 'mod_booking');
        }

        $currentkeys = $ctx['currentslotkeys'];
        $currentslots = $ctx['currentslots'];
        $currentset = array_fill_keys($currentkeys, true);

        // Locked (deadline-fixed) current slots may neither be moved away nor cancelled.
        $lockedkeys = array_map(
            static fn(array $s): string => $s['start'] . ':' . $s['end'],
            slot_change_policy::partition_slots($answer)['locked']
        );
        $lockedset = array_fill_keys($lockedkeys, true);

        $newkeys = array_values(array_unique(array_filter(array_map('trim', $newkeys))));
        $newset = array_fill_keys($newkeys, true);

        $kept = $removed = $added = [];
        foreach ($currentkeys as $key) {
            if (!empty($newset[$key])) {
                $kept[] = $key;
            } else {
                $removed[] = $key;
            }
        }
        foreach ($newkeys as $key) {
            if (empty($currentset[$key])) {
                $added[] = $key;
            }
        }

        $errors = [];
        // D4: editing only -- the update may not grow the booking (adding is "Book another slot").
        if (count($newkeys) > count($currentkeys)) {
            $errors[] = 'slot_update_no_add';
        }
        // Locked slots cannot be given up (self-service only; a manager may override).
        if ($isself) {
            foreach ($removed as $key) {
                if (!empty($lockedset[$key])) {
                    $errors[] = 'slot_update_locked_kept';
                    break;
                }
            }
            // Given-up slots must still be actionable (deadline) — bypassed for managers.
            $offset = slot_change_policy::resolve_deadline_minutes($optionid);
            $now = time();
            foreach ($removed as $key) {
                [$start] = self::parse_key($key);
                if ($start > 0 && !slot_change_policy::slot_actionable($start, $offset, $now)) {
                    $errors[] = 'slot_rebook_slot_started';
                    break;
                }
            }
        }
        $newslots = [];
        foreach ($newkeys as $key) {
            [$start, $end] = self::parse_key($key);
            if ($start <= 0 || $end <= $start) {
                continue;
            }
            if (
                empty($currentset[$key])
                && !slot_availability::is_slot_available($optionid, $start, $end, (int)$answer->userid, [], $baid)
            ) {
                $errors[] = 'slot_update_unavailable';
            }
            $newslots[] = ['start' => $start, 'end' => $end];
        }
        usort($newslots, static fn(array $a, array $b): int => $a['start'] <=> $b['start']);

        $delta = target_price_policy::calculate_move_delta($optionid, $userid, $currentslots, $newslots);

        if (count($newkeys) === 0) {
            $route = 'cancel';
        } else if (!$isself) {
            // Manager updates are price-agnostic: they always commit directly (no refund/cart).
            $route = 'direct';
        } else if (abs($delta) < self::PRICE_EPSILON) {
            $route = 'direct';
        } else if ($delta < 0) {
            $route = 'refund';
        } else {
            $route = 'cart';
        }

        return [
            'currentkeys' => $currentkeys,
            'lockedkeys' => array_values($lockedkeys),
            'newkeys' => $newkeys,
            'kept' => $kept,
            'removed' => $removed,
            'added' => $added,
            'netdelta' => round($delta, 2),
            'route' => $route,
            // A "move" preserves the slot count but swaps at least one slot (vs. pure cancel/keep).
            'ismove' => count($newkeys) === count($currentkeys) && !empty($removed),
            'newstart' => $newslots ? (int)$newslots[0]['start'] : 0,
            'newend' => $newslots ? (int)$newslots[count($newslots) - 1]['end'] : 0,
            'slotcount' => count($newslots),
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /**
     * Parse a "start:end" slot key into integer [start, end] (both 0 on a malformed key).
     *
     * @param string $key
     * @return array{0:int,1:int}
     */
    private static function parse_key(string $key): array {
        $parts = explode(':', $key);
        if (count($parts) !== 2) {
            return [0, 0];
        }
        return [(int)$parts[0], (int)$parts[1]];
    }

    /**
     * Apply a self-service slot update (the new selection replaces the current slots) and route it
     * by the net price difference. Mirrors the routing previously inlined in the move_slot
     * webservice; ownership / opt-in / deadline guards live in the slot_mover helpers this calls.
     *
     * @param int $optionid booking option id
     * @param int $baid booking answer id
     * @param int $userid acting (owning) user id
     * @param array $keys target slot keys ("start:end") for the new selection
     * @param string $reason optional change reason
     * @param string $actor 'self' (priced routing) or 'manager' (price-agnostic direct)
     * @return array{mode: string, pricedelta: float, moveid: int, newstart: int, newend: int, slotcount: int}
     */
    public static function apply(
        int $optionid,
        int $baid,
        int $userid,
        array $keys,
        string $reason = '',
        string $actor = 'self'
    ): array {
        if ($actor === 'manager') {
            return self::apply_manager($optionid, $baid, $keys, $reason);
        }

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        $ctx = slot_mover::get_move_context($optionid, $baid);
        $currentkeys = $ctx['currentslotkeys'];
        $newkeys = array_values(array_unique(array_filter(array_map('trim', $keys))));
        $currentset = array_fill_keys($currentkeys, true);
        $hasadds = (bool) array_filter($newkeys, static fn(string $k): bool => empty($currentset[$k]));

        // Reduction / full cancellation (the selection only drops current slots, adds none):
        // cancel the given-up slots and refund the net difference. A pure reduction can never be
        // a net upgrade, so it never routes to the cart.
        if (count($newkeys) < count($currentkeys) && !$hasadds) {
            return self::apply_reduction($optionid, $baid, $userid, $newkeys, $ctx, $reason, $settings);
        }
        // Mixed shrink (drop some + swap others in one update): validate via plan, then route by the
        // net delta. A net upgrade is held in the cart and committed on checkout; otherwise it
        // commits now (move_self -> relaxed move_validated fires slotmoved + slotcancelled per change).
        if (count($newkeys) < count($currentkeys)) {
            $plan = self::plan($optionid, $baid, $userid, $keys);
            if (!empty($plan['errors'])) {
                throw new \moodle_exception(reset($plan['errors']), 'mod_booking');
            }
            $delta = (float)$plan['netdelta'];
            $range = [
                'newstart' => $plan['newstart'],
                'newend' => $plan['newend'],
                'slotcount' => $plan['slotcount'],
            ];

            if ($delta > self::PRICE_EPSILON) {
                // Net upgrade: hold the reduced + swapped selection, put the difference in the cart.
                $newslots = [];
                foreach ($newkeys as $key) {
                    [$start, $end] = self::parse_key($key);
                    if ($start > 0 && $end > $start) {
                        $newslots[] = ['start' => $start, 'end' => $end];
                    }
                }
                usort($newslots, static fn(array $a, array $b): int => $a['start'] <=> $b['start']);
                $moveid = slot_move_store::create_pending(
                    $optionid,
                    $baid,
                    $userid,
                    $newslots,
                    $ctx['currentslots'],
                    $delta,
                    shopping_cart::get_expirationtime()
                );
                shopping_cart::add_item_to_cart('mod_booking', 'moveslot', $optionid, $userid);
                return self::outcome('cart', $delta, $moveid, $range);
            }

            // Net neutral / cheaper: commit now and refund any difference.
            slot_mover::move_self($optionid, $baid, $keys, $reason);
            $mode = 'direct';
            if ($delta < -self::PRICE_EPSILON) {
                self::refund_to_cart(
                    $optionid,
                    $userid,
                    abs($delta),
                    get_string('slotmove_cartitem_title', 'mod_booking', $settings->get_title_with_prefix())
                );
                $mode = 'refund';
            }
            return self::outcome($mode, $delta, 0, $range);
        }
        if (count($newkeys) > count($currentkeys)) {
            throw new \moodle_exception('slot_update_no_add', 'mod_booking');
        }

        // Same-count move/swap: validate + resolve the target slots, then price the change.
        $resolved = slot_mover::resolve_self_target_slots($optionid, $baid, $keys);
        $delta = target_price_policy::calculate_move_delta(
            $optionid,
            $userid,
            $resolved['currentslots'],
            $resolved['newslots']
        );

        // Price-neutral move: commit directly, no payment.
        if (abs($delta) < self::PRICE_EPSILON) {
            $result = slot_mover::move_self($optionid, $baid, $keys, $reason);
            return self::outcome('direct', 0.0, 0, $result);
        }

        // Downgrade: commit directly and refund the difference as credit (no checkout). The refund
        // is a no-op when the slot was not purchased through the cart (e.g. a free/manual booking).
        if ($delta < 0) {
            $result = slot_mover::move_self($optionid, $baid, $keys, $reason);
            self::refund_to_cart(
                $optionid,
                $userid,
                abs($delta),
                get_string('slotmove_cartitem_title', 'mod_booking', $settings->get_title_with_prefix())
            );
            return self::outcome('refund', $delta, 0, $result);
        }

        // Upgrade: hold the target slot via a pending move and put the difference into the cart.
        // The move is committed only on a successful checkout (service_provider 'moveslot' area).
        $moveid = slot_move_store::create_pending(
            $optionid,
            $baid,
            $userid,
            $resolved['newslots'],
            $resolved['currentslots'],
            $delta,
            shopping_cart::get_expirationtime()
        );
        // The cart item is keyed by optionid (not moveid) so the shopping_cart ledger is
        // option-traceable; the pending move is resolved from (optionid, user) in the callbacks.
        shopping_cart::add_item_to_cart('mod_booking', 'moveslot', $optionid, $userid);

        return self::outcome('cart', $delta, $moveid, [
            'newstart' => $resolved['newstart'],
            'newend' => $resolved['newend'],
            'slotcount' => $resolved['slotcount'],
        ]);
    }

    /**
     * Issue a partial refund as cart credit, when the shopping_cart supports it.
     *
     * The refund is a no-op for slots that were never purchased through the cart, and is skipped
     * entirely when local_shopping_cart is absent or predates the add_partial_refund() API (e.g.
     * minimal CI environments). The routing mode is decided independently of this call.
     *
     * @param int $optionid booking option id
     * @param int $userid user receiving the credit
     * @param float $amount refund amount (positive)
     * @param string $itemtitle ledger line title
     * @return void
     */
    private static function refund_to_cart(int $optionid, int $userid, float $amount, string $itemtitle): void {
        if (!method_exists(shopping_cart::class, 'add_partial_refund')) {
            return;
        }
        shopping_cart::add_partial_refund('mod_booking', 'option', $optionid, $userid, $amount, $itemtitle);
    }

    /**
     * Commit a pure reduction (drop given-up slots, add none) and refund the net difference.
     * Reuses release_self: a partial cancellation fires the slotcancelled event; giving up the
     * last slot triggers the standard full-deletion path (user_delete_response, its own event).
     *
     * @param int $optionid
     * @param int $baid
     * @param int $userid
     * @param array $newkeys normalized remaining slot keys (empty for a full cancellation)
     * @param array $ctx move context (from slot_mover::get_move_context)
     * @param string $reason
     * @param booking_option_settings $settings booking option settings
     * @return array{mode: string, pricedelta: float, moveid: int, newstart: int, newend: int, slotcount: int}
     */
    private static function apply_reduction(
        int $optionid,
        int $baid,
        int $userid,
        array $newkeys,
        array $ctx,
        string $reason,
        booking_option_settings $settings
    ): array {
        $currentslots = $ctx['currentslots'];
        $newset = array_fill_keys($newkeys, true);

        // The slots being given up = current minus the remaining selection.
        $released = array_values(array_filter(
            $ctx['currentslotkeys'],
            static fn(string $k): bool => empty($newset[$k])
        ));

        $newslots = [];
        foreach ($newkeys as $key) {
            [$start, $end] = self::parse_key($key);
            if ($start > 0 && $end > $start) {
                $newslots[] = ['start' => $start, 'end' => $end];
            }
        }
        usort($newslots, static fn(array $a, array $b): int => $a['start'] <=> $b['start']);

        $delta = target_price_policy::calculate_move_delta($optionid, $userid, $currentslots, $newslots);

        slot_mover::release_self($optionid, $baid, $released, $reason);

        $mode = 'direct';
        if ($delta < -self::PRICE_EPSILON) {
            self::refund_to_cart(
                $optionid,
                $userid,
                abs($delta),
                get_string('slotmove_cartitem_title', 'mod_booking', $settings->get_title_with_prefix())
            );
            $mode = 'refund';
        }

        return self::outcome($mode, $delta, 0, [
            'newstart' => $newslots ? (int)$newslots[0]['start'] : 0,
            'newend' => $newslots ? (int)$newslots[count($newslots) - 1]['end'] : 0,
            'slotcount' => count($newslots),
        ]);
    }

    /**
     * Apply a manager update (price-agnostic, capability-gated). Managers may move, swap and reduce
     * another user's slots without payment; an empty selection is a full cancellation through the
     * standard deletion path. Growing the booking is rejected here too (edit-only).
     *
     * @param int $optionid booking option id
     * @param int $baid booking answer id
     * @param array $keys target slot keys ("start:end") for the new selection
     * @param string $reason optional change reason
     * @return array{mode: string, pricedelta: float, moveid: int, newstart: int, newend: int, slotcount: int}
     */
    private static function apply_manager(int $optionid, int $baid, array $keys, string $reason): array {
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $context = context_module::instance($settings->cmid);
        if (
            !has_capability('mod/booking:moveslots', $context)
            && !has_capability('mod/booking:updatebooking', $context)
        ) {
            require_capability('mod/booking:moveslots', $context);
        }

        $ctx = slot_mover::get_move_context($optionid, $baid);
        $newkeys = array_values(array_unique(array_filter(array_map('trim', $keys))));

        if (count($newkeys) > count($ctx['currentslotkeys'])) {
            throw new moodle_exception('slot_update_no_add', 'mod_booking');
        }

        // Full cancellation: drop the last slot through the standard deletion path.
        if (count($newkeys) === 0) {
            $answer = $ctx['answer'];
            $option = singleton_service::get_instance_of_booking_option((int)$settings->cmid, $optionid);
            $option->user_delete_response((int)$answer->userid);
            return self::outcome('cancel', 0.0, 0, ['newstart' => 0, 'newend' => 0, 'slotcount' => 0]);
        }

        // Move / swap / reduction: the manager move core (move_validated) handles all of these and
        // fires slotmoved / slotcancelled per change. No pricing, refund or cart involvement.
        $result = slot_mover::move($optionid, $baid, $newkeys, $reason);
        return self::outcome('direct', 0.0, 0, $result);
    }

    /**
     * Build the routing outcome array.
     *
     * @param string $mode 'direct' | 'refund' | 'cart' | 'cancel'
     * @param float $pricedelta net price difference of the update
     * @param int $moveid pending move id (cart mode) or 0
     * @param array $result the move result carrying newstart/newend/slotcount
     * @return array{mode: string, pricedelta: float, moveid: int, newstart: int, newend: int, slotcount: int}
     */
    private static function outcome(string $mode, float $pricedelta, int $moveid, array $result): array {
        return [
            'mode' => $mode,
            'pricedelta' => round($pricedelta, 2),
            'moveid' => $moveid,
            'newstart' => (int)($result['newstart'] ?? 0),
            'newend' => (int)($result['newend'] ?? 0),
            'slotcount' => (int)($result['slotcount'] ?? 0),
        ];
    }
}
