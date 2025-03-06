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

use local_shopping_cart\shopping_cart_handler;
use mod_booking\booking_option_settings;
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
class shoppingcart extends field_base {

    /**
     * This ID is used for sorting execution.
     * @var int
     */
    public static $id = MOD_BOOKING_OPTION_FIELD_SHOPPPINGCART;

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
    public static $alternativeimportidentifiers = [
        'sch_allowinstallment',
    ];

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

        if (class_exists('local_shopping_cart\shopping_cart_handler')) {

            // We only run this line to make sure we have the constants.
            $schhandler = new shopping_cart_handler('mod_booking', 'option');
            parent::prepare_save_field($formdata, $newoption, $updateparam, 0);
            $instance = new shoppingcart();
            $changes = $instance->check_for_changes($formdata, $instance);
            return $changes;
        }
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

        if (class_exists('local_shopping_cart\shopping_cart_handler')) {

            // We only run this line to make sure we have the constants.
            $schhandler = new shopping_cart_handler('mod_booking', 'option');

            $schhandler->definition($mform, $formdata);
        }
    }

    /**
     * This function adds error keys for form validation.
     * @param array $data
     * @param array $files
     * @param array $errors
     * @return void
     */
    public static function validation(array $data, array $files, array &$errors) {
        if (class_exists('local_shopping_cart\shopping_cart_handler')) {

            // We only run this line to make sure we have the constants.
            $schhandler = new shopping_cart_handler('mod_booking', 'option');

            $schhandler->validation($data, $errors);
        }
    }

    /**
     * The save data function is very specific only for those values that should be saved...
     * ... after saving the option. This is so, when we need an option id for saving (because of other table).
     * @param stdClass $formdata
     * @param stdClass $option
     * @param int $index
     * @return void
     * @throws \dml_exception
     */
    public static function save_data(stdClass &$formdata, stdClass &$option, int $index = 0) {

        if (class_exists('local_shopping_cart\shopping_cart_handler')) {

            // We only run this line to make sure we have the constants.
            $schhandler = new shopping_cart_handler('mod_booking', 'option');
            $schhandler->save_data($formdata, $option);
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

        if (class_exists('local_shopping_cart\shopping_cart_handler')) {

            // We only run this line to make sure we have the constants.
            $schhandler = new shopping_cart_handler('mod_booking', 'option', $data->id);
            $schhandler->set_data($data);
        }
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

        $classname = 'shoppingcart';
        if (in_array($classname, $excludeclassesfromtrackingchanges)) {
            return [];
        }
        $formvalues = (array) $formdata;
        $keys = preg_grep('/^sch_/', array_keys($formvalues));

        foreach ($keys as $key) {
            $newvalue = $formdata->$key;
            if (!empty($formdata->id) && isset($value)) {
                $settings = singleton_service::get_instance_of_booking_option_settings($formdata->id);
                $mockdata = new stdClass();
                $mockdata->id = $formdata->id;
                $self::set_data($mockdata, $settings);
                $oldvalue = $mockdata->$key;
            }

            if (
                $oldvalue != $newvalue
                && !(empty($oldvalue) && empty($newvalue))
                ) {
                    // If change was found in any of the shoppingcart fields, return this generic information.
                    return [
                        'changes' => [
                            'fieldname' => 'shoppingcart',
                        ],
                    ];
            }
            // TODO: Track changes for each key like in customfields / pollurl.
        }
        // No changes were found, so array is empty.
        return [];
    }

}
