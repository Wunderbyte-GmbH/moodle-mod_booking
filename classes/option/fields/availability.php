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
 * Control and manage booking dates.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\option\fields;

use context_module;
use mod_booking\bo_availability\bo_info;
use mod_booking\booking_option_settings;
use mod_booking\event\selectusers_user_added;
use mod_booking\option\fields_info;
use mod_booking\option\field_base;
use mod_booking\singleton_service;
use MoodleQuickForm;
use stdClass;

/**
 * Class to handle one property of the booking_option_settings class.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class availability extends field_base {
    /**
     * This ID is used for sorting execution.
     * @var int
     */
    public static $id = MOD_BOOKING_OPTION_FIELD_AVAILABILITY;

    /**
     * Some fields are saved with the booking option...
     * This is normal behaviour.
     * Some can be saved only post save (when they need the option id).
     * @var int
     */
    public static $save = MOD_BOOKING_EXECUTION_POSTSAVE;

    /**
     * This identifies the header under which this particular field should be displayed.
     * @var string
     */
    public static $header = MOD_BOOKING_HEADER_AVAILABILITY;

    /**
     * An int value to define if this field is standard or used in a different context.
     * @var array
     */
    public static $fieldcategories = [MOD_BOOKING_OPTION_FIELD_STANDARD];

    /**
     * Additionally to the classname, there might be others keys which should instantiate this class.
     * @var array
     */
    public static $alternativeimportidentifiers = [
        'boavenrolledincourse',
        'boavenrolledincohorts',
        'bo_cond_enrolledincourse_restrict',
        'bo_cond_enrolledincohorts_restrict',
        'bo_cond_hascompetency_restrict',
        'bo_cond_previouslybooked_restrict',
        'bo_cond_selectusers_restrict',
        'bo_cond_nooverlapping_restrict',
        'bo_cond_allowedtobookininstance_restrict',
        'bo_cond_userprofilefield_1_default_restrict',
        'bo_cond_userprofilefield_2_custom_restrict',
        'bo_cond_customform_restrict',
    ];

    /**
     * This is an array of incompatible field ids.
     * @var array
     */
    public static $incompatiblefields = [
        MOD_BOOKING_OPTION_FIELD_EASY_BOOKINGCLOSINGTIME,
        MOD_BOOKING_OPTION_FIELD_EASY_BOOKINGOPENINGTIME,
        MOD_BOOKING_OPTION_FIELD_EASY_AVAILABILITY_PREVIOUSLYBOOKED,
        MOD_BOOKING_OPTION_FIELD_EASY_AVAILABILITY_SELECTUSERS,
    ];

    /**
     * This function interprets the value from the form and, if useful...
     * ... relays it to the new option class for saving or updating.
     * @param stdClass $formdata
     * @param stdClass $newoption
     * @param int $updateparam
     * @param ?mixed $returnvalue
     * @return string // If no warning, empty string.
     */
    public static function prepare_save_field(
        stdClass &$formdata,
        stdClass &$newoption,
        int $updateparam,
        $returnvalue = null
    ): array {

        // Save the additional JSON conditions (the ones which have been added to the mform).
        bo_info::save_json_conditions_from_form($formdata);
        // As availability class can be used without also calling bookingopening and -closing time, we need to call them here.
        bookingopeningtime::prepare_save_field($formdata, $newoption, $updateparam);
        bookingclosingtime::prepare_save_field($formdata, $newoption, $updateparam);

        $newoption->availability = $formdata->availability ?? '[]';
        if (empty($newoption->sqlfilter)) {
            $newoption->sqlfilter = $formdata->sqlfilter;
        }

        $instance = new availability();
        return $instance->check_for_changes($formdata, $instance);
    }

    /**
     * Instance form definition
     * @param MoodleQuickForm $mform
     * @param array $formdata
     * @param array $optionformconfig
     * @param array $fieldstoinstanciate
     * @param bool $applyheader
     * @return void
     */
    public static function instance_form_definition(
        MoodleQuickForm &$mform,
        array &$formdata,
        array $optionformconfig,
        $fieldstoinstanciate = [],
        $applyheader = true
    ) {

        $optionid = $formdata['id'];

        // Todo: expert/simple mode needs to work with this too!
        // Add availability conditions.
        bo_info::add_conditions_to_mform($mform, $optionid);
    }

    /**
     * Standard function to transfer stored value to form.
     * @param stdClass $data
     * @param booking_option_settings $settings
     * @return void
     * @throws dml_exception
     */
    public static function set_data(stdClass &$data, booking_option_settings $settings) {

        global $DB;

        // Availability normally comes from settings, but it might come from the importer as well.
        if (!empty($data->importing)) {
            if (!empty($data->availability)) {
                $availability = $data->availability;
            } else {
                $availability = $settings->availability ?? "[]";
                if (empty($availability)) {
                    $availability = "[]";
                }
                $data->availability = $availability;
            }

            // On importing, we support the boavenrolledincourse key.
            if (!empty($data->boavenrolledincourse)) {
                $items = explode(',', $data->boavenrolledincourse);

                [$inorequal, $params] = $DB->get_in_or_equal($items, SQL_PARAMS_NAMED);
                $sql = "SELECT id
                        FROM {course}
                        WHERE shortname $inorequal";
                $courses = $DB->get_records_sql($sql, $params);

                $data->bo_cond_enrolledincourse_courseids = array_keys($courses);
                $data->bo_cond_enrolledincourse_restrict = 1;
                // The operator defaults to "OR", but can be set via the boavenrolledincourseoperator column.
                $data->bo_cond_enrolledincourse_courseids_operator
                    = $data->boavenrolledincourseoperator ?? 'OR';
                unset($data->boavenrolledincourse);
                unset($data->boavenrolledincourseoperator);
            }
            // We do the some for the boavenrolledincohorts key.
            if (!empty($data->boavenrolledincohorts)) {
                $items = explode(',', $data->boavenrolledincohorts);
                [$inorequal, $params] = $DB->get_in_or_equal($items, SQL_PARAMS_NAMED);
                $sql = "SELECT id
                        FROM {cohort}
                        WHERE idnumber $inorequal";
                $cohorts = $DB->get_records_sql($sql, $params);

                $data->bo_cond_enrolledincohorts_cohortids = array_keys($cohorts);
                $data->bo_cond_enrolledincohorts_restrict = 1;
                // The operator defaults to "OR", but can be set via the boavenrolledincourseoperator column.
                $data->bo_cond_enrolledincohorts_cohortids_operator = $data->boavenrolledincohortsoperator ?? 'OR';
                unset($data->boavenrolledincohorts);
                unset($data->boavenrolledincohortsoperator);
            }
        } else {
            $availability = $settings->availability ?? "[]";
            bookingopeningtime::set_data($data, $settings);
            bookingclosingtime::set_data($data, $settings);
        }

        if (!empty($availability)) {
            $jsonobject = json_decode($availability);

            if (!empty($data->importing)) {
                // Preserve explicitly imported condition values and only backfill missing fields
                // from the existing availability JSON.
                $defaultvalues = new stdClass();
                bo_info::set_defaults($defaultvalues, $jsonobject);
                foreach ($defaultvalues as $key => $value) {
                    if (!isset($data->{$key})) {
                        $data->{$key} = $value;
                    }
                }
            } else {
                bo_info::set_defaults($data, $jsonobject);
            }
        }
    }

    /**
     * Compares the stored availability conditions with the submitted ones, condition by condition.
     *
     * The availability JSON holds one object per condition. Only conditions that were added,
     * removed or changed are reported, each as a change entry of its own, so the change notice
     * can name the condition and show its old and new settings. Re-serialisation differences
     * (key order, numeric strings, order of selected ids) are not changes.
     *
     * @param stdClass $formdata
     * @param field_base $self
     * @param mixed $mockdata // Only needed if there the object needs params for the save_data function.
     * @param string|null $key
     * @param mixed $value
     *
     * @return array
     *
     */
    public function check_for_changes(
        stdClass $formdata,
        field_base $self,
        $mockdata = '',
        string|null $key = null,
        $value = ''
    ): array {

        $changes = [];

        $excludeclassesfromtrackingchanges = MOD_BOOKING_CLASSES_EXCLUDED_FROM_CHANGES_TRACKING;

        $classname = fields_info::get_class_name(static::class);
        if (in_array($classname, $excludeclassesfromtrackingchanges)) {
            return $changes;
        }

        $settings = singleton_service::get_instance_of_booking_option_settings(
            $formdata->optionid ?? $formdata->id
        );

        $oldconditions = self::decode_conditions($settings->availability ?? '');
        $newconditions = self::decode_conditions($formdata->availability ?? '');

        $conditionids = array_unique(array_merge(array_keys($oldconditions), array_keys($newconditions)));
        sort($conditionids);

        foreach ($conditionids as $conditionid) {
            $oldcondition = $oldconditions[$conditionid] ?? [];
            $newcondition = $newconditions[$conditionid] ?? [];
            if ($oldcondition == $newcondition) {
                continue;
            }
            $changes[] = [
                'changes' => [
                    'fieldname' => 'availability',
                    'formkey' => 'availability',
                    'conditionid' => $conditionid,
                    'conditionname' => $newcondition['name'] ?? $oldcondition['name'] ?? '',
                    'oldvalue' => $oldcondition,
                    'newvalue' => $newcondition,
                ],
            ];
        }

        if (empty($changes)) {
            return [];
        }
        return ['changes' => $changes];
    }

    /**
     * Once all changes are collected, trigger an event for every user newly allowed to book.
     *
     * @param array $changes
     * @param object $data
     * @param object $newoption
     * @param object $originaloption
     *
     * @return void
     *
     */
    public static function changes_collected_action(
        array $changes,
        object $data,
        object $newoption,
        object $originaloption
    ) {
        self::trigger_selectusers_user_added_events($changes, $data, $newoption);
    }

    /**
     * Triggers selectusers_user_added for each user that was added to the "select users" condition.
     *
     * Looks through the collected changes of all fields, so it works for the full availability
     * field as well as for the easy "select users" field. Nothing is triggered for users already
     * on the list or for removed users.
     *
     * @param array $changes all collected changes, keyed by field class
     * @param object $data the submitted data
     * @param object $newoption the saved option record
     *
     * @return array the ids of the users an event was triggered for
     *
     */
    public static function trigger_selectusers_user_added_events(array $changes, object $data, object $newoption): array {
        global $USER;

        $addeduserids = [];
        foreach ($changes as $fieldchanges) {
            if (!is_array($fieldchanges) || !is_array($fieldchanges['changes'] ?? null)) {
                continue;
            }
            foreach ($fieldchanges['changes'] as $entry) {
                if (!is_array($entry) || !is_array($entry['changes'] ?? null)) {
                    continue;
                }
                $entry = $entry['changes'];
                if (
                    ($entry['fieldname'] ?? '') !== 'availability'
                    || (int)($entry['conditionid'] ?? 0) !== MOD_BOOKING_BO_COND_JSON_SELECTUSERS
                ) {
                    continue;
                }
                $olduserids = array_map('intval', (array)($entry['oldvalue']['userids'] ?? []));
                $newuserids = array_map('intval', (array)($entry['newvalue']['userids'] ?? []));
                $addeduserids = array_merge($addeduserids, array_diff($newuserids, $olduserids));
            }
        }
        $addeduserids = array_values(array_unique(array_filter($addeduserids)));
        if (empty($addeduserids)) {
            return [];
        }

        $optionid = (int)($newoption->id ?? 0);
        $cmid = (int)($data->cmid ?? 0);
        if (empty($cmid) && !empty($optionid)) {
            $cmid = (int)(singleton_service::get_instance_of_booking_option_settings($optionid)->cmid ?? 0);
        }
        if (empty($optionid) || empty($cmid)) {
            return [];
        }
        $context = context_module::instance($cmid);

        foreach ($addeduserids as $userid) {
            $event = selectusers_user_added::create([
                'objectid' => $optionid,
                'context' => $context,
                'userid' => $USER->id,
                'relateduserid' => $userid,
                'other' => [
                    'cmid' => $cmid,
                ],
            ]);
            $event->trigger();
        }
        return $addeduserids;
    }

    /**
     * Decodes an availability JSON string into normalised condition arrays keyed by condition id.
     *
     * Normalised means: associative arrays with sorted keys, lists sorted by value, so that two
     * serialisations of the same settings compare equal with ==.
     *
     * @param string $json
     *
     * @return array
     *
     */
    public static function decode_conditions(string $json): array {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $conditions = [];
        foreach ($decoded as $condition) {
            if (!is_array($condition) || !isset($condition['id'])) {
                continue;
            }
            $conditions[(int)$condition['id']] = self::normalise_condition($condition);
        }
        return $conditions;
    }

    /**
     * Recursively sorts a decoded condition so that key order and list order do not count as changes.
     *
     * @param mixed $value
     *
     * @return mixed
     *
     */
    private static function normalise_condition($value) {
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $k => $v) {
            $value[$k] = self::normalise_condition($v);
        }
        if (array_is_list($value)) {
            sort($value);
        } else {
            ksort($value);
        }
        return $value;
    }

    /**
     * Returns the readable change of one availability condition for the change notice.
     *
     * @param array $changes
     *
     * @return array
     *
     */
    public function get_changes_description(array $changes): array {
        $fieldname = get_string('availability', 'mod_booking');
        // Entries restored from the log store may hold objects instead of arrays.
        $oldvalue = self::to_array($changes['oldvalue'] ?? null);
        $newvalue = self::to_array($changes['newvalue'] ?? null);

        if (empty($oldvalue) && empty($newvalue)) {
            return [
                'info' => get_string('changeinfochanged', 'mod_booking', $fieldname) . '.',
            ];
        }

        return [
            'fieldname' => $fieldname,
            'oldvalue' => self::describe_condition($oldvalue),
            'newvalue' => self::describe_condition($newvalue),
        ];
    }

    /**
     * Casts a condition that may be an array, an object or empty to an array.
     *
     * @param mixed $value
     *
     * @return array
     *
     */
    private static function to_array($value): array {
        if (is_object($value)) {
            $value = json_decode(json_encode($value), true);
        }
        return is_array($value) ? $value : [];
    }

    /**
     * Renders one decoded condition as "label: setting; setting".
     *
     * @param array $condition
     *
     * @return string
     *
     */
    private static function describe_condition(array $condition): string {
        if (empty($condition)) {
            return '';
        }
        $label = self::condition_label($condition['name'] ?? '', (int)($condition['id'] ?? 0));
        $settings = [];
        foreach ($condition as $key => $value) {
            switch ($key) {
                case 'id':
                case 'name':
                case 'class':
                case 'sqlfilter':
                    break;
                case 'userids':
                    $settings[] = get_string('bocondselectusersuserids', 'mod_booking') . ': '
                        . self::describe_users((array)$value);
                    break;
                case 'overrides':
                    $settings[] = get_string('overrideconditioncheckbox', 'mod_booking') . ': '
                        . self::describe_overrides((array)$value);
                    break;
                case 'overrideoperator':
                    $settings[] = get_string('overrideoperator', 'mod_booking') . ': ' . $value;
                    break;
                default:
                    $settings[] = $key . ': ' . self::describe_value($value);
                    break;
            }
        }
        return empty($settings) ? $label : $label . ': ' . implode('; ', $settings);
    }

    /**
     * Readable label of a condition: its lang string if there is one, otherwise its name.
     *
     * @param string $name
     * @param int $id
     *
     * @return string
     *
     */
    private static function condition_label(string $name, int $id): string {
        if (empty($name)) {
            foreach (bo_info::get_conditions() as $condition) {
                if ((int)$condition->id === $id) {
                    $name = fields_info::get_class_name(get_class($condition));
                    break;
                }
            }
        }
        $identifier = 'bocond' . str_replace('_', '', $name);
        if (!empty($name) && get_string_manager()->string_exists($identifier, 'mod_booking')) {
            return get_string($identifier, 'mod_booking');
        }
        return $name;
    }

    /**
     * Renders user ids as "Firstname Lastname (ID:x)" where the user exists.
     *
     * @param array $userids
     *
     * @return string
     *
     */
    private static function describe_users(array $userids): string {
        $names = [];
        foreach ($userids as $userid) {
            $user = singleton_service::get_instance_of_user((int)$userid);
            $names[] = empty($user) ? (string)$userid : get_string('userinfosasstring', 'mod_booking', $user);
        }
        return implode(', ', $names);
    }

    /**
     * Renders overridden condition ids by their labels.
     *
     * @param array $conditionids
     *
     * @return string
     *
     */
    private static function describe_overrides(array $conditionids): string {
        $labels = [];
        foreach ($conditionids as $conditionid) {
            $labels[] = self::condition_label('', (int)$conditionid);
        }
        return implode(', ', $labels);
    }

    /**
     * Renders a scalar or list setting value.
     *
     * @param mixed $value
     *
     * @return string
     *
     */
    private static function describe_value($value): string {
        if (is_array($value)) {
            return implode(', ', array_map([self::class, 'describe_value'], $value));
        }
        if (is_bool($value)) {
            return get_string($value ? 'on' : 'off', 'mod_booking');
        }
        return (string)$value;
    }

    /**
     * This function adds error keys for form validation.
     * @param array $data
     * @param array $files
     * @param array $errors
     * @return array
     */
    public static function validation(array $data, array $files, array &$errors) {
        bo_info::validation($data, $files, $errors);
        return $errors;
    }
}
