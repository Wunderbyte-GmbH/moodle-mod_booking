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

use mod_booking\bo_availability\bo_info;
use mod_booking\booking_option_settings;
use mod_booking\option\field_base;
use mod_booking\option\fields_info;
use mod_booking\singleton_service;
use MoodleQuickForm;
use stdClass;

/**
 * Class to handle one property of the booking_option_settings class.
 *
 * Courseendtime is fully replaced with the optiondates class.
 * Its only here as a placeholder.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class easy_availability_selectusers extends field_base {
    /**
     * This ID is used for sorting execution.
     * @var int
     */
    public static $id = MOD_BOOKING_OPTION_FIELD_EASY_AVAILABILITY_SELECTUSERS;

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
    public static $header = MOD_BOOKING_HEADER_AVAILABILITY;

    /**
     * An int value to define if this field is standard or used in a different context.
     * @var array
     */
    public static $fieldcategories = [MOD_BOOKING_OPTION_FIELD_EASY];

    /**
     * Additionally to the classname, there might be others keys which should instantiate this class.
     * @var array
     */
    public static $alternativeimportidentifiers = [];

    /**
     * This is an array of incompatible field ids.
     * @var array
     */
    public static $incompatiblefields = [
        MOD_BOOKING_OPTION_FIELD_AVAILABILITY,
    ];

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

        // Select users condition.
        if ($formdata->bo_cond_selectusers_restrict == 1 && !empty(($formdata->bo_cond_selectusers_userids))) {
            $formdata->bo_cond_selectusers_overrideconditioncheckbox = true; // Can be hardcoded here.
            $formdata->bo_cond_selectusers_overrideoperator = 'OR'; // Can be hardcoded here.

            // We always override these conditions, so users are always allowed to book outside time restrictions.
            $formdata->bo_cond_selectusers_overridecondition = [
                MOD_BOOKING_BO_COND_BOOKING_TIME,
                MOD_BOOKING_BO_COND_OPTIONHASSTARTED,
            ];

            // If the overbook checkbox has been checked, we also add the conditions so the user(s) can overbook.
            if (!empty($formdata->selectusersoverbookcheckbox)) {
                $formdata->bo_cond_selectusers_overridecondition[] = MOD_BOOKING_BO_COND_FULLYBOOKED;
                $formdata->bo_cond_selectusers_overridecondition[] = MOD_BOOKING_BO_COND_NOTIFYMELIST;
            }
        } else {
            $formdata->bo_cond_selectusers_restrict = 0;
        }

        // Here we have to make sure we don't override anything.
        $tempform = new stdClass();
        bo_info::set_defaults($tempform, json_decode($formdata->availability ?? '{}'));

        foreach ($tempform as $key => $value) {
            if (!isset($formdata->{$key})) {
                $formdata->{$key} = $value;
            }
        }

        // Save the additional JSON conditions (the ones which have been added to the mform).
        bo_info::save_json_conditions_from_form($formdata);
        $newoption->availability = $formdata->availability;

        return [];
    }

    /**
     * This function adds error keys for form validation.
     * @param array $formdata
     * @param array $files
     * @param array $errors
     * @return array
     */
    public static function validation(array $formdata, array $files, array &$errors) {

        return $errors;
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

        // Add the selectusers condition:
        // Select users who can override booking_time condition.
        $mform->addElement('advcheckbox', 'bo_cond_selectusers_restrict', get_string('easyavailability:selectusers', 'local_musi'));

        $mform->addElement('checkbox', 'selectusersoverbookcheckbox', get_string('easyavailability:overbook', 'local_musi'));
        $mform->setDefault('selectusersoverbookcheckbox', 'checked');
        $mform->hideIf('selectusersoverbookcheckbox', 'bo_cond_selectusers_restrict', 'notchecked');

        $options = [
            'multiple' => true,
            'noselectionstring' => get_string('choose...', 'mod_booking'),
            'ajax' => 'local_shopping_cart/form_users_selector',
            'valuehtmlcallback' => function ($value) {
                global $OUTPUT;
                if (empty($value)) {
                    return get_string('choose...', 'mod_booking');
                }
                $user = singleton_service::get_instance_of_user((int)$value);
                $details = [
                    'id' => $user->id,
                    'email' => $user->email,
                    'firstname' => $user->firstname,
                    'lastname' => $user->lastname,
                ];
                return $OUTPUT->render_from_template(
                    'mod_booking/form-user-selector-suggestion',
                    $details
                );
            },
        ];
        $mform->addElement(
            'autocomplete',
            'bo_cond_selectusers_userids',
            get_string('bocondselectusersuserids', 'mod_booking'),
            [],
            $options
        );
        $mform->hideIf('bo_cond_selectusers_userids', 'bo_cond_selectusers_restrict', 'notchecked');

        // This is to transmit the original availability values.
        if (!$mform->elementExists('availability')) {
            $mform->addElement('hidden', 'availability');
            $mform->setType('availability', PARAM_RAW);
        }

        $mform->addElement('html', '<hr>');
    }

    /**
     * Standard function to transfer stored value to form.
     * @param stdClass $formdata
     * @param booking_option_settings $settings
     * @return void
     * @throws dml_exception
     */
    public static function set_data(stdClass &$formdata, booking_option_settings $settings) {

        if (!empty($settings->availability)) {
            $availabilityarray = json_decode($settings->availability ?? '{}');
            foreach ($availabilityarray as $av) {
                switch ($av->id) {
                    case MOD_BOOKING_BO_COND_JSON_SELECTUSERS:
                        if (!empty($av->userids)) {
                            $formdata->bo_cond_selectusers_restrict = true;
                            $formdata->bo_cond_selectusers_userids = $av->userids;
                        }
                        if (
                            in_array(MOD_BOOKING_BO_COND_FULLYBOOKED, $av->overrides ?? []) &&
                            in_array(MOD_BOOKING_BO_COND_NOTIFYMELIST, $av->overrides ?? [])
                        ) {
                            $formdata->selectusersoverbookcheckbox = true;
                        } else {
                            $formdata->selectusersoverbookcheckbox = false;
                        }
                        break;
                }
            }
        }
        // We will always transmit the initial values.
        $formdata->availability = $settings->availability ?? '{}';
    }
}
