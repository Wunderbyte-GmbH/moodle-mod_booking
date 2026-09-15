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
 * The ticket_rejected event: entry staff refused a scanned ticket at the door.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\event;

use mod_booking\singleton_service;
use stdClass;

/**
 * Fired when entry staff rejects a scanned ticket (e.g. the person at the door is not the holder).
 *
 * No presence status is written for a rejection; the event is the audit trail.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ticket_rejected extends \core\event\base {
    /**
     * Init
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'booking_tickets';
    }

    /**
     * Get name (used for rule/report labels — never build an 'event_' key manually).
     *
     * @return string
     */
    public static function get_name() {
        return get_string('ticketrejected', 'mod_booking');
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
        $a->optionid = (int) ($this->other['optionid'] ?? 0);
        $a->optiondateid = (int) ($this->other['optiondateid'] ?? 0);
        return get_string('ticketrejectedinfo', 'mod_booking', $a);
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
