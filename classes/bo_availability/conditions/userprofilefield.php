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
 * Base class for a single booking option availability condition.
 *
 * All bo condition types must extend this class.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

 namespace mod_booking\bo_availability\conditions;

use mod_booking\bo_availability\bo_condition;
use mod_booking\booking_option_settings;
use mod_booking\singleton_service;
use MoodleQuickForm;

/**
 * This class takes the configuration from json in the available column of booking_options table.
 *
 * All bo condition types must extend this class.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class userprofilefield implements bo_condition {

    /** @var int $id Id is set via json during construction */
    public $id = null;

    /** @var int $iscustomizable marker to see if class can take json. */
    public $iscustomizable = true;

    /** @var stdClass $customsettings an stdclass coming from the json which passes custom settings */
    public $customsettings = null;

    /**
     * Constructor.
     *
     * @param integer $id
     * @return void
     */
    public function __construct(int $id = null) {

        if ($id) {
            $this->id = $id;
        }
    }

    /**
     * Determines whether a particular item is currently available
     * according to this availability condition.
     * @param booking_option_settings $settings Item we're checking
     * @param int $userid User ID to check availability for
     * @param bool $not Set true if we are inverting the condition
     * @return bool True if available
     */
    public function is_available(booking_option_settings $settings, $userid, $not = false):bool {

        // This is the return value. Not available to begin with.
        $isavailable = false;

        if (!isset($this->customsettings->profilefield)) {
            $isavailable = true;
        } else {

            // Profilefield is set.
            $user = singleton_service::get_instance_of_user($userid);
            $profilefield = $this->customsettings->profilefield;

            // If the profilefield is not here right away, we might need to retrieve it.
            if (!isset($user->$profilefield)) {
                profile_load_custom_fields($user);
                $value = $user->profile[$profilefield] ?? null;
            } else {
                $value = $user->$profilefield;
            }

            // If value is not null, we compare it.
            if ($value) {
                switch ($this->customsettings->operator) {
                    case '=':
                        if ($value == $this->customsettings->value) {
                            $isavailable = true;
                        }
                        break;
                    case '<':
                        if ($value < $this->customsettings->value) {
                            $isavailable = true;
                        }
                        break;
                    case '>':
                        if ($value > $this->customsettings->value) {
                            $isavailable = true;
                        }
                        break;
                    case '~':
                        if (strpos($this->customsettings->value, $value)) {
                            $isavailable = true;
                        }
                        break;
                }
            }

        }

        // If it's inversed, we inverse.
        if ($not) {
            $isavailable = !$isavailable;
        }

        return $isavailable;
    }

    /**
     * Obtains a string describing this restriction (whether or not
     * it actually applies). Used to obtain information that is displayed to
     * students if the activity is not available to them, and for staff to see
     * what conditions are.
     *
     * The $full parameter can be used to distinguish between 'staff' cases
     * (when displaying all information about the activity) and 'student' cases
     * (when displaying only conditions they don't meet).
     *
     * @param bool $full Set true if this is the 'full information' view
     * @param booking_option_settings $settings Item we're checking
     * @param int $userid User ID to check availability for
     * @param bool $not Set true if we are inverting the condition
     * @return array availability and Information string (for admin) about all restrictions on
     *   this item
     */
    public function get_description($full = false, booking_option_settings $settings, $userid = null, $not = false):array {

        $description = '';

        $isavailable = $this->is_available($settings, $userid, $not);

        if ($isavailable) {
            $description = $full ? get_string('bo_cond_userprofilefield_full_available', 'mod_booking') :
                get_string('bo_cond_userprofilefield_available', 'mod_booking');
        } else {
            $description = $full ? get_string('bo_cond_userprofilefield_full_not_available',
                'mod_booking',
                $this->customsettings) :
                get_string('bo_cond_userprofilefield_not_available', 'mod_booking');
        }

        return [$isavailable, $description];
    }

    /**
     * Only customizable functions need to return their necessary form elements.
     *
     * @param MoodleQuickForm $mform
     * @return void
     */
    public function add_condition_to_mform(MoodleQuickForm &$mform) {

        $mform->addElement('advcheckbox', 'priceformulaisactive', get_string('priceformulaisactive', 'mod_booking'),
            null, null, [0, 1]);
        $mform->setDefault('priceformulaisactive', 0);

        $mform->addElement('advcheckbox', 'priceformulaoff', get_string('priceformulaoff', 'mod_booking'),
        null, null, [0, 1]);
        $mform->addHelpButton('priceformulaoff', 'priceformulaoff', 'mod_booking');
        $mform->setDefault('priceformulaoff', 0);

    }
}
