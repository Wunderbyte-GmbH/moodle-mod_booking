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

use coding_exception;
use mod_booking\booking_option;
use mod_booking\booking_option_settings;
use mod_booking\customfield\optiondate_cfields;
use mod_booking\dates;
use mod_booking\option\dates_handler;
use mod_booking\option\fields;
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
class optiondates extends field_base {

    /**
     * This ID is used for sorting execution.
     * @var int
     */
    public static $id = MOD_BOOKING_OPTION_FIELD_OPTIONDATES;

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
    public static $alternativeimportidentifiers = [
        'coursestarttime',
        'courseendtime',
        'coursestartdate',
        'courseenddate',
        'dayofweektime',
        'semesterid',
        'starddate',
        'enddate',
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
     * @param mixed $returnvalue
     * @return string // If no warning, empty string.
     */
    public static function prepare_save_field(
        stdClass &$formdata,
        stdClass &$newoption,
        int $updateparam,
        $returnvalue = null): string {

        // Run through all dates to make sure we don't have an array.
        // We need to transform dates to timestamps.
        list($dates, $highesindex) = dates::get_list_of_submitted_dates((array)$formdata);

        foreach ($dates as $date) {

            $newoption->{'coursestarttime_' . $date['index']} = $date['coursestarttime'];
            $newoption->{'courseendtime_' . $date['index']} = $date['courseendtime'];
            $newoption->{'optiondateid_' . $date['index']} = $date['optiondateid'];
            $newoption->{'daystonotify_' . $date['index']} = $date['daystonotify'];

            // We want to set the coursestarttime to the first coursestarttime.
            if (!isset($newoption->coursestarttime)) {
                $newoption->coursestarttime = $date['coursestarttime'];
            }
            // We want to set the courseendtime to the last courseendtime.
            $newoption->courseendtime = $date['courseendtime'];;
        }

        // If there is no date left, we delete courestartdate & courseenddate.
        if (empty($dates)) {
            $newoption->coursestarttime = null;
            $newoption->courseendtime = null;
        }

        $newoption->dayofweektime = $formdata->dayofweektime ?? '';
        $dayinfo = dates_handler::prepare_day_info($formdata->dayofweektime ?? '');
        $newoption->dayofweek = $dayinfo['day'] ?? '';
        $newoption->semesterid = $formdata->semesterid ?? 0;

        // We can return a warning message here.
        return '';
    }

    /**
     * This function adds error keys for form validation.
     * @param array $data
     * @param array $files
     * @param array $errors
     */
    public static function validation(array $data, array $files, array &$errors) {

        // Run through all dates to make sure we don't have an array.
        // We need to transform dates to timestamps.
        list($dates, $highesindex) = dates::get_list_of_submitted_dates($data);

        $problems = array_filter($dates, fn($a) => $a['coursestarttime'] > $a['courseendtime']);

        foreach ($problems as $problem) {
            // TODO: Make it nice.
            $errors['courseendtime_' . $problem['index']] = get_string('problemwithdate', 'mod_booking');
        }
    }

    /**
     * Instance form definition
     * @param MoodleQuickForm $mform
     * @param array $formdata
     * @param array $optionformconfig
     * @return void
     */
    public static function instance_form_definition(MoodleQuickForm &$mform, array &$formdata, array $optionformconfig) {

        // Workaround: Only show, if it is not turned off in the option form config.
        // We currently need this, because hideIf does not work with editors.
        // In expert mode, we do not hide anything.
        if ($optionformconfig['formmode'] == 'expert' ||
            !isset($optionformconfig['datesheader']) || $optionformconfig['datesheader'] == 1) {

            $mform->addElement('hidden', 'datesmarker', 0);
            $mform->setType('datesmarker', PARAM_INT);
        }
    }

    /**
     * Save data.
     *
     * @param stdClass $formdata
     * @param stdClass $option
     * @return void
     * @throws \dml_exception
     */
    public static function save_data(stdClass &$formdata, stdClass &$option) {

        dates::save_optiondates_from_form($formdata, $option);
    }

    /**
     * Standard function to transfer stored value to form.
     *
     * @param stdClass $data
     * @param booking_option_settings $settings
     * @return void
     * @throws \dml_exception
     */
    public static function set_data(stdClass &$data, booking_option_settings $settings) {

        if (!empty($data->importing)) {
            // If we have a dayofweektime, we need to setup the semester.
            if (!empty($data->dayofweektime)) {
                // This is needed to create the new values from dayofweekstring.
                $data->addoptiondateseries = 1;
                // We need this on import, to not overrule import via settings.
                $data->datesmarker = 1;

                if (!empty($data->cmid)) {
                    $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($data->cmid);
                }

                $data->semesterid = $data->semesterid ?? $settings->semesterid ?? $bookingsettings->semesterid ?? 0;

                // If there is not semesterid to be found at this point, we abort.
                if (empty($data->semesterid)) {

                    // Todo: Make a meaningful error message to the cause of this abortion.
                    return;
                }
            }
        } else {
            $data->dayofweektime = $data->dayofweektime ?? $settings->dayofweektime ?? '';

            if (!empty($data->cmid)) {
                $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($data->cmid);
            }

            $data->semesterid = $data->semesterid ?? $settings->semesterid ?? $bookingsettings->semesterid ?? 0;
        }

        // We need to modify the data we set for dates.
        $data = dates::set_data($data);
    }

    /**
     * Definition after data callback
     * @param MoodleQuickForm $mform
     * @param mixed $formdata
     * @return void
     * @throws coding_exception
     */
    public static function definition_after_data(MoodleQuickForm &$mform, $formdata) {

        dates::definition_after_data($mform, $formdata);
    }
}
