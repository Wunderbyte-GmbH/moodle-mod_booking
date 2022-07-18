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
 * Event observers.
 *
 * @package mod_booking
 * @copyright 2015 Andraž Prinčič <atletek@gmail.com>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
use mod_booking\booking_option;
use mod_booking\calendar;
use mod_booking\singleton_service;
use mod_booking\booking_elective;

/**
 * Event observer for mod_booking.
 */
class mod_booking_observer {

    /**
     * Observer for the user_deleted event
     *
     * @param \core\event\user_deleted $event
     */
    public static function user_deleted(\core\event\user_deleted $event) {
        global $DB;

        $params = array('userid' => $event->relateduserid);

        $DB->delete_records_select('booking_answers', 'userid = :userid', $params);
        $DB->delete_records_select('booking_teachers', 'userid = :userid', $params);
        $DB->delete_records_select('booking_optiondates_teachers', 'userid = :userid', $params);
        $DB->delete_records_select('booking_userevents', 'userid = :userid', $params);
        $DB->delete_records_select('booking_icalsequence', 'userid = :userid', $params);
    }

    /**
     * Triggered via user_enrolment_deleted event.
     *
     * @param \core\event\user_enrolment_deleted $event
     */
    public static function user_enrolment_deleted(\core\event\user_enrolment_deleted $event) {
        global $DB;
        $cp = (object) $event->other['userenrolment'];
        if ($cp->lastenrol) {
            $sql = 'SELECT bo.id, bo.bookingid
            FROM {booking_options} bo
            JOIN {booking} b ON bo.bookingid = b.id
            WHERE bo.courseid = :courseid
            AND b.removeuseronunenrol = 1';
            $params = ['courseid' => $cp->courseid];
            $options = $DB->get_records_sql($sql, $params);
            if (!empty($options)) {
                foreach ($options as $option) {
                    $bo = booking_option::create_option_from_optionid($option->id, $option->bookingid);
                    $bo->user_delete_response($cp->userid);
                }
                $optionids = array_keys($options);
                list ($insql, $inparams) = $DB->get_in_or_equal($optionids, SQL_PARAMS_NAMED);
                $inparams['userid'] = $cp->userid;
                $DB->delete_records_select('booking_teachers',
                    "userid = :userid AND optionid $insql", $inparams);
            }
        }
    }

    /**
     * When a new booking option is created, we insert a new calendar entry.
     *
     * @param \mod_booking\event\bookingoption_created $event
     */
    public static function bookingoption_created(\mod_booking\event\bookingoption_created $event) {
        new calendar($event->contextinstanceid, $event->objectid, 0, calendar::TYPEOPTION);
    }


    /**
     * @param \mod_booking\event\booking_cancelled $event
     * @throws dml_exception
     */
    public static function booking_cancelled(\mod_booking\event\booking_cancelled $event) {

        global $DB;

        $userid = $event->relateduserid;
        $optionid = $event->objectid;

        $records = $DB->get_records('booking_userevents', array('userid' => $userid, 'optionid' => $optionid));
        foreach ($records as $record) {
            $DB->delete_records('event', array('id' => $record->eventid));
            $DB->delete_records('booking_userevents', array('id' => $record->id));
        }
    }

    /**
     * Updates calendar entry for teachers when a booking option is updated.
     *
     * @param \mod_booking\event\bookingoption_updated $event
     * @throws dml_exception
     */
    public static function bookingoption_updated(\mod_booking\event\bookingoption_updated $event) {
        global $DB, $PAGE, $USER;

        $optionid = $event->objectid;
        $cmid = $event->contextinstanceid;
        $context = $event->get_context();

        $bookingoption = singleton_service::get_instance_of_booking_option($cmid, $optionid);

        $option = $bookingoption->option;

        // If there are associated optiondates (sessions) then update their calendar events.
        if ($optiondates = $DB->get_records('booking_optiondates', ['optionid' => $optionid])) {

            // Delete course event if we have optiondates (multisession!).
            if ($option->calendarid) {
                $DB->delete_records('event', array('id' => $option->calendarid));
                $data = new stdClass();
                $data->id = $optionid;
                $data->calendarid = 0;
                $DB->update_record('booking_options', $data);

                // Also, delete all associated user events.

                // Get all the user events.
                $sql = "SELECT e.* FROM {booking_userevents} ue
                JOIN {event} e
                ON ue.eventid = e.id
                WHERE ue.optionid = :optionid AND
                ue.optiondateid IS NULL";

                $allevents = $DB->get_records_sql($sql, [
                        'optionid' => $optionid]);

                // We delete all userevents and return false.

                foreach ($allevents as $eventrecord) {
                    $DB->delete_records('event', array('id' => $eventrecord->id));
                    $DB->delete_records('booking_userevents', array('id' => $eventrecord->id));
                }
            }

            foreach ($optiondates as $optiondate) {
                // Create or update the sessions.
                option_optiondate_update_event($option, $optiondate, $cmid);
            }
        } else { // This means that there are no multisessions.
            // This is for the course event.
            new calendar($event->contextinstanceid, $optionid, 0, calendar::TYPEOPTION);

            // This is for the user events.
            option_optiondate_update_event($option, null, $cmid);
        }

        $allteachers = $DB->get_fieldset_select('booking_teachers', 'userid', 'optionid = :optionid AND calendarid > 0',
            array( 'optionid' => $event->objectid));
        foreach ($allteachers as $key => $value) {
            new calendar($event->contextinstanceid, $event->objectid, $value, calendar::TYPETEACHERUPDATE);
        }
    }

