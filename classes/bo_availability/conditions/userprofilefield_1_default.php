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
use mod_booking\singleton_service;
use mod_booking\utils\wb_payment;
use MoodleQuickForm;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/user/profile/lib.php');

/**
 * This class takes the configuration from json in the available column of booking_options table.
 *
 * All bo condition types must extend this class.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class userprofilefield_1_default implements bo_condition {

    /** @var int $id Id is set via json during construction but we still need a default ID */
    public $id = MOD_BOOKING_BO_COND_JSON_USERPROFILEFIELD;

    /** @var bool $overridable Indicates if the condition can be overriden. */
    public $overridable = true;

    /** @var stdClass $customsettings an stdclass coming from the json which passes custom settings */
    public $customsettings = null;

    /**
     * Constructor.
     *
     * @param int $id
     * @return void
     */
    public function __construct(int $id = null) {

        if ($id) {
            $this->id = $id;
        }
    }

    /**
     * Needed to see if class can take JSON.
     * @return bool
     */
    public function is_json_compatible(): bool {
        return true; // Customizable condition.
    }

    /**
     * Needed to see if it shows up in mform.
     * @return bool
     */
    public function is_shown_in_mform(): bool {
        return true;
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

        // This is the return value. Not available to begin with.
        $isavailable = false;

        if (!isset($this->customsettings->profilefield)) {
            $isavailable = true;
        } else {

            if (isloggedin()) {
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
                            if (mb_strpos($value, $this->customsettings->value) !== false) {
                                $isavailable = true;
                            }
                            break;
                        case '!=':
                            if ($value != $this->customsettings->value) {
                                $isavailable = true;
                            }
                            break;
                        case '!~':
                            if (mb_strpos($value, $this->customsettings->value) === false) {
                                $isavailable = true;
                            }
                            break;
                        case '[]':
                            $array = explode(",", $this->customsettings->value);
                            if (in_array($value, $array)) {
                                $isavailable = true;
                            }
                            break;
                        case '[!]':
                            $array = explode(",", $this->customsettings->value);
                            if (!in_array($value, $array)) {
                                $isavailable = true;
                            }
                            break;
                        case '()':
                            if (empty($value)) {
                                $isavailable = true;
                            }
                            break;
                        case '(!)':
                            if (!empty($value)) {
                                $isavailable = true;
                            }
                            break;
                    }
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
    public function hard_block(booking_option_settings $settings, $userid):bool {

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
    public function get_description(booking_option_settings $settings, $userid = null, $full = false, $not = false):array {

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

        // Check if PRO version is activated.
        if (wb_payment::pro_version_is_activated()) {

            // Choose the user profile field which is used to store each user's price category.
            $userprofilefields = $DB->get_columns('user', true);
            if (!empty($userprofilefields)) {
                $userprofilefieldsarray = [];
                $userprofilefieldsarray[0] = get_string('userinfofieldoff', 'mod_booking');

                $stringmanager = get_string_manager();

                // Create an array of key => value pairs for the dropdown.
                foreach ($userprofilefields as $key => $value) {

                    if ($stringmanager->string_exists($key, 'core')) {
                        $userprofilefieldsarray[$key] = get_string($key);
                    } else {
                        $userprofilefieldsarray[$key] = $key;
                    }
                }

                $mform->addElement('advcheckbox', 'bo_cond_userprofilefield_1_default_restrict',
                    get_string('bo_cond_userprofilefield_1_default_restrict', 'mod_booking'));

                $mform->addElement('select', 'bo_cond_userprofilefield_field',
                    get_string('bo_cond_userprofilefield_field', 'mod_booking'), $userprofilefieldsarray);
                $mform->hideIf('bo_cond_userprofilefield_field', 'bo_cond_userprofilefield_1_default_restrict', 'notchecked');

                $operators = [
                    '=' => get_string('equals', 'mod_booking'),
                    '!=' => get_string('equalsnot', 'mod_booking'),
                    '<' => get_string('lowerthan', 'mod_booking'),
                    '>' => get_string('biggerthan', 'mod_booking'),
                    '~' => get_string('contains', 'mod_booking'),
                    '!~' => get_string('containsnot', 'mod_booking'),
                    '[]' => get_string('inarray', 'mod_booking'),
                    '[!]' => get_string('notinarray', 'mod_booking'),
                    '()' => get_string('isempty', 'mod_booking'),
                    '(!)' => get_string('isnotempty', 'mod_booking'),
                ];
                $mform->addElement('select', 'bo_cond_userprofilefield_operator',
                    get_string('bo_cond_userprofilefield_operator', 'mod_booking'), $operators);
                $mform->hideIf('bo_cond_userprofilefield_operator', 'bo_cond_userprofilefield_field', 'eq', 0);
                $mform->hideIf('bo_cond_userprofilefield_operator', 'bo_cond_userprofilefield_1_default_restrict', 'notchecked');

                $mform->addElement('text', 'bo_cond_userprofilefield_value',
                    get_string('bo_cond_userprofilefield_value', 'mod_booking'));
                $mform->setType('bo_cond_userprofilefield_value', PARAM_RAW);
                $mform->hideIf('bo_cond_userprofilefield_value', 'bo_cond_userprofilefield_field', 'eq', 0);
                $mform->hideIf('bo_cond_userprofilefield_value', 'bo_cond_userprofilefield_1_default_restrict', 'notchecked');

                $mform->addElement('checkbox', 'bo_cond_userprofilefield_overrideconditioncheckbox',
                    get_string('overrideconditioncheckbox', 'mod_booking'));
                $mform->hideIf('bo_cond_userprofilefield_overrideconditioncheckbox', 'bo_cond_userprofilefield_field', 'eq', 0);
                $mform->hideIf('bo_cond_userprofilefield_overrideconditioncheckbox', 'bo_cond_userprofilefield_1_default_restrict',
                    'notchecked');

                $overrideoperators = [
                    'OR' => get_string('overrideoperator:or', 'mod_booking'),
                    'AND' => get_string('overrideoperator:and', 'mod_booking'),
                ];
                $mform->addElement('select', 'bo_cond_userprofilefield_overrideoperator',
                    get_string('overrideoperator', 'mod_booking'), $overrideoperators);
                $mform->hideIf('bo_cond_userprofilefield_overrideoperator', 'bo_cond_userprofilefield_overrideconditioncheckbox',
                    'notchecked');

                $overrideconditions = bo_info::get_conditions(MOD_BOOKING_CONDPARAM_CANBEOVERRIDDEN);
                $overrideconditionsarray = [];
                foreach ($overrideconditions as $overridecondition) {
                    // We do not combine conditions with each other.
                    if ($overridecondition->id == $this->id) {
                        continue;
                    }

                    // Remove the namespace from classname.
                    $fullclassname = get_class($overridecondition); // With namespace.
                    $classnameparts = explode('\\', $fullclassname);
                    $shortclassname = end($classnameparts); // Without namespace.
                    $overrideconditionsarray[$overridecondition->id] =
                        get_string('bo_cond_' . $shortclassname, 'mod_booking');
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
                                if ($jsoncondition->id != $this->id
                                    && isset($currentcondition->overridable)
                                    && ($currentcondition->overridable == true)) {
                                    $overrideconditionsarray[$jsoncondition->id] = get_string('bo_cond_' .
                                        $jsoncondition->name, 'mod_booking');
                                }
                            }
                        }
                    }
                }

                $options = [
                    'noselectionstring' => get_string('choose...', 'mod_booking'),
                    'tags' => false,
                    'multiple' => true,
                ];
                $mform->addElement('autocomplete', 'bo_cond_userprofilefield_overridecondition',
                    get_string('overridecondition', 'mod_booking'), $overrideconditionsarray, $options);
                $mform->hideIf('bo_cond_userprofilefield_overridecondition', 'bo_cond_userprofilefield_overrideconditioncheckbox',
                    'notchecked');
            }
        } else {
            // No PRO license is active.
            $mform->addElement('static', 'bo_cond_userprofilefield_1_default_restrict',
                get_string('bo_cond_userprofilefield_1_default_restrict', 'mod_booking'),
                get_string('proversiononly', 'mod_booking'));
        }

        // Workaround: Only show, if it is not turned off in the option form config.
        // We currently need this, because html elements do not show up in the option form config.
        // In expert mode, we always show everything.
        $showhorizontalline = true;
        $formmode = get_user_preferences('optionform_mode');
        if ($formmode !== 'expert') {
            $cfgrestrictwithuserprofilefield = $DB->get_field('booking_optionformconfig', 'active',
                ['elementname' => 'bo_cond_userprofilefield_1_default_restrict']);
            if ($cfgrestrictwithuserprofilefield === "0") {
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
     * @param int $optionid
     * @param int $userid optional user id
     * @return array
     */
    public function render_page(int $optionid, int $userid = 0) {
        return [];
    }

    /**
     * Returns a condition object which is needed to create the condition JSON.
     *
     * @param stdClass $fromform
     * @return stdClass|null the object for the JSON
     */
    public function get_condition_object_for_json(stdClass $fromform): stdClass {

        $conditionobject = new stdClass;

        if (!empty($fromform->bo_cond_userprofilefield_1_default_restrict)) {
            // Remove the namespace from classname.
            $classname = __CLASS__;
            $classnameparts = explode('\\', $classname);
            $shortclassname = end($classnameparts); // Without namespace.

            $conditionobject->id = $this->id;
            $conditionobject->name = $shortclassname;
            $conditionobject->class = $classname;
            $conditionobject->profilefield = $fromform->bo_cond_userprofilefield_field;
            $conditionobject->operator = $fromform->bo_cond_userprofilefield_operator;
            $conditionobject->value = $fromform->bo_cond_userprofilefield_value;

            if (!empty($fromform->bo_cond_userprofilefield_overrideconditioncheckbox)) {
                $conditionobject->overrides = $fromform->bo_cond_userprofilefield_overridecondition;
                $conditionobject->overrideoperator = $fromform->bo_cond_userprofilefield_overrideoperator;
            }
        }
        // Might be an empty object.
        return $conditionobject;
    }

    /**
     * Set default values to be shown in form when loaded from DB.
     * @param stdClass $defaultvalues the default values
     * @param stdClass $acdefault the condition object from JSON
     */
    public function set_defaults(stdClass &$defaultvalues, stdClass $acdefault) {
        if (!empty($acdefault->profilefield)) {
            $defaultvalues->bo_cond_userprofilefield_1_default_restrict = "1";
            $defaultvalues->bo_cond_userprofilefield_field = $acdefault->profilefield;
            $defaultvalues->bo_cond_userprofilefield_operator = $acdefault->operator;
            $defaultvalues->bo_cond_userprofilefield_value = $acdefault->value;
        }
        if (!empty($acdefault->overrides)) {
            $defaultvalues->bo_cond_userprofilefield_overrideconditioncheckbox = "1";
            $defaultvalues->bo_cond_userprofilefield_overridecondition = $acdefault->overrides;
            $defaultvalues->bo_cond_userprofilefield_overrideoperator = $acdefault->overrideoperator;
        }
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
        $userid = 0, $full = false, $not = false, bool $fullwidth = true): array {

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
    private function get_description_string($isavailable, $full, $settings) {
        if ($isavailable) {
            $description = $full ? get_string('bo_cond_userprofilefield_full_available', 'mod_booking') :
                get_string('bo_cond_userprofilefield_available', 'mod_booking');
        } else {

            if (!$this->customsettings) {
                // This description can only works with the right custom settings.
                $availabilityarray = json_decode($settings->availability);

                foreach ($availabilityarray as $availability) {
                    if (strpos($availability->class, 'userprofilefield_1_default') > 0) {

                        $this->customsettings = (object)$availability;
                    }
                }
            }

            $description = $full ? get_string('bo_cond_userprofilefield_full_not_available',
                'mod_booking',
                $this->customsettings) :
                get_string('bo_cond_userprofilefield_not_available', 'mod_booking');
        }
        return $description;
    }
}
