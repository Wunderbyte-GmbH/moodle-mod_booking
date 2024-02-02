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
 * Handle fields for booking option.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\option;

use coding_exception;
use core_component;
use mod_booking\booking_option_settings;
use mod_booking\singleton_service;
use moodle_exception;
use MoodleQuickForm;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Control and manage booking dates.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fields_info {

     /**
      * This function runs through all installed field classes and executes the prepare save function.
      * Returns an array of warnings as string.
      * @param stdClass $formdata
      * @param stdClass $newoption
      * @param int $updateparam
      * @return array
      */
    public static function prepare_save_fields(stdClass &$formdata, stdClass &$newoption,
        int $updateparam = MOD_BOOKING_UPDATE_OPTIONS_PARAM_DEFAULT): array {

        $warnings = [];
        $error = [];

        $classes = self::get_field_classes();

        foreach ($classes as $classname) {
            if (class_exists($classname)) {

                // Execute the prepare function of every field.
                try {
                    $warning = $classname::prepare_save_field($formdata, $newoption, $updateparam);
                } catch (\Exception $e) {
                    $error[] = $e;
                }

                if (!empty($warning)) {
                    $warnings[] = $warning;
                }
            }
        }

        return $warnings;
    }

    /**
     * A quick way to get classname without namespace.
     * @param mixed $classname
     * @return int|false|string
     */
    public static function get_class_name($classname) {
        if ($pos = strrpos($classname, '\\')) {
            return substr($classname, $pos + 1);
        }
        return $pos;
    }

    /**
     * This is a standard function to add a header, if it is not yet there.
     * @param MoodleQuickForm $mform
     * @param string $headeridentifier
     * @return void
     * @throws coding_exception
     */
    public static function add_header_to_mform(MoodleQuickForm &$mform, string $headeridentifier) {

        $headericon = '';

        switch ($headeridentifier) {
            case MOD_BOOKING_HEADER_GENERAL:
                $headericon = '<i class="fa fa-fw fa-cog" aria-hidden="true"></i>';
                break;
            case MOD_BOOKING_HEADER_ADVANCEDOPTIONS:
                $headericon = '<i class="fa fa-fw fa-cogs" aria-hidden="true"></i>';
                break;
            case MOD_BOOKING_HEADER_BOOKINGOPTIONTEXT:
                $headericon = '<i class="fa fa-fw fa-comments" aria-hidden="true"></i>';
                break;
            // TODO: Add icons for the other headers here...
        }

        if (!empty($headericon)) {
            $headericon .= '&nbsp;';
        }

        $elementexists = $mform->elementExists($headeridentifier);
        switch ($headeridentifier) {
            case MOD_BOOKING_HEADER_CUSTOMFIELDS:
            case MOD_BOOKING_HEADER_ACTIONS:
            case MOD_BOOKING_HEADER_ELECTIVE:
            case MOD_BOOKING_HEADER_PRICE:
            case MOD_BOOKING_HEADER_TEACHERS:
            case MOD_BOOKING_HEADER_SUBBOOKINGS:
            case MOD_BOOKING_HEADER_AVAILABILITY:
                // For some identifiers, we do nothing.
                // Because they take care of everything in one step.
                break;
            default:
                if (!$elementexists) {
                    $mform->addElement('header', $headeridentifier, $headericon . get_string($headeridentifier, 'mod_booking'));
                }
                break;
        }
    }

    /**
     * Add all available fields in the right order.
     * @param MoodleQuickForm $mform
     * @param array $formdata
     * @param array $optionformconfig
     * @return void
     */
    public static function instance_form_definition(MoodleQuickForm &$mform, array &$formdata, array &$optionformconfig) {

        $classes = self::get_field_classes();

        foreach ($classes as $class) {
            $class::instance_form_definition($mform, $formdata, $optionformconfig);
        }
    }

    /**
     * Validation.
     * @param array $data
     * @param array $files
     * @param array $errors
     * @return void
     */
    public static function validation(array $data, array $files, array &$errors) {

        $classes = self::get_field_classes();

        foreach ($classes as $class) {
            $class::validation($data, $files, $errors);
        }
    }

    /**
     * Save fields post
     * @param stdClass $formdata
     * @param stdClass $option
     * @param int $updateparam
     * @return void
     */
    public static function save_fields_post(stdClass &$formdata, stdClass &$option, int $updateparam) {

        $classes = self::get_field_classes(MOD_BOOKING_EXECUTION_POSTSAVE);

        foreach ($classes as $class) {
            $class::save_data($formdata, $option);
        }
    }

    /**
     * Set data
     * @param stdClass $data
     * @return string
     */
    public static function set_data(stdClass &$data) {

        $optionid = $data->id ?? 0;

        $errormessage = '';

        if (!empty($optionid)) {
            $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        } else {
            $settings = new booking_option_settings(0);
        }

        $classes = self::get_field_classes();

        try {
            foreach ($classes as $classname) {
                $classname::set_data($data, $settings);
            }
        } catch (moodle_exception $e) {
            // This is just to get out of the loop.
            // We use this exit in the template class.
            $errormessage = $e->getMessage();
        }

        return $errormessage;
    }

    /**
     * Definition after data
     * @param MoodleQuickForm $mform
     * @param array $formdata
     * @return void
     */
    public static function definition_after_data(MoodleQuickForm &$mform, array &$formdata) {

        $classes = self::get_field_classes();

        foreach ($classes as $classname) {
            $classname::definition_after_data($mform, $formdata);
        }
    }

    /**
     * Get all classes function.
     * Save param allows to filter for all (default) or special save logic.
     * @param int $save
     * @return array
     */
    private static function get_field_classes(int $save = -1) {
        $fields = core_component::get_component_classes_in_namespace(
            "mod_booking",
            'option\fields'
        );

        $classes = [];
        foreach (array_keys($fields) as $classname) {

            // We might only want postsave classes.
            if ($save === MOD_BOOKING_EXECUTION_POSTSAVE) {
                if ($classname::$save !== MOD_BOOKING_EXECUTION_POSTSAVE) {
                    continue;
                }
            }
            // We might only want only normal save classes.
            if ($save === MOD_BOOKING_EXECUTION_NORMAL) {
                if ($classname::$save !== MOD_BOOKING_EXECUTION_NORMAL) {
                    continue;
                }
            }

            $classes[$classname::$id] = $classname;
        }

        ksort($classes);

        return $classes;
    }
}
