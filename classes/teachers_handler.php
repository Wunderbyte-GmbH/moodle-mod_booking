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

define('MOD_BOOKING_FORM_TEACHERS', 'teachersforoption');

global $CFG;
require_once("$CFG->libdir/formslib.php");

use cache_helper;
use coding_exception;
use context_course;
use context_module;
use context_system;
use dml_exception;
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

        $mform->addElement(
            'header',
            'bookingoptionteachers',
            '<i class="fa fa-fw fa-graduation-cap" aria-hidden="true"></i>&nbsp;' .
            get_string('teachers', 'mod_booking')
        );

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
                    $details
                );
        }

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
                    'id' => $user->id ?? 0,
                    'email' => $user->email ?? '',
                    'firstname' => $user->firstname ?? '',
                    'lastname' => $user->lastname ?? '',
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
            'teachersforoption',
            get_string('assignteachers', 'mod_booking'),
            $list,
            $options
        );

        $mform->addHelpButton('teachersforoption', 'teachersforoption', 'mod_booking');

        // We only show link to teaching journal if it's an already existing booking option.
        if (!empty($this->optionid) && $this->optionid > 0) {
            $optionsettings = singleton_service::get_instance_of_booking_option_settings($this->optionid);
            $optiondatesteachersreporturl = new moodle_url('/mod/booking/optiondates_teachers_report.php', [
                'cmid' => $optionsettings->cmid,
                'optionid' => $this->optionid,
            ]);
            $mform->addElement(
                'static',
                'info:teachersforoptiondates',
                '',
                get_string('info:teachersforoptiondates', 'mod_booking', $optiondatesteachersreporturl->out())
            );
        }
    }

    /**
     * Load existing teachers into mform.
     *
     * @param MoodleQuickForm $mform reference to mform
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
     * Load existing teachers into mform.
     *
     * @param stdClass $data reference to data
     */
    public function set_data(stdClass &$data) {

        if (
            !isset($data->{MOD_BOOKING_FORM_TEACHERS})
            && !empty($this->optionid)
            && $this->optionid > 0
        ) {
            $optionsettings = singleton_service::get_instance_of_booking_option_settings($this->optionid);
            $teachers = $optionsettings->teachers;
            $teacherids = [];
            foreach ($teachers as $teacher) {
                $teacherids[] = $teacher->userid;
            }
            $data->{MOD_BOOKING_FORM_TEACHERS} = $teacherids;
        }
    }

    /**
     * Subscribe new teachers and / or unsubscribe removed teachers from booking option.
     *
     * @param stdClass $formdata formdata
     * @param bool $doenrol true if we want to enrol teachers into course
     */
    public function save_from_form(stdClass &$formdata, bool $doenrol = true) {

        global $DB;

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
            if (empty($newteacherid)) {
                continue;
            }
            $dosubscribe = true;
            if (in_array($newteacherid, $oldteacherids)) {
                // Teacher is already subscribed to booking option.
                // But we still need to check if the teacher is enrolled in the associated course.
                if (!empty($formdata->courseid) && $DB->record_exists('course', ['id' => $formdata->courseid])) {
                            // There is a course, so check if the teacher is already enrolled.
                    $coursecontext = context_course::instance($formdata->courseid);
                    if (is_enrolled($coursecontext, $newteacherid, '', true)) {
                        // Teacher is already subscribed AND enrolled to the course.
                        // We do not need to call the subscribe function.
                        $dosubscribe = false;
                    }
                }
            }
            if ($dosubscribe) {
                // It's a new teacher or the teacher was not enrolled into the course.
                if (
                    !self::subscribe_teacher_to_booking_option(
                        $newteacherid,
                        $this->optionid,
                        $optionsettings->cmid,
                        null,
                        $doenrol,
                        (int)($formdata->courseid ?? 0)
                    )
                ) {
                    // Add teacher to group not yet implemented! (Third parameter of the function).
                    throw new moodle_exception(
                        'cannotaddsubscriber',
                        'booking',
                        '',
                        null,
                        'Cannot add subscriber with id: ' . $newteacherid
                    );
                }
            }
        }

        foreach ($oldteacherids as $oldteacherid) {
            if (!in_array($oldteacherid, $teacherids)) {
                // The teacher has been removed.
                if (
                    !empty($oldteacherid)
                    && !self::unsubscribe_teacher_from_booking_option(
                        $oldteacherid,
                        $this->optionid,
                        $optionsettings->cmid
                    )
                ) {
                    throw new moodle_exception(
                        'cannotremovesubscriber',
                        'booking',
                        '',
                        null,
                        'Cannot remove subscriber with id: ' . $oldteacherid
                    );
                }
            }
        }
    }

    /**
     * Adds user as teacher (booking manager) to a booking option
     *
     * @param int $userid
     * @param int $optionid
     * @param int $cmid
     * @param mixed $groupid the group object or group id
     * @param bool $doenrol true if we want to enrol the teacher into the relevant course
     * @param int $courseid true if we want to enrol the teacher into the relevant course
     * @return bool true if teacher was subscribed
     */
    public function subscribe_teacher_to_booking_option(
        int $userid,
        int $optionid,
        int $cmid,
        $groupid = null,
        bool $doenrol = true,
        int $courseid = 0
    ) {

        global $DB, $USER, $COURSE;

        // On template creation, we don't have a cmid, we don't want to enrol the user.
        if (!empty($cmid)) {
            $option = singleton_service::get_instance_of_booking_option($cmid, $optionid);
            // Get settings of the booking instance (do not confuse with option settings).
            $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);

            // Always enrol into current course with defined role.
            $teacherrole = get_config('booking', 'definedteacherrole');
            if ($teacherrole) {
                $option->enrol_user($userid, true, $teacherrole, true, $bookingsettings->course);
            }

            // Even if teacher already exists in DB, we still might want to enrol him/her into a NEW course.
            if (
                $doenrol
                && !empty($bookingsettings->teacherroleid)
            ) {
                // We enrol teacher with the type defined in settings.
                $option->enrol_user($userid, true, $bookingsettings->teacherroleid, true, $courseid);

                /* NOTE: In the future, we might need a teacher_enrolled event here (or inside enrol_user)
                which indicates that a teacher has been enrolled into a Moodle course. */
            }
        }

        if ($DB->record_exists("booking_teachers", ["userid" => $userid, "optionid" => $optionid])) {
            return true;
        }

        $newteacherrecord = new stdClass();
        $newteacherrecord->userid = $userid;
        $newteacherrecord->optionid = $optionid;
        $newteacherrecord->bookingid = $bookingsettings->id ?? 0;

        $inserted = $DB->insert_record("booking_teachers", $newteacherrecord);

        // When inserting a new teacher, we also need to insert the teacher for each future optiondate.
        // We do not add the teacher to optiondates in the past as they are already over.
        // If needed, the teacher can still be added manually via teachers journal.
        self::subscribe_teacher_to_all_optiondates($optionid, $userid, time());

        if (!empty($groupid)) {
            groups_add_member($groupid, $userid);
        }

        if (empty($cmid)) {
            $context = context_system::instance();
        } else {
            $context = context_module::instance($cmid);
        }

        if ($inserted) {
            $event = \mod_booking\event\teacher_added::create([
                'userid' => $USER->id, // The logged-in user.
                'relateduserid' => $userid, // This is the teacher!
                'objectid' => $optionid,
                'context' => $context,
            ]);
            $event->trigger();
        }

        return $inserted;
    }

    /**
     * Removes teacher from the subscriber list.
     *
     * @param int $userid
     * @param int $optionid
     * @param int $cmid
     * @return bool true if successful
     */
    public function unsubscribe_teacher_from_booking_option(int $userid, int $optionid, int $cmid) {
        global $DB, $USER;

        $event = \mod_booking\event\teacher_removed::create(
            ['userid' => $USER->id, 'relateduserid' => $userid, 'objectid' => $optionid,
                    'context' => context_module::instance($cmid),
            ]
        );
        $event->trigger();

        // Also delete the teacher from every optiondate in the future.
        // We do not remove the teacher from dates in the past as (s)he might have been present.
        // If needed, the entries can be removed manually via teachers journal.
        self::remove_teacher_from_all_optiondates($optionid, $userid, time());

        return ($DB->delete_records(
            'booking_teachers',
            ['userid' => $userid, 'optionid' => $optionid]
        ));
    }

    // phpcs:ignore moodle.Commenting.TodoComment.MissingInfoInline
    // TODO: diese Functions aus dates_handler rausnehmen und von hier aus verwenden!

    /**
     * Helper function to add a new teacher to every (currently existing)
     * optiondate of an option.
     * @param int $optionid the booking option id
     * @param int $userid the user id of the teacher
     * @param int $timestamp if supplied, the teacher will be added only to optiondates AFTER this date
     */
    public static function subscribe_teacher_to_all_optiondates(int $optionid, int $userid, int $timestamp = 0) {

        global $DB;

        if (empty($optionid) || empty($userid)) {
            debugging('Could not connect teacher to optiondates because of missing userid or optionid.');
            return;
        }

        // 1. Get all currently existing optiondates of the option.
        $existingoptiondates = $DB->get_records('booking_optiondates', ['optionid' => $optionid]);
        if (!empty($existingoptiondates)) {
            foreach ($existingoptiondates as $existingoptiondate) {
                // If a timestamp was supplied, then we only add the teacher...
                // ...to optiondates AFTER this timestamp.
                if (!empty($timestamp) && $existingoptiondate->coursestarttime < $timestamp) {
                    continue;
                }
                $newentry = new stdClass();
                $newentry->optiondateid = $existingoptiondate->id;
                $newentry->userid = $userid;
                // 2. Insert the teacher into booking_optiondates_teachers for every optiondate.

                // Only do this if the record does not exist already.
                if (
                    !$DB->record_exists(
                        'booking_optiondates_teachers',
                        ['optiondateid' => $newentry->optiondateid, 'userid' => $newentry->userid]
                    )
                ) {
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
                    $newentry = new stdClass();
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
     * @param int $timestamp if supplied, the teacher will be removed only from optiondates AFTER this date
     */
    public static function remove_teacher_from_all_optiondates(int $optionid, int $userid, int $timestamp = 0) {
        global $DB;

        if (empty($optionid) || empty($userid)) {
            throw new moodle_exception('Could not remove teacher from optiondates because of missing userid or optionid.');
        }

        // 1. Get all currently existing optiondates of the option.
        $existingoptiondates = $DB->get_records('booking_optiondates', ['optionid' => $optionid]);
        if (!empty($existingoptiondates)) {
            foreach ($existingoptiondates as $existingoptiondate) {
                // If we have a timestamp set, we only remove the teacher from optiondates AFTER this timestamp.
                if (!empty($timestamp) && $existingoptiondate->coursestarttime < $timestamp) {
                    continue;
                }

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
     * @param ?int $userid (optional) teacher id - if set only entries for this teacher will be deleted
     */
    public static function delete_booking_optiondates_teachers_by_bookingid(int $bookingid, ?int $userid = null) {
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

    /**
     * Get teacher ids.
     * @param stdClass $data
     * @param bool $throwerror If not finding the email should throw an error.
     * @return array|void
     * @throws coding_exception
     * @throws dml_exception
     */
    public static function get_teacherids_from_form(stdClass $data, $throwerror = false) {

        global $DB;

        if (isset($data->teacheremail)) {
            return self::get_user_ids_from_string($data->teacheremail, $throwerror);
        }
    }

    /**
     * This function can retrieve the userids from a string with either emails or usernames.
     *
     * @param string|array $users string can contain userids, emails or usernames, can also be already an array of userids
     * @param bool $email // if false, it's usernames, not usermails.
     * @param bool $throwerror If not finding the email should throw an error.
     * @return array
     */
    public static function get_user_ids_from_string($users, $email = true, $throwerror = false) {

        global $DB;

        if (empty($users)) {
            return [];
        }
        // First we explode teacheremail, there might be mulitple teachers.
        // We always use comma as separator.
        $teacheremails = array_map('strtolower', explode(',', $users)); // Convert input to lowercase.
        $column = $email ? 'LOWER(email)' : 'LOWER(username)';  // Ensure case-insensitive comparison.

        [$inorequal, $params] = $DB->get_in_or_equal($teacheremails, SQL_PARAMS_NAMED);

        $sql = "SELECT id
                FROM {user}
                WHERE " . $DB->sql_equal('suspended', 0)
                . " AND " . $DB->sql_equal('deleted', 0)
                . " AND " . $DB->sql_equal('confirmed', 1)
                . " AND $column $inorequal
        ";

        $teacherids = $DB->get_fieldset_sql($sql, $params);

        if ($throwerror && (count($teacherids) != count($teacheremails))) {
            throw new moodle_exception(
                'userswerenotfound',
                'mod_booking',
                '',
                $teacheremails,
                'The following users were not found ' . json_encode($teacheremails)
            );
        }

        return $teacherids;
    }
}
