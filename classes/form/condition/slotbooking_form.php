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
 * Dynamic form used inside prepage modal for slot selection.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\form\condition;

use context;
use context_system;
use core_form\dynamic_form;
use html_writer;
use mod_booking\local\mobile\slotbookingstore;
use mod_booking\bo_availability\conditions\cancelmyself;
use mod_booking\local\slotbooking\slot_availability;
use mod_booking\local\slotbooking\slot_dto;
use mod_booking\singleton_service;
use moodle_url;
use stdClass;

/**
 * Dynamic slot selection form.
 */
class slotbooking_form extends dynamic_form {
    /** @var int step size for custom duration options (minutes) */
    private const CUSTOM_SLOT_DURATION_STEP_MINUTES = 15;

    /**
     * Context for dynamic submission.
     *
     * @return context
     */
    protected function get_context_for_dynamic_submission(): context {
        return context_system::instance();
    }

    /**
     * Permission check.
     *
     * @return void
     */
    protected function check_access_for_dynamic_submission(): void {
        require_capability('mod/booking:conditionforms', context_system::instance());
    }

    /**
     * Load cached selection into form.
     *
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {
        global $USER;

        $formdata = $this->_ajaxformdata;
        $optionid = (int)($formdata['id'] ?? 0);
        $userid = (int)($formdata['userid'] ?? $USER->id);

        $store = new slotbookingstore($userid, $optionid);
        $cached = $store->get_slotbooking_data();

        $data = new stdClass();
        $data->id = $optionid;
        $data->userid = $userid;
        $data->slot_selection = '';
        $data->slot_teacher_selection = '';
        $data->slot_custom_start = 0;
        $data->slot_custom_duration = 0;

        if (!empty($cached) && !empty($cached->slot_selection)) {
            $data->slot_selection = (string)$cached->slot_selection;
        }
        if (!empty($cached) && isset($cached->slot_teacher_selection)) {
            $data->slot_teacher_selection = (string)$cached->slot_teacher_selection;
        }

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $config = $settings->slotconfig ?? null;
        if ((string)($config->slot_type ?? 'fixed') === 'userdefined' && strpos((string)$data->slot_selection, ':') !== false) {
            [$start, $end] = array_map('intval', explode(':', (string)$data->slot_selection, 2));
            if ($start > 0 && $end > $start) {
                $data->slot_custom_start = $start;
                $data->slot_custom_duration = $end - $start;
            }
        }

        $this->set_data($data);
    }

    /**
     * Persist selected slot in cache.
     *
     * @return stdClass
     */
    public function process_dynamic_submission(): stdClass {
        $data = $this->get_data();

        $settings = singleton_service::get_instance_of_booking_option_settings((int)$data->id);
        $config = $settings->slotconfig ?? null;
        $slottype = (string)($config->slot_type ?? 'fixed');

        if ($slottype === 'userdefined') {
            $start = (int)($data->slot_custom_start ?? 0);
            $duration = (int)($data->slot_custom_duration ?? 0);
            $end = $start + $duration;
            $selectionvalue = ($start > 0 && $end > $start) ? ($start . ':' . $end) : '';

            $data->slot_selection = $selectionvalue;
            $data->slot_teacher_selection = json_encode([]);

            $store = new slotbookingstore((int)$data->userid, (int)$data->id);
            $store->set_slotbooking_data((object)[
                'slot_selection' => $selectionvalue,
                'slot_teacher_selection' => $data->slot_teacher_selection,
            ]);

            return $data;
        }

        $selectionvalue = $data->slot_selection ?? '';
        if (is_array($selectionvalue)) {
            $selectionvalue = implode(',', array_filter(array_map('trim', $selectionvalue), function ($entry) {
                return $entry !== '';
            }));
        } else {
            $selectionvalue = (string)$selectionvalue;
        }
        $data->slot_selection = $selectionvalue;

        $teachersrequired = max(0, (int)($data->slot_teachers_required_count ?? 0));
        $teacherselectionraw = (string)($data->slot_teacher_selection ?? '');
        $teacherselection = json_decode($teacherselectionraw, true);
        if (!is_array($teacherselection)) {
            $teacherselection = [];
        }

        $selectionkeys = array_values(array_unique(array_filter(
            array_map('trim', explode(',', $selectionvalue)),
            function ($entry) {
                return $entry !== '';
            }
        )));
        $selectionkeyset = array_fill_keys($selectionkeys, true);

        $normalizedteacherselection = [];
        if ($teachersrequired > 0) {
            foreach ($teacherselection as $slotkey => $teacherids) {
                if (!is_string($slotkey) || empty($selectionkeyset[$slotkey]) || !is_array($teacherids)) {
                    continue;
                }
                $teacherids = array_values(array_unique(array_filter(array_map('intval', $teacherids), function ($id) {
                    return $id > 0;
                })));
                if (!empty($teacherids)) {
                    $normalizedteacherselection[$slotkey] = $teacherids;
                }
            }
        }

        $data->slot_teacher_selection = json_encode($normalizedteacherselection);

        $store = new slotbookingstore((int)$data->userid, (int)$data->id);
        $store->set_slotbooking_data((object)[
            'slot_selection' => $selectionvalue,
            'slot_teacher_selection' => $data->slot_teacher_selection,
        ]);

        return $data;
    }

