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

namespace mod_booking\option;

use coding_exception;
use mod_booking\booking_option_settings;
use mod_booking\option\fields;
use mod_booking\option\fields_info;
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
abstract class field_base implements fields {
    /**
     * This ID is used for sorting execution.
     * @var int
     */
    public static $id = 0;

    /**
     * Some fields are saved with the booking option...
     * This is normal behaviour.
     * Some can be saved only post save (when they need the option id).
     * @var int
     */
    public static $save = MOD_BOOKING_EXECUTION_NORMAL;

    /**
     * This identifies the header under which this particular field should be displayed.
     * @var string
     */
    public static $header = '';

    /**
     * This is an array of incompatible field ids.
     * @var array
     */
    public static $incompatiblefields = [];

    /**
     * This function interprets the value from the form and, if useful...
     * ... relays it to the new option class for saving or updating.
     * @param stdClass $formdata
     * @param stdClass $newoption
     * @param int $updateparam
     * @param mixed $returnvalue
     * @return array // Covers changes & warnings, if nothing to report: empty array.
     */
    public static function prepare_save_field(
        stdClass &$formdata,
        stdClass &$newoption,
        int $updateparam,
        $returnvalue = ''
    ): array {

        $key = fields_info::get_class_name(static::class);
        $value = $formdata->{$key} ?? null;

        if (!empty($value)) {
            $newoption->{$key} = $value;
        } else {
            $newoption->{$key} = $returnvalue;
        }

        // We can return an warning message here. Or report changes.
        return [];
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
    }

    /**
     * This function adds error keys for form validation.
     * @param array $data
     * @param array $files
     * @param array $errors
     * @return array
     */
    public static function validation(array $data, array $files, array &$errors) {

        return $errors;
    }

    /**
     * The save data function is very specific only for those values that should be saved...
     * ... after saving the option. This is so, when we need an option id for saving (because of other table).
     * @param stdClass $formdata
     * @param stdClass $option
     * @return void
     * @throws \dml_exception
     */
    public static function save_data(stdClass &$formdata, stdClass &$option) {
    }

    /**
     * Standard function to transfer stored value to form.
     * @param stdClass $data
     * @param booking_option_settings $settings
     * @return void
     * @throws dml_exception
     */
    public static function set_data(stdClass &$data, booking_option_settings $settings) {

        $key = fields_info::get_class_name(static::class);
        // Normally, we don't call set data after the first time loading.
        if (isset($data->{$key})) {
            return;
        }

        $value = $settings->{$key} ?? null;

        $data->{$key} = $value;
    }

    /**
     * Definition after data callback
     * @param MoodleQuickForm $mform
     * @param mixed $formdata
     * @return void
     * @throws coding_exception
     */
    public static function definition_after_data(MoodleQuickForm &$mform, $formdata) {
    }

    /**
     * Definition after data callback
     * @return string
     * @throws coding_exception
     */
    public static function return_classname_name() {

        $classname = get_called_class();

        // We only want the last part of the classname.
        $array = explode('\\', $classname);

        $classname = array_pop($array);
        return $classname;
    }

    /**
     * Gets the full classname including namespace.
     * @return string
     * @throws coding_exception
     */
    public static function return_full_classname(): string {
        return get_called_class();
    }

    /**
     * Every class can provide subfields.
     * @return array
     */
    public static function get_subfields() {
        return [];
    }

    /**
     * Once all changes are collected, also those triggered in save data, this is a possible hook for the fields.
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
        return;
    }

    /**
     * Check if there is a difference between the former and the new values of the formdata.
     *
     * @param stdClass $formdata
     * @param field_base $self
     * @param mixed $mockdata // Only needed if there the object needs params for the save_data function.
     * @param string $key
     * @param mixed $value
     *
     * @return array
     *
     */
    public function check_for_changes(
        stdClass $formdata,
        field_base $self,
        $mockdata = '',
        string $key = '',
        $value = ''
    ): array {
        if (!isset($self)) {
            return [];
        }

        $excludeclassesfromtrackingchanges = MOD_BOOKING_CLASSES_EXCLUDED_FROM_CHANGES_TRACKING;

        $classname = fields_info::get_class_name(static::class);
        if (in_array($classname, $excludeclassesfromtrackingchanges)) {
            return [];
        }

        $changes = [];
        $key = empty($key) ? $classname : $key;
        $value = empty($value) ? ($formdata->{$key} ?? '') : $value;

        $mockdata = empty($mockdata) ? new stdClass() : $mockdata;

        // Check if there were changes and return these.
        if (!empty($formdata->id) && isset($value)) {
            $settings = singleton_service::get_instance_of_booking_option_settings($formdata->id);
            $self::set_data($mockdata, $settings);

            // Handling for textfields.
            if (
                is_array($mockdata->{$key})
                && isset($mockdata->{$key}['text'])
            ) {
                    $oldvalue = $mockdata->{$key}['text'];
            } else if (
                is_object($mockdata->{$key})
                && property_exists($mockdata->{$key}, 'text')
            ) {
                if (is_null($mockdata->{$key}->text)) {
                    $oldvalue = "";
                } else {
                    $oldvalue = $mockdata->{$key}->text;
                }
            } else { // Default handling.
                $oldvalue = $mockdata->{$key};
            }

            if (
                is_array($value)
                && isset($value['text'])
            ) {
                $newvalue = $value['text'];
            } else if (
                is_object($value)
                && property_exists($value, 'text')
            ) {
                if (is_null($value->text)) {
                    $newvalue = "";
                } else {
                    $newvalue = $value->text;
                }
            } else { // Default handling.
                $newvalue = $value;
            }

            if (
                $oldvalue != $newvalue
                && !(empty($oldvalue) && empty($newvalue))
            ) {
                $changes = [
                    'changes' => [
                        'fieldname' => $classname,
                        'oldvalue' => $oldvalue,
                        'newvalue' => $newvalue,
                        'formkey' => $key,
                    ],
                ];
            }
        }
        return $changes;
    }

