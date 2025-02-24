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
 * Already booked condition (item has been booked).
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\bo_availability\conditions;

use mod_booking\bo_availability\bo_condition;
use mod_booking\bo_availability\bo_info;
use mod_booking\booking;
use mod_booking\booking_option_settings;
use mod_booking\singleton_service;
use moodle_url;
use MoodleQuickForm;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Base class for a single bo availability condition.
 *
 * All bo condition types must extend this class.
 *
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class maxoptionsfromcategory implements bo_condition {
    /** @var int $id Standard Conditions have hardcoded ids. */
    public $id = MOD_BOOKING_BO_COND_JSON_MAXOPTIONSFROMCATEGORY;

    /** @var bool $overwrittenbybillboard Indicates if the condition can be overwritten by the billboard. */
    public $overwrittenbybillboard = false;

    /**
     * Handling for otheranswersfromcategory.
     *
     * @var array
     */
    private array $handling = [];

    /**
     * The name of the customfield where the value is stored.
     *
     * @var string
     */
    private string $customfield = '';

    /**
     * Storing overlapping options.
     *
     * @var array
     */
    private array $otheranswers = [];

    /**
     * Customsettings.
     *
     * @var object
     */
    public object $customsettings;

    /**
     * Singleton instance.
     *
     * @var object
     */
    private static $instance = null;

    /**
     * C
     *
     *
     */
    private function __construct() {
    }

    /**
     * Singleton instance.
     *
     * @return object
     *
     */
    public static function instance(): object {
        if (empty(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Reset method to clear the singleton state.
     *
     * @return void
     *
     */
    public static function reset_instance(): void {
        self::$instance = null;
    }

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
     * @param int $userid User ID to check availability for
     * @param bool $not Set true if we are inverting the condition
     * @return bool True if available
     */
    public function is_available(booking_option_settings $settings, int $userid, bool $not = false): bool {
        global $DB;

        // This is the return value. Not available to begin with.
        $isavailable = false;

        // Check if this bookingoption contains the field defined in settings.
        $field = get_config('booking', 'maxoptionsfromcategoryfield');
        if (
            isset($settings->customfields[$field])
            && !empty($restriction = $this->max_options_defined($settings))
        ) {
            // Check if the settings of this booking contain restrictions.
            // Check if this option contains the field as defined in the restriction.

            // Get the booking answers for this instance.
            $bookinganswer = singleton_service::get_instance_of_booking_answers($settings);

            // If the user is not yet booked we return true.
            $this->otheranswers = $bookinganswer->exceeds_max_bookings($userid, $restriction, $field);
            if (
                empty($this->otheranswers)
            ) {
                $isavailable = true;
            }
        } else {
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
        return true;
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
     * @param int $userid User ID to check availability for
     * @param bool $full Set true if this is the 'full information' view
     * @param bool $not Set true if we are inverting the condition
     * @return array availability and Information string (for admin) about all restrictions on
     *   this item
     */
    public function get_description(booking_option_settings $settings, $userid = null, $full = false, $not = false): array {

        $description = '';

        $isavailable = $this->is_available($settings, $userid, $not);

        $description = $this->get_description_string($isavailable, $full, $settings, $userid);

        return [$isavailable, $description, MOD_BOOKING_BO_PREPAGE_NONE, MOD_BOOKING_BO_BUTTON_CANCEL];
    }

    /**
     * Only customizable functions need to return their necessary form elements.
     *
     * @param MoodleQuickForm $mform
     * @param int $optionid
     * @return void
     */
    public function add_condition_to_mform(MoodleQuickForm &$mform, int $optionid = 0) {
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
        return [];
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
    public function render_button(
        booking_option_settings $settings,
        int $userid = 0,
        bool $full = false,
        bool $not = false,
        bool $fullwidth = true
    ): array {

        $label = $this->get_description_string(false, $full, $settings);
        return bo_info::render_button(
            $settings,
            $userid,
            $label,
            'alert alert-danger',
            true,
            $fullwidth,
            'alert',
            'option',
            true,
            '',
            '',
            'fa-play'
        );
    }

    /**
     * Helper function to return localized description strings.
     *
     * @param bool $isavailable
     * @param bool $full
     * @param booking_option_settings $settings
     * @param int $userid
     * @return string
     */
    private function get_description_string(
        bool $isavailable,
        bool $full,
        booking_option_settings $settings,
        int $userid = 0
    ): string {

        $description = "";
        if (
            !$isavailable
            && $this->overwrittenbybillboard
            && !empty($desc = bo_info::apply_billboard($this, $settings))
        ) {
            return $desc;
        }

        if (!$isavailable) {
            $description = $this->get_string_with_url();
        }
        return $description;
    }

    /**
     * Return a rendered string with links to bookingoption.
     *
     *
     * @return [type]
     *
     */
    private function get_string_with_url() {
        global $CFG, $USER;

        $optionid = 0;
        $string = "";
        foreach ($this->otheranswers as $answer) {
            if (empty($optionid = $answer->optionid)) {
                continue;
            }

            $booking = singleton_service::get_instance_of_booking_by_optionid($optionid);
            $bookingoption = singleton_service::get_instance_of_booking_option_settings($optionid);

            $title = $bookingoption->get_title_with_prefix();
            $url = new moodle_url($CFG->wwwroot . '/mod/booking/optionview.php', [
                'cmid' => $booking->cmid,
                'optionid' => $optionid,
            ]);
            $url = $url->out(false);
            $string .= '<div><a href="' . $url . '" >"' . $title . '" </a></div>';
        }

        $savedsettings = booking::get_value_of_json_by_key($booking->id, 'maxoptionsfromcategory') ?? '';
        if (
            !empty($savedsettings)
            && !empty($savedsettingsdata = (array)json_decode($savedsettings))
        ) {
            $field = get_config('booking', 'maxoptionsfromcategoryfield') ?? '';
            // Fix for drop-down menu customfields.
            if (is_array($bookingoption->customfields[$field])) {
                $typestr = reset($bookingoption->customfields[$field]);
            } else {
                $typestr = $bookingoption->customfields[$field];
            }
            $a = (object) [
                'maxoptions' => $string,
                'type' => $typestr,
                'category' => $field,
                'max' => reset($savedsettingsdata)->count, // Since count is the same for all, we can just take the first one.
            ];
            $string = get_string('maxoptionsstringdetailed', 'mod_booking', $a);
        } else {
            $string = get_string('maxoptionsstring', 'mod_booking');
        }

        return $string;
    }

    /**
     * Set data function to add the right values to the form.
     * @param stdClass $defaultvalues
     * @param int $optiondateid
     * @param int $idx
     * @return void
     */
    public static function set_data(stdClass &$defaultvalues, int $optiondateid, int $idx) {

        $values = &$defaultvalues;
    }

    /**
     * Returns a condition object which is needed to create the condition JSON.
     *
     * @param stdClass $fromform
     * @return stdClass|null the object for the JSON
     */
    public function get_condition_object_for_json(stdClass $fromform): stdClass {

        $conditionobject = new stdClass();

        if (!empty($fromform->bo_cond_maxoptionsfromcategory_restrict)) {

            $classname = __CLASS__;
            $classnameparts = explode('\\', $classname);
            $shortclassname = end($classnameparts);

            // Remove the namespace from classname.
            $conditionobject->id = $this->id;
            $conditionobject->maxoptionsfromcategory = 1;
            $conditionobject->maxoptionsfromcategoryhandling = $fromform->bo_cond_maxoptionsfromcategory_handling;
            $conditionobject->class = $classname;
            $conditionobject->name = $shortclassname;
        }
        // Might be an empty object.
        return $conditionobject;
    }

    /**
     * Set the defaults.
     *
     * @param stdClass $defaultvalues
     * @param stdClass $acdefault
     *
     *
     */
    public function set_defaults(stdClass &$defaultvalues, stdClass $acdefault) {
        if (!empty($acdefault->maxoptionsfromcategory)) {
            $defaultvalues->bo_cond_maxoptionsfromcategory_restrict = "1";
            $defaultvalues->bo_cond_maxoptionsfromcategory_handling = $acdefault->maxoptionsfromcategoryhandling ?? "";
        } else {
            $defaultvalues->bo_cond_maxoptionsfromcategory_restrict = "0";
        }
    }

    /**
     * Returns empty array if max options from category is defined and otherwise the kind of handling (block, warn).
     *
     * @param booking_option_settings $settings
     *
     * @return array
     *
     */
    private function max_options_defined(booking_option_settings $settings): array {
        if (!empty($this->handling[$settings->cmid])) {
            return $this->handling[$settings->cmid];
        }
        $booking = singleton_service::get_instance_of_booking_by_cmid($settings->cmid);
        $bsettingsjson = $booking->settings->json ?? '';
        if (empty($bsettingsjson)) {
            return [];
        }
        $data = json_decode($bsettingsjson);
        if (empty($data->maxoptionsfromcategory)) {
            return [];
        }
        $maxoptions = (array) json_decode($data->maxoptionsfromcategory) ?? [];
        if (reset($maxoptions)->count == 0) {
            return [];
        }
        $this->handling[$settings->cmid] = $maxoptions;
        return $this->handling[$settings->cmid];
    }
}
