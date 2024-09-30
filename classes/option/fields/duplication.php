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
use mod_booking\option\fields;
use mod_booking\option\fields_info;
use mod_booking\option\field_base;
use mod_booking\singleton_service;
use mod_booking\utils\wb_payment;
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
class duplication extends field_base {

    /**
     * This ID is used for sorting execution.
     * @var int
     */
    public static $id = MOD_BOOKING_OPTION_FIELD_DUPLICATION;

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
    public static $fieldcategories = [MOD_BOOKING_OPTION_FIELD_NECESSARY];

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

        return parent::prepare_save_field($formdata, $newoption, $updateparam, '');
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

        // No definition needed.
    }

    /**
     * Standard function to transfer stored value to form.
     * @param stdClass $data
     * @param booking_option_settings $settings
     * @return void
     * @throws dml_exception
     */
    public static function set_data(stdClass &$data, booking_option_settings $settings) {

        if (!empty($data->id)) {
            return;
        }

        if (!empty($data->copyoptionid)) {
            // First, retrieve the template we want to use.

            $optionid = $data->copyoptionid;
            // Now, we need to create the data for this option the same way we would create it otherwise...
            $templateoption = (object)[
                'cmid' => $data->cmid,
                'id' => $optionid, // In the context of option_form class, id always refers to optionid.
                'optionid' => $optionid, // Just kept on for legacy reasons.
                'bookingid' => $data->bookingid,
                'copyoptionid' => 0, // Do NOT set it here as we might get stuck in a loop.
                'oldcopyoptionid' => $data->copyoptionid ?? 0,
                'returnurl' => '',
            ];

            fields_info::set_data($templateoption);

            // Now we have the templateoption fully prepared...
            // ... we can just replace a few values and continue working from here with this object.
            $excluded = [
                'id',
                'optionid',
                'cmid',
                'bookingid',
                'returnurl',
                'identifier',
                'sesskey',
            ];

            foreach ($templateoption as $key => $value) {

                if (strpos($key, MOD_BOOKING_FORM_OPTIONDATEID) !== false) {

                    $data->{$key} = 0;

                } else if (!in_array($key, $excluded)) {
                    $data->{$key} = $value;
                }
            }
            throw new moodle_exception('loadtemplate', 'mod_booking');
        }
    }
}