    /**
     * Whether the per-user slot cap applies when building this form's picker slots.
     *
     * The booking form applies it: a user at max_slots_per_user gets no bookable slots and the
     * definition explains why instead of rendering a dead picker. The update editor
     * (slotupdate_form) overrides this to true - its user is at the cap by definition (they hold
     * the booking being edited), and with the cap applied the inherited definition would replace
     * the whole editor with the max-slots-reached message.
     *
     * @return bool
     */
    protected function ignore_user_slot_cap(): bool {
        return false;
    }

    /**
     * Form definition.
     *
     * @return void
     */
    public function definition(): void {
        global $USER;

        $mform = $this->_form;
        $formdata = $this->_ajaxformdata;

        $optionid = (int)($formdata['id'] ?? 0);
        $userid = (int)($formdata['userid'] ?? $USER->id);

        $mform->addElement('hidden', 'id', $optionid);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('hidden', 'userid', $userid);
        $mform->setType('userid', PARAM_INT);

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $config = $settings->slotconfig ?? null;
        $showprices = !empty($settings->useprice) ? 1 : 0;
        $slottype = (string)($config->slot_type ?? 'fixed');
        $maxslots = max(1, (int)($config->max_slots_per_user ?? 1));
        $teachersrequired = max(0, (int)($config->teachers_required ?? 0));
        $viewmode = in_array((string)($config->booking_interface ?? 'list'), ['list', 'calendar'], true)
            ? (string)$config->booking_interface
            : 'list';

        if ($slottype === 'userdefined') {
            $teachersrequired = 0;
        }

        // Raw list of additional (merged-in) option ids from the slotbooking sidebar, before any
        // per-slot-type gating. The fixed-type branch below only merges in calendar view mode (see
        // its own comment); the userdefined branch always merges when this option is part of a
        // multi-option sidebar at all, since it has no alternate list/selectgroups fallback picker
        // whose "start:end" keys could collide across options.
        $rawadditionaloptionids = self::get_additional_option_ids($formdata);

        // Build the canonical picker DTO once. $pickerslots is embedded verbatim for the JS picker
        // (Stufe 2, WS-identical payload), while $openslots is the flat shape the server-side
        // selectgroups/empty-check consume. Both stay scoped to the primary option only - the
        // list/selectgroups fallback pickers below key each slot as "start:end" with no option
        // prefix, so merging additional options into them would silently collide or drop entries
        // between two options sharing a time range. Merging only happens for slot_calendar_data
        // below, and only in the calendar view mode.
        $pickerslots = $slottype === 'userdefined'
            ? []
            : slot_dto::build_picker_slots($optionid, $userid, $this->ignore_user_slot_cap());
        $openslots = self::to_open_slots($pickerslots);

        $mform->addElement('hidden', 'slot_max_selection', (string)$maxslots);
        $mform->setType('slot_max_selection', PARAM_INT);

        $mform->addElement('hidden', 'slot_teachers_required_count', (string)$teachersrequired);
        $mform->setType('slot_teachers_required_count', PARAM_INT);

        $mform->addElement('hidden', 'slot_teacher_selection', '');
        $mform->setType('slot_teacher_selection', PARAM_RAW_TRIMMED);

        $mform->addElement('hidden', 'slot_examiners_per_slot_label', get_string('slot_examiners_per_slot', 'mod_booking'));
        $mform->setType('slot_examiners_per_slot_label', PARAM_TEXT);

        $mform->addElement('hidden', 'slot_use_prices', (string)$showprices);
        $mform->setType('slot_use_prices', PARAM_INT);

        $timezone = \core_date::get_user_timezone($USER);
        $mform->addElement('hidden', 'slot_timezone', (string)$timezone);
        $mform->setType('slot_timezone', PARAM_TEXT);

        $mform->addElement('hidden', 'slot_validation_error_target', 'slot_selection');
        $mform->setType('slot_validation_error_target', PARAM_ALPHANUMEXT);

        if ($slottype === 'userdefined') {
            $mform->addElement('hidden', 'slot_selection', '');
            $mform->setType('slot_selection', PARAM_TEXT);

            $mform->addElement('hidden', 'slot_custom_start', 0);
            $mform->setType('slot_custom_start', PARAM_INT);

            // Unlike the other slot types, slot_custom_start is a hidden field and mform does not
            // render inline error decoration for hidden elements. Target the visible slot_calendar_ui
            // static element below instead (same as the calendar-view branch), so a server-side
            // validation error is actually visible instead of silently swallowed.
            $mform->setDefault('slot_validation_error_target', 'slot_calendar_ui');

            $durationoptions = self::get_custom_duration_options($config);
            $mform->addElement(
                'select',
                'slot_custom_duration',
                get_string('slot_custom_duration', 'mod_booking'),
                $durationoptions
            );
            $mform->setType('slot_custom_duration', PARAM_INT);
            $mform->setDefault('slot_custom_duration', self::get_default_custom_duration($config, $durationoptions));

            // Multi-option merge (from the slotbooking sidebar): each merged option gets its own
            // open-day entries (own opening hours/duration options/capacity/bookedranges), tagged
            // with optionid so the JS's sidebar can switch which one is active. Each day's
            // bookedranges stay scoped to that SAME option only (see get_custom_open_days()) - a
            // booking on one merged option must not appear on another merged option's timeline,
            // they are separate bookable things that merely happen to share this sidebar. This is
            // deliberately not gated on $viewmode (see the $rawadditionaloptionids comment above).
            $customdays = self::get_custom_open_days($optionid, $userid);
            foreach ($rawadditionaloptionids as $additionaloptionid) {
                $customdays = array_merge($customdays, self::get_custom_open_days($additionaloptionid, $userid));
            }

            $mform->addElement('hidden', 'slot_calendar_data', json_encode($customdays));
            $mform->setType('slot_calendar_data', PARAM_RAW_TRIMMED);

            $mform->addElement('hidden', 'slot_legend_mine_label', get_string('slot_legend_mine', 'mod_booking'));
            $mform->setType('slot_legend_mine_label', PARAM_TEXT);

            $mform->addElement('hidden', 'slot_legend_blocked_label', get_string('slot_legend_blocked', 'mod_booking'));
            $mform->setType('slot_legend_blocked_label', PARAM_TEXT);

            $calendarcontainer = html_writer::div('', 'booking-slot-calendar-picker', [
                'data-region' => 'slot-calendar-picker',
                'style' => 'flex:1 1 36rem; min-width:0; width:100%; max-width:100%;',
            ]);
            $customeditorcontainer = html_writer::div('', 'booking-slot-custom-editor', [
                'data-region' => 'slot-custom-editor',
                'style' => 'flex:1 1 22rem; min-width:0; width:100%; max-width:100%;',
            ]);

            $calendarwrapper = html_writer::div(
                $calendarcontainer . $customeditorcontainer,
                'd-flex flex-column flex-lg-row flex-wrap align-items-start gap-3',
                [
                    'style' => 'width:100%;',
                ]
            );
            $mform->addElement('static', 'slot_calendar_ui', get_string('slot_selection', 'mod_booking'), $calendarwrapper);

            return;
        }

        // Multi-option merge (from the slotbooking sidebar) only applies to the calendar view mode -
        // see the scoping note above.
        $additionaloptionids = $viewmode === 'calendar' ? $rawadditionaloptionids : [];
        $calendarslots = $pickerslots;
        foreach ($additionaloptionids as $additionaloptionid) {
            $calendarslots = array_merge($calendarslots, slot_dto::build_picker_slots($additionaloptionid, $userid));
        }

        // Stufe 2: embed the selectable-slot snapshot so the picker JS reads it from the form instead
        // of calling the get_slots webservice. Same field name as the userdefined custom-day calendar
        // so the JS reads a single hidden field; payload is byte-identical to what get_slots returns
        // for a single option, and additionally carries every merged-in option's slots when applicable.
        $mform->addElement('hidden', 'slot_calendar_data', json_encode($calendarslots));
        $mform->setType('slot_calendar_data', PARAM_RAW_TRIMMED);

        // Gate on $calendarslots (a superset of $openslots once merged) instead of $openslots alone,
        // so a primary option with no open slots of its own doesn't hide an otherwise-open merged
        // calendar.
        if (empty($calendarslots)) {
            $mform->addElement('static', 'slot_selection_info', '', get_string('slot_no_open_slots', 'mod_booking'));
            $mform->addElement('hidden', 'slot_selection', '');
            $mform->setType('slot_selection', PARAM_TEXT);
            return;
        }

        if (empty($openslots)) {
            $mform->addElement('static', 'slot_selection_info', '', get_string('slot_no_open_slots', 'mod_booking'));
            $mform->addElement('hidden', 'slot_selection', '');
            $mform->setType('slot_selection', PARAM_TEXT);
            return;
        }

        // to_open_slots() maps every picker slot through unchanged - including 'booked' ones, and
        // the 'unavailable' ones a user gets once they have used up max_slots_per_user - so neither
        // guard above can ever be empty for somebody who already holds a slot. Without this the
        // form renders a picker in which every single entry is unselectable, together with a
        // Continue button that can only fail validation, and says nothing about why.
        $hasbookableslots = false;
        foreach ($calendarslots as $calendarslot) {
            if (!empty($calendarslot['bookable'])) {
                $hasbookableslots = true;
                break;
            }
        }

        if (!$hasbookableslots) {
            // Distinguish the two reasons: the option genuinely has nothing open right now, versus
            // this particular user having taken all the slots they are allowed to hold.
            $nothingbookablemessage = slot_availability::has_remaining_slot_capacity($optionid, $userid)
                ? get_string('slot_no_open_slots', 'mod_booking')
                : get_string('slot_error_max_slots_reached', 'mod_booking');

            $mform->addElement('static', 'slot_selection_info', '', $nothingbookablemessage);
            $mform->addElement('hidden', 'slot_selection', '');
            $mform->setType('slot_selection', PARAM_TEXT);
            return;
        }

        if ($viewmode === 'calendar') {
            $mform->addElement('hidden', 'slot_selection', '');
            $mform->setType('slot_selection', PARAM_TEXT);

            $mform->setDefault('slot_validation_error_target', 'slot_calendar_ui');

            $calendarcontainer = html_writer::div('', 'booking-slot-calendar-picker', [
                'data-region' => 'slot-calendar-picker',
                'style' => 'flex:1 1 36rem; min-width:0; width:100%; max-width:100%;',
            ]);
            $fixededitorcontainer = html_writer::div('', 'booking-slot-fixed-editor', [
                'data-region' => 'slot-fixed-editor',
                'style' => 'flex:1 1 22rem; min-width:0; width:100%; max-width:100%;',
            ]);

            $calendarwrapper = html_writer::div(
                $calendarcontainer . $fixededitorcontainer,
                'd-flex flex-column flex-lg-row flex-wrap align-items-start gap-3',
                [
                    'style' => 'width:100%;',
                    'data-region' => 'slot-calendar-wrapper',
                ]
            );
            $mform->addElement('static', 'slot_calendar_ui', get_string('slot_selection', 'mod_booking'), $calendarwrapper);
            return;
        }

        if ($maxslots > 1) {
            $mform->addElement('hidden', 'slot_selection', '');
            $mform->setType('slot_selection', PARAM_TEXT);

            $mform->setDefault('slot_validation_error_target', 'slot_list_ui');

            $listcontainer = html_writer::div('', 'booking-slot-list-picker', [
                'data-region' => 'slot-list-picker',
            ]);
            $mform->addElement('static', 'slot_list_ui', get_string('slot_selection', 'mod_booking'), $listcontainer);

            return;
        }

        $options = [];
        foreach ($openslots as $slot) {
            if (!isset($options[$slot['daylabel']])) {
                $options[$slot['daylabel']] = [];
            }
            $options[$slot['daylabel']][$slot['key']] = $slot['timelabel'];
        }

        $mform->addElement('selectgroups', 'slot_selection', get_string('slot_selection', 'mod_booking'), $options);
        $mform->setType('slot_selection', PARAM_TEXT);
    }

