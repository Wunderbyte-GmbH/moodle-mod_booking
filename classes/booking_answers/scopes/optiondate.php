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
 * Booking answers scope: optiondate.
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer, Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\booking_answers\scopes;

use local_wunderbyte_table\filters\types\standardfilter;
use local_wunderbyte_table\wunderbyte_table;
use mod_booking\booking;
use mod_booking\booking_answers\scope_base;
use mod_booking\singleton_service;
use context_module;
use mod_booking\table\manageusers_table;
use moodle_url;

/**
 * Booking answers scope: optiondate.
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer, Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class optiondate extends scope_base {
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
        global $DB;

        // In optiondate scope, we have only booked users.
        if ($statusparam != 0) {
            return null;
        }

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

        // Only in optiondate.
        // We are in optiondate scope, so scopeid is optiondateid.
        $optionid = $DB->get_field('booking_optiondates', 'optionid', ['id' => $scopeid]);
        $cmid = singleton_service::get_instance_of_booking_option_settings($optionid)->cmid;
        if (!empty($cmid)) {
            // Add checkboxes, so we can perform actions for more than one selected user.
            $table->addcheckboxes = true;

            // Add fulltext search.
            $table->define_fulltextsearchcolumns(['firstname', 'lastname', 'email', 'notes']);

            // Add sorting.
            $sortablecolumns = [
                'firstname' => get_string('firstname'),
                'lastname' => get_string('lastname'),
                'email' => get_string('email'),
                'status' => get_string('presence', 'mod_booking'),
            ];
            $table->define_sortablecolumns($sortablecolumns);

            // Add filter for presence status.
            $presencestatusfilter = new standardfilter('status', get_string('presence', 'mod_booking'));
            $presencestatusfilter->add_options(booking::get_array_of_possible_presence_statuses());
            $table->add_filter($presencestatusfilter);

            $table->filteronloadinactive = true;
            $table->showfilterontop = true;

            $table->actionbuttons[] = [
                'label' => get_string('presence', 'mod_booking'), // Name of your action button.
                'class' => 'btn btn-primary btn-sm ml-2',
                'href' => '#', // You can either use the link, or JS, or both.
                'iclass' => 'fa fa-user-o', // Add an icon before the label.
                'formname' => 'mod_booking\\form\\optiondates\\modal_change_status',
                'nomodal' => false,
                'id' => -1,
                'selectionmandatory' => true,
                'data' => [ // Will be added eg as data-id = $values->id, so values can be transmitted to the method above.
                    'scope' => 'optiondate',
                    'titlestring' => 'changepresencestatus',
                    'submitbuttonstring' => 'save',
                    'component' => 'mod_booking',
                    'cmid' => $cmid,
                    'optionid' => $optionid ?? 0,
                    'optiondateid' => $scopeid ?? 0,
                ],
            ];

            $table->actionbuttons[] = [
                'label' => get_string('notes', 'mod_booking'), // Name of your action button.
                'class' => 'btn btn-primary btn-sm ml-1',
                'href' => '#', // You can either use the link, or JS, or both.
                'iclass' => 'fa fa-pencil', // Add an icon before the label.
                // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
                /* 'methodname' => 'mymethod', // The method needs to be added to your child of wunderbyte_table class. */
                'formname' => 'mod_booking\\form\\optiondates\\modal_change_notes',
                'nomodal' => false,
                'id' => -1,
                'selectionmandatory' => true,
                'data' => [ // Will be added eg as data-id = $values->id, so values can be transmitted to the method above.
                    'scope' => 'optiondate',
                    'titlestring' => 'notes',
                    'submitbuttonstring' => 'save',
                    'component' => 'mod_booking',
                    'cmid' => $cmid,
                    'optionid' => $optionid ?? 0,
                    'optiondateid' => $scopeid ?? 0,
                ],
            ];
        }

        // Add checkboxes for multi-selection.
        $table->addcheckboxes = true;

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
    public function return_cols_for_tables(int $statusparam): array {

        $columns = [
            'firstname' => get_string('firstname', 'core'),
            'lastname'  => get_string('lastname', 'core'),
            'email'     => get_string('email', 'core'),
        ];

        switch ($statusparam) {
            case MOD_BOOKING_STATUSPARAM_BOOKED:
                $columns['status'] = get_string('presence', 'mod_booking');
                $columns['notes'] = get_string('notes', 'mod_booking');
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
