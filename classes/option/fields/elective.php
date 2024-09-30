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

use dml_exception;
use mod_booking\booking_option_settings;
use mod_booking\elective as Mod_bookingElective;
use mod_booking\option\fields_info;
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
class elective extends field_base {

    /**
     * This ID is used for sorting execution.
     * @var int
     */
    public static $id = MOD_BOOKING_OPTION_FIELD_ELECTIVE;
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
    public static $header = MOD_BOOKING_HEADER_ELECTIVE;
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

        $value = $formdata->mustcombine ?? null;

        if (!empty($value)) {
            $newoption->mustcombine = $value;
        } else {
            $newoption->mustcombine = '';
        }

        $value = $formdata->mustnotcombine ?? null;

        if (!empty($value)) {
            $newoption->mustnotcombine = $value;
        } else {
            $newoption->mustnotcombine = '';
        }

        $value = $formdata->sortorder ?? null;

        if (!empty($value)) {
            $newoption->sortorder = $value;
        } else {
            $newoption->sortorder = 0;
        }

        $booking = singleton_service::get_instance_of_booking_by_cmid($formdata->cmid);

        // On an elective option, we always set enrolementstatus to 0.
        if (!empty($booking->settings->iselective)) {
            $newoption->enrolmentstatus = 0;
        }

        // We can return an warning message here.
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

        // Add elective mform elements..
        Mod_bookingElective::instance_option_form_definition($mform, $formdata);

    }

    /**
     * Standard function to transfer stored value to form.
     * @param stdClass $data
     * @param booking_option_settings $settings
     * @return void
     * @throws dml_exception
     */
    public static function set_data(stdClass &$data, booking_option_settings $settings) {

        $value = $settings->sortorder ?? null;
        $data->sortorder = $value;

        Mod_bookingElective::option_form_set_data($data);
    }

    /**
     * Save data
     *
     * @param stdClass $formdata
     * @param stdClass $option
     * @return void
     * @throws dml_exception
     */
    public static function save_data(stdClass &$formdata, stdClass &$option) {

        $booking = singleton_service::get_instance_of_booking_by_cmid($formdata->cmid);

        // Save combination arrays to DB.
        if (!empty($booking->settings->iselective)) {
            Mod_bookingElective::addcombinations($option->id, $formdata->mustcombine, 1);
            Mod_bookingElective::addcombinations($option->id, $formdata->mustnotcombine, 0);
        }
    }
}
