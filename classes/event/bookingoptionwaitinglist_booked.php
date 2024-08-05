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
 * The bookingoptionwaitinglist_booked event.
 *
 * @package mod_booking
 * @copyright 2024 Wunderbyte, http://www.wunderbyte.at
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\event;

/**
 * The bookingoptionwaitinglist_booked event class.
 *
 * @property-read array $other { Extra information about event. Acesss an instance of the booking module }
 * @since Moodle 3.8
 * @copyright 2024 Wunderbyte
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bookingoptionwaitinglist_booked extends \core\event\base {

    /**
     * Init
     *
     * @return void
     *
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'booking_answers';
    }

    /**
     * Get name
     *
     * @return string
     *
     */
    public static function get_name() {
        return get_string('bookingoptionwaitinglistbooked', 'mod_booking');
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
        ];
        if ($this->userid != $this->data['relateduserid']) {
            return get_string('bookingoptionbookedotheruserwaitinglistdesc', 'mod_booking', $data);
        } else {
            return get_string('bookingoptionbookedsameuserwaitinglistdesc', 'mod_booking', $data);
        }
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
        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The \'relateduserid\' must be set.');
        }
    }
}
