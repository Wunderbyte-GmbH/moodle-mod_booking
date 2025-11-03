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
 * Booking answers scope: instance - non-aggregated.
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer, Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_booking\booking_answers\scopes;

use context_module;
use local_wunderbyte_table\wunderbyte_table;
use mod_booking\booking_answers\scope_base_answers;
use mod_booking\output\booked_users;
use mod_booking\table\manageusers_table;
use moodle_url;

/**
 * Booking answers scope: instance - non-aggregated.
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer, Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class instanceanswers extends scope_base_answers {
    /**
     * Returns the sql to fetch booked users with a certain status.
     * Orderd by timemodified, to be able to sort them.
     * @param string $scope option | instance | course | system
     * @param int $scopeid optionid | cmid | courseid | 0
     * @param int $statusparam
     * @return array
     */
    public function return_sql_for_booked_users(string $scope, int $scopeid, int $statusparam): array {
        $cmid = $scopeid;
        $fields = 's1.*';
        $where = ' 1 = 1 ';

        $params['statusparam'] = $statusparam;
        $params['cmid'] = $cmid;
        if (get_config('booking', 'bookingstrackerpresencecounter')) {
            $params['statustocount'] = get_config('booking', 'bookingstrackerpresencecountervaluetocount');
        }
        $endpart = $this->get_endpart();
        $selectpart = $this->get_selectpart($scope);

        // We need to set a limit for the query in mysqlfamily.
        $fields = 's1.*';
        $from = "
        (
            SELECT s2.*
            FROM (
                $selectpart
                WHERE ba.waitinglist=:statusparam
                AND cm.id=:cmid
                LIMIT 1000000
            ) s2
            $endpart
        ) s1";

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
     * @param array $customfields = []
     * @return \local_wunderbyte_table\wunderbyte_table|null
     */
    public function return_users_table(
        string $scope,
        int $scopeid,
        int $statusparam,
        string $tablenameprefix,
        array $columns,
        array $headers = [],
        bool $sortable = false,
        bool $paginate = false,
        array $customfields = []
    ) {
        [$fields, $from, $where, $params] = $this->return_sql_for_booked_users($scope, $scopeid, $statusparam);

        $tablename = "{$tablenameprefix}_{$scope}_{$scopeid}";
        $table = new manageusers_table($tablename);

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

        $table->define_fulltextsearchcolumns(['titleprefix', 'text', 'firstname', 'lastname', 'email']);
        $sortablecolumns = [
            'titleprefix' => get_string('titleprefix', 'mod_booking'),
            'text' => get_string('bookingoption', 'mod_booking'),
            'firstname' => get_string('firstname', 'core'),
            'lastname' => get_string('lastname', 'core'),
            'email' => get_string('email', 'core'),
        ];
        if ($statusparam == 0) {
            $sortablecolumns['presencecount'] = get_string('presencecount', 'mod_booking');
            $sortablecolumns['status'] = get_string('presence', 'mod_booking');
            $sortablecolumns['notes'] = get_string('notes', 'mod_booking');
        }
        $sortablecolumns['timemodified'] = get_string('timemodified', 'mod_booking');
        $table->define_sortablecolumns($sortablecolumns);
        $table->sort_default_column = 'timemodified';
        $table->sort_default_order = SORT_DESC;

        if ($statusparam != MOD_BOOKING_STATUSPARAM_DELETED) {
            $table->addcheckboxes = true;
            $table->actionbuttons[] = booked_users::create_delete_button();
        }

        return $table;
    }

    /**
     * Each scope can decide under which circumstances it actually adds the downloadbutton.
     *
     * @param wunderbyte_table $table
     * @param string $scope
     * @param int $scopeid
     * @param int $statusparam
     *
     * @return void
     *
     */
    public function show_download_button(wunderbyte_table &$table, string $scope, int $scopeid, int $statusparam) {
        if ($this->has_capability_in_scope($scopeid, 'mod/booking:updatebooking')) {
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
                $table->showdownloadbuttonatbottom = true;
            }
        }
    }

    /**
     * Helper function to check capability for logged-in user in provided scope.
     * @param int $scopeid
     * @param string $capability
     */
    public function has_capability_in_scope($scopeid, $capability) {
        return has_capability($capability, context_module::instance($scopeid));
    }
}
