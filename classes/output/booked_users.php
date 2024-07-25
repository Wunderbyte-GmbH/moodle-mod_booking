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
 * This file contains the definition for the renderable classes for bookingoption dates.
 *
 * @package   mod_booking
 * @copyright 2022 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Bernhard Fischer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
 * It is used to display a slightly configurable list of booked users for a given booking option.
 *
 * @package     mod_booking
 * @copyright   2022 Wunderbyte GmbH {@link http://www.wunderbyte.at}
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
     * @param int $optionid
     * @param bool $showbooked
     * @param bool $showwaiting
     * @param bool $showreserved
     * @param bool $showtonotifiy
     * @param bool $showdeleted
     *
     */
    public function __construct(
        int $optionid,
        bool $showbooked = false,
        bool $showwaiting = false,
        bool $showreserved = false,
        bool $showtonotifiy = false,
        bool $showdeleted = false
    ) {

        if ($showreserved) {
            list($fields, $from, $where, $params)
                = booking_answers::return_sql_for_booked_users($optionid, MOD_BOOKING_STATUSPARAM_RESERVED);

            $tablename = 'reserved' . $optionid;

            $table = new manageusers_table($tablename);

            $table->define_cache('mod_booking', 'bookedusertable');
            $table->define_columns(['name', 'action_delete']);
            $table->set_sql($fields, $from, $where, $params);

            $html = $table->outhtml(20, false);
            $this->reservedusers = count($table->rawdata) > 0 ? $html : null;
        }

        if ($showbooked) {
            list($fields, $from, $where, $params)
                = booking_answers::return_sql_for_booked_users($optionid, MOD_BOOKING_STATUSPARAM_BOOKED);

            $tablename = 'booked' . $optionid;

            $table = new manageusers_table($tablename);

            $table->define_cache('mod_booking', 'bookedusertable');
            $table->define_columns(['name', 'action_delete']);
            $table->set_sql($fields, $from, $where, $params);

            $html = $table->outhtml(20, false);
            $this->bookedusers = count($table->rawdata) > 0 ? $html : null;
        }

        if ($showwaiting) {

            list($fields, $from, $where, $params)
                = booking_answers::return_sql_for_booked_users($optionid, MOD_BOOKING_STATUSPARAM_WAITINGLIST);

            $tablename = 'waitinglist' . $optionid;

            $table = new manageusers_table($tablename);

            $table->define_cache('mod_booking', 'bookedusertable');
            $table->define_columns(['rank', 'name', 'action_confirm_delete']);
            $table->sortablerows = true;
            $table->set_sql($fields, $from, $where, $params);

            $html = $table->outhtml(20, false);
            $this->waitinglist = count($table->rawdata) > 0 ? $html : null;
        }

        if ($showtonotifiy) {

            list($fields, $from, $where, $params)
                = booking_answers::return_sql_for_booked_users($optionid, MOD_BOOKING_STATUSPARAM_NOTIFYMELIST);

            $tablename = 'notifymelist' . $optionid;

            $table = new manageusers_table($tablename);

            $table->define_cache('mod_booking', 'bookedusertable');
            $table->define_columns(['name', 'action_delete']);
            $table->set_sql($fields, $from, $where, $params);

            $html = $table->outhtml(20, false);
            $this->userstonotify = count($table->rawdata) > 0 ? $html : null;
        }

        if ($showdeleted) {
            list($fields, $from, $where, $params)
                = booking_answers::return_sql_for_booked_users($optionid, MOD_BOOKING_STATUSPARAM_DELETED);

            $tablename = 'deleted' . $optionid;

            $table = new manageusers_table($tablename);

            $table->define_cache('mod_booking', 'bookedusertable');
            $table->define_columns(['name', 'timemodified']);
            $table->set_sql($fields, $from, $where, $params);

            $table->use_pages = true;

            $html = $table->outhtml(20, false);
            $this->deletedusers = count($table->rawdata) > 0 ? $html : null;
        }
    }

    /**
     * Export for template.
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output) {

        $returnarray = [];
        if (!empty($this->bookedusers)) {
            $returnarray['bookedusers'] = $this->bookedusers;
        }
        if (!empty($this->waitinglist)) {
            $returnarray['waitinglist'] = $this->waitinglist;
        }
        if (!empty($this->reservedusers)) {
            $returnarray['reservedusers'] = $this->reservedusers;
        }
        if (!empty($this->userstonotify)) {
            $returnarray['userstonotify'] = $this->userstonotify;
        }
        if (!empty($this->deletedusers)) {
            $returnarray['deletedusers'] = $this->deletedusers;
        }

        return $returnarray;
    }
}
