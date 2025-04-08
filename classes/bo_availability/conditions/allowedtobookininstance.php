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
 * Availability condition that checks if a user is enrolled in (a) certain course(s).
 *
 * @package     mod_booking
 * @copyright   2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Bernhard Fischer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_booking\bo_availability\conditions;

use context_module;
use context_system;
use mod_booking\bo_availability\bo_condition;
use mod_booking\bo_availability\bo_info;
use mod_booking\booking_option_settings;
use mod_booking\singleton_service;
use mod_booking\utils\wb_payment;
use MoodleQuickForm;
use stdClass;

/**
 * Availability condition that checks if a user is enrolled in (a) certain course(s).
 *
 * @package     mod_booking
 * @copyright   2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Bernhard Fischer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class allowedtobookininstance implements bo_condition {
    /** @var int $id set via json during construction */
    public $id = MOD_BOOKING_BO_COND_JSON_ALLOWEDTOBOOKININSTANCE;

    /** @var bool $overridable Indicates if the condition can be overridden. */
    public $overridable = true;

    /** @var bool $overwrittenbybillboard Indicates if the condition can be overwritten by the billboard. */
    public $overwrittenbybillboard = true;

    /** @var stdClass $customsettings an stdclass coming from the json which passes custom settings */
    public $customsettings = null;

    /**
     * Singleton instance.
     *
     * @var object
     */
    private static $instance = null;

    /**
     * Singleton instance.
     *
     * @param ?int $id
     * @return object
     *
     */
    public static function instance(?int $id = null): object {
        if (empty(self::$instance)) {
            self::$instance = new self($id);
        }
        return self::$instance;
    }

    /**
     * Constructor.
     *
     * @param ?int $id
     * @return void
     */
    private function __construct(?int $id = null) {
        if ($id) {
            $this->id = $id;
        }
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
     * @return bool true if available
     */
    public function is_available(booking_option_settings $settings, int $userid, bool $not = false): bool {

        // This is a special kind of condition.
        // We want to test booking:choose capability.
        // But there is a json setting which allows us to disable this check.

        // This is the return value. Not available to begin with.
        $isavailable = false;

        if (($this->customsettings->capabilitynotneeded ?? 0) == 1) {
            $isavailable = true;
        } else {
            $context = context_module::instance($settings->cmid);

            // If the user is not yet booked we return true.
            if (has_capability('mod/booking:choose', $context)) {
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

        // Check if PRO version is activated.
        if (wb_payment::pro_version_is_activated()) {
            $mform->addElement(
                'advcheckbox',
                'bo_cond_allowedtobookininstance_restrict',
                get_string('bocondallowedtobookininstance', 'mod_booking')
            );

            $mform->addElement(
                'advcheckbox',
                'bo_cond_allowedtobookininstance_capabilitynotneeded',
                get_string('bocondallowedtobookininstanceanyways', 'mod_booking')
            );
            $mform->setDefault('bo_cond_allowedtobookininstance_capabilitynotneeded', 1);
            $mform->hideIf(
                'bo_cond_allowedtobookininstance_capabilitynotneeded',
                'bo_cond_allowedtobookininstance_restrict',
                'notchecked'
            );

            $overrideoperators = [
                'OR' => get_string('overrideoperator:or', 'mod_booking'),
                'AND' => get_string('overrideoperator:and', 'mod_booking'),
            ];

            $mform->addElement(
                'checkbox',
                'bo_cond_allowedtobookininstance_overrideconditioncheckbox',
                get_string('overrideconditioncheckbox', 'mod_booking')
            );
            $mform->hideIf(
                'bo_cond_allowedtobookininstance_overrideconditioncheckbox',
                'bo_cond_allowedtobookininstance_restrict',
                'notchecked'
            );

            $mform->addElement(
                'select',
                'bo_cond_allowedtobookininstance_overrideoperator',
                get_string('overrideoperator', 'mod_booking'),
                $overrideoperators
            );
            $mform->hideIf(
                'bo_cond_allowedtobookininstance_overrideoperator',
                'bo_cond_allowedtobookininstance_overrideconditioncheckbox',
                'notchecked'
            );

            $overrideconditions = bo_info::get_conditions(MOD_BOOKING_CONDPARAM_CANBEOVERRIDDEN);
            $overrideconditionsarray = [];
            foreach ($overrideconditions as $overridecondition) {
                // We do not combine conditions of same type with each other.
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
                            $currentcondition = $currentclassname::instance();
                            // Currently conditions of the same type cannot be combined with each other.
                            if (
                                $jsoncondition->id != $this->id
                                && isset($currentcondition->overridable)
                                && ($currentcondition->overridable == true)
                            ) {
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
            $mform->addElement(
                'autocomplete',
                'bo_cond_allowedtobookininstance_overridecondition',
                get_string('overridecondition', 'mod_booking'),
                $overrideconditionsarray,
                $options
            );
            $mform->hideIf(
                'bo_cond_allowedtobookininstance_overridecondition',
                'bo_cond_allowedtobookininstance_overrideconditioncheckbox',
                'notchecked'
            );
        } else {
            // No PRO license is active.
            $mform->addElement(
                'static',
                'bo_cond_allowedtobookininstance_restrict',
                get_string('bocondallowedtobookininstance', 'mod_booking'),
                get_string('proversiononly', 'mod_booking')
            );
        }

        $mform->addElement('html', '<hr class="w-50"/>');
    }

    /**
     * Returns a condition object which is needed to create the condition JSON.
     *
     * @param stdClass $fromform
     * @return stdClass|null the object for the JSON
     */
    public function get_condition_object_for_json(stdClass $fromform): stdClass {
        $conditionobject = new stdClass();
        if (!empty($fromform->bo_cond_allowedtobookininstance_restrict)) {
            // Remove the namespace from classname.
            $classname = __CLASS__;
            $classnameparts = explode('\\', $classname);
            $shortclassname = end($classnameparts); // Without namespace.

            $conditionobject->id = $this->id;
            $conditionobject->name = $shortclassname;
            $conditionobject->class = $classname;
            $conditionobject->capabilitynotneeded = $fromform->bo_cond_allowedtobookininstance_capabilitynotneeded;

            if (!empty($fromform->bo_cond_allowedtobookininstance_overrideconditioncheckbox)) {
                $conditionobject->overrides = $fromform->bo_cond_allowedtobookininstance_overridecondition;
                $conditionobject->overrideoperator = $fromform->bo_cond_allowedtobookininstance_overrideoperator;
            }
        }
        // Might be an empty object if restriction is not set.
        return $conditionobject;
    }

    /**
     * Set default values to be shown in form when loaded from DB.
     * @param stdClass $defaultvalues the default values
     * @param stdClass $acdefault the condition object from JSON
     */
    public function set_defaults(stdClass &$defaultvalues, stdClass $acdefault) {
        if (!empty($acdefault->capabilitynotneeded)) {
            $defaultvalues->bo_cond_allowedtobookininstance_restrict = "1";
            $defaultvalues->bo_cond_allowedtobookininstance_capabilitynotneeded = $acdefault->capabilitynotneeded;
        }
        if (!empty($acdefault->overrides)) {
            $defaultvalues->bo_cond_allowedtobookininstance_overrideconditioncheckbox = "1";
            $defaultvalues->bo_cond_allowedtobookininstance_overridecondition = $acdefault->overrides;
            $defaultvalues->bo_cond_allowedtobookininstance_overrideoperator = $acdefault->overrideoperator;
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
        global $DB;

        if ($isavailable) {
            $description = $full ? get_string('bocondallowedtobookininstancefullavailable', 'mod_booking') :
                get_string('bocondallowedtobookininstanceavailable', 'mod_booking');
        } else {
            if (!$this->customsettings) {
                // This description can only work with the right custom settings.
                $availabilityarray = json_decode($settings->availability);

                foreach ($availabilityarray as $availability) {
                    if (strpos($availability->class, 'allowedtobookininstance') !== false) {
                        $this->customsettings = (object)$availability;
                    }
                }
            }

            $description = $full ?
                    get_string('bocondallowedtobookininstancefullnotavailable', 'mod_booking') :
                    get_string('bocondallowedtobookininstancenotavailable', 'mod_booking');
        }

        return $description;
    }

    /**
     * Apply customdata for this booking option from booking_option_settings.
     *
     * @param booking_option_settings $settings
     *
     * @return void
     *
     */
    public function apply_customdata(booking_option_settings $settings) {

        if (!empty($settings->availability)) {
            $availabilities = json_decode($settings->availability);
            foreach ($availabilities as $condtion) {
                if ($condtion->id === $this->id) {
                    $this->customsettings = $condtion;
                    break;
                }
            }
        }
    }
}
