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
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\option\fields;

use mod_booking\booking_option;
use mod_booking\booking_option_settings;
use mod_booking\option\fields_info;
use mod_booking\option\field_base;
use MoodleQuickForm;
use stdClass;

/**
 * Class to handle one property of the booking_option_settings class.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Magdalena Holczik
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class respondapi extends field_base {
    /**
     * This ID is used for sorting execution.
     * @var int
     */
    public static $id = MOD_BOOKING_OPTION_FIELD_RESPONDAPI;

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
    public static $header = MOD_BOOKING_HEADER_RESPONDAPI;

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
     * @return array
     */
    public static function prepare_save_field(
        stdClass &$formdata,
        stdClass &$newoption,
        int $updateparam,
        $returnvalue = null
    ): array {

        if (!get_config('booking', 'marmara_enabled')) {
            return [];
        }

        return [];


        // check if checkbox is ticked
        // check if id is empty
        // if it's ticked AND id is empty, contact marmara server
        // save the return value!

        // booking_option::add_data_to_json($newoption, 'mynewcriteriaid', $mynewcriteriaid);


        //get_config('booking', 'marmara_enabled')
        // $instance = new certificate();
        /* $changes = [];
        $key = fields_info::get_class_name(static::class);
        $value = $formdata->{$key} ?? null;

        if (!empty($value)) {
            booking_option::add_data_to_json($newoption, $key, $formdata->{$key});
        } else {
            booking_option::remove_key_from_json($newoption, $key);
        }

        // Add expiration key to json.
        $keys = self::$certificatedatekeys;
        // Process each field and save it to json.
        foreach ($keys as $key) {
            $valueexpirydate = $formdata->{$key} ?? null;

            if (!empty($valueexpirydate)) {
                booking_option::add_data_to_json($newoption, $key, $formdata->{$key});
            } else {
                booking_option::remove_key_from_json($newoption, $key);
            }
        }

        $certificatechanges = $instance->check_for_changes($formdata, $instance, null, $key, $value);
        if (!empty($certificatechanges)) {
            $changes[$key] = $certificatechanges;
        };

        // We can return an warning message here.
        return ['changes' => $changes]; */
    }

    /**
     * Instance form definition
     * @param MoodleQuickForm $mform
     * @param array $formdata
     * @param array $optionformconfig
     * @param array $fieldstoinstanciate
     * @param bool $applyheader
     * @return void
     *
     */
    public static function instance_form_definition(
        MoodleQuickForm &$mform,
        array &$formdata,
        array $optionformconfig,
        $fieldstoinstanciate = [],
        $applyheader = true
    ) {

        /*if (!class_exists('tool_certificate\certificate')) {
            return;
        }*/

        global $DB;

        // Standardfunctionality to add a header to the mform (only if its not yet there).
        if ($applyheader) {
            fields_info::add_header_to_mform($mform, self::$header);
        }

        // $mform->addElement('text', 'myidentifier', 'mylabel', 'mydefaulttext', 'x');
        // $mform->setDefault('myidentifier', 'x');
        // $mform->setType('myidentifier', PARAM_TEXT);


        // add the checkbox if it should syn
        // add a text element keyword id.
        // add rule to hide it if checkobx is not set to sync


        $mform->addElement('advcheckbox', 'enablemarmarasync', get_string('marmara:sync', 'mod_booking'));
        $mform->setType('enablemarmarasync', PARAM_INT);
        $mform->setDefault('enablemarmarasync', 0);

        $keywordid = 13245;
        // Add the disabled text field only if value exists.
        if (!empty($keywordid)) {
            $mform->addElement('text', 'marmaracriteriaid', get_string('marmara:keywordid', 'booking'));
            $mform->setType('marmaracriteriaid', PARAM_INT);
            $mform->disabledIf('marmaracriteriaid', 'enablemarmarasync', 'noteq', 1);
            // $mform->setDefault('marmaracriteriaid', $keywordid);
            // $mform->freeze('marmaracriteriaid'); // disables the field
            // $mform->disabledIf('marmaracriteriaid', 'marmara_sync', 'notchecked');
        }
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
     * Function to set the Data for the form.
     *
     * @param stdClass $data
     * @param booking_option_settings $settings
     *
     * @return void
     *
     */
    public static function set_data(stdClass &$data, booking_option_settings $settings) {

        // in the data object, there might be a json value
        // with the value for the checkbox? checked or not
        // and the kriteria ID (if it's there)

        // $data->marmaracriteriaid = booking_option::get_value_of_json_by_key($settings->id, 'selflearningcourse') ?? 0;
        $data->marmaracriteriaid = '454554';
    }
}
