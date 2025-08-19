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

use context_system;
use mod_booking\booking_answers\scope_base;
use mod_booking\output\booked_users;
use mod_booking\table\manageusers_table;
use local_wunderbyte_table\wunderbyte_table;

/**
 * Class for booking answers.
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class systemanswers extends scope_base {
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

        $params = [
            'statusparam' => $statusparam,
            'statustocount' => get_config('booking', 'bookingstrackerpresencecountervaluetocount'),
        ];

        return [$fields, $from, $where, $params];
    }

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

        $table->define_sortablecolumns($sortablecolumns);

        return $table;
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

        return $columns;
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
