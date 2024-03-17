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

use local_wunderbyte_table\filters\types\standardfilter;
use local_wunderbyte_table\wunderbyte_table;
use mod_booking\booking_answers;
use mod_booking\singleton_service;
use mod_booking\table\manageusers_table;
use moodle_url;
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

    /** @var array $bookedusers array of bookedusers */
    public $bookedusers = [];

    /** @var array $waitinglist array of waitinglist */
    public $waitinglist = [];

    /** @var string $reservedusers array of reservedusers */
    public $reservedusers;

    /** @var array $userstonotify array of reservedusers */
    public $userstonotify = [];

    /** @var array $deletedusers array of reservedusers */
    public $deletedusers = [];

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
            bool $showdeleted = false) {

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $ba = singleton_service::get_instance_of_booking_answers($settings);

        if ($showreserved) {

        }

        if ($showbooked) {
            foreach ($ba->usersonlist as $item) {
                $user = singleton_service::get_instance_of_user($item->id);
                $url = new moodle_url('/user/profile.php', ['id' => $item->id]);

                $this->bookedusers[] = [
                    'id' => $item->baid,
                    'firstname' => $user->firstname,
                    'lastname' => $user->lastname,
                    'email' => $user->email,
                    'status' => get_string('waitinglist', 'mod_booking'),
                    'userprofilelink' => $url->out(),
                ];
            }
        }

        if ($showwaiting) {
            $table = new manageusers_table('waitinglist' . $optionid);

            $table->define_cache('mod_booking', 'bookedusertable');

            $table->define_columns(['name', 'action']);

            $table->sortablerows = true;

            list($fields, $from, $where, $params)
                = booking_answers::return_sql_for_booked_users($optionid, MOD_BOOKING_STATUSPARAM_WAITINGLIST);

            $table->set_sql($fields, $from, $where, $params);

            $this->waitinglist = $table->outhtml(20, false);
        }

        if ($showtonotifiy) {
            foreach ($ba->userstonotify as $item) {
                $user = singleton_service::get_instance_of_user($item->id);
                $url = new moodle_url('/user/profile.php', ['id' => $item->id]);

                $this->userstonotify[] = [
                    'id' => $item->baid,
                    'firstname' => $user->firstname,
                    'lastname' => $user->lastname,
                    'email' => $user->email,
                    'status' => get_string('waitinglist', 'mod_booking'),
                    'userprofilelink' => $url->out(),
                ];
            }
        }

        if ($showdeleted) {
            foreach ($ba->usersdeleted as $item) {
                $user = singleton_service::get_instance_of_user($item->id);
                $url = new moodle_url('/user/profile.php', ['id' => $item->id]);

                $this->deletedusers[] = [
                    'id' => $item->baid,
                    'firstname' => $user->firstname,
                    'lastname' => $user->lastname,
                    'email' => $user->email,
                    'status' => get_string('waitinglist', 'mod_booking'),
                    'userprofilelink' => $url->out(),
                ];
            }
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
