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
use mod_booking\customfield\booking_handler;
use mod_booking\option\field_base;
use context_module;
use mod_booking\singleton_service;
use MoodleQuickForm;
use stdClass;
use moodle_exception;

/**
 * Class to handle one property of the booking_option_settings class.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class customfields extends field_base {

    /**
     * This ID is used for sorting execution.
     * @var int
     */
    public static $id = MOD_BOOKING_OPTION_FIELD_COSTUMFIELDS;

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
    public static $header = MOD_BOOKING_HEADER_CUSTOMFIELDS;

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
        $returnvalue = null): array {

        // No need to do anything here.
        return [];
    }

    /**
     * Instance form definition
     * @param MoodleQuickForm $mform
     * @param array $formdata
     * @param array $optionformconfig
     * @return void
     */
    public static function instance_form_definition(MoodleQuickForm &$mform, array &$formdata, array $optionformconfig) {

        $optionid = $formdata['id'] ?? $formdata['optionid'];

        if (!empty($formdata['cmid'])) {
            $context = context_module::instance($formdata['cmid']);
        } else if (!empty($optionid)) {
            $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
            $context = context_module::instance($settings->cmid);
        } else {
            throw new moodle_exception('customfields.php: missing context in function instance_form_definition');
        }

        // Add custom fields.
        $handler = booking_handler::create();
        $handler->instance_form_definition($mform, $optionid, null, null, $context->id);
    }

    /**
     * This function adds error keys for form validation.
     * @param array $data
     * @param array $files
     * @param array $errors
     * @return void
     */
    public static function validation(array $data, array $files, array &$errors) {

        $cfhandler = booking_handler::create();
        $errors = array_merge($errors, $cfhandler->instance_form_validation($data, $files));

    }

    /**
     * The save data function is very specific only for those values that should be saved...
     * ... after saving the option. This is so, when we need an option id for saving (because of other table).
     * @param stdClass $formdata
     * @param stdClass $option
     * @return void
     * @throws dml_exception
     */
    public static function save_data(stdClass &$formdata, stdClass &$option) {

        // This is to save customfield data
        // The id key has to be set to option id.
        $optionid = $option->id;
        $handler = booking_handler::create();
        $handler->instance_form_save($formdata, $optionid == -1);
    }

    /**
     * Standard function to transfer stored value to form.
     * @param stdClass $data
     * @param booking_option_settings $settings
     * @return void
     * @throws dml_exception
     */
    public static function set_data(stdClass &$data, booking_option_settings $settings) {

        // If we are not importing, we can just run the normal routine.
        if (empty($data->importing)) {
            $handler = booking_handler::create();
            $handler->instance_form_before_set_data($data);
        } else {
            // If we are importing, we need to make sure to correctly override the fields from the import..
            // But not touch those fields that are not present in the import.
            $handler = booking_handler::create();
            $handler->instance_form_before_set_data_on_import($data);
        }
    }

    /**
     * Every class can provide subfields.
     * @return array
     */
    public static function get_subfields() {
        $handler = booking_handler::create();
        $fields = $handler->get_fields();

        $returnarray = array_map(fn($a) => [
            'id' => $a->get('id'),
            'shortname' => $a->get('shortname'),
            'name' => $a->get('name'),
            'checked' => 1,
            'header' => $a->get_category()->get('name'),
            ], $fields);

        return $returnarray;
    }
}
