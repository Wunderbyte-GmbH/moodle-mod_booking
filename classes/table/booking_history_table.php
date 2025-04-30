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
 * Booking history table.
 *
 * @package     mod_booking
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Bernhard Fischer-Sengseis
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\table;

use mod_booking\local\bookingstracker\bookingstracker_helper;
use mod_booking\singleton_service;
use mod_booking\booking;
use moodle_url;

defined('MOODLE_INTERNAL') || die();

use local_wunderbyte_table\wunderbyte_table;
use stdClass;

global $CFG;

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Booking history table.
 *
 * @package     mod_booking
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Bernhard Fischer-Sengseis
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_history_table extends wunderbyte_table {
    /**
     * Return option column.
     *
     * @param stdClass $values
     * @return string
     */
    public function col_text(stdClass $values) {
        if ($this->is_downloading()) {
            return $values->text ?? '';
        }
        return bookingstracker_helper::render_col_text($values);
    }

    /**
     * Column for timecreated value.
     * @param stdClass $values
     * @return string
     */
    public function col_timecreated(stdClass $values) {
        return userdate($values->timecreated);
    }

    /**
     * Column for details of operation.
     * @param stdClass $values
     * @return string
     */
    public function col_json(stdClass $values) {
        if (empty($values->json)) {
            return "";
        }
        $info = json_decode($values->json, true);
        $a = new stdClass();
        if (strrpos($values->json, 'presence') !== false) {
            $possiblepresences = booking::get_array_of_possible_presence_statuses();
            $a->presenceold = $possiblepresences[$info['presence']['presenceold']];
            $a->presencenew = $possiblepresences[$info['presence']['presencenew']];
            return get_string('presencechangedhistory', 'mod_booking', $a);
        }
        if (strrpos($values->json, 'notes') !== false) {
            $a->notesold = $info['notes']['notesold'];
            $a->notesnew = $info['notes']['notesnew'];
            return get_string('noteseditedhistory', 'mod_booking', $a);
        }
        if (strrpos($values->json, 'booking') !== false) {
            $a->oldbooking = $info['booking']['oldbooking'];
            $a->newbooking = $values->bookingid;
            return get_string('movedbookinghistory', 'mod_booking', $a);
        };
        if (strrpos($values->json, 'completion') !== false) {
            $info = json_decode($values->json, true);
            $possiblecompletions =
            [   0 => get_string('statusincomplete', 'booking'),
                1 => get_string('completed', 'booking'),
            ];
            $a = new stdClass();
            $a->completionold = $possiblecompletions[$info['completion']['completionold']];
            $a->completionnew = $possiblecompletions[$info['completion']['completionnew']];
            return get_string('completionchangedhistory', 'mod_booking', $a);
        }
        return "";
    }


    /**
     * Column for details of operation.
     * @param stdClass $values
     * @return string
     */
    public function col_status(stdClass $values) {
        $status = booking::get_array_of_possible_booking_history_statuses();
        $resolved = $status[$values->status];
        return "$values->status - $resolved";
    }

    /**
     * Column for details of operation.
     * @param stdClass $values
     * @return string
     */
    public function col_usermodified(stdClass $values) {
        $user = singleton_service::get_instance_of_user($values->usermodified);
        return "$user->firstname $user->lastname";
    }
}
