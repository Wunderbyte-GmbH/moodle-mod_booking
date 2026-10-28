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
 * Sync of course completions to the booking options of a linked course category.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\sync;

use mod_booking\local\autocreateoption;
use mod_booking\singleton_service;
use mod_booking\utils\wb_payment;
use stdClass;

/**
 * Completes the booking of a user in the option of a course when the user completes the course.
 *
 * Applies to the options of a course in the booking instances that link the course's category with the setting
 * "Complete the booking when the course is completed" on: the option auto-created for the course (connected to it
 * or not) and any other option of the instance connected to the course. The booking gets the completion date of
 * the course (course_completions.timecompleted), so certificates and reports carry the date the course was
 * completed, not the date the sync ran. A booking that is completed already is never changed.
 *
 * The observer handles the course_completed event. The backfill ({@see self::sync_course()}) handles completions
 * that happened before the booking existed, e.g. when the enrolment sync books a user who completed the course
 * long ago, or when the category is synced by hand.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class completionsync {
    /**
     * Called by the course_completed observer.
     *
     * Cheap for courses outside any linked category: one lookup in the cached category map.
     *
     * @param int $courseid
     * @param int $userid the user who completed the course
     * @param int $timecompleted the completion time of the course, 0 = unknown (now is used)
     * @return int[] the ids of the booking answers that were completed
     */
    public static function handle_course_completed(int $courseid, int $userid, int $timecompleted): array {
        $completed = [];
        if ($courseid <= 0 || $userid <= 0) {
            return $completed;
        }
        $bookingids = self::get_instances_for_course($courseid);
        if (empty($bookingids)) {
            return $completed;
        }
        $timecompleted = $timecompleted > 0 ? $timecompleted : time();
        foreach ($bookingids as $bookingid) {
            foreach (autocreateoption::get_option_ids_for_course($bookingid, $courseid) as $optionid) {
                $answerid = self::complete_answer($optionid, $userid, $timecompleted);
                if ($answerid > 0) {
                    $completed[] = $answerid;
                }
            }
        }
        return $completed;
    }

    /**
     * Completes the bookings of the users who completed the course already (backfill).
     *
     * Called after the enrolment sync of a course has booked its users and after a category was synced by hand.
     *
     * @param int $courseid
     * @param int $bookingid only this instance, 0 = every instance that links the course's category
     * @return int the number of bookings that were completed
     */
    public static function sync_course(int $courseid, int $bookingid = 0): int {
        global $DB;

        $bookingids = self::get_instances_for_course($courseid);
        if ($bookingid > 0) {
            $bookingids = in_array($bookingid, $bookingids, true) ? [$bookingid] : [];
        }
        if (empty($bookingids)) {
            return 0;
        }

        $count = 0;
        foreach ($bookingids as $instanceid) {
            foreach (autocreateoption::get_option_ids_for_course($instanceid, $courseid) as $optionid) {
                $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
                $answers = singleton_service::get_instance_of_booking_answers($settings);
                $userids = [];
                foreach ($answers->get_usersonlist() as $userid => $user) {
                    if (empty($user->completed)) {
                        $userids[] = (int)$userid;
                    }
                }
                if (empty($userids)) {
                    continue;
                }
                [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
                $params['course'] = $courseid;
                $completions = $DB->get_records_select(
                    'course_completions',
                    "course = :course AND timecompleted > 0 AND userid $insql",
                    $params,
                    '',
                    'userid, timecompleted'
                );
                foreach ($completions as $completion) {
                    if (self::complete_answer($optionid, (int)$completion->userid, (int)$completion->timecompleted) > 0) {
                        $count++;
                    }
                }
            }
        }
        return $count;
    }

    /**
     * The booking instances with the completion sync on that link the category of the course.
     *
     * @param int $courseid
     * @return int[]
     */
    private static function get_instances_for_course(int $courseid): array {
        global $DB;

        $map = autocreateoption::get_category_map();
        if (empty($map)) {
            return [];
        }
        $categoryid = (int)$DB->get_field('course', 'category', ['id' => $courseid]);
        if ($categoryid <= 0 || empty($map[$categoryid])) {
            return [];
        }
        if (!wb_payment::pro_version_is_activated()) {
            return [];
        }

        $bookingids = [];
        foreach ($map[$categoryid] as $bookingid) {
            $settings = singleton_service::get_instance_of_booking_settings_by_bookingid((int)$bookingid);
            $json = $settings->jsonobject ?? new stdClass();
            if (!empty($json->{autocreateoption::JSONKEY_SYNCCOMPLETION})) {
                $bookingids[] = (int)$bookingid;
            }
        }
        return $bookingids;
    }

    /**
     * Completes the booked answer of a user in an option with the given date, unless it is completed already.
     *
     * Goes through booking_option::toggle_user_completion(), so the completion event, the activity completion of
     * the booking instance, the certificate and the history entry are the same as for a completion by hand.
     *
     * @param int $optionid
     * @param int $userid
     * @param int $timecompleted
     * @return int the id of the completed answer, 0 if nothing was completed
     */
    private static function complete_answer(int $optionid, int $userid, int $timecompleted): int {
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        if (empty($settings->id) || empty($settings->cmid)) {
            return 0;
        }
        $answers = singleton_service::get_instance_of_booking_answers($settings);
        $users = $answers->get_usersonlist();
        if (empty($users[$userid]) || !empty($users[$userid]->completed)) {
            return 0;
        }
        $answerid = (int)($users[$userid]->baid ?? 0);
        $option = singleton_service::get_instance_of_booking_option((int)$settings->cmid, $optionid);
        if (!$option->toggle_user_completion($userid, 0, false, $timecompleted)) {
            return 0;
        }
        return $answerid;
    }
}
