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
defined('MOODLE_INTERNAL') || die();

/**
 * Event observer for mod_booking.
 */
class mod_booking_observer {

    /**
     * Observer for the user_deleted event.
     *
     * @param \core\event\user_deleted $event
     */
    public static function user_deleted(\core\event\user_deleted $event) {
        global $DB;

        $params = array('userid' => $event->relateduserid);

        $DB->delete_records_select('booking_answers', 'userid = :userid', $params);
        $DB->delete_records_select('booking_teachers', 'userid = :userid', $params);
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
            $DB->delete_records_select('booking_answers',
                    " userid = :userid
                      AND bookingid IN ( SELECT id FROM {booking} WHERE course = :course AND removeuseronunenrol = 1)",
                    array('userid' => $cp->userid, 'course' => $cp->courseid));
            $DB->delete_records_select('booking_teachers',
                    " userid = :userid AND bookingid IN ( SELECT id FROM {booking} WHERE course = :course)",
                    array('userid' => $cp->userid, 'course' => $cp->courseid));
        }
    }

    /**
     * Updates calendar entry for teachers when a booking option is updated.
     *
     * @param \mod_booking\event\bookingoption_updated $event
     * @throws dml_exception
     */
    public static function bookingoption_updated(\mod_booking\event\bookingoption_updated $event) {
        global $DB;

        new \mod_booking\calendar($event->contextinstanceid, $event->objectid, 0, \mod_booking\calendar::TYPEOPTION);

        $allteachers = $DB->get_fieldset_select('booking_teachers', 'userid', 'optionid = :optionid AND calendarid > 0', array( 'optionid' => $event->objectid));
        foreach ($allteachers as $key => $value) {
            new \mod_booking\calendar($event->contextinstanceid, $event->objectid, $value->userid, \mod_booking\calendar::TYPETEACHERUPDATE);
        }
    }

    /**
     * When custom field is renamed, we need to regenerate calendar recors.
     *
     * @param \mod_booking\event\custom_field_changed $event
     */
    public static function custom_field_changed(\mod_booking\event\custom_field_changed $event) {
        global $DB;

        $alloptions = $DB->get_records_sql('SELECT id, bookingid FROM {booking_options} WHERE addtocalendar = 1 AND calendarid > 0');

        foreach ($alloptions as $key => $value) {
            $tmpcmid = $DB->get_record_sql(
                "SELECT cm.id FROM {course_modules} cm
                JOIN {modules} md ON md.id = cm.module
                JOIN {booking} m ON m.id = cm.instance
                WHERE md.name = 'booking' AND cm.instance = ?", array($value->bookingid));

                new \mod_booking\calendar($tmpcmid->id, $value->id, 0, \mod_booking\calendar::TYPEOPTION);

                $allteachers = $DB->get_records_sql('SELECT userid FROM {booking_teachers} WHERE optionid = ? AND calendarid > 0', array($value->id));
            foreach ($allteachers as $keyt => $valuet) {
                new \mod_booking\calendar($tmpcmid->id, $value->id, $valuet->userid, \mod_booking\calendar::TYPETEACHERUPDATE);
            }
        }
    }

    /**
     * When new booking option is created, we insert new calendar entry.
     *
     * @param \mod_booking\event\bookingoption_created $event
     */
    public static function bookingoption_created(\mod_booking\event\bookingoption_created $event) {
        new \mod_booking\calendar($event->contextinstanceid, $event->objectid, 0, \mod_booking\calendar::TYPEOPTION);
    }

    /**
     * When we add teacher to booking option, we also add calendar event to their calendar.
     *
     * @param \mod_booking\event\teacher_added $event
     */
    public static function teacher_added(\mod_booking\event\teacher_added $event) {
        new \mod_booking\calendar($event->contextinstanceid, $event->objectid, $event->relateduserid, \mod_booking\calendar::TYPETEACHERADD);
    }

    /**
     * When teacher is removed from booking option we delete their calendar records.
     *
     * @param \mod_booking\event\teacher_removed $event
     */
    public static function teacher_removed(\mod_booking\event\teacher_removed $event) {
        new \mod_booking\calendar($event->contextinstanceid, $event->objectid, $event->relateduserid, \mod_booking\calendar::TYPETEACHERREMOVE);
    }
}
