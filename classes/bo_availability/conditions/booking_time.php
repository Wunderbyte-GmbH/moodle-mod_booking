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
use MoodleQuickForm;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Class for a single bo availability condition.
 *
 * This returns true or false based on the standard booking times
 * OR a custom time passed on via the availability json
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_time implements bo_condition {

    /** @var int $id Standard Conditions have hardcoded ids. */
    public $id = BO_COND_BOOKING_TIME;

    /**
     * Needed to see if class can take JSON.
     * @return bool
     */
    public function is_json_compatible(): bool {
        return false; // Hardcoded condition, but still shown in mform.
    }

    /**
     * Needed to see if it shows up in mform.
     * @return bool
     */
    public function is_shown_in_mform(): bool {
        return true; // Hardcoded condition, but still shown in mform.
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

        $now = time();

        // Here, the logic is easier when we set available to true first.
        $isavailable = true;

        // This condition is either hardcoded with the standard booking opening or booking closing time, or its customized.

        if ($this->id == -2) {
            $openingtime = $settings->bookingopeningtime ?? null;
            $closingtime = $settings->bookingclosingtime ?? null;
        } else {

            $jsonstring = $settings->availability ?? '';

            $jsonobject = json_decode($jsonstring);

            $openingtime = $jsonobject->openingtime ?? null;
            $closingtime = $jsonobject->closingtime ?? null;
        }

        // If there is a bookingopeningtime and now is smaller, we return false.
        if (!empty($openingtime)
            && ($now < $openingtime)) {
            $isavailable = false;
        }

        // If there is a bookingclosingtime and now is bigger, we return false.
        if (!empty($closingtime)
            && ($now > $closingtime)) {
            $isavailable = false;
        }

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
    public function get_description(booking_option_settings $settings, $userid = null, $full = false, $not = false):array {

        $description = '';

        $isavailable = $this->is_available($settings, $userid, $not);

        $description = $this->get_description_string($isavailable, $full);

        return [$isavailable, $description, BO_PREPAGE_NONE, BO_BUTTON_MYALERT];
    }

    /**
     * Only customizable functions need to return their necessary form elements.
     *
     * @param MoodleQuickForm $mform
     * @param int $optionid
     * @return void
     */
    public function add_condition_to_mform(MoodleQuickForm &$mform, int $optionid = 0) {
        global $DB;

        $mform->addElement('checkbox', 'restrictanswerperiodopening',
                get_string('restrictanswerperiodopening', 'mod_booking'));

        $mform->addElement('date_time_selector', 'bookingopeningtime', get_string('bookingopeningtime', 'mod_booking'));
        $mform->setType('bookingopeningtime', PARAM_INT);
        $mform->hideIf('bookingopeningtime', 'restrictanswerperiodopening', 'notchecked');

        $mform->addElement('checkbox', 'restrictanswerperiodclosing',
                get_string('restrictanswerperiodclosing', 'mod_booking'));

        $mform->addElement('date_time_selector', 'bookingclosingtime', get_string('bookingclosingtime', 'mod_booking'));
        $mform->setType('bookingclosingtime', PARAM_INT);
        $mform->hideIf('bookingclosingtime', 'restrictanswerperiodclosing', 'notchecked');

        // Workaround: Only show, if it is not turned off in the option form config.
        // We currently need this, because html elements do not show up in the option form config.
        // In expert mode, we always show everything.
        $showhorizontalline = true;
        $formmode = get_user_preferences('optionform_mode');
        if ($formmode !== 'expert') {
            $cfgrestrictanswerperiodopening = $DB->get_field('booking_optionformconfig', 'active',
                ['elementname' => 'restrictanswerperiodopening']);
            $cfgrestrictanswerperiodclosing = $DB->get_field('booking_optionformconfig', 'active',
                ['elementname' => 'restrictanswerperiodclosing']);
            if (($cfgrestrictanswerperiodopening === "0") && ($cfgrestrictanswerperiodclosing === "0")) {
                $showhorizontalline = false;
            }
        }
        if ($showhorizontalline) {
            $mform->addElement('html', '<hr class="w-50"/>');
        }
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
     * Return should look somehow like this.
     * ['mod_booking/bookit_button', $data];
     *
     * @param booking_option_settings $settings
     * @param int $userid
     * @param boolean $full
     * @param boolean $not
     * @return array
     */
    public function render_button(booking_option_settings $settings, $userid = 0, $full = false, $not = false):array {
        global $USER;

        if ($userid === null) {
            $userid = $USER->id;
        }
        $label = $this->get_description_string(false, $full);

        return [
            'mod_booking/bookit_button',
            [
                'itemid' => $settings->id,
                'area' => 'option',
                'userid' => $userid ?? 0,
                'main' => [
                    'label' => $label,
                    'class' => 'alert alert-success',
                    'role' => 'alert',
                ]
            ]
        ];
    }

    /**
     * Helper function to return localized description strings.
     *
     * @param bool $isavailable
     * @param bool $full
     * @return void
     */
    private function get_description_string($isavailable, $full) {
        if ($isavailable) {
            $description = $full ? get_string('bo_cond_booking_time_full_available', 'mod_booking') :
                get_string('bo_cond_booking_time_available', 'mod_booking');
        } else {
            $description = $full ? get_string('bo_cond_booking_time_full_not_available', 'mod_booking') :
                get_string('bo_cond_booking_time_not_available', 'mod_booking');
        }
        return $description;
    }
}
