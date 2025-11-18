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
 * Booking answers scope: options to confirm.
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\booking_answers\scopes;

use mod_booking\customfield\booking_handler;
use mod_booking\table\manageusers_table;
use local_wunderbyte_table\wunderbyte_table;

/**
 * Booking answers scope: options to confirm.
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class optionstoconfirmreduced extends optionstoconfirm {
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
        if (!empty($customfields)) {
            $customfieldheadings = [];
            $customfieldsarray = booking_handler::get_customfields([array_values($customfields)]);
            foreach ($customfieldsarray as $customfield) {
                $customfieldheadings[] = $customfield->name;
            }
            $headers = array_merge($headers, $customfieldheadings);
            $columns = array_merge($columns, $customfields);
        }
        $table->define_cache('mod_booking', "bookedusertable");
        $table->define_columns($columns);
        $table->define_headers($headers);

        if ($paginate) {
            $table->use_pages = true;
        }
        $table->set_sql($fields, $from, $where, $params);
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
            'name' => get_string('fullname', 'core'),
            'text' => get_string('bookingoptionname', 'mod_booking'),
            'action_confirm_delete' => get_string('confirmbooking', 'mod_booking'),
            'timemodified' => get_string('timecreated', 'core'),
        ];
        return $columns;
    }
}
