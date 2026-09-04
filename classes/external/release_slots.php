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
 * Webservice that releases (cancels) individual booked slots for the participant themselves.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_booking\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_booking\local\slotbooking\slot_mover;
use mod_booking\local\slotbooking\slot_update_service;
use mod_booking\permissions;
use mod_booking\singleton_service;
use moodle_exception;

/**
 * External service: self-service partial cancellation of booked slots.
 */
class release_slots extends external_api {
    /**
     * Parameter description.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'optionid' => new external_value(PARAM_INT, 'booking option id'),
            'baid' => new external_value(PARAM_INT, 'booking answer id'),
            'releaseslots' => new external_value(
                PARAM_RAW,
                'JSON list of slot keys to release; keys are cast to int timestamps server-side'
            ),
            'reason' => new external_value(PARAM_TEXT, 'cancellation reason', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Release the selected booked slots.
     *
     * @param int $optionid booking option id
     * @param int $baid booking answer id
     * @param string $releaseslots JSON encoded list of slot keys to release
     * @param string $reason cancellation reason
     * @return array{success: bool, released: int, remaining: int, cancelled: bool}
     */
    public static function execute(
        int $optionid,
        int $baid,
        string $releaseslots,
        string $reason = ''
    ): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'optionid' => $optionid,
            'baid' => $baid,
            'releaseslots' => $releaseslots,
            'reason' => $reason,
        ]);

        $settings = singleton_service::get_instance_of_booking_option_settings($params['optionid']);
        // Users with mod/booking:choose may release their booked slots without course
        // access (e.g. booked via shortcode lists); slot_mover::release_self() itself
        // enforces the ownership and cancellation rules.
        permissions::validate_context_for_booking((int)($settings->cmid ?? 0));

        $keys = json_decode($params['releaseslots'], true);
        if (!is_array($keys)) {
            $keys = array_filter(array_map('trim', explode(',', (string)$params['releaseslots'])));
        }
        $keys = array_values(array_unique(array_filter(
            array_map('strval', $keys),
            static fn(string $key): bool => trim($key) !== ''
        )));

        // The service is told what REMAINS, not what goes. Deriving the remainder here also catches
        // keys that do not belong to this answer: they would otherwise vanish silently and turn the
        // update into a no-op (or, with an equal count, into a move).
        $ctx = slot_mover::get_move_context($params['optionid'], $params['baid']);
        $currentkeys = $ctx['currentslotkeys'];
        $releaseset = array_fill_keys($keys, true);
        $remaining = array_values(array_filter(
            $currentkeys,
            static fn(string $key): bool => empty($releaseset[$key])
        ));
        if (empty($keys) || count($remaining) + count($keys) !== count($currentkeys)) {
            throw new moodle_exception('slot_rebook_not_allowed', 'mod_booking');
        }

        // Giving up the LAST slot of a purchased booking is a full cancellation: that belongs to the
        // shopping cart's cancel flow (consumed quota, cancellation fee, cancelled purchase), which
        // the regular cancel button offers. A partial refund here would return the full price and
        // skip those rules. Unpurchased bookings (free, or booked without the cart) still cancel here.
        if (empty($remaining) && slot_mover::purchased_via_cart($params['optionid'], (int)$USER->id)) {
            throw new moodle_exception('slot_release_use_cancel', 'mod_booking');
        }

        // Releasing slots is a partial cancellation: route it through the same service the update
        // editor uses, so the given-up slots are refunded as cart credit instead of being dropped
        // for free. slot_mover::release_self() stays the mechanic underneath and keeps enforcing
        // ownership, the opt-in, the cancellation policy and the per-slot change deadline.
        $outcome = slot_update_service::apply(
            $params['optionid'],
            $params['baid'],
            (int)$USER->id,
            $remaining,
            $params['reason']
        );

        return [
            'success' => true,
            'released' => count($currentkeys) - (int)$outcome['slotcount'],
            'remaining' => (int)$outcome['slotcount'],
            'cancelled' => (int)$outcome['slotcount'] === 0,
            'pricedelta' => (float)$outcome['pricedelta'],
        ];
    }

    /**
     * Return value description.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'whether the release succeeded'),
            'released' => new external_value(PARAM_INT, 'number of slots released'),
            'remaining' => new external_value(PARAM_INT, 'number of slots still booked'),
            'cancelled' => new external_value(PARAM_BOOL, 'whether the whole booking was cancelled'),
            'pricedelta' => new external_value(PARAM_FLOAT, 'net price change; negative when slots were refunded'),
        ]);
    }
}
