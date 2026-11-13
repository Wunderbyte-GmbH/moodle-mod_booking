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
 * The userprofilefield_changed event.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\event;

/**
 * A custom user profile field of a user changed, who is booked or on the waiting list of a booking option.
 *
 * The event is only triggered when a booking rule is waiting for the new value of the field
 * (see \mod_booking\booking_rules\local\userprofilefield_change), once for every booking option concerned.
 * The objectid is the id of the booking option, the related user is the user whose profile field changed.
 *
 * @property-read array $other {
 *      Extra information about event.
 *
 *      - string field: shortname of the custom user profile field.
 *      - string oldvalue: the value before the change.
 *      - string newvalue: the value after the change.
 *      - int status: booking status of the user in the booking option (booked or waiting list).
 * }
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class userprofilefield_changed extends \core\event\base {
    /**
     * Init
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'booking_options';
    }

    /**
     * Get name
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventuserprofilefieldchanged', 'mod_booking');
    }

    /**
     * Get description
     *
     * @return string
     */
    public function get_description() {
        // When the event is restored from the JSON of a rule, "other" is an object.
        $other = (array) $this->other;

        $user = \core_user::get_user((int) $this->relateduserid);

        $a = (object) [
            'relateduserid' => $this->relateduserid,
            'fullname' => $user ? fullname($user) : '',
            'optionid' => $this->objectid,
            'field' => $other['field'] ?? '',
            'oldvalue' => $other['oldvalue'] ?? '',
            'newvalue' => $other['newvalue'] ?? '',
        ];

        return get_string('eventuserprofilefieldchangeddesc', 'mod_booking', $a);
    }

    /**
     * Get url
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/mod/booking/view.php', ['id' => $this->contextinstanceid]);
    }

    /**
     * Custom validation.
     *
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();

        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The \'relateduserid\' must be set.');
        }
    }
}
