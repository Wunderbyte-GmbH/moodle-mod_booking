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
 * "Update booking" DynamicForm: move / cancel / change a booked answer's slots in one editor.
 *
 * Inherits the slot-selection input layer from slotbooking_form (picker, embedded slot snapshot,
 * hidden sync, live price) and only replaces the submit half: instead of staging a new booking it
 * routes the change through slot_update_service (see
 * docs/blueprints/SLOTBOOKING_UPDATE_BOOKING_BLUEPRINT.md). The current slots are pre-selected;
 * deselecting cancels, switching blocks moves/changes. A hidden confirm flag drives a two-pass
 * confirmation (first pass returns the itemised summary, second pass commits).
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\form\condition;

use context_module;
use context_system;
use mod_booking\local\slotbooking\slot_change_policy;
use mod_booking\local\slotbooking\slot_mover;
use mod_booking\local\slotbooking\slot_update_service;
use mod_booking\singleton_service;
use moodle_exception;
use stdClass;

/**
 * Self-service / manager "update booking" form built on the booking form's input layer.
 */
class slotupdate_form extends slotbooking_form {
    /**
     * Permission check: self-service owner (opt-in + ownership) or a manager (moveslots/updatebooking).
     *
     * The same ownership/capability guard slot_update_service enforces, so the form entry is gated
     * exactly like the service that commits the change.
     *
     * @return void
     */
    protected function check_access_for_dynamic_submission(): void {
        global $USER, $DB;

        self::require_booking_lib();
        require_capability('mod/booking:conditionforms', context_system::instance());

        $formdata = $this->_ajaxformdata;
        $optionid = (int)($formdata['id'] ?? 0);
        $baid = (int)($formdata['baid'] ?? 0);
        $selfservice = !empty($formdata['selfservice']);

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $context = context_module::instance($settings->cmid);

        if ($selfservice) {
            require_capability('mod/booking:moveslotsself', $context);
            $answer = $DB->get_record('booking_answers', ['id' => $baid, 'optionid' => $optionid], '*', MUST_EXIST);
            if (
                (int)$answer->userid !== (int)$USER->id
                || !slot_mover::self_rebooking_allowed($optionid, $answer)
            ) {
                throw new moodle_exception('slot_rebook_not_allowed', 'mod_booking');
            }
        } else if (
            !has_capability('mod/booking:moveslots', $context)
            && !has_capability('mod/booking:updatebooking', $context)
        ) {
            require_capability('mod/booking:moveslots', $context);
        }
    }

    /**
     * Form definition: the update-specific hidden fields plus the inherited input layer.
     *
     * The hidden fields are added BEFORE the parent definition because the parent returns early in
     * several view-mode branches; adding them first guarantees they exist in every branch.
     *
     * @return void
     */
    public function definition(): void {
        $mform = $this->_form;

        $mform->addElement('hidden', 'baid', 0);
        $mform->setType('baid', PARAM_INT);

        $mform->addElement('hidden', 'selfservice', 0);
        $mform->setType('selfservice', PARAM_INT);

        // Baseline: the answer's current slots (pre-selected) and its locked (deadline-fixed) slots.
        $mform->addElement('hidden', 'slot_update_current', '');
        $mform->setType('slot_update_current', PARAM_RAW_TRIMMED);

        $mform->addElement('hidden', 'slot_update_locked', '');
        $mform->setType('slot_update_locked', PARAM_RAW_TRIMMED);

        // Two-pass confirm flag: 0 = first pass (summary only), 1 = confirmed commit.
        $mform->addElement('hidden', 'slot_update_confirmed', 0);
        $mform->setType('slot_update_confirmed', PARAM_INT);

        parent::definition();

        // Optional change reason (stored on the answer, surfaced in the move/cancel notifications).
        // Added after the inherited picker so it renders at the bottom, next to the submit button.
        $mform->addElement('text', 'slot_update_reason', get_string('slot_rebook_reason', 'mod_booking'));
        $mform->setType('slot_update_reason', PARAM_TEXT);
    }

