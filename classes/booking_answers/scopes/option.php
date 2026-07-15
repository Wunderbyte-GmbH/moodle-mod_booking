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
 * Booking answers scope: option.
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\booking_answers\scopes;

use context_system;
use local_wunderbyte_table\wunderbyte_table;
use mod_booking\booking_answers\scope_base;
use mod_booking\local\bookingstracker\columns_helper;
use mod_booking\output\booked_users;
use mod_booking\singleton_service;
use context_module;
use mod_booking\table\manageusers_table;
use moodle_url;

/**
 * Booking answers scope: option.
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class option extends scope_base {
    /**
     * Scope name.
     * @var string
     */
    public $scope = 'option';

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

        // Only in option.
        $optionid = $scopeid;
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $cmid = $settings->cmid;

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
                if (
                    get_config('booking', 'waitinglistshowplaceonwaitinglist')
                    && $scope === 'option'
                ) {
                    $sortablecolumns = [];
                    $table->sort_default_column = 'userrank';
                    $table->sort_default_order = SORT_ASC;
                } else {
                    $sortablecolumns = [
                        'firstname' => get_string('firstname'),
                        'lastname' => get_string('lastname'),
                        'email' => get_string('email'),
                    ];
                    $table->sort_default_column = 'lastname';
                    $table->sort_default_order = SORT_ASC;
                }
                break;
            case MOD_BOOKING_STATUSPARAM_PREVIOUSLYBOOKED:
                $sortablecolumns = [
                    'firstname' => get_string('firstname'),
                    'lastname' => get_string('lastname'),
                    'email' => get_string('email'),
                    'timebooked' => get_string('timebooked', 'mod_booking'),
                ];
                $table->sort_default_column = 'timebooked';
                $table->sort_default_order = SORT_DESC;

                break;
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

        $table->define_sortablecolumns($sortablecolumns);

        if (
            $statusparam == MOD_BOOKING_STATUSPARAM_BOOKED
            && !empty($cmid)
            && !empty($optionid)
        ) {
            $table->actionbuttons[] = booked_users::create_action_button(
                'presence',
                'fa fa-user-o',
                'mod_booking\\form\\optiondates\\modal_change_status',
                [
                    'scope' => 'option',
                    'titlestring' => 'changepresencestatus',
                    'submitbuttonstring' => 'save',
                    'component' => 'mod_booking',
                    'cmid' => $cmid,
                    'optionid' => $optionid ?? 0,
                ],
                'btn btn-primary btn-sm ms-2'
            );

            $table->actionbuttons[] = booked_users::create_action_button(
                'notes',
                'fa fa-pencil',
                'mod_booking\\form\\optiondates\\modal_change_notes',
                [
                    'scope' => 'option',
                    'titlestring' => 'notes',
                    'submitbuttonstring' => 'save',
                    'component' => 'mod_booking',
                    'cmid' => $cmid,
                    'optionid' => $optionid ?? 0,
                ]
            );

            if (has_capability('mod/booking:communicate', context_module::instance($cmid))) {
                $table->actionbuttons[] = booked_users::create_action_button(
                    'sendcustommsg',
                    'fa fa-envelope',
                    'mod_booking\\form\\modal_send_custom_message',
                    [
                        'titlestring' => 'sendcustommsg',
                        'submitbuttonstring' => 'sendmessage',
                        'component' => 'mod_booking',
                        'cmid' => $cmid,
                        'optionid' => $optionid ?? 0,
                    ],
                    'btn btn-primary btn-sm ms-2'
                );
            }

            // Like on report.php, the "(Un)confirm completion status" button is only shown
            // if the completed column is configured in the responsesfields setting.
            $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);
            $responsesfields = array_map('trim', explode(',', (string)($bookingsettings->responsesfields ?? '')));
            if (in_array('completed', $responsesfields)) {
                $table->actionbuttons[] = booked_users::create_completion_button(
                    $bookingsettings->btncacname ?? ''
                );
            }

            if (has_capability('mod/booking:subscribeusers', context_module::instance($cmid))) {
                $table->actionbuttons[] = booked_users::create_action_button(
                    'transferusers',
                    'fa fa-exchange',
                    'mod_booking\\form\\modal_transfer_users',
                    [
                        'titlestring' => 'transferusers',
                        'submitbuttonstring' => 'transfer',
                        'component' => 'mod_booking',
                        'cmid' => $cmid,
                        'optionid' => $optionid ?? 0,
                    ],
                    'btn btn-primary btn-sm ms-2'
                );
            }
        }

        if (
            $statusparam == MOD_BOOKING_STATUSPARAM_BOOKED
            && !empty($certificatebutton = booked_users::create_certificate_button())
        ) {
            $table->actionbuttons[] = $certificatebutton;
        }

        if ($statusparam != MOD_BOOKING_STATUSPARAM_DELETED) {
            $table->addcheckboxes = true;

            // Only show delete button if user has capability to delete responses.
            if (
                $this->has_capability_in_scope($scopeid, 'mod/booking:deleteresponses')
            ) {
                $table->actionbuttons[] = booked_users::create_delete_button();
            }
        }

        return $table;
    }

    /**
     * This functions defines the columns for each scope.
     *
     * @param int $statusparam
     * @param int $scopeid
     *
     * @return array
     *
     */
    public function return_cols_for_tables(int $statusparam, int $scopeid = 0): array {

        // Columns configured in the instance setting "Manage responses - Page" (responsesfields).
        $columns = columns_helper::display_columns($this->get_cmid_for_scopeid($scopeid), $scopeid);
        if (empty($columns)) {
            $columns = [
                'firstname' => get_string('firstname', 'core'),
                'lastname'  => get_string('lastname', 'core'),
                'email'     => get_string('email', 'core'),
            ];
        }

        // Columns for the fields of the customform availability condition, like on report.php.
        $columns = array_merge($columns, columns_helper::customform_columns($scopeid, true));

        // Slot columns for slotbooking options, like on report.php.
        $columns = array_merge($columns, columns_helper::slot_columns($scopeid));

        // Status-specific columns stay automatic so the tracker workflow (presence, notes,
        // confirm/delete actions) keeps working regardless of the configured columns.
        switch ($statusparam) {
            case MOD_BOOKING_STATUSPARAM_BOOKED:
                if (
                    get_config('booking', 'bookingstrackerpresencecounter')
                    && !isset($columns['presencecount'])
                ) {
                    $columns['presencecount'] = get_string('presencecount', 'mod_booking');
                }
                if (!isset($columns['status'])) {
                    $columns['status'] = get_string('presence', 'mod_booking');
                }
                if (!isset($columns['notes'])) {
                    $columns['notes'] = get_string('notes', 'mod_booking');
                }
                break;
            case MOD_BOOKING_STATUSPARAM_WAITINGLIST:
                $columns['action_confirm_delete'] = get_string('actionsonbookinganswer', 'mod_booking');
                if (get_config('booking', 'waitinglistshowplaceonwaitinglist')) {
                    // Use array_merge to add the user rank at the first place.
                    $columns = array_merge(
                        ['userrank' => get_string('userrank', 'mod_booking')],
                        $columns
                    );
                }
                break;
            case MOD_BOOKING_STATUSPARAM_RESERVED:
            case MOD_BOOKING_STATUSPARAM_NOTIFYMELIST:
                $columns['action_delete'] = get_string('bookingstrackerdelete', 'mod_booking');
                break;
            case MOD_BOOKING_STATUSPARAM_NOTBOOKED:
                break;
            case MOD_BOOKING_STATUSPARAM_BOOKED_DELETED:
                if (!isset($columns['timemodified'])) {
                    $columns['timemodified'] = get_string('timemodified', 'mod_booking');
                }
                break;
            case MOD_BOOKING_STATUSPARAM_PREVIOUSLYBOOKED:
                if (!isset($columns['timebooked'])) {
                    $columns['timebooked'] = get_string('timebooked', 'mod_booking');
                }
                break;
        }

        // If the option supports enrolling multiple users (customform enrolusersaction field),
        // we always show the enrollink columns.
        if (
            !empty($scopeid)
            && in_array($statusparam, [MOD_BOOKING_STATUSPARAM_BOOKED, MOD_BOOKING_STATUSPARAM_WAITINGLIST])
            && columns_helper::has_enrolusersaction($scopeid)
        ) {
            $columns = self::add_enrollink_columns($columns);
        }

        return $columns;
    }

    /**
     * Adds the enrollink columns. If an enrollink column already exists (customform mapping),
     * "received from" is inserted right after it, otherwise both are appended.
     *
     * @param array $columns
     * @return array
     */
    private static function add_enrollink_columns(array $columns): array {
        if (!isset($columns['enrollink'])) {
            $columns['enrollink'] = get_string('enrollink', 'mod_booking');
            $columns['enrollinkreceivedfrom'] = get_string('enrollinkreceivedfrom', 'mod_booking');
            return $columns;
        }
        $newcolumns = [];
        foreach ($columns as $key => $value) {
            $newcolumns[$key] = $value;
            if ($key === 'enrollink') {
                $newcolumns['enrollinkreceivedfrom'] = get_string('enrollinkreceivedfrom', 'mod_booking');
            }
        }
        return $newcolumns;
    }

    /**
     * This functions defines the columns for the table download.
     *
     * @param int $statusparam
     * @param int $scopeid
     *
     * @return array
     *
     */
    public function return_cols_for_download(int $statusparam, int $scopeid = 0): array {

        // Columns configured in the instance setting "Manage responses - Download" (reportfields).
        $columns = columns_helper::download_columns($this->get_cmid_for_scopeid($scopeid), $scopeid);
        if (empty($columns)) {
            return $this->return_cols_for_tables($statusparam, $scopeid);
        }

        // Columns for the fields of the customform availability condition, like on report.php.
        $columns = array_merge($columns, columns_helper::customform_columns($scopeid));

        // Slot columns for slotbooking options, like on report.php.
        $columns = array_merge($columns, columns_helper::slot_columns($scopeid));

        if (
            $statusparam == MOD_BOOKING_STATUSPARAM_WAITINGLIST
            && get_config('booking', 'waitinglistshowplaceonwaitinglist')
            && !isset($columns['userrank'])
        ) {
            $columns = array_merge(
                ['userrank' => get_string('userrank', 'mod_booking')],
                $columns
            );
        }

        // If the option supports enrolling multiple users (customform enrolusersaction field),
        // we always add the enrollink columns (as in the display table).
        if (
            !empty($scopeid)
            && in_array($statusparam, [MOD_BOOKING_STATUSPARAM_BOOKED, MOD_BOOKING_STATUSPARAM_WAITINGLIST])
            && columns_helper::has_enrolusersaction($scopeid)
        ) {
            $columns = self::add_enrollink_columns($columns);
        }

        return $columns;
    }

    /**
     * Resolves the cmid of the booking instance for the given scopeid (optionid).
     *
     * @param int $scopeid
     * @return int
     */
    public function get_cmid_for_scopeid(int $scopeid): int {
        if (empty($scopeid)) {
            return 0;
        }
        return singleton_service::get_instance_of_booking_option_settings($scopeid)->cmid ?? 0;
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

        $optionid = $scopeid;
        $where = " 1 = 1 ";

        $whereoptionid1 = " AND ba.optionid=:optionid ";
        $whereoptionid2 = " AND boda.optionid = :optionid2 ";
        $whereoptionid3 = " AND ba.optionid=:optionid3 ";
        $params['optionid'] = $optionid;
        $params['optionid2'] = $optionid;
        $params['optionid3'] = $optionid;

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
                    $whereoptionid2
                    GROUP BY boda.optionid, boda.userid
                ) pcnt
                ON pcnt.optionid = ba.optionid AND pcnt.userid = u.id";
            $params['optionid2'] = $optionid;
            $params['statustocount'] = get_config('booking', 'bookingstrackerpresencecountervaluetocount');
        }

        // Only for waiting list, we need to add the rank.
        $ranksqlpart = '';
        $orderby = ' ORDER BY lastname, firstname, timemodified ASC';
        if (
            $statusparam == MOD_BOOKING_STATUSPARAM_WAITINGLIST
            && $scope === 'option'
        ) {
            // For waiting list, we need to determine the rank order.
            $ranksqlpart = ", (
                SELECT COUNT(*)
                FROM (
                    SELECT
                        ba.id,
                        ba.timemodified
                    FROM {booking_answers} ba
                    WHERE ba.waitinglist=:statusparam2 $whereoptionid3
                ) s3
                WHERE (s3.timemodified < s2.timemodified) OR (s3.timemodified = s2.timemodified AND s3.id <= s2.id)
            ) AS userrank";
            $orderby = ' ORDER BY userrank ASC';

            // Params for rank order.
            $params['statusparam2'] = $statusparam;
        }

        $whereneedtoconfirm = '';
        $whereneedtoconfirmjoin = '';

        // Subselects for the custom user profile fields configured in the instance settings.
        [$profilefieldselect, $profilefieldparams] = columns_helper::profilefield_sql(
            $this->get_cmid_for_scopeid($optionid)
        );
        $params = array_merge($params, $profilefieldparams);

        // Additional selects/joins for configured columns (price/currency, rating).
        [$extraselect, $extrajoin, $extraparams] = columns_helper::extra_fields_sql(
            $this->get_cmid_for_scopeid($optionid),
            $optionid
        );
        $params = array_merge($params, $extraparams);

        // We need to set a limit for the query in mysqlfamily.
        $fields = 's1.*';
        $from = "
        (
            SELECT s2.* $ranksqlpart
            FROM (
                SELECT
                    ba.id,
                    u.id AS userid,
                    u.username,
                    u.firstname,
                    u.lastname,
                    u.email,
                    u.institution,
                    u.city,
                    u.department,
                    u.idnumber,
                    ba.waitinglist,
                    ba.status,
                    ba.notes,
                    $selectpresencecount
                    ba.timemodified,
                    ba.timecreated,
                    ba.timebooked,
                    ba.completed,
                    ba.completeddate,
                    ba.numrec,
                    ba.places,
                    ba.startdate,
                    ba.enddate,
                    ba.optionid,
                    ba.json,
                    bo.text,
                    bo.titleprefix,
                    bo.location,
                    bo.coursestarttime,
                    bo.courseendtime,
                    '" . $scope . "' AS scope
                    $profilefieldselect
                    $extraselect
                FROM {booking_answers} ba
                JOIN {user} u ON ba.userid = u.id
                JOIN {booking_options} bo ON bo.id = ba.optionid
                $whereneedtoconfirmjoin
                $presencecountsqlpart
                $extrajoin
                WHERE ba.waitinglist=:statusparam $whereoptionid1 $whereneedtoconfirm
                LIMIT 1000000
            ) s2
            $orderby
        ) s1";

        return [$fields, $from, $where, $params];
    }

    /**
     * Helper function to check capability for logged-in user in provided scope.
     * @param int $scopeid
     * @param string $capability
     */
    public function has_capability_in_scope($scopeid, $capability) {
        if (!empty($scopeid)) {
            $cmid = singleton_service::get_instance_of_booking_option_settings($scopeid)->cmid;
            return has_capability($capability, context_module::instance($cmid));
        } else {
            return has_capability($capability, context_system::instance());
        }
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
                    'scopeid' => $scopeid,
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
