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
 * Event observer for mod_forum.
 */
class mod_booking_observer {

    /**
     * Observer for course_module_updated.
     *
     * @param \core\event\course_module_updated $event
     * @return void
     */
    public static function course_module_updated(\core\event\course_module_updated $event) {
        global $DB;

        $visible = $DB->get_record('course_modules', array('id' => $event->contextinstanceid),
                'visible');

        $showhide = new stdClass();
        $showhide->id = $event->other['instanceid'];
        $showhide->showinapi = $visible->visible;

        $DB->update_record("booking", $showhide);

        return;
    }

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
                    " userid = :userid AND bookingid IN ( SELECT id FROM {booking} WHERE course = :course)",
                    array('userid' => $cp->userid, 'course' => $cp->courseid));
            $DB->delete_records_select('booking_teachers',
                    " userid = :userid AND bookingid IN ( SELECT id FROM {booking} WHERE course = :course)",
                    array('userid' => $cp->userid, 'course' => $cp->courseid));
        }
    }
}
