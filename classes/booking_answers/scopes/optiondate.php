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

use local_wunderbyte_table\wunderbyte_table;
use mod_booking\booking_answers\scope_base;
use mod_booking\singleton_service;
use context_module;
use moodle_url;

/**
 * Class for booking answers.
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class optiondate extends scope_base {
    /**
     * Returns the sql to fetch booked users with a certain status.
     * Orderd by timemodified, to be able to sort them.
     * @param string $scope option | instance | course | system
     * @param int $scopeid optionid | cmid | courseid | 0
     * @param int $statusparam
     * @return (string|int[])[]
     */
    public function return_sql_for_booked_users(string $scope, int $scopeid, int $statusparam) {
        global $DB;

        $optiondateid = $scopeid;
        $where = " 1 = 1 ";

        // We need to set a limit for the query in mysqlfamily.
        $fields = 's1.*';
        $from = " (
            SELECT " .
                $DB->sql_concat("bo.id", "'-'", "bod.id", "'-'", "u.id") .
                " id,
                bod.id optiondateid,
                bod.coursestarttime,
                bod.courseendtime,
                ba.userid,
                ba.waitinglist,
                boda.status,
                boda.json,
                boda.notes,
                bo.id optionid,
                bo.titleprefix,
                bo.text,
                u.firstname,
                u.lastname,
                u.email,
                '" . $scope . "' AS scope
            FROM {booking_optiondates} bod
            JOIN {booking_options} bo
            ON bo.id = bod.optionid
            JOIN {booking_answers} ba
            ON bo.id = ba.optionid
            JOIN {user} u
            ON u.id = ba.userid
            LEFT JOIN {booking_optiondates_answers} boda
            ON bod.id = boda.optiondateid AND bo.id = boda.optionid AND ba.userid = boda.userid
            WHERE bod.id = :optiondateid AND ba.waitinglist = :statusparam
            ORDER BY u.lastname, u.firstname, bod.coursestarttime ASC
            LIMIT 10000000000
        ) s1";
        $params = [
            'optiondateid' => $optiondateid,
            'statusparam' => MOD_BOOKING_STATUSPARAM_BOOKED,
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
            'firstname' => get_string('firstname', 'core'),
            'lastname'  => get_string('lastname', 'core'),
            'email'     => get_string('email', 'core'),
            'status'    => get_string('presence', 'mod_booking'),
            'notes'     => get_string('notes', 'mod_booking'),
        ];

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
     * Helper function to check capability for logged-in user in provided scope.
     * @param int $scopeid
     * @param string $capability
     */
    public function has_capability_in_scope($scopeid, $capability) {
        global $DB;
        $optionid = $DB->get_field('booking_optiondates', 'optionid', ['id' => $scopeid]);
        $cmid = singleton_service::get_instance_of_booking_option_settings($optionid)->cmid;
        return has_capability($capability, context_module::instance($cmid));
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
}
