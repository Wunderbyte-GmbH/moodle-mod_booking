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
use mod_booking\singleton_service;
use moodle_exception;
use MoodleQuickForm;
use stdClass;

/**
 * Class to handle one property of the booking_option_settings class.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class prepare_import extends field_base {

    /**
     * This ID is used for sorting execution.
     * @var int
     */
    public static $id = MOD_BOOKING_OPTION_FIELD_PREPARE_IMPORT;

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
    public static $fieldcategories = [
        MOD_BOOKING_OPTION_FIELD_NECESSARY,
    ];

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

        $addastemplate = optional_param('addastemplate', 0, PARAM_INT) ?? 0;

        $addastemplate = $addastemplate = optional_param('addastemplate', 0, PARAM_INT) ?? 0;
        if (!empty($addastemplate)) {
            $formdata['addastemplate'] = $addastemplate;
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

        global $DB;

        // Here, we determine if we need fetch data from an existing booking option.
        // We can only do that if we have an identifier.
        // Coming from the form, we'll have even with a new option id set to 0.
        if (!isset($data->id) && !empty($data->identifier)) {
            $data->importing = true;
            if ($record = $DB->get_record('booking_options', ['identifier' => $data->identifier])) {

                $data->id = $record->id;

            } else if (empty($data->text) && empty($data->name)) {
                throw new moodle_exception(
                    'identifiernotfoundnotenoughdata',
                    'mod_booking',
                    '',
                    $data->identifier,
                    "The record with the identifier $data->identifier was not found in db");
            }
        }

        // If there is no bookingid but there is the cmid, we can work with that.
        if (empty($data->bookingid) && !empty($data->cmid)) {

            $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($data->cmid);
            $data->bookingid = $bookingsettings->id;
        }
        $addastemplate = $data->addastemplate = optional_param('addastemplate', 0, PARAM_INT) ?? 0;
        if (!empty($addastemplate)) {
            $data->addastemplate = $addastemplate;
        }
        // We will always set id to 0, if it's not set yet.
        if (!isset($data->id)) {
            $data->id = 0;
        }
    }
}
