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

use mod_booking\bo_availability\bo_info;
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
class enrolmentstatus extends field_base {
    /**
     * This ID is used for sorting execution.
     * @var int
     */
    public static $id = MOD_BOOKING_OPTION_FIELD_ENROLMENTSTATUS;

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
    public static $alternativeimportidentifiers = [
        '',
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
        $returnvalue = null
    ): array {

        parent::prepare_save_field($formdata, $newoption, $updateparam, 0);

        $instance = new enrolmentstatus();
        $changes = $instance->check_for_changes($formdata, $instance);

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

        $mform->addElement(
            'advcheckbox',
            'enrolmentstatus',
            get_string('enrolmentstatus', 'mod_booking'),
            '',
            ['group' => 1],
            [2, 0]
        );
        $mform->setType('enrolmentstatus', PARAM_INT);
        $mform->setDefault('enrolmentstatus', 2);
        $mform->addHelpButton('enrolmentstatus', 'enrolmentstatus', 'mod_booking');
        $mform->hideIf('enrolmentstatus', 'selflearningcourse', 'eq', 1);
    }

    /**
     * Standard function to transfer stored value to form.
     * @param stdClass $data
     * @param booking_option_settings $settings
     * @return void
     * @throws dml_exception
     */
    public static function set_data(stdClass &$data, booking_option_settings $settings) {

        // Availability normally comes from settings, but it might come from the importer as well.
        if (!empty($data->importing)) {
            $data->enrolmentstatus = $data->enrolmentstatus ?? 2; // Default is always 2.
        } else {
            if (!empty($data->enrolmentstatus) && $data->enrolmentstatus == 1) {
                // phpcs:ignore moodle.Commenting.TodoComment.MissingInfoInline
                // TODO: Fix course inscription.
                // There is a fundamentally flawed way of inscribing users to courses.
                // As long as this has not been fixed, we need to set this value to 0.
                // Else, we would switch from "inscription at course start" to immediate inscription implicitly.
                $data->enrolmentstatus = 0;
            }

            $value = $settings->enrolmentstatus ?? 2;
            $data->enrolmentstatus = $value;
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

        $classname = 'enrolementstatus';
        if (in_array($classname, $excludeclassesfromtrackingchanges)) {
            return [];
        }

        $changes = [];
        $value = $formdata->enrolmentstatus;

        $mockdata = empty($mockdata) ? new stdClass() : $mockdata;

        // Check if there were changes and return these.
        if (!empty($formdata->id) && isset($value)) {
            $settings = singleton_service::get_instance_of_booking_option_settings($formdata->id);
            $self::set_data($mockdata, $settings);

            $oldvalue = $mockdata->enrolmentstatus;
            $newvalue = $value;

            if (
                $oldvalue != $newvalue
                && !(empty($oldvalue) && empty($newvalue))
            ) {
                $changes = [
                    'changes' => [
                        'fieldname' => $classname,
                        'oldvalue' => empty($oldvalue) ? 0 : 1,
                        'newvalue' => empty($newvalue) ? 0 : 1,
                        'formkey' => 'enrolmentstatus',
                    ],
                ];
            }
        }
        return $changes;
    }
}
