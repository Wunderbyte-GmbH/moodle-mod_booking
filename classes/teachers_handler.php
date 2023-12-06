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
 * Handler for managing teachers of booking options or booking optiondates.
 *
 * @package    mod_booking
 * @copyright  2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author     Bernhard Fischer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->libdir/formslib.php");

use cache_helper;
use context_course;
use moodle_exception;
use moodle_url;
use MoodleQuickForm;
use stdClass;

/**
 * Handler for managing teachers of booking options or booking optiondates.
 *
 * @package    mod_booking
 * @copyright  2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author     Bernhard Fischer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class teachers_handler {

    /** @var int $optionid */
    public $optionid = 0;

    /**
     * Constructor.
     * @param int $optionid
     */
    public function __construct(int $optionid = 0) {
        $this->optionid = $optionid;
    }

    /**
     * Add form fields to be passed on mform.
     *
     * @param MoodleQuickForm $mform
     * @return void
     */
    public function add_to_mform(MoodleQuickForm &$mform) {
        global $DB, $OUTPUT;

        // Workaround: Only show, if it is not turned off in the option form config.
        // We currently need this, because hideIf does not work with headers.
        // In expert mode, we always show everything.
        $showteachersheader = true;
        $formmode = get_user_preferences('optionform_mode');
        if ($formmode !== 'expert') {
            $cfgteachersheader = $DB->get_field('booking_optionformconfig', 'active',
                ['elementname' => 'bookingoptionteachers']);
            if ($cfgteachersheader === "0") {
                $showteachersheader = false;
            }
        }
        if ($showteachersheader) {
            $mform->addElement('header', 'bookingoptionteachers',
                get_string('teachers', 'mod_booking'));
        }

        /* Important note: Currently, all users can be added as teachers for a booking option.
        In the future, there might be a user profile field defining users which are allowed
        to be added as teachers. */

        // We need to preload list to not only have the id, but the rendered values.

        $settings = singleton_service::get_instance_of_booking_option_settings($this->optionid);

        $list = [];
        foreach ($settings->teachers as $teacher) {
            $details = [
                'id' => $teacher->userid,
                'email' => $teacher->email,
                'firstname' => $teacher->firstname,
                'lastname' => $teacher->lastname,
            ];
            $list[$teacher->userid] =
                $OUTPUT->render_from_template(
                    'mod_booking/form-user-selector-suggestion',
                    $details);
        }

        $options = [
            'tags' => false,
            'multiple' => true,
            'noselectionstring' => '',
            'ajax' => 'mod_booking/form_users_selector',
            'valuehtmlcallback' => function($value) {
                global $OUTPUT;
                $user = singleton_service::get_instance_of_user((int)$value);
                $details = [
                    'id' => $user->id,
                    'email' => $user->email,
                    'firstname' => $user->firstname,
                    'lastname' => $user->lastname,
                ];
                return $OUTPUT->render_from_template(
                        'mod_booking/form-user-selector-suggestion', $details);
            },
        ];
        /* Important note: Currently, all users can be added as teachers for optiondates.
        In the future, there might be a user profile field defining users which are allowed
        to be added as substitute teachers. */
        $mform->addElement('autocomplete', 'teachersforoption', get_string('assignteachers', 'mod_booking'),
            $list, $options);

        $mform->addHelpButton('teachersforoption', 'teachersforoption', 'mod_booking');

        // We only show link to teaching journal if it's an already existing booking option.
        if (!empty($this->optionid) && $this->optionid > 0) {
            $optionsettings = singleton_service::get_instance_of_booking_option_settings($this->optionid);
            $optiondatesteachersreporturl = new moodle_url('/mod/booking/optiondates_teachers_report.php', [
                'cmid' => $optionsettings->cmid,
                'optionid' => $this->optionid,
            ]);
            $mform->addElement('static', 'info:teachersforoptiondates', '',
                    get_string('info:teachersforoptiondates', 'mod_booking', $optiondatesteachersreporturl->out()));
        }
    }

    /**
     * Load existing teachers into mform.
     *
     * @param MoodleQuickForm &$mform reference to mform
     */
    public function instance_form_before_set_data(MoodleQuickForm &$mform) {

        if (!empty($this->optionid) && $this->optionid > 0) {
            $optionsettings = singleton_service::get_instance_of_booking_option_settings($this->optionid);
            $teachers = $optionsettings->teachers;
            $teacherids = [];
            foreach ($teachers as $teacher) {
                $teacherids[] = $teacher->userid;
            }
            $mform->setDefaults(['teachersforoption' => $teacherids]);
        }
    }

    /**
     * Subscribe new teachers and / or unsubscribe removed teachers from booking option.
     *
     * @param stdClass &$formdata formdata
     * @param bool $doenrol true if we want to enrol teachers into course
     */
    public function save_from_form(stdClass &$formdata, bool $doenrol = true) {

        // If we don't have the key here, we ignore all of this.
        if (!isset($formdata->teachersforoption)) {
            return;
        }

        // Array of teacher ids.
        $teacherids = $formdata->teachersforoption;

        $optionsettings = singleton_service::get_instance_of_booking_option_settings($this->optionid);

        $oldteacherids = [];
        foreach ($optionsettings->teachers as $oldteacher) {
            $oldteacherids[] = $oldteacher->userid;
        }

        foreach ($teacherids as $newteacherid) {
            $dosubscribe = true;
            if (in_array($newteacherid, $oldteacherids)) {
                // Teacher is already subscribed to booking option.
                // But we still need to check if the teacher is enrolled in the associated course.
                if (isset($formdata->courseid) && $formdata->courseid == -1) {
                    $dosubscribe = true;
                } else {
                    if (empty($formdata->courseid)) {
                        // Teacher is already subscribed to booking option and there is no course.
                        $dosubscribe = false;
                    } else {
                        // There is a course, so check if the teacher is already enrolled.
                        $coursecontext = context_course::instance($formdata->courseid);
                        if (is_enrolled($coursecontext, $newteacherid, '', true)) {
                            // Teacher is already subscribed AND enrolled to the course.
                            // We do not need to call the subscribe function.
                            $dosubscribe = false;
                        }
                    }
                }
            }
            if ($dosubscribe) {
                // It's a new teacher or the teacher was not enrolled into the course.
                if (!subscribe_teacher_to_booking_option($newteacherid, $this->optionid, $optionsettings->cmid, null, $doenrol)) {
                    // Add teacher to group not yet implemented! (Third parameter of the function).
                    throw new moodle_exception('cannotaddsubscriber', 'booking', '', null,
                        'Cannot add subscriber with id: ' . $newteacherid);
                }
            }
        }

        foreach ($oldteacherids as $oldteacherid) {
            if (!in_array($oldteacherid, $teacherids)) {
                // The teacher has been removed.
                if (!unsubscribe_teacher_from_booking_option($oldteacherid, $this->optionid, $optionsettings->cmid)) {
                    throw new moodle_exception('cannotremovesubscriber', 'booking', '', null,
                        'Cannot remove subscriber with id: ' . $oldteacherid);
                }
            }
        }
    }

    // TODO: diese Functions aus dates_handler rausnehmen und von hier aus verwenden!

    /**
     * Helper function to add a new teacher to every (currently existing)
     * optiondate of an option.
     * @param int $optionid the booking option id
     * @param int $userid the user id of the teacher
     */
    public static function subscribe_teacher_to_all_optiondates(int $optionid, int $userid) {
        global $DB;

        if (empty($optionid) || empty ($userid)) {
            debugging('Could not connect teacher to optiondates because of missing userid or optionid.');
            return;
        }

        // 1. Get all currently existing optiondates of the option.
        $existingoptiondates = $DB->get_records('booking_optiondates', ['optionid' => $optionid], '', 'id');
        if (!empty($existingoptiondates)) {
            foreach ($existingoptiondates as $existingoptiondate) {
                $newentry = new stdClass;
                $newentry->optiondateid = $existingoptiondate->id;
                $newentry->userid = $userid;
                // 2. Insert the teacher into booking_optiondates_teachers for every optiondate.

                // Only do this if the record does not exist already.
                if (!$DB->record_exists('booking_optiondates_teachers',
                                        ['optiondateid' => $newentry->optiondateid, 'userid' => $newentry->userid])) {
                        $DB->insert_record('booking_optiondates_teachers', $newentry);
                }
            }
            cache_helper::purge_by_event('setbackcachedteachersjournal');
        }
    }

    /**
     * Helper function to add the option's teacher(s) to a newly created optiondate.
     * @param int $optiondateid the id of the newly created optiondate
     */
    public static function subscribe_existing_teachers_to_new_optiondate(int $optiondateid) {
        global $DB;

        if (empty($optiondateid)) {
            debugging(
                'Could not subscribe existing teacher(s) to the new optiondate because of missing optiondateid.'
            );
            return;
        }

        if ($optiondate = $DB->get_record('booking_optiondates', ['id' => $optiondateid])) {
            // Get all currently set teachers of the option.
            $teachers = $DB->get_records('booking_teachers', ['optionid' => $optiondate->optionid]);
            if (!empty($teachers)) {
                foreach ($teachers as $teacher) {
                    $newentry = new stdClass;
                    $newentry->optiondateid = $optiondate->id;
                    $newentry->userid = $teacher->userid;
                    // Insert the newly created optiondate with each teacher.
                    $DB->insert_record('booking_optiondates_teachers', $newentry);
                }
                cache_helper::purge_by_event('setbackcachedteachersjournal');
            }
        }
    }

    /**
     * Helper function to remove a teacher from every (currently existing)
     * optiondate of an option.
     * @param int $optionid the booking option id
     * @param int $userid the user id of the teacher
     */
    public static function remove_teacher_from_all_optiondates(int $optionid, int $userid) {
        global $DB;

        if (empty($optionid) || empty ($userid)) {
            throw new moodle_exception('Could not remove teacher from optiondates because of missing userid or optionid.');
        }

        // 1. Get all currently existing optiondates of the option.
        $existingoptiondates = $DB->get_records('booking_optiondates', ['optionid' => $optionid], '', 'id');
        if (!empty($existingoptiondates)) {
            foreach ($existingoptiondates as $existingoptiondate) {
                // 2. Delete the teacher from every optiondate.
                $DB->delete_records('booking_optiondates_teachers', [
                    'optiondateid' => $existingoptiondate->id,
                    'userid' => $userid,
                ]);
            }
            cache_helper::purge_by_event('setbackcachedteachersjournal');
        }
    }

    /**
     * Helper function to remove the option's teacher(s) from a deleted optiondate.
     * @param int $optiondateid the id of the deleted optiondate
     */
    public static function remove_teachers_from_deleted_optiondate(int $optiondateid) {
        global $DB;

        // Delete all entries in booking_optiondates_teachers associated with the optiondate.
        $DB->delete_records('booking_optiondates_teachers', ['optiondateid' => $optiondateid]);

        cache_helper::purge_by_event('setbackcachedteachersjournal');
    }

    /**
     * Helper function to remove all entries in booking_optiondates_teachers
     * for a specific booking instance (by bookingid).
     * @param int $bookingid the id of the booking instance
     * @param int $userid (optional) teacher id - if set only entries for this teacher will be deleted
     */
    public static function delete_booking_optiondates_teachers_by_bookingid(int $bookingid, int $userid = null) {
        global $DB;

        if (empty($bookingid)) {
            throw new moodle_exception('Could not clear entries from booking_optiondates_teachers because of missing booking id.');
        }

        // Get all currently existing optiondates of the booking instance.
        $existingoptiondates = $DB->get_records('booking_optiondates', ['bookingid' => $bookingid], '', 'id');
        if (!empty($existingoptiondates)) {
            foreach ($existingoptiondates as $existingoptiondate) {
                if (empty($userid)) {
                    $DB->delete_records('booking_optiondates_teachers', ['optiondateid' => $existingoptiondate->id]);
                } else {
                    $DB->delete_records('booking_optiondates_teachers', [
                        'optiondateid' => $existingoptiondate->id,
                        'userid' => $userid,
                    ]);
                }
            }
            cache_helper::purge_by_event('setbackcachedteachersjournal');
        }
    }

    /**
     * Helper function to remove all entries in booking_optiondates_teachers
     * for a specific booking option (by optionid).
     * @param int $optionid the id of the booking option
     */
    public static function delete_booking_optiondates_teachers_by_optionid(int $optionid) {
        global $DB;

        if (empty($optionid)) {
            throw new moodle_exception('Could not clear entries from booking_optiondates_teachers because of missing option id.');
        }

        // Get all currently existing optiondates of the option.
        $existingoptiondates = $DB->get_records('booking_optiondates', ['optionid' => $optionid], '', 'id');
        if (!empty($existingoptiondates)) {
            foreach ($existingoptiondates as $existingoptiondate) {
                $DB->delete_records('booking_optiondates_teachers', ['optiondateid' => $existingoptiondate->id]);
            }
            cache_helper::purge_by_event('setbackcachedteachersjournal');
        }
    }
}
