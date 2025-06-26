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

namespace mod_booking\booking_answers;

use context_system;
use local_wunderbyte_table\wunderbyte_table;
use mod_booking\table\manageusers_table;
use moodle_url;

/**
 * Class for booking answers.
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scope_base {
    /**
     * Render users table based on status param
     *
     * @param string $scope
     * @param int $scopeid
     * @param int $statusparam
     * @param string $tablenameprefix
     * @param array $columns
     * @param array $headers
     * @param bool $sortable
     * @param bool $paginate
     * @return ?string
     */
    public function return_users_table(
        string $scope,
        int $scopeid,
        int $statusparam,
        string $tablenameprefix,
        array $columns,
        array $headers = [],
        bool $sortable = false,
        bool $paginate = false
    ): wunderbyte_table {
        [$fields, $from, $where, $params] = self::return_sql_for_booked_users($scope, $scopeid, $statusparam);

        $tablename = "{$tablenameprefix}_{$scope}_{$scopeid}";
        $table = new manageusers_table($tablename);

        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        // Todo: $table->define_baseurl() ...
        $table->define_cache('mod_booking', "bookedusertable");
        $table->define_columns($columns);
        $table->define_headers($headers);

        if ($sortable) {
            $table->sortablerows = true;
        }

        if ($paginate) {
            $table->use_pages = true;
        }

        $table->set_sql($fields, $from, $where, $params);

        $this->show_download_button($table, $scope, $scopeid, $statusparam);

        // Other scopes (system, course, instance).
        $table->define_fulltextsearchcolumns(['titleprefix', 'text', 'coursename', 'instancename']);

        $sortablecolumns = [
            'titleprefix' => get_string('titleprefix', 'mod_booking'),
            'text' => get_string('bookingoption', 'mod_booking'),
            'answerscount' => get_string('answerscount', 'mod_booking'),
            'presencecount' => get_string('presencecount', 'mod_booking'),
        ];
        $table->define_sortablecolumns($sortablecolumns);

        return $table;
    }

    /**
     * Returns the sql to fetch booked users with a certain status.
     * Orderd by timemodified, to be able to sort them.
     * @param string $scope option | instance | course | system
     * @param int $scopeid optionid | cmid | courseid | 0
     * @param int $statusparam
     * @return (string|int[])[]
     */
    public function return_sql_for_booked_users(string $scope, int $scopeid, int $statusparam) {

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
            GROUP BY cm.id, c.id, c.fullname, bo.id, ba.waitinglist, bo.titleprefix, bo.text, b.name
            ORDER BY bo.titleprefix, bo.text ASC
                LIMIT 10000000000
        ) s1";
        $params = [
            'statusparam' => $statusparam,
            'statustocount' => get_config('booking', 'bookingstrackerpresencecountervaluetocount'),
        ];

        return [$fields, $from, $where, $params];
    }

    /**
     * This functions defines the columns for each scope.
     *
     * @param int $statusparam
     *
     * @return array
     *
     */
    public function return_cols_for_tables(int $statusparam) {

        $columns = [
            'titleprefix' => get_string('titleprefix', 'mod_booking'),
            'text'  => get_string('bookingoption', 'mod_booking'),
            'answerscount'     => get_string('answerscount', 'mod_booking'),
        ];

        if (get_config('booking', 'bookingstrackerpresencecounter')) {
            $columns['presencecount'] = get_string('presencecount', 'mod_booking');
        }

        switch ($statusparam) {
            case MOD_BOOKING_STATUSPARAM_BOOKED:
                break;
            case MOD_BOOKING_STATUSPARAM_WAITINGLIST:
                break;
            case MOD_BOOKING_STATUSPARAM_RESERVED:
                break;
            case MOD_BOOKING_STATUSPARAM_NOTIFYMELIST:
                break;
            case MOD_BOOKING_STATUSPARAM_NOTBOOKED:
                break;
            case MOD_BOOKING_STATUSPARAM_BOOKED_DELETED:
                break;
        }

        return $columns;
    }

    /**
     * Each scope can decide under which circumstances it actually adds the downloadbutton.
     *
     * @param wunderbyte_table $table
     * @param string $scope
     * @param int $scopeid
     * @param int $statusparam
     *
     * @return [type]
     *
     */
    public function show_download_button(wunderbyte_table &$table, string $scope, int $scopeid, int $statusparam) {
        if (self::has_capability_in_scope($scopeid, 'mod/booking:updatebooking')) {
            $baseurl = new moodle_url(
                '/mod/booking/download_report2.php',
                [
                    'scope' => self::return_classname(),
                    'statusparam' => $statusparam,
                ]
            );
            $table->define_baseurl($baseurl);

            // We currently support download for booked users only.
            if ($statusparam == 0) {
                $table->showdownloadbutton = true;
            }
        }
    }

    /**
     * Return classname.
     *
     * @return string
     *
     */
    public static function return_classname(): string {
        $classnamewith = static::class;
        $classparts = explode('\\', $classnamewith);
        $classname = end($classparts);
        return $classname;
    }

    /**
     * Helper function to check capability for logged-in user in provided scope.
     * @param int $scopeid
     * @param string $capability
     */
    public function has_capability_in_scope($scopeid, $capability) {
        return has_capability($capability, context_system::instance());
    }
}
