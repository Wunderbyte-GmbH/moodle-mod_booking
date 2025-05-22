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

use context;
use context_module;
use context_system;
use mod_booking\booking_option;
use mod_booking\booking_option_settings;
use mod_booking\booking_rules\booking_rules;
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
class applybookingrules extends field_base {
    /**
     * This ID is used for sorting execution.
     * @var int
     */
    public static $id = MOD_BOOKING_OPTION_FIELD_APPLYBOOKINGRULE;

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
    public static $header = MOD_BOOKING_HEADER_RULES;

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
        'skipbookingrulesmode',
        'skipbookingrules',
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
     * @return array // Changes.
     */
    public static function prepare_save_field(
        stdClass &$formdata,
        stdClass &$newoption,
        int $updateparam,
        $returnvalue = null
    ): array {

        // We store the information if a booking option can be cancelled in the JSON.
        // So this has to happen BEFORE JSON is saved!
        if (
            empty($formdata->skipbookingrules)
            && empty($formdata->skipbookingrulesmode)
        ) {
            // This will store the correct JSON to $optionvalues->json.
            booking_option::remove_key_from_json($newoption, "skipbookingrules");
            booking_option::remove_key_from_json($newoption, "skipbookingrulesmode");
        } else {
            booking_option::add_data_to_json(
                $newoption,
                "skipbookingrules",
                $formdata->skipbookingrules
            );
            booking_option::add_data_to_json(
                $newoption,
                "skipbookingrulesmode",
                $formdata->skipbookingrulesmode
            );
        }

        $instance = new applybookingrules();
        $mockdata = new stdClass();
        $mockdata->id = $formdata->optionid ?? $formdata->id;
        $mockdata->skipbookingrules = $formdata->skipbookingrules;
        $mockdata->skipbookingrulesmode = $formdata->skipbookingrulesmode;

        // Todo: Write changes function.
        $changes1 = $instance->check_for_changes($formdata, $instance, $mockdata, 'skipbookingrulesmode');
        $changes2 = $instance->check_for_changes($formdata, $instance, $mockdata, 'skipbookingrules');
        return empty($changes1) ? $changes2 : $changes1;
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
        if (!empty($formdata['context'])) {
            $context = $formdata['context'];
        } else if (!empty($formdata['cmid'])) {
            $context = context_module::instance($formdata['cmid']);
        } else {
            $context = context_system::instance();
        }

        // Standardfunctionality to add a header to the mform (only if its not yet there).
        if ($applyheader) {
            fields_info::add_header_to_mform($mform, self::$header);
        }

        $modes = [
            0 => get_string('skipbookingrulesoptout', 'mod_booking'),
            1 => get_string('skipbookingrulesoptin', 'mod_booking'),
        ];

        $rules = booking_rules::get_list_of_saved_rules_by_context($context->id);
        $ruleschoice = [];

        foreach ($rules as $rule) {
            $ruleobject = json_decode($rule->rulejson);
            $context = context::instance_by_id($rule->contextid);

            if ($context->contextlevel === CONTEXT_SYSTEM) {
                $contextname = get_string('system', 'mod_booking');
            } else {
                $booking = singleton_service::get_instance_of_booking_settings_by_cmid($context->instanceid);
                $contextname = $booking->name;
            }

            $ruleschoice[$rule->id] = "$ruleobject->name ($contextname)";
        }

        $options = [
            'noselectionstring' => get_string('noruleselected', 'mod_booking'),
            'tags' => false,
            'multiple' => true,
        ];

        $mform->addElement(
            'select',
            'skipbookingrulesmode',
            get_string('skipbookingrulesmode', 'mod_booking'),
            $modes,
        );

        $mform->addElement(
            'autocomplete',
            'skipbookingrules',
            get_string('skipbookingrulesrules', 'mod_booking'),
            $ruleschoice,
            $options
        );
    }

    /**
     * Standard function to transfer stored value to form.
     * @param stdClass $data
     * @param booking_option_settings $settings
     * @return void
     * @throws dml_exception
     */
    public static function set_data(stdClass &$data, booking_option_settings $settings) {

        // When importing, we can leave everything as it is.
        // Only when not importing, we need to read from json.
        if (!empty($data->importing) && !empty($data->skipbookingrules)) {
            $data->skipbookingrules = explode(',', $data->skipbookingrules);
        } else {
            $data->skipbookingrulesmode = booking_option::get_value_of_json_by_key($data->id, "skipbookingrulesmode");
            $data->skipbookingrules = booking_option::get_value_of_json_by_key($data->id, "skipbookingrules");
        }
    }

    /**
     * Check to see if on one particular options, rules should not apply.
     *
     * @param int $optionid
     * @param int $ruleid
     *
     * @return bool
     *
     */
    public static function apply_rule(int $optionid, int $ruleid) {

        $rulemode = booking_option::get_value_of_json_by_key($optionid, "skipbookingrulesmode");
        $rulesarray = booking_option::get_value_of_json_by_key($optionid, "skipbookingrules");
        $applyrule = true; // We apply the rules.

        if (!is_array($rulesarray)) {
            $rulesarray = [];
        }
        // If there is no entry or rulemode is 0, we apply all rules, except the ones specified (opt out).
        if (empty($rulemode)) {
            $applyrule = !in_array($ruleid, $rulesarray);
        } else { // Else we only apply those specified (opt in).
            $applyrule = in_array($ruleid, $rulesarray);
        }

        return $applyrule;
    }
}