    /**
     * Validation for dynamic submission.
     *
     * @param array $data form data
     * @param array $files files
     * @return array
     */
    public function validation($data, $files): array {
        $errors = [];
        $errortarget = (string)($data['slot_validation_error_target'] ?? 'slot_selection');
        if ($errortarget === '') {
            $errortarget = 'slot_selection';
        }

        $optionid = (int)($data['id'] ?? 0);
        $userid = (int)($data['userid'] ?? 0);
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $config = $settings->slotconfig ?? null;
        $slottype = (string)($config->slot_type ?? 'fixed');

        // Recomputed from $optionid's OWN config rather than trusting the submitted
        // slot_max_selection hidden field: that field only ever reflects the PRIMARY option's limit
        // (set once when the form first loads - see definition()). $optionid here already correctly
        // reflects the switched-to option (see setActiveOptionId in condition/slotBooking.js), so
        // deriving the cap from its own config keeps server-side validation in sync with whichever
        // option is actually being booked.
        $maxslots = max(1, (int)($config->max_slots_per_user ?? 1));

        // Re-validating a selection already persisted as the user's own answer (e.g. after it
        // was added to the shopping cart) must not count that answer as an occupant against
        // itself, or an already-booked slot looks "no longer available" to its own owner.
        // "Book again" (multiplebookings) lets a user hold more than one active answer for the
        // same option at once, so every one of them must be excluded, not just the first.
        $ownanswerids = slot_availability::get_active_answer_ids_for_user($optionid, $userid);

        if ($slottype === 'userdefined') {
            $start = (int)($data['slot_custom_start'] ?? 0);
            $duration = (int)($data['slot_custom_duration'] ?? 0);
            $durationoptions = self::get_custom_duration_options($config);
            $startintervalseconds = max(1, (int)($config->slot_start_interval_minutes ?? 30)) * MINSECS;

            if ($start <= 0 || $duration <= 0 || !array_key_exists($duration, $durationoptions)) {
                $errors[$errortarget] = get_string('slot_error_selection_required', 'mod_booking');
                return $errors;
            }

            $end = $start + $duration;
            $openingseconds = self::time_to_seconds((string)($config->opening_time ?? '08:00'));
            $daystart = strtotime('midnight', $start);
            $dayopening = $daystart + $openingseconds;
            if ($start < $dayopening || (($start - $dayopening) % $startintervalseconds) !== 0) {
                $errors[$errortarget] = get_string('slot_error_selected_unavailable', 'mod_booking');
                return $errors;
            }

            if (!slot_availability::is_within_slot_openings($optionid, $start, $end)) {
                $errors[$errortarget] = get_string('slot_error_selected_unavailable', 'mod_booking');
                return $errors;
            }

            // The slotbooking calendar/Continue button now stays available for the WHOLE life of a
            // slot option (see alreadybooked::get_description()'s slotconfig bypass) - this is the
            // place that actually enforces whether a new booking round is allowed at all. Slots the
            // user already owns don't count as "new" (has_capacity_for_selection() excludes them),
            // so re-validating an already-booked/cached selection never trips this - only a
            // genuinely additional slot beyond max_slots_per_user does. Without this, an exhausted
            // extra booking would sail through validation() (evaluate_slot_for_user() itself does
            // not check this at all) all the way to book_user_on_option() (booking_option.php),
            // which then silently no-ops instead of ever creating an answer - the user would see a
            // "successful" confirmation for a booking that never actually happened.
            if (!slot_availability::has_capacity_for_selection($optionid, $userid, [$start . ':' . $end])) {
                $errors[$errortarget] = get_string('slot_error_max_slots_reached', 'mod_booking');
                return $errors;
            }

            $evaluation = slot_availability::evaluate_slot_for_user(
                $optionid,
                $start,
                $end,
                $userid,
                excludeanswerids: $ownanswerids
            );
            if (empty($evaluation['bookable'])) {
                $errors[$errortarget] = (string)($evaluation['errormessage']
                    ?? get_string('slot_error_selected_unavailable', 'mod_booking'));
            }

            return $errors;
        }

        $selectiondata = $data['slot_selection'] ?? '';
        if (is_array($selectiondata)) {
            $entries = array_filter(array_map('trim', $selectiondata), function ($entry) {
                return $entry !== '';
            });
        } else {
            $entries = array_filter(array_map('trim', explode(',', (string)$selectiondata)), function ($entry) {
                return $entry !== '';
            });
        }

        if (empty($entries)) {
            $errors[$errortarget] = get_string('slot_error_selection_required', 'mod_booking');
            return $errors;
        }

        if (count($entries) > $maxslots) {
            $errors[$errortarget] = get_string('slot_error_selection_toomany', 'mod_booking', $maxslots);
            return $errors;
        }

        $parsedranges = [];
        foreach ($entries as $entry) {
            if (strpos($entry, ':') === false) {
                $errors[$errortarget] = get_string('slot_error_selection_required', 'mod_booking');
                return $errors;
            }

            [$start, $end] = array_map('intval', explode(':', $entry, 2));
            if ($start <= 0 || $end <= $start) {
                $errors[$errortarget] = get_string('slot_error_selection_required', 'mod_booking');
                return $errors;
            }

            $parsedranges[] = [$start, $end];
        }

        // Each entry is normally checked only against the option's *existing* bookings; two
        // ranges picked together in this same submission are not in the database yet, so that
        // check alone would not catch them overlapping each other.
        if (slot_availability::ranges_overlap_internally($parsedranges)) {
            $errors[$errortarget] = get_string('slot_error_selection_overlap', 'mod_booking');
            return $errors;
        }

        // Same capacity gate as the userdefined branch above - see its comment.
        if (!slot_availability::has_capacity_for_selection($optionid, $userid, $entries)) {
            $errors[$errortarget] = get_string('slot_error_max_slots_reached', 'mod_booking');
            return $errors;
        }

        $teachersrequired = max(0, (int)($data['slot_teachers_required_count'] ?? 0));
        $teacherselectionraw = (string)($data['slot_teacher_selection'] ?? '');
        $teacherselection = json_decode($teacherselectionraw, true);
        if (!is_array($teacherselection)) {
            $teacherselection = [];
        }

        $selectedset = array_fill_keys($entries, true);
        if ($teachersrequired <= 0) {
            foreach ($teacherselection as $slotkey => $teacherids) {
                if (!empty($selectedset[$slotkey]) && is_array($teacherids) && !empty($teacherids)) {
                    $errors[$errortarget] = get_string('slot_error_selection_required', 'mod_booking');
                    return $errors;
                }
            }

            foreach ($entries as $entry) {
                [$start, $end] = array_map('intval', explode(':', $entry, 2));
                $evaluation = slot_availability::evaluate_slot_for_user(
                    $optionid,
                    $start,
                    $end,
                    $userid,
                    excludeanswerids: $ownanswerids
                );
                if (empty($evaluation['bookable'])) {
                    $errors[$errortarget] = (string)($evaluation['errormessage']
                        ?? get_string('slot_error_selected_unavailable', 'mod_booking'));
                    return $errors;
                }
            }

            return $errors;
        }

        foreach ($entries as $entry) {
            $selectedteachers = [];
            if (!empty($teacherselection[$entry]) && is_array($teacherselection[$entry])) {
                $selectedteachers = array_values(array_unique(array_filter(
                    array_map('intval', $teacherselection[$entry]),
                    function ($id) {
                        return $id > 0;
                    }
                )));
            }

            if (count($selectedteachers) !== $teachersrequired) {
                $errors[$errortarget] = get_string('slot_error_teacher_required', 'mod_booking');
                return $errors;
            }

            [$start, $end] = array_map('intval', explode(':', $entry, 2));
            $evaluation = slot_availability::evaluate_slot_for_user(
                $optionid,
                $start,
                $end,
                $userid,
                $selectedteachers,
                excludeanswerids: $ownanswerids
            );
            if (empty($evaluation['bookable'])) {
                $errors[$errortarget] = (string)($evaluation['errormessage']
                    ?? get_string('slot_error_selected_unavailable', 'mod_booking'));
                return $errors;
            }
        }

        return $errors;
    }

