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

use cache_helper;
use context;
use context_module;
use context_system;
use mod_booking\event\optiondates_teacher_added;
use mod_booking\event\optiondates_teacher_deleted;
use moodle_exception;
use moodle_url;
use mod_booking\singleton_service;
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

        $context = $this->get_context_for_dynamic_submission();

        if (
            (has_capability('mod/booking:updatebooking', $context)
            || has_capability('mod/booking:addeditownoption', $context)
            || has_capability('mod/booking:viewreports', $context)
            || has_capability('mod/booking:limitededitownoption', $context)) == false
        ) {
                throw new moodle_exception('youdonthavetherighttoaccessthisform', 'mod_booking');
        }
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

        // Get deduction data from DB.
        $settings = singleton_service::get_instance_of_booking_option_settings($data->optionid);
        foreach ($settings->teachers as $teacher) {
            if (
                $record = $DB->get_record('booking_odt_deductions', [
                'optiondateid' => $data->optiondateid,
                'userid' => $teacher->userid,
                ])
            ) {
                $data->{'deduction-teacherid-' . $teacher->userid} = 1;
                $data->{'deductionreason-teacherid-' . $teacher->userid} = $record->reason;
            }
        }

        // Teachers will be retrieved from $data->teachersforoptiondate in process_dynamic_submission.
        $this->set_data($data);
    }

    /**
     * This is the correct place to insert and delete data from DB after modal form submission.
     */
    public function process_dynamic_submission() {
        global $DB, $USER;

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
                        'userid' => $existingteacherid,
                    ]);

                    // Trigger an event so we can use it with booking rules.
                    $event = optiondates_teacher_deleted::create([
                        'objectid' => $data->optionid,
                        'context' => context_system::instance(),
                        'userid' => $USER->id,
                        'relateduserid' => $existingteacherid,
                        'other' => [
                            'cmid' => $data->cmid,
                        ],
                    ]);
                    $event->trigger();
                }
            }
            cache_helper::purge_by_event('setbackcachedteachersjournal');
        }
        // Add teachers if they have been added in autocomplete.
        if (!empty($teachersforoptiondate)) {
            foreach ($teachersforoptiondate as $teacherforoptiondate) {
                if (!in_array($teacherforoptiondate, $existingteacherids)) {
                    $newteacherrecord = new stdClass();
                    $newteacherrecord->optiondateid = $data->optiondateid;
                    $newteacherrecord->userid = $teacherforoptiondate;
                    $DB->insert_record('booking_optiondates_teachers', $newteacherrecord);

                    // Trigger an event so we can use it with booking rules.
                    // Trigger an event so we can use it with booking rules.
                    $event = optiondates_teacher_added::create([
                        'objectid' => $data->optionid,
                        'context' => context_system::instance(),
                        'userid' => $USER->id,
                        'relateduserid' => $teacherforoptiondate,
                        'other' => [
                            'cmid' => $data->cmid,
                        ],
                    ]);
                    $event->trigger();
                }
            }
            cache_helper::purge_by_event('setbackcachedteachersjournal');
        }

        // Save reason.
        if (!empty($data->reason)) {
            if ($optiondaterecord = $DB->get_record('booking_optiondates', ['id' => $data->optiondateid])) {
                $optiondaterecord->reason = $data->reason;
                $DB->update_record('booking_optiondates', $optiondaterecord);
            }
        }

        $now = time();

        // Save deductions if there are any.
        $settings = singleton_service::get_instance_of_booking_option_settings($data->optionid);
        foreach ($settings->teachers as $teacher) {
            if (
                isset($data->{'deduction-teacherid-' . $teacher->userid})
                && $data->{'deduction-teacherid-' . $teacher->userid} == 1
                && !empty($data->{'deductionreason-teacherid-' . $teacher->userid})
            ) {
                if (
                    $existingdeductionrecord = $DB->get_record('booking_odt_deductions', [
                    'optiondateid' => $data->optiondateid,
                    'userid' => $teacher->userid,
                    ])
                ) {
                    $existingdeductionrecord->reason = trim($data->{'deductionreason-teacherid-' . $teacher->userid});
                    $existingdeductionrecord->usermodified = $USER->id;
                    $existingdeductionrecord->timemodified = $now;
                    // Record already exists, so we update.
                    $DB->update_record('booking_odt_deductions', $existingdeductionrecord);
                    // Important: Purge cache here!
                    cache_helper::purge_by_event('setbackcachedteachersjournal');
                } else {
                    $deductionrecord = new stdClass();
                    $deductionrecord->optiondateid = $data->optiondateid;
                    $deductionrecord->userid = $teacher->userid;
                    $deductionrecord->reason = trim($data->{'deductionreason-teacherid-' . $teacher->userid});
                    $deductionrecord->usermodified = $USER->id;
                    $deductionrecord->timecreated = $now;
                    $deductionrecord->timemodified = $now;
                    // It's a new record, so we insert.
                    $DB->insert_record('booking_odt_deductions', $deductionrecord);
                    // Important: Purge cache here!
                    cache_helper::purge_by_event('setbackcachedteachersjournal');
                }
            } else if (
                isset($data->{'deduction-teacherid-' . $teacher->userid})
                && $data->{'deduction-teacherid-' . $teacher->userid} == 0
                && ($existingdeductionrecord = $DB->get_record('booking_odt_deductions', [
                'optiondateid' => $data->optiondateid,
                'userid' => $teacher->userid,
                ]))
            ) {
                // A record still exists, but we have unchecked the checkbox, so delete.
                $DB->delete_records('booking_odt_deductions', [
                    'optiondateid' => $data->optiondateid,
                    'userid' => $teacher->userid,
                ]);
                // Important: Purge cache here!
                cache_helper::purge_by_event('setbackcachedteachersjournal');
            }
        }

        return $data;
    }

    /**
     * The form definition.
     */
    public function definition() {
        global $DB, $OUTPUT;

        $mform = $this->_form;

        $cmid = $this->_ajaxformdata['cmid'];
        $optionid = $this->_ajaxformdata['optionid'];
        $optiondateid = $this->_ajaxformdata['optiondateid'];
        $teacheridstring = $this->_ajaxformdata['teachers'];
        $teacherids = explode(',', $teacheridstring);
        $teacherids = array_filter($teacherids, 'is_numeric'); // Eliminate 'undefined' in case if no teacher.

        $list = [];
        // Process only if teachers assigned.
        if (!empty($teacherids)) {
             [$insql, $inparams] = $DB->get_in_or_equal($teacherids, SQL_PARAMS_NAMED);

            $sql = "SELECT id, firstname, lastname, email FROM {user} WHERE id $insql";
            $teachers = $DB->get_records_sql($sql, $inparams);

            foreach ($teachers as $teacher) {
                $details = [
                    'id' => $teacher->id,
                    'email' => $teacher->email,
                    'firstname' => $teacher->firstname,
                    'lastname' => $teacher->lastname,
                ];
                $list[$teacher->id] =
                    $OUTPUT->render_from_template(
                        'mod_booking/form-user-selector-suggestion',
                        $details
                    );
            }
        }

        $mform->addElement('hidden', 'cmid', $cmid);
        $mform->setType('cmid', PARAM_INT);

        $mform->addElement('hidden', 'optionid', $optionid);
        $mform->setType('optionid', PARAM_INT);

        $mform->addElement('hidden', 'optiondateid', $optiondateid);
        $mform->setType('optiondateid', PARAM_INT);

        $mform->addElement('hidden', 'teachers', $teacheridstring);
        $mform->setType('teachers', PARAM_RAW);

        $options = [
            'tags' => false,
            'multiple' => true,
            'noselectionstring' => '',
            'ajax' => 'mod_booking/form_teachers_selector',
            'valuehtmlcallback' => function ($value) {
                global $OUTPUT;
                if (empty($value)) {
                    return get_string('choose...', 'mod_booking');
                }
                $user = singleton_service::get_instance_of_user((int)$value);
                $details = [
                    'id' => $user->id,
                    'email' => $user->email,
                    'firstname' => $user->firstname,
                    'lastname' => $user->lastname,
                ];
                return $OUTPUT->render_from_template(
                    'mod_booking/form-user-selector-suggestion',
                    $details
                );
            },
        ];
        /* Important note: Currently, all users can be added as teachers for optiondates.
        In the future, there might be a user profile field defining users which are allowed
        to be added as substitute teachers. */
        $mform->addElement(
            'autocomplete',
            'teachersforoptiondate',
            get_string('teachers', 'mod_booking'),
            $list,
            $options
        );
        $mform->setDefault('teachersforoptiondate', $teacherids);

        $mform->addElement('text', 'reason', get_string('reason', 'mod_booking'));
        $mform->setType('reason', PARAM_TEXT);

        if (has_capability('mod/booking:canreviewsubstitutions', context_system::instance())) {
            $mform->addElement('header', 'deductionheader', get_string('deduction', 'mod_booking'));
            $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
            $deductableteachers = [];
            foreach ($settings->teachers as $teacher) {
                // If the teacher was present at the date, we cannot log a deduction!
                if (
                    $DB->get_record('booking_optiondates_teachers', [
                    'optiondateid' => $optiondateid,
                    'userid' => $teacher->userid,
                    ])
                ) {
                    continue;
                } else {
                    $deductableteachers[] = $teacher;
                }
            }
            if (!empty($deductableteachers)) {
                foreach ($deductableteachers as $teacher) {
                    $mform->addElement(
                        'advcheckbox',
                        'deduction-teacherid-' . $teacher->userid,
                        $teacher->firstname . " " . $teacher->lastname
                    );
                    $mform->addElement(
                        'text',
                        'deductionreason-teacherid-' . $teacher->userid,
                        get_string('deductionreason', 'mod_booking')
                    );
                    $mform->hideIf(
                        'deductionreason-teacherid-' . $teacher->userid,
                        'deduction-teacherid-' . $teacher->userid
                    );
                }
            } else {
                $mform->addElement('html', '<div class="alert alert-light">' .
                    get_string('deductionnotpossible', 'mod_booking') . '</div>');
            }
        }
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

        $optionid = $data['optionid'];

        if (strlen($data['reason']) > 250) {
            $errors['reason'] = get_string('error:reasontoolong', 'mod_booking');
        }
        if (empty($data['teachersforoptiondate'])) {
            if (empty($data['reason'])) {
                $errors['reason'] = get_string('error:reasonfornoteacher', 'mod_booking');
            }
        } else {
            $teachersforoption = $DB->get_fieldset_select(
                'booking_teachers',
                'userid',
                'optionid = :optionid',
                ['optionid' => $optionid]
            );
            $teachersforoptiondate = $data['teachersforoptiondate'];
            sort($teachersforoption);
            sort($teachersforoptiondate);
            if (($teachersforoption != $teachersforoptiondate) && empty($data['reason'])) {
                $errors['reason'] = get_string('error:reasonforsubstituteteacher', 'mod_booking');
            }
        }

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        foreach ($settings->teachers as $teacher) {
            if (
                isset($data['deduction-teacherid-' . $teacher->userid])
                && $data['deduction-teacherid-' . $teacher->userid] == 1
            ) {
                if (empty(trim($data['deductionreason-teacherid-' . $teacher->userid]))) {
                    $errors['deductionreason-teacherid-' . $teacher->userid] =
                        get_string('error:reasonfordeduction', 'mod_booking');
                }
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

        $url = new moodle_url(
            '/mod/booking/optiondates_teachers_report.php',
            ['cmid' => $cmid, 'optionid' => $optionid]
        );
        return $url;
    }
}
