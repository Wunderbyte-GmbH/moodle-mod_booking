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
 * Booking answers scope class.
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\booking_answers\scopes;

use context_course;
use local_wunderbyte_table\wunderbyte_table;
use mod_booking\booking_answers\scope_base;
use mod_booking\output\booked_users;
use mod_booking\table\manageusers_table;

/**
 * Class for booking answers.
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course extends scope_base {
    /**
     * Returns the sql to fetch booked users with a certain status.
     * Orderd by timemodified, to be able to sort them.
     * @param string $scope option | instance | course | system
     * @param int $scopeid optionid | cmid | courseid | 0
     * @param int $statusparam
     * @return (string|int[])[]
     */
    public function return_sql_for_booked_users(string $scope, int $scopeid, int $statusparam) {

        $courseid = $scopeid;
        $fields = 's1.*';
        $where = ' 1 = 1 ';
        $from = " (
            SELECT
                bo.id,
                bo.id as optionid,
                ba.waitinglist,
                cm.id AS cmid,
                c.id AS courseid,
                c.fullname AS coursename,
                bo.titleprefix,
                bo.text,
                b.name AS instancename,
                COUNT(ba.id) answerscount,
                SUM(pcnt.presencecount) presencecount,
                '" . $scope . "' AS scope
            FROM {booking_options} bo
            LEFT JOIN {booking_answers} ba ON bo.id = ba.optionid
            LEFT JOIN {user} u ON ba.userid = u.id
            JOIN {course_modules} cm ON bo.bookingid = cm.instance
            JOIN {booking} b ON b.id = bo.bookingid
            JOIN {course} c ON c.id = b.course
            JOIN {modules} m ON m.id = cm.module
            LEFT JOIN (
                SELECT boda.optionid, boda.userid, COUNT(*) AS presencecount
                FROM {booking_optiondates_answers} boda
                WHERE boda.status = :statustocount
                GROUP BY boda.optionid, boda.userid
            ) pcnt
            ON pcnt.optionid = ba.optionid AND pcnt.userid = u.id
            WHERE
                m.name = 'booking'
                AND ba.waitinglist = :statusparam
                AND c.id = :courseid
            GROUP BY cm.id, c.id, c.fullname, bo.id, ba.waitinglist, bo.titleprefix, bo.text, b.name
            ORDER BY bo.titleprefix, bo.text ASC
                LIMIT 10000000000
        ) s1";
        $params = [
            'statusparam' => $statusparam,
            'statustocount' => get_config('booking', 'bookingstrackerpresencecountervaluetocount'),
            'courseid' => $courseid,
        ];

        return [$fields, $from, $where, $params];
    }

    /**
     * Helper function to check capability for logged-in user in provided scope.
     * @param int $scopeid
     * @param string $capability
     */
    public function has_capability_in_scope($scopeid, $capability) {
        return has_capability($capability, context_course::instance($scopeid));
    }
}
