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
 * The bookinganswercustomformconditions_deleted event.
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
 * The bookinganswercustomformconditions_deleted event.
 *
 * @package mod_booking
 * @copyright 2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Magdalena Holczik
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bookinganswercustomformconditions_deleted extends \core\event\base {

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
        return get_string('bookinganswerupdated', 'mod_booking');
    }

    /**
     * Get description
     *
     * @return string
     *
     */
    public function get_description() {

        $userid = $this->data['userid']; // The user who DID the deletion.
        $bookinganswerid = $this->data['objectid']; // The id of the bookinganswer.
        $relateduserid = $this->data['relateduser']; // The id of the user from the bookinganswer.
        $otherdata = $this->data['other']; // The other data.
        // The other data.
        $col = $otherdata['column'] ?? '';

        $user = singleton_service::get_instance_of_user((int) $userid);
        $ruser = singleton_service::get_instance_of_user((int) $relateduserid);

        $a = new stdClass();
        $a->user = $user->firstname . " " . $user->lastname . " (ID: " . $userid . ")";
        $a->relateduser = $ruser->firstname . " " . $ruser->lastname . " (ID: " . $relateduserid . ")";
        $a->bookinganswerid = $bookinganswerid;

        return get_string('eventdesc:bookinganswercustomformconditionsdeleted', 'mod_booking', $a);

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
