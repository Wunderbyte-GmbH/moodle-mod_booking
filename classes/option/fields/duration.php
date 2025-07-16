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
 * Class for field 'duration'.
 *
 * @package mod_booking
 * @copyright 2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer-Sengseis
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\option\fields;

use mod_booking\booking_option;
use mod_booking\booking_option_settings;
use mod_booking\option\fields_info;
use mod_booking\option\field_base;
use mod_booking\utils\wb_payment;
use moodle_url;
use MoodleQuickForm;
use stdClass;

/**
 * Class for field 'duration'.
 *
 * @package mod_booking
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer-Sengseis
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class duration extends field_base {
    /**
     * This ID is used for sorting execution.
     * @var int
     */
    public static $id = MOD_BOOKING_OPTION_FIELD_DURATION;

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
    public static $header = MOD_BOOKING_HEADER_COURSES;

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
     * @return array// If no warning, empty array.
     */
    public static function prepare_save_field(
        stdClass &$formdata,
        stdClass &$newoption,
        int $updateparam,
        $returnvalue = null
    ): array {
        if (
            !empty($formdata->duration)
            && ($formdata->selflearningcourse ?? false)
        ) {
            $newoption->duration = $formdata->duration ?? 0;
        } else {
            $newoption->duration = 0;
        }

        if (isset($formdata->selflearningcourse)) {
            booking_option::add_data_to_json($newoption, "selflearningcourse", $formdata->selflearningcourse);
        }
        $instance = new duration();
        $mockdata = new stdClass();
        $mockdata->id = $formdata->id;
        $changes = $instance->check_for_changes($formdata, $instance, $mockdata);
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
        $proversion = wb_payment::pro_version_is_activated();
        $optionid = $formdata['id'];

        // Standardfunctionality to add a header to the mform (only if its not yet there).
        if ($applyheader) {
            fields_info::add_header_to_mform($mform, self::$header);
        }

        // PRO feature: Self-learning courses - booking options with a duration.
        $showlinktosettings = false;
        if ($proversion) {
            $selflearningcourseactive = (int)get_config('booking', 'selflearningcourseactive');
            if (!$selflearningcourseactive) {
                // If PRO is active, but setting is not yet on, we want to show a link to config settings.
                $showlinktosettings = true;
            }
        } else {
            $selflearningcourseactive = 0;
        }
        $mform->addElement('hidden', 'selflearningcourseactive', $selflearningcourseactive);
        $mform->setType('selflearningcourseactive', PARAM_INT);

        $selflearningcourselabel = get_string('selflearningcourse', 'mod_booking');
        // The label can be overwritten in plugin config.
        if (!empty(get_config('booking', 'selflearningcourselabel'))) {
            $selflearningcourselabel = get_config('booking', 'selflearningcourselabel');
        }

        // Add checkbox to mark self-learning courses.
        $mform->addElement(
            'advcheckbox',
            'selflearningcourse',
            $selflearningcourselabel . " " . get_string('badge:pro', 'mod_booking'),
            null,
            null,
            [0, 1]
        );
        $mform->setDefault('selflearningcourse', 0);
        if ($showlinktosettings) {
            // If PRO version is active, but selflearningcourse is not, we show a link to config settings.
            $mform->addHelpButton(
                'selflearningcourse',
                'turnthisoninsettings',
                'mod_booking',
                '',
                false,
                new moodle_url(
                    '/admin/settings.php',
                    ['section' => 'modsettingbooking'],
                    'admin-selflearningcourseactive'
                )
            );
        } else {
            // Else we show the normal help text.
            $mform->addHelpButton('selflearningcourse', 'selflearningcourse', 'mod_booking', '', false, $selflearningcourselabel);
        }
        $mform->disabledIf('selflearningcourse', 'selflearningcourseactive', 'neq', 1);

        if ($selflearningcourseactive === 1) {
            $mform->addElement(
                'static',
                'selflearningcoursealert',
                '',
                '<div class="alert alert-light">' .
                    get_string('selflearningcoursealert', 'mod_booking', $selflearningcourselabel) .
                '</div>'
            );
            $mform->hideIf('selflearningcoursealert', 'selflearningcourse');
        }

        // Add duration.
        $mform->addElement('duration', 'duration', get_string('duration', 'mod_booking'));
        $mform->setType('duration', PARAM_INT);
        $mform->setDefault('duration', 2592000); // 30 days.
        $mform->hideIf('duration', 'selflearningcourse', 'neq', 1);
    }

    /**
     * Standard function to transfer stored value to form.
     * @param stdClass $data
     * @param booking_option_settings $settings
     * @return void
     * @throws dml_exception
     */
    public static function set_data(stdClass &$data, booking_option_settings $settings) {

        if (!empty($data->importing)) {
            $data->selflearningcourse = $data->selflearningcourse
                ?? $settings->selflearningcourse ?? 0;
        } else {
            $selflearningcourse = $settings->selflearningcourse;
            if (!empty($selflearningcourse)) {
                $data->selflearningcourse = $selflearningcourse;
            }
        }

        // Normally, we don't call set data after the first time loading.
        if (isset($data->duration)) {
            return;
        }
        $data->duration = $settings->duration ?? 0;
    }

    /**
     * This function adds error keys for form validation.
     * @param array $data
     * @param array $files
     * @param array $errors
     * @return array
     */
    public static function validation(array $data, array $files, array &$errors) {
        // Check if we have dates set by checking if there are keys starting with "optiondate_".
        if (!empty($data['selflearningcourse'])) {
            $keys = preg_grep('/^optiondateid_/', array_keys($data));
            if (!empty($keys)) {
                $selflearningcourselabel = get_string('selflearningcourse', 'mod_booking');
                // The label can be overwritten in plugin config.
                if (!empty(get_config('booking', 'selflearningcourselabel'))) {
                    $selflearningcourselabel = get_config('booking', 'selflearningcourselabel');
                }
                $errors['selflearningcourse'] = get_string(
                    'error:selflearningcourseallowsnodates',
                    'mod_booking',
                    $selflearningcourselabel
                );
            }
        }
        return $errors;
    }
}
