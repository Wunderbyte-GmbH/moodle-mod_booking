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

use context_system;
use mod_booking\bo_availability\bo_condition;
use mod_booking\bo_availability\bo_info;
use mod_booking\booking_option_settings;
use mod_booking\option\time_handler;
use MoodleQuickForm;
use stdClass;

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
    public $id = MOD_BOOKING_BO_COND_BOOKING_TIME;

    /** @var bool $overridable Indicates if the condition can be overriden. */
    public $overridable = true;

    /** @var bool $overwrittenbybillboard Indicates if the condition can be overwritten by the billboard. */
    public $overwrittenbybillboard = false;

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
        return false;
        // Important: If we want to re-activate override conditions here, we need to make it JSON compatible!
    }

    /**
     * Needed to see if it shows up in mform,
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
    public function is_available(booking_option_settings $settings, int $userid, bool $not = false): bool {

        $now = time();

        // Here, the logic is easier when we set available to true first.
        $isavailable = true;

        // Get opening and closing time from option settings.
        [$openingtime, $closingtime] = $this->get_booking_opening_and_closing_time($settings);

        // If there is a bookingopeningtime and now is smaller, we return false.
        if (
            !empty($openingtime)
            && ($now < $openingtime)
        ) {
            $isavailable = false;
        }

        // If there is a bookingclosingtime and now is bigger, we return false.
        if (
            !empty($closingtime)
            && ($now > $closingtime)
        ) {
            $isavailable = false;
        }

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
        $where = "(
                    sqlfilter <> 2

                    OR (
                        (bookingopeningtime < 1 OR bookingopeningtime < :bookingopeningtimenow1)
                        AND (bookingclosingtime < 1 OR bookingclosingtime > :bookingopeningtimenow2)
                    )
                  )";

        // Using realtime here would destroy our caching.
        // Cache would be invalidated every second.
        // Therefore, the filter of bookingopeningtime goes on the timestamp of 00:00.
        // Closing on 23:59.
        $nowstart = strtotime('today 00:00');
        $nowend = strtotime('today 23:59');

        $params = [
            'bookingopeningtimenow1' => $nowstart,
            'bookingopeningtimenow2' => $nowend,
        ];

        return ['', '', '', $params, $where];
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

        $context = context_system::instance();
        if (has_capability('mod/booking:overrideboconditions', $context)) {
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

        $description = $this->get_description_string($isavailable, $full, $settings);

        return [$isavailable, $description, MOD_BOOKING_BO_PREPAGE_NONE, MOD_BOOKING_BO_BUTTON_MYALERT];
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

        $mform->addElement(
            'advcheckbox',
            'restrictanswerperiodopening',
            get_string('restrictanswerperiodopening', 'mod_booking')
        );

        $mform->addElement(
            'date_time_selector',
            'bookingopeningtime',
            get_string('from', 'mod_booking'),
            time_handler::set_timeintervall(),
        );
        $mform->setType('bookingopeningtime', PARAM_INT);
        $mform->setDefault('bookingopeningtime', time_handler::prettytime(time()));
        $mform->hideIf('bookingopeningtime', 'restrictanswerperiodopening', 'notchecked');

        $mform->addElement(
            'advcheckbox',
            'restrictanswerperiodclosing',
            get_string('restrictanswerperiodclosing', 'mod_booking')
        );

        $mform->addElement(
            'date_time_selector',
            'bookingclosingtime',
            get_string('until', 'mod_booking'),
            time_handler::set_timeintervall(),
        );
        $mform->setType('bookingclosingtime', PARAM_INT);
        $mform->setDefault('bookingclosingtime', time_handler::prettytime(time()));
        $mform->hideIf('bookingclosingtime', 'restrictanswerperiodclosing', 'notchecked');

        $mform->addElement(
            'advcheckbox',
            'bo_cond_booking_time_sqlfiltercheck',
            get_string('sqlfiltercheckstring', 'mod_booking')
        );

        // Override conditions should not be necessary here - but let's keep it if we change our mind.
        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /* $mform->addElement('checkbox', 'bo_cond_booking_time_overrideconditioncheckbox',
                get_string('overrideconditioncheckbox', 'mod_booking'));
        $mform->hideIf('bo_cond_booking_time_overrideconditioncheckbox', 'restrictanswerperiodopening', 'notchecked');
        $mform->hideIf('bo_cond_booking_time_overrideconditioncheckbox', 'restrictanswerperiodclosing', 'notchecked');

        $overrideoperators = [
            'OR' => get_string('overrideoperator:or', 'mod_booking'),
            'AND' => get_string('overrideoperator:and', 'mod_booking'),
        ];
        $mform->addElement('select', 'bo_cond_booking_time_overrideoperator',
            get_string('overrideoperator', 'mod_booking'), $overrideoperators);
        $mform->hideIf('bo_cond_booking_time_overrideoperator', 'bo_cond_booking_time_overrideconditioncheckbox',
            'notchecked');

        $overrideconditions = bo_info::get_conditions(MOD_BOOKING_CONDPARAM_CANBEOVERRIDDEN);
        $overrideconditionsarray = [];
        foreach ($overrideconditions as $overridecondition) {
            // We do not combine conditions with each other.
            if ($overridecondition->id == MOD_BOOKING_BO_COND_BOOKING_TIME) {
                continue;
            }

            // Remove the namespace from classname.
            $fullclassname = get_class($overridecondition); // With namespace.
            $classnameparts = explode('\\', $fullclassname);
            $shortclassname = end($classnameparts); // Without namespace.
            $shortclassname = str_replace("_", "", $shortclassname); // Remove underscroll.
            $overrideconditionsarray[$overridecondition->id] =
                get_string('bocond' . $shortclassname, 'mod_booking');
        }

        // Check for json conditions that might have been saved before.
        if (!empty($optionid) && $optionid > 0) {
            $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
            if (!empty($settings->availability)) {
                $jsonconditions = json_decode($settings->availability);
                if (!empty($jsonconditions)) {
                    foreach ($jsonconditions as $jsoncondition) {
                        $currentclassname = $jsoncondition->class;
                        $currentcondition = new $currentclassname();
                        // Currently conditions of the same type cannot be combined with each other.
                        if ($jsoncondition->id != MOD_BOOKING_BO_COND_BOOKING_TIME
                            && isset($currentcondition->overridable)
                            && ($currentcondition->overridable == true)) {
                            $overrideconditionsarray[$jsoncondition->id] = get_string('bocond' .
                                str_replace("_", "", $jsoncondition->name), 'mod_booking');
                        }
                    }
                }
            }
        }

        $options = array(
            'noselectionstring' => get_string('choose...', 'mod_booking'),
            'tags' => false,
            'multiple' => true,
        );
        $mform->addElement('autocomplete', 'bo_cond_booking_time_overridecondition',
            get_string('overridecondition', 'mod_booking'), $overrideconditionsarray, $options);
        $mform->hideIf('bo_cond_booking_time_overridecondition', 'bo_cond_booking_time_overrideconditioncheckbox',
            'notchecked');*/

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
        $userid = 0,
        $full = false,
        $not = false,
        bool $fullwidth = true
    ): array {

        $label = $this->get_description_string(false, $full, $settings);

        return bo_info::render_button($settings, $userid, $label, 'alert alert-warning', true, $fullwidth, 'alert', 'option');
    }

    /**
     * Helper function to return localized description strings.
     *
     * @param bool $isavailable
     * @param bool $full
     * @param booking_option_settings $settings
     * @return string
     */
    private function get_description_string(bool $isavailable, bool $full, booking_option_settings $settings) {

        if (
            !$isavailable
            && $this->overwrittenbybillboard
            && !empty($desc = bo_info::apply_billboard($this, $settings))
        ) {
            return $desc;
        }
        if ($isavailable) {
            $description = get_string('bocondbookingtimeavailable', 'mod_booking');
        } else {
            // Localized time format.
            switch (current_language()) {
                case 'de':
                    $timeformat = "d.m.Y, H:i";
                    break;
                default:
                    $timeformat = "F j, Y, g:i a";
                    break;
            }

            // Get opening and closing time from option settings.
            [$openingtime, $closingtime] = $this->get_booking_opening_and_closing_time($settings);

            $description = '';
            if (!empty($openingtime) && time() < $openingtime) {
                $openingdatestring = date($timeformat, $openingtime);
                $description .= $full ?
                    get_string('bocondbookingopeningtimefullnotavailable', 'mod_booking', $openingdatestring) :
                    get_string('bocondbookingopeningtimenotavailable', 'mod_booking', $openingdatestring);
            }
            if (!empty($closingtime) && time() > $closingtime) {
                $closingdatestring = date($timeformat, $closingtime);
                $description .= $full ?
                    get_string('bocondbookingclosingtimefullnotavailable', 'mod_booking', $closingdatestring) :
                    get_string('bocondbookingclosingtimenotavailable', 'mod_booking', $closingdatestring);
            }
            // Fallback: If description is still empty, we still want to show that it's not available.
            if (empty($description)) {
                $description = get_string('bocondbookingtimenotavailable', 'mod_booking');
            }
        }

        return $description;
    }

    /**
     * Helper function to get opening and closing time from settings.
     * @param booking_option_settings $settings
     * @return array an array containing int $bookingopeningtime and $bookingclosingtime
     */
    private function get_booking_opening_and_closing_time(booking_option_settings $settings) {

        // This condition is either hardcoded with the standard booking opening or booking closing time, or its customized.
        if ($this->id == MOD_BOOKING_BO_COND_BOOKING_TIME) {
            $openingtime = $settings->bookingopeningtime ?? null;
            $closingtime = $settings->bookingclosingtime ?? null;
        } else {
            $jsonstring = $settings->availability ?? '';

            $jsonobject = json_decode($jsonstring);

            $openingtime = $jsonobject->openingtime ?? null;
            $closingtime = $jsonobject->closingtime ?? null;
        }

        return [$openingtime, $closingtime];
    }

    /*
     * Returns a condition object which is needed to create the condition JSON.
     *
     * @param stdClass $fromform
     * @return stdClass|null the object for the JSON
     */
    // Override conditions should not be necessary here - but let's keep it if we change our mind.
    // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
    /*public function get_condition_object_for_json(stdClass $fromform): stdClass {

        $conditionobject = new stdClass();

        // Booking time is a special case, bookingopeningtime and bookingclosingtime are stored in extra DB fields not in JSON!

        if (!empty($fromform->restrictanswerperiodopening) || !empty($fromform->restrictanswerperiodclosing)) {
            // Remove the namespace from classname.
            $classname = __CLASS__;
            $classnameparts = explode('\\', $classname);
            $shortclassname = end($classnameparts); // Without namespace.

            $conditionobject->id = MOD_BOOKING_BO_COND_BOOKING_TIME;
            $conditionobject->name = $shortclassname;
            $conditionobject->class = $classname;

            // Important: We do not store bookingopeningtime and bookingclosingtime in JSON!
            // They are stored in extra DB fields.

            if (!empty($fromform->bo_cond_booking_time_overrideconditioncheckbox)) {
                $conditionobject->overrides = $fromform->bo_cond_booking_time_overridecondition;
                $conditionobject->overrideoperator = $fromform->bo_cond_booking_time_overrideoperator;
            }
        }
        // Might be an empty object.
        return $conditionobject;
    }*/

    // Override conditions should not be necessary here - but let's keep it if we change our mind.
    // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
    /*
     * Set default values to be shown in form when loaded from DB.
     * @param stdClass &$defaultvalues the default values
     * @param stdClass $acdefault the condition object from JSON
     */
    // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
    /*public function set_defaults(stdClass &$defaultvalues, stdClass $acdefault) {

        if (!empty($acdefault->overrides)) {
            $defaultvalues->bo_cond_booking_time_overrideconditioncheckbox = "1";
            $defaultvalues->bo_cond_booking_time_overridecondition = $acdefault->overrides;
            $defaultvalues->bo_cond_booking_time_overrideoperator = $acdefault->overrideoperator;
        }
    }*/

}
