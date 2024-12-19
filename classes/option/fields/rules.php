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
 * @copyright 2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\option\fields;

use mod_booking\booking_option;
use mod_booking\booking_option_settings;
use mod_booking\booking_rules\booking_rules;
use mod_booking\option\fields;
use mod_booking\option\fields_info;
use mod_booking\option\field_base;
use mod_booking\singleton_service;
use moodle_url;
use MoodleQuickForm;
use stdClass;

/**
 * Class to handle one property of the booking_option_settings class.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rules extends field_base {

    /**
     * This ID is used for sorting execution.
     * @var int
     */
    public static $id = MOD_BOOKING_OPTION_FIELD_RULES;

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
    public static $alternativeimportidentifiers = ['rules'];

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
        $returnvalue = null): array {

        // REgex ids gecheckte boxen von rules.
        $rulesdeactivated = [];

        foreach ($formdata as $key => $value) {
            // Check if the key matches the pattern 'ruleinactive_' followed by a number.
            if (preg_match('/^ruleinactive_(\d+)$/', $key, $matches)) {
                // If the value is not empty, add the rule ID to the array.
                if (!empty($value)) {
                    $rulesdeactivated[] = (int)$matches[1];
                }
            }
        }
        if (empty($rulesdeactivated)) {
            // This will store the correct JSON to $optionvalues->json.
            booking_option::remove_key_from_json($newoption, "rulesdeactivated");
        } else {
            booking_option::add_data_to_json($newoption, "rulesdeactivated", $rulesdeactivated);
        }

        // TODO: Correctly check for changes!
        $mockdata = new stdClass();
        $mockdata->id = $formdata->id;
        $instance = new rules();
        $changes = $instance->check_for_changes($formdata, $instance, $mockdata);

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

        global $CFG, $COURSE;

        // Standardfunctionality to add a header to the mform (only if its not yet there).
        if ($applyheader) {
            fields_info::add_header_to_mform($mform, self::$header);
        }

        $booking = singleton_service::get_instance_of_booking_by_bookingid($formdata['bookingid'] ?? 0);

        $allrules = booking_rules::get_list_of_saved_rules();

        if (empty($allrules)) {
            $mform->addElement('static', 'norules', '', get_string('norulesfound', 'mod_booking'));
        }
        // Order rules by context.
        $rulesbycontext = [];
        foreach ($allrules as $rule) {
            $rulesbycontext[$rule->contextid][] = $rule;
        }

        foreach ($rulesbycontext as $context => $rules) {
            if (
                !booking_rules::booking_matches_rulecontext($booking->cmid, $context)
            ) {
                unset($rulesbycontext[$context]);
                continue;
            }
            // Add header.
            if ($context == 1) {
                $url = new moodle_url('/mod/booking/edit_rules.php', ['cmid' => $booking->cmid]);
                $mform->addElement(
                    'static',
                    'rulesheader_' . $context,
                    '',
                    get_string('rulesincontextglobalheader', 'mod_booking', $url->out())
                );
            } else {
                $url = new moodle_url('/mod/booking/edit_rules.php', ['cmid' => $booking->cmid]);
                $a = (object) [
                    'rulesincontexturl' => $url->out(),
                    'bookingname' => $booking->settings->name,
                ];
                $mform->addElement('static', 'rulesheader_' . $context, '', get_string('rulesincontextheader', 'mod_booking', $a));
            }

            foreach ($rules as $rule) {
                $data = json_decode($rule->rulejson);
                $mform->addElement(
                    'advcheckbox',
                    'ruleinactive_' . $rule->id,
                    $data->name,
                    get_string('bookingruledeactivate', 'mod_booking'),
                    0,
                    [0, 1]
                );
            }
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

        $deactivatedrules = booking_option::get_value_of_json_by_key($data->id, "rulesdeactivated");
        foreach ($deactivatedrules as $ruleid) {
            $key = 'ruleinactive_' . $ruleid;
            $data->$key = 1;
        }
    }
}