    /**
     * Return values for bookingoption_updated event.
     *
     * @param array $changes
     *
     * @return array
     *
     */
    public function get_changes_description(array $changes): array {
        $oldvalue = $changes['oldvalue'] ?? '';
        $newvalue = $changes['newvalue'] ?? '';
        $fieldname = $changes['fieldname'] ?? '';

        $fieldnamestring = get_string($changes['fieldname'], 'booking');
        $infotext = get_string('changeinfochanged', 'booking', $fieldnamestring);

        if ((empty($oldvalue) && empty($newvalue)) || $oldvalue == $newvalue) {
            return [
                'info' => $infotext . ".",
            ];
        }

        $areaswithuseridstoresolve = [
            'responsiblecontact',
            'teachers',
        ];

        $areaswithtimestampstoresolve = [
            'coursestarttime',
            'courseendtime',
            'bookingclosingtime',
            'bookingopeningtime',
            'canceluntil',
        ];

        $checkboxvalues = [
            'waitforconfirmation',
            'disablebookingusers',
            'disablecancel',
            'selflearningcourse',
        ];

        $changes = [
            'oldvalue' => $oldvalue,
            'newvalue' => $newvalue,
        ];

        // In some cases, formvalues are ids of users, we make them readable.
        if (in_array($fieldname, $areaswithuseridstoresolve)) {
            $oldvaluestring = "";
            $newvaluestring = "";

            if (!empty($oldvalue)) {
                if (is_array($oldvalue)) {
                    foreach ($oldvalue as $userid) {
                        $ov = $this->resolve_userid_as_readable_personparams((int) $userid, $oldvaluestring);
                    };
                } else {
                    $ov = $this->resolve_userid_as_readable_personparams((int) $oldvalue, $oldvaluestring);
                }
                if (!$ov) {
                    $oldvaluestring = $oldvalue;
                }
            }
            if (!empty($newvalue)) {
                if (is_array($newvalue)) {
                    foreach ($newvalue as $userid) {
                        $nv = $this->resolve_userid_as_readable_personparams((int) $userid, $newvaluestring);
                    };
                } else {
                    $nv = $this->resolve_userid_as_readable_personparams((int) $newvalue, $newvaluestring);
                }
                if (!$nv) {
                    $newvaluestring = $newvalue;
                }
            }

            $changes['oldvalue'] = $oldvaluestring;
            $changes['newvalue'] = $newvaluestring;
        } else if (in_array($fieldname, $areaswithtimestampstoresolve)) {
            // In some cases, values are timestamps that need to be made human readable.
            $changes['oldvalue'] = empty($oldvalue) ? "" : userdate($oldvalue, get_string('strftimedatetime', 'langconfig'));
            $changes['newvalue'] = empty($newvalue) ? "" : userdate($newvalue, get_string('strftimedatetime', 'langconfig'));
        } else if (in_array($fieldname, $checkboxvalues)) {
            // In some cases, values are 1/0 meaning on/off.
            $changes['oldvalue'] = empty($oldvalue) ? get_string('off', 'mod_booking') : get_string('on', 'mod_booking');
            $changes['newvalue'] = empty($newvalue) ? get_string('off', 'mod_booking') : get_string('on', 'mod_booking');
        }

        $changes['fieldname'] = get_string($fieldname, 'mod_booking');
        return $changes;
    }

    /**
     * Appends the information about a given user(id) to the string.
     *
     * @param int $userid
     * @param string $returnvalue
     *
     * @return bool
     *
     */
    private function resolve_userid_as_readable_personparams(int $userid, string &$returnvalue) {
        $user = singleton_service::get_instance_of_user((int)$userid);
        if (empty($user)) {
            return false;
        }
        $returnvalue .= empty($returnvalue) ? "" : ", ";
        $returnvalue .= get_string('userinfosasstring', 'mod_booking', $user);
        return true;
    }
}
