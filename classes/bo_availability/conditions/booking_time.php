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
use mod_booking\singleton_service;
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
class booking_time implements bo_condition, freezable_condition {
    /** @var int $id Standard Conditions have hardcoded ids. */
    public $id = MOD_BOOKING_BO_COND_BOOKING_TIME;

    /** @var stdClass|null $customsettings JSON-backed settings when condition is instantiated from availability JSON. */
    public $customsettings = null;

    /** @var bool $overridable Indicates if the condition can be overriden. */
    public $overridable = true;

    /** @var bool $overwrittenbybillboard Indicates if the condition can be overwritten by the billboard. */
    public $overwrittenbybillboard = false;

    /** @var array|null Singleton instances. */
    private static $instances = null;

    /**
     * Singleton instance.
     *
     * @param ?int $id
     * @return object
     *
     */
    public static function instance(?int $id = null): object {
        if (!isset(self::$instances[$id])) {
            self::$instances[$id] = new self();
        }
        return self::$instances[$id];
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
        return false;
    }

    /**
     * Needed to see if it shows up in mform,
     * @return bool
     */
    public function is_shown_in_mform(): bool {
        return true; // Hardcoded condition, but still shown in mform.
    }

    /**
     * Returns the name of the condition.
     *
     * @return string
     *
     */
    public function get_name(): string {
        return get_string(identifier: 'bocondbookingtime', component: 'mod_booking');
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
     * @param array $params This is the array with parameters for the sql query.
     * @return array
     */
    public function return_sql(int $userid = 0, &$params = []): array {
        if (!empty(get_config('booking', 'sqlfilterbookingtimeonlypast'))) {
            $where = "(
                        sqlfilter <> 2

                        OR (
                            bookingclosingtime < 1 OR bookingclosingtime > :bookingopeningtimenow2
                        )
                      )";

            // Using realtime here would destroy our caching.
            // Cache would be invalidated every second.
            // Therefore, closing goes on timestamp of 23:59.
            $nowend = strtotime('today 23:59');

            $params['bookingopeningtimenow2'] = $nowend;
        } else {
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

            $params['bookingopeningtimenow1'] = $nowstart;
            $params['bookingopeningtimenow2'] = $nowend;
        }

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
     * Relative-mode elements are included; condition_visibility_manager silently skips
     * any element that does not exist in the form (e.g. when relative mode is disabled).
     *
     * @return string[]
     */
    public function get_condition_form_elements(): array {
        return [
            'restrictanswerperiodopening',
            'booking_time_opening_mode',
            'bookingopeningtime',
            'booking_time_opening_relative_duration',
            'booking_time_opening_relative_beforeafter',
            'booking_time_opening_relative_datefield',
            'restrictanswerperiodclosing',
            'booking_time_closing_mode',
            'bookingclosingtime',
            'booking_time_closing_relative_duration',
            'booking_time_closing_relative_beforeafter',
            'booking_time_closing_relative_datefield',
            'bo_cond_booking_time_sqlfiltercheck',
        ];
    }

    public function add_condition_to_mform(MoodleQuickForm &$mform, int $optionid = 0) {
        global $DB;

        $relativemodeenabled = self::is_relative_mode_enabled();

        // Get existing condition data if available.
        $openingmode = 0;
        $closingmode = 0;
        $conditionobject = null;
        // For new options, the checkboxes start unchecked regardless of relative mode.
        $checkboxopening = 0;
        $checkboxclosing = 0;

        if (empty($optionid)) {
            if (self::is_relative_mode_enabled()) {
                // Pre-select relative in the mode dropdowns, but leave the checkboxes unchecked.
                $openingmode = 2;
                $closingmode = 2;
                // Only pre-check the checkboxes when the auto-apply setting is on.
                if (!empty(get_config('booking', 'bookingopeningtimerelativeautoapply'))) {
                    $checkboxopening = 1;
                }
                if (!empty(get_config('booking', 'bookingclosingtimerelativeautoapply'))) {
                    $checkboxclosing = 1;
                }
            }
        } else if (!empty($optionid) && $optionid > 0) {
            $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
            if (!empty($settings->availability)) {
                $jsonconditions = json_decode($settings->availability);
                if (!empty($jsonconditions)) {
                    foreach ($jsonconditions as $jsoncondition) {
                        if (isset($jsoncondition->id) && $jsoncondition->id == MOD_BOOKING_BO_COND_BOOKING_TIME) {
                            $conditionobject = $jsoncondition;
                            $openingmode = $jsoncondition->openingmode ?? 0;
                            $closingmode = $jsoncondition->closingmode ?? 0;
                            break;
                        }
                    }
                }
            }

            // Backwards compatibility: if no JSON mode but we have DB times, treat as absolute.
            if (empty($conditionobject)) {
                if (!empty($settings->bookingopeningtime)) {
                    $openingmode = 1;
                }
                if (!empty($settings->bookingclosingtime)) {
                    $closingmode = 1;
                }
            }
            // For existing options the checkbox mirrors whether a mode is active.
            $checkboxopening = $openingmode > 0 ? 1 : 0;
            $checkboxclosing = $closingmode > 0 ? 1 : 0;
        }

        // Opening time mode select - checkbox enables/disables this choice.
        $modes = [
            1 => get_string('bookingtimeabsolutemode', 'mod_booking'),
            2 => get_string('bookingtimerelativemode', 'mod_booking'),
        ];
        // Master checkbox - enables/disables the time restrictions for opening period.
        $mform->addElement(
            'advcheckbox',
            'restrictanswerperiodopening',
            get_string('restrictanswerperiodopening', 'mod_booking')
        );
        $mform->setDefault('restrictanswerperiodopening', $checkboxopening);

        // Opening time mode select - only shown when checkbox is checked.
        if ($relativemodeenabled) {
            $mform->addElement(
                'select',
                'booking_time_opening_mode',
                get_string('restrictanswerperiodopening', 'mod_booking'),
                $modes
            );
            // Hide mode select when checkbox is unchecked.
            $mform->hideIf('booking_time_opening_mode', 'restrictanswerperiodopening', 'eq', 0);
            // Set default to absolute mode (1) when enabled, never 0.
            $mform->setDefault('booking_time_opening_mode', max(1, $openingmode));
        }

        // Opening time absolute.
        $mform->addElement(
            'date_time_selector',
            'bookingopeningtime',
            get_string('bookingtimeopeningabsolutedate', 'mod_booking'),
            time_handler::set_timeintervall(),
        );
        $mform->setType('bookingopeningtime', PARAM_INT);
        $defaultopeningtime = $conditionobject->openingtime ?? $settings->bookingopeningtime ?? time_handler::prettytime(time());
        $mform->setDefault('bookingopeningtime', $defaultopeningtime);
        // Hide opening time picker when checkbox unchecked.
        $mform->hideIf('bookingopeningtime', 'restrictanswerperiodopening', 'eq', 0);
        if ($relativemodeenabled) {
            // Also hide when mode is not absolute.
            $mform->hideIf('bookingopeningtime', 'booking_time_opening_mode', 'neq', 1);
        }

        // Opening time relative.
        $datefields = [
            'coursestarttime' => get_string('bookingoptionstart', 'mod_booking'),
            'courseendtime' => get_string('bookingoptionend', 'mod_booking'),
        ];
        if ($relativemodeenabled) {
            $mform->addElement(
                'duration',
                'booking_time_opening_relative_duration',
                get_string('bookingtimeopeningrelativeduration', 'mod_booking')
            );
            $mform->setDefault(
                'booking_time_opening_relative_duration',
                $conditionobject->openingrelativeduration ?? self::get_default_relative_opening_duration()
            );
            // Hide relative duration when checkbox unchecked or mode is not relative.
            $mform->hideIf('booking_time_opening_relative_duration', 'restrictanswerperiodopening', 'eq', 0);
            $mform->hideIf('booking_time_opening_relative_duration', 'booking_time_opening_mode', 'neq', 2);

            $mform->addElement(
                'select',
                'booking_time_opening_relative_beforeafter',
                get_string('bookingtimerelativebeforeafter', 'mod_booking'),
                [
                    1 => get_string('before', 'mod_booking'),
                    -1 => get_string('after', 'mod_booking'),
                ]
            );
            $mform->setDefault(
                'booking_time_opening_relative_beforeafter',
                $conditionobject->openingrelativebeforeafter ?? self::get_default_relative_opening_beforeafter()
            );
            // Hide relative before/after when checkbox unchecked or mode is not relative.
            $mform->hideIf('booking_time_opening_relative_beforeafter', 'restrictanswerperiodopening', 'eq', 0);
            $mform->hideIf('booking_time_opening_relative_beforeafter', 'booking_time_opening_mode', 'neq', 2);

            $mform->addElement(
                'select',
                'booking_time_opening_relative_datefield',
                get_string('bookingtimerelativedatefield', 'mod_booking'),
                $datefields
            );
            $openingdatefield = $conditionobject->openingrelativedatefield ?? self::get_default_relative_opening_datefield();
            $mform->setDefault('booking_time_opening_relative_datefield', $openingdatefield);
            // Hide relative datefield when checkbox unchecked or mode is not relative.
            $mform->hideIf('booking_time_opening_relative_datefield', 'restrictanswerperiodopening', 'eq', 0);
            $mform->hideIf('booking_time_opening_relative_datefield', 'booking_time_opening_mode', 'neq', 2);
        }

        // Master checkbox - enables/disables the time restrictions for closing period.
        $mform->addElement(
            'advcheckbox',
            'restrictanswerperiodclosing',
            get_string('restrictanswerperiodclosing', 'mod_booking')
        );
        $mform->setDefault('restrictanswerperiodclosing', $checkboxclosing);

        // Closing time mode select - only shown when checkbox is checked.
        if ($relativemodeenabled) {
            $mform->addElement(
                'select',
                'booking_time_closing_mode',
                get_string('restrictanswerperiodclosing', 'mod_booking'),
                $modes
            );
            // Hide mode select when checkbox is unchecked.
            $mform->hideIf('booking_time_closing_mode', 'restrictanswerperiodclosing', 'eq', 0);
            // Set default to absolute mode (1) when enabled, never 0.
            $mform->setDefault('booking_time_closing_mode', max(1, $closingmode));
        }

        // Closing time absolute.
        $mform->addElement(
            'date_time_selector',
            'bookingclosingtime',
            get_string('bookingtimeclosingabsolutedate', 'mod_booking'),
            time_handler::set_timeintervall(),
        );
        $mform->setType('bookingclosingtime', PARAM_INT);
        $defaultclosingtime = $conditionobject->closingtime ?? $settings->bookingclosingtime ?? time_handler::prettytime(time());
        $mform->setDefault('bookingclosingtime', $defaultclosingtime);
        // Hide closing time picker when checkbox unchecked.
        $mform->hideIf('bookingclosingtime', 'restrictanswerperiodclosing', 'eq', 0);
        if ($relativemodeenabled) {
            // Also hide when mode is not absolute.
            $mform->hideIf('bookingclosingtime', 'booking_time_closing_mode', 'neq', 1);
        }

        // Closing time relative.
        if ($relativemodeenabled) {
            $mform->addElement(
                'duration',
                'booking_time_closing_relative_duration',
                get_string('bookingtimeclosingrelativeduration', 'mod_booking')
            );
            $mform->setDefault(
                'booking_time_closing_relative_duration',
                $conditionobject->closingrelativeduration ?? self::get_default_relative_closing_duration()
            );
            // Hide relative duration when checkbox unchecked or mode is not relative.
            $mform->hideIf('booking_time_closing_relative_duration', 'restrictanswerperiodclosing', 'eq', 0);
            $mform->hideIf('booking_time_closing_relative_duration', 'booking_time_closing_mode', 'neq', 2);

            $mform->addElement(
                'select',
                'booking_time_closing_relative_beforeafter',
                get_string('bookingtimerelativebeforeafter', 'mod_booking'),
                [
                    1 => get_string('before', 'mod_booking'),
                    -1 => get_string('after', 'mod_booking'),
                ]
            );
            $mform->setDefault(
                'booking_time_closing_relative_beforeafter',
                $conditionobject->closingrelativebeforeafter ?? self::get_default_relative_closing_beforeafter()
            );
            // Hide relative before/after when checkbox unchecked or mode is not relative.
            $mform->hideIf('booking_time_closing_relative_beforeafter', 'restrictanswerperiodclosing', 'eq', 0);
            $mform->hideIf('booking_time_closing_relative_beforeafter', 'booking_time_closing_mode', 'neq', 2);

            $mform->addElement(
                'select',
                'booking_time_closing_relative_datefield',
                get_string('bookingtimerelativedatefield', 'mod_booking'),
                $datefields
            );
            $closingdatefield = $conditionobject->closingrelativedatefield ?? self::get_default_relative_closing_datefield();
            $mform->setDefault('booking_time_closing_relative_datefield', $closingdatefield);
            // Hide relative datefield when checkbox unchecked or mode is not relative.
            $mform->hideIf('booking_time_closing_relative_datefield', 'restrictanswerperiodclosing', 'eq', 0);
            $mform->hideIf('booking_time_closing_relative_datefield', 'booking_time_closing_mode', 'neq', 2);
        }

        $mform->addElement(
            'advcheckbox',
            'bo_cond_booking_time_sqlfiltercheck',
            !empty(get_config('booking', 'sqlfilterbookingtimeonlypast'))
                ? get_string('sqlfiltercheckstringbookingtimeclosingonly', 'mod_booking')
                : get_string('sqlfiltercheckstringbookingtimeopeningandclosing', 'mod_booking')
        );
        $mform->addHelpButton(
            'bo_cond_booking_time_sqlfiltercheck',
            'sqlfilterbookingtimeonlypast',
            'mod_booking',
            '',
            false,
            new \moodle_url(
                '/admin/settings.php',
                ['section' => 'modsettingbooking'],
                'admin-sqlfilterbookingtimeonlypast'
            )
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

        $mform->addElement(
            'html',
            '<div id="restrictanswerperiodclosing_hr" class="d-flex justify-content-end"><hr class="w-75"/></div>'
        );
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
                    $timeformat = "%d %B %Y, %H:%M";
                    break;
                default:
                    $timeformat = "%B %e, %Y, %l:%M %P";
                    break;
            }

            // Get opening and closing time from option settings.
            [$openingtime, $closingtime] = $this->get_booking_opening_and_closing_time($settings);

            $description = '';
            if (!empty($openingtime) && time() < $openingtime) {
                $openingdatestring = userdate((int) $openingtime, $timeformat);
                $description .= $full ?
                    get_string('bocondbookingopeningtimefullnotavailable', 'mod_booking', $openingdatestring) :
                    get_string('bocondbookingopeningtimenotavailable', 'mod_booking', $openingdatestring);
            }
            if (!empty($closingtime) && time() > $closingtime) {
                $closingdatestring = userdate((int) $closingtime, $timeformat);
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
            $jsonstring = $settings->availability ?? '';
            $openingmode = null;
            $closingmode = null;
            $jsonobject = null;

            if (!empty($jsonstring)) {
                $jsonconditions = json_decode($jsonstring);
                if (is_array($jsonconditions)) {
                    foreach ($jsonconditions as $jsoncondition) {
                        if (isset($jsoncondition->id) && (int)$jsoncondition->id === MOD_BOOKING_BO_COND_BOOKING_TIME) {
                            $jsonobject = $jsoncondition;
                            break;
                        }
                    }
                }

                // Backwards compatibility: support old object-style JSON as well.
                if (empty($jsonobject) && is_object($jsonconditions)) {
                    $jsonobject = $jsonconditions;
                }

                if (!empty($jsonobject)) {
                    $openingmode = $jsonobject->openingmode ?? null;
                    $closingmode = $jsonobject->closingmode ?? null;
                }
            }

            // Backwards compatibility: if no opening mode but we have DB opening time, treat as absolute.
            if ($openingmode === null && !empty($settings->bookingopeningtime)) {
                $openingmode = 1;
            }

            // Backwards compatibility: if no closing mode but we have DB closing time, treat as absolute.
            if ($closingmode === null && !empty($settings->bookingclosingtime)) {
                $closingmode = 1;
            }

            // Handle opening time.
            if ($openingmode === 1) {
                // Absolute opening: use DB field.
                $openingtime = $settings->bookingopeningtime ?? null;
            } else if ($openingmode === 2) {
                // Relative opening.
                $duration = $jsonobject->openingrelativeduration ?? 0;
                $beforeafter = $jsonobject->openingrelativebeforeafter ?? 1;
                $datefield = $jsonobject->openingrelativedatefield ?? 'coursestarttime';

                $basetime = $this->get_base_time($settings, $datefield);
                if ($basetime) {
                    $openingtime = $basetime - ($beforeafter * $duration);
                } else {
                    // No usable base time (e.g. no sessions yet): fall back to the
                    // pre-computed absolute value that was stored at save time.
                    $openingtime = !empty($settings->bookingopeningtime) ? $settings->bookingopeningtime : null;
                }
            } else {
                $openingtime = null;
            }

            // Handle closing time.
            if ($closingmode === 1) {
                // Absolute closing: use DB field.
                $closingtime = $settings->bookingclosingtime ?? null;
            } else if ($closingmode === 2) {
                // Relative closing.
                $duration = $jsonobject->closingrelativeduration ?? 0;
                $beforeafter = $jsonobject->closingrelativebeforeafter ?? 1;
                $datefield = $jsonobject->closingrelativedatefield ?? 'coursestarttime';

                $basetime = $this->get_base_time($settings, $datefield);
                if ($basetime) {
                    $closingtime = $basetime - ($beforeafter * $duration);
                } else {
                    // No usable base time (e.g. no sessions yet): fall back to the
                    // pre-computed absolute value that was stored at save time.
                    $closingtime = !empty($settings->bookingclosingtime) ? $settings->bookingclosingtime : null;
                }
            } else {
                $closingtime = null;
            }
        } else {
            $jsonstring = $settings->availability ?? '';

            $jsonobject = json_decode($jsonstring);

            $openingtime = $jsonobject->openingtime ?? null;
            $closingtime = $jsonobject->closingtime ?? null;
        }

        return [$openingtime, $closingtime];
    }

    /**
     * Get the base timestamp for relative calculations.
     * The coursestarttime refers to the booking_options.coursestarttime column,
     * not the course start date.
     *
     * @param booking_option_settings $settings
     * @param string $datefield
     * @return int|null
     */
    private function get_base_time(booking_option_settings $settings, string $datefield): ?int {
        switch ($datefield) {
            case 'coursestarttime':
                return $settings->coursestarttime ?? null;
            case 'courseendtime':
                return $settings->courseendtime ?? null;
            // Add more cases as needed.
            default:
                return null;
        }
    }

    /**
     * Resolve booking-time persistence data from form input.
     *
     * This is pure condition logic: interpret opening/closing modes and compute
     * timestamps to be persisted by option field classes.
     *
     * @param stdClass $data
     * @return stdClass
     */
    public static function resolve_persistence_data(stdClass $data): stdClass {
        $result = (object)[
            'hasopening' => false,
            'hasclosing' => false,
            'openingmode' => null,
            'closingmode' => null,
            'bookingopeningtime' => null,
            'bookingclosingtime' => null,
            'restrictanswerperiodopening' => null,
            'restrictanswerperiodclosing' => null,
        ];

        $openingmode = null;
        if (property_exists($data, 'booking_time_opening_mode')) {
            $openingmode = (int)($data->booking_time_opening_mode ?? 0);
            /* Master checkbox takes precedence: hideIf still submits hidden selects, so
            an unchecked checkbox must override whatever booking_time_opening_mode says. */
            if (property_exists($data, 'restrictanswerperiodopening') && empty($data->restrictanswerperiodopening)) {
                $openingmode = 0;
            }
        } else if (property_exists($data, 'bookingopeningtime')) {
            /* Legacy absolute-only interface.
            Respect the master checkbox: an unchecked checkbox means no restriction,
            regardless of the date_time_selector value (which always submits a non-zero timestamp). */
            if (property_exists($data, 'restrictanswerperiodopening') && empty($data->restrictanswerperiodopening)) {
                $openingmode = 0;
            } else {
                $openingmode = !empty($data->bookingopeningtime) ? 1 : 0;
            }
        }

        $closingmode = null;
        if (property_exists($data, 'booking_time_closing_mode')) {
            $closingmode = (int)($data->booking_time_closing_mode ?? 0);
            /* Master checkbox takes precedence: hideIf still submits hidden selects, so
            an unchecked checkbox must override whatever booking_time_closing_mode says. */
            if (property_exists($data, 'restrictanswerperiodclosing') && empty($data->restrictanswerperiodclosing)) {
                $closingmode = 0;
            }
        } else if (property_exists($data, 'bookingclosingtime')) {
            /* Legacy absolute-only interface.
            Respect the master checkbox: an unchecked checkbox means no restriction,
            regardless of the date_time_selector value (which always submits a non-zero timestamp). */
            if (property_exists($data, 'restrictanswerperiodclosing') && empty($data->restrictanswerperiodclosing)) {
                $closingmode = 0;
            } else {
                $closingmode = !empty($data->bookingclosingtime) ? 1 : 0;
            }
        }

        if ($openingmode !== null) {
            $result->hasopening = true;
            $result->openingmode = $openingmode;
            $result->restrictanswerperiodopening = $openingmode > 0 ? 1 : 0;

            if ($openingmode === 0) {
                $result->bookingopeningtime = 0;
            } else if ($openingmode === 1) {
                $result->bookingopeningtime = (int)($data->bookingopeningtime ?? 0);
            } else if ($openingmode === 2) {
                $duration = $data->booking_time_opening_relative_duration ?? 0;
                $beforeafter = $data->booking_time_opening_relative_beforeafter ?? 1;
                $datefield = $data->booking_time_opening_relative_datefield ?? 'coursestarttime';
                $basetime = self::get_base_time_from_form_data($data, $datefield);
                $result->bookingopeningtime = $basetime ? ($basetime - ($beforeafter * $duration)) : 0;
            }
        }

        if ($closingmode !== null) {
            $result->hasclosing = true;
            $result->closingmode = $closingmode;
            $result->restrictanswerperiodclosing = $closingmode > 0 ? 1 : 0;

            if ($closingmode === 0) {
                $result->bookingclosingtime = 0;
            } else if ($closingmode === 1) {
                $result->bookingclosingtime = (int)($data->bookingclosingtime ?? 0);
            } else if ($closingmode === 2) {
                $duration = $data->booking_time_closing_relative_duration ?? 0;
                $beforeafter = $data->booking_time_closing_relative_beforeafter ?? 1;
                $datefield = $data->booking_time_closing_relative_datefield ?? 'coursestarttime';
                $basetime = self::get_base_time_from_form_data($data, $datefield);
                $result->bookingclosingtime = $basetime ? ($basetime - ($beforeafter * $duration)) : 0;
            }
        }

        return $result;
    }

    /**
     * Get base timestamp for relative calculation from option form/update data.
     *
     * @param stdClass $data
     * @param string $datefield
     * @return int|null
     */
    private static function get_base_time_from_form_data(stdClass $data, string $datefield): ?int {
        switch ($datefield) {
            case 'coursestarttime':
                return $data->coursestarttime ?? null;
            case 'courseendtime':
                return $data->courseendtime ?? null;
            // Add more cases as needed.
            default:
                return null;
        }
    }

    /**
     * Returns a condition object which is needed to create the condition JSON.
     *
     * @param stdClass $fromform
     * @return stdClass|null the object for the JSON
     */
    public function get_condition_object_for_json(stdClass $fromform): ?stdClass {

        $conditionobject = new stdClass();

        $openingmode = property_exists($fromform, 'booking_time_opening_mode')
            ? (int)($fromform->booking_time_opening_mode ?? 0)
            : null;
        /* Master checkbox takes precedence: hideIf still submits hidden selects, so
        an unchecked checkbox must override whatever booking_time_opening_mode says. */
        if (
            $openingmode !== null
            && property_exists($fromform, 'restrictanswerperiodopening')
            && empty($fromform->restrictanswerperiodopening)
        ) {
            $openingmode = 0;
        }
        $closingmode = property_exists($fromform, 'booking_time_closing_mode')
            ? (int)($fromform->booking_time_closing_mode ?? 0)
            : null;
        /* Master checkbox takes precedence: hideIf still submits hidden selects, so
        an unchecked checkbox must override whatever booking_time_closing_mode says. */
        if (
            $closingmode !== null
            && property_exists($fromform, 'restrictanswerperiodclosing')
            && empty($fromform->restrictanswerperiodclosing)
        ) {
            $closingmode = 0;
        }
        $relativemodeenabled = self::is_relative_mode_enabled();
        $existingcondition = self::get_existing_condition_object_from_form_or_option($fromform);

        // Legacy interface mode: do not expose mode fields, keep existing JSON configuration if present.
        if (
            !$relativemodeenabled
            && !property_exists($fromform, 'booking_time_opening_mode')
            && !property_exists($fromform, 'booking_time_closing_mode')
        ) {
            return self::get_existing_condition_object_from_option((int)($fromform->id ?? 0));
        }

        // If one mode key is not part of submitted payload, keep previously stored mode.
        if ($openingmode === null && !empty($existingcondition)) {
            $openingmode = isset($existingcondition->openingmode) ? (int)$existingcondition->openingmode : null;
        }
        if ($closingmode === null && !empty($existingcondition)) {
            $closingmode = isset($existingcondition->closingmode) ? (int)$existingcondition->closingmode : null;
        }

        // Fallback for payloads without explicit mode keys.
        if ($openingmode === null) {
            $openingmode = !empty($fromform->restrictanswerperiodopening) ? 1 : 0;
        }
        if ($closingmode === null) {
            $closingmode = !empty($fromform->restrictanswerperiodclosing) ? 1 : 0;
        }

        // If both modes are 0 (no restriction), no JSON needed.
        if ($openingmode == 0 && $closingmode == 0) {
            return null;
        }

        // Remove the namespace from classname.
        $classname = __CLASS__;
        $classnameparts = explode('\\', $classname);
        $shortclassname = end($classnameparts); // Without namespace.

        $conditionobject->id = MOD_BOOKING_BO_COND_BOOKING_TIME;
        $conditionobject->name = $shortclassname;
        $conditionobject->class = $classname;

        // Store opening mode and data.
        $conditionobject->openingmode = $openingmode;
        // For mode 1 (absolute), times are stored in DB fields, not in JSON.
        // Only relative parameters are stored in JSON.
        if ($openingmode == 2) {
            // Relative opening: store relative data only.
            $conditionobject->openingrelativeduration = $fromform->booking_time_opening_relative_duration
                ?? $existingcondition->openingrelativeduration
                ?? 0;
            $conditionobject->openingrelativebeforeafter = $fromform->booking_time_opening_relative_beforeafter
                ?? $existingcondition->openingrelativebeforeafter
                ?? 1;
            $conditionobject->openingrelativedatefield = $fromform->booking_time_opening_relative_datefield
                ?? $existingcondition->openingrelativedatefield
                ?? 'coursestarttime';
        }

        // Store closing mode and data.
        $conditionobject->closingmode = $closingmode;
        // For mode 1 (absolute), times are stored in DB fields, not in JSON.
        // Only relative parameters are stored in JSON.
        if ($closingmode == 2) {
            // Relative closing: store relative data only.
            $conditionobject->closingrelativeduration = $fromform->booking_time_closing_relative_duration
                ?? $existingcondition->closingrelativeduration
                ?? 0;
            $conditionobject->closingrelativebeforeafter = $fromform->booking_time_closing_relative_beforeafter
                ?? $existingcondition->closingrelativebeforeafter
                ?? 1;
            $conditionobject->closingrelativedatefield = $fromform->booking_time_closing_relative_datefield
                ?? $existingcondition->closingrelativedatefield
                ?? 'coursestarttime';
        }

        // Override conditions - keeping for future.
        if (!empty($fromform->bo_cond_booking_time_overrideconditioncheckbox)) {
            $conditionobject->overrides = $fromform->bo_cond_booking_time_overridecondition;
            $conditionobject->overrideoperator = $fromform->bo_cond_booking_time_overrideoperator;
        }

        // Might be an empty object.
        return $conditionobject;
    }

    /**
     * Return existing booking_time condition from current form availability first,
     * then fallback to persisted option settings.
     *
     * @param stdClass $fromform
     * @return stdClass|null
     */
    private static function get_existing_condition_object_from_form_or_option(stdClass $fromform): ?stdClass {
        if (!empty($fromform->availability)) {
            $jsonconditions = json_decode($fromform->availability);
            if (is_array($jsonconditions)) {
                foreach ($jsonconditions as $jsoncondition) {
                    if (!isset($jsoncondition->id) || (int)$jsoncondition->id !== MOD_BOOKING_BO_COND_BOOKING_TIME) {
                        continue;
                    }
                    return $jsoncondition;
                }
            }
        }

        return self::get_existing_condition_object_from_option((int)($fromform->id ?? 0));
    }

    /**
     * Upsert booking_time condition data in availability JSON based on current form values.
     *
     * This persists mode and relative details in JSON while keeping all other
     * availability conditions untouched.
     *
     * @param stdClass $fromform
     * @return void
     */
    public static function upsert_condition_in_availability(stdClass &$fromform): void {
        $hasbookingtimepayload =
            property_exists($fromform, 'booking_time_opening_mode')
            || property_exists($fromform, 'booking_time_closing_mode')
            || property_exists($fromform, 'booking_time_opening_relative_duration')
            || property_exists($fromform, 'booking_time_opening_relative_beforeafter')
            || property_exists($fromform, 'booking_time_opening_relative_datefield')
            || property_exists($fromform, 'booking_time_closing_relative_duration')
            || property_exists($fromform, 'booking_time_closing_relative_beforeafter')
            || property_exists($fromform, 'booking_time_closing_relative_datefield');

        // If there is no explicit booking_time payload, do not mutate availability JSON.
        if (!$hasbookingtimepayload) {
            return;
        }

        $availabilityconditions = [];
        if (!empty($fromform->availability)) {
            $decoded = json_decode($fromform->availability);
            if (is_array($decoded)) {
                $availabilityconditions = $decoded;
            }
        }

        // Remove existing booking_time condition entry.
        $filteredconditions = [];
        foreach ($availabilityconditions as $condition) {
            if (!isset($condition->id) || (int)$condition->id !== MOD_BOOKING_BO_COND_BOOKING_TIME) {
                $filteredconditions[] = $condition;
            }
        }

        // Build latest booking_time condition object from form and append when needed.
        $instance = new self();
        $conditionobject = $instance->get_condition_object_for_json($fromform);
        if (!empty($conditionobject)) {
            $filteredconditions[] = $conditionobject;
        }

        $fromform->availability = json_encode(array_values($filteredconditions));
    }

    // Override conditions should not be necessary here - but let's keep it if we change our mind.
    // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
    /**
     * Set default values to be shown in form when loaded from DB.
     * @param stdClass $defaultvalues the default values
     * @param stdClass $acdefault the condition object from JSON
     */
    public function set_defaults(stdClass &$defaultvalues, stdClass $acdefault) {

        $openingmode = $acdefault->openingmode ?? null;
        $closingmode = $acdefault->closingmode ?? null;

        // Backwards compatibility: if no opening mode but we have DB opening time, treat as absolute.
        if ($openingmode === null && !empty($defaultvalues->bookingopeningtime)) {
            $openingmode = 1;
        }

        // Backwards compatibility: if no closing mode but we have DB closing time, treat as absolute.
        if ($closingmode === null && !empty($defaultvalues->bookingclosingtime)) {
            $closingmode = 1;
        }

        // Set mode defaults: ensure valid modes (1 or 2), never 0.
        $defaultvalues->booking_time_opening_mode = ($openingmode > 0) ? $openingmode : 1;
        $defaultvalues->booking_time_closing_mode = ($closingmode > 0) ? $closingmode : 1;

        if ($openingmode == 2) {
            // Relative opening: load relative data from JSON.
            $defaultvalues->booking_time_opening_relative_duration = $acdefault->openingrelativeduration ?? 0;
            $defaultvalues->booking_time_opening_relative_beforeafter = $acdefault->openingrelativebeforeafter ?? 1;
            $defaultvalues->booking_time_opening_relative_datefield = $acdefault->openingrelativedatefield ?? 'coursestarttime';
        }

        if ($closingmode == 2) {
            // Relative closing: load relative data from JSON.
            $defaultvalues->booking_time_closing_relative_duration = $acdefault->closingrelativeduration ?? 0;
            $defaultvalues->booking_time_closing_relative_beforeafter = $acdefault->closingrelativebeforeafter ?? 1;
            $defaultvalues->booking_time_closing_relative_datefield = $acdefault->closingrelativedatefield ?? 'coursestarttime';
        }

        if (!empty($acdefault->overrides)) {
            $defaultvalues->bo_cond_booking_time_overrideconditioncheckbox = "1";
            $defaultvalues->bo_cond_booking_time_overridecondition = $acdefault->overrides;
            $defaultvalues->bo_cond_booking_time_overrideoperator = $acdefault->overrideoperator;
        }
    }

    /**
     * Return existing booking_time condition object from option availability JSON.
     *
     * @param int $optionid
     * @return stdClass|null
     */
    private static function get_existing_condition_object_from_option(int $optionid): ?stdClass {
        if (empty($optionid)) {
            return null;
        }

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        if (empty($settings->availability)) {
            return null;
        }

        $jsonconditions = json_decode($settings->availability);
        if (empty($jsonconditions) || !is_array($jsonconditions)) {
            return null;
        }

        foreach ($jsonconditions as $jsoncondition) {
            if (!isset($jsoncondition->id) || (int)$jsoncondition->id !== MOD_BOOKING_BO_COND_BOOKING_TIME) {
                continue;
            }
            return $jsoncondition;
        }

        return null;
    }

    /**
     * Returns whether relative booking time mode is enabled globally.
     *
     * @return bool
     */
    private static function is_relative_mode_enabled(): bool {
        $configvalue = get_config('booking', 'bookingtimerelativeenabled');
        if ($configvalue === false || $configvalue === null || $configvalue === '') {
            return false;
        }
        return !empty($configvalue);
    }

    /**
     * Returns the globally configured default relative duration in seconds.
     *
     * @return int
     */
    private static function get_default_relative_opening_duration(): int {
        $duration = get_config('booking', 'bookingtimerelativedefaultopeningduration');
        if ($duration !== false && $duration !== null && $duration !== '') {
            return (int)$duration;
        }

        // Backwards compatibility with previous single default setting.
        $legacyduration = get_config('booking', 'bookingtimerelativedefaultduration');
        if ($legacyduration !== false && $legacyduration !== null && $legacyduration !== '') {
            return (int)$legacyduration;
        }

        return 0;
    }

    /**
     * Returns the globally configured default relative closing duration in seconds.
     *
     * @return int
     */
    private static function get_default_relative_closing_duration(): int {
        $duration = get_config('booking', 'bookingtimerelativedefaultclosingduration');
        if ($duration !== false && $duration !== null && $duration !== '') {
            return (int)$duration;
        }

        // Backwards compatibility with previous single default setting.
        $legacyduration = get_config('booking', 'bookingtimerelativedefaultduration');
        if ($legacyduration !== false && $legacyduration !== null && $legacyduration !== '') {
            return (int)$legacyduration;
        }

        return 0;
    }

    /**
     * Returns the globally configured default datefield for the relative opening select.
     *
     * @return string
     */
    private static function get_default_relative_opening_datefield(): string {
        $value = get_config('booking', 'bookingtimerelativedefaultopeningdatefield');
        if ($value !== false && $value !== null && $value !== '') {
            return (string)$value;
        }
        return 'coursestarttime';
    }

    /**
     * Returns the globally configured default datefield for the relative closing select.
     *
     * @return string
     */
    private static function get_default_relative_closing_datefield(): string {
        $value = get_config('booking', 'bookingtimerelativedefaultclosingdatefield');
        if ($value !== false && $value !== null && $value !== '') {
            return (string)$value;
        }
        return 'coursestarttime';
    }

    /**
     * Returns the globally configured default before/after value for the relative opening beforeafter select.
     * 1 = before, -1 = after.
     *
     * @return int
     */
    private static function get_default_relative_opening_beforeafter(): int {
        $value = get_config('booking', 'bookingtimerelativedefaultopeningbeforeafter');
        if ($value !== false && $value !== null && $value !== '') {
            return (int)$value;
        }
        return 1;
    }

    /**
     * Returns the globally configured default before/after value for the relative closing beforeafter select.
     * 1 = before, -1 = after.
     *
     * @return int
     */
    private static function get_default_relative_closing_beforeafter(): int {
        $value = get_config('booking', 'bookingtimerelativedefaultclosingbeforeafter');
        if ($value !== false && $value !== null && $value !== '') {
            return (int)$value;
        }
        return 1;
    }

    /**
     * Destroys the singleton entirely.
     *
     * @return bool
     */
    public static function destroy_instances() {
        self::$instances = [];
        return true;
    }
}
