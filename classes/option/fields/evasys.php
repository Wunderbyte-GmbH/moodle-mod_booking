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
 * Evasys Option Form field.
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

 namespace mod_booking\option\fields;

 use mod_booking\booking_option_settings;
 use mod_booking\local\evasys_evaluation;
 use mod_booking\option\field_base;
 use mod_booking\option\fields_info;
 use MoodleQuickForm;
 use stdClass;

 /**
  * EvaSys evaluation field for booking options.
  */
class evasys extends field_base {
    /**
     * This ID is used for sorting execution.
     * @var int
     */
    public static $id = MOD_BOOKING_OPTION_FIELD_EVASYS;

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
    public static $header = MOD_BOOKING_HEADER_EVASYS;

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
     * Prepare Savefield
     *
     * @param stdClass $formdata
     * @param stdClass $newoption
     * @param int $updateparam
     * @param mixed $returnvalue
     *
     * @return array
     *
     */
    public static function prepare_save_field(
        stdClass &$formdata,
        stdClass &$newoption,
        int $updateparam,
        $returnvalue = null
    ): array {
        return [];
    }

    /**
     * Define Form.
     *
     * @param MoodleQuickForm $mform
     * @param array $formdata
     * @param array $optionformconfig
     * @param array $fieldstoinstanciate
     * @param bool $applyheader
     *
     * @return void
     *
     */
    public static function instance_form_definition(
        MoodleQuickForm &$mform,
        array &$formdata,
        array $optionformconfig,
        $fieldstoinstanciate = [],
        $applyheader = true
    ): void {

        if (empty(get_config('mod_booking', 'evasyssubunits'))) {
            return;
        }

        // Curl evasys for questionaires.
        $questionaires = [1, 2, 3];
        // TODO: Curl evasys for additional recpients.
        $recipients = ['David', 'NichtDavid'];

        if (empty(get_config('booking', 'useevasys'))) {
            return;
        }

        $mform->addElement(
            'header',
            'evasysheader',
            '<i class="fa fa-clipboard" aria-hidden="true"></i>&nbsp;' .
            get_string('evasysheader', 'booking')
        );

        $mform->addElement(
            'autocomplete',
            'evasys_questionaire',
            get_string('evasys_questionaire', 'mod_booking'),
            $questionaires
        );

        $mform->addElement(
            'date_selector',
            'evasys_evaluation_starttime',
            get_string('evasys_evaluation_starttime', 'mod_booking')
        );
        $mform->addElement(
            'date_selector',
            'evasys_evaluation_endtime',
            get_string('evasys_evaluation_endtime', 'mod_booking')
        );

        $mform->addElement(
            'autocomplete',
            'evasys_other_report_recipients',
            get_string('evasys_other_report_recipients', 'mod_booking'),
            $recipients,
            ['multiple' => true]
        );

        $mform->addElement(
            'advcheckbox',
            'evasys_notifyparticipants',
            get_string('evasys_notifyparticipants', 'mod_booking'),
            '',
            [],
            [0, 1],
        );
        $mform->addElement(
            'hidden',
            'evasys_id',
            0
        );
        $mform->setType('evasys_id', PARAM_INT);
    }

    /**
     * Load Form data from DB.
     *
     * @param stdClass $data
     * @param booking_option_settings $settings
     *
     * @return void
     *
     */
    public static function set_data(&$data, $settings) {
        $evasys = new evasys_evaluation();
        $evasys->load_form($data);
    }

    /**
     * Save Form data
     * @param stdClass $formdata
     * @param stdClass $option
     * @return void
     * @throws \dml_exception
     */
    public static function save_data(&$formdata, &$option) {
        $evasys = new evasys_evaluation();
        $evasys->save_form($formdata, $option);
    }
}
