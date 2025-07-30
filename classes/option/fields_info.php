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
use mod_booking\booking_option_settings;
use mod_booking\singleton_service;
use moodle_exception;
use MoodleQuickForm;
use stdClass;
use context_module;
use dml_exception;
use Exception;
use mod_booking\settings\optionformconfig\optionformconfig_info;

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
    public static function prepare_save_fields(
        stdClass &$formdata,
        stdClass &$newoption,
        int $updateparam = MOD_BOOKING_UPDATE_OPTIONS_PARAM_DEFAULT
    ): array {

        $feedback = [];
        $error = [];
        // Todo: implement error handling.

        $context = context_module::instance($formdata->cmid);
        $classes = self::get_field_classes($context->id);

        foreach ($classes as $classname) {
            if (class_exists($classname)) {
                // We want to ignore some classes here.
                if (self::ignore_class($formdata, $classname)) {
                    continue;
                }

                // Execute the prepare function of every field.
                try {
                    $returnvalue = $classname::prepare_save_field($formdata, $newoption, $updateparam);
                } catch (\Exception $e) {
                    $error[] = $e;
                }

                if (!empty($returnvalue)) {
                    $feedback[$classname] = $returnvalue;
                }
            }
        }

        return $feedback;
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
     * A quick way to get namespace from classname.
     * @param string $classname
     * @return string
     */
    public static function get_namespace_from_class_name($classname) {
        $namespace = "";
        if ($classname == "dates") {
            $classname = "optiondates";
        } else if ($classname == "enrolementstatus") {
            $classname = "enrolmentstatus";
        }
        $base = 'mod_booking\\option\\fields\\' . $classname;
        if (class_exists($base)) {
            return $base;
        }
        return $namespace;
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
            case MOD_BOOKING_HEADER_COURSES:
                $headericon = '<i class="fa fa-fw fa-graduation-cap" aria-hidden="true"></i>';
                break;
            case MOD_BOOKING_HEADER_DATES:
                $headericon = '<i class="fa fa-fw fa-calendar" aria-hidden="true"></i>';
                break;
            case MOD_BOOKING_HEADER_SHAREDPLACES:
                $headericon = '<i class="fa fa-fw fa-share-alt" aria-hidden="true"></i>';
                break;
            case MOD_BOOKING_HEADER_CERTIFICATE:
                $headericon = '<i class="fa fa-fw fa-certificate" aria-hidden="true"></i>';
                break;
            // Todo: Add icons for the other headers here...
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
     * @return array
     */
    public static function instance_form_definition(MoodleQuickForm &$mform, array &$formdata) {

        if (!empty($formdata['cmid'])) {
            $context = context_module::instance($formdata['cmid']);
        } else if (!empty($formdata['optionid'])) {
            $settings = singleton_service::get_instance_of_booking_option_settings($formdata['optionid']);
            $context = context_module::instance($settings->cmid);
        } else {
            throw new moodle_exception('fields_info.php: missing context in function instance_form_definition');
        }

        $classes = self::get_field_classes($context->id);

        foreach ($classes as $key => $classname) {
            // We want to ignore some classes here.
            if (self::ignore_class((object)$formdata, $classname)) {
                unset($classes[$key]);
                continue;
            }
            $classname::instance_form_definition($mform, $formdata, []);
        }

        return $classes;
    }

    /**
     * Validation.
     * @param array $data
     * @param array $files
     * @param array $errors
     * @return void
     */
    public static function validation(array $data, array $files, array &$errors) {

        $context = context_module::instance($data['cmid']);
        $classes = self::get_field_classes($context->id);

        foreach ($classes as $classname) {
            // We want to ignore some classes here.
            if (self::ignore_class((object)$data, $classname)) {
                continue;
            }

            $classname::validation($data, $files, $errors);
        }
    }

    /**
     * Save fields post
     * @param stdClass $formdata
     * @param stdClass $option
     * @param int $updateparam
     * @return array
     */
    public static function save_fields_post(stdClass &$formdata, stdClass &$option, int $updateparam): array {

        $context = context_module::instance($formdata->cmid);
        $classes = self::get_field_classes($context->id, MOD_BOOKING_EXECUTION_POSTSAVE);
        $changes = [];
        foreach ($classes as $classname) {
            // We want to ignore some classes here.
            if (self::ignore_class($formdata, $classname)) {
                continue;
            }
            $changes[$classname] = $classname::save_data($formdata, $option);
        }
        return $changes;
    }

    /**
     * Set data
     * @param stdClass $data
     * @return string
     */
    public static function set_data(stdClass &$data) {

        $optionid = $data->id ?? $data->optionid ?? 0;

        $errormessage = '';

        if (!empty($optionid)) {
            $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        } else {
            $settings = new booking_option_settings(0);
        }

        $cmid = $data->cmid ?? $settings->cmid ?? 0;
        $context = context_module::instance($cmid);
        $classes = self::get_field_classes($context->id);

        try {
            foreach ($classes as $classname) {
                // We want to ignore some classes here.
                if (self::ignore_class($data, $classname)) {
                    continue;
                }
                $classname::set_data($data, $settings);
                // We might get the id during prepare__import. If so, we want to get the settings object.
                if (!empty($data->id) && empty($settings->id)) {
                    $settings = singleton_service::get_instance_of_booking_option_settings($data->id);
                }
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

        if (!empty($formdata['cmid'])) {
            $context = context_module::instance($formdata['cmid']);
        } else if (!empty($formdata['optionid'])) {
            $settings = singleton_service::get_instance_of_booking_option_settings($formdata['optionid']);
            $context = context_module::instance($settings->cmid);
        } else {
            throw new moodle_exception('formconfig.php: missing context in function instance_form_definition');
        }
        $classes = self::get_field_classes($context->id);

        foreach ($classes as $classname) {
            // We want to ignore some classes here.
            if (self::ignore_class($formdata, $classname)) {
                continue;
            }

            $classname::definition_after_data($mform, $formdata);
        }
    }

    /**
     * Get all classes function.
     * This already filters classes for the given users and settings.
     * Save param allows to filter for all (default) or special save logic.
     * @param int $contextid
     * @param int $save
     * @return array
     */
    private static function get_field_classes(int $contextid, int $save = -1) {

        // We get the fields directly as configured fields.

        $capability = optionformconfig_info::return_capability_for_user($contextid);
        $record = optionformconfig_info::return_configured_fields_for_capability($contextid, $capability);

        $fields = json_decode($record['json']);

        $classes = [];
        foreach ($fields as $field) {
            $classname = $field->fullclassname;

            // We might only want postsave classes.
            if ($save === MOD_BOOKING_EXECUTION_POSTSAVE) {
                if (!class_exists($classname) || $classname::$save !== MOD_BOOKING_EXECUTION_POSTSAVE) {
                    continue;
                }
            }
            // We might only want only normal save classes.
            if ($save === MOD_BOOKING_EXECUTION_NORMAL) {
                if (!class_exists($classname) || $classname::$save !== MOD_BOOKING_EXECUTION_NORMAL) {
                    continue;
                }
            }
            if (!empty($field->necessary) || !empty($field->checked)) {
                if (class_exists($classname)) {
                    $classes[$classname::$id] = $classname;
                }
            }
        }

        return $classes;
    }

    /**
     * Ignore class only applies to importing mode.
     * During import, forms are created differently then normally.
     * @param mixed $data
     * @param mixed $classname
     * @return bool
     * @throws coding_exception
     * @throws dml_exception
     */
    private static function ignore_class($data, $classname) {

        global $DB;

        if (!empty($data->importing)) {
            // If we are importing, we see if the value is actually present.
            // We only want the last part of the classname.
            $array = explode('\\', $classname);
            $shortclassname = array_pop($array);

            // If the class is not necessary and not part of the imported fields, ignore it.
            if (
                !in_array(MOD_BOOKING_OPTION_FIELD_NECESSARY, $classname::$fieldcategories)
                && !isset($data->{$shortclassname})
            ) {
                if ($classname::$id === MOD_BOOKING_OPTION_FIELD_PRICE) {
                    // Todo: if a column is called like any price category.
                    $existingpricecategories = $DB->get_records('booking_pricecategories', ['disabled' => 0]);
                    $results = array_filter($existingpricecategories, fn($a) => isset($data->{$a->identifier}));
                    if (!empty($results)) {
                        return false;
                    }
                }

                // If there are alternative identifiers, we have to check if one of them is present.
                foreach ($classname::$alternativeimportidentifiers as $alternativeidentifier) {
                    if (isset($data->{$alternativeidentifier})) {
                        return false;
                    }
                }

                // The custom field class is the only one which still needs to executed, as we dont.
                if ($classname::$id !== MOD_BOOKING_OPTION_FIELD_COSTUMFIELDS) {
                    return true;
                }
            }
        }
        return false;
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
     *
     */
    public static function all_changes_collected_actions(
        array $changes,
        object $data,
        object $newoption,
        object $originaloption
    ) {
        if (!empty($data->cmid)) {
            $context = context_module::instance($data->cmid);
        } else if (!empty($formdata['optionid'])) {
            $settings = singleton_service::get_instance_of_booking_option_settings($newoption->id);
            $context = context_module::instance($settings->cmid);
        } else {
            throw new moodle_exception('formconfig.php: missing context in function instance_form_definition');
        }
        $classes = self::get_field_classes($context->id);

        foreach ($classes as $classname) {
            $classname::changes_collected_action(
                $changes,
                $data,
                $newoption,
                $originaloption
            );
        }
        return;
    }
}
