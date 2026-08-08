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
 * The ticket_created event.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\event;

/**
 * The ticket_created event class.
 *
 * Fired when an entry ticket has been created for a participant. Booking rules react on this
 * event to deliver the ticket, using the "Send ticket" action.
 *
 * @property-read array $other { Extra information about event. Contains optionid, optionname, ticketid and code }
 * @since Moodle 4.5
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ticket_created extends \core\event\base {
    /**
     * Init
     *
     * @return void
     *
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'booking_tickets';
    }

    /**
     * Get name
     *
     * This string is also the label shown in the event dropdown of the booking rules manager.
     *
     * @return string
     *
     */
    public static function get_name() {
        return get_string('ticketcreated', 'mod_booking');
    }

    /**
     * Get description
     *
     * @return string
     *
     */
    public function get_description() {
        $data = [
            'userid' => $this->userid,
            'relateduserid' => $this->data['relateduserid'],
            'objectid' => $this->objectid,
            'optionid' => $this->data['other']['optionid'] ?? 0,
        ];
        return get_string('ticketcreateddesc', 'mod_booking', $data);
    }

    /**
     * Get_url
     *
     * @return \moodle_url
     *
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
        if (!isset($this->data['relateduserid'])) {
            throw new \coding_exception('The \'relateduserid\' must be set.');
        }
        // The booking rules engine resolves the option from other[optionid].
        if (!isset($this->data['other']['optionid'])) {
            throw new \coding_exception('The \'optionid\' must be set in other.');
        }
    }
}
