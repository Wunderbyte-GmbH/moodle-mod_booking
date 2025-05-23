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

use mod_booking\booking_option_settings;
use mod_booking\option\fields_info;
use mod_booking\option\field_base;
use mod_booking\singleton_service;
use MoodleQuickForm;
use stdClass;
use dml_exception;

/**
 * Class to handle one property of the booking_option_settings class.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class invisible extends field_base {
    /**
     * This ID is used for sorting execution.
     * @var int
     */
    public static $id = MOD_BOOKING_OPTION_FIELD_INVISIBLE;

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
    public static $header = MOD_BOOKING_HEADER_GENERAL;

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
    public static $incompatiblefields = [];

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

        parent::prepare_save_field($formdata, $newoption, $updateparam, 0);

        $instance = new invisible();
        $changes = $instance->check_for_changes($formdata, $instance);

        // Set the timemadevisible timestamp.
        $change = reset($changes);
        if ($formdata->optionid == 0) {
            // The option is new.
            $newoption->timemadevisible = time();
        } else if (
            $change['fieldname'] == 'invisible'
            && in_array($change['oldvalue'], [1, 2]) // Was invisible.
            && $change['newvalue'] == 0 // Was set to visible.
        ) {
            // The option was set from invisible to visible.
            $newoption->timemadevisible = time();
        } else {
            // In all other cases, we use the timecreated value.
            $settings = singleton_service::get_instance_of_booking_option_settings($formdata->optionid);
            if (empty($settings->timemadevisible)) {
                if (!empty($settings->timecreated)) {
                    $newoption->timemadevisible = $settings->timecreated;
                } else {
                    // Fallback if timecreated is 0.
                    $newoption->timemadevisible = $settings->timemodified;
                }
            }
        }

        return $changes;
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

        // Standardfunctionality to add a header to the mform (only if its not yet there).
        if ($applyheader) {
            fields_info::add_header_to_mform($mform, self::$header);
        }

        $optionid = $formdata['id'] ?? $formdata['optionid'] ?? 0;

        // Visibility.
        $visibilityoptions = [
            0 => get_string('optionvisible', 'mod_booking'),
            1 => get_string('optioninvisible', 'mod_booking'),
            2 => get_string('optionvisibledirectlink', 'mod_booking'),
        ];
        $mform->addElement('select', 'invisible', get_string('optionvisibility', 'mod_booking'), $visibilityoptions);
        $mform->setType('invisible', PARAM_INT);
        $mform->setDefault('invisible', 0);
        $mform->addHelpButton('invisible', 'optionvisibility', 'mod_booking');

        if (!empty($optionid)) {
            $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
            $timemadevisible = $settings->timemadevisible ?? 0;
            if (!empty($timemadevisible)) {
                if ($settings->invisible == 0) {
                    $readabletimemadevisible = userdate($timemadevisible, get_string('strftimedaydatetime', 'langconfig'));
                    $mform->addElement(
                        'html',
                        '<div class="bookingoption-form-timemadevisible text-muted small ml-4 mb-3">'
                        . get_string('timemadevisible', 'mod_booking') . ": "
                        . $readabletimemadevisible . '</div>'
                    );
                }
            }
        }
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
}