    /**
     * Page URL for dynamic submission.
     *
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        return new moodle_url('/mod/booking/view.php');
    }

    /**
     * Build the open-slot list for an option/user by delegating to the canonical DTO.
     *
     * Single named entry point on top of slot_dto::build_picker_slots() + to_open_slots(),
     * returning the flat shape (status folded into timelabel) the server-side form consumes.
     *
     * @param int $optionid booking option id
     * @param int $userid user id
     *
     * @return array
     */
    private static function get_open_slots(int $optionid, int $userid): array {
        return self::to_open_slots(slot_dto::build_picker_slots($optionid, $userid));
    }

    /**
     * Map the canonical picker DTO list to the flat open-slot shape with labels and teacher availability.
     *
     * @param array $pickerslots picker slot DTOs from slot_dto::build_picker_slots()
     *
     * @return array
     *
     */
    private static function to_open_slots(array $pickerslots): array {
        // Derive the flat open-slot shape from the canonical slot DTO so the booking picker,
        // the calendar report and the move flow all share one source of truth. The status
        // suffix is folded back into timelabel.
        return array_map(static function (array $slot): array {
            return [
                'key' => $slot['key'],
                'start' => $slot['start'],
                'end' => $slot['end'],
                'status' => $slot['status'],
                'selectable' => $slot['selectable'],
                'daylabel' => $slot['daylabel'],
                'timelabel' => $slot['timelabel'] . $slot['statuslabel'],
                'teachers' => $slot['teachers'],
                'price' => $slot['price'],
                'currency' => $slot['currency'],
                'priceformatted' => $slot['priceformatted'],
            ];
        }, $pickerslots);
    }

