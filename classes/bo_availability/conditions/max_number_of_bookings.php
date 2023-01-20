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
use mod_booking\booking_answers;
use mod_booking\booking_option_settings;
use mod_booking\singleton_service;
use MoodleQuickForm;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Base class for a single bo availability condition.
 *
 * All bo condition types must extend this class.
 *
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class max_number_of_bookings implements bo_condition {

    /** @var int $id Standard Conditions have hardcoded ids. */
    public $id = BO_COND_MAX_NUMBER_OF_BOOKINGS;

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
     * @param int $userid User ID to check availability for
     * @param bool $not Set true if we are inverting the condition
     * @return bool True if available
     */
    public function is_available(booking_option_settings $settings, $userid, $not = false): bool {

        global $DB;

        // This is the return value. Not available to begin with.
        $isavailable = false;

        $booking = singleton_service::get_instance_of_booking_by_optionid($settings->id);

        // This value comes from booking instance settings, not $settings, which would be from booking option.
        $maxperuser = $booking->settings->maxperuser;

        if (empty($maxperuser)) {
            $isavailable = true;
        } else {
            // Get the number of bookings, either STATUSPARAM_BOOKED or STATUSPARAM_WAITINGLIST.
            $numberofbookings = booking_answers::number_of_active_bookings_for_user($userid, $settings->bookingid);

            // If the $maxperuser-value is smaller then the value we are looking for, we return true.
            if ($numberofbookings < $maxperuser) {
                $isavailable = true;
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
    public function get_description(booking_option_settings $settings, $userid = null, $full = false, $not = false): array {

        $description = '';

        $isavailable = $this->is_available($settings, $userid, $not);

        if ($isavailable) {
            $description = $full ? get_string('bo_cond_max_number_of_bookings_full_available', 'mod_booking') :
                get_string('bo_cond_max_number_of_bookings_available', 'mod_booking');
        } else {
            $description = $full ? get_string('bo_cond_max_number_of_bookings_full_not_available', 'mod_booking') :
                get_string('bo_cond_max_number_of_bookings_not_available', 'mod_booking');
        }

        return [$isavailable, $description, false, BO_BUTTON_INDIFFERENT];
    }

    /**
     * Only customizable functions need to return their necessary form elements.
     *
     * @param MoodleQuickForm $mform
     * @param int $optionid
     * @return void
     */
    public function add_condition_to_mform(MoodleQuickForm &$mform, int $optionid = 0) {
        // Do nothing.
    }

    /**
     * The page refers to an additional page which a booking option can inject before the booking process.
     * Not all bo_conditions need to take advantage of this. But eg a condition which requires...
     * ... the acceptance of a booking policy would render the policy with this function.
     *
     * @param integer $optionid
     * @return string
     */
    public function render_page(int $optionid) {
        return "";
    }

    /**
     * Some conditions (like price & bookit) provide a button.
     * Renders the button, attaches js to the Page footer and returns the html.
     *
     * @param integer $optionid
     * @param object|null $user
     * @return string
     */
    public static function render_button(int $optionid, object $user = null) {
        return "";
    }
}
