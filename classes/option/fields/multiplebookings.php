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
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Mahdi Poustini
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class multiplebookings extends field_base {
    /**
     * This ID is used for sorting execution.
     * @var int
     */
    public static $id = MOD_BOOKING_OPTION_FIELD_MULTIPLEBOOKINGS;

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

        // We store the information fro the multiplebookings option in the JSON.
        // So this has to happen BEFORE JSON is saved!
        if (empty($formdata->multiplebookings)) {
            // This will store the correct JSON to $optionvalues->json.
            booking_option::add_data_to_json($newoption, "multiplebookings", 0); // 0 means could not have multiple bookings.
            booking_option::add_data_to_json($newoption, "allowtobookagainafter", 0); // 0 means could not have multiple bookings.
        } else {
            booking_option::add_data_to_json($newoption, "multiplebookings", 1); // 1 means can have multiple bookings.
            booking_option::add_data_to_json($newoption, "allowtobookagainafter", $formdata->allowtobookagainafter ?? 0);
        }

        parent::prepare_save_field($formdata, $newoption, $updateparam, '');

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

        // Standardfunctionality to add a header to the mform (only if its not yet there).
        if ($applyheader) {
            fields_info::add_header_to_mform($mform, self::$header);
        }

        $mform->addElement(
            'advcheckbox',
            'multiplebookings',
            get_string('multiplebookings', 'mod_booking'),
            null,
            null,
            [0, 1]
        );

        $mform->addElement(
            'duration',
            'allowtobookagainafter',
            get_string('allowtobookagainafter', 'mod_booking')
        );
        $mform->hideIf('allowtobookagainafter', 'multiplebookings', 'neq', '1');
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

        // Normally, we don't call set data after the first time loading.
        if (isset($data->multiplebookings)) {
            return;
        }

        // Fetch the original value from the DB, because in settings object might be overwritten by campaigns.
        if (!empty($settings->id)) {
            // Load sync status from JSON (default to 0 if not found).
            $multiplebookings = booking_option::get_value_of_json_by_key($settings->id, 'multiplebookings') ?? 0;
            $data->multiplebookings = (int)$multiplebookings;

            $allowtobookagainafter = booking_option::get_value_of_json_by_key($settings->id, 'allowtobookagainafter') ?? 0;
            $data->allowtobookagainafter = (int)$allowtobookagainafter;
        }
    }
}
