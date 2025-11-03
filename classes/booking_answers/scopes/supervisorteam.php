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

namespace mod_booking\booking_answers\scopes;

use Exception;
use mod_booking\table\manageusers_table;
use local_wunderbyte_table\wunderbyte_table;

/**
 * Booking answers scope: supervisor's team.
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Mahdi Poustini
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later>
 */
class supervisorteam extends optionstoconfirm {
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
     * @param array $customfields
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
        bool $paginate = false,
        array $customfields = []
    ) {
        [$fields, $from, $where, $params] = $this->return_sql_for_booked_users($scope, $scopeid, $statusparam, $customfields);

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

        $table->define_fulltextsearchcolumns(['firstname', 'lastname', 'email']);

        // Sorting settings.
        $sortablecolumns = [
            'firstname' => get_string('firstname'),
            'lastname' => get_string('lastname'),
            'email' => get_string('email'),
        ];
        $table->sort_default_column = 'lastname';
        $table->sort_default_order = SORT_ASC;
        $table->define_sortablecolumns($sortablecolumns);

        return $table;
    }

    /**
     * Returns the DB dependent restriction to only return answers which supervisor can see.
     * @param array $params
     *
     * @return string
     *
     */
    public function get_whereneedtoconfirm_sql(array &$params): string {
        $pluginname = 'confirmation_supervisor';
        $fullclassname = "\\bookingextension_{$pluginname}\\local\\confirmbooking";
        if (!class_exists($fullclassname)) {
            throw new Exception("The {$pluginname} booking extension is required.");
        }
        $class = new $fullclassname();
        // This option makes this scope different from the scope 'optionstoconfirm'.
        // When the 'supervisorteam' property is set to true, some restrictions such as the confirmation order
        // (e.g., supervisor, HR) will be ignored.
        $class->supervisorteam = true;
        $where = $class->return_where_sql($params);
        return "( {$where} )";
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
            'text' => get_string('bookingoptionname', 'mod_booking'),
            'firstname' => get_string('firstname', 'core'),
            'lastname'  => get_string('lastname', 'core'),
            'email'     => get_string('email', 'core'),
            'bookingstatus'     => get_string('booknow', 'mod_booking'),
        ];

        return $columns;
    }

    /**
     * Returns a new array of labels for the tables.
     * You can set a custom name for each table.
     * Possible keys are:
     * - bookings
     * - waitinglist
     * - reservedusers
     * - userstonotify
     * - deletedbookings
     * - bookinghistory
     * @param array $defaultlables
     * @return array
     */
    public function get_lables_of_tables(array $defaultlables): array {
        $newlabels = $defaultlables;
        $newlabels['waitinglist'] = get_string('tableheaderwaitforconfirmation', 'mod_booking');
        return $newlabels;
    }
}
