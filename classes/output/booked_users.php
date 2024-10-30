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

use mod_booking\booking_answers;
use mod_booking\table\manageusers_table;
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
     *
     */
    public function __construct(
        string $scope = 'system',
        int $scopeid = 0,
        bool $showbooked = false,
        bool $showwaiting = false,
        bool $showreserved = false,
        bool $showtonotify = false,
        bool $showdeleted = false
    ) {
        // Columns.
        $bookeduserscols = ['name', 'action_delete'];
        $waitinglistcols = ['name', 'action_confirm_delete'];
        $reserveduserscols = ['name', 'action_delete'];
        $userstonotifycols = ['name', 'action_delete'];
        $deleteduserscols = ['name', 'timemodified'];

        // Headers.
        $bookedusersheaders = [get_string('booking', 'mod_booking'), get_string('delete', 'mod_booking')];
        $waitinglistheaders = [
            get_string('booking', 'mod_booking'),
            get_string('delete', 'mod_booking'),
        ];
        $reservedusersheaders = [get_string('booking', 'mod_booking'), get_string('delete', 'mod_booking')];
        $userstonotifyheaders = [get_string('booking', 'mod_booking'), get_string('delete', 'mod_booking')];
        $deletedusersheaders = [get_string('booking', 'mod_booking'), get_string('date')];

        // If the scope contains more than one option...
        // ...then we have to add an option column!
        if ($scope != 'option') {
            array_unshift($bookeduserscols, 'option');
            array_unshift($waitinglistcols, 'option');
            array_unshift($reserveduserscols, 'option');
            array_unshift($userstonotifycols, 'option');
            array_unshift($deleteduserscols, 'option');

            array_unshift($bookedusersheaders, get_string('bookingoption', 'mod_booking'));
            array_unshift($waitinglistheaders, get_string('bookingoption', 'mod_booking'));
            array_unshift($reservedusersheaders, get_string('bookingoption', 'mod_booking'));
            array_unshift($userstonotifyheaders, get_string('bookingoption', 'mod_booking'));
            array_unshift($deletedusersheaders, get_string('bookingoption', 'mod_booking'));
        } else {
            // We are in 'option' scope.
            if (get_config('booking', 'waitinglistshowplaceonwaitinglist')) {
                array_unshift($waitinglistcols, 'rank');
                array_unshift($waitinglistheaders, get_string('rank', 'mod_booking'));
            }
        }

        array_unshift($bookeduserscols, 'checkbox');
        array_unshift($waitinglistcols, 'checkbox');
        array_unshift($reserveduserscols, 'checkbox');
        array_unshift($userstonotifycols, 'checkbox');

        $headercheckboxhtml = '<input type="checkbox" id="usercheckboxall" name="selectall" value="0" />';
        array_unshift($bookedusersheaders, $headercheckboxhtml);
        array_unshift($waitinglistheaders, $headercheckboxhtml);
        array_unshift($reservedusersheaders, $headercheckboxhtml);
        array_unshift($userstonotifyheaders, $headercheckboxhtml);

        $this->bookedusers = $showbooked ?
            $this->render_users_table(
                $scope,
                $scopeid,
                MOD_BOOKING_STATUSPARAM_BOOKED,
                'booked',
                $bookeduserscols,
                $bookedusersheaders
            ) : null;

        $this->waitinglist = $showwaiting ? $this->render_users_table(
            $scope,
            $scopeid,
            MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            'waitinglist',
            $waitinglistcols,
            $waitinglistheaders,
            true
        ) : null;

        $this->reservedusers = $showreserved ? $this->render_users_table(
            $scope,
            $scopeid,
            MOD_BOOKING_STATUSPARAM_RESERVED,
            'reserved',
            $reserveduserscols,
            $reservedusersheaders,
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

        $html = $table->outhtml(20, false);
        return count($table->rawdata) > 0 ? $html : null;
    }

    /**
     * Export for template.
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        return array_filter([
            'bookedusers' => $this->bookedusers,
            'waitinglist' => $this->waitinglist,
            'reservedusers' => $this->reservedusers,
            'userstonotify' => $this->userstonotify,
            'deletedusers' => $this->deletedusers,
        ]);
    }
}
