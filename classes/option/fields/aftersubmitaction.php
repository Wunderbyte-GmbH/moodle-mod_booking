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
 * Action after submit of edit option form.
 *
 * @package mod_booking
 * @copyright 2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @author  Bernhard Fischer-Sengseis
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
 * Class to handle one property of the booking_option_settings class.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class aftersubmitaction extends field_base {

    /**
     * This ID is used for sorting execution.
     * @var int
     */
    public static $id = MOD_BOOKING_OPTION_FIELD_AFTERSUBMITACTION;

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
    public static $header = MOD_BOOKING_HEADER_BOOKINGOPTIONTEXT;

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
        $returnvalue = null): array {

        if (isset($formdata->aftersubmitaction)) {
            switch ($formdata->aftersubmitaction) {
                case 'submitandadd':
                    $newreturnurl = new moodle_url('/mod/booking/editoptions.php', [
                        'id' => $formdata->cmid,
                        'optionid' => null,
                        'returnto' => 'url',
                        'returnurl' => $formdata->returnurl,
                    ]);
                    $formdata->returnurl = $newreturnurl->out(false);
                    $newoption->returnurl = $newreturnurl->out(false);
                    break;
                case 'submitandstay':
                    if (empty($newoption->id)) {
                        break;
                    }
                    $newreturnurl = new moodle_url('/mod/booking/editoptions.php', [
                        'id' => $formdata->cmid,
                        'optionid' => $newoption->id,
                        'returnto' => 'url',
                        'returnurl' => $formdata->returnurl,
                    ]);
                    $formdata->returnurl = $newreturnurl->out(false);
                    $newoption->returnurl = $newreturnurl->out(false);
                    break;
                case 'submitandgoback':
                    if (empty($formdata->returnurl)) {
                        $newreturnurl = new moodle_url('/mod/booking/view.php', [
                            'id' => $formdata->cmid,
                            'optionid' => $newoption->id,
                        ]);
                        $formdata->returnurl = $newreturnurl->out(false);
                        $newoption->returnurl = $newreturnurl->out(false);
                    } else {
                        $newoption->returnurl = $formdata->returnurl;
                    }
                    break;
            }
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
     */
    public static function instance_form_definition(
        MoodleQuickForm &$mform,
        array &$formdata,
        array $optionformconfig,
        $fieldstoinstanciate = [],
        $applyheader = true
    ) {

        // What to do after submit button is pressed.
        $aftersubmitactions = [
            'submitandgoback' => get_string ('submitandgoback', 'mod_booking'), // Go back to returnurl.
            'submitandstay' => get_string ('submitandstay', 'mod_booking'), // Stay on edit option form.
            'submitandadd' => get_string ('submitandadd', 'mod_booking'), // Create a new option after submit.
        ];
        // For new booking options, we cannot stay on page, because we have not optionid for returnurl.
        if (empty($formdata['id'])) {
            unset($aftersubmitactions['submitandstay']);
        }

        $mform->closeHeaderBefore('aftersubmitaction');
        $mform->addElement('select', 'aftersubmitaction', get_string('aftersubmitaction', 'mod_booking'), $aftersubmitactions);
    }

    /**
     * Standard function to transfer stored value to form.
     * @param stdClass $data
     * @param booking_option_settings $settings
     * @return void
     * @throws dml_exception
     */
    public static function set_data(stdClass &$data, booking_option_settings $settings) {
        return;
    }
}