    /**
     * Parse the additional option ids passed in from the multi-option slotbooking sidebar.
     *
     * Contract with the JS side (amd/src/condition/slotBooking.js): the DynamicForm's ajaxformdata
     * carries an "additionalids" entry - a JSON-encoded array of option ids - alongside the existing
     * "id"/"userid" entries, whenever the container has more than one option id (see the
     * data-optionids attribute on templates/condition/slotbooking.mustache).
     *
     * @param array $formdata dynamic form ajaxformdata
     * @return int[]
     */
    private static function get_additional_option_ids(array $formdata): array {
        $raw = (string)($formdata['additionalids'] ?? '');
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map('intval', $decoded),
            fn($id) => $id > 0
        )));
    }


    /**
     * Build allowed duration options for user-defined slots.
     *
     * @param object|null $config
     * @return array<int, string>
     */
    private static function get_custom_duration_options(?object $config): array {
        $maxminutes = max(1, (int)($config->slot_duration_minutes ?? 30));
        $minminutes = max(1, (int)($config->slot_interval_minutes ?? 60));
        $maxdays = max(1, (int)($config->slot_max_days_per_slot ?? 1));

        $lowerminutes = min($minminutes, $maxminutes);
        $upperminutes = min(max($minminutes, $maxminutes), $maxdays * DAYMINS);
        $step = max(1, (int)($config->slot_duration_step_minutes ?? self::CUSTOM_SLOT_DURATION_STEP_MINUTES));

        $options = [];
        for ($minutes = $lowerminutes; $minutes <= $upperminutes; $minutes += $step) {
            $seconds = $minutes * MINSECS;
            $options[$seconds] = format_time($seconds);
        }

        // If the step doesn't evenly divide the min/max range, still offer the configured
        // maximum as the last option so it always remains selectable.
        $uppersecondskey = $upperminutes * MINSECS;
        if (!empty($options) && !array_key_exists($uppersecondskey, $options)) {
            $options[$uppersecondskey] = format_time($uppersecondskey);
        }

        if (empty($options)) {
            $seconds = $lowerminutes * MINSECS;
            $options[$seconds] = format_time($seconds);
        }

        return $options;
    }

    /**
     * Resolve default custom slot duration in seconds.
     * @param object|null $config
     * @param array $options
     *
     * @return int
     *
     */
    private static function get_default_custom_duration(?object $config, array $options): int {
        $configured = max(1, (int)($config->slot_duration_minutes ?? 30)) * MINSECS;
        if (array_key_exists($configured, $options)) {
            return $configured;
        }

        return (int)array_key_first($options);
    }

    /**
     * Build available day entries for user-defined slot selection calendar.
     *
     * @param int $optionid
     * @param int $userid
     *
     * @return array
     *
     */
    private static function get_custom_open_days(int $optionid, int $userid): array {
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $config = $settings->slotconfig ?? null;
        if (empty($config)) {
            return [];
        }

        $openingseconds = self::time_to_seconds((string)($config->opening_time ?? '08:00'));
        $closingseconds = self::time_to_seconds((string)($config->closing_time ?? '18:00'));
        if ($closingseconds <= $openingseconds) {
            return [];
        }

        $alloweddays = self::parse_days_of_week((string)($config->days_of_week ?? '1,2,3,4,5'));
        if (empty($alloweddays)) {
            return [];
        }

        $rangestart = time();
        if (!empty($config->valid_from)) {
            $rangestart = max($rangestart, (int)$config->valid_from);
        }
        $rangeend = strtotime('+90 days', $rangestart);
        if (!empty($config->valid_until)) {
            $rangeend = min($rangeend, (int)$config->valid_until + DAYSECS);
        }

        $capacity = max(1, (int)($config->max_participants_per_slot ?? 1));
        $mindurationseconds = max(1, (int)($config->slot_interval_minutes ?? 60)) * MINSECS;
        $stepseconds = max(1, (int)($config->slot_start_interval_minutes ?? 30)) * MINSECS;
        // Each merged option can allow different durations (own min/max/step) - carried on every
        // day entry so the JS can rebuild the duration <select> for whichever option the user
        // actually picks in the multi-option chooser below, instead of leaving it stuck on
        // whatever the PRIMARY option's own duration options happened to be.
        $durationoptions = self::get_custom_duration_options($config);
        $defaultduration = self::get_default_custom_duration($config, $durationoptions);

        // Whether THIS option can currently be cancelled by the user, and its own booking page -
        // both per-option (not per-day), computed once here. Lets the JS show a clickable "Booked"
        // link on the user's own booked range in the timeline, mirroring
        // slot_dto::build_picker_slots()'s manageurl for the fixed-type grid.
        $cancelable = !cancelmyself::apply_coolingoff_period($settings, $userid)
            && !(new cancelmyself())->is_available($settings, $userid);
        $manageurl = (new moodle_url(
            '/mod/booking/optionview.php',
            ['cmid' => $settings->cmid, 'optionid' => $optionid]
        ))->out(false);

        $days = [];
        $daycursor = strtotime('midnight', $rangestart);

        while ($daycursor < $rangeend) {
            $dayofweek = (int)date('N', $daycursor);
            if (!in_array($dayofweek, $alloweddays, true)) {
                $daycursor += DAYSECS;
                continue;
            }

            $openfrom = $daycursor + $openingseconds;
            $openuntil = $daycursor + $closingseconds;
            if ($openuntil <= $openfrom) {
                $daycursor += DAYSECS;
                continue;
            }

            if ($openuntil <= $rangestart || $openfrom >= $rangeend) {
                $daycursor += DAYSECS;
                continue;
            }

            $openfrom = max($openfrom, $rangestart);
            $openuntil = min($openuntil, $rangeend);

            // The $rangestart ("now", for today) can land on this day's openfrom with arbitrary
            // seconds (whatever second time() returned) instead of a clean interval boundary.
            // validation() requires every submitted start to be exactly
            // "$dayopening + k * startintervalseconds" (see the userdefined branch there) - an
            // unaligned openfrom here would make the very FIRST selectable moment of TODAY always
            // fail that check, even though evaluate_slot_for_user() itself has no problem with it.
            // Snap forward to the next valid boundary.
            $dayopeningforalignment = $daycursor + $openingseconds;
            if ($openfrom > $dayopeningforalignment) {
                $stepsfromopening = (int)ceil(($openfrom - $dayopeningforalignment) / $stepseconds);
                $openfrom = $dayopeningforalignment + ($stepsfromopening * $stepseconds);
            }

            if ($openuntil <= $openfrom) {
                $daycursor += DAYSECS;
                continue;
            }

            $bookable = false;
            for ($candidate = $openfrom; $candidate + $mindurationseconds <= $openuntil; $candidate += $stepseconds) {
                $evaluation = slot_availability::evaluate_slot_for_user(
                    $optionid,
                    $candidate,
                    $candidate + $mindurationseconds,
                    $userid
                );
                if (!empty($evaluation['bookable'])) {
                    $bookable = true;
                    break;
                }
            }

            if (!$bookable) {
                $daycursor += DAYSECS;
                continue;
            }

            $bookedranges = slot_availability::get_booked_ranges_for_day(
                $optionid,
                $daycursor,
                $daycursor + DAYSECS,
                $userid
            );
            $bookedranges = array_map(static function (array $range) use ($cancelable, $manageurl): array {
                if (!empty($range['mine'])) {
                    $range['manageurl'] = $cancelable ? $manageurl : '';
                }
                return $range;
            }, $bookedranges);
            $days[] = [
                // Prefixed with optionid: with a merged sidebar, two different options can each
                // have an entry for the same calendar date - the day-grouping in the JS keys purely
                // by calendar date (see toDayKey()), so a shared key here is not itself a problem,
                // but keeping it unique avoids surprises in any code that maps by 'key'.
                'key' => $optionid . ':' . $daycursor,
                'optionid' => $optionid,
                'optionname' => format_string($settings->text ?? ''),
                'start' => $daycursor,
                'end' => $daycursor + DAYSECS,
                'daylabel' => slot_dto::day_label($daycursor),
                'timelabel' => slot_dto::time_range_label($openfrom, $openuntil),
                'openfrom' => $openfrom,
                'openuntil' => $openuntil,
                'durationoptions' => $durationoptions,
                'defaultduration' => $defaultduration,
                'startintervalminutes' => max(1, (int)($config->slot_start_interval_minutes ?? 30)),
                'bookable' => 1,
                'capacity' => $capacity,
                'bookedranges' => $bookedranges,
            ];

            $daycursor += DAYSECS;
        }

        return $days;
    }

    /**
     * Parse HH:MM to seconds from midnight.
     *
     * @param string $time
     * @return int
     */
    private static function time_to_seconds(string $time): int {
        if (!preg_match('/^(\d{2}):(\d{2})$/', $time, $matches)) {
            return 0;
        }

        $hours = (int)$matches[1];
        $minutes = (int)$matches[2];
        if ($hours < 0 || $hours > 23 || $minutes < 0 || $minutes > 59) {
            return 0;
        }

        return ($hours * HOURSECS) + ($minutes * MINSECS);
    }

    /**
     * Parse CSV day list (1..7).
     *
     * @param string $dayscsv
     * @return int[]
     */
    private static function parse_days_of_week(string $dayscsv): array {
        $parts = array_filter(array_map('trim', explode(',', $dayscsv)), static function (string $value): bool {
            return $value !== '';
        });

        $days = [];
        foreach ($parts as $part) {
            $day = (int)$part;
            if ($day >= 1 && $day <= 7) {
                $days[] = $day;
            }
        }

        return array_values(array_unique($days));
    }
}
