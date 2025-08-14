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
use mod_booking\output\booked_users;
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
     * @return wunderbyte_table|null
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
    ) {
        [$fields, $from, $where, $params] = $this->return_sql_for_booked_users($scope, $scopeid, $statusparam);

        $viewtype = get_user_preferences('bookingstrackerviewtype');

        $tablename = "{$tablenameprefix}_{$scope}_{$scopeid}_{$viewtype}";
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

        switch ($viewtype) { // Non-aggregated. Individual booking answers.
            case 'answers':
                $table->define_fulltextsearchcolumns(['firstname', 'lastname', 'email']);
                switch ($statusparam) {
                    case MOD_BOOKING_STATUSPARAM_DELETED:
                        $sortablecolumns = [
                            'firstname' => get_string('firstname'),
                            'lastname' => get_string('lastname'),
                            'email' => get_string('email'),
                            'timemodified' => get_string('timemodified', 'mod_booking'),
                        ];
                        $table->sort_default_column = 'timemodified';
                        $table->sort_default_order = SORT_DESC;
                        break;
                    case MOD_BOOKING_STATUSPARAM_BOOKED:
                        $sortablecolumns = [
                            'firstname' => get_string('firstname'),
                            'lastname' => get_string('lastname'),
                            'email' => get_string('email'),
                            'status' => get_string('presence', 'mod_booking'),
                            'presencecount' => get_string('presencecount', 'mod_booking'),
                        ];
                        $table->sort_default_column = 'lastname';
                        $table->sort_default_order = SORT_ASC;
                        break;
                    case MOD_BOOKING_STATUSPARAM_WAITINGLIST:
                    default:
                        $sortablecolumns = [
                            'firstname' => get_string('firstname'),
                            'lastname' => get_string('lastname'),
                            'email' => get_string('email'),
                        ];
                        $table->sort_default_column = 'lastname';
                        $table->sort_default_order = SORT_ASC;
                        break;
                }
                if ($statusparam != MOD_BOOKING_STATUSPARAM_DELETED) {
                    $table->addcheckboxes = true;
                    $table->actionbuttons[] = booked_users::create_delete_button();
                }
                break;
            case 'options': // Aggregated booking options.
            default:
                $table->define_fulltextsearchcolumns(['titleprefix', 'text', 'coursename', 'instancename']);
                $sortablecolumns = [
                    'titleprefix' => get_string('titleprefix', 'mod_booking'),
                    'text' => get_string('bookingoption', 'mod_booking'),
                    'answerscount' => get_string('answerscount', 'mod_booking'),
                    'presencecount' => get_string('presencecount', 'mod_booking'),
                ];
                break;
        }
        $table->define_sortablecolumns($sortablecolumns);

        return $table;
    }

    /**
     * Returns the sql to fetch booked users with a certain status.
     * Orderd by timemodified, to be able to sort them.
     * @param string $scope option | instance | course | system
     * @param int $scopeid optionid | cmid | courseid | 0
     * @param int $statusparam
     * @return array
     */
    public function return_sql_for_booked_users(string $scope, int $scopeid, int $statusparam): array {

        $fields = 's1.*';
        $where = ' 1 = 1 ';
        $viewtype = get_user_preferences('bookingstrackerviewtype');

        switch ($viewtype) { // Non-aggregated. Individual booking answers.
            case 'answers':
                $params['statusparam'] = $statusparam;

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
                    $params['statustocount'] = get_config('booking', 'bookingstrackerpresencecountervaluetocount');
                }
                $orderby = ' ORDER BY lastname, firstname, timemodified ASC';

                // We need to set a limit for the query in mysqlfamily.
                $fields = 's1.*';
                $from = "
                (
                    SELECT s2.*
                    FROM (
                        SELECT
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
                            ba.optionid,
                            ba.json,
                            '" . $scope . "' AS scope
                        FROM {booking_answers} ba
                        JOIN {user} u ON ba.userid = u.id
                        $presencecountsqlpart
                        WHERE ba.waitinglist=:statusparam
                        LIMIT 1000000
                    ) s2
                    $orderby
                ) s1";
                break;
            case 'options': // This is the aggregated view.
            default:
                $wherepart = $this->get_wherepart_for_booked_users($statusparam);
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
                    $wherepart
                    GROUP BY cm.id, c.id, c.fullname, bo.id, ba.waitinglist, bo.titleprefix, bo.text, b.name
                    ORDER BY bo.titleprefix, bo.text ASC
                        LIMIT 10000000000
                ) s1";
                break;
        }
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

        $viewtype = get_user_preferences('bookingstrackerviewtype');

        switch ($viewtype) { // Non-aggregated. Individual booking answers.
            case 'answers':
                $columns = [
                    'firstname' => get_string('firstname', 'core'),
                    'lastname'  => get_string('lastname', 'core'),
                    'email'     => get_string('email', 'core'),
                ];

                switch ($statusparam) {
                    case MOD_BOOKING_STATUSPARAM_BOOKED:
                        if (get_config('booking', 'bookingstrackerpresencecounter')) {
                            $columns['presencecount'] = get_string('presencecount', 'mod_booking');
                        }
                        $columns['status'] = get_string('presence', 'mod_booking');
                        $columns['notes'] = get_string('notes', 'mod_booking');
                        break;
                    case MOD_BOOKING_STATUSPARAM_WAITINGLIST:
                        // Currently no deletion supported in system scope.
                        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
                        /* $columns['action_confirm_delete'] = get_string('bookingstrackerdelete', 'mod_booking');
                        if (get_config('booking', 'waitinglistshowplaceonwaitinglist')) {
                            // Use array_merge to add the user rank at the first place.
                            $columns = array_merge(
                                ['userrank' => get_string('userrank', 'mod_booking')],
                                $columns
                            );
                        } */
                        break;
                    case MOD_BOOKING_STATUSPARAM_RESERVED:
                    case MOD_BOOKING_STATUSPARAM_NOTIFYMELIST:
                        $columns['action_delete'] = get_string('bookingstrackerdelete', 'mod_booking');
                        break;
                    case MOD_BOOKING_STATUSPARAM_NOTBOOKED:
                        break;
                    case MOD_BOOKING_STATUSPARAM_BOOKED_DELETED:
                        $columns['timemodified'] = get_string('timemodified', 'mod_booking');
                        break;
                }
                break;
            case 'options': // This is the aggregated view.
            default:
                $columns = [
                    'titleprefix' => get_string('titleprefix', 'mod_booking'),
                    'text'  => get_string('bookingoption', 'mod_booking'),
                    'answerscount'     => get_string('answerscount', 'mod_booking'),
                ];

                if (get_config('booking', 'bookingstrackerpresencecounter')) {
                    $columns['presencecount'] = get_string('presencecount', 'mod_booking');
                }
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

    /**
     * Helper function to get the $wherepart for the return_sql_for_booked_users function.
     * @param int $statusparam
     * @return string the where part of the sql query
     */
    public function get_wherepart_for_booked_users(int $statusparam): string {
        $wherepart = '';
        // For the booked users section, we want to show all booking options, even if they have no answers.
        if ($statusparam === 0) {
            $wherepart = "WHERE
                m.name = 'booking'
                AND (ba.waitinglist = 0 OR ba.waitinglist IS NULL)";
        } else {
            // This is the default case for all other sections.
            $wherepart = "WHERE
                m.name = 'booking'
                AND ba.waitinglist = :statusparam";
        }
        return $wherepart;
    }
}
