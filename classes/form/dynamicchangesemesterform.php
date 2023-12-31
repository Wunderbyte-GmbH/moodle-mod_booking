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
 * Dynamic change semester form
 *
 * @package mod_booking
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->libdir/formslib.php");

use context;
use context_system;
use core_form\dynamic_form;
use html_writer;
use mod_booking\semester;
use mod_booking\singleton_service;
use mod_booking\task\task_adhoc_reset_optiondates_for_semester;
use moodle_url;
use stdClass;

/**
 * Add holidays form.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dynamicchangesemesterform extends dynamic_form {

    /** @var int $cmid */
    private $cmid = null;

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
     * Set data for dynamic submission.
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {

        $cmid = optional_param('id', 0, PARAM_INT);
        $choosesemester = null;

        if ($cmid && $cmid != 0) {
            $this->cmid = $cmid;

            $booking = singleton_service::get_instance_of_booking_by_cmid($cmid);

        } else if ($this->cmid && $this->cmid != 0) {
            $cmid = $this->cmid;
        } else if (isset($this->_ajaxformdata['cmid'])) {
            $cmid = $this->_ajaxformdata['cmid'];
            $choosesemester = isset($this->_ajaxformdata['choosesemester']) ? $this->_ajaxformdata['choosesemester'] : null;
            $this->cmid = $cmid;
        }

        $data = new stdClass();

        if ($cmid) {
            $data->cmid = $cmid;
            $data->choosesemester = $choosesemester;
        }

        $this->set_data($data);
    }

    /**
     * Process dynamic submission.
     * @return stdClass|null
     */
    public function process_dynamic_submission(): stdClass {
        global $USER;

        $data = $this->get_data();

        $task = new task_adhoc_reset_optiondates_for_semester();

        $taskdata = [
            'cmid' => $data->cmid,
            'semesterid' => $data->choosesemester,
        ];
        $task->set_custom_data($taskdata);
        $task->set_userid($USER->id);

        // Now queue the task or reschedule it if it already exists (with matching data).
        \core\task\manager::reschedule_or_queue_adhoc_task($task);

        return $data;
    }

    /**
     * Form definition.
     * @return void
     */
    public function definition(): void {

        $cmid = optional_param('id', 0, PARAM_INT);

        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);

        $mform = $this->_form;

        $mform->addElement('html', '<div class="alert alert-danger mt-3">' .
                get_string('changesemester:warning', 'mod_booking') . '</div>');

        $mform->addElement('hidden', 'cmid', 0);
        $mform->settype('cmid', PARAM_INT);

        $selectarray = semester::get_semesters_id_name_array();
        $mform->addElement('select', 'choosesemester', get_string('choosesemester', 'mod_booking'), $selectarray);
        if (!empty($bookingsettings->semesterid)) {
            // If the booking instance has an associated semester, set it as default.
            $mform->setDefault('choosesemester', $bookingsettings->semesterid);
        }

        $mform->addElement('advcheckbox', 'confirmchangesemester', get_string('confirmchangesemester', 'mod_booking'));

        // Buttons.
        $this->add_action_buttons(false, get_string('changesemester', 'mod_booking'));
    }

    /**
     * Server-side form validation.
     * @param array $data
     * @param array $files
     * @return array $errors
     */
    public function validation($data, $files): array {
        global $DB;

        $errors = [];

        if (empty($data['confirmchangesemester'])) {
            $errors['confirmchangesemester'] = get_string('error:confirmthatyouaresure', 'mod_booking');
        }
        if (!empty($DB->get_records('task_adhoc', [
            'component' => 'mod_booking',
            'classname' => '\mod_booking\task\task_adhoc_reset_optiondates_for_semester',
        ]))) {
            $errors['confirmchangesemester'] = get_string('error:taskalreadystarted', 'mod_booking');
        }

        return $errors;
    }

    /**
     * Get page URL for dynamic submission.
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        return new moodle_url('/mod/booking/semesters.php', ['id' => $this->cmid]);
    }
}
