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

use html_writer;
use mod_booking\booking_option;
use mod_booking\booking_option_settings;
use mod_booking\option\fields_info;
use mod_booking\option\field_base;
use mod_booking\singleton_service;
use mod_booking\utils\wb_payment;
use moodle_exception;
use MoodleQuickForm;
use stdClass;

/**
 * Class to handle one property of the booking_option_settings class.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer-Sengseis
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sharedplaces extends field_base {
    /**
     * This ID is used for sorting execution.
     * @var int
     */
    public static $id = MOD_BOOKING_OPTION_FIELD_SHAREDPLACES;

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
    public static $header = MOD_BOOKING_HEADER_SHAREDPLACES;

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
        'sharedplaceswithoptions',
        'sharedplacespriority',
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
        $returnvalue = null
    ): array {

        // We store the information until when a booking option can be cancelled in the JSON.
        // So this has to happen BEFORE JSON is saved!
        if (empty($formdata->sharedplaceswithoptions)) {
            // This will store the correct JSON to $optionvalues->json.
            booking_option::remove_key_from_json($newoption, "sharedplaceswithoptions");
        } else {
            booking_option::add_data_to_json($newoption, "sharedplaceswithoptions", $formdata->sharedplaceswithoptions);
        }

        if (empty($formdata->sharedplacespriority)) {
            // This will store the correct JSON to $optionvalues->json.
            booking_option::remove_key_from_json($newoption, "sharedplacespriority");
        } else {
            booking_option::add_data_to_json($newoption, "sharedplacespriority", $formdata->sharedplacespriority);
        }

        $instance = new sharedplaces();
        $changes = $instance->check_for_changes($formdata, $instance);

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
        global $DB;

        if (empty($formdata['id'])) {
            // No shared places for templates.
            return;
        }

        // Check if PRO version is activated.
        if (wb_payment::pro_version_is_activated()) {
            $bookingoptionarray = [];
            $sharedplacesoptions = [
                'tags' => false,
                'multiple' => true,
            ];
            if (
                $bookingoptionrecords = $DB->get_records_sql(
                    "SELECT bo.id optionid, bo.titleprefix, bo.text optionname, b.name instancename
                    FROM {booking_options} bo
                    LEFT JOIN {booking} b
                    ON bo.bookingid = b.id"
                )
            ) {
                foreach ($bookingoptionrecords as $bookingoptionrecord) {
                    if (!empty($bookingoptionrecord->titleprefix)) {
                        $bookingoptionarray[$bookingoptionrecord->optionid] =
                            "$bookingoptionrecord->titleprefix - $bookingoptionrecord->optionname " .
                                "($bookingoptionrecord->instancename)";
                    } else {
                        $bookingoptionarray[$bookingoptionrecord->optionid] =
                            "$bookingoptionrecord->optionname ($bookingoptionrecord->instancename)";
                    }
                }
            }

            // Standardfunctionality to add a header to the mform (only if its not yet there).
            if ($applyheader) {
                fields_info::add_header_to_mform($mform, self::$header);
            }

            $mform->addElement(
                'autocomplete',
                'sharedplaceswithoptions',
                get_string('sharedplaces', 'mod_booking'),
                $bookingoptionarray,
                $sharedplacesoptions
            );
            $mform->addHelpButton('sharedplaceswithoptions', 'sharedplaces', 'mod_booking');

            $mform->addElement(
                'advcheckbox',
                'sharedplacespriority',
                '',
                get_string('sharedplacespriority', 'mod_booking'),
            );
            $mform->addHelpButton('sharedplacespriority', 'sharedplacespriority', 'mod_booking');
        } else {
            // Standardfunctionality to add a header to the mform (only if its not yet there).
            if ($applyheader) {
                fields_info::add_header_to_mform($mform, self::$header);
            }
            $mform->addElement(
                'static',
                'nolicenseforsharedplaces',
                get_string('licensekeycfg', 'mod_booking'),
                get_string('licensekeycfgdesc', 'mod_booking')
            );
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

        // We have no special import setting here.
        $sharedplaceswithoptions = booking_option::get_value_of_json_by_key($data->id, "sharedplaceswithoptions");
        $sharedplacespriority = booking_option::get_value_of_json_by_key($data->id, "sharedplacespriority");
        if (!empty($sharedplaceswithoptions)) {
            // We need to make sure that we save the optionids as strings.
            $data->sharedplaceswithoptions = array_map(fn($a) => "$a", $sharedplaceswithoptions);
        }
        if (!empty($sharedplacespriority)) {
            $data->sharedplacespriority = $sharedplacespriority;
        }
    }

    /**
     * This function adds error keys for form validation.
     * @param array $data
     * @param array $files
     * @param array $errors
     * @return array
     */
    public static function validation(array $data, array $files, array &$errors) {

        if (!empty($data['sharedplacespriority'])) {
            $sharedoptions = self::get_sharedplaces_options($data['id']);
        }

        // This is the case when other options reference this option.
        if (!empty($sharedoptions)) {
            $settings = singleton_service::get_instance_of_booking_option_settings(reset($sharedoptions));
            $linktoption = html_writer::link(
                "/mod/booking/editoptions.php?id=$settings->cmid&optionid=$settings->id",
                $settings->get_title_with_prefix()
            );

            $errors['sharedplacespriority'] = get_string('sharedplacespriorityerror', 'mod_booking', $linktoption);
        }

        return $errors;
    }

    /**
     * Check if there is a difference between the former and the new values of the formdata.
     *
     * @param stdClass $formdata
     * @param field_base $self
     * @param mixed $mockdata // Only needed if there the object needs params for the save_data function.
     * @param string $key
     * @param mixed $value
     *
     * @return array
     *
     */
    public function check_for_changes(
        stdClass $formdata,
        field_base $self,
        $mockdata = '',
        string $key = '',
        $value = ''
    ): array {

        if (!isset($self)) {
            return [];
        }

        $excludeclassesfromtrackingchanges = MOD_BOOKING_CLASSES_EXCLUDED_FROM_CHANGES_TRACKING;

        $classname = 'sharedplaces';
        if (in_array($classname, $excludeclassesfromtrackingchanges)) {
            return [];
        }

        $changes = [];

        if (!empty($formdata->sharedplaceswithoptions)) {
            $newvalue = $formdata->sharedplaceswithoptions;
        } else {
            $newvalue = "";
        }

        // Check if there were changes and return these.
        if (!empty($formdata->id) && isset($value)) {
            $settings = singleton_service::get_instance_of_booking_option_settings($formdata->id);
            $mockdata = new stdClass();
            $mockdata->id = $formdata->id;
            $self::set_data($mockdata, $settings);

            if (!empty($mockdata->sharedplaceswithoptions)) {
                $oldvalue = $settings->jsonobject->sharedplaceswithoptions;
            } else {
                $oldvalue = "";
            }

            if (
                $oldvalue != $newvalue
                && !(empty($oldvalue) && empty($newvalue))
            ) {
                $changes = [
                    'changes' => [
                        'fieldname' => $classname,
                        'oldvalue' => $oldvalue,
                        'newvalue' => $newvalue,
                        'formkey' => 'sharedplaceswithoptions',
                    ],
                ];
            }
            // If we don't have changes with the option, we still check priority.
            if (empty($changes)) {
                if (!empty($mockdata->sharedplacespriority)) {
                    $oldvalue = $settings->jsonobject->sharedplacespriority;
                } else {
                    $oldvalue = "";
                }

                if (
                    $oldvalue != $newvalue
                    && !(empty($oldvalue) && empty($newvalue))
                ) {
                    $changes = [
                        'changes' => [
                            'fieldname' => $classname,
                            'oldvalue' => $oldvalue,
                            'newvalue' => $newvalue,
                            'formkey' => 'sharedplacespriority',
                        ],
                    ];
                }
            }
        }
        return $changes;
    }

    /**
     * Gets the shared options.
     *
     * @param int $optionid
     * @param bool $onlypriority
     *
     * @return array
     *
     */
    public static function get_sharedplaces_options(int $optionid, bool $onlypriority = false): array {
        global $DB;
        $databasetype = $DB->get_dbfamily();
        if (empty($optionid)) {
            return [];
        }
        $sql = "SELECT id FROM {booking_options} WHERE ";

        switch ($databasetype) {
            case 'postgres':
                $additionalwhere = $onlypriority ? " AND (json::jsonb ->> 'sharedplacespriority')::int = 1 " : '';
                $where = "(json::jsonb -> 'sharedplaceswithoptions') @> '[\"$optionid\"]'::jsonb
                    $additionalwhere
                ";
                break;
            case 'mysql':
                $additionalwhere = $onlypriority ? " AND JSON_UNQUOTE(JSON_EXTRACT(json, '$.sharedplacespriority')) = '1' " : '';
                $where = "JSON_CONTAINS(json, '\"$optionid\"', '$.sharedplaceswithoptions')
                    $additionalwhere
                ";
                break;
            default:
                throw new moodle_exception('Unsupported database type for JSON key extraction.');
        }
        $sql .= $where;
        return $DB->get_fieldset_sql($sql);
    }


    /**
     * Merges the param and returns a string like IN (1,2).
     *
     * @param int $optionid
     * @param mixed $params
     *
     * @return string
     *
     */
    public static function return_shared_places_where_sql(int $optionid, &$params) {

        global $DB;
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $optionids = [(int)$optionid];
        if (
            !empty($settings->jsonobject->sharedplaceswithoptions)
            && is_array($settings->jsonobject->sharedplaceswithoptions)
        ) {
            $optionids = array_merge(
                $optionids,
                array_map(fn($a) => (int)$a, $settings->jsonobject->sharedplaceswithoptions)
            );
        }

        unset($params['optionid']);
        [$spinorequal, $spparams] = $DB->get_in_or_equal($optionids, SQL_PARAMS_NAMED, 'shpl_');
        $params = array_merge($params, $spparams);

        return $spinorequal;
    }

    /**
     * Syncs the waitinglist of shared options.
     *
     * @param int $optionid
     * @param bool $onlypriority
     *
     * @return bool
     *
     */
    public static function sync_sharedplaces_options(int $optionid, $onlypriority = true) {
        $sharedoptionids = self::get_sharedplaces_options($optionid, $onlypriority);

        if (!empty($sharedoptionids)) {
            foreach ($sharedoptionids as $sharedoptionid) {
                $sharedsettings = singleton_service::get_instance_of_booking_option_settings($sharedoptionid);
                $sharedoption = singleton_service::get_instance_of_booking_option($sharedsettings->cmid, $sharedoptionid);
                $result = $sharedoption->sync_waiting_list(true);
            }
            booking_option::purge_cache_for_answers($optionid);
            // We only stop execution if we have synced a user before.
            if ($result) {
                return $result;
            }
        }
        return false;
    }
}
