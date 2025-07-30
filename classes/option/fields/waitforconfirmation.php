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

use mod_booking\booking_option;
use mod_booking\booking_option_settings;
use mod_booking\option\fields_info;
use mod_booking\option\field_base;
use mod_booking\singleton_service;
use MoodleQuickForm;
use stdClass;

/**
 * Class to handle one property of the booking_option_settings class.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer-Sengseis
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class waitforconfirmation extends field_base {
    /**
     * This ID is used for sorting execution.
     * @var int
     */
    public static $id = MOD_BOOKING_OPTION_FIELD_WAITFORCONFIRMATION;

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
    public static $header = MOD_BOOKING_HEADER_ADVANCEDOPTIONS;

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
        if (isset($formdata->waitforconfirmation)) {
            booking_option::add_data_to_json($newoption, "waitforconfirmation", $formdata->waitforconfirmation);
            if (isset($formdata->confirmationonnotification)) {
                booking_option::add_data_to_json($newoption, "confirmationonnotification", $formdata->confirmationonnotification);
                if (isset($formdata->confirmationonnotificationoneatatime)) {
                    booking_option::add_data_to_json(
                        $newoption,
                        "confirmationonnotificationoneatatime",
                        $formdata->confirmationonnotification
                    );
                }
            }
        }

        $instance = new waitforconfirmation();
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

        $optionid = $formdata['id'];

        // Standardfunctionality to add a header to the mform (only if its not yet there).
        if ($applyheader) {
            fields_info::add_header_to_mform($mform, self::$header);
        }

        $waitforconfirmationoptions = [
            0 => get_string('norestriction', 'mod_booking'),
            1 => get_string('waitforconfirmation', 'mod_booking'),
            2 => get_string('waitforconfirmationonwaitinglist', 'mod_booking'),
        ];

        $mform->addElement(
            'select',
            'waitforconfirmation',
            get_string('waitforconfirmation', 'mod_booking'),
            $waitforconfirmationoptions
        );

        // Confirmation on notification.
        $confirmationonnotificationoptions = [
            0 => get_string('confirmationonnotificationnoopen', 'mod_booking'),
            1 => get_string('confirmationonnotificationyesforall', 'mod_booking'),
            2 => get_string('confirmationonnotificationyesoneatatime', 'mod_booking'),
        ];
        $mform->addElement(
            'select',
            'confirmationonnotification',
            get_string('confirmationonnotification', 'mod_booking'),
            $confirmationonnotificationoptions
        );
        $mform->hideIf('confirmationonnotification', 'waitforconfirmation', 'eq', 0);

        if (!empty(get_config('booking', 'displayinfoaboutrules'))) {
            $mform->addElement(
                'static',
                'confirmationonnotificationwarning',
                '',
                get_string('confirmationonnotificationwarning', 'mod_booking')
            );
            $mform->hideIf('confirmationonnotificationwarning', 'confirmationonnotification', 'eq', 0);
        }

        $mform->addElement(
            'advcheckbox',
            'confirmationonnotificationoneatatime',
            get_string('confirmationonnotificationoneatatime', 'mod_booking')
        );
        $mform->hideIf('confirmationonnotificationoneatatime', 'confirmationonnotification', 'unchecked');
        $mform->hideIf('confirmationonnotificationoneatatime', 'waitforconfirmation', 'neq', 2);
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
            $data->waitforconfirmation = $data->waitforconfirmation
                ?? booking_option::get_value_of_json_by_key($data->id, "waitforconfirmation") ?? 0;
        } else {
            $waitforconfirmation = booking_option::get_value_of_json_by_key($data->id, "waitforconfirmation");
            if (!empty($waitforconfirmation)) {
                $data->waitforconfirmation = $waitforconfirmation;

                $confirmationonnotification = booking_option::get_value_of_json_by_key($data->id, "confirmationonnotification");
                if (!empty($confirmationonnotification)) {
                    $data->confirmationonnotification = $confirmationonnotification;
                }
                $confirmationonnotificationoneatatime = booking_option::get_value_of_json_by_key(
                    $data->id,
                    "confirmationonnotificationoneatatime"
                );
                if (!empty($confirmationonnotificationoneatatime)) {
                    $data->confirmationonnotificationoneatatime = $confirmationonnotificationoneatatime;
                }
            }
        }
    }
}
