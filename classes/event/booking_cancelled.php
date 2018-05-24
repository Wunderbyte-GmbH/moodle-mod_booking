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
 * The booking_cancelled event.
 *
 * @package mod_booking
 * @copyright 2014 David Bogner, http://www.edulabs.org
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_booking\event;

defined('MOODLE_INTERNAL') || die();


/**
 * The booking_cancelled event class.
 *
 * @property-read array $other { Extra information about event. Acesss an instance of the booking module }
 * @since Moodle 2.7
 * @copyright 2014 David Bogner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_cancelled extends \core\event\base {

    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'booking_answers';
    }

    public static function get_name() {
        return get_string('eventbooking_cancelled', 'booking');
    }

    public function get_description() {
        if ($this->userid != $this->data['relateduserid']) {
            return "The user with id {$this->userid} cancelled the user with id {$this->data['other']['userid']} from the option with id  {$this->objectid}.";
        } else {
            return "The user with id {$this->userid} cancelled the booking option with id {$this->objectid}.";
        }
    }

    public function get_url() {
        return new \moodle_url('/mod/booking/subscribeusers.php',
                array('id' => $this->contextinstanceid, 'optionid' => $this->objectid));
    }
}