    /**
     * When a new booking option date is created, we insert a new calendar entry for the session
     * and hide the old booking option calendar entry.
     *
     * @param \mod_booking\event\bookingoptiondate_created $event
     */
    public static function bookingoptiondate_created(\mod_booking\event\bookingoptiondate_created $event) {

        $optionid = $event->other['optionid'];

        new calendar($event->contextinstanceid, $optionid, 0,
            calendar::TYPEOPTIONDATE, $event->objectid);

        $cmid = $event->contextinstanceid;
        $bookingoption = singleton_service::get_instance_of_booking_option($cmid, $optionid);

        $users = $bookingoption->get_all_users_booked();
        foreach ($users as $user) {
            new calendar($event->contextinstanceid, $optionid, $user->id, calendar::TYPEOPTIONDATE, $event->objectid, 1);
        }
    }


    /**
     * When a booking option is completed, we send a mail to the user (as long as sendmail is activated).
     *
     * @param \mod_booking\event\bookingoption_completed $event
     */
    public static function bookingoption_completed(\mod_booking\event\bookingoption_completed $event) {

        $optionid = $event->objectid;
        $cmid = $event->other['cmid'];
        $selecteduserid = $event->relateduserid;

        $bookingoption = new booking_option($cmid, $optionid);

        if (empty($bookingoption->booking->settings->sendmail)) {
            // If sendmail is not set or not active, we don't do anything.
            return;
        }

        try {

            // Send a message to the user who has completed the booking option (or who has been marked for completion).
            $bookingoption->sendmessage_completed($selecteduserid);

        } catch (coding_exception | dml_exception $e) {

            debugging('Booking option completion message could not be sent. ' .
                'Exception in function observer.php/bookingoption_completed.');

        }
    }

    /**
     * Change calendar entry when custom field is changed.
     *
     * @param \mod_booking\event\custom_field_changed $event
     * @throws dml_exception
     */
    public static function custom_field_changed(\mod_booking\event\custom_field_changed $event) {
        global $DB;

        $alloptions = $DB->get_records_sql(
            "SELECT id, bookingid
            FROM {booking_options}
            WHERE addtocalendar IN (1, 2) AND calendarid > 0"
        );

        foreach ($alloptions as $key => $value) {
            $tmpcmid = $DB->get_record_sql(
                "SELECT cm.id FROM {course_modules} cm
                JOIN {modules} md ON md.id = cm.module
                JOIN {booking} m ON m.id = cm.instance
                WHERE md.name = 'booking' AND cm.instance = ?", array($value->bookingid)
            );

            new calendar($tmpcmid->id, $value->id, 0, calendar::TYPEOPTION);

            $allteachers = $DB->get_records_sql("SELECT userid FROM {booking_teachers} WHERE optionid = ? AND calendarid > 0",
                array($value->id));

            foreach ($allteachers as $keyt => $valuet) {
                new calendar($tmpcmid->id, $value->id, $valuet->userid, calendar::TYPETEACHERUPDATE);
            }
        }
    }

    /**
     * When we add teacher to booking option, we also add calendar event to their calendar.
     *
     * @param \mod_booking\event\teacher_added $event
     */
    public static function teacher_added(\mod_booking\event\teacher_added $event) {
        new calendar($event->contextinstanceid, $event->objectid, $event->relateduserid, calendar::TYPETEACHERADD);
    }

    /**
     * When teacher is removed from booking option we delete their calendar records.
     *
     * @param \mod_booking\event\teacher_removed $event
     */
    public static function teacher_removed(\mod_booking\event\teacher_removed $event) {
        new calendar($event->contextinstanceid, $event->objectid, $event->relateduserid, calendar::TYPETEACHERREMOVE);
    }

    /**
     * When a course is completed, check if the user needs to be enrolled in the next course.
     *
     * @param \core\event\course_completed $event
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    public static function course_completed(\core\event\course_completed $event) {
        global $DB;

        // Check if there is an associated booking_answer with status 'booked' for the userid and courseid.
        $sql = 'SELECT ba.userid, bo.courseid
                FROM {booking_answers} ba
                JOIN {booking_options} bo
                ON ba.optionid = bo.id
                WHERE ba.userid = :userid AND ba.waitinglist = 0 AND bo.courseid = :courseid';
        $params = ['userid' => $event->relateduserid, 'courseid' => $event->courseid];

        // Only execute if there are associated booking_answers.
        if ($bookedanswers = $DB->get_records_sql($sql, $params)) {
            // Call the enrolment function.
            booking_elective::enrol_booked_users_to_course();
        }
    }
}
