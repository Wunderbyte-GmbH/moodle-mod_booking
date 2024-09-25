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
class userprofilefield_2_custom implements bo_condition {

    /** @var int $id Id is set via json during construction */
    public $id = MOD_BOOKING_BO_COND_JSON_CUSTOMUSERPROFILEFIELD;

    /** @var bool $overridable Indicates if the condition can be overriden. */
    public $overridable = true;

    /** @var stdClass $customsettings an stdclass coming from the json which passes custom settings */
    public $customsettings = null;

    /**
     * Constructor.
     *
     * @param ?int $id
     * @return void
     */
    public function __construct(?int $id = null) {

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

                $firstcheck = $this->compare_fields(
                    $user,
                    $this->customsettings->profilefield,
                    $this->customsettings->operator,
                    $this->customsettings->value
                );
                $secondcheck = $this->compare_fields(
                    $user,
                    $this->customsettings->profilefield2,
                    $this->customsettings->operator2,
                    $this->customsettings->value2
                );

                if (empty($this->customsettings->connectsecondfield)) {
                    // Availabilty depends only on first field.
                    $isavailable = $firstcheck;
                } else if ($this->customsettings->connectsecondfield === "&&") {
                    $isavailable = $firstcheck && $secondcheck;
                } else if ($this->customsettings->connectsecondfield === "||") {
                    $isavailable = $firstcheck || $secondcheck;
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
     * Actually compare the fields according to value of operator.
     *
     * @param string $operator
     * @param string $profilefieldvalue
     * @param string $formvalue
     *
     * @return bool
     *
     */
    private function compare_operation(
        string $operator,
        string $profilefieldvalue,
        string $formvalue
    ): bool {
        $isavailable = false;
        // If value is not null, we compare it.
        if ($profilefieldvalue) {
            switch ($operator) {
                case '=':
                    if ($profilefieldvalue == $formvalue) {
                        $isavailable = true;
                    }
                    break;
                case '<':
                    if ($profilefieldvalue < $formvalue) {
                        $isavailable = true;
                    }
                    break;
                case '>':
                    if ($profilefieldvalue > $formvalue) {
                        $isavailable = true;
                    }
                    break;
                case '~':
                    if (mb_strpos($profilefieldvalue, $formvalue) !== false) {
                        $isavailable = true;
                    }
                    break;
                case '!=':
                    if ($profilefieldvalue != $formvalue) {
                        $isavailable = true;
                    }
                    break;
                case '!~':
                    if (mb_strpos($profilefieldvalue, $formvalue) === false) {
                        $isavailable = true;
                    }
                    break;
                case '[]':
                    $array = explode(",", $formvalue);
                    if (in_array($profilefieldvalue, $array)) {
                        $isavailable = true;
                    }
                    break;
                case '[!]':
                    $array = explode(",", $formvalue);
                    if (!in_array($profilefieldvalue, $array)) {
                        $isavailable = true;
                    }
                    break;
                case '[~]':
                    $array = explode(",", $formvalue);
                    foreach ($array as $itemvalue) {
                        if (mb_strpos($profilefieldvalue, $itemvalue) !== false) {
                            $isavailable = true;
                            break;
                        }
                    }
                    break;
                case '[!~]':
                    $array = explode(",", $formvalue);
                    $isavailable = true;
                    foreach ($array as $itemvalue) {
                        if (mb_strpos($profilefieldvalue, $itemvalue) !== false) {
                            $isavailable = false;
                            break;
                        }
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
        return $isavailable;
    }

    /**
     * Compare fields from user and formvalues.
     *
     * @param object $user
     * @param string $profilefield
     * @param string $operator
     * @param string $formvalue
     *
     * @return bool
     *
     */
    private function compare_fields(
        object $user,
        string $profilefield,
        string $operator,
        string $formvalue
    ): bool {

        // If the profilefield is not here right away, we might need to retrieve it.
        if (!isset($user->$profilefield)) {
            $fields = profile_get_user_fields_with_data($user->id);
            $usercustomfields = new stdClass();
            foreach ($fields as $formfield) {
                $usercustomfields->{$formfield->field->shortname} = $formfield->data;
            }
            $user->profile = (array)$usercustomfields ?? [];
            $value = $user->profile[$profilefield] ?? null;
        } else {
            $value = $user->$profilefield;
        }

        return $this->compare_operation(
            $operator,
            $value,
            $formvalue
        );
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

        $description = self::get_description_string($isavailable, $full, $settings);

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

            $customuserprofilefields = $DB->get_records('user_info_field', null, '', 'id, name, shortname');
            if (!empty($customuserprofilefields)) {
                $customuserprofilefieldsarray = [];
                $customuserprofilefieldsarray[0] = get_string('userinfofieldoff', 'mod_booking');

                // Create an array of key => value pairs for the dropdown.
                foreach ($customuserprofilefields as $customuserprofilefield) {
                    $customuserprofilefieldsarray[$customuserprofilefield->shortname] =
                        format_string($customuserprofilefield->name);
                }

                $mform->addElement('advcheckbox', 'bo_cond_userprofilefield_2_custom_restrict',
                    get_string('boconduserprofilefield2customrestrict', 'mod_booking'));

                $mform->addElement('select', 'bo_cond_customuserprofilefield_field',
                    get_string('bocondcustomuserprofilefieldfield', 'mod_booking'), $customuserprofilefieldsarray);
                $mform->hideIf('bo_cond_customuserprofilefield_field', 'bo_cond_userprofilefield_2_custom_restrict', 'notchecked');

                $operators = [
                    '=' => get_string('equals', 'mod_booking'),
                    '!=' => get_string('equalsnot', 'mod_booking'),
                    '<' => get_string('lowerthan', 'mod_booking'),
                    '>' => get_string('biggerthan', 'mod_booking'),
                    '~' => get_string('contains', 'mod_booking'),
                    '!~' => get_string('containsnot', 'mod_booking'),
                    '[]' => get_string('inarray', 'mod_booking'),
                    '[!]' => get_string('notinarray', 'mod_booking'),
                    '[~]' => get_string('containsinarray', 'mod_booking'),
                    '[!~]' => get_string('containsnotinarray', 'mod_booking'),
                    '()' => get_string('isempty', 'mod_booking'),
                    '(!)' => get_string('isnotempty', 'mod_booking'),
                ];
                $mform->addElement('select', 'bo_cond_customuserprofilefield_operator',
                    get_string('bocondcustomuserprofilefieldoperator', 'mod_booking'), $operators);
                $mform->hideIf('bo_cond_customuserprofilefield_operator', 'bo_cond_customuserprofilefield_field', 'eq', 0);
                $mform->hideIf('bo_cond_customuserprofilefield_operator', 'bo_cond_userprofilefield_2_custom_restrict',
                    'notchecked');

                $mform->addElement('text', 'bo_cond_customuserprofilefield_value',
                    get_string('bocondcustomuserprofilefieldvalue', 'mod_booking'));
                $mform->setType('bo_cond_customuserprofilefield_value', PARAM_RAW);
                $mform->hideIf('bo_cond_customuserprofilefield_value', 'bo_cond_customuserprofilefield_field', 'eq', 0);
                $mform->hideIf('bo_cond_customuserprofilefield_value', 'bo_cond_userprofilefield_2_custom_restrict', 'notchecked');

                // Possiblity to add second field.
                $options = [
                    '0' => get_string('useonlyonefield', 'mod_booking'),
                    '&&' => get_string('andotherfield', 'mod_booking'),
                    '||' => get_string('orotherfield', 'mod_booking'),
                ];
                $mform->addElement('select', 'bo_cond_customuserprofilefield_connectsecondfield',
                    get_string('bocondcustomuserprofilefieldconnectsecondfield', 'mod_booking'), $options);
                $mform->hideIf('bo_cond_customuserprofilefield_connectsecondfield', 'bo_cond_customuserprofilefield_field', 'eq', 0);
                $mform->hideIf('bo_cond_customuserprofilefield_connectsecondfield', 'bo_cond_userprofilefield_2_custom_restrict',
                    'notchecked');

                // TODO validation: make sure, same field isn't selected twice.
                $mform->addElement('select', 'bo_cond_customuserprofilefield_field2',
                    get_string('bocondcustomuserprofilefieldfield2', 'mod_booking'), $customuserprofilefieldsarray);
                $mform->hideIf('bo_cond_customuserprofilefield_field2', 'bo_cond_userprofilefield_2_custom_restrict', 'notchecked');
                $mform->hideIf(
                    'bo_cond_customuserprofilefield_field2',
                    'bo_cond_customuserprofilefield_connectsecondfield',
                    'eq',
                    '0'
                );
                $mform->hideIf('bo_cond_customuserprofilefield_field2', 'bo_cond_customuserprofilefield_field', 'eq', 0);

                $mform->addElement('select', 'bo_cond_customuserprofilefield_operator2',
                    get_string('bocondcustomuserprofilefieldoperator2', 'mod_booking'), $operators);
                $mform->hideIf('bo_cond_customuserprofilefield_operator', 'bo_cond_customuserprofilefield_field', 'eq', 0);
                $mform->hideIf('bo_cond_customuserprofilefield_operator', 'bo_cond_userprofilefield_2_custom_restrict',
                    'notchecked');
                $mform->hideIf(
                    'bo_cond_customuserprofilefield_operator2',
                    'bo_cond_customuserprofilefield_connectsecondfield',
                    'eq',
                    '0'
                );

                $mform->addElement('text', 'bo_cond_customuserprofilefield_value2',
                    get_string('bocondcustomuserprofilefieldvalue2', 'mod_booking'));
                $mform->setType('bo_cond_customuserprofilefield_value2', PARAM_RAW);
                $mform->hideIf('bo_cond_customuserprofilefield_value2', 'bo_cond_customuserprofilefield_field', 'eq', 0);
                $mform->hideIf('bo_cond_customuserprofilefield_value2', 'bo_cond_userprofilefield_2_custom_restrict', 'notchecked');
                $mform->hideIf(
                    'bo_cond_customuserprofilefield_value2',
                    'bo_cond_customuserprofilefield_connectsecondfield',
                    'eq',
                    '0'
                );

                $mform->addElement('checkbox', 'bo_cond_customuserprofilefield_overrideconditioncheckbox',
                    get_string('overrideconditioncheckbox', 'mod_booking'));
                $mform->hideIf('bo_cond_customuserprofilefield_overrideconditioncheckbox', 'bo_cond_customuserprofilefield_field',
                    'eq', 0);
                $mform->hideIf('bo_cond_customuserprofilefield_overrideconditioncheckbox',
                    'bo_cond_userprofilefield_2_custom_restrict', 'notchecked');

                $overrideoperators = [
                    'OR' => get_string('overrideoperator:or', 'mod_booking'),
                    'AND' => get_string('overrideoperator:and', 'mod_booking'),
                ];
                $mform->addElement('select', 'bo_cond_customuserprofilefield_overrideoperator',
                    get_string('overrideoperator', 'mod_booking'), $overrideoperators);
                $mform->hideIf('bo_cond_customuserprofilefield_overrideoperator',
                    'bo_cond_customuserprofilefield_overrideconditioncheckbox', 'notchecked');

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
                                if ($jsoncondition->id != $this->id
                                    && isset($currentcondition->overridable)
                                    && ($currentcondition->overridable == true)) {
                                    $overrideconditionsarray[$jsoncondition->id] = get_string('bocond' .
                                        str_replace("_", "", $jsoncondition->name), 'mod_booking');
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
                $mform->addElement('autocomplete', 'bo_cond_customuserprofilefield_overridecondition',
                    get_string('overridecondition', 'mod_booking'), $overrideconditionsarray, $options);
                $mform->hideIf('bo_cond_customuserprofilefield_overridecondition',
                    'bo_cond_customuserprofilefield_overrideconditioncheckbox', 'notchecked');
            }
        } else {
            // No PRO license is active.
            $mform->addElement('static', 'bo_cond_userprofilefield_2_custom_restrict',
                get_string('boconduserprofilefield2customrestrict', 'mod_booking'),
                get_string('proversiononly', 'mod_booking'));
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

        $conditionobject = new stdClass();

        if (!empty($fromform->bo_cond_userprofilefield_2_custom_restrict)) {
            // Remove the namespace from classname.
            $classname = __CLASS__;
            $classnameparts = explode('\\', $classname);
            $shortclassname = end($classnameparts); // Without namespace.

            $conditionobject->id = $this->id;
            $conditionobject->name = $shortclassname;
            $conditionobject->class = $classname;
            $conditionobject->profilefield = $fromform->bo_cond_customuserprofilefield_field;
            $conditionobject->operator = $fromform->bo_cond_customuserprofilefield_operator;
            $conditionobject->value = $fromform->bo_cond_customuserprofilefield_value;
            $conditionobject->connectsecondfield = $fromform->bo_cond_customuserprofilefield_connectsecondfield;
            $conditionobject->profilefield2 = $fromform->bo_cond_customuserprofilefield_field2;
            $conditionobject->operator2 = $fromform->bo_cond_customuserprofilefield_operator2;
            $conditionobject->value2 = $fromform->bo_cond_customuserprofilefield_value2;

            if (!empty($fromform->bo_cond_customuserprofilefield_overrideconditioncheckbox)) {
                $conditionobject->overrides = $fromform->bo_cond_customuserprofilefield_overridecondition;
                $conditionobject->overrideoperator = $fromform->bo_cond_customuserprofilefield_overrideoperator;
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
            $defaultvalues->bo_cond_userprofilefield_2_custom_restrict = "1";
            $defaultvalues->bo_cond_customuserprofilefield_field = $acdefault->profilefield;
            $defaultvalues->bo_cond_customuserprofilefield_operator = $acdefault->operator;
            $defaultvalues->bo_cond_customuserprofilefield_value = $acdefault->value;
            $defaultvalues->bo_cond_customuserprofilefield_connectsecondfield = $acdefault->connectsecondfield;
            $defaultvalues->bo_cond_customuserprofilefield_field2 = $acdefault->profilefield2;
            $defaultvalues->bo_cond_customuserprofilefield_operator2 = $acdefault->operator2;
            $defaultvalues->bo_cond_customuserprofilefield_value2 = $acdefault->value2;
        }
        if (!empty($acdefault->overrides)) {
            $defaultvalues->bo_cond_customuserprofilefield_overrideconditioncheckbox = "1";
            $defaultvalues->bo_cond_customuserprofilefield_overridecondition = $acdefault->overrides;
            $defaultvalues->bo_cond_customuserprofilefield_overrideoperator = $acdefault->overrideoperator;
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
    private function get_description_string(bool $isavailable, bool $full, booking_option_settings $settings) {
        if ($isavailable) {
            $description = $full ? get_string('bocondcustomuserprofilefieldfullavailable', 'mod_booking') :
                get_string('bocondcustomuserprofilefieldavailable', 'mod_booking');
        } else {

            // We need to make sure we have the custom settings ready.
            if (!$this->customsettings) {
                // This description can only works with the right custom settings.
                $availabilityarray = json_decode($settings->availability);

                foreach ($availabilityarray as $availability) {
                    if (strpos($availability->class, 'userprofilefield_2_custom') > 0) {

                        $this->customsettings = (object)$availability;
                    }
                }
            }
            $description = $full ? get_string('bocondcustomuserprofilefieldfullnotavailable',
                'mod_booking',
                $this->customsettings) :
                get_string('bocondcustomuserprofilefieldnotavailable', 'mod_booking');
        }
        return $description;
    }
}
