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
 * Scope base class.
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer, Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\booking_answers;

use context_system;
use core\exception\moodle_exception;
use local_wunderbyte_table\wunderbyte_table;
use mod_booking\booking_option_settings;
use mod_booking\customfield\booking_handler;
use moodle_url;

/**
 * Scope base class.
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer, Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scope_base {
    /**
     * Returns the sql to fetch booked users with a certain status.
     * Orderd by timemodified, to be able to sort them.
     * @param string $scope option | instance | course | system
     * @param int $scopeid optionid | cmid | courseid | 0
     * @param int $statusparam
     * @return array
     */
    public function return_sql_for_booked_users(string $scope, int $scopeid, int $statusparam): array {
        // Actual implementation in subclasses.
        return [];
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
        // Actual implementation in subclasses.
        return [];
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
        $ba = new booking_answers();
        /** @var \mod_booking\booking_answers\scope_base $class */
        $class = $ba->return_class_for_scope($scope);
        if ($class->has_capability_in_scope($scopeid, 'mod/booking:updatebooking')) {
            $baseurl = new moodle_url(
                '/mod/booking/download_report2.php',
                [
                    'scope' => $scope,
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
        return null; // Actual implementation in subclasses.
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
    public function get_wherepart(int $statusparam): string {
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
    /**
     * Joins Customfields.
     *
     * @param string $fields
     * @param string $from
     * @param string $where
     * @param array $params
     * @param array $customfields
     *
     * @return array
     *
     */
    public function join_customfields(string $fields, string $from, string $where, array $params, array $customfields = []) {
        global $DB;
        $filterarray = [];
        [$select1, $from1, $filter1, $params1] =
        booking_option_settings::return_sql_for_customfield(
            $filterarray,
            $customfields,
            's1.optionid'
        );
        $fields .= !empty($select1) ? ", $select1 " : '';
        $from   .= " $from1 ";
        $where  .= " $filter1 ";

        foreach ($filterarray as $key => $value) {
            // Ensure the key is lowercased.
            $paramsvaluekey = "param";
            while (isset($params[$paramsvaluekey])) {
                $paramsvaluekey .= $counter;
                $counter++;
            }

            if (gettype($value) == 'integer') {
                $filter .= " AND $key = $value";
            } else {
                $filter .= " AND " . $DB->sql_like("$key", ":$paramsvaluekey", false);
                $params[$paramsvaluekey] = $value;
            }
        }

        $params = array_merge($params, $params1);

        return [$fields, $from, $where ?? '', $params];
    }
}
