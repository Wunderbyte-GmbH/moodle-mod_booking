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

use core_course_external;
use mod_booking\booking_option_settings;
use mod_booking\option\fields_info;
use mod_booking\option\field_base;
use MoodleQuickForm;
use stdClass;

/**
 * Class to handle one property of the booking_option_settings class.
 *
 * Courseendtime is fully replaced with the optiondates class.
 * Its only here as a placeholder.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bookingclosingtime extends field_base {

    /**
     * This ID is used for sorting execution.
     * @var int
     */
    public static $id = MOD_BOOKING_OPTION_FIELD_BOOKINGCLOSINGTIME;

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
    public static $alternativeimportidentifiers = [];

    /**
     * This is an array of incompatible field ids.
     * @var array
     */
    public static $incompatiblefields = [
        MOD_BOOKING_OPTION_FIELD_EASY_BOOKINGCLOSINGTIME,
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
        $returnvalue = null): array {

        $key = fields_info::get_class_name(static::class);
        $value = $formdata->{$key} ?? null;

        $instance = new bookingclosingtime();
        $changes = $instance->check_for_changes($formdata, $instance, null, $key, $value);

        if (empty($formdata->restrictanswerperiodclosing)) {
            $newoption->{$key} = 0;
            $formdata->restrictanswerperiodclosing = 0;
            if (empty($changes['changes']['oldvalue'])) {
                return [];
            }
            $changes['changes']['newvalue'] = 0;
        } else {
            if (!empty($value)) {
                $newoption->{$key} = $value;
                if (!empty($formdata->bo_cond_booking_time_sqlfiltercheck)) {
                    $newoption->sqlfilter = MOD_BOOKING_SQL_FILTER_ACTIVE_BO_TIME;
                } else if (!isset($newoption->sqlfilter)) {
                    $newoption->sqlfilter = MOD_BOOKING_SQL_FILTER_INACTIVE;
                }
            } else {
                $newoption->{$key} = 0;
            }
        }

        // We can return an changes here.
        return $changes;
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
     * Standard function to transfer stored value to form.
     * @param stdClass $data
     * @param booking_option_settings $settings
     * @return void
     * @throws dml_exception
     */
    public static function set_data(stdClass &$data, booking_option_settings $settings) {

        $key = fields_info::get_class_name(static::class);

        // Importing needs special treatment.
        if (!empty($data->importing)) {
            if (isset($data->{$key})) {
                $value = strtotime($data->{$key}, time());
                $data->{$key} = $value;
                $data->restrictanswerperiodclosing = 1;
            }
            if (($data->sqlfilter ?? 0) == MOD_BOOKING_SQL_FILTER_ACTIVE_BO_TIME) {
                $data->bo_cond_booking_time_sqlfiltercheck = 1;
            }

        } else {

            // Normally, we don't call set data after the first time loading.
            if (isset($data->{$key}) && !empty($data->{$key})) {
                $data->restrictanswerperiodclosing = 1;
                return;
            }

            $value = $settings->{$key} ?? null;

            $data->{$key} = $value;

            // We need to also set the checkbox correctly.
            if (!empty($value)) {
                $data->restrictanswerperiodclosing = 1;
            }
            if ($settings->sqlfilter == MOD_BOOKING_SQL_FILTER_ACTIVE_BO_TIME) {
                $data->bo_cond_booking_time_sqlfiltercheck = 1;
            }
        }
    }
}
