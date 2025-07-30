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
use mod_booking\booking_option_settings;
use mod_booking\dates;
use mod_booking\option\dates_handler;
use mod_booking\option\field_base;
use mod_booking\option\fields_info;
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
        'optiondateid_0',
        'optiondateid_1',
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
        // Run through all dates to make sure we don't have an array.
        // We need to transform dates to timestamps.
        [$dates, $highestindex] = dates::get_list_of_submitted_dates((array)$formdata);

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
            $newoption->courseendtime = $date['courseendtime'];
        }

        // If there is no date left, we delete courestartdate & courseenddate.
        if (empty($dates)) {
            $newoption->coursestarttime = null;
            $newoption->courseendtime = null;
        }

        $newoption->dayofweektime = $formdata->dayofweektime ?? '';

        // We now support multiple dayofweektime strings.
        $dayofweektimestrings = dates_handler::split_and_trim_reoccurringdatestring($formdata->dayofweektime ?? '');
        $weekdays = [];
        if (!empty($dayofweektimestrings)) {
            foreach ($dayofweektimestrings as $dayofweektime) {
                $dayinfo = dates_handler::prepare_day_info($dayofweektime ?? '');
                if (!empty($dayinfo['day'])) {
                    $weekdays[] = $dayinfo['day'];
                }
            }
        }
        $newoption->dayofweek = implode(',', $weekdays);
        $newoption->semesterid = $formdata->semesterid ?? 0;

        // We can return a warning message here.
        return [];
    }

    /**
     * This function adds error keys for form validation.
     * @param array $data
     * @param array $files
     * @param array $errors
     * @return void
     */
    public static function validation(array $data, array $files, array &$errors): void {

        // Run through all dates to make sure we don't have an array.
        // We need to transform dates to timestamps.
        [$dates, $highestindex] = dates::get_list_of_submitted_dates($data);

        $problems = array_filter($dates, fn($a) => $a['coursestarttime'] > $a['courseendtime']);

        foreach ($problems as $problem) {
            // phpcs:ignore moodle.Commenting.TodoComment.MissingInfoInline
            // Todo: Make it nice.
            $errors['courseendtime_' . $problem['index']] = get_string('problemwithdate', 'mod_booking');
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
        // Standardfunctionality to add a header to the mform (only if its not yet there).
        if ($applyheader) {
            fields_info::add_header_to_mform($mform, self::$header);
        }
        $mform->addElement('hidden', 'datesmarker', 0);
        $mform->setType('datesmarker', PARAM_INT);
    }

    /**
     * Save data.
     *
     * @param stdClass $formdata
     * @param stdClass $option
     * @return array
     * @throws \dml_exception
     */
    public static function save_data(stdClass &$formdata, stdClass &$option): array {

        return dates::save_optiondates_from_form($formdata, $option);
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

                $data->semesterid = !empty($data->semesterid) ? $data->semesterid : (
                    !empty($settings->semesterid) ? $settings->semesterid : (
                        !empty($bookingsettings->semesterid) ? $bookingsettings->semesterid : 0
                    )
                );

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

    /**
     * Return values for bookingoption_updated event.
     *
     * @param array $changes
     *
     * @return array
     *
     */
    public function get_changes_description(array $changes): array {

        $fieldname = get_string($changes['fieldname'], 'booking');
        $infotext = get_string('changeinfochanged', 'booking', $fieldname);

        $oldvalue = isset($changes['oldvalue']) ? $this->prepare_dates_array($changes['oldvalue']) : "";
        $newvalue = isset($changes['newvalue']) ? $this->prepare_dates_array($changes['newvalue']) : "";

        $returnarray = [
            'oldvalue' => $oldvalue,
            'newvalue' => $newvalue,
            'fieldname' => get_string($changes['fieldname'], 'booking'),
        ];

        if (empty($oldvalue) && empty($newvalue)) {
            $returnarray['info'] = $infotext;
        }

        return $returnarray;
    }

    /**
     * Create human readable strings of dates, times and entities (if given).
     *
     * @param array $dates
     *
     * @return array
     *
     */
    private function prepare_dates_array(array $dates): array {
        $returndates = [];
        foreach ($dates as $date) {
            $date = (object)$date;
            $d = dates_handler::prettify_datetime(
                (int)$date->coursestarttime,
                (int)$date->courseendtime
            );
            $datestring = $d->datestring;
            if (!empty($date->entityid)) {
                $entity = singleton_service::get_entity_by_id($date->entityid);
                $datestring .= " " . $entity[$date->entityid]->name;
            }
            $returndates[] = $datestring;
        }
        return $returndates;
    }
}
