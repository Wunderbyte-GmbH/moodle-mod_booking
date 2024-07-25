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

use mod_booking\singleton_service;
use stdClass;

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
        return get_string('bookinganswercancelled', 'mod_booking');
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

        // TODO: Gute Description machen.

        $user = singleton_service::get_instance_of_user((int) $userid);
        $relateduser = singleton_service::get_instance_of_user((int) $relateduserid);
        $settings = singleton_service::get_instance_of_booking_option_settings((int) $optionid);

        $a = new stdClass();
        $a->user = $user->firstname . " " . $user->lastname . " (ID: " . $userid . ")";
        $a->relateduser = $relateduser->firstname . " " . $relateduser->lastname . " (ID: " . $relateduserid . ")";
        $a->title = $settings->get_title_with_prefix() . " (ID: " . $optionid . ")";

        $extrainfo = '';
        if (!empty($this->data['other']['extrainfo'])) {
            $extrainfo = " (" . $this->data['other']['extrainfo'] . ")";
        }

        if ($userid == $relateduserid) {
            return get_string('eventdesc:bookinganswercancelledself', 'mod_booking', $a) . $extrainfo;
        } else {
            return get_string('eventdesc:bookinganswercancelled', 'mod_booking', $a) . $extrainfo;
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
