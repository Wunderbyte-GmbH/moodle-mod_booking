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
 * Booking option field class.
 *
 * @package bookingextension_confirmation_trainer
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer-Sengseis
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace bookingextension_confirmation_trainer\option\fields;

use mod_booking\booking_option_settings;
use mod_booking\option\fields_info;
use mod_booking\option\field_base;
use MoodleQuickForm;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/bookingextension/confirmation_trainer/lib.php');

/**
 * Booking option field class.
 *
 * @package bookingextension_confirmation_trainer
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer-Sengseis
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class confirmation_trainer extends field_base {
    /**
     * This subplugin component.
     * @var string
     */
    public static $subplugin = 'bookingextension_confirmation_trainer';

    /**
     * This ID is used for sorting execution.
     * @var int
     */
    public static $id = MOD_BOOKING_OPTION_FIELD_CONFIRMATION_TRAINER;

    /**
     * The header identifier.
     * @var string
     */
    public static $header = MOD_BOOKING_HEADER_ASKFORCONFIRMATION;

    /**
     * The icon for the field's header icon.
     * @var string
     */
    public static $headericon = '<i class="fa fa-cubes" aria-hidden="true"></i>&nbsp;';

    /**
     * Some fields are saved with the booking option...
     * This is normal behaviour.
     * Some can be saved only post save (when they need the option id).
     * @var int
     */
    public static $save = MOD_BOOKING_EXECUTION_NORMAL;

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

        $changes = [];

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
     *
     */
    public static function instance_form_definition(
        MoodleQuickForm &$mform,
        array &$formdata,
        array $optionformconfig,
        $fieldstoinstanciate = [],
        $applyheader = true
    ) {
        // Add header of subplugin to the mform (only if its not yet there).
        if ($applyheader) {
            $elementexists = $mform->elementExists(self::$header);
            if (!$elementexists) {
                $mform->addElement(
                    'header',
                    self::$header,
                    self::$headericon . get_string(self::$header, self::$subplugin)
                );
            }
        }

        $mform->addElement('advcheckbox', 'confirmation_trainer_enabled', get_string('confirmation_trainer_enabled', 'bookingextension_confirmation_trainer'));
        $mform->hideIf('confirmation_trainer_enabled', 'waitforconfirmation', 'neq', 1);
        $mform->addElement(
            'static',
            'waitforconfirmationdescription',
            '',
            get_string('workflowdescription', 'bookingextension_confirmation_trainer')
        );
        $mform->hideIf('waitforconfirmationdescription', 'waitforconfirmation', 'neq', 1);
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

        // CAll API to update the name & description.
        $confirmation_trainerhandler = new confirmation_trainer_handler();
        $confirmation_trainerhandler->changes_collected_action($changes, $data, $newoption, $originaloption);
    }
}
