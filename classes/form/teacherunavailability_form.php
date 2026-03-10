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
 * Moodle form for teacher slot unavailability entries.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\form;

use context;
use context_module;
use core_form\dynamic_form;
use mod_booking\option\dates_handler;
use mod_booking\semester;
use mod_booking\singleton_service;
use moodle_url;
use stdClass;

/**
 * Form to add teacher unavailability block.
 */
class teacherunavailability_form extends dynamic_form {
    /**
     * Get effective semester id from option or booking settings.
     *
     * @param int $optionid
     * @param int $cmid
     * @return int
     */
    private function get_effective_semesterid(int $optionid, int $cmid = 0): int {
        if ($optionid <= 0) {
            if ($cmid > 0) {
                $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);
                return (int)($bookingsettings->semesterid ?? 0);
            }
            return 0;
        }

        $optionsettings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $optionsemesterid = (int)($optionsettings->semesterid ?? 0);
        if ($optionsemesterid > 0) {
            return $optionsemesterid;
        }

        $bookingid = (int)($optionsettings->bookingid ?? 0);
        if ($bookingid <= 0 && $cmid > 0) {
            $bookingoption = singleton_service::get_instance_of_booking_option($cmid, $optionid);
            $bookingid = (int)($bookingoption->bookingid ?? 0);
        }
        if ($bookingid <= 0) {
            if ($cmid > 0) {
                $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);
                return (int)($bookingsettings->semesterid ?? 0);
            }
            return 0;
        }

        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_bookingid($bookingid);
        return (int)($bookingsettings->semesterid ?? 0);
    }

    /**
     * Get all submitted entry indices.
     *
     * @param array $submitted
     * @param int $fallbackcounter
     * @return array<int>
     */
    private function get_submitted_entry_indices(array $submitted, int $fallbackcounter = 0): array {
        $indices = [];
        foreach ($submitted as $key => $value) {
            if (preg_match('/^unavailability_id_(\d+)$/', (string)$key, $matches)) {
                $indices[] = (int)$matches[1];
            } else if (
                preg_match('/^unavailable_from_(\d+)(?:\[|$)/', (string)$key, $matches)
                || preg_match('/^unavailable_until_(\d+)(?:\[|$)/', (string)$key, $matches)
            ) {
                $indices[] = (int)$matches[1];
            }
        }

        if (empty($indices) && $fallbackcounter > 0) {
            for ($index = 1; $index <= $fallbackcounter; $index++) {
                $indices[] = $index;
            }
        }

        $indices = array_values(array_unique($indices));
        sort($indices);

        return $indices;
    }

    /**
     * Add one unavailability entry as collapsible card.
     *
     * @param \MoodleQuickForm $mform
     * @param int $index
     * @param array{id:int, from:int, until:int, reason:string} $entry
     * @param bool $expanded
     * @return void
     */
    private function add_entry_as_collapsible(\MoodleQuickForm $mform, int $index, array $entry, bool $expanded): void {
        global $OUTPUT;

        $from = (int)$entry['from'];
        $until = (int)$entry['until'];
        if ($from <= 0) {
            $from = time();
        }
        if ($until <= $from) {
            $until = $from + HOURSECS;
        }

        $headername = dates_handler::prettify_optiondates_start_end($from, $until, current_language());
        $headerid = 'booking_unavailability_' . $index;
        $collapseid = 'booking_unavailability_collapse' . $index;
        $accordionid = 'accordion_optionid_' . $index;

        $headerdata = [
            'headername' => $headername,
            'headerid' => $headerid,
            'collapseid' => $collapseid,
            'accordionid' => $accordionid,
            'expanded' => $expanded,
        ];

        $mform->addElement('html', $OUTPUT->render_from_template('mod_booking/option/option_collapsible_open', $headerdata));

        $mform->addElement('hidden', 'unavailability_id_' . $index, (int)$entry['id']);
        $mform->setType('unavailability_id_' . $index, PARAM_INT);

        $mform->addElement(
            'date_time_selector',
            'unavailable_from_' . $index,
            get_string('from', 'mod_booking')
        );
        $mform->setType('unavailable_from_' . $index, PARAM_INT);

        $mform->addElement(
            'date_time_selector',
            'unavailable_until_' . $index,
            get_string('to', 'mod_booking')
        );
        $mform->setType('unavailable_until_' . $index, PARAM_INT);

        $mform->addElement('text', 'reason_' . $index, get_string('reason', 'mod_booking'), ['size' => 40]);
        $mform->setType('reason_' . $index, PARAM_TEXT);

        $deletebuttonname = 'deleteunavailability_' . $index;
        $mform->registerNoSubmitButton($deletebuttonname);
        $mform->addElement('submit', $deletebuttonname, get_string('delete', 'mod_booking'), [
            'data-action' => $deletebuttonname,
        ]);

        $mform->addElement('html', $OUTPUT->render_from_template('mod_booking/option/option_collapsible_close', []));
    }

    /**
     * Build entries from incoming form state if present, otherwise from DB.
     *
     * @return array<int, array{id:int, from:int, until:int, reason:string}>
     */
    private function get_entries_for_form(): array {
        global $DB;

        $date = (int)($this->_ajaxformdata['date'] ?? 0);
        if ($date <= 0) {
            $date = time();
        }

        $weekstart = strtotime('monday this week', $date);
        if ($weekstart === false) {
            $weekstart = strtotime('monday this week');
        }
        $entries = [];
        $indices = [];
        foreach ((array)$this->_ajaxformdata as $key => $value) {
            if (preg_match('/^unavailability_id_(\d+)$/', (string)$key, $matches)) {
                $indices[] = (int)$matches[1];
            }
        }

        if (!empty($indices)) {
            sort($indices);
            foreach ($indices as $index) {
                $entries[] = [
                    'id' => (int)($this->_ajaxformdata['unavailability_id_' . $index] ?? 0),
                    'from' => $this->to_timestamp($this->_ajaxformdata['unavailable_from_' . $index] ?? 0),
                    'until' => $this->to_timestamp($this->_ajaxformdata['unavailable_until_' . $index] ?? 0),
                    'reason' => (string)($this->_ajaxformdata['reason_' . $index] ?? ''),
                ];
            }
        } else {
            $optionid = (int)($this->_ajaxformdata['optionid'] ?? 0);
            $teacherid = (int)($this->_ajaxformdata['teacherid'] ?? 0);

            $records = $DB->get_records(
                'booking_teacher_unavailability',
                [
                    'optionid' => $optionid,
                    'teacherid' => $teacherid,
                ],
                'unavailable_from ASC'
            );
            foreach ($records as $record) {
                $entries[] = [
                    'id' => (int)$record->id,
                    'from' => (int)$record->unavailable_from,
                    'until' => (int)$record->unavailable_until,
                    'reason' => (string)$record->reason,
                ];
            }
        }

        if (!empty($this->_ajaxformdata['adddatebutton']) || !empty($this->_ajaxformdata['addunavailabilitybutton'])) {
            $entries[] = [
                'id' => 0,
                'from' => (int)$weekstart + (8 * HOURSECS),
                'until' => (int)$weekstart + (9 * HOURSECS),
                'reason' => '',
            ];
        }

        if (!empty($this->_ajaxformdata['addoptiondateseries'])) {
            $chooseperiod = (int)($this->_ajaxformdata['chooseperiod'] ?? 0);
            $seriesstring = trim((string)($this->_ajaxformdata['reoccurringdatestring'] ?? ''));

            if (
                $chooseperiod > 0
                && $seriesstring !== ''
                && dates_handler::reoccurring_datestring_is_correct($seriesstring)
            ) {
                $series = dates_handler::get_optiondate_series($chooseperiod, $seriesstring);
                foreach ((array)($series['dates'] ?? []) as $seriesdate) {
                    $entries[] = [
                        'id' => 0,
                        'from' => (int)($seriesdate->starttimestamp ?? 0),
                        'until' => (int)($seriesdate->endtimestamp ?? 0),
                        'reason' => '',
                    ];
                }
            }
        }

        foreach ((array)$this->_ajaxformdata as $key => $value) {
            if (preg_match('/^deleteunavailability_(\d+)$/', (string)$key, $matches)) {
                $index = (int)$matches[1] - 1;
                if (isset($entries[$index])) {
                    unset($entries[$index]);
                }
            }
        }

        return array_values($entries);
    }

    /**
     * Register clicked no-submit buttons so processing remains valid after dynamic row changes.
     *
     * @param \MoodleQuickForm $mform
     * @return void
     */
    private function register_clicked_nosubmit_buttons(\MoodleQuickForm $mform): void {
        if (!empty($this->_ajaxformdata['adddatebutton']) || !empty($this->_ajaxformdata['addunavailabilitybutton'])) {
            $mform->registerNoSubmitButton('adddatebutton');
            $mform->registerNoSubmitButton('addunavailabilitybutton');
        }
        if (!empty($this->_ajaxformdata['addoptiondateseries'])) {
            $mform->registerNoSubmitButton('addoptiondateseries');
        }

        foreach ((array)$this->_ajaxformdata as $key => $value) {
            if (preg_match('/^deleteunavailability_\d+$/', (string)$key)) {
                $mform->registerNoSubmitButton((string)$key);
            }
        }
    }

    /**
     * Convert date selector value to timestamp.
     *
     * @param mixed $value
     * @return int
     */
    private function to_timestamp($value): int {
        if (is_numeric($value)) {
            return (int)$value;
        }

        if (is_array($value)) {
            $year = $this->extract_int_value($value['year'] ?? 0);
            $month = $this->extract_int_value($value['month'] ?? 0);
            $day = $this->extract_int_value($value['day'] ?? 0);
            $hour = $this->extract_int_value($value['hour'] ?? 0);
            $minute = $this->extract_int_value($value['minute'] ?? 0);
            if ($year > 0 && $month > 0 && $day > 0) {
                return make_timestamp($year, $month, $day, $hour, $minute);
            }
        }

        return 0;
    }

    /**
     * Extract first integer value from scalar or nested selector arrays.
     *
     * @param mixed $value
     * @return int
     */
    private function extract_int_value($value): int {
        if (is_array($value)) {
            $first = reset($value);
            if ($first === false && empty($value)) {
                return 0;
            }
            return $this->extract_int_value($first);
        }

        if (is_numeric($value)) {
            return (int)$value;
        }

        return 0;
    }

    /**
     * Dynamic submission context.
     *
     * @return context
     */
    protected function get_context_for_dynamic_submission(): context {
        $cmid = (int)($this->_ajaxformdata['id'] ?? 0);
        return context_module::instance($cmid);
    }

    /**
     * Dynamic permission check.
     *
     * @return void
     */
    protected function check_access_for_dynamic_submission(): void {
        global $USER, $CFG;

        require_once($CFG->dirroot . '/mod/booking/locallib.php');

        $cmid = (int)($this->_ajaxformdata['id'] ?? 0);
        $optionid = (int)($this->_ajaxformdata['optionid'] ?? 0);
        $teacherid = (int)($this->_ajaxformdata['teacherid'] ?? 0);
        if ($teacherid <= 0) {
            $teacherid = (int)$USER->id;
        }

        $context = context_module::instance($cmid);
        $bookingoption = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $isteacherofoption = booking_check_if_teacher($bookingoption->settings);
        $canmanageunavailability = is_siteadmin()
            || has_capability('mod/booking:manageslotunavailability', $context)
            || has_capability('mod/booking:updatebooking', $context);

        if (!$canmanageunavailability && !$isteacherofoption) {
            require_capability('mod/booking:manageslotunavailability', $context);
        }

        if (!$canmanageunavailability && $teacherid !== (int)$USER->id) {
            throw new \moodle_exception('nopermissions', 'error', '', null, get_string('slot_error_editownonly', 'mod_booking'));
        }
    }

    /**
     * Preload form defaults.
     *
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {
        global $USER;

        $cmid = (int)($this->_ajaxformdata['id'] ?? 0);
        $optionid = (int)($this->_ajaxformdata['optionid'] ?? 0);
        $teacherid = (int)($this->_ajaxformdata['teacherid'] ?? 0);
        $date = (int)($this->_ajaxformdata['date'] ?? 0);

        if ($teacherid <= 0) {
            $teacherid = (int)$USER->id;
        }
        if ($date <= 0) {
            $date = time();
        }

        $data = new stdClass();
        $data->id = $cmid;
        $data->optionid = $optionid;
        $data->teacherid = $teacherid;
        $data->date = $date;

        $effectivesemesterid = $this->get_effective_semesterid($optionid, $cmid);
        if ($effectivesemesterid > 0) {
            $data->chooseperiod = (int)($this->_ajaxformdata['chooseperiod'] ?? $effectivesemesterid);
            $data->reoccurringdatestring = (string)($this->_ajaxformdata['reoccurringdatestring'] ?? '');
        }

        $entries = $this->get_entries_for_form();
        $counter = 0;
        foreach ($entries as $entry) {
            $counter++;
            $data->{'unavailability_id_' . $counter} = (int)$entry['id'];
            $data->{'unavailable_from_' . $counter} = (int)$entry['from'];
            $data->{'unavailable_until_' . $counter} = (int)$entry['until'];
            $data->{'reason_' . $counter} = (string)$entry['reason'];
        }
        $data->unavailability_counter = $counter;

        $this->set_data($data);
    }

    /**
     * Process dynamic submission.
     *
     * @return stdClass
     */
    public function process_dynamic_submission(): stdClass {
        global $DB;

        $data = $this->get_data();
        if (empty($data)) {
            $response = new stdClass();
            $response->saved = false;
            return $response;
        }

        $submitted = (array)$data;

        $cmid = (int)($submitted['id'] ?? 0);
        $optionid = (int)($submitted['optionid'] ?? 0);
        $teacherid = (int)($submitted['teacherid'] ?? 0);
        $date = (int)($submitted['date'] ?? 0);

        $entries = [];
        $indices = $this->get_submitted_entry_indices($submitted, (int)($submitted['unavailability_counter'] ?? 0));

        foreach ($indices as $index) {
            $entries[] = [
                'id' => (int)($submitted['unavailability_id_' . $index] ?? 0),
                'from' => $this->to_timestamp($submitted['unavailable_from_' . $index] ?? 0),
                'until' => $this->to_timestamp($submitted['unavailable_until_' . $index] ?? 0),
                'reason' => (string)($submitted['reason_' . $index] ?? ''),
            ];
        }

        $transaction = $DB->start_delegated_transaction();

        $DB->delete_records('booking_teacher_unavailability', [
            'optionid' => $optionid,
            'teacherid' => $teacherid,
        ]);

        $now = time();
        foreach ($entries as $entry) {
            $from = (int)$entry['from'];
            $until = (int)$entry['until'];

            $record = (object)[
                'optionid' => $optionid,
                'teacherid' => $teacherid,
                'unavailable_from' => $from,
                'unavailable_until' => $until,
                'reason' => (string)$entry['reason'],
                'timecreated' => $now,
            ];

            $DB->insert_record('booking_teacher_unavailability', $record);
        }

        $transaction->allow_commit();

        $response = new stdClass();
        $response->id = $cmid;
        $response->optionid = $optionid;
        $response->teacherid = $teacherid;
        $response->date = $date;
        $response->saved = true;

        return $response;
    }

    /**
     * Dynamic form page URL.
     *
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        return new moodle_url('/mod/booking/teacherunavailability.php');
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

        $mform->addElement('hidden', 'id', (int)($formdata['id'] ?? 0));
        $mform->setType('id', PARAM_INT);

        $mform->addElement('hidden', 'optionid', (int)($formdata['optionid'] ?? 0));
        $mform->setType('optionid', PARAM_INT);

        $teacherid = (int)($formdata['teacherid'] ?? 0);
        if ($teacherid <= 0) {
            $teacherid = (int)$USER->id;
        }
        $mform->addElement('hidden', 'teacherid', $teacherid);
        $mform->setType('teacherid', PARAM_INT);

        $mform->addElement('hidden', 'date', (int)($formdata['date'] ?? 0));
        $mform->setType('date', PARAM_INT);

        $this->register_clicked_nosubmit_buttons($mform);

        $cmid = (int)($formdata['id'] ?? 0);
        $optionid = (int)($formdata['optionid'] ?? 0);
        $effectivesemesterid = $this->get_effective_semesterid($optionid, $cmid);
        if ($effectivesemesterid > 0) {
            $semestersarray = semester::get_semesters_id_name_array();
            $mform->addElement(
                'autocomplete',
                'chooseperiod',
                get_string('chooseperiod', 'mod_booking'),
                $semestersarray,
                ['tags' => false, 'multiple' => false]
            );
            $mform->setType('chooseperiod', PARAM_INT);
            $mform->setDefault('chooseperiod', $effectivesemesterid);
            $mform->addHelpButton('chooseperiod', 'chooseperiod', 'mod_booking');

            $mform->addElement(
                'text',
                'reoccurringdatestring',
                get_string('reoccurringdatestring', 'mod_booking'),
                ['onkeypress' => 'return event.keyCode != 13;']
            );
            $mform->setType('reoccurringdatestring', PARAM_TEXT);
            $mform->addHelpButton('reoccurringdatestring', 'reoccurringdatestring', 'mod_booking');

            $mform->registerNoSubmitButton('addoptiondateseries');
            $mform->addElement('submit', 'addoptiondateseries', get_string('addoptiondateseries', 'mod_booking'), [
                'data-action' => 'addoptiondateseries',
            ]);
        }

        $entries = $this->get_entries_for_form();
        $counter = 0;
        if (empty($entries)) {
            $mform->addElement('static', 'nodatesmessage', '', get_string('datenotset', 'mod_booking'));
        } else {
            $openaddedentry = !empty($formdata['adddatebutton']) || !empty($formdata['addunavailabilitybutton']);
            foreach ($entries as $entry) {
                $counter++;
                $expanded = $openaddedentry && ($counter === count($entries));
                $this->add_entry_as_collapsible($mform, $counter, $entry, $expanded);
            }
        }

        $mform->addElement('hidden', 'unavailability_counter', $counter);
        $mform->setType('unavailability_counter', PARAM_INT);

        $mform->registerNoSubmitButton('adddatebutton');
        $mform->addElement('submit', 'adddatebutton', get_string('adddatebutton', 'mod_booking'), [
            'data-action' => 'adddatebutton',
        ]);

        $this->add_action_buttons(true, get_string('savechanges'));
    }

    /**
     * Validation.
     *
     * @param array $data Form data
     * @param array $files Files
     * @return array
     */
    public function validation($data, $files): array {
        $errors = [];

        if (!empty($data['adddatebutton']) || !empty($data['addunavailabilitybutton'])) {
            return $errors;
        }
        if (!empty($data['addoptiondateseries'])) {
            if (empty($data['chooseperiod'])) {
                $errors['chooseperiod'] = get_string('required');
            }

            $seriesstring = trim((string)($data['reoccurringdatestring'] ?? ''));
            if ($seriesstring === '') {
                $errors['reoccurringdatestring'] = get_string('required');
            } else if (!dates_handler::reoccurring_datestring_is_correct($seriesstring)) {
                $errors['reoccurringdatestring'] = get_string('reoccurringdatestringerror', 'mod_booking');
            }

            return $errors;
        }
        foreach ((array)$data as $key => $value) {
            if (preg_match('/^deleteunavailability_\d+$/', (string)$key)) {
                return $errors;
            }
        }

        $indices = $this->get_submitted_entry_indices((array)$data, (int)($data['unavailability_counter'] ?? 0));
        foreach ($indices as $index) {
            $from = $this->to_timestamp($data['unavailable_from_' . $index] ?? 0);
            $until = $this->to_timestamp($data['unavailable_until_' . $index] ?? 0);
            if ($until <= $from) {
                $errors['unavailable_until_' . $index] = get_string('slot_error_validrange', 'mod_booking');
            }
        }

        return $errors;
    }
}
