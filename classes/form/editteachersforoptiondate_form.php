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

use context;
use context_module;
use context_system;
use moodle_url;
use stdClass;

/**
 * Class editteachersforoptiondate_form
 *
 * See PHPdocs in the parent class to understand the purpose of each method
 *
 * @package     mod_booking
 * @copyright   2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Bernhard Fischer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class editteachersforoptiondate_form extends \core_form\dynamic_form {

    /**
     * Returns form context
     *
     * If context depends on the form data, it is available in $this->_ajaxformdata or
     * by calling $this->optional_param()
     *
     * @return context
     */
    protected function get_context_for_dynamic_submission(): context {
        $cmid = $this->_ajaxformdata['cmid'];
        return context_module::instance($cmid);
    }

    /**
     * Check access for dynamic submission.
     *
     * @return void
     */
    protected function check_access_for_dynamic_submission(): void {
        require_capability('mod/booking:addeditownoption', $this->get_context_for_dynamic_submission());
    }

    /**
     * Here we can prepare the data before submission.
     */
    public function set_data_for_dynamic_submission(): void {
        global $DB;

        $data = new stdClass();
        $data->cmid = $this->_ajaxformdata['cmid'];
        $data->optionid = $this->_ajaxformdata['optionid'];
        $data->optiondateid = $this->_ajaxformdata['optiondateid'];

        // Get reason from DB.
        if ($reason = $DB->get_field('booking_optiondates', 'reason', ['id' => $data->optiondateid])) {
            $data->reason = $reason;
        }

        // Teachers will be retrieved from $data->teachersforoptiondate in process_dynamic_submission.
        $this->set_data($data);
    }

    /**
     * This is the correct place to insert and delete data from DB after modal form submission.
     */
    public function process_dynamic_submission() {
        global $DB;

        $data = $this->get_data();
        $teachersforoptiondate = $data->teachersforoptiondate;

        // First get already existing teachers.
        $existingteacherrecords = $DB->get_records('booking_optiondates_teachers', ['optiondateid' => $data->optiondateid]);
        // Transform into an array of userids.
        $existingteacherids = [];
        if (!empty($existingteacherrecords)) {
            foreach ($existingteacherrecords as $existingteacherrecord) {
                $existingteacherids[] = $existingteacherrecord->userid;
            }
        }
        // Delete teachers from existing teachers if they have been removed.
        if (!empty($existingteacherids)) {
            foreach ($existingteacherids as $existingteacherid) {
                if (!in_array($existingteacherid, $teachersforoptiondate)) {
                    $DB->delete_records('booking_optiondates_teachers', [
                        'optiondateid' => $data->optiondateid,
                        'userid' => $existingteacherid
                    ]);
                }
            }
        }
        // Add teachers if they have been added in autocomplete.
        if (!empty($teachersforoptiondate)) {
            foreach ($teachersforoptiondate as $teacherforoptiondate) {
                if (!in_array($teacherforoptiondate, $existingteacherids)) {
                    $newteacherrecord = new stdClass;
                    $newteacherrecord->optiondateid = $data->optiondateid;
                    $newteacherrecord->userid = $teacherforoptiondate;
                    $DB->insert_record('booking_optiondates_teachers', $newteacherrecord);
                }
            }
        }

        // Save reason.
        if (!empty($data->reason)) {
            if ($optiondaterecord = $DB->get_record('booking_optiondates', ['id' => $data->optiondateid])) {
                $optiondaterecord->reason = $data->reason;
                $DB->update_record('booking_optiondates', $optiondaterecord);
            }
        }

        return $data;
    }

    /**
     * The form definition.
     */
    public function definition() {
        global $DB;

        $mform = $this->_form;

        $cmid = $this->_ajaxformdata['cmid'];
        $optionid = $this->_ajaxformdata['optionid'];
        $optiondateid = $this->_ajaxformdata['optiondateid'];
        $teachers = explode(',', $this->_ajaxformdata['teachers']);

        $mform->addElement('hidden', 'cmid', $cmid);
        $mform->setType('cmid', PARAM_INT);

        $mform->addElement('hidden', 'optionid', $optionid);
        $mform->setType('optionid', PARAM_INT);

        $mform->addElement('hidden', 'optiondateid', $optiondateid);
        $mform->setType('optiondateid', PARAM_INT);

        $mform->addElement('hidden', 'teachers', $teachers);
        $mform->setType('teachers', PARAM_RAW);

        $options = [
            'tags' => false,
            'multiple' => true
        ];
        /* Important note: Currently, all users can be added as teachers for optiondates.
        In the future, there might be a user profile field defining users which are allowed
        to be added as substitute teachers. */
        $userrecords = $DB->get_records_sql(
            "SELECT id, firstname, lastname, email FROM {user}"
        );
        $allowedusers = [];
        foreach ($userrecords as $userrecord) {
            $allowedusers[$userrecord->id] = "$userrecord->firstname $userrecord->lastname ($userrecord->email)";
        }

        $mform->addElement('autocomplete', 'teachersforoptiondate', get_string('teachers', 'mod_booking'),
            $allowedusers, $options);
        $mform->setDefault('teachersforoptiondate', $teachers);

        $mform->addElement('text', 'reason', get_string('reason', 'mod_booking'));
        $mform->setType('reason', PARAM_TEXT);
    }

    /**
     * Validation: We need a reason for optiondates with missing teacher or substitute teachers.
     *
     * @param array $data
     * @param array $files
     */
    public function validation($data, $files) {
        global $DB;
        $errors = [];

        if (strlen($data['reason']) > 250) {
            $errors['reason'] = get_string('error:reasontoolong', 'mod_booking');
        }
        if (empty($data['teachersforoptiondate'])) {
            if (empty($data['reason'])) {
                $errors['reason'] = get_string('error:reasonfornoteacher', 'mod_booking');
            }
        } else {
            $teachersforoption = $DB->get_fieldset_select('booking_teachers', 'userid', 'optionid = :optionid',
                ['optionid' => $data['optionid']]);
            $teachersforoptiondate = $data['teachersforoptiondate'];
            sort($teachersforoption);
            sort($teachersforoptiondate);
            if (($teachersforoption != $teachersforoptiondate) && empty($data['reason'])) {
                $errors['reason'] = get_string('error:reasonforsubstituteteacher', 'mod_booking');
            }
        }

        return $errors;
    }

    /**
     * This seems to be not needed - we'll do it anyway.
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        $cmid = $this->_ajaxformdata['cmid'];
        $optionid = $this->_ajaxformdata['optionid'];

        if (!$cmid) {
            $cmid = $this->optional_param('cmid', '', PARAM_RAW);
        }

        $url = new moodle_url('/mod/booking/optiondates_teachers_report.php' , array('id' => $cmid, 'optionid' => $optionid));
        return $url;
    }
}