    /**
     * Pre-fill the form with the answer's current slots (pre-selected) plus the locked baseline.
     *
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {
        global $USER;

        self::require_booking_lib();
        $formdata = $this->_ajaxformdata;
        $optionid = (int)($formdata['id'] ?? 0);
        $baid = (int)($formdata['baid'] ?? 0);
        $userid = (int)($formdata['userid'] ?? $USER->id);
        $selfservice = !empty($formdata['selfservice']);

        $ctx = slot_mover::get_move_context($optionid, $baid);
        $currentkeys = array_values($ctx['currentslotkeys']);
        $lockedkeys = array_map(
            static fn(array $s): string => $s['start'] . ':' . $s['end'],
            slot_change_policy::partition_slots($ctx['answer'])['locked']
        );

        $data = new stdClass();
        $data->id = $optionid;
        $data->userid = $userid;
        $data->baid = $baid;
        $data->selfservice = $selfservice ? 1 : 0;
        // Current slots start out selected; deselecting them cancels, switching moves/changes.
        $data->slot_selection = implode(',', $currentkeys);
        $data->slot_teacher_selection = '';
        $data->slot_update_current = json_encode($currentkeys);
        $data->slot_update_locked = json_encode(array_values($lockedkeys));
        $data->slot_update_confirmed = 0;
        $data->slot_update_reason = '';
        // Override the inherited booking snapshot (build_picker_slots) with the move targets: open
        // slots PLUS the answer's current slots, which are always included and selectable here. The
        // booking snapshot marks the user's own booked slots as non-selectable / may omit a full one,
        // so the current slots could not be deselected or swapped — the editor needs them selectable.
        $data->slot_calendar_data = json_encode(array_values($ctx['targetslots']));

        $this->set_data($data);
    }

    /**
     * Validation: hard, blocking checks only (selection rules, availability, deadline/locked for
     * self-service). The soft price-change acknowledgement is the two-pass confirm in
     * process_dynamic_submission, not a validation error.
     *
     * @param array $data form data
     * @param array $files files
     * @return array
     */
    public function validation($data, $files): array {
        self::require_booking_lib();
        $errors = [];
        $errortarget = (string)($data['slot_validation_error_target'] ?? 'slot_selection');
        if ($errortarget === '') {
            $errortarget = 'slot_selection';
        }

        $optionid = (int)($data['id'] ?? 0);
        $baid = (int)($data['baid'] ?? 0);
        $userid = (int)($data['userid'] ?? 0);
        $actor = empty($data['selfservice']) ? 'manager' : 'self';
        $newkeys = self::extract_keys($data['slot_selection'] ?? '');

        try {
            $plan = slot_update_service::plan($optionid, $baid, $userid, $newkeys, $actor);
        } catch (moodle_exception $e) {
            $errors[$errortarget] = $e->getMessage();
            return $errors;
        }

        if (!empty($plan['errors'])) {
            $errors[$errortarget] = get_string((string)reset($plan['errors']), 'mod_booking');
        }

        return $errors;
    }

    /**
     * Submit: route the change through slot_update_service. Two passes — the first returns the
     * itemised plan and asks for confirmation without committing; the second (confirmed) commits and
     * returns the routing mode so the JS can redirect to checkout (cart) or close the prepage.
     *
     * @return stdClass
     */
    public function process_dynamic_submission(): stdClass {
        self::require_booking_lib();
        $data = $this->get_data();

        $optionid = (int)$data->id;
        $baid = (int)$data->baid;
        $userid = (int)$data->userid;
        $actor = empty($data->selfservice) ? 'manager' : 'self';
        $confirmed = !empty($data->slot_update_confirmed);
        $reason = trim((string)($data->slot_update_reason ?? ''));
        $newkeys = self::extract_keys($data->slot_selection ?? '');

        $plan = slot_update_service::plan($optionid, $baid, $userid, $newkeys, $actor);

        // Nothing changed: report a no-op so the JS can just close without a confirm round-trip.
        if (empty($plan['removed']) && empty($plan['added'])) {
            return (object)['status' => 'nochange'];
        }

        if (!$confirmed) {
            // First pass: hand the itemised diff back to the JS for the confirm summary; do not commit.
            return (object)[
                'status' => 'needsconfirm',
                'route' => (string)$plan['route'],
                'netdelta' => (float)$plan['netdelta'],
                'ismove' => (bool)$plan['ismove'],
                'removed' => array_values($plan['removed']),
                'added' => array_values($plan['added']),
                'slotcount' => (int)$plan['slotcount'],
            ];
        }

        // Second pass: commit and route by the net delta (direct / refund / cart / cancel).
        $outcome = slot_update_service::apply($optionid, $baid, $userid, $newkeys, $reason, $actor);

        return (object)[
            'status' => 'committed',
            'mode' => (string)$outcome['mode'],
            'pricedelta' => (float)$outcome['pricedelta'],
            'moveid' => (int)$outcome['moveid'],
            'slotcount' => (int)$outcome['slotcount'],
        ];
    }

    /**
     * Ensure the module's lib.php (slot status constants like MOD_BOOKING_STATUSPARAM_BOOKED) is
     * loaded. The dynamic-form WS context autoloads the form class but not the plugin lib, unlike
     * the page/webservice callers of the same services.
     *
     * @return void
     */
    private static function require_booking_lib(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/booking/lib.php');
    }

    /**
     * Normalise a slot selection (comma-separated string or array) into a clean, unique key list.
     *
     * @param string|array $selection
     * @return array<int, string>
     */
    private static function extract_keys($selection): array {
        if (is_array($selection)) {
            $parts = $selection;
        } else {
            $parts = explode(',', (string)$selection);
        }

        return array_values(array_unique(array_filter(
            array_map('trim', array_map('strval', $parts)),
            static fn(string $key): bool => $key !== ''
        )));
    }
}
