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
use mod_booking\option\field_base;
use mod_booking\singleton_service;
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
class responsiblecontact extends field_base {
    /**
     * This ID is used for sorting execution.
     * @var int
     */
    public static $id = MOD_BOOKING_OPTION_FIELD_RESPONSIBLECONTACT;

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
    public static $header = MOD_BOOKING_HEADER_RESPONSIBLECONTACT;

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
     * @return string // If no warning, empty string.
     */
    public static function prepare_save_field(
        stdClass &$formdata,
        stdClass &$newoption,
        int $updateparam,
        $returnvalue = null
    ): array {

        $instance = new responsiblecontact();
        $mockclass = new stdClass();
        $mockclass->id = $formdata->id ?? 1;
        $changes = $instance->check_for_changes($formdata, $instance, $mockclass);

        // Here to convert the multiple contacts array and save it as string.
        if (!empty($formdata->responsiblecontact)) {
            $formdata->responsiblecontact = implode(',', $formdata->responsiblecontact);
        }
        parent::prepare_save_field($formdata, $newoption, $updateparam, 0);

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

        $mform->addElement(
            'header',
            'responsiblecontactheader',
            '<i class="fa fa-fw fa-user" aria-hidden="true"></i>&nbsp;' . get_string('responsiblecontact', 'mod_booking')
        );

        // Responsible contact person - autocomplete.
        $options = [
            'ajax' => 'mod_booking/form_users_selector',
            'multiple' => true,
            'noselectionstring' => get_string('choose...', 'mod_booking'),
            'valuehtmlcallback' => function ($value) {
                global $OUTPUT;
                if (empty($value)) {
                    return get_string('choose...', 'mod_booking');
                }
                $user = singleton_service::get_instance_of_user((int)$value);
                if (!$user || !user_can_view_profile($user)) {
                    return false;
                }
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
            'responsiblecontact',
            get_string('responsiblecontact', 'mod_booking'),
            [],
            $options
        );
        $mform->addHelpButton('responsiblecontact', 'responsiblecontact', 'mod_booking');
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
            if (empty($data->responsiblecontact)) {
                $data->responsiblecontact = $settings->responsiblecontact ?? [];
            }
        } else {
            // We are importing.
            if (!empty($data->responsiblecontact)) {
                // We set throwerror to true...
                // ... because on importing, we want it to fail, if responsiblecontact is not found.
                if (is_string($data->responsiblecontact)) {
                    $userids = teachers_handler::get_user_ids_from_string($data->responsiblecontact, true);
                    $data->responsiblecontact = $userids ?? [];
                } else if (is_array($data->responsiblecontact)) {
                    // If it's already an array, we assume it's userids.
                    return;
                } else {
                    // If it's not a string or array, we set it to an empty array.
                    $data->responsiblecontact = [];
                }
            } else {
                $data->responsiblecontact = $settings->responsiblecontact ?? [];
            }
        }
    }

    /**
     * Save data
     * @param stdClass $formdata
     * @param stdClass $option
     * @return void
     * @throws \dml_exception
     */
    public static function save_data(stdClass &$formdata, stdClass &$option) {
        global $DB;
        $cmid = $formdata->cmid;
        $optionid = $option->id;
        if (!empty($cmid) && !empty($optionid)) {
            // Check if we need to enrol responsible contact users.
            if (get_config('booking', 'responsiblecontactenroltocourse')) {
                $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
                $bookingoption = singleton_service::get_instance_of_booking_option($cmid, $optionid);
                $oldcontacts = $settings->responsiblecontact;
                if (empty($formdata->responsiblecontact)) {
                    $formdata->responsiblecontact = '';
                }
                $newcontacts = array_map('trim', explode(',', $formdata->responsiblecontact ?? ''));

                // Now get the role id for the responsible contacts and enroll them if they are newcontacts.
                foreach ($newcontacts as $newcontact) {
                    if (!empty($newcontact) && !in_array($newcontact, $oldcontacts)) {
                        $roleid = (int) get_config('booking', 'definedresponsiblecontactrole');
                        if (empty($roleid)) {
                            $roleid = 0;
                        }
                        $courseid = $formdata->courseid ?? 0;
                        if (!empty($courseid)) {
                            $bookingoption->enrol_user((int) $newcontact, false, $roleid, false, $courseid, true);
                        }
                    }
                }
                // We need to unenrol the oldcontacts contacts, that are not in the newcontacts array.
                foreach ($oldcontacts as $oldcontact) {
                    if (!empty($oldcontact) && !in_array($oldcontact, $newcontacts)) {
                        $bookingoption->unenrol_user((int)$oldcontact);
                    }
                }
            }
        }
    }
}
