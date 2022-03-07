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

namespace mod_booking\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->libdir/formslib.php");

use context;
use context_user;
use core_form\dynamic_form;
use moodle_url;
use stdClass;

/**
 * Add semesters form.
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class semesters_form extends dynamic_form {

    /**
     * {@inheritdoc}
     * @see moodleform::definition()
     */
    public function definition() {
        global $DB;

        $mform = $this->_form;

        // Loop through already existing semesters.
        $semesters = $DB->get_records_sql("SELECT * FROM {booking_semesters}");
        $i = 1;
        foreach ($semesters as $semester) {

            // Use 2 digits, so we can have more than 9 semesters.
            $j = sprintf('%02d', $i);

            $mform->addElement('hidden', 'semesterid' . $j, $semester->id);
            $mform->setType('semesterid' . $j, PARAM_INT);

            $mform->addElement('text', 'semesteridentifier' . $j, get_string('semesteridentifier', 'booking') . ' ' . $i);
            $mform->setType('semesteridentifier' . $j, PARAM_TEXT);
            $mform->setDefault('semesteridentifier' . $j, $semester->identifier);
            $mform->addHelpButton('semesteridentifier' . $j, 'semesteridentifier', 'booking');
            $mform->disabledIf('semesteridentifier' . $j, 'semesterid' . $j, 'neq', '0');

            $mform->addElement('text', 'semestername' . $j, get_string('semestername', 'booking'));
            $mform->setType('semestername' . $j, PARAM_RAW);
            $mform->setDefault('semestername' . $j, $semester->name);
            $mform->addHelpButton('semestername' . $j, 'semestername', 'booking');
            $mform->disabledIf('semestername' . $j, 'deletesemester' . $j, 'checked');

            $mform->addElement('date_selector', 'semesterstart' . $j, get_string('semesterstart', 'booking'));
            $mform->addHelpButton('semesterstart' . $j, 'semesterstart', 'booking');
            $mform->setDefault('semesterstart' . $j, $semester->start);
            $mform->disabledIf('semesterstart' . $j, 'deletesemester' . $j, 'checked');

            $mform->addElement('date_selector', 'semesterend' . $j, get_string('semesterend', 'booking'));
            $mform->addHelpButton('semesterend' . $j, 'semesterend', 'booking');
            $mform->setDefault('semesterend' . $j, $semester->end);
            $mform->disabledIf('semesterend' . $j, 'deletesemester' . $j, 'checked');

            $mform->addElement('advcheckbox', 'deletesemester' . $j,
                get_string('deletesemester', 'booking'), null, null, [0, 1]);
            $mform->setDefault('deletesemester' . $j, 0);
            $mform->addHelpButton('deletesemester' . $j, 'deletesemester', 'booking');

            $i++;
        }

        // Now, if there are less than the maximum number of semesters allow adding additional ones.
        if (count($semesters) < MAX_SEMESTERS) {
            // Between 1 to MAX_SEMESTERS semesters are supported.
            $start = count($semesters) + 1;
            $this->add_semesters($mform, $start);
        }

        // Add "Save" and "Cancel" buttons.
        $this->add_action_buttons(true);
    }

    /**
     * Helper function to create form elements for adding semesters.
     *
     * @param MoodleQuickForm $mform
     * @param int $i if there already are existing semesters start with the succeeding number.
     */
    public function add_semesters($mform, $i = 1): void {

        // Use 2 digits, so we can have more than 9 semesters.
        $j = sprintf('%02d', $i);

        // Add checkbox to add first semester.
        $mform->addElement('checkbox', 'addsemester' . $j, get_string('addsemester', 'booking'));

        while ($i <= MAX_SEMESTERS) {

            // Use 2 digits, so we can have more than 9 semesters.
            $j = sprintf('%02d', $i);

            // New elements have a default semesterid of 0.
            $mform->addElement('hidden', 'semesterid' . $j, 0);
            $mform->setType('semesterid' . $j, PARAM_INT);

            $mform->addElement('text', 'semesteridentifier' . $j,
                get_string('semesteridentifier', 'booking') . ' ' . $i);
            $mform->setType('semesteridentifier' . $j, PARAM_TEXT);
            $mform->addHelpButton('semesteridentifier' . $j, 'semesteridentifier', 'booking');
            $mform->hideIf('semesteridentifier' . $j, 'addsemester' . $j, 'notchecked');
            $mform->disabledIf('semesteridentifier' . $j, 'deletesemester' . $j, 'checked');

            $mform->addElement('text', 'semestername' . $j, get_string('semestername', 'booking'));
            $mform->setType('semestername' . $j, PARAM_RAW);
            $mform->setDefault('semestername' . $j, '');
            $mform->addHelpButton('semestername' . $j, 'semestername', 'booking');
            $mform->hideIf('semestername' . $j, 'addsemester' . $j, 'notchecked');
            $mform->disabledIf('semestername' . $j, 'deletesemester' . $j, 'checked');

            $mform->addElement('date_selector', 'semesterstart' . $j, get_string('semesterstart', 'booking'));
            $mform->addHelpButton('semesterstart' . $j, 'semesterstart', 'booking');
            $mform->hideIf('semesterstart' . $j, 'addsemester' . $j, 'notchecked');
            $mform->disabledIf('semesterstart' . $j, 'deletesemester' . $j, 'checked');

            $mform->addElement('date_selector', 'semesterend' . $j, get_string('semesterend', 'booking'));
            $mform->addHelpButton('semesterend' . $j, 'semesterend', 'booking');
            $mform->hideIf('semesterend' . $j, 'addsemester' . $j, 'notchecked');
            $mform->disabledIf('semesterend' . $j, 'deletesemester' . $j, 'checked');

            $mform->addElement('advcheckbox', 'deletesemester' . $j,
                get_string('deletesemester', 'booking'), null, null, [0, 1]);
            $mform->setDefault('deletesemester' . $j, 0);
            $mform->addHelpButton('deletesemester' . $j, 'deletesemester', 'booking');
            $mform->hideIf('deletesemester' . $j, 'addsemester' . $j, 'notchecked');

            // Show checkbox to add a semester.
            if ($i < MAX_SEMESTERS) {
                $next = sprintf('%02d', $i + 1); // Use two digits.

                $mform->addElement('checkbox', 'addsemester' . $next, get_string('addsemester', 'booking'));
                $mform->hideIf('addsemester' . $next, 'addsemester' . $j, 'notchecked');
            }

            $i++;
        }
    }

    /**
     * Validate semesters.
     *
     * {@inheritdoc}
     * @see moodleform::validation()
     */
    public function validation($data, $files) {

        global $DB;

        $errors = array();

        // Validate semesters.
        for ($i = 1; $i <= MAX_SEMESTERS; $i++) {

            // Clear form variables.
            $semesteridentifierx = null;
            $semesterstartx = null;
            $semesterendx = null;

            // Use 2 digits, so we can have more than 9 semesters.
            $j = sprintf('%02d', $i);

            // Check if values are set and initialize.
            if (isset($data['semesteridentifier' . $j])) {
                $semesteridentifierx = $data['semesteridentifier' . $j];
            }
            if (isset($data['semestername' . $j])) {
                $semesternamex = $data['semestername' . $j];
            }
            if (isset($data['semesterstart' . $j])) {
                $semesterstartx = $data['semesterstart' . $j];
            }
            if (isset($data['semesterend' . $j])) {
                $semesterendx = $data['semesterend' . $j];
            }
            if (isset($data['deletesemester' . $j])) {
                // If we delete, we need no validation.
                if ($data['deletesemester' . $j] == '1') {
                    continue;
                }
            }

            // If it's a new record...
            if (empty($data['semesterid' . $j])) {

                // Skip if both identifier and name are missing.
                if (empty($semesteridentifierx) && empty($semesternamex)) {
                    continue;
                }

                // The semester identifier is not allowed to be empty.
                if (empty($semesteridentifierx)) {
                    $errors['semesteridentifier' . $j] = get_string('erroremptysemesteridentifier', 'booking');
                }

                // The semester name is not allowed to be empty.
                if (empty($semesternamex)) {
                    $errors['semestername' . $j] = get_string('erroremptysemestername', 'booking');
                }

                // The semester end needs to be after semester start.
                if ($semesterendx <= $semesterstartx) {
                    $errors['semesterstart' . $j] = get_string('errorsemesterstart', 'booking');
                    $errors['semesterend' . $j] = get_string('errorsemesterend', 'booking');
                }

                // The identifier of a semester needs to be unique.
                $records = $DB->get_records('booking_semesters', ['identifier' => $semesteridentifierx]);
                if (count($records) > 0) {
                    $errors['semesteridentifier' . $j] = get_string('errorduplicatesemesteridentifier', 'booking');
                }

                // The name of a semester needs to be unique.
                $records = $DB->get_records('booking_semesters', ['name' => $semesternamex]);
                if (count($records) > 0) {
                    $errors['semestername' . $j] = get_string('errorduplicatesemestername', 'booking');
                }

            } else {
                // It it's an exisiting record...

                // The semester name is not allowed to be empty.
                if (empty($semesternamex)) {
                    $errors['semestername' . $j] = get_string('erroremptysemestername', 'booking');
                }

                // The semester end needs to be after semester start.
                if ($semesterendx <= $semesterstartx) {
                    $errors['semesterstart' . $j] = get_string('errorsemesterstart', 'booking');
                    $errors['semesterend' . $j] = get_string('errorsemesterend', 'booking');
                }
            }
        }

        return $errors;
    }

    /**
     * {@inheritDoc}
     * @see moodleform::get_data()
     */
    public function get_data() {
        $data = parent::get_data();
        return $data;
    }

    /**
     * {@inheritDoc}
     */
    protected function check_access_for_dynamic_submission(): void {
        require_capability('moodle/user:manageownfiles', $this->get_context_for_dynamic_submission());
    }

    /**
     * Process the form submission, used if form was submitted via AJAX.
     * This method can return scalar values or arrays that can be json-encoded, they will be passed to the caller JS.
     * Submission data can be accessed as: $this->get_data()
     *
     * @return mixed
     */
    public function process_dynamic_submission() {
        $data = $this->get_data();

        return $data;
    }

    /**
     * Load in existing data as form defaults
     *
     * Can be overridden to retrieve existing values from db by entity id and also
     * to preprocess editor and filemanager elements
     *
     * Example:
     *     $this->set_data(get_entity($this->_ajaxformdata['id']));
     */
    public function set_data_for_dynamic_submission(): void {
        $data = new stdClass();
        $this->set_data($data);
    }

    /**
     * Returns form context
     *
     * If context depends on the form data, it is available in $this->_ajaxformdata or
     * by calling $this->optional_param()
     *
     * @return context
     */
    protected function get_context_for_dynamic_submission(): context {
        global $USER;
        return context_user::instance($USER->id);
    }

    /**
     * Returns url to set in $PAGE->set_url() when form is being rendered or submitted via AJAX
     *
     * This is used in the form elements sensitive to the page url, such as Atto autosave in 'editor'
     *
     * If the form has arguments (such as 'id' of the element being edited), the URL should
     * also have respective argument.
     *
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        return new moodle_url('/mod/booking/semesters.php');
    }
}
