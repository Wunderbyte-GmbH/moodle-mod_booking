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
 * @copyright 2022 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\bo_availability\conditions;

use mod_booking\bo_availability\bo_condition;
use mod_booking\bo_availability\bo_info;
use mod_booking\booking_option_settings;
use mod_booking\singleton_service;
use moodle_url;
use MoodleQuickForm;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * This class extends MOD_BOOKING_BO_COND_JSON_NOOVERLAPPING (nooverlapping.php)
 * to extend check to remotely affected options.
 * Even if overlapping isn't forbidden for the current bookingoption,
 * it may be overlapping with other booked options where it is forbidden.
 * Therefore we have to check the availability for each bookingoption, even if condtion isn't set in availabilty field.
 *
 * All bo condition types must extend this class.
 *
 *
 * @package mod_booking
 * @copyright 2024 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class nooverlappingproxy implements bo_condition {
    /** @var int $id Standard Conditions have hardcoded ids. */
    public $id = MOD_BOOKING_BO_COND_JSON_NOOVERLAPPINGPROXY;

    /** @var bool $overwrittenbybillboard Indicates if the condition can be overwritten by the billboard. */
    public $overwrittenbybillboard = false;

    /**
     * Handling for overlapping options/sessions.
     *
     * @var array
     */
    private array $handling = [];

    /**
     * Storing overlapping options.
     *
     * @var array
     */
    private array $overlappinganswers = [];

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
     *
     * @param booking_option_settings $settings Item we're checking
     * @param int $userid User ID to check availability for
     * @param bool $not Set true if we are inverting the condition
     *
     * @return bool True if available
     */
    public function is_available(booking_option_settings $settings, int $userid, bool $not = false): bool {

        // This is the return value. Not available to begin with.
        $isavailable = false;

        if (!empty($this->return_handling_from_settings($settings))) {
            $isavailable = true;
        } else {
            $forbidden = false;
            // Get the booking answers for this instance.
            $bookinganswer = singleton_service::get_instance_of_booking_answers($settings);

            $bookinginformation = $bookinganswer->return_all_booking_information($userid);

            // If the user is not yet booked we return true.
            if (
                (
                    isset($bookinginformation['iambooked'])
                    || isset($bookinginformation['onwaitinglist'])
                )
                || empty($this->overlappinganswers = $bookinganswer->is_overlapping($userid, $forbidden))
            ) {
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
     * Each function can return additional sql.
     * This will be used if the conditions should not only block booking...
     * ... but actually hide the conditons alltogether.
     * @param int $userid
     * @return array
     */
    public function return_sql(int $userid = 0): array {

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
        $handling = $this->return_handling_from_answers($settings->id);
        if ($handling != MOD_BOOKING_COND_OVERLAPPING_HANDLING_BLOCK) {
            return false;
        }
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

        // Fetch the handling correctly if overlapping is done because of settings in answer.
        $handling = $this->return_handling_from_answers($settings->id);

        $buttonclass = $handling == MOD_BOOKING_COND_OVERLAPPING_HANDLING_BLOCK
            ? MOD_BOOKING_BO_BUTTON_JUSTMYALERT : MOD_BOOKING_BO_BUTTON_CANCEL;

        return [$isavailable, $description, MOD_BOOKING_BO_PREPAGE_NONE, $buttonclass];
    }

    /**
     * Only customizable functions need to return their necessary form elements.
     *
     * @param MoodleQuickForm $mform
     * @param int $optionid
     * @return void
     */
    public function add_condition_to_mform(MoodleQuickForm &$mform, int $optionid = 0) {
        $mform->addElement(
            'advcheckbox',
            'bo_cond_nooverlapping_restrict',
            get_string('nooverlappingsettingcheckbox', 'mod_booking'),
        );
        $options = [
            MOD_BOOKING_COND_OVERLAPPING_HANDLING_BLOCK => get_string('nooverlappingselectblocking', 'mod_booking'),
            MOD_BOOKING_COND_OVERLAPPING_HANDLING_WARN => get_string('nooverlappingselectwarning', 'mod_booking'),
        ];
        $mform->addElement(
            'select',
            'bo_cond_nooverlapping_handling',
            get_string('nooverlappingselectinfo', 'mod_booking'),
            $options
        );
        $mform->hideIf('bo_cond_nooverlapping_handling', 'bo_cond_nooverlapping_restrict', 'eq', 0);
        $mform->addElement('html', '<hr class="w-50"/>');
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
        $handling = $this->return_handling_from_answers($settings->id);
        switch ($handling) {
            case MOD_BOOKING_COND_OVERLAPPING_HANDLING_BLOCK:
                $buttonclass = 'alert alert-danger';
                break;
            default:
                $buttonclass = 'alert alert-warning';
                break;
        }
        return bo_info::render_button(
            $settings,
            $userid,
            $label,
            $buttonclass,
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
            // Check in the overlapping answers if any of them are blocking, or only warning.

            $handling = $this->return_handling_from_answers($settings->id);

            switch ($handling) {
                case MOD_BOOKING_COND_OVERLAPPING_HANDLING_BLOCK:
                    $description = $this->get_string_with_url('nooverlapblocking', $settings, $userid);
                    break;
                case MOD_BOOKING_COND_OVERLAPPING_HANDLING_WARN:
                    $description = $this->get_string_with_url('nooverlapwarning', $settings, $userid);
                    break;
            }
        }
        return $description;
    }

    /**
     * Return a rendered string with links to bookingoption.
     *
     * @param string $identifier
     * @param object $settings
     * @param int $userid
     *
     * @return [type]
     *
     */
    private function get_string_with_url(string $identifier, object $settings, int $userid = 0) {
        global $CFG, $USER;

        $optionid = 0;
        $string = "";
        foreach ($this->overlappinganswers as $answer) {
            if (empty($optionid = $answer->optionid)) {
                continue;
            }

            $booking = singleton_service::get_instance_of_booking_by_optionid($optionid);
            $bookinoption = singleton_service::get_instance_of_booking_option_settings($optionid);
            if (empty($userid)) {
                $userid = $USER->id;
            }

            $title = $bookinoption->get_title_with_prefix();
            $url = new moodle_url($CFG->wwwroot . '/mod/booking/optionview.php', [
                'cmid' => $booking->cmid,
                'optionid' => $optionid,
            ]);
            $url = $url->out(false);
            $string .= '<div><a href="' . $url . '" >"' . $title . '" </a></div>';
        }
        return get_string($identifier, 'mod_booking', $string);
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

        if (!empty($fromform->bo_cond_nooverlapping_restrict)) {

            $classname = __CLASS__;
            $classnameparts = explode('\\', $classname);
            $shortclassname = end($classnameparts);

            // Remove the namespace from classname.
            $conditionobject->id = $this->id;
            $conditionobject->nooverlapping = 1;
            $conditionobject->nooverlappinghandling = $fromform->bo_cond_nooverlapping_handling;
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
        if (!empty($acdefault->nooverlapping)) {
            $defaultvalues->bo_cond_nooverlapping_restrict = "1";
            $defaultvalues->bo_cond_nooverlapping_handling = $acdefault->nooverlappinghandling ?? "";
        } else {
            $defaultvalues->bo_cond_nooverlapping_restrict = "0";
        }
    }

    /**
     * Returns empty string if no overlapping is defined and otherwise the kind of handling (block, warn).
     *
     * @param int $optionid
     *
     * @return int
     *
     */
    private function return_handling_from_answers(int $optionid): int {
        if (!empty($this->handling[$optionid])) {
            return $this->handling[$optionid];
        }
        if (empty($this->overlappinganswers)) {
            return MOD_BOOKING_COND_OVERLAPPING_HANDLING_EMPTY;
        }
        $handling = MOD_BOOKING_COND_OVERLAPPING_HANDLING_WARN;

        foreach ($this->overlappinganswers as $answer) {
            if ($answer->nooverlappinghandling > $handling) {
                // BLOCKING will be higher than warning - use highest here.
                $handling = $answer->nooverlappinghandling;
            }
        }
        $this->handling[$optionid] = $handling;
        return $this->handling[$optionid];
    }

    /**
     * Returns empty string if no overlapping is defined and otherwise the kind of handling (block, warn).
     *
     * @param booking_option_settings $settings
     *
     * @return int
     *
     */
    private function return_handling_from_settings(booking_option_settings $settings): int {

        if (!isset($settings->availability)) {
            return MOD_BOOKING_COND_OVERLAPPING_HANDLING_EMPTY;
        }
        $availability = json_decode($settings->availability);
        if (empty($availability[0]->nooverlapping)) {
            return MOD_BOOKING_COND_OVERLAPPING_HANDLING_EMPTY;
        }
        $optionid = $settings->id;
        $this->handling[$optionid] = $availability[0]->nooverlappinghandling ?? MOD_BOOKING_COND_OVERLAPPING_HANDLING_EMPTY;
        return $this->handling[$optionid];
    }
}
