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
 * The bookinganswer_cancelled event.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author David Bogner, Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_booking\event;

/**
 * The bookinganswer_cancelled event.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author David Bogner, Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bookinganswer_cancelled extends \core\event\base {

    /**
     * Init
     *
     * @return void
     *
     */
    protected function init() {
        $this->data['crud'] = 'u';
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
        return get_string('bookinganswer_cancelled', 'booking');
    }

    /**
     * Get description
     *
     * @return string
     *
     */
    public function get_description() {

        $userid = $this->data['userid']; // The user who DID the cancellation.
        $relateduserid = $this->data['relateduserid']; // Affected user - the user who was cancelled from the option.
        $optionid = $this->data['objectid']; // The option id.

        $extrainfo = '';
        if (!empty($this->data['other']['extrainfo'])) {
            $extrainfo = " NOTE: (" . $this->data['other']['extrainfo'] . ")";
        }

        if ($userid == $relateduserid) {
            return "The user with id $relateduserid cancelled his booking of the option with id $optionid.$extrainfo";
        } else {
            return "The user with id $relateduserid was removed from the option with id $optionid by user with id $userid." .
                $extrainfo;
        }
    }

    /**
     * Get_url
     *
     * @return \moodle_url
     *
     */
    public function get_url() {
        return new \moodle_url('/mod/booking/subscribeusers.php',
                ['id' => $this->contextinstanceid, 'optionid' => $this->objectid]);
    }
}
