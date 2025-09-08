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
 * This file contains the definition for the renderable classes for booked users.
 *
 * It is used to display a configurable list of booked users for a given context.
 *
 * @package     mod_booking
 * @copyright   2024 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author      Bernhard Fischer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\output;

use local_wunderbyte_table\filters\types\datepicker;
use local_wunderbyte_table\filters\types\standardfilter;
use local_wunderbyte_table\wunderbyte_table;
use mod_booking\booking;
use mod_booking\booking_answers\booking_answers;
use mod_booking\booking_answers\scope_base;
use mod_booking\singleton_service;
use mod_booking\table\booking_history_table;
use moodle_exception;
use renderer_base;
use renderable;
use templatable;

/**
 * This file contains the definition for the renderable classes for booked users.
 *
 * It is used to display a configurable list of booked users for a given context.
 *
 * @package     mod_booking
 * @copyright   2024 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author      Bernhard Fischer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booked_users implements renderable, templatable {
    /** @var string $bookedusers rendered table of bookedusers */
    public $bookedusers;

    /** @var string $waitinglist rendered table of waitinglist */
    public $waitinglist;

    /** @var string $reservedusers rendered table of reservedusers */
    public $reservedusers;

    /** @var string $userstonotify rendered table of userstonotify */
    public $userstonotify;

    /** @var string $deletedusers rendered table of deletedusers */
    public $deletedusers;

    /** @var string $bookinghistory rendered table of bookinghistory */
    public $bookinghistory;

    /** @var string $previouslybooked rendered table of previouslybooked */
    public $previouslybooked;

    /** @var string $optionstoconfirm rendered table of options to confirm */
    public $optionstoconfirm;

    /** @var string $optionstoconfirmadditionaltexts rendered additional texts for the table of options to confirm */
    public $optionstoconfirmadditionaltexts;

    /** @var string $deputyselect rendered additional texts for the table of options to confirm */
    public $deputyselect;

    /**
     * Constructor
     *
     * @param string $scope can be system, course, instance or option
     * @param int $scopeid id matching the scope, e.g. optionid for scope 'option'
     * @param bool $showbooked
     * @param bool $showwaiting
     * @param bool $showreserved
     * @param bool $showtonotify
     * @param bool $showdeleted
     * @param bool $showbookinghistory
     * @param bool $showoptionstoconfirm
     * @param bool $showpreviouslybooked
     * @param int $cmid optional course module id of booking instance
     */
    public function __construct(
        string $scope = 'system',
        int $scopeid = 0,
        bool $showbooked = false,
        bool $showwaiting = false,
        bool $showreserved = false,
        bool $showtonotify = false,
        bool $showdeleted = false,
        bool $showbookinghistory = false,
        bool $showoptionstoconfirm = false,
        bool $showpreviouslybooked = false,
        int $cmid = 0
    ) {
        $ba = new booking_answers();
        /** @var scope_base $class */
        $class = $ba->return_class_for_scope($scope);
        $columns = $class->return_cols_for_tables(MOD_BOOKING_STATUSPARAM_BOOKED);

        $this->bookedusers = $showbooked ?
            $this->render_users_table(
                $scope,
                $scopeid,
                MOD_BOOKING_STATUSPARAM_BOOKED,
                'booked',
                array_keys($columns),
                array_values($columns),
                false,
                true
            ) : null;

        // For optiondate scope, we only show booked users.
        if ($scope != 'optiondate') {
            $columns = $class->return_cols_for_tables(MOD_BOOKING_STATUSPARAM_WAITINGLIST);
            $this->waitinglist = $showwaiting ? $this->render_users_table(
                $scope,
                $scopeid,
                MOD_BOOKING_STATUSPARAM_WAITINGLIST,
                'waitinglist',
                array_keys($columns),
                array_values($columns),
                // Sorting of waiting list only possible if setting to show place is enabled.
                (bool)get_config('booking', 'waitinglistshowplaceonwaitinglist')
            ) : null;

            $columns = $class->return_cols_for_tables(MOD_BOOKING_STATUSPARAM_RESERVED);
            $this->reservedusers = $showreserved ? $this->render_users_table(
                $scope,
                $scopeid,
                MOD_BOOKING_STATUSPARAM_RESERVED,
                'reserved',
                array_keys($columns),
                array_values($columns),
            ) : null;

            $columns = $class->return_cols_for_tables(MOD_BOOKING_STATUSPARAM_NOTIFYMELIST);
            $this->userstonotify = $showtonotify ? $this->render_users_table(
                $scope,
                $scopeid,
                MOD_BOOKING_STATUSPARAM_NOTIFYMELIST,
                'notifymelist',
                array_keys($columns),
                array_values($columns),
            ) : null;

            $columns = $class->return_cols_for_tables(MOD_BOOKING_STATUSPARAM_DELETED);
            $this->deletedusers = $showdeleted ? $this->render_users_table(
                $scope,
                $scopeid,
                MOD_BOOKING_STATUSPARAM_DELETED,
                'deleted',
                array_keys($columns),
                array_values($columns),
                false,
                true
            ) : null;

            $columns = $class->return_cols_for_tables(MOD_BOOKING_STATUSPARAM_WAITINGLIST);
            $this->optionstoconfirmadditionaltexts
                = $this->render_additional_texts($scope, $scopeid, MOD_BOOKING_STATUSPARAM_WAITINGLIST);
            $this->optionstoconfirm = $showoptionstoconfirm ? $this->render_users_table(
                $scope,
                $scopeid,
                MOD_BOOKING_STATUSPARAM_WAITINGLIST,
                'optionstoconfirm',
                array_keys($columns),
                array_values($columns),
                // Sorting of waiting list only possible if setting to show place is enabled.
                (bool)get_config('booking', 'waitinglistshowplaceonwaitinglist')
            ) : null;

            $columns = $class->return_cols_for_tables(MOD_BOOKING_STATUSPARAM_PREVIOUSLYBOOKED);
            $this->previouslybooked = $showpreviouslybooked ? $this->render_users_table(
                $scope,
                $scopeid,
                MOD_BOOKING_STATUSPARAM_PREVIOUSLYBOOKED,
                'previouslybooked',
                array_keys($columns),
                array_values($columns),
                false
            ) : null;

            // Booking history table.
            $this->bookinghistory = $showbookinghistory ? $this->render_bookinghistory_table($scope, $scopeid) : null;
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
     * @return ?string
     */
    private function render_users_table(
        string $scope,
        int $scopeid,
        int $statusparam,
        string $tablenameprefix,
        array $columns,
        array $headers = [],
        bool $sortable = false,
        bool $paginate = false
    ): ?string {
        $ba = new booking_answers();
        /** @var scope_base $class */
        $class = $ba->return_class_for_scope($scope);
        $table = $class->return_users_table(
            $scope,
            $scopeid,
            $statusparam,
            $tablenameprefix,
            $columns,
            $headers,
            $sortable,
            $paginate
        );

        // Activate sorting dropdown.
        $table->cardsort = true;

        $table->showcountlabel = true;
        $table->showdownloadbutton = true;
        $table->showdownloadbuttonatbottom = true;
        $table->showreloadbutton = true;
        $table->showrowcountselect = true;

        $html = $table->outhtml(20, false);
        return count($table->rawdata) > 0 ? $html : null;
    }

    /**
     * Returns an instance of wunderbyte table for testing purposes.
     * This function is only accessible from PHPunit tests.
     * @param string $scope
     * @param int $scopeid
     * @param int $statusparam
     * @return ?wunderbyte_table
     */
    public function return_raw_table(
        string $scope,
        int $scopeid,
        int $statusparam
    ): ?wunderbyte_table {

        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            return null;
        }

        $ba = new booking_answers();
        /** @var scope_base $class */
        $class = $ba->return_class_for_scope($scope);
        $columns = $class->return_cols_for_tables($statusparam);
        $table = $class->return_users_table(
            $scope,
            $scopeid,
            $statusparam,
            $scope,
            array_keys($columns),
            array_values($columns),
            false,
            false
        );

        $table->outhtml(20000, false);

        return $table;
    }

    /**
     * Helper function to get a booking history table for the provided scope and id.
     * @param string $scope can be system, option, instance, user
     * @param int $scopeid 0 for system, optionid for option, cmid for instance, userid for user
     * @return string|null the rendered booking history table
     */
    private function render_bookinghistory_table(string $scope = 'system', int $scopeid = 0): ?string {
        $table = new booking_history_table("bookinghistorytable_{$scope}_{$scopeid}");
        $table->tabletemplate = 'local_wunderbyte_table/twtable_list';

        switch ($scope) {
            case 'system':
            case 'systemanswers':
                $wherepart = '';
                $params = [];
                break;
            case 'option':
                $optionid = $scopeid;
                $wherepart = "WHERE bh.optionid = :optionid";
                $params = ['optionid' => $optionid];
                break;
            case 'instance':
            case 'instanceanswers':
                $cmid = $scopeid; // Cmid - not bookingid!
                $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);
                $bookingid = $bookingsettings->id;
                $wherepart = "WHERE bh.bookingid = :bookingid";
                $params = ['bookingid' => $bookingid];
                break;
            case 'course':
            case 'courseanswers':
                $courseid = $scopeid;
                $wherepart = "WHERE c.id = :courseid";
                $params = ['courseid' => $courseid];
                break;
            default:
                throw new moodle_exception('Invalid scope for booking history table.');
        }

        $fields = "s1.*";
        $from = "(
            SELECT
                bh.id,
                c.id AS courseid,
                bh.bookingid,
                cm.id AS cmid,
                bh.optionid,
                bh.userid,
                c.fullname AS coursename,
                b.name AS instancename,
                bo.titleprefix,
                bo.text,
                u.firstname,
                u.lastname,
                u.email,
                bh.status,
                bh.usermodified,
                bh.timecreated,
                bh.json
            FROM {booking_history} bh
            LEFT JOIN {booking} b ON b.id = bh.bookingid
            LEFT JOIN {user} u ON u.id = bh.userid
            LEFT JOIN {booking_options} bo ON bo.id = bh.optionid
            LEFT JOIN {course} c ON c.id = b.course
            JOIN {course_modules} cm ON cm.instance = bh.bookingid
            JOIN {modules} m ON m.name = 'booking' AND m.id = cm.module
            $wherepart
            ORDER BY bh.id DESC
        ) s1";
        $where = "1=1";

        $table->set_sql($fields, $from, $where, $params);
        $table->define_cache('mod_booking', 'bookinghistorytable');
        $table->use_pages = true;

        $columns1 = [];
        $headers1 = [];
        if (in_array($scope, ['system', 'course', 'instance'])) {
            $columns1 = [
                'titleprefix',
                'text',
            ];
            $headers1 = [
                get_string('titleprefix', 'mod_booking'),
                get_string('bookingoption', 'mod_booking'),
            ];
        }

        $columns2 = [
            'firstname',
            'lastname',
            'email',
            'status',
            'usermodified',
            'timecreated',
            'json',
        ];

        $headers2 = [
            get_string('firstname'),
            get_string('lastname'),
            get_string('email'),
            get_string('status'),
            get_string('usermodified', 'mod_booking'),
            get_string('timecreated'),
            get_string('details', 'mod_booking'), // JSON.
        ];

        $columns = array_merge($columns1, $columns2);
        $headers = array_merge($headers1, $headers2);

        $table->define_columns($columns);
        $table->define_headers($headers);

        // Add filters.
        $statusfilter = new standardfilter('status', get_string('status'));
        $statusfilter->add_options(booking::get_array_of_possible_booking_history_statuses());
        $table->add_filter($statusfilter);

        $firstnamefilter = new standardfilter('firstname', get_string('firstname'));
        $table->add_filter($firstnamefilter);

        $lastnamefilter = new standardfilter('lastname', get_string('lastname'));
        $table->add_filter($lastnamefilter);

        $datepicker = new datepicker(
            'timecreated',
            get_string('timecreated', 'mod_booking'),
        );
        // For the datepicker, we need to add special options.
        $datepicker->add_options(
            'in between',
            '<',
            get_string('apply_filter', 'local_wunderbyte_table'),
            'now',
            'now + 1 year'
        );
        $table->add_filter($datepicker);
        $table->showfilterontop = true;

        $sortablecolumns1 = [];
        if (in_array($scope, ['system', 'course', 'instance'])) {
            $sortablecolumns1 = [
                'titleprefix' => get_string('titleprefix', 'mod_booking'),
                'text' => get_string('bookingoption', 'mod_booking'),
            ];
        }

        $sortablecolumns2 = [
            'firstname' => get_string('firstname'),
            'lastname' => get_string('lastname'),
            'email' => get_string('email'),
            'status' => get_string('status'),
            'timecreated' => get_string('timecreated', 'mod_booking'),
        ];
        $sortablecolumns = array_merge($sortablecolumns1, $sortablecolumns2);
        $table->define_sortablecolumns($sortablecolumns);
        $table->showrowcountselect = true;

        // Activate sorting dropdown.
        $table->cardsort = true;

        $table->define_fulltextsearchcolumns([
            'firstname',
            'lastname',
            'email',
            'titleprefix',
            'text',
            'coursename',
            'instancename',
        ]);

        [$idstring, $tablecachehash, $html] = $table->lazyouthtml(20, true);
        return $html;
    }

    /**
     * Export for template.
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        return array_filter([
            'bookedusers' => $this->bookedusers ?? null,
            'waitinglist' => $this->waitinglist ?? null,
            'reservedusers' => $this->reservedusers ?? null,
            'userstonotify' => $this->userstonotify ?? null,
            'deletedusers' => $this->deletedusers ?? null,
            'bookinghistory' => $this->bookinghistory ?? null,
            'optionstoconfirm' => $this->optionstoconfirm ?? null,
            'optionstoconfirmadditionaltexts' => $this->optionstoconfirmadditionaltexts ?? null,
            'previouslybooked' => $this->previouslybooked ?? null,
            'deputyselect' => $this->deputyselect ?? null,
        ]);
    }

    /**
     * Description for create_delete_button
     * @param string $labelkey
     * @param string $icon
     * @param string $formname
     * @param array $data
     * @param string $css
     * @return array
     */
    public static function create_action_button(
        string $labelkey,
        string $icon,
        string $formname,
        array $data,
        string $css = 'btn btn-primary btn-sm ml-1'
    ): array {
        return [
            'label' => get_string($labelkey, 'mod_booking'),
            'class' => $css,
            'href' => '#',
            'iclass' => $icon,
            'formname' => $formname,
            'nomodal' => false,
            'id' => -1,
            'selectionmandatory' => true,
            'data' => $data,
        ];
    }

    /**
     * Function to create delete button.
     *
     * @return array
     *
     */
    public static function create_delete_button(): array {
        return [
            'iclass' => 'fa fa-trash mr-1',
            'label' => get_string('bookingstrackerdelete', 'mod_booking'),
            'class' => 'btn btn-danger btn-sm ml-1',
            'href' => '#',
            'methodname' => 'delete_checked_booking_answers',
            'nomodal' => false,
            'selectionmandatory' => true,
            'id' => -1,
            'data' => [
                'id' => 'id',
                'titlestring' => 'delete',
                'bodystring' => 'deletecheckedanswersbody',
                'submitbuttonstring' => 'delete',
                'component' => 'mod_booking',
            ],
        ];
    }

    /**
     * Renders an additional text and returns HTML.
     * @param string $scope
     * @param int $scopeid
     * @param int $statusparam
     * @return bool|string
     */
    public function render_additional_texts(string $scope, int $scopeid, int $statusparam) {
        global $OUTPUT;
        $ba = new booking_answers();
        /** @var scope_base $class */
        $class = $ba->return_class_for_scope($scope);
        $data['texts'] = $class->get_additional_texts($statusparam);
        return $OUTPUT->render_from_template('mod_booking/additional_texts', $data);
    }
}
