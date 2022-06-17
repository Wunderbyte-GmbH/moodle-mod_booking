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
use context_system;
use core_form\dynamic_form;
use html_writer;
use mod_booking\semester;
use moodle_exception;
use moodle_url;
use stdClass;

/**
 * Add holidays form.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dynamicholidaysform extends dynamic_form {

    /**
     * Get context for dynamic submission.
     * @return context
     */
    protected function get_context_for_dynamic_submission(): context {
        return context_system::instance();
    }

    /**
     * Check access for dynamic submission.
     * @return void
     */
    protected function check_access_for_dynamic_submission(): void {
        require_capability('moodle/site:config', context_system::instance());
    }

    /**
     * Transform holidays data from form to an array of holiday objects.
     * @param stdClass $data data from form
     * @return array
     */
    protected function transform_data_to_holidays_array(stdClass $data): array {
        $holidaysarray = [];

        if (!empty($data->semesteridentifier) && is_array($data->semesteridentifier)
            && !empty($data->holidayname) && is_array($data->holidayname)
            && !empty($data->holidaystart) && is_array($data->holidaystart)
            && !empty($data->holidayend) && is_array($data->holidayend)) {

            foreach ($data->semesteridentifier as $idx => $semesteridentifier) {

                $holiday = new stdClass;
                $holiday->semesteridentifier = $semesteridentifier;
                $holiday->name = trim($data->holidayname[$idx]);
                $holiday->startdate = $data->holidaystart[$idx];
                $holiday->enddate = $data->holidayend[$idx];

                if (!empty($holiday->semesteridentifier)) {
                    $holidaysarray[] = $holiday;
                } else {
                    throw new moodle_exception('ERROR: Semester identifier for holiday must not be empty.');
                }

                if (empty($holiday->name)) {
                    throw new moodle_exception('ERROR: Holiday name must not be empty.');
                }
            }
        }
        return $holidaysarray;
    }

    /**
     * Set data for dynamic submission.
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {
        global $DB;

        $data = new stdClass;

        if ($existingholidays = $DB->get_records_sql("SELECT * FROM {booking_holidays} ORDER BY startdate ASC")) {
            $data->holidays = count($existingholidays);
            foreach ($existingholidays as $existingholiday) {
                $data->semesteridentifier[] = $existingholiday->semesteridentifier;
                $data->holidayname[] = trim($existingholiday->name);
                $data->holidaystart[] = $existingholiday->startdate;
                $data->holidayend[] = $existingholiday->enddate;
            }

        } else {
            // No holidays found in DB.
            $data->holidays = 0;
            $data->semesteridentifier = [];
            $data->holidayname = [];
            $data->holidaystart = [];
            $data->holidayend = [];
        }

        $this->set_data($data);
    }

    /**
     * Process dynamic submission.
     * @return stdClass|null
     */
    public function process_dynamic_submission(): stdClass {
        global $DB;

        // This is the correct place to save and update holidays.
        $holidaydata = $this->get_data();
        $holidaysarray = $this->transform_data_to_holidays_array($holidaydata);

        $existingholidaynames = [];
        $currentholidaynames = $holidaydata->holidayname;

        if ($existingholidays = $DB->get_records('booking_holidays')) {
            foreach ($existingholidays as $existingholiday) {
                $existingholidaynames[] = trim($existingholiday->name);
            }
        }

        foreach ($holidaysarray as $holiday) {

            $holiday->name = trim($holiday->name);

            // If it's a new holiday name: insert.
            if (!in_array($holiday->name, $existingholidaynames)) {
                $DB->insert_record('booking_holidays', $holiday);
            } else {
                // If it's an existing holiday name: update.
                $existingrecord = $DB->get_record('booking_holidays', ['name' => $holiday->name]);
                $holiday->id = $existingrecord->id;
                $DB->update_record('booking_holidays', $holiday);
            }
        }

        // Delete all holidays from DB which are not part of the form anymore.
        foreach ($existingholidaynames as $existingholidayname) {
            if (!in_array($existingholidayname, $currentholidaynames)) {
                $DB->delete_records('booking_holidays', ['name' => $existingholidayname]);
            }
        }

        return $this->get_data();
    }

    /**
     * Form definition.
     * @return void
     */
    public function definition(): void {
        global $DB;

        $mform = $this->_form;

        // Repeated elements.
        $repeatedholidays = [];

        // Options to store help button texts etc.
        $repeateloptions = [];

        $holidaylabel = html_writer::tag('b', get_string('holiday', 'booking') . ' {no}',
            array('class' => 'holidaylabel'));
        $repeatedholidays[] = $mform->createElement('static', 'holidaylabel', $holidaylabel);

        $semestersarray = semester::get_semesters_identifier_name_array();
        $repeatedholidays[] = $mform->createElement('select', 'semesteridentifier',
            get_string('choosesemester', 'mod_booking'), $semestersarray);
        $mform->setType('semesteridentifier', PARAM_TEXT);
        $repeateloptions['semesteridentifier']['helpbutton'] = ['choosesemester', 'mod_booking'];

        $repeatedholidays[] = $mform->createElement('text', 'holidayname', get_string('holidayname', 'booking'));
        $mform->setType('holidayname', PARAM_TEXT);
        $repeateloptions['holidayname']['helpbutton'] = ['holidayname', 'mod_booking'];

        $repeatedholidays[] = $mform->createElement('date_selector', 'holidaystart', get_string('holidaystart', 'booking'));
        $repeateloptions['holidaystart']['helpbutton'] = ['holidaystart', 'mod_booking'];

        $repeatedholidays[] = $mform->createElement('date_selector', 'holidayend', get_string('holidayend', 'booking'));
        $repeateloptions['holidayend']['helpbutton'] = ['holidayend', 'mod_booking'];

        $repeatedholidays[] = $mform->createElement('submit', 'deleteholiday', get_string('deleteholiday', 'mod_booking'));

        $numberofholidaystoshow = 1;
        if ($existingholidays = $DB->get_records('booking_holidays')) {
            $numberofholidaystoshow = count($existingholidays);
        }

        $this->repeat_elements($repeatedholidays, $numberofholidaystoshow,
            $repeateloptions, 'holidays', 'addholiday', 1, get_string('addholiday', 'mod_booking'), true, 'deleteholiday');

        // Buttons.
        $this->add_action_buttons();
    }

    /**
     * Server-side form validation.
     * @param array $data
     * @param array $files
     * @return array $errors
     */
    public function validation($data, $files): array {
        $errors = [];

        $data['holidayname'] = array_map('trim', $data['holidayname']);
        $holidaynamecounts = array_count_values($data['holidayname']);

        foreach ($data['semesteridentifier'] as $idx => $semesteridentifier) {
            if (empty($semesteridentifier)) {
                $errors["semesteridentifier[$idx]"] = get_string('erroremptysemesteridentifier', 'booking');
            }
        }

        foreach ($data['holidayname'] as $idx => $holidayname) {
            if (empty($holidayname)) {
                $errors["holidayname[$idx]"] = get_string('erroremptyholidayname', 'booking');
            }
            if ($holidaynamecounts[$holidayname] > 1) {
                $errors["holidayname[$idx]"] = get_string('errorduplicateholidayname', 'booking');
            }
        }

        foreach ($data['holidaystart'] as $idx => $holidaystart) {
            if ($holidaystart > $data['holidayend'][$idx]) {
                $errors["holidaystart[$idx]"] = get_string('errorholidaystart', 'booking');
                $errors["holidayend[$idx]"] = get_string('errorholidayend', 'booking');
            }
        }

        return $errors;
    }

    /**
     * Get page URL for dynamic submission.
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        return new moodle_url('/mod/booking/semesters.php');
    }
}
