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
 * Availability condition that checks if a user is enrolled in (a) certain cohort(s).
 *
 * @package     mod_booking
 * @copyright   2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Magdalena Holczik
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_booking\bo_availability\conditions;

use context_course;
use context_system;
use mod_booking\bo_availability\bo_condition;
use mod_booking\bo_availability\bo_info;
use mod_booking\booking_option_settings;
use mod_booking\singleton_service;
use mod_booking\utils\wb_payment;
use moodle_url;
use MoodleQuickForm;
use stdClass;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/cohort/lib.php');
/**
 * Availability condition that checks if a user is enrolled in (a) certain cohort(s).
 *
 * @package     mod_booking
 * @copyright   2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Magdalena Holczik
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrolledincohorts implements bo_condition {
    /** @var int $id set via json during construction */
    public $id = MOD_BOOKING_BO_COND_JSON_ENROLLEDINCOHORTS;

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

        global $USER;

        // This is the return value. Not available to begin with.
        $isavailable = false;
        $neededcohorts = $this->customsettings->cohortids;

        if (empty($neededcohorts)) {
            $isavailable = true;
        } else {
            $enrolledcohorts = array_keys(singleton_service::get_cohorts_of_user($userid));
            $isavailable = false;
            $difference = array_diff($neededcohorts, $enrolledcohorts);
            if (empty($this->customsettings->cohortidsoperator) || $this->customsettings->cohortidsoperator != 'OR') {
                // User has to be enrolled in all cohorts.
                if (empty($difference)) {
                    $isavailable = true;
                }
            } else if (count($difference) < count($neededcohorts)) {
                $isavailable = true;
                // We only get true, if the user is enrolled in at least one of the cohorts of the condition.
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
        global $USER, $DB;

        $params = [];

        if (empty($userid)) {
            $userid = $USER->id;
        }

        // We get the first userid from the restriction on which booking answers we see.
        // But here we need to open this up. If we don't have the table for a given user...
        // ... we want to see the one of USER.

        $usercohorts = singleton_service::get_cohorts_of_user($userid);
        $databasetype = $DB->get_dbfamily();
        if (empty($usercohorts)) {
            if ($databasetype == 'postgres') {
                $where = "
                    (
                        availability IS NOT NULL
                        AND NOT EXISTS (
                            SELECT 1
                            FROM jsonb_array_elements(availability::jsonb) elem
                            WHERE elem ->> 'sqlfilter' = '1'
                        )
                    )";
            } else if (
                $databasetype == 'mysql'
                && db_is_at_least_mariadb_106_or_mysql_8() // JSON_TABLE is only available in MariaDB 10.6+ and MySQL 8.0+.
            ) {
                $where = "
                (
                    availability IS NOT NULL
                    AND NOT EXISTS (
                        SELECT 1
                        FROM JSON_TABLE(availability, '$[*]' COLUMNS (sqlfilter VARCHAR(10) PATH '$.sqlfilter')) jt
                        WHERE jt.sqlfilter = '1'
                    )
                )";
            } else {
                return ["", "", "", $params, ""];
            }
            return ["", "", "", $params, $where];
        }

        // The $key param is the name of the param in json.
        if ($databasetype == 'postgres') {
            // Appended as string for DB syntax reasons.
            $appendwhere1 = "";
            $cohortids = array_map(fn($c) => '"' . $c->id . '"', $usercohorts);
            $appendwhere1 = implode(', ', $cohortids);

            $cohorts = [];
            $appendwhere2 = "";
            foreach ($usercohorts as $cohort) {
                $cohorts[] = "'$cohort->id'";
            }
            // List of current cohorts of user.
            $appendwhere2 = implode(', ', $cohorts);

            // Depending on the cohortidsoperator check either if user in enrolled in all or at least one cohorts selected.
            // Default is AND - all cohorts must be met by user.
            $where = "
            availability IS NOT NULL
            AND ((NOT EXISTS (
                            SELECT 1
                            FROM jsonb_array_elements(availability::jsonb) elem
                            WHERE elem ->> 'sqlfilter' = '1'
                        ))
                OR (CASE
                    WHEN (availability::jsonb->0->>'cohortidsoperator') = 'OR' THEN
                        EXISTS (
                            SELECT 1
                            FROM jsonb_array_elements(availability::jsonb) AS obj
                            WHERE obj->>'cohortids' IS NOT NULL
                            AND EXISTS (
                                SELECT 1
                                FROM jsonb_array_elements_text((obj->'cohortids')::jsonb) AS cohortids
                                WHERE cohortids::text IN ($appendwhere2)
                            )
                        )
                    ELSE
                        NOT EXISTS (
                            SELECT 1
                            FROM jsonb_array_elements(availability::jsonb) AS obj
                            WHERE obj->>'cohortids' IS NOT NULL
                            AND NOT (obj->'cohortids')::jsonb <@ '[$appendwhere1]'::jsonb
                        )
                    END
                )
            )";
            return ['', '', '', $params, $where];
        } else if (
            $databasetype == 'mysql'
            && db_is_at_least_mariadb_106_or_mysql_8() // JSON_TABLE is only available in MariaDB 10.6+ and MySQL 8.0+.
        ) {
            $andcases = '';
            foreach (array_keys($usercohorts) as $cohortid) {
                $andcases .= "
                CASE
                    WHEN JSON_SEARCH(availability, 'one', '$cohortid', NULL, '\$[*].cohortids') IS NOT NULL THEN 1
                    ELSE 0
                END +";
            }
            $andcases = rtrim($andcases, ' +');

            $where = "
                availability IS NOT NULL
                AND ((
                    (NOT EXISTS (
                        SELECT 1
                        FROM JSON_TABLE(availability, '$[*]' COLUMNS (sqlfilter VARCHAR(10) PATH '$.sqlfilter')) jt
                        WHERE jt.sqlfilter = '1'
                    ))
                )
                OR (
                    id IN (
                        SELECT id
                            FROM (
                                        SELECT id,
                                        JSON_UNQUOTE(JSON_EXTRACT(availability, '$[0].cohortidsoperator')) AS operator,
                                        JSON_LENGTH(JSON_EXTRACT(availability, '$[*].cohortids[*]')) AS length,
                                            ($andcases) AS true_conditions_count
                                    FROM {booking_options}
                                WHERE availability IS NOT NULL
                            ) s1
                        WHERE (
                                CASE
                                    WHEN operator LIKE \"AND\" THEN length = true_conditions_count
                                    ELSE true_conditions_count > 0
                                END
                        )
                    )
                )
            )";
            return ['', '', '', $params, $where];
        } else {
            return ['', '', '', $params, ''];
        }
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
            $cohortssarray = [];

            $cohorts = cohort_get_all_cohorts(0, 500);

            if ($cohorts) {
                if ($cohorts["totalcohorts"] > count($cohorts["cohorts"])) {
                    $mform->addElement(
                        'static',
                        'bo_cond_enrolledincohorts_warning',
                        '',
                        get_string('bocondenrolledincohortswarning', 'mod_booking')
                    );
                }

                foreach ($cohorts['cohorts'] as $cohortrecord) {
                    $cohortssarray[$cohortrecord->id] =
                        "$cohortrecord->name (ID: $cohortrecord->id)";
                }
            }

            $mform->addElement(
                'advcheckbox',
                'bo_cond_enrolledincohorts_restrict',
                get_string('bocondenrolledincohorts', 'mod_booking')
            );

            $enrolledincohortsoptions = [
                'tags' => false,
                'multiple' => true,
            ];

            $overrideoperators = [
                'OR' => get_string('overrideoperator:or', 'mod_booking'),
                'AND' => get_string('overrideoperator:and', 'mod_booking'),
            ];

            $mform->addElement(
                'autocomplete',
                'bo_cond_enrolledincohorts_cohortids',
                get_string('cohorts', 'mod_booking'),
                $cohortssarray,
                $enrolledincohortsoptions
            );
            $mform->hideIf('bo_cond_enrolledincohorts_cohortids', 'bo_cond_enrolledincohorts_restrict', 'notchecked');

            $cohortoperator = [
                'OR' => get_string('onecohortmustbefound', 'mod_booking'),
                'AND' => get_string('allcohortsmustbefound', 'mod_booking'),
            ];

            $mform->addElement(
                'select',
                'bo_cond_enrolledincohorts_cohortids_operator',
                get_string('overrideoperator', 'mod_booking'),
                $cohortoperator
            );
            $mform->setDefault('bo_cond_enrolledincohorts_cohortids_operator', 'OR');
            $mform->hideIf('bo_cond_enrolledincohorts_cohortids_operator', 'bo_cond_enrolledincohorts_restrict', 'notchecked');

            $mform->addElement(
                'advcheckbox',
                'bo_cond_enrolledincohorts_sqlfiltercheck',
                get_string('sqlfiltercheckstring', 'mod_booking')
            );
            $mform->hideIf('bo_cond_enrolledincohorts_sqlfiltercheck', 'bo_cond_enrolledincohorts_restrict', 'notchecked');

            $mform->addElement(
                'advcheckbox',
                'bo_cond_enrolledincohorts_overrideconditioncheckbox',
                get_string('overrideconditioncheckbox', 'mod_booking')
            );
            $mform->hideIf(
                'bo_cond_enrolledincohorts_overrideconditioncheckbox',
                'bo_cond_enrolledincohorts_restrict',
                'notchecked'
            );

            $mform->addElement(
                'select',
                'bo_cond_enrolledincohorts_overrideoperator',
                get_string('overrideoperator', 'mod_booking'),
                $overrideoperators
            );
            $mform->hideIf(
                'bo_cond_enrolledincohorts_overrideoperator',
                'bo_cond_enrolledincohorts_overrideconditioncheckbox',
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
                                    str_replace("_", "", $jsoncondition->name), 'mod_booking'); // Remove underscroll.
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
                'bo_cond_enrolledincohorts_overridecondition',
                get_string('overridecondition', 'mod_booking'),
                $overrideconditionsarray,
                $options
            );
            $mform->hideIf(
                'bo_cond_enrolledincohorts_overridecondition',
                'bo_cond_enrolledincohorts_overrideconditioncheckbox',
                'notchecked'
            );
        } else {
            // No PRO license is active.
            $mform->addElement(
                'static',
                'bo_cond_enrolledincohorts_restrict',
                get_string('bocondenrolledincohorts', 'mod_booking'),
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
        if (!empty($fromform->bo_cond_enrolledincohorts_restrict)) {
            // Remove the namespace from classname.
            $classname = __CLASS__;
            $classnameparts = explode('\\', $classname);
            $shortclassname = end($classnameparts); // Without namespace.

            $conditionobject->id = $this->id;
            $conditionobject->name = $shortclassname;
            $conditionobject->class = $classname;
            $conditionobject->cohortids = $fromform->bo_cond_enrolledincohorts_cohortids;
            $conditionobject->cohortidsoperator = $fromform->bo_cond_enrolledincohorts_cohortids_operator;
            $conditionobject->sqlfilter = $fromform->bo_cond_enrolledincohorts_sqlfiltercheck ?? 0;

            if (!empty($fromform->bo_cond_enrolledincohorts_overrideconditioncheckbox)) {
                $conditionobject->overrides = $fromform->bo_cond_enrolledincohorts_overridecondition;
                $conditionobject->overrideoperator = $fromform->bo_cond_enrolledincohorts_overrideoperator;
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
        if (!empty($acdefault->cohortids)) {
            $defaultvalues->bo_cond_enrolledincohorts_restrict = "1";
            $defaultvalues->bo_cond_enrolledincohorts_cohortids = $acdefault->cohortids;
            $defaultvalues->bo_cond_enrolledincohorts_cohortids_operator = $acdefault->cohortidsoperator ?? 'AND';
            $defaultvalues->bo_cond_enrolledincohorts_sqlfiltercheck = $acdefault->sqlfilter ?? "";
        }
        if (!empty($acdefault->overrides)) {
            $defaultvalues->bo_cond_enrolledincohorts_overrideconditioncheckbox = "1";
            $defaultvalues->bo_cond_enrolledincohorts_overridecondition = $acdefault->overrides;
            $defaultvalues->bo_cond_enrolledincohorts_overrideoperator = $acdefault->overrideoperator;
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
            $description = $full ? get_string('bocondenrolledincohortsfullavailable', 'mod_booking') :
                get_string('bocondenrolledincohortsavailable', 'mod_booking');
        } else {
            if (!$this->customsettings) {
                // This description can only work with the right custom settings.
                $availabilityarray = json_decode($settings->availability);

                foreach ($availabilityarray as $availability) {
                    if (strpos($availability->class, 'enrolledincohort') > 0) {
                        $this->customsettings = (object)$availability;
                    }
                }
            }

            if (!isset($this->customsettings->cohortids)) {
                return 'Error in "enrolledincohort" availability condition: no cohort ids are set!';
            }

            $a = '';
            $coursestringsarr = [];
            foreach ($this->customsettings->cohortids as $cohortid) {
                $cohort = singleton_service::get_cohort($cohortid);
                if (!empty($cohort)) {
                    $coursestringsarr[] = $cohort->name;
                }
            }
            $a = implode(', ', $coursestringsarr);

            if (
                isset($this->customsettings->cohortidsoperator)
                && $this->customsettings->cohortidsoperator == 'OR'
            ) {
                $description = $full ?
                    get_string('bocondenrolledincohortsfullnotavailable', 'mod_booking', $a) :
                    get_string('bocondenrolledincohortsnotavailable', 'mod_booking', $a);
            } else {
                $description = $full ?
                    get_string('bocondenrolledincohortsfullnotavailableand', 'mod_booking', $a) :
                    get_string('bocondenrolledincohortsnotavailableand', 'mod_booking', $a);
            }
        }

        return $description;
    }
}
