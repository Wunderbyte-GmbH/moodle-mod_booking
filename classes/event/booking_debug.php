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
 * A debug event.
 *
 * @package mod_booking
 * @copyright 2024 Georg Maißer, Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_booking\event;

use moodle_url;

/**
 * The booking_cancelled event class.
 * @property-read array $other { Extra information about event. Acesss an instance of the booking module }
 * @since Moodle 4.1
 * @copyright 2024 Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_debug extends \core\event\base {
    /**
     * Booking init.
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'booking';
    }

    /**
     * Get name.
     * @return string
     */
    public static function get_name() {
        return "Debug Booking";
    }
    /**
     * Get description.
     * @return string
     */
    public function get_description() {
        $datastring = json_encode($this->data);
        return "We got the following data: $datastring";
    }

    /**
     * Get url
     * @return moodle_url
     */
    public function get_url() {
        return new moodle_url(
            '/mod/booking/subscribeusers.php',
            ['id' => $this->contextinstanceid, 'optionid' => $this->objectid]
        );
    }
}
