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

 namespace mod_booking\bo_availability\subconditions;

use mod_booking\bo_availability\bo_subcondition;
use mod_booking\booking_option_settings;
use mod_booking\price;
use mod_booking\singleton_service;
use MoodleQuickForm;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * If a price is set for the option, normal booking is not available.
 *
 * Booking only via payment.
 *
 * All bo condition types must extend this class.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class isbookable implements bo_subcondition {

    /** @var int $id Standard Conditions have hardcoded ids. */
    public $id = MOD_BOOKING_BO_COND_ISBOOKABLE;

    /**
     * Get the condition id.
     *
     * @return int
     *
     */
    public function get_id(): int {
        return $this->id;
    }

    /**
     * Needed to see if class can take JSON.
     * @return bool
     */
    public function is_json_compatible(): bool {
        return false; // Hardcoded condition.
    }

    /**
     * Needed to see if it shows up in mform.
     * @return bool
     */
    public function is_shown_in_mform(): bool {
        return false;
    }

    /**
     * Determines whether a particular item is currently available
     * according to this availability condition.
     * @param booking_option_settings $settings Item we're checking
     * @param int $subbookingid
     * @param int $userid User ID to check availability for
     * @param bool $not Set true if we are inverting the condition
     * @return bool True if available
     */
    public function is_available(booking_option_settings $settings, int $subbookingid, int $userid, $not = false): bool {

        global $USER;

        if ($userid == 0) {
            $userid = $USER->id;
        }

        // This is the return value. Not available to begin with.
        $isavailable = false;

        // Get the booking answers for this instance.
        $bookinganswer = singleton_service::get_instance_of_booking_answers($settings);

        // If the user is not yet booked in the option we return fasle.
        switch ($bookinganswer->user_status($userid)) {
            case MOD_BOOKING_STATUSPARAM_BOOKED:
            case MOD_BOOKING_STATUSPARAM_RESERVED:
                $isavailable = true;
                break;
            default:
                $isavailable = false;
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
     * @param booking_option_settings $settings Item we're checking
     * @param int $subbookingid
     * @param int $userid User ID to check availability for
     * @param bool $full Set true if this is the 'full information' view
     * @param bool $not Set true if we are inverting the condition
     * @return array availability and Information string (for admin) about all restrictions on
     *   this item
     */
    public function get_description(booking_option_settings $settings,
        $subbookingid, $userid = null, $full = false, $not = false): array {

        $description = '';

        $isavailable = $this->is_available($settings, $subbookingid, $userid, $not);

        $description = $this->get_description_string($isavailable, $full);

        return [$isavailable, $description, MOD_BOOKING_BO_PREPAGE_NONE, MOD_BOOKING_BO_BUTTON_JUSTMYALERT];
    }

    /**
     * Only customizable functions need to return their necessary form elements.
     *
     * @param MoodleQuickForm $mform
     * @param int $optionid
     * @param int $subbookingid
     * @return void
     */
    public function add_condition_to_mform(MoodleQuickForm &$mform, int $optionid = 0, $subbookingid = 0) {
        // Do nothing.
    }

    /**
     * Some conditions (like price & bookit) provide a button.
     * Renders the button, attaches js to the Page footer and returns the html.
     * Return should look somehow like this.
     * ['mod_booking/bookit_button', $data];
     *
     * @param booking_option_settings $settings
     * @param int $subbookingid
     * @param int $userid
     * @param bool $full
     * @param bool $not
     * @param bool $fullwidth
     * @return array
     */
    public function render_button(booking_option_settings $settings,
        int $subbookingid, int $userid=0, bool $full=false, bool $not=false, bool $fullwidth=true): array {

        global $USER;

        if ($userid === null) {
            $userid = $USER->id;
        }
        $label = $this->get_description_string(false, $full);

        $data = [
            'itemid' => $settings->id,
            'area' => 'option',
            'userid' => $userid ?? 0,
            'nojs' => true,
            'main' => [
                'label' => $label,
                'class' => 'alert alert-danger',
                'role' => 'alert',
            ],
        ];

        if ($fullwidth) {
            $data['fullwidth'] = $fullwidth;
        }

        return ['mod_booking/bookit_button', $data];
    }

    /**
     * Helper function to return localized description strings.
     *
     * @param bool $isavailable
     * @param bool $full
     * @return string
     */
    private function get_description_string($isavailable, $full) {
        if ($isavailable) {
            $description = $full ? get_string('bocondsubisbookablefullavailable', 'mod_booking') :
                get_string('bocondsubisbookableavailable', 'mod_booking');
        } else {
            $description = $full ? get_string('bocondsubisbookablefullnotavailable', 'mod_booking') :
                get_string('bocondsubisbookablenotavailable', 'mod_booking');
        }
        return $description;
    }
}
