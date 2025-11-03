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
 * Scope base class for non-aggregated answers view.
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\booking_answers;

/**
 * Scope base class for non-aggregated answers view.
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scope_base_answers extends scope_base {
    /**
     * Helper function to get the $selectpart for the return_sql_for_booked_users function.
     * @param string $scope
     * @return string the select part of the sql query
     */
    public function get_selectpart(string $scope): string {
        // If presence counter is activated, we add that to SQL.
        $selectpresencecount = '';
        $presencecountsqlpart = '';
        if (get_config('booking', 'bookingstrackerpresencecounter')) {
            $selectpresencecount = 'pcnt.presencecount,';
            $presencecountsqlpart =
                "LEFT JOIN (
                    SELECT boda.optionid, boda.userid, COUNT(*) AS presencecount
                    FROM {booking_optiondates_answers} boda
                    WHERE boda.status = :statustocount
                    GROUP BY boda.optionid, boda.userid
                ) pcnt
                ON pcnt.optionid = ba.optionid AND pcnt.userid = u.id";
        }
        return
            "SELECT
                ba.id,
                u.id AS userid,
                u.username,
                u.firstname,
                u.lastname,
                u.email,
                ba.waitinglist,
                ba.status,
                ba.notes,
                $selectpresencecount
                ba.timemodified,
                ba.timecreated,
                cm.id AS cmid,
                c.id AS courseid,
                c.fullname AS coursename,
                ba.optionid,
                bo.titleprefix,
                bo.text,
                b.name AS instancename,
                ba.json,
                '" . $scope . "' AS scope
            FROM {booking_answers} ba
            JOIN {booking_options} bo ON ba.optionid = bo.id
            JOIN {course_modules} cm ON bo.bookingid = cm.instance
            JOIN {booking} b ON b.id = bo.bookingid
            JOIN {course} c ON c.id = b.course
            JOIN {modules} m ON m.id = cm.module
            JOIN {user} u ON ba.userid = u.id
            $presencecountsqlpart";
    }

    /**
     * Helper function to get the $endpart for the return_sql_for_booked_users function.
     * @return string the end part of the sql query
     */
    public function get_endpart(): string {
        return "ORDER BY titleprefix, text, lastname, firstname, timemodified ASC";
    }

    /**
     * This functions defines the columns for each scope.
     *
     * @param int $statusparam
     *
     * @return array
     *
     */
    public function return_cols_for_tables(int $statusparam): array {
        $columns = [
            'titleprefix' => get_string('titleprefix', 'mod_booking'),
            'text' => get_string('bookingoption', 'mod_booking'),
            'firstname' => get_string('firstname', 'core'),
            'lastname'  => get_string('lastname', 'core'),
            'email'     => get_string('email', 'core'),
        ];

        if ($statusparam == 0) {
            if (get_config('booking', 'bookingstrackerpresencecounter')) {
                $columns['presencecount'] = get_string('presencecount', 'mod_booking');
            }
            $columns['status'] = get_string('presence', 'mod_booking');
            $columns['notes'] = get_string('notes', 'mod_booking');
        }
        $columns['timemodified'] = get_string('timemodified', 'mod_booking');

        return $columns;
    }
}
