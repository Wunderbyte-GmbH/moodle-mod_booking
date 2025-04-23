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
use mod_booking\calendar;
use mod_booking\option\fields_info;
use mod_booking\option\field_base;
use mod_booking\teachers_handler;
use MoodleQuickForm;
use stdClass;

/**
 * Class to handle one property of the booking_option_settings class.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class teachers extends field_base {
    /**
     * This ID is used for sorting execution.
     * @var int
     */
    public static $id = MOD_BOOKING_OPTION_FIELD_TEACHERS;

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
    public static $header = MOD_BOOKING_HEADER_TEACHERS;

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
        'teacheremail',
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

        parent::prepare_save_field($formdata, $newoption, $updateparam, '');

        $instance = new teachers();
        $mockclass = new stdClass();
        $mockclass->id = $formdata->id ?? 0;
        $changes = $instance->check_for_changes($formdata, $instance, $mockclass, 'teachersforoption');

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

        global $CFG;

        // Add teachers.
        $teacherhandler = new teachers_handler($formdata['id'] ?? 0);
        $teacherhandler->add_to_mform($mform);
    }

    /**
     * Standard function to transfer stored value to form.
     * @param stdClass $data
     * @param booking_option_settings $settings
     * @return void
     * @throws \dml_exception
     */
    public static function set_data(stdClass &$data, booking_option_settings $settings) {

        if (empty($data->importing) && !empty($data->id)) {
            $teacherhandler = new teachers_handler($data->id);
            $teacherhandler->set_data($data);
        } else {
            // This Logic is linked to the webservice importer functionality.
            // If we are currently importing, we check the mergeparam.
            // We might want to add teachers instead of replacing them.
            // We set throwerror to true...
            // ... because on importing, we want it to fail, if teacher is not found.
            $teacherids = teachers_handler::get_teacherids_from_form($data, true);

            if (
                !empty($data->importing)
                && (!empty($data->mergeparam))
            ) {
                if ($data->mergeparam > 1) {
                    $oldteacherids = $settings->teacherids;
                    $teacherids = array_merge($oldteacherids, $teacherids);
                }
            }
            $data->teachersforoption = $teacherids;
        }
    }

    /**
     * Save data
     * @param stdClass $data
     * @param stdClass $option
     * @return array
     * @throws \dml_exception
     */
    public static function save_data(stdClass &$data, stdClass &$option): array {

        $changes = [];

        $teacherhandler = new teachers_handler($data->id);
        $teacherhandler->save_from_form($data);

        return $changes;
    }

    /**
     * Once all changes are collected, also those triggered in save data, this is a possible hook for the fields.
     *
     * @param array $changes
     * @param object $data
     * @param object $newoption
     * @param object $originaloption
     *
     * @return void
     */
    public static function changes_collected_action(
        array $changes,
        object $data,
        object $newoption,
        object $originaloption
    ) {
        $oldteacherids = $changes["mod_booking\\option\\fields\\teachers"]["changes"]["oldvalue"] ?? [];
        $newteacherids = $changes["mod_booking\\option\\fields\\teachers"]["changes"]["newvalue"] ?? [];

        $optionid = $data->optionid ?? $data->id;
        if (empty($optionid)) {
            return;
        }

        // First, get all optiondateids.
        $optiondateids = [];
        foreach ($data as $key => $value) {
            if (preg_match('/^optiondateid_\d+$/', $key)) {
                $optiondateids[] = (int)$value;
            }
        }

        // The teacher was removed. So delete all calendar entries.
        foreach ($oldteacherids as $oldteacherid) {
            if (!in_array($oldteacherid, $newteacherids)) {
                new calendar((int)$data->cmid, (int)$optionid, (int)$oldteacherid, calendar::MOD_BOOKING_TYPETEACHERREMOVE);
            }
        }

        // The teacher was added. So create calendar entries.
        foreach ($newteacherids as $newteacherid) {
            if (!in_array($newteacherid, $oldteacherids)) {
                foreach ($optiondateids as $optiondateid) {
                    new calendar(
                        (int)$data->cmid,
                        (int)$optionid,
                        (int)$newteacherid,
                        calendar::MOD_BOOKING_TYPEOPTIONDATE,
                        $optiondateid,
                        1
                    );
                }
            }
        }
        return;
    }
}
