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
use MoodleQuickForm;
use stdClass;

/**
 * Class to handle one property of the booking_option_settings class.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class location extends field_base {

    /**
     * This ID is used for sorting execution.
     * @var int
     */
    public static $id = MOD_BOOKING_OPTION_FIELD_LOCATION;

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
     * @return array // If no warning, empty string.
     */
    public static function prepare_save_field(
        stdClass &$formdata,
        stdClass &$newoption,
        int $updateparam,
        $returnvalue = null): array {

        if (!class_exists('local_entities\entitiesrelation_handler')) {
            parent::prepare_save_field($formdata, $newoption, $updateparam, '');
            $instance = new location();
            $changes = $instance->check_for_changes($formdata, $instance);
            return $changes;
        } else {
            return [];
        }
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

        global $DB, $CFG;

        // We don't show the location and address fields if we have entities installed.
        if (!class_exists('local_entities\entitiesrelation_handler')) {
            // Standardfunctionality to add a header to the mform (only if its not yet there).
            if ($applyheader) {
                fields_info::add_header_to_mform($mform, self::$header);
            }

            // Location.
            $sql = 'SELECT DISTINCT location FROM {booking_options} ORDER BY location';
            $locationarray = $DB->get_fieldset_sql($sql);

            $locationstrings = [];
            foreach ($locationarray as $item) {
                $locationstrings[$item] = $item;
            }

            $options = [
                    'noselectionstring' => get_string('nolocationselected', 'mod_booking'),
                    'tags' => true,
            ];
            $mform->addElement('autocomplete', 'location', get_string('location', 'mod_booking'), $locationstrings, $options);
            if (!empty($CFG->formatstringstriptags)) {
                $mform->setType('location', PARAM_TEXT);
            } else {
                $mform->setType('location', PARAM_CLEANHTML);
            }
            $mform->addHelpButton('location', 'location', 'mod_booking');
        }
    }
}
