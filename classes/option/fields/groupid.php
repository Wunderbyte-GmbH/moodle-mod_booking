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
 * Display the group ID for a booking option.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\option\fields;

use mod_booking\booking_option_settings;
use mod_booking\option\fields_info;
use mod_booking\option\field_base;
use mod_booking\singleton_service;
use moodle_url;
use MoodleQuickForm;
use stdClass;

/**
 * Display the group ID for a booking option.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class groupid extends field_base {
    /**
     * This ID is used for sorting execution.
     * @var int
     */
    public static $id = MOD_BOOKING_OPTION_FIELD_GROUPID;

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
    public static $header = MOD_BOOKING_HEADER_COURSES;

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
        $returnvalue = null
    ): array {
        $optionid = $formdata->id ?? $formdata->optionid ?? 0;
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        if (empty($settings->cmid)) {
            // We need the cmid to get the booking settings. If it's missing, we do nothing.
            return [];
        }
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($settings->cmid);
        $bo = singleton_service::get_instance_of_booking_option($settings->cmid, $optionid);

        // If the reset checkbox is checked, clear the groupid on the option being saved.
        if (isset($formdata->resetgroupid) && $formdata->resetgroupid == 1) {
            // Let's try to find the correct groupid and set it, if we find it.
            if (!empty($optionid)) {
                $correctgroupname = "$bookingsettings->name - $settings->text ($optionid)";
                $groupid = groups_get_group_by_name($settings->courseid, $correctgroupname);
                if (!empty($groupid)) {
                    $newoption->groupid = $groupid;
                    return [];
                } else if (!empty($bookingsettings->addtogroup) && !empty($settings->courseid)) {
                    $newoption->groupid = $bo->create_group($newoption, true, 0, true);
                    return [];
                }
            }
            // Else, we just reset the groupid to null.
            $newoption->groupid = null;
        } else if (
            !empty($bookingsettings->addtogroup)
            && !empty($settings->courseid)
            && empty($settings->groupid)
        ) {
            $newoption->groupid = $bo->create_group($newoption, true, 0, true);
            return [];
        }
        // Else we do nothing.
        return [];
    }

    /**
     * Instance form definition.
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

        global $DB;

        // Standardfunctionality to add a header to the mform (only if its not yet there).
        if ($applyheader) {
            fields_info::add_header_to_mform($mform, self::$header);
        }

        $optionid = $formdata['id'] ?? $formdata['optionid'] ?? 0;
        if (empty($optionid)) {
            // We need an option id to get the correct group information.
            return;
        }
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $groupid = $settings->groupid ?? null;
        if (empty($groupid)) {
            // No groupid set, so we have nothing to display.
            return;
        }

        // Get group name, course id and course name in a single query.
        $group = groups_get_group($groupid);

        $grouplabel = '';
        if (!empty($group)) {
            $grouplabel = '<b>' . ($group->name ?? '?') . '</b>';
            if (!empty($group->courseid)) {
                $groupsincourseurl = new moodle_url('/group/index.php', ['id' => $group->courseid]);
                $grouplabel = '<a href="' . $groupsincourseurl->out(false) . '" target="_blank" class="btn btn-primary">'
                    . $grouplabel . '</a>';
            }
        } else {
            // Group not found, but we can at least display the id.
            $grouplabel = '<b>?</b> (ID: ' . $groupid . ')';
        }

        $mform->addElement(
            'static',
            'groupiddisplay',
            get_string('group', 'moodle'),
            $grouplabel
        );
        $mform->addHelpButton('groupiddisplay', 'groupiddisplay', 'mod_booking');

        // Checkbox to reset the groupid upon saving.
        $mform->addElement(
            'advcheckbox',
            'resetgroupid',
            get_string('wronggroup', 'mod_booking')
        );
        $mform->setType('resetgroupid', PARAM_INT);
        $mform->addHelpButton('resetgroupid', 'wronggroup', 'mod_booking');
    }

    /**
     * Standard function to transfer stored value to form.
     * @param stdClass $data
     * @param booking_option_settings $settings
     * @return void
     * @throws \dml_exception
     */
    public static function set_data(stdClass &$data, booking_option_settings $settings) {
        $data->resetgroupid = 0;
        return;
    }
}
