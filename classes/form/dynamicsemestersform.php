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

use coding_exception;
use context;
use context_system;
use core_form\dynamic_form;
use html_writer;
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
     * Get semester data.
     * @return array
     */
    protected function get_semester_data(): array {
        $semesterdata = [];
        if (!empty($this->_ajaxformdata['semesteridentifier']) && is_array($this->_ajaxformdata['semesteridentifier'])) {
            foreach (array_values($this->_ajaxformdata['semesteridentifier']) as $idx => $semesteridentifier) {
                $semesterdata["semesteridentifier[$idx]"] = clean_param($semesteridentifier, PARAM_TEXT);
            }
        }
        return $semesterdata;
    }

    /**
     * Set data for dynamic submission.
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {
        $this->set_data($this->get_semester_data());
    }

    /**
     * Process dynamic submission.
     * @return stdClass|null
     */
    public function process_dynamic_submission(): stdClass {
        // This is the correct place to save and update semesters.
        if ($this->get_data()->name === 'error') {
            // For testing exceptions.
            throw new \coding_exception('Name is error');
        }
        return $this->get_data();
    }

    /**
     * Form definition.
     * @return void
     */
    public function definition(): void {
        $mform = $this->_form;

        // Repeated elements.
        $repeatedsemesters = [];

        $semesterlabel = html_writer::tag('b', get_string('semester', 'booking') . ' {no}',
            array('class' => 'semesterlabel'));
        $repeatedsemesters[] = $mform->createElement('static', 'semesterlabel', $semesterlabel);

        $repeatedsemesters[] = $mform->createElement('text', 'semesteridentifier', get_string('semesteridentifier', 'booking'));
        $mform->setType('semesteridentifier', PARAM_TEXT);

        $repeatedsemesters[] = $mform->createElement('text', 'semestername', get_string('semestername', 'booking'));
        $mform->setType('semestername', PARAM_TEXT);

        $repeatedsemesters[] = $mform->createElement('date_selector', 'semesterstart', get_string('semesterstart', 'booking'));

        $repeatedsemesters[] = $mform->createElement('date_selector', 'semesterend', get_string('semesterend', 'booking'));

        $this->repeat_elements($repeatedsemesters, max(1, count($this->get_semester_data())),
            [], 'semesters', 'semester_add_fields', 1, null, true);

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

        // TODO: Add server-side validations.
        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /*if (strlen($data['name']) < 3) {
            $errors['name'] = 'Name must be at least three characters long';
        }*/
        return $errors;
    }

    /**
     * Get page URL for dynamic submission.
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        return new moodle_url('/mod/booking/semester.php');
    }
}
