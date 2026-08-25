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
 * Webservice persisting and server-validating a user's slot selection.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_booking\external;

use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_booking\local\mobile\slotbookingstore;
use mod_booking\local\slotbooking\slot_availability;
use mod_booking\local\slotbooking\slot_price;
use mod_booking\permissions;
use mod_booking\singleton_service;

/**
 * External service: validate and cache a slot selection before booking.
 */
class save_slot_selection extends external_api {
    /**
     * Parameter description.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'optionid' => new external_value(PARAM_INT, 'booking option id'),
            'userid' => new external_value(PARAM_INT, 'user id', VALUE_DEFAULT, 0),
            'selection' => new external_value(
                PARAM_RAW,
                'JSON list of slot keys ("start:end"); keys are cast to int timestamps server-side'
            ),
            'teacherselection' => new external_value(
                PARAM_RAW,
                'JSON encoded map of slot key to teacher id list; all ids are cast to int server-side',
                VALUE_DEFAULT,
                '{}'
            ),
            'persistselection' => new external_value(
                PARAM_BOOL,
                'Persist the valid selection to the slot store (default). The live pre-validation
                 feedback in the booking form passes false: it fires debounced on every selection
                 change, so its late responses would otherwise race the booking commit and rewrite
                 the store AFTER the commit already cleared it - leaving the previous selection
                 preselected on the next booking pass.',
                VALUE_DEFAULT,
                true
            ),
        ]);
    }

    /**
     * Validate the selection and, if valid, persist it to the slot store.
     *
     * @param int $optionid booking option id
     * @param int $userid user id (0 = current user)
     * @param string $selection JSON encoded list of slot keys
     * @param string $teacherselection JSON encoded teacher map
     * @param bool $persistselection persist to the slot store; false = validate/price only
     * @return array{valid: bool, errors: string, price: float}
     */
    public static function execute(
        int $optionid,
        int $userid,
        string $selection,
        string $teacherselection = '{}',
        bool $persistselection = true
    ): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'optionid' => $optionid,
            'userid' => $userid,
            'selection' => $selection,
            'teacherselection' => $teacherselection,
            'persistselection' => $persistselection,
        ]);

        $optionid = $params['optionid'];
        $userid = $params['userid'] ?: (int)$USER->id;

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        // Users with mod/booking:choose may use the slot picker without course access (e.g. via shortcode lists).
        permissions::validate_context_for_booking((int)($settings->cmid ?? 0));
        require_capability('mod/booking:conditionforms', context_system::instance());
        // Validating and caching a selection for another user needs the book for others (or cashier) rights.
        \mod_booking\form\condition\customform_form::require_userid_access($userid, $optionid);

        $keys = self::normalise_keys($params['selection']);
        $teachermap = json_decode($params['teacherselection'], true);
        if (!is_array($teachermap)) {
            $teachermap = [];
        }

        $config = $settings->slotconfig ?? null;
        $maxslots = max(1, (int)($config->max_slots_per_user ?? 1));
        $teachersrequired = slot_availability::get_teachers_required($optionid);

        $errors = [];
        $normalizedteachers = [];
        $slots = [];

        // A user can already hold up to max_slots_per_user answers for this option from an earlier
        // purchase. The per-slot bookability check further down deliberately excludes the user's
        // OWN existing answers (so they can still view/re-edit a pending selection), so it alone
        // can't catch "already at capacity, trying to buy one more" - without this check, the
        // actual add-to-cart call downstream (shopping_cart::add_item_to_cart(), see
        // bo_info::load_pre_booking_page()) silently declines to add anything, and the user ends up
        // redirected to an empty cart with no explanation.
        if (!slot_availability::has_remaining_slot_capacity($optionid, $userid)) {
            $errors['slot_selection'] = get_string('slot_error_max_slots_reached', 'mod_booking');
        }

        if (count($keys) > $maxslots) {
            $errors['slot_selection'] = get_string('slot_error_selection_toomany', 'mod_booking');
        }

        $parsedranges = [];
        foreach ($keys as $key) {
            [$start, $end] = array_map('intval', array_pad(explode(':', $key, 2), 2, 0));
            if ($end > $start) {
                $parsedranges[] = [$start, $end];
            }
        }
        if (slot_availability::ranges_overlap_internally($parsedranges)) {
            $errors['slot_selection'] = get_string('slot_error_selection_overlap', 'mod_booking');
        }

        // A selection can already be (part of) the user's own persisted answer(s) - e.g. this
        // webservice also re-validates the cached selection once on load, and "book again"
        // (multiplebookings) can leave more than one active answer for this option. Without
        // excluding all of them, a slot the user already holds is counted as an occupant against
        // itself and wrongly reported as unavailable.
        $ownanswerids = slot_availability::get_active_answer_ids_for_user($optionid, $userid);

        // A user who already holds a (non-cancelled) answer for this option can only pick keys
        // that either are already their own or still fit within remaining capacity
        // (max_slots_per_user) - has_capacity_for_selection() excludes already-owned keys from
        // counting as "new", so re-validating an already-booked/cached selection never trips this.
        // Checked here too (not just slotbooking_form::validation()) so the live preview this
        // webservice powers (see slotBooking.js liveValidate()) surfaces the same "max slots
        // reached" message the instant an otherwise-bookable slot is clicked, instead of only after
        // a full form submit round-trip.
        if (!slot_availability::has_capacity_for_selection($optionid, $userid, $keys)) {
            $errors['slot_selection'] = get_string('slot_error_max_slots_reached', 'mod_booking');
        }

        // Once the too-many/overlap checks above have already rejected this selection, skip the
        // per-slot bookability loop below entirely - an otherwise-bookable slot would silently
        // overwrite the more specific error already set above (both write to the same
        // 'slot_selection' key) with a vaguer, misleading one.
        foreach (empty($errors) ? $keys : [] as $key) {
            [$start, $end] = array_map('intval', array_pad(explode(':', $key, 2), 2, 0));
            if ($end <= $start) {
                $errors['slot_selection'] = get_string('slot_error_selection_required', 'mod_booking');
                continue;
            }

            $selectedteachers = [];
            if ($teachersrequired > 0) {
                $selectedteachers = array_values(array_unique(array_filter(
                    array_map('intval', (array)($teachermap[$key] ?? [])),
                    static fn(int $id): bool => $id > 0
                )));
                if (count($selectedteachers) !== $teachersrequired) {
                    $errors['slot_selection'] = get_string('slot_error_selection_required', 'mod_booking');
                    continue;
                }
                $normalizedteachers[$key] = $selectedteachers;
            }

            $evaluation = slot_availability::evaluate_slot_for_user(
                $optionid,
                $start,
                $end,
                $userid,
                $selectedteachers,
                excludeanswerids: $ownanswerids
            );
            if (empty($evaluation['bookable'])) {
                $errors['slot_selection'] = get_string('slot_error_selected_unavailable', 'mod_booking');
                continue;
            }

            $slots[] = ['start' => $start, 'end' => $end];
        }

        $price = 0.0;
        if (empty($errors) && !empty($slots)) {
            if (!empty($params['persistselection'])) {
                $store = new slotbookingstore($userid, $optionid);
                $store->set_slotbooking_data((object)[
                    'slot_selection' => implode(',', $keys),
                    'slot_teacher_selection' => json_encode($normalizedteachers),
                ]);
            }
            $price = slot_price::calculate_price($optionid, count($slots), $userid, $slots);
        }

        return [
            'valid' => empty($errors),
            'errors' => json_encode($errors),
            'price' => $price,
        ];
    }

    /**
     * Decode the selection payload into a clean, unique list of slot keys.
     *
     * @param string $selection JSON encoded list or comma separated string
     * @return string[]
     */
    private static function normalise_keys(string $selection): array {
        $keys = json_decode($selection, true);
        if (!is_array($keys)) {
            $keys = explode(',', $selection);
        }

        return array_values(array_unique(array_filter(
            array_map('trim', array_map('strval', $keys)),
            static fn(string $key): bool => $key !== ''
        )));
    }

    /**
     * Return value description.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'valid' => new external_value(PARAM_BOOL, 'whether the selection is valid and was persisted'),
            'errors' => new external_value(PARAM_RAW, 'JSON encoded map of validation errors'),
            'price' => new external_value(PARAM_FLOAT, 'total price for the selected slots'),
        ]);
    }
}
