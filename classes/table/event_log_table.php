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
 * Class event_log_table.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\table;
use mod_booking\singleton_service;
use Throwable;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/tablelib.php');

use local_wunderbyte_table\wunderbyte_table;

/**
 * Class to handle event log table.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class event_log_table extends wunderbyte_table {
    /**
     * Overrides the output for this column.
     * @param object $values
     * @return string
     */
    public function col_eventname($values) {
        unset($values->id);
        unset($values->origin);
        unset($values->ip);
        unset($values->realuserid);
        unset($values->user);
        unset($values->uniqueid);
        unset($values->username);

        $event = $values->eventname::restore((array)$values, []);

        return $event->get_name();
    }

    /**
     * Overrides the output for this column.
     * @param object $values
     * @return string
     */
    public function col_description($values) {
        unset($values->id);
        unset($values->origin);
        unset($values->ip);
        unset($values->realuserid);
        unset($values->user);
        unset($values->uniqueid);
        unset($values->username);

        $event = $values->eventname::restore((array)$values, []);
        try {
            $description = $event->get_description();
        } catch (Throwable $th) {
            $description = $values->other;
        }

        return $description;
    }

    /**
     * The context information column.
     *
     * @param object $values The row data.
     * @return string
     */
    public function col_timecreated($values) {
        return userdate($values->timecreated);
    }

    /**
     * Transform userid to username.
     *
     * @param object $values The row data.
     * @return string
     */
    public function col_userid($values) {

        $user = singleton_service::get_instance_of_user($values->userid);
        return "$user->firstname $user->lastname";
    }
}
