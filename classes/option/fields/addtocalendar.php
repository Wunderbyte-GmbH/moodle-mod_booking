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

use mod_booking\calendar;
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
class addtocalendar extends field_base {
    /**
     * This ID is used for sorting execution.
     * @var int
     */
    public static $id = MOD_BOOKING_OPTION_FIELD_ADDTOCALENDAR;

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
    public static $header = MOD_BOOKING_HEADER_DATES;

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

        global $DB;

        $optionid = $formdata->id;
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        // Delete calendar events if they are turned off in form.
        if ($formdata->addtocalendar == 0) {
            if ($optiondates = $DB->get_records('booking_optiondates', ['optionid' => $optionid])) {
                foreach ($optiondates as $optiondate) {
                    // Delete calendar course event for the optiondate.
                    if (
                        $DB->delete_records_select(
                            'event',
                            "eventtype = 'course'
                            AND courseid <> 0
                            AND component = 'mod_booking'
                            AND uuid = :pattern",
                            ['pattern' => "{$optionid}-{$optiondate->id}"]
                        )
                    ) {
                        $optiondate->eventid = null;
                        $DB->update_record('booking_optiondates', $optiondate);
                    }
                }
            }
        }
        parent::prepare_save_field($formdata, $newoption, $updateparam, '');

        $instance = new addtocalendar();
        $changes = $instance->check_for_changes($formdata, $instance);

        return $changes;
    }

    /**
     * Save data
     * @param stdClass $data
     * @param stdClass $option
     * @return void
     * @throws \dml_exception
     */
    public static function save_data(stdClass &$data, stdClass &$option) {

        global $DB;

        if (isset($data->addtocalendar) && $data->addtocalendar == 1) {
            $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
            // We need to make sure not to run the calendar function on a tmeplate without a cmid.
            if (
                !empty($settings->cmid)
                && ($optiondates = $DB->get_records('booking_optiondates', ['optionid' => $option->id]))
            ) {
                foreach ($optiondates as $optiondate) {
                    if ($DB->record_exists('event', ['id' => $optiondate->eventid])) {
                        continue;
                    }
                    calendar::booking_optiondate_add_to_cal($settings->cmid, $option->id, $optiondate, $settings->calendarid);
                }
            }
        };
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
        // Add header to the mform (only if its not yet there).
        if ($applyheader) {
            fields_info::add_header_to_mform($mform, self::$header);
        }

        // Add to course calendar dropdown.
        $caleventtypes = [
            0 => get_string('caldonotadd', 'mod_booking'),
            1 => get_string('caladdascourseevent', 'mod_booking'),
        ];
        $mform->addElement('select', 'addtocalendar', get_string('addtocalendar', 'mod_booking'), $caleventtypes);
        $mform->setDefault('addtocalendar', 0);
        $mform->hideIf('addtocalendar', 'selflearningcourse', 'eq', 1);

        if (get_config('booking', 'addtocalendar_locked')) {
            // If the setting is locked in settings.php it will be frozen.
            $mform->freeze('addtocalendar');
        }
    }
}
