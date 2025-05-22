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
 * Moodle form for dynamic holidays.
 *
 * @package   mod_booking
 * @copyright 2021 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Bernhard Fischer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->libdir/formslib.php");

use cache_helper;
use context;
use context_system;
use core_form\dynamic_form;
use html_writer;
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

        if (!empty($data->holidaystart) && is_array($data->holidaystart)) {
            foreach ($data->holidaystart as $idx => $holidaystart) {
                $holiday = new stdClass();
                $holiday->id = $data->holidayid[$idx];
                if (!empty($data->holidayname[$idx])) {
                    $holiday->name = trim($data->holidayname[$idx]);
                } else {
                    $holiday->name = '';
                }
                $holiday->startdate = $data->holidaystart[$idx];
                if ($data->holidayendactive[$idx] == 1) {
                    $holiday->enddate = $data->holidayend[$idx];
                } else {
                    // If we have no end date, it is the same as start date.
                    $holiday->enddate = $holiday->startdate;
                }
                $holidaysarray[] = $holiday;
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

        $data = new stdClass();

        if ($existingholidays = $DB->get_records_sql("SELECT * FROM {booking_holidays} ORDER BY startdate DESC")) {
            $data->holidays = count($existingholidays);
            foreach ($existingholidays as $existingholiday) {
                $data->holidayid[] = $existingholiday->id;
                $data->holidaystart[] = $existingholiday->startdate;
                if ($existingholiday->startdate == $existingholiday->enddate) {
                    $data->holidayendactive[] = 0;
                } else {
                    $data->holidayendactive[] = 1;
                }
                $data->holidayend[] = $existingholiday->enddate;
                $data->holidayname[] = trim($existingholiday->name);
            }
        } else {
            // No holidays found in DB.
            $data->holidays = 0;
            $data->id = [];
            $data->holidaystart = [];
            $data->holidayendactive = [];
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

        $existingholidayids = [];
        $currentholidayids = $holidaydata->holidayid;

        if ($existingholidays = $DB->get_records('booking_holidays')) {
            foreach ($existingholidays as $existingholiday) {
                $existingholidayids[] = $existingholiday->id;
            }
        }

        foreach ($holidaysarray as $holiday) {
            // If it's a new holiday id: insert.
            if (!in_array($holiday->id, array_keys($existingholidays))) {
                $DB->insert_record('booking_holidays', $holiday);
            } else {
                // If it's an existing holiday id: update.
                $existingrecord = $existingholidays[$holiday->id];
                $holiday->id = $existingrecord->id;
                $DB->update_record('booking_holidays', $holiday);
            }
        }

        // Delete all holidays from DB which are not part of the form anymore.
        foreach ($existingholidayids as $existingholidayid) {
            if (!in_array($existingholidayid, $currentholidayids)) {
                $DB->delete_records('booking_holidays', ['id' => $existingholidayid]);
            }
        }

        // So we can be sure that we use the right dates.
        cache_helper::purge_by_event('setbacksemesters');

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

        $repeatedholidays[] = $mform->createElement('hidden', 'holidayid', 0);
        $mform->setType('holidayid', PARAM_INT);

        // Holiday start date.
        $repeatedholidays[] = $mform->createElement('date_selector', 'holidaystart', get_string('holidaystart', 'mod_booking'));

        // Checkbox for end date.
        $repeatedholidays[] = $mform->createElement(
            'advcheckbox',
            'holidayendactive',
            get_string('holidayendactive', 'mod_booking'),
            null,
            null,
            [0, 1]
        );
        $repeateloptions['holidayend']['hideif'] = ['holidayendactive', 'eq', 0];

        // Holiday end date.
        $repeatedholidays[] = $mform->createElement('date_selector', 'holidayend', get_string('holidayend', 'mod_booking'));

        // Holiday name.
        $repeatedholidays[] = $mform->createElement('text', 'holidayname', get_string('holidayname', 'booking'));
        $mform->setType('holidayname', PARAM_TEXT);

        // Delete holiday button.
        $repeatedholidays[] = $mform->createElement('submit', 'deleteholiday', get_string('deleteholiday', 'mod_booking'));

        $repeatedholidays[] = $mform->createElement('html', '<hr/>');

        $numberofholidaystoshow = 1;
        $numberofholidaystoshow = $DB->count_records('booking_holidays') ?? 1;

        $this->repeat_elements(
            $repeatedholidays,
            $numberofholidaystoshow,
            $repeateloptions,
            'holidays',
            'addholiday',
            1,
            get_string('addholiday', 'mod_booking'),
            true,
            'deleteholiday'
        );

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

        foreach ($data['holidaystart'] as $idx => $holidaystart) {
            if ($data['holidayendactive'][$idx] && $holidaystart > $data['holidayend'][$idx]) {
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
