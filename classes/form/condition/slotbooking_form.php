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
use mod_booking\local\slotbooking\slot_availability;
use mod_booking\singleton_service;
use moodle_url;
use stdClass;

/**
 * Dynamic slot selection form.
 */
class slotbooking_form extends dynamic_form {
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

        if (!empty($cached) && !empty($cached->slot_selection)) {
            $data->slot_selection = (string)$cached->slot_selection;
        }
        if (!empty($cached) && isset($cached->slot_teacher_selection)) {
            $data->slot_teacher_selection = (string)$cached->slot_teacher_selection;
        }

        $selectedslotkeys = array_values(array_unique(array_filter(
            array_map('trim', explode(',', (string)$data->slot_selection)),
            function ($entry) {
                return $entry !== '';
            }
        )));
        $selectedslotset = array_fill_keys($selectedslotkeys, true);
        $openslots = self::get_open_slots($optionid, $userid);
        foreach ($openslots as $index => $slot) {
            $fieldname = self::slot_selection_checkbox_name($index);
            $data->{$fieldname} = !empty($selectedslotset[$slot['key']]) ? 1 : 0;
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

        $submittedvalues = (array)$data;
        $selectedfromcheckboxes = self::extract_selected_slot_entries_from_checkboxes($submittedvalues);

        $selectionvalue = '';
        if (!empty($selectedfromcheckboxes)) {
            $selectionvalue = implode(',', $selectedfromcheckboxes);
        } else {
            $selectionvalue = $data->slot_selection ?? '';
            if (is_array($selectionvalue)) {
                $selectionvalue = implode(',', array_filter(array_map('trim', $selectionvalue), function ($entry) {
                    return $entry !== '';
                }));
            } else {
                $selectionvalue = (string)$selectionvalue;
            }
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
        $maxslots = max(1, (int)($config->max_slots_per_user ?? 1));
        $teachersrequired = max(0, (int)($config->teachers_required ?? 0));
        $viewmode = in_array((string)($config->booking_interface ?? 'list'), ['list', 'calendar'], true)
            ? (string)$config->booking_interface
            : 'list';

        $openslots = self::get_open_slots($optionid, $userid);

        $mform->addElement('hidden', 'slot_max_selection', (string)$maxslots);
        $mform->setType('slot_max_selection', PARAM_INT);

        $mform->addElement('hidden', 'slot_teachers_required_count', (string)$teachersrequired);
        $mform->setType('slot_teachers_required_count', PARAM_INT);

        $mform->addElement('hidden', 'slot_teacher_selection', '');
        $mform->setType('slot_teacher_selection', PARAM_RAW_TRIMMED);

        $mform->addElement('hidden', 'slot_validation_error_target', 'slot_selection');
        $mform->setType('slot_validation_error_target', PARAM_ALPHANUMEXT);

        if (empty($openslots)) {
            $mform->addElement('static', 'slot_selection_info', '', get_string('slot_no_open_slots', 'mod_booking'));
            $mform->addElement('hidden', 'slot_selection', '');
            $mform->setType('slot_selection', PARAM_TEXT);
            $mform->addElement('hidden', 'slot_calendar_data', json_encode([]));
            $mform->setType('slot_calendar_data', PARAM_RAW_TRIMMED);
            return;
        }

        $mform->addElement('hidden', 'slot_calendar_data', json_encode($openslots));
        $mform->setType('slot_calendar_data', PARAM_RAW_TRIMMED);

        if ($viewmode === 'calendar') {
            $mform->addElement('hidden', 'slot_selection', '');
            $mform->setType('slot_selection', PARAM_TEXT);

            $mform->setDefault('slot_validation_error_target', 'slot_calendar_ui');

            $calendarcontainer = html_writer::div('', 'booking-slot-calendar-picker', [
                'data-region' => 'slot-calendar-picker',
            ]);
            $mform->addElement('static', 'slot_calendar_ui', get_string('slot_selection', 'mod_booking'), $calendarcontainer);
            return;
        }

        if ($maxslots > 1) {
            $mform->addElement('hidden', 'slot_selection', '');
            $mform->setType('slot_selection', PARAM_TEXT);

            if (!empty($openslots)) {
                $mform->setDefault('slot_validation_error_target', self::slot_selection_checkbox_name(0));
            }

            foreach ($openslots as $index => $slot) {
                $fieldname = self::slot_selection_checkbox_name($index);
                $label = $slot['daylabel'] . ' · ' . $slot['timelabel'];
                $checkbox = $mform->addElement('advcheckbox', $fieldname, '', $label);
                $mform->setType($fieldname, PARAM_INT);
                $checkbox->updateAttributes([
                    'data-slot-selection-checkbox' => '1',
                ]);
            }

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

        $maxslots = max(1, (int)($data['slot_max_selection'] ?? 1));
        $entries = self::extract_selected_slot_entries_from_checkboxes($data);
        if (empty($entries)) {
            $selectiondata = $data['slot_selection'] ?? '';
            if (is_array($selectiondata)) {
                $entries = array_filter(array_map('trim', $selectiondata), function ($entry) {
                    return $entry !== '';
                });
            } else {
                $selection = (string)$selectiondata;
                $entries = array_filter(array_map('trim', explode(',', $selection)), function ($entry) {
                    return $entry !== '';
                });
            }
        }

        if (empty($entries)) {
            $errors[$errortarget] = get_string('slot_error_selection_required', 'mod_booking');
            return $errors;
        }

        if (count($entries) > $maxslots) {
            $errors[$errortarget] = get_string('slot_error_selection_toomany', 'mod_booking', $maxslots);
            return $errors;
        }

        $optionid = (int)($data['id'] ?? 0);

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
                $evaluation = slot_availability::evaluate_slot_for_user($optionid, $start, $end, (int)($data['userid'] ?? 0));
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
                (int)($data['userid'] ?? 0),
                $selectedteachers
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
     * Build deterministic checkbox field name for a slot list entry.
     *
     * @param int $index slot index in the rendered open-slot list
     * @return string
     */
    private static function slot_selection_checkbox_name(int $index): string {
        return 'slot_selection_cb_' . $index;
    }

    /**
     * Return currently open slots with labels and teacher availability.
     *
     * @param int $optionid booking option id
     * @param int $userid user id
     * @return array<int, array{key:string,start:int,end:int,daylabel:string,timelabel:string,teachers:array}>
     */
    private static function get_open_slots(int $optionid, int $userid): array {
        $slots = slot_availability::get_slots_with_status($optionid, $userid);
        $openslots = [];

        foreach ($slots as $slot) {
            $slotstatus = (string)($slot['status'] ?? 'unavailable');
            if (!in_array($slotstatus, ['open', 'warning'], true)) {
                continue;
            }

            $start = (int)$slot['start'];
            $end = (int)$slot['end'];
            $daylabel = userdate($start, get_string('strftimedaydate', 'langconfig'));
            $key = $start . ':' . $end;
            $label = userdate($start, get_string('strftimetime', 'langconfig'))
                . ' - '
                . userdate($end, get_string('strftimetime', 'langconfig'));
            if ($slotstatus === 'warning') {
                $label .= ' (!)';
            }

            $openslots[] = [
                'key' => $key,
                'start' => $start,
                'end' => $end,
                'daylabel' => $daylabel,
                'timelabel' => $label,
                'teachers' => slot_availability::get_available_teachers_for_slot($optionid, $start, $end),
            ];
        }

        return $openslots;
    }

    /**
     * Extract selected slot entries from checkbox controls.
     *
     * @param array $formvalues submitted form values
     * @return array<int, string>
     */
    private static function extract_selected_slot_entries_from_checkboxes(array $formvalues): array {
        $calendarraw = (string)($formvalues['slot_calendar_data'] ?? '[]');
        $openslots = json_decode($calendarraw, true);
        if (!is_array($openslots)) {
            return [];
        }

        $entries = [];
        foreach ($openslots as $index => $slot) {
            if (!is_array($slot) || empty($slot['key'])) {
                continue;
            }

            $fieldname = self::slot_selection_checkbox_name((int)$index);
            if (empty($formvalues[$fieldname])) {
                continue;
            }

            $entries[] = (string)$slot['key'];
        }

        return array_values(array_unique($entries));
    }
}
