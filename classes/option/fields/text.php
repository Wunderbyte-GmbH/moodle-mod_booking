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
class text extends field_base {
    /**
     * This ID is used for sorting execution.
     * @var int
     */
    public static $id = MOD_BOOKING_OPTION_FIELD_TEXT;

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
    public static $alternativeimportidentifiers = ['name'];

    /**
     * This is an array of incompatible field ids.
     * @var array
     */
    public static $incompatiblefields = [MOD_BOOKING_OPTION_FIELD_EASY_TEXT];

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

        parent::prepare_save_field($formdata, $newoption, $updateparam, '');

        $mockdata = new stdClass();
        $mockdata->id = $formdata->id;
        $instance = new text();
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

        global $CFG, $COURSE;

        // Standardfunctionality to add a header to the mform (only if its not yet there).
        if ($applyheader) {
            fields_info::add_header_to_mform($mform, self::$header);
        }

        $booking = singleton_service::get_instance_of_booking_by_bookingid($formdata['bookingid'] ?? 0);

        // Booking option name.
        $mform->addElement('text', 'text', get_string('bookingoptionname', 'mod_booking'), ['size' => '64']);
        $mform->addRule('text', get_string('required'), 'required', null, 'client');
        $mform->addRule('text', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('text', PARAM_TEXT);
        } else {
            $mform->setType('text', PARAM_CLEANHTML);
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

        global $COURSE;

        // Normally, we don't call set data after the first time loading.
        // The ID will only be emtpy on creating a new booking option.
        if (empty($data->importing)) {
            if (empty($data->id)) {
                $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($data->cmid);
                // Add standard name here.
                $eventtype = $bookingsettings->eventtype;
                if ($eventtype && strlen($eventtype) > 0) {
                    $eventtype = "- $eventtype ";
                } else {
                    $eventtype = '';
                }
                $value = "$COURSE->fullname $eventtype";
                $data->text = $value;
            } else if (!isset($data->text)) {
                // We only set name from settings if text was not already transmitted.
                $key = fields_info::get_class_name(static::class);
                $value = $settings->text ?? null;
                $data->{$key} = $value;
            }
        } else {
            $key = fields_info::get_class_name(static::class);
            $data->{$key} = $data->text ?? $data->title ?? $data->name ?? get_string('novalidtitlefound', 'mod_booking');
        }
    }
}
