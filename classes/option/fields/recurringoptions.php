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

use mod_booking\bo_actions\actions_info;
use mod_booking\option\fields_info;
use mod_booking\option\field_base;
use mod_booking\subbookings\subbookings_info;
use MoodleQuickForm;
use stdClass;

/**
 * Class to handle one property of the booking_option_settings class.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recurringoptions extends field_base {

    /**
     * This ID is used for sorting execution.
     * @var int
     */
    public static $id = MOD_BOOKING_OPTION_FIELD_RECURRINGOPTIONS;

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
    public static $header = MOD_BOOKING_HEADER_RECURRINGOPTIONS;

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
        $returnvalue = null): string {

        return parent::prepare_save_field($formdata, $newoption, $updateparam, '');
    }

    /**
     * Instance form definition
     * @param MoodleQuickForm $mform
     * @param array $formdata
     * @param array $optionformconfig
     * @return void
     */
    public static function instance_form_definition(MoodleQuickForm &$mform, array &$formdata, array $optionformconfig) {

        // Templates and recurring 'events' - only visible when adding new.
        if ($formdata['optionid'] == -1) {

            // Workaround: Only show, if it is not turned off in the option form config.
            // We currently need this, because hideIf does not work with headers.
            // In expert mode, we do not hide anything.
            if ($optionformconfig['formmode'] == 'expert' ||
                !isset($optionformconfig['recurringheader']) || $optionformconfig['recurringheader'] == 1) {
                $mform->addElement('header', 'recurringheader',
                            get_string('recurringheader', 'mod_booking'));
            }

            $mform->addElement('checkbox', 'repeatthisbooking',
                        get_string('repeatthisbooking', 'mod_booking'));
            $mform->addElement('text', 'howmanytimestorepeat',
                        get_string('howmanytimestorepeat', 'mod_booking'));
            $mform->setType('howmanytimestorepeat', PARAM_INT);
            $mform->setDefault('howmanytimestorepeat', 1);
            $mform->disabledIf('howmanytimestorepeat', 'repeatthisbooking', 'notchecked');
            $howoften = [
                86400 => get_string('day'),
                604800 => get_string('week'),
                2592000 => get_string('month'),
            ];
            $mform->addElement('select', 'howoftentorepeat', get_string('howoftentorepeat', 'mod_booking'),
                        $howoften);
            $mform->setType('howoftentorepeat', PARAM_INT);
            $mform->setDefault('howoftentorepeat', 86400);
            $mform->disabledIf('howoftentorepeat', 'repeatthisbooking', 'notchecked');
        }
    }
}
