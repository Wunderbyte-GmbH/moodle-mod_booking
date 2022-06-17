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
namespace mod_booking\utils;

class db {

    /**
     * Return all booking options where i'm enroled.
     *
     * @return array
     */
    public function mybookings() {
        global $USER, $DB;
        $sql = "SELECT
        ba.id,
        c.id courseid,
        c.fullname,
        b.id bookingid,
        b.name,
        bo.text,
        bo.id optionid,
        bo.coursestarttime,
        bo.courseendtime,
        cm.id cmid
        FROM
        {booking_answers} ba
            LEFT JOIN
        {booking_options} bo ON ba.optionid = bo.id
            LEFT JOIN
        {booking} b ON b.id = bo.bookingid
            LEFT JOIN
        {course} c ON c.id = b.course
            LEFT JOIN
            {course_modules} cm ON cm.module = (SELECT
                    id
                FROM
                    {modules}
                WHERE
                    name = 'booking')
                AND instance = b.id
        WHERE
            userid = ?
            AND cm.visible = 1
        ORDER BY c.id ASC, b.id ASC , bo.id ASC";

        return $DB->get_records_sql($sql, array($USER->id), 0, 0);
    }

    /**
     * Get all badges for course.
     *
     * @param $courseid
     *
     * @return array
     */
    public function getbadges($courseid = null) {
        global $DB;

        if (!empty($courseid)) {
            $sql = 'SELECT b.id, b.name FROM {badge} b WHERE ' .
                'b.status = 1 OR b.status = 3 ORDER BY b.name ASC';
            $params = array();
            $params['courseid'] = $courseid;

            return $DB->get_records_sql_menu($sql, $params);
        } else {
            return array();
        }
    }

    /**
     * Get all users that have or not have cerain activity completed.
     *
     * @param $cmid
     * @param $optionid
     * @param $completed If true, return users who completed activity.
     *
     * @return array of matching users.
     */
    public function getusersactivity($cmid = null, $optionid = null, $completed = false) {
        global $DB;

        $ud = array();
        $oud = array();
        $users = $DB->get_records('course_modules_completion', array('coursemoduleid' => $cmid));
        $ousers = $DB->get_records('booking_answers', array('optionid' => $optionid));

        foreach ($users as $u) {
            $ud[] = $u->userid;
        }

        foreach ($ousers as $u) {
            $oud[] = $u->userid;
        }

        if ($completed) {
            return array_intersect($oud, $ud);
        } else {
            return array_diff($oud, $ud);
        }
    }

    /**
     * Get all users that have certain badge.
     *
     * @param $badgeid
     * @param $optionid
     *
     * @return array of matching users.
     */
    public function getusersbadges($badgeid = null, $optionid = null) {
        global $DB;

        $ud = array();
        $oud = array();
        $users = $DB->get_records('badge_issued', array('badgeid' => $badgeid));
        $ousers = $DB->get_records('booking_answers', array('optionid' => $optionid));

        foreach ($users as $u) {
            $ud[] = $u->userid;
        }

        foreach ($ousers as $u) {
            $oud[] = $u->userid;
        }

        return array_intersect($oud, $ud);
    }
}
