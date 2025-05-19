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
 * Control and manage booking respondapi.
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\option\fields;

use mod_booking\booking_option;
use mod_booking\booking_option_settings;
use mod_booking\local\respondapi\handlers\respondapi_handler;
use mod_booking\option\fields_info;
use mod_booking\option\field_base;
use mod_booking\singleton_service;
use MoodleQuickForm;
use stdClass;

/**
 * Class to handle one property of the booking_option_settings class.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Magdalena Holczik
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class respondapi extends field_base {
    /**
     * This ID is used for sorting execution.
     * @var int
     */
    public static $id = MOD_BOOKING_OPTION_FIELD_RESPONDAPI;

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
    public static $header = MOD_BOOKING_HEADER_RESPONDAPI;

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
     * @return array
     */
    public static function prepare_save_field(
        stdClass &$formdata,
        stdClass &$newoption,
        int $updateparam,
        $returnvalue = null
    ): array {
        // Check if checkbox is ticked.
        // Check if id is empty.
        // If it's ticked AND id is empty, contact marmara server.
        // Save the return value.
        if (!get_config('booking', 'marmara_enabled')) {
            return [];
        }

        // Save enablemarmarasync flag into JSON.
        booking_option::add_data_to_json($newoption, 'enablemarmarasync', $formdata->enablemarmarasync ?? 0);

        // If syncing is enabled but ID is missing, call API to fetch it.
        if (
            empty($formdata->marmaracriteriaid)
            && !empty($formdata->enablemarmarasync)
            && !empty($formdata->selectparentkeywordid)
        ) {
            $settings = singleton_service::get_instance_of_booking_option_settings($formdata->id);
            $newkeywordname = $settings->get_title_with_prefix() ?: $newoption->text;
            $newkeyworddescription = $formdata->description['text'];
            $respondapihandler = new respondapi_handler();
            $newkeywordparentid = $respondapihandler->extract_idnumber_from_id($formdata->selectparentkeywordid);
            $fetchedcriteriaid = $respondapihandler->get_new_keyword(
                $newkeywordparentid,
                $newkeywordname,
                $newkeyworddescription,
            );

            if (!empty($fetchedcriteriaid)) {
                booking_option::add_data_to_json($newoption, 'marmaracriteriaid', $fetchedcriteriaid);
            }

            if (!empty($formdata->selectparentkeywordid)) {
                booking_option::add_data_to_json($newoption, 'selectparentkeyword', $formdata->selectparentkeywordid);
            }

            return ['changes' => ['marmaracriteriaid' => $fetchedcriteriaid]];
        }

        if (empty($formdata->enablemarmarasync)) {
            booking_option::remove_key_from_json($newoption, 'marmaracriteriaid');
            booking_option::remove_key_from_json($newoption, 'selectparentkeyword');
        }

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
     *
     */
    public static function instance_form_definition(
        MoodleQuickForm &$mform,
        array &$formdata,
        array $optionformconfig,
        $fieldstoinstanciate = [],
        $applyheader = true
    ) {
        if ($applyheader) {
            fields_info::add_header_to_mform($mform, self::$header);
        }

        $respondapihandler = new respondapi_handler($formdata['id'] ?? 0);
        $respondapihandler->add_to_mform($mform);
    }

    /**
     * This function adds error keys for form validation.
     * @param array $data
     * @param array $files
     * @param array $errors
     * @return array
     */
    public static function validation(array $data, array $files, array &$errors) {
        return $errors;
    }

    /**
     * Function to set the Data for the form.
     *
     * @param stdClass $data
     * @param booking_option_settings $settings
     *
     * @return void
     *
     */
    public static function set_data(stdClass &$data, booking_option_settings $settings) {
        global $OUTPUT;
        $rootparentkeyword = get_config('booking', 'marmara_keywordparentid');
        // In the data object, there might be a json value.
        // With the value for the checkbox? checked or not.
        // And the kriteria ID (if it's there).
        if (!empty($settings->id)) {
            // Load sync status from JSON (default to 0 if not found).
            $enablemarmarasync = booking_option::get_value_of_json_by_key($settings->id, 'enablemarmarasync') ?? 0;
            $data->enablemarmarasync = (int)$enablemarmarasync;

            // Load criteria ID from JSON.
            $currentvalue = booking_option::get_value_of_json_by_key($settings->id, 'marmaracriteriaid') ?? null;

            $data->marmaracriteriaid = $currentvalue;

            // Load parent keyword ID from JSON.
            $selectparentkeywordid = booking_option::get_value_of_json_by_key($settings->id, 'selectparentkeyword') ?? null;
            if (empty($selectparentkeywordid)) {
                $details = [
                    'id' => $rootparentkeyword ?? 0,
                    'name' => 'Root Keyword' ?? '',
                ];
                $data->selectparentkeyword = $OUTPUT->render_from_template(
                    'mod_booking/respondapi/parentkeyword',
                    $details
                );
            } else {
                $selectparentkeywordid = json_decode($selectparentkeywordid);
                $details = [
                    'id' => $selectparentkeywordid->id ?? 0,
                    'name' => $selectparentkeywordid->name ?? '',
                ];
                return $OUTPUT->render_from_template(
                    'mod_booking/respondapi/parentkeyword',
                    $details
                );
            }
        }
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
    public static function changes_collected_action(
        array $changes,
        object $data,
        object $newoption,
        object $originaloption
    ) {
       // Get last text and description. $data contains always last updated text and description.
        $text = $data->text;
        $description = $data->description['text'];

       // Check if option name or description is changed, then call API to update keyword name.
        if (
            array_key_exists(\mod_booking\option\fields\text::class, $changes) ||
            array_key_exists(\mod_booking\option\fields\description::class, $changes)
        ) {
            // CAll API to update the name & description.
            $respondapihandler = new respondapi_handler();
            $respondapihandler->update_keyword($data->marmaracriteriaid, $text, $description);
        }
    }
}
