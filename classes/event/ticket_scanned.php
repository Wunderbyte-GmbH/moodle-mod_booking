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
 * The ticket_scanned event.
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\event;

use mod_booking\singleton_service;
use stdClass;

/**
 * The ticket_scanned event class (SofaTicket entry control).
 *
 * Fired when an entry ticket is successfully scanned and the participant is checked in.
 * `relateduserid` is the scanned (admitted) participant; `userid` is the scanning staff member;
 * `objectid` is the booking option id. Traceability of check-ins runs via this event + presence status.
 *
 * @since Moodle 4.5
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ticket_scanned extends \core\event\base {
    /**
     * Init
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'booking_answers';
    }

    /**
     * Get name (used for rule/report labels — never build an 'event_' key manually).
     *
     * @return string
     */
    public static function get_name() {
        return get_string('ticketscanned', 'mod_booking');
    }

    /**
     * Get description
     *
     * @return string
     */
    public function get_description() {
        $relateduserid = (int) $this->data['relateduserid'];
        $ruser = singleton_service::get_instance_of_user($relateduserid);

        $a = new stdClass();
        $a->relateduser = $ruser->firstname . " " . $ruser->lastname . " (ID: " . $relateduserid . ")";
        $a->scanner = $this->userid;
        $a->optionid = $this->objectid;

        return get_string('ticketscannedinfo', 'mod_booking', $a);
    }

    /**
     * Get_url
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/mod/booking/view.php', ['id' => $this->contextinstanceid]);
    }

    /**
     * Custom validation.
     *
     * @throws \coding_exception
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The \'relateduserid\' must be set.');
        }
    }
}
