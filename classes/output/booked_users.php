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

use context_module;
use context_course;
use context_system;
use local_wunderbyte_table\filters\types\datepicker;
use local_wunderbyte_table\filters\types\standardfilter;
use mod_booking\booking;
use mod_booking\booking_answers;
use mod_booking\singleton_service;
use mod_booking\table\booking_history_table;
use mod_booking\table\manageusers_table;
use moodle_exception;
use moodle_url;
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
        int $cmid = 0
    ) {
        switch ($scope) {
            case 'optiondate':
                $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);

                // For optiondates we only show booked users.
                // Also, we have no delete action but presence tracking.
                $bookeduserscols[] = 'firstname';
                $bookedusersheaders[] = get_string('firstname', 'core');
                $bookeduserscols[] = 'lastname';
                $bookedusersheaders[] = get_string('lastname', 'core');
                $bookeduserscols[] = 'email';
                $bookedusersheaders[] = get_string('email', 'core');
                $bookeduserscols[] = 'status';
                $bookedusersheaders[] = get_string('presence', 'mod_booking');
                $bookeduserscols[] = 'notes';
                $bookedusersheaders[] = get_string('notes', 'mod_booking');

                // It's redundant because we also have bulk actions.
                // So for now, we do not show the action column.
                // But we still kept the code in col_action case we need it in the future.
                // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
                /* $bookeduserscols[] = 'actions';
                $bookedusersheaders[] = get_string('actions', 'mod_booking'); */
                break;
            case 'option':
                // Define columns and headers for the tables.
                $bookeduserscols[] = 'firstname';
                $bookedusersheaders[] = get_string('firstname', 'core');
                $bookeduserscols[] = 'lastname';
                $bookedusersheaders[] = get_string('lastname', 'core');
                $bookeduserscols[] = 'email';
                $bookedusersheaders[] = get_string('email', 'core');
                $bookeduserscols[] = 'status';
                $bookedusersheaders[] = get_string('presence', 'mod_booking');
                $bookeduserscols[] = 'notes';
                $bookedusersheaders[] = get_string('notes', 'mod_booking');

                if (get_config('booking', 'bookingstrackerpresencecounter')) {
                    $bookeduserscols[] = 'presencecount';
                }
                $bookeduserscols[] = 'action_delete';

                $waitinglistcols = ['firstname', 'lastname', 'email', 'action_confirm_delete'];
                $reserveduserscols = ['firstname', 'lastname', 'email', 'action_delete'];
                $userstonotifycols = ['firstname', 'lastname', 'email', 'action_delete'];
                $deleteduserscols = ['firstname', 'lastname', 'email', 'timemodified'];

                if (get_config('booking', 'bookingstrackerpresencecounter')) {
                    $bookedusersheaders[] = get_string('presencecount', 'mod_booking');
                }
                $bookedusersheaders[] = get_string('bookingstrackerdelete', 'mod_booking');

                $waitinglistheaders = [
                    get_string('firstname', 'core'),
                    get_string('lastname', 'core'),
                    get_string('email', 'core'),
                    get_string('bookingstrackerdelete', 'mod_booking'),
                ];
                $reservedusersheaders = [
                    get_string('firstname', 'core'),
                    get_string('lastname', 'core'),
                    get_string('email', 'core'),
                    get_string('bookingstrackerdelete', 'mod_booking'),
                ];
                $userstonotifyheaders = [
                    get_string('firstname', 'core'),
                    get_string('lastname', 'core'),
                    get_string('email', 'core'),
                    get_string('bookingstrackerdelete', 'mod_booking'),
                ];
                $deletedusersheaders = [
                    get_string('firstname', 'core'),
                    get_string('lastname', 'core'),
                    get_string('email', 'core'),
                    get_string('timemodified', 'mod_booking'),
                ];

                if (get_config('booking', 'waitinglistshowplaceonwaitinglist')) {
                    array_unshift($waitinglistcols, 'userrank');
                    array_unshift($waitinglistheaders, get_string('userrank', 'mod_booking'));
                }
                break;
            case 'system':
            case 'course':
            case 'instance':
            default:
                // Define columns and headers for the tables.
                $bookeduserscols = [];
                $waitinglistcols = [];
                $reserveduserscols = [];
                $userstonotifycols = [];
                $deleteduserscols = [];
                $bookedusersheaders = [];
                $waitinglistheaders = [];
                $reservedusersheaders = [];
                $userstonotifyheaders = [];
                $deletedusersheaders = [];

                $bookeduserscols[] = 'titleprefix';
                $bookeduserscols[] = 'text';
                $bookeduserscols[] = 'answerscount';
                if (get_config('booking', 'bookingstrackerpresencecounter')) {
                    $bookeduserscols[] = 'presencecount';
                }

                $waitinglistcols[] = 'titleprefix';
                $waitinglistcols[] = 'text';
                $waitinglistcols[] = 'answerscount';

                $reserveduserscols[] = 'titleprefix';
                $reserveduserscols[] = 'text';
                $reserveduserscols[] = 'answerscount';

                $userstonotifycols[] = 'titleprefix';
                $userstonotifycols[] = 'text';
                $userstonotifycols[] = 'answerscount';

                $deleteduserscols[] = 'titleprefix';
                $deleteduserscols[] = 'text';
                $deleteduserscols[] = 'answerscount';

                $bookedusersheaders[] = get_string('titleprefix', 'mod_booking');
                $bookedusersheaders[] = get_string('bookingoption', 'mod_booking');
                $bookedusersheaders[] = get_string('answerscount', 'mod_booking');
                if (get_config('booking', 'bookingstrackerpresencecounter')) {
                    $bookedusersheaders[] = get_string('presencecount', 'mod_booking');
                }

                $waitinglistheaders[] = get_string('titleprefix', 'mod_booking');
                $waitinglistheaders[] = get_string('bookingoption', 'mod_booking');
                $waitinglistheaders[] = get_string('answerscount', 'mod_booking');

                $reservedusersheaders[] = get_string('titleprefix', 'mod_booking');
                $reservedusersheaders[] = get_string('bookingoption', 'mod_booking');
                $reservedusersheaders[] = get_string('answerscount', 'mod_booking');

                $userstonotifyheaders[] = get_string('titleprefix', 'mod_booking');
                $userstonotifyheaders[] = get_string('bookingoption', 'mod_booking');
                $userstonotifyheaders[] = get_string('answerscount', 'mod_booking');

                $deletedusersheaders[] = get_string('titleprefix', 'mod_booking');
                $deletedusersheaders[] = get_string('bookingoption', 'mod_booking');
                $deletedusersheaders[] = get_string('answerscount', 'mod_booking');
                break;
        }

        $this->bookedusers = $showbooked ?
            $this->render_users_table(
                $scope,
                $scopeid,
                MOD_BOOKING_STATUSPARAM_BOOKED,
                'booked',
                $bookeduserscols,
                $bookedusersheaders,
                false,
                true
            ) : null;

        // For optiondate scope, we only show booked users.
        if ($scope != 'optiondate') {
            $this->waitinglist = $showwaiting ? $this->render_users_table(
                $scope,
                $scopeid,
                MOD_BOOKING_STATUSPARAM_WAITINGLIST,
                'waitinglist',
                $waitinglistcols,
                $waitinglistheaders,
                // Sorting of waiting list only possible if setting to show place is enabled.
                (bool)get_config('booking', 'waitinglistshowplaceonwaitinglist')
            ) : null;

            $this->reservedusers = $showreserved ? $this->render_users_table(
                $scope,
                $scopeid,
                MOD_BOOKING_STATUSPARAM_RESERVED,
                'reserved',
                $reserveduserscols,
                $reservedusersheaders
            ) : null;

            $this->userstonotify = $showtonotify ? $this->render_users_table(
                $scope,
                $scopeid,
                MOD_BOOKING_STATUSPARAM_NOTIFYMELIST,
                'notifymelist',
                $userstonotifycols,
                $userstonotifyheaders
            ) : null;

            $this->deletedusers = $showdeleted ? $this->render_users_table(
                $scope,
                $scopeid,
                MOD_BOOKING_STATUSPARAM_DELETED,
                'deleted',
                $deleteduserscols,
                $deletedusersheaders,
                false,
                true
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
        [$fields, $from, $where, $params] = booking_answers::return_sql_for_booked_users($scope, $scopeid, $statusparam);

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

        // Table configurations for different scopes.
        if (self::has_capability_in_scope($scope, $scopeid, 'mod/booking:updatebooking')) {
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
                if (in_array($scope, ['option', 'optiondate'])) {
                    $table->showdownloadbuttonatbottom = true;
                }
            }
        }

        // Checkboxes are currently only supported in option scope.
        if ($scope === 'option') {
            $optionid = $scopeid;
            $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
            $cmid = $settings->cmid;

            // Add fulltext search.
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
                    if (get_config('booking', 'waitinglistshowplaceonwaitinglist')) {
                        // No sorting allowed as it would destroy rank order.
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

            // Add sorting.
            $table->define_sortablecolumns($sortablecolumns);

            if ($statusparam == MOD_BOOKING_STATUSPARAM_BOOKED) {
                // Action button to change presence status of booking answer (option scope).
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
                        'scope' => 'option',
                        'titlestring' => 'changepresencestatus',
                        'submitbuttonstring' => 'save',
                        'component' => 'mod_booking',
                        'cmid' => $cmid,
                        'optionid' => $optionid ?? 0,
                    ],
                ];
                // Action button to add notes for booking answer (option scope).
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
                        'scope' => 'option',
                        'titlestring' => 'notes',
                        'submitbuttonstring' => 'save',
                        'component' => 'mod_booking',
                        'cmid' => $cmid,
                        'optionid' => $optionid ?? 0,
                    ],
                ];
            }

            if ($statusparam != MOD_BOOKING_STATUSPARAM_DELETED) {
                $table->addcheckboxes = true;

                // Show modal, single call, use selected items.
                $table->actionbuttons[] = [
                    'iclass' => 'fa fa-trash mr-1', // Add an icon before the label.
                    'label' => get_string('bookingstrackerdelete', 'mod_booking'),
                    'class' => 'btn btn-danger btn-sm ml-1',
                    'href' => '#',
                    'methodname' => 'delete_checked_booking_answers',
                    // To include a dynamic form to open and edit entry in modal.
                    // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
                    /* 'formname' => 'local_myplugin\\form\\edit_mytableentry', */
                    'nomodal' => false,
                    'selectionmandatory' => true,
                    'id' => -1,
                    'data' => [
                        'id' => 'id',
                        'titlestring' => 'delete',
                        'bodystring' => 'deletecheckedanswersbody',
                        // Localized title to be displayed as title in dynamic form (formname).
                        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
                        'submitbuttonstring' => 'delete',
                        'component' => 'mod_booking',
                        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
                        /* 'labelcolumn' => 'name', */
                    ],
                ];
            }
        } else if ($scope == 'optiondate') {
            global $DB;
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
        } else {
            // All other scopes: system, course, instance.
            // Add fulltext search.
            $table->define_fulltextsearchcolumns(['titleprefix', 'text', 'coursename', 'instancename']);
            // Add sorting.
            $sortablecolumns = [
                'titleprefix' => get_string('titleprefix', 'mod_booking'),
                'text' => get_string('bookingoption', 'mod_booking'),
                'answerscount' => get_string('answerscount', 'mod_booking'),
                'presencecount' => get_string('presencecount', 'mod_booking'),
            ];
            $table->define_sortablecolumns($sortablecolumns);
        }

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
                $wherepart = '';
                $params = [];
                break;
            case 'option':
                $optionid = $scopeid;
                $wherepart = "WHERE bh.optionid = :optionid";
                $params = ['optionid' => $optionid];
                break;
            case 'instance':
                $cmid = $scopeid; // Cmid - not bookingid!
                $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);
                $bookingid = $bookingsettings->id;
                $wherepart = "WHERE bh.bookingid = :bookingid";
                $params = ['bookingid' => $bookingid];
                break;
            case 'course':
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
        ]);
    }

    /**
     * Helper function to check capability for logged-in user in provided scope.
     * @param string $scope
     * @param int $scopeid
     * @param string $capability
     */
    public static function has_capability_in_scope($scope, $scopeid, $capability) {
        switch ($scope) {
            case 'optiondate':
                global $DB;
                $optionid = $DB->get_field('booking_optiondates', 'optionid', ['id' => $scopeid]);
                $cmid = singleton_service::get_instance_of_booking_option_settings($optionid)->cmid;
                return has_capability($capability, context_module::instance($cmid));
            case 'option':
                $cmid = singleton_service::get_instance_of_booking_option_settings($scopeid)->cmid;
                return has_capability($capability, context_module::instance($cmid));
            case 'instance':
                return has_capability($capability, context_module::instance($scopeid));
            case 'course':
                return has_capability($capability, context_course::instance($scopeid));
            case 'system':
                return has_capability($capability, context_system::instance());
            default:
                throw new moodle_exception('Invalid scope for booked users table.');
        }
    }
}
