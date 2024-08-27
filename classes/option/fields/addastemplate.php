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

use mod_booking\option\fields;
use mod_booking\option\fields_info;
use mod_booking\option\field_base;
use mod_booking\utils\wb_payment;
use MoodleQuickForm;
use stdClass;

/**
 * Class to handle one property of the booking_option_settings class.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class addastemplate extends field_base {

    /**
     * This ID is used for sorting execution.
     * @var int
     */
    public static $id = MOD_BOOKING_OPTION_FIELD_TEMPLATESAVE;

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
    public static $header = MOD_BOOKING_HEADER_TEMPLATESAVE;

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

        // When the addastemplate is not null, we set bookingid to 0.
        if (!empty($formdata->addastemplate)) {
            $newoption->bookingid = 0;
        }

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

        global $DB;

        // Templates - only visible when adding new.
        if (has_capability('mod/booking:manageoptiontemplates', $formdata['context'])
            && ($formdata['id'] < 1
                || ($formdata['addastemplate'] ?? 0) > 0)) {

            $mform->addElement('header', 'templateheader',
                '<i class="fa fa-fw fa-clone" aria-hidden="true"></i>&nbsp;' .
                get_string('addastemplate', 'mod_booking'));

            $numberoftemplates = $DB->count_records('booking_options', ['bookingid' => 0]);

            if ($numberoftemplates < 1 || wb_payment::pro_version_is_activated()) {
                $addastemplate = [
                        0 => get_string('notemplate', 'mod_booking'),
                        1 => get_string('asglobaltemplate', 'mod_booking'),
                ];
                $mform->addElement('select', 'addastemplate', get_string('addastemplate', 'mod_booking'),
                        $addastemplate);
                $mform->setType('addastemplate', PARAM_INT);
            } else {
                $mform->addElement('static', 'nolicense', get_string('licensekeycfg', 'mod_booking'),
                    get_string('licensekeycfgdesc', 'mod_booking'));
            }
        }
    }
}
