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
 * Moodle form for dynamic semesters.
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
use coding_exception;
use context;
use context_system;
use core_form\dynamic_form;
use html_writer;
use moodle_exception;
use moodle_url;
use stdClass;

/**
 * Add semesters form.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dynamicsemestersform extends dynamic_form {

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
     * Transform semester data from form to an array of semester classes.
     * @param stdClass $semesterdata data from form
     * @return array
     */
    protected function transform_data_to_semester_array(stdClass $semesterdata): array {
        $semestersarray = [];

        if (!empty($semesterdata->semesteridentifier) && is_array($semesterdata->semesteridentifier)
            && !empty($semesterdata->semestername) && is_array($semesterdata->semestername)
            && !empty($semesterdata->semesterstart) && is_array($semesterdata->semesterstart)
            && !empty($semesterdata->semesterend) && is_array($semesterdata->semesterend)) {

            foreach ($semesterdata->semesteridentifier as $idx => $semesteridentifier) {

                $semester = new stdClass();
                $semester->identifier = trim($semesteridentifier);
                $semester->name = trim($semesterdata->semestername[$idx]);
                $semester->startdate = $semesterdata->semesterstart[$idx];
                $semester->enddate = $semesterdata->semesterend[$idx];

                if (!empty($semester->identifier)) {
                    $semestersarray[] = $semester;
                } else {
                    throw new moodle_exception('ERROR: Semester identifier must not be empty.');
                }
            }
        }
        return $semestersarray;
    }

    /**
     * Set data for dynamic submission.
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {
        global $DB;

        $data = new stdClass();

        if ($existingsemesters = $DB->get_records_sql("SELECT * FROM {booking_semesters} ORDER BY startdate DESC")) {
            $data->semesters = count($existingsemesters);
            foreach ($existingsemesters as $existingsemester) {
                $data->semesteridentifier[] = trim($existingsemester->identifier);
                $data->semestername[] = trim($existingsemester->name);
                $data->semesterstart[] = $existingsemester->startdate;
                $data->semesterend[] = $existingsemester->enddate;
            }

        } else {
            // No semesters found in DB.
            $data->semesters = 0;
            $data->semesteridentifier = [];
            $data->semestername = [];
            $data->semesterstart = [];
            $data->semesterend = [];
        }

        $this->set_data($data);
    }

    /**
     * Process dynamic submission.
     * @return stdClass|null
     */
    public function process_dynamic_submission(): stdClass {
        global $DB;

        // This is the correct place to save and update semesters.
        $semesterdata = $this->get_data();
        $semestersarray = $this->transform_data_to_semester_array($semesterdata);

        $existingidentifiers = [];
        $currentidentifiers = $semesterdata->semesteridentifier;

        if ($existingsemesters = $DB->get_records('booking_semesters')) {
            foreach ($existingsemesters as $existingsemester) {
                $existingidentifiers[] = trim($existingsemester->identifier);
            }
        }

        foreach ($semestersarray as $semester) {

            $semester->identifier = trim($semester->identifier);
            $semester->name = trim($semester->name);

            // If it's a new identifier: insert.
            if (!in_array($semester->identifier, $existingidentifiers)) {
                $DB->insert_record('booking_semesters', $semester);
            } else {
                // If it's an existing identifier: update.
                $existingrecord = $DB->get_record('booking_semesters', ['identifier' => $semester->identifier]);
                $semester->id = $existingrecord->id;
                $DB->update_record('booking_semesters', $semester);
            }
        }

        // Delete all semesters from DB which are not part of the form anymore.
        foreach ($existingidentifiers as $existingidentifier) {
            if (!in_array($existingidentifier, $currentidentifiers)) {
                $DB->delete_records('booking_semesters', ['identifier' => $existingidentifier]);
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
        $repeatedsemesters = [];

        // Options to store help button texts etc.
        $repeateloptions = [];

        $semesterlabel = html_writer::tag('b', get_string('semester', 'booking') . ' {no}',
            ['class' => 'semesterlabel']);
        $repeatedsemesters[] = $mform->createElement('static', 'semesterlabel', $semesterlabel);

        $repeatedsemesters[] = $mform->createElement('text', 'semesteridentifier', get_string('semesteridentifier', 'booking'));
        $mform->setType('semesteridentifier', PARAM_TEXT);
        // Info: Help buttons in repeat_elements groups are causing problems with Moodle 4.0.
        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /* $repeateloptions['semesteridentifier']['helpbutton'] = ['semesteridentifier', 'mod_booking'];*/

        $repeatedsemesters[] = $mform->createElement('text', 'semestername', get_string('semestername', 'booking'));
        $mform->setType('semestername', PARAM_TEXT);
        // Info: Help buttons in repeat_elements groups are causing problems with Moodle 4.0.
        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /* $repeateloptions['semestername']['helpbutton'] = ['semestername', 'mod_booking']; */

        $repeatedsemesters[] = $mform->createElement('date_selector', 'semesterstart', get_string('semesterstart', 'booking'));
        // Info: Help buttons in repeat_elements groups are causing problems with Moodle 4.0.
        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /* $repeateloptions['semesterstart']['helpbutton'] = ['semesterstart', 'mod_booking']; */

        $repeatedsemesters[] = $mform->createElement('date_selector', 'semesterend', get_string('semesterend', 'booking'));
        // Info: Help buttons in repeat_elements groups are causing problems with Moodle 4.0.
        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /* $repeateloptions['semesterend']['helpbutton'] = ['semesterend', 'mod_booking']; */

        $repeatedsemesters[] = $mform->createElement('submit', 'deletesemester', get_string('deletesemester', 'mod_booking'));

        $numberofsemesterstoshow = 1;
        if ($existingsemesters = $DB->get_records('booking_semesters')) {
            $numberofsemesterstoshow = count($existingsemesters);
        }

        $this->repeat_elements($repeatedsemesters, $numberofsemesterstoshow,
            $repeateloptions, 'semesters', 'addsemester', 1, get_string('addsemester', 'mod_booking'), true, 'deletesemester');

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

        $data['semesteridentifier'] = array_map('trim', $data['semesteridentifier']);
        $data['semestername'] = array_map('trim', $data['semestername']);

        $semesteridentifiercounts = array_count_values($data['semesteridentifier']);
        $semesternamecounts = array_count_values($data['semestername']);

        foreach ($data['semesteridentifier'] as $idx => $semesteridentifier) {
            if (empty($semesteridentifier)) {
                $errors["semesteridentifier[$idx]"] = get_string('erroremptysemesteridentifier', 'booking');
            }
            if ($semesteridentifiercounts[$semesteridentifier] > 1) {
                $errors["semesteridentifier[$idx]"] = get_string('errorduplicatesemesteridentifier', 'booking');
            }
        }

        foreach ($data['semestername'] as $idx => $semestername) {
            if (empty($semestername)) {
                $errors["semestername[$idx]"] = get_string('erroremptysemestername', 'booking');
            }
            if ($semesternamecounts[$semestername] > 1) {
                $errors["semestername[$idx]"] = get_string('errorduplicatesemestername', 'booking');
            }
        }

        foreach ($data['semesterstart'] as $idx => $semesterstart) {
            if ($semesterstart >= $data['semesterend'][$idx]) {
                $errors["semesterstart[$idx]"] = get_string('errorsemesterstart', 'booking');
                $errors["semesterend[$idx]"] = get_string('errorsemesterend', 'booking');
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
