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


 use core_user;
 use mod_booking\booking_option_settings;
 use mod_booking\local\evasys_evaluation;
 use mod_booking\option\field_base;
 use mod_booking\option\fields_info;
 use mod_booking\singleton_service;
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
     * List of Evasyskeys.
     *
     * @var array
     */
    public static $evasyskeys = ['evasys_questionaire', 'evasys_evaluation_starttime', 'evasys_evaluation_endtime', 'evasys_other_report_recipients', 'evasysperiods', 'evasys_notifyparticipants'];

    /**
     * Relevant Keys to conract API.
     *
     * @var array
     */
    public static $relevantkeys = ['evasys_questionaire', 'evasys_other_report_recipients', 'evasysperiods'];

    /**
     * Prepare Savefield.
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
        $instance = new evasys();
        $changes = [];
        if (empty($formdata->id)) {
            return $changes;
        } else {
            foreach (self::$evasyskeys as $key) {
                $value = $formdata->{$key} ?? null;
                $mockdata = (object)['optionid' => $formdata->id];
                $changeinkey = $instance->check_for_changes($formdata, $instance, $mockdata, $key, $value);
                if (!empty($changeinkey)) {
                    $changes['changes'][$key] = $changeinkey;
                }
            }
        }
        return $changes;
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

        if (empty(get_config('booking', 'evasyssubunits'))) {
            return;
        }
        $evasys = new evasys_evaluation();
        $forms = $evasys->get_allforms();
        $recipients = $evasys->get_recipients();
        $periods = $evasys->get_periods_for_option();

        if (empty(get_config('booking', 'useevasys'))) {
            return;
        }

        if ($applyheader) {
            fields_info::add_header_to_mform($mform, self::$header);
        }
        $mform->addElement(
            'autocomplete',
            'evasys_questionaire',
            get_string('evasys:questionaire', 'mod_booking'),
            $forms
        );

        $mform->addElement(
            'date_selector',
            'evasys_evaluation_starttime',
            get_string('evasys:evaluation_starttime', 'mod_booking')
        );
        $mform->addElement(
            'date_selector',
            'evasys_evaluation_endtime',
            get_string('evasys:evaluation_endtime', 'mod_booking')
        );

        $mform->addElement(
            'autocomplete',
            'evasys_other_report_recipients',
            get_string('evasys:other_report_recipients', 'mod_booking'),
            $recipients,
            ['multiple' => true],
        );

        $mform->addElement(
            'autocomplete',
            'evasysperiods',
            get_string('evasysperiods', 'mod_booking'),
            $periods,
        );

        $mform->addElement(
            'advcheckbox',
            'evasys_notifyparticipants',
            get_string('evasys:notifyparticipants', 'mod_booking'),
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
        if (empty($formdata->teachersforoption)) {
            return;
        }
            // Check if Customfield for Option exists.
        $instance = singleton_service::get_instance_of_booking_option($formdata->cmid, $option->id);
        if (empty($instance->option->evasysid)) {
            $coursedata = $evasys->aggregate_data_for_course_save($formdata, $option);
            $evasys->save_course($option, $coursedata);
        }
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
     */
    public static function changes_collected_action(
        array $changes,
        object $data,
        object $newoption,
        object $originaloption
    ) {
        if (empty($data->teachersforoption)) {
            return;
        }
        $sendtoapi = false;
        foreach ($changes["mod_booking\\option\\fields\\evasys"]['changes'] as $key => $value) {
            if (in_array($key, self::$relevantkeys, true)) {
                $sendtoapi = true;
            }
        }
        if ($sendtoapi) {
            $evasys = new evasys_evaluation();
            $instance = singleton_service::get_instance_of_booking_option($data->cmid, $newoption->id);
            $evasysid = $instance->option->evasysid;
            $internalid = end(explode(',', $evasysid));
            $coursedata = $evasys->aggregate_data_for_course_save($data, $newoption, $internalid);
            $evasys->update_course($coursedata);
        }
    }
}
