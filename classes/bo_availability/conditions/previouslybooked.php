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

use context_module;
use context_system;
use mod_booking\bo_availability\bo_condition;
use mod_booking\bo_availability\freezable_condition;
use mod_booking\bo_availability\bo_info;
use mod_booking\booking_option_settings;
use mod_booking\singleton_service;
use mod_booking\utils\wb_payment;
use moodle_url;
use MoodleQuickForm;
use stdClass;

/**
 * This class takes the configuration from json in the available column of booking_options table.
 *
 * All bo condition types must extend this class.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class previouslybooked implements bo_condition, freezable_condition {
    /** @var int $id Id is set via json during construction */
    public $id = MOD_BOOKING_BO_COND_JSON_PREVIOUSLYBOOKED;

    /** @var bool $overridable Indicates if the condition can be overriden. */
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
     * Reset method to clear the singleton state.
     *
     * @return void
     *
     */
    public static function reset_instance(): void {
        self::$instance = null;
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
     * Returns the name of the condition.
     *
     * @return string
     *
     */
    public function get_name(): string {
        return get_string('bocondpreviouslybooked', 'mod_booking');
    }

    /**
     * Returns whether the condition is skippable or not.
     *
     * @return bool
     */
    public function is_skippable(): bool {
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

        $optionids = self::required_optionids($this->customsettings);

        if (empty($optionids) || !isloggedin() || isguestuser()) {
            $isavailable = true;
        } else {
            // The user's own answers across all instances are already in the per-user cache
            // (session cache + request singleton, see booking_answers::get_all_answers_for_user_cached()).
            // We never load the answers of the referenced options themselves: that would cost one
            // answers object (and query) per prerequisite option for every option rendered.
            $bookinganswer = singleton_service::get_instance_of_booking_answers($settings);
            $myanswers = $bookinganswer->get_all_answers_for_user($userid, 0, [
                MOD_BOOKING_STATUSPARAM_BOOKED,
                MOD_BOOKING_STATUSPARAM_WAITINGLIST,
                MOD_BOOKING_STATUSPARAM_RESERVED,
            ]);

            $requirecompletion = !empty($this->customsettings->requirecompletion);
            $fulfilled = [];
            foreach ($myanswers as $answer) {
                if ((int)$answer->waitinglist !== MOD_BOOKING_STATUSPARAM_BOOKED) {
                    continue;
                }
                if ($requirecompletion && (int)($answer->completed ?? 0) !== 1) {
                    continue;
                }
                $fulfilled[(int)$answer->optionid] = true;
            }

            $matches = 0;
            foreach ($optionids as $optionid) {
                if (isset($fulfilled[$optionid])) {
                    $matches++;
                }
            }

            if (self::operator($this->customsettings) === 'OR') {
                $isavailable = $matches > 0;
            } else {
                // A referenced option that does not exist (anymore) can never be booked, so AND fails.
                $isavailable = $matches === count($optionids);
            }
        }

        // If it's inversed, we inverse.
        if ($not) {
            $isavailable = !$isavailable;
        }

        return $isavailable;
    }

    /**
     * Returns the ids of the booking options this condition requires.
     *
     * Reads the current "optionids" array and, for conditions saved before several options
     * could be selected, the legacy single "optionid".
     *
     * @param ?stdClass $customsettings
     * @return int[] distinct option ids, empty when the condition has no option
     */
    public static function required_optionids(?stdClass $customsettings): array {
        if (empty($customsettings)) {
            return [];
        }
        $optionids = [];
        if (!empty($customsettings->optionids)) {
            $optionids = (array)$customsettings->optionids;
        } else if (!empty($customsettings->optionid)) {
            $optionids = (array)$customsettings->optionid;
        }
        $optionids = array_values(array_unique(array_filter(array_map('intval', $optionids))));
        return $optionids;
    }

    /**
     * Returns the operator that combines the required options: AND (all) or OR (at least one).
     *
     * @param ?stdClass $customsettings
     * @return string 'AND' or 'OR'
     */
    public static function operator(?stdClass $customsettings): string {
        if (!empty($customsettings->optionidsoperator) && $customsettings->optionidsoperator === 'OR') {
            return 'OR';
        }
        return 'AND';
    }

    /**
     * Each function can return additional sql.
     * This will be used if the conditions should not only block booking...
     * ... but actually hide the conditons alltogether.
     * @param int $userid
     * @param array $params This is the array with parameters for the sql query.
     * @return array
     */
    public function return_sql(int $userid = 0, &$params = []): array {

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

        $description = !$isavailable ? $this->get_description_string($isavailable, $full, $settings) : '';

        return [$isavailable, $description, MOD_BOOKING_BO_PREPAGE_NONE, MOD_BOOKING_BO_BUTTON_MYALERT];
    }

    /**
     * Only customizable functions need to return their necessary form elements.
     *
     * @param MoodleQuickForm $mform
     * @param int $optionid
     * @return void
     */
    /**
     * Returns the ordered list of form element names this condition adds to the option form.
     * The first element is used as the warning insertion anchor.
     *
     * @return string[]
     */
    public function get_condition_form_elements(): array {
        return [
            'bo_cond_previouslybooked_restrict',
            'bo_cond_previouslybooked_optionid',
            'bo_cond_previouslybooked_optionidsoperator',
            'bo_cond_previouslybooked_requirecompletion',
            'bo_cond_previouslybooked_overrideconditioncheckbox',
            'bo_cond_previouslybooked_overrideoperator',
            'bo_cond_previouslybooked_overridecondition',
        ];
    }

    /**
     * Add condition-specific form elements to the booking option form.
     *
     * @param MoodleQuickForm $mform Booking option form instance.
     * @param int $optionid Booking option id.
     * @return void
     */
    public function add_condition_to_mform(MoodleQuickForm &$mform, int $optionid = 0) {
        global $DB;

        // Check if PRO version is activated.
        if (wb_payment::pro_version_is_activated()) {
            $mform->addElement(
                'advcheckbox',
                'bo_cond_previouslybooked_restrict',
                get_string('bocondpreviouslybookedrestrict', 'mod_booking')
            );

            $previouslybookedoptions = [
                'tags' => false,
                'multiple' => true,
                'noselectionstring' => get_string('choose...', 'mod_booking'),
                'ajax' => 'mod_booking/form_booking_options_selector',
                'valuehtmlcallback' => function ($value) {
                    global $OUTPUT;
                    if (empty($value)) {
                        return get_string('choose...', 'mod_booking');
                    }
                    $optionsettings = singleton_service::get_instance_of_booking_option_settings((int)$value);
                    $instancesettings = singleton_service::get_instance_of_booking_settings_by_cmid($optionsettings->cmid);

                    $details = (object)[
                        'id' => $optionsettings->id,
                        'titleprefix' => $optionsettings->titleprefix,
                        'text' => $optionsettings->text,
                        'instancename' => $instancesettings->name,
                    ];
                    return $OUTPUT->render_from_template(
                        'mod_booking/form_booking_options_selector_suggestion',
                        $details
                    );
                },
            ];
            $mform->addElement(
                'autocomplete',
                'bo_cond_previouslybooked_optionid',
                get_string('bocondpreviouslybookedoptionid', 'mod_booking'),
                [],
                $previouslybookedoptions
            );
            $mform->setType('bo_cond_previouslybooked_optionid', PARAM_INT);
            $mform->hideIf('bo_cond_previouslybooked_optionid', 'bo_cond_previouslybooked_restrict', 'notchecked');

            // How the selected options combine: all of them (AND) or at least one (OR).
            $mform->addElement(
                'select',
                'bo_cond_previouslybooked_optionidsoperator',
                get_string('bocondpreviouslybookedoperator', 'mod_booking'),
                [
                    'AND' => get_string('alloptionsmustbebooked', 'mod_booking'),
                    'OR' => get_string('oneoptionmustbebooked', 'mod_booking'),
                ]
            );
            $mform->setDefault('bo_cond_previouslybooked_optionidsoperator', 'AND');
            $mform->hideIf(
                'bo_cond_previouslybooked_optionidsoperator',
                'bo_cond_previouslybooked_restrict',
                'notchecked'
            );

            // Require completion of the selected booking option before allowing booking.
            $mform->addElement(
                'advcheckbox',
                'bo_cond_previouslybooked_requirecompletion',
                get_string('bocondpreviouslybookedrequirecompletion', 'mod_booking')
            );
            $mform->hideIf(
                'bo_cond_previouslybooked_requirecompletion',
                'bo_cond_previouslybooked_restrict',
                'notchecked'
            );

            $mform->addElement(
                'checkbox',
                'bo_cond_previouslybooked_overrideconditioncheckbox',
                get_string('overrideconditioncheckbox', 'mod_booking')
            );
            $mform->hideIf('bo_cond_previouslybooked_overrideconditioncheckbox', 'bo_cond_previouslybooked_restrict', 'notchecked');

            $overrideoperators = [
                'OR' => get_string('overrideoperator:or', 'mod_booking'),
                'AND' => get_string('overrideoperator:and', 'mod_booking'),
            ];
            $mform->addElement(
                'select',
                'bo_cond_previouslybooked_overrideoperator',
                get_string('overrideoperator', 'mod_booking'),
                $overrideoperators
            );
            $mform->hideIf(
                'bo_cond_previouslybooked_overrideoperator',
                'bo_cond_previouslybooked_overrideconditioncheckbox',
                'notchecked'
            );

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
                'bo_cond_previouslybooked_overridecondition',
                get_string('overridecondition', 'mod_booking'),
                $overrideconditionsarray,
                $options
            );
            $mform->hideIf(
                'bo_cond_previouslybooked_overridecondition',
                'bo_cond_previouslybooked_overrideconditioncheckbox',
                'notchecked'
            );
        } else {
            // No PRO license is active.
            $mform->addElement(
                'static',
                'bo_cond_previouslybooked_restrict',
                get_string('bocondpreviouslybookedrestrict', 'mod_booking'),
                get_string('proversiononly', 'mod_booking')
            );
        }

        $mform->addElement(
            'html',
            '<div id="bo_cond_previouslybooked_restrict_hr" class="d-flex justify-content-end"><hr class="w-75"/></div>'
        );
    }

    /**
     * Returns a condition object which is needed to create the condition JSON.
     *
     * @param stdClass $fromform
     * @return stdClass|null the object for the JSON
     */
    public function get_condition_object_for_json(stdClass $fromform): stdClass {
        $conditionobject = new stdClass();
        if (!empty($fromform->bo_cond_previouslybooked_restrict)) {
            // Remove the namespace from classname.
            $classname = __CLASS__;
            $classnameparts = explode('\\', $classname);
            $shortclassname = end($classnameparts); // Without namespace.

            $conditionobject->id = $this->id;
            $conditionobject->name = $shortclassname;
            $conditionobject->class = $classname;
            // The form field accepts one id (legacy callers, easy availability, import) or an array.
            $optionids = self::required_optionids((object)[
                'optionids' => $fromform->bo_cond_previouslybooked_optionid ?? [],
            ]);
            $conditionobject->optionids = $optionids;
            $conditionobject->optionidsoperator =
                ($fromform->bo_cond_previouslybooked_optionidsoperator ?? 'AND') === 'OR' ? 'OR' : 'AND';
            // Readers that still expect a single option get the first one.
            $conditionobject->optionid = $optionids[0] ?? 0;

            // Persist completion requirement.
            if (!empty($fromform->bo_cond_previouslybooked_requirecompletion)) {
                $conditionobject->requirecompletion = 1;
            }

            if (!empty($fromform->bo_cond_previouslybooked_overrideconditioncheckbox)) {
                $conditionobject->overrides = $fromform->bo_cond_previouslybooked_overridecondition;
                $conditionobject->overrideoperator = $fromform->bo_cond_previouslybooked_overrideoperator;
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
        $optionids = self::required_optionids($acdefault);
        if (!empty($optionids)) {
            $defaultvalues->bo_cond_previouslybooked_restrict = "1";
            $defaultvalues->bo_cond_previouslybooked_optionid = $optionids;
            $defaultvalues->bo_cond_previouslybooked_optionidsoperator = self::operator($acdefault);
        }
        if (!empty($acdefault->requirecompletion)) {
            $defaultvalues->bo_cond_previouslybooked_requirecompletion = "1";
        }
        if (!empty($acdefault->overrides)) {
            $defaultvalues->bo_cond_previouslybooked_overrideconditioncheckbox = "1";
            $defaultvalues->bo_cond_previouslybooked_overridecondition = $acdefault->overrides;
            $defaultvalues->bo_cond_previouslybooked_overrideoperator = $acdefault->overrideoperator;
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
    public function get_description_string(bool $isavailable, bool $full, booking_option_settings $settings) {

        if (
            !$isavailable
            && $this->overwrittenbybillboard
            && !empty($desc = bo_info::apply_billboard($this, $settings))
        ) {
            return $desc;
        }

        if ($isavailable) {
            $description = $full ? get_string('bocondpreviouslybookedfullavailable', 'mod_booking') :
                get_string('bocondpreviouslybookedavailable', 'mod_booking');
        } else {
            if (!$this->customsettings) {
                // This description can only work with the right custom settings.
                $availabilityarray = json_decode($settings->availability);

                foreach ($availabilityarray as $availability) {
                    if (strpos($availability->class, 'previouslybooked') > 0) {
                        $this->customsettings = (object)$availability;
                    }
                }
            }

            $optionids = self::required_optionids($this->customsettings);
            if (empty($optionids)) {
                return get_string('bocondpreviouslybookednooption', 'mod_booking');
            }

            $links = [];
            foreach ($optionids as $optionid) {
                $links[] = self::option_link($optionid);
            }

            if (count($links) === 1) {
                $description = $full ?
                    get_string('bocondpreviouslybookedfullnotavailable', 'mod_booking', $links[0]) :
                    get_string('bocondpreviouslybookednotavailable', 'mod_booking', $links[0]);
            } else {
                $a = implode(', ', array_map(
                    fn(stdClass $link) => '<a href="' . $link->url . '">' . $link->title . '</a>',
                    $links
                ));
                if (self::operator($this->customsettings) === 'OR') {
                    $description = $full ?
                        get_string('bocondpreviouslybookedfullnotavailableany', 'mod_booking', $a) :
                        get_string('bocondpreviouslybookednotavailableany', 'mod_booking', $a);
                } else {
                    $description = $full ?
                        get_string('bocondpreviouslybookedfullnotavailableall', 'mod_booking', $a) :
                        get_string('bocondpreviouslybookednotavailableall', 'mod_booking', $a);
                }
            }
        }

        return $description;
    }

    /**
     * Url and formatted title of a referenced option, for the description strings.
     *
     * @param int $optionid
     * @return stdClass with "url" and "title"
     */
    private static function option_link(int $optionid): stdClass {
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $a = new stdClass();
        if (empty($settings->id)) {
            // The referenced option was deleted; still name it so the configuration error is visible.
            $a->url = '';
            $a->title = get_string('bocondpreviouslybookeddeletedoption', 'mod_booking', $optionid);
            return $a;
        }
        $url = new moodle_url('/mod/booking/optionview.php', [
            'optionid' => $optionid,
            'cmid' => $settings->cmid,
        ]);
        $a->url = $url->out(false);
        /* Nothing formats the button label downstream, so without format_string() a title with
        multilang tags like {mlang de}...{mlang} reaches the user as literal tags. The context is
        passed explicitly for callers without a $PAGE->context (cron, tasks, web services). */
        $a->title = format_string(
            $settings->get_title_with_prefix(),
            true,
            ['context' => context_module::instance($settings->cmid)]
        );
        return $a;
    }
}
