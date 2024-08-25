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
use mod_booking\subbookings\subbookings_info;
use MoodleQuickForm;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Class to check if booking this option is blocked by subbooking.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class subbooking_blocks implements bo_condition {

    /** @var int $id Standard Conditions have hardcoded ids. */
    public $id = MOD_BOOKING_BO_COND_SUBBOOKINGBLOCKS;

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
    public function is_available(booking_option_settings $settings, int $userid, bool $not = false): bool {

        global $DB;

        // This is the return value. Not available to begin with.
        $isavailable = false;

        if (!subbookings_info::is_blocked($settings)) {
            $isavailable = true;
        }

        // If it's inversed, we inverse.
        if ($not) {
            $isavailable = !$isavailable;
        }

        return $isavailable;
    }

    /**
     * Each function can return additional sql.
     * This will be used if the conditions should not only block booking...
     * ... but actually hide the conditons alltogether.
     *
     * @return array
     */
    public function return_sql(): array {

        return ['', '', '', [], ''];
    }

    /**
     * The hard block is complementary to the is_available check.
     * While is_available is used to build eg also the prebooking modals and...
     * ... introduces eg the booking policy or the subbooking page, the hard block is meant to prevent ...
     * ... unwanted booking. It's the check just before booking if we really...
     * ... want the user to book. It will return always return false on subbookings...
     * ... as they are not necessary, but return true when the booking policy is not yet answered.
     * Hard block is only checked if is_available already returns false.
     *
     * @param booking_option_settings $settings
     * @param int $userid
     * @return bool
     */
    public function hard_block(booking_option_settings $settings, $userid): bool {

        return false;
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
     * This condition is used to block from booking, but also to offer an interface...
     * ... for users to book something else, the subbookings.
     *
     * @param booking_option_settings $settings Item we're checking
     * @param int $userid User ID to check availability for
     * @param bool $full Set true if this is the 'full information' view
     * @param bool $not Set true if we are inverting the condition
     * @return array availability and Information string (for admin) about all restrictions on
     *   this item
     */
    public function get_description(booking_option_settings $settings, $userid = null, $full = false, $not = false): array {

        $description = '';

        $isavailable = $this->is_available($settings, $userid, $not);

        $description = $this->get_description_string($isavailable, $full);

        return [$isavailable, $description, MOD_BOOKING_BO_PREPAGE_PREBOOK, MOD_BOOKING_BO_BUTTON_NOBUTTON];
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
     * @param int $optionid
     * @param int $userid optional user id
     * @return array
     */
    public function render_page(int $optionid, int $userid = 0) {

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        // First, we need to have a list of the blocking subbookings.

        $templates = [];
        $dataarray = [];
        foreach ($settings->subbookings as $subbooking) {
            if ($subbooking->block) {
                list($data, $template) = $subbooking->return_interface($settings, $userid);
                if (!empty($data)) {
                    $dataarray[] = $data;
                    $templates[] = $template;
                }
            }
        }

        $jsonstring = json_encode($dataarray);

        $returnarray = [
            // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
            /*'json' => $jsonstring, */
            'data' => $dataarray,
            'template' => implode(',', $templates),
            'buttontype' => 0,
        ];

        return $returnarray;
    }

    /**
     * Some conditions (like price & bookit) provide a button.
     * Renders the button, attaches js to the Page footer and returns the html.
     * Return should look somehow like this.
     * ['mod_booking/bookit_button', $data];
     *
     * @param booking_option_settings $settings
     * @param int $userid
     * @param bool $full
     * @param bool $not
     * @param bool $fullwidth
     * @return array
     */
    public function render_button(booking_option_settings $settings,
        int $userid = 0, bool $full = false, bool $not = false, bool $fullwidth = true): array {

        return ['', ''];
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
            $description = $full ? get_string('bocondisbookablefullavailable', 'mod_booking') :
                get_string('bocondisbookableavailable', 'mod_booking');
        } else {

            // If we have one or more subbookings, we render the interface here.
            // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
            /* foreach ($settings->subbookings as $subbooking) {

                // These are already instantiated subbookings, we can call the function right away.
                // list($data, $template) = $subbooking->return_interface($settings);
            } */

            $description = '';

        }
        return $description;
    }
}
