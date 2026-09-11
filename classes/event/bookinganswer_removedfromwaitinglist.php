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

namespace mod_booking\event;

/**
 * Event bookinganswer_removedfromwaitinglist: a person was removed from the waiting list because
 * their waiting list offer expired (waitlistrecycling = 3).
 *
 * objectid is the booking option id, relateduserid the removed person.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bookinganswer_removedfromwaitinglist extends \core\event\base {
    /**
     * Set basic properties for the event.
     */
    protected function init() {
        $this->data['crud'] = 'd';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'booking_options';
    }

    /**
     * Get name
     *
     * @return string
     */
    public static function get_name() {
        return get_string('bookinganswerremovedfromwaitinglist', 'mod_booking');
    }

    /**
     * Get description
     *
     * @return string
     */
    public function get_description() {
        $data = [
            'userid' => $this->userid,
            'relateduserid' => $this->data['relateduserid'],
            'objectid' => $this->objectid,
        ];

        return get_string('bookinganswerremovedfromwaitinglistdesc', 'mod_booking', $data);
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
