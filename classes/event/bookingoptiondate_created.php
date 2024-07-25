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
 * The bookingoptiondate_created event.
 *
 * @package mod_booking
 * @copyright 2021 Bernhard Fischer, https://www.wunderbyte.at
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_booking\event;

/**
 * The bookingoptiondate_created event class.
 *
 * @property-read array $other { Extra information about the event. Acesss an instance of the booking module }
 * @since Moodle 2.7
 * @copyright 2021 Bernhard Fischer, https://www.wunderbyte.at
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bookingoptiondate_created extends \core\event\base {

    /**
     * Init
     *
     * @return void
     *
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'booking_optiondates';
    }

    /**
     * Get name
     *
     * @return string
     *
     */
    public static function get_name() {
        return get_string('bookingoptiondatecreated', 'booking');
    }

    /**
     * Get description
     *
     * @return string
     *
     */
    public function get_description() {
        return "User with id '{$this->userid}' created 'booking option date (session)' with id '{$this->objectid}'.";
    }

    /**
     * Get_url
     *
     * @return \moodle_url
     *
     */
    public function get_url() {
        return new \moodle_url('/mod/booking/report.php',
                ['id' => $this->contextinstanceid, 'optiondateid' => $this->objectid]);
    }
}
