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
 use mod_booking\local\evasys_handler;
 use mod_booking\local\evasys_helper_service;
 use mod_booking\local\evasys_soap_service;
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
     * List of Evasyskeys.
     *
     * @var array
     */
    public static $evasyskeys = ['evasys_questionaire', 'evasys_starttime', 'evasys_endtime', 'evasys_other_report_recipients', 'evasysperiods', 'evasys_notifyparticipants'];

    /**
     * Relevant Keys to update survey to API.
     *
     * @var array
     */
    public static $relevantkeyssurvey = ['evasys_questionaire', 'evasysperiods'];

    /**
     * Relevant Keys when a course need to be upgraded.
     *
     * @var array
     */
    public static $relevantkeyscourse = ['evasys_other_report_recipients'];

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
        $evasys = new evasys_handler();
        $forms = ['tags' => false,
        'multiple' => false,
        'noselectionstring' => '',
        'ajax' => 'mod_booking/form_evasysquestionaire_selector',
        'valuehtmlcallback' => function ($value) {
            if (empty($value)) {
                return get_string('choose...', 'mod_booking');
            }
            $array = explode('-', $value);
            $name = end($array);
            $return = base64_decode($name);
            return $return;
        },
        ];

        $recipients = $evasys->get_recipients();
        $periodoptions = [
        'tags' => false,
        'multiple' => false,
        'noselectionstring' => '',
        'ajax' => 'mod_booking/form_evasysperiods_selector',
        'valuehtmlcallback' => function ($value) {
            if (empty($value)) {
                return get_string('choose...', 'mod_booking');
            }
            $array = explode('-', $value);
            $name = end($array);
            $return = base64_decode($name);
            return $return;
        },
        ];


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
            [],
            $forms,
        );

        $options = [
            0 => get_string('evasys:timemodeduration', 'mod_booking'),
            1 => get_string('evasys:timemodestart', 'mod_booking'),

        ];
        $mform->addElement(
            'select',
            'evasys_timemode',
            get_string('evasys:timemode', 'mod_booking'),
            $options
        );
        $mform->setDefault('evasys_timemode', 0);

        $beforestartoptions = [
            - 86400 => "24",
            -7200 => "2",
            -3600 => "1",
            0 => "0",
        ];

        // Add date selectors.
        $mform->addElement(
            'select',
            'evasys_durationbeforestart',
            get_string('evasys:evaluation:durationbeforestart', 'mod_booking'),
            $beforestartoptions
        );
        $mform->setDefault('_durationbeforestart', -7200);
        $afterendoptions = [
            86400 => "24",
            172800 => "48",
            604800 => "168",
            1209600 => "336",
        ];
        // Add date selectors.
        $mform->addElement(
            'select',
            'evasys_durationafterend',
            get_string('evasys:evaluation:durationafterend', 'mod_booking'),
            $afterendoptions
        );
        $mform->setDefault('evasys_durationafterend', 172800);

        // Add date selectors.
        $mform->addElement(
            'date_selector',
            'evasys_starttime',
            get_string('evasys:evaluation_starttime', 'mod_booking')
        );
        $mform->addElement(
            'date_selector',
            'evasys_endtime',
            get_string('evasys:evaluation_endtime', 'mod_booking')
        );

        // Hide date selectors unless "duration" (option 1) is selected.
        $mform->hideIf('evasys_starttime', 'evasys_timemode', 'noteq', 1);
        $mform->hideIf('evasys_endtime', 'evasys_timemode', 'noteq', 1);
        $mform->hideIf('evasys_durationafterend', 'evasys_timemode', 'noteq', 0);
        $mform->hideIf('evasys_durationbeforestart', 'evasys_timemode', 'noteq', 0);


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
            [],
            $periodoptions,
        );
        $mform->addElement(
            'advcheckbox',
            'evasys_notifyparticipants',
            get_string('evasys:notifyparticipants', 'mod_booking'),
            '',
            [],
            [0, 1],
        );

        $mform->hideIf('evasys_timemode', 'evasys_questionaire', 'eq', '');
        $mform->hideIf('evasys_other_report_recipients', 'evasys_questionaire', 'eq', '');
        $mform->hideIf('evasysperiods', 'evasys_questionaire', 'eq', '');
        $mform->hideIf('evasys_notifyparticipants', 'evasys_questionaire', 'eq', '');
        $mform->hideIf('evasys_durationafterend', 'evasys_questionaire', 'eq', '');
        $mform->hideIf('evasys_durationbeforestart', 'evasys_questionaire', 'eq', '');

        $mform->addElement(
            'hidden',
            'evasys_booking_id',
            0
        );
        $mform->setType('evasys_booking_id', PARAM_INT);

        $mform->addElement(
            'hidden',
            'evasys_formid',
            0
        );
        $mform->setType('evasys_formid', PARAM_INT);

        $mform->addElement(
            'hidden',
            'evasys_courseidexternal',
            0
        );
        $mform->setType('evasys_courseidexternal', PARAM_TEXT);

        $mform->addElement(
            'hidden',
            'evasys_courseidinternal',
            ''
        );
        $mform->setType('evasys_courseidinternal', PARAM_INT);

        $mform->addElement(
            'hidden',
            'evasys_surveyid',
            0
        );
        $mform->setType('evasys_surveyid', PARAM_INT);
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
        $evasys = new evasys_handler();
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
        $evasys = new evasys_handler();
        $evasys->save_form($formdata, $option);
        if (empty($formdata->teachersforoption)) {
            return;
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
        global $DB;
        if (empty($data->teachersforoption)) {
            return;
        }
        $helper = new evasys_helper_service();
        $evasys = new evasys_handler();
        if (empty($data->evasys_courseidexternal)) {
            $coursedata = $evasys->aggregate_data_for_course_save($data, $newoption);
            $courseresponse = $evasys->save_course($data, $coursedata);
            $args = $helper->set_args_insert_survey(
                $courseresponse->m_nUserId,
                $courseresponse->m_nCourseId,
                $data->evasys_questionaire,
                $courseresponse->m_nPeriodId,
            );
            $id = $data->evasys_booking_id;
            $evasys->save_survey($args, $id);
        } else {
            $now = time();
            if ($now < $data->_starttime) {
                return;
            }
            if ($helper->delete_condition_met($changes) && !empty($data->id)) {
                    // Delete Survey.
                    $service = new evasys_soap_service();
                    $helper = new evasys_helper_service();
                    $argssurvey = $helper->set_args_delete_survey($data->evasys_surveyid);
                    $service->delete_survey($argssurvey);
                    // Delete Course.
                    $argscourse = $helper->set_args_delete_course($data->evasys_courseidinternal);
                    $service->delete_course($argscourse);
                    $DB->delete_records('booking_evasys', ['id' => $data->evasys_booking_id]);
                    return;
            }
            $updatesurvey = false;
            $updatecourse = false;
            // Checks if the survey and therefore the course needs to be updated.
            foreach ($changes["mod_booking\\option\\fields\\evasys"]['changes'] as $key => $value) {
                if (in_array($key, self::$relevantkeyssurvey, true)) {
                    $updatesurvey = true;
                }
            }
        // Checks for the only key where only the course needs to be updated.
            if (!$updatesurvey && isset($changes["mod_booking\\option\\fields\\evasys"]['changes'][self::$relevantkeyscourse])) {
                $updatecourse = true;
            }
            if ($updatesurvey) {
                $evasys = new evasys_handler();
                $surveyid = $data->evasys_surveyid;
                $evasys->update_survey($surveyid, $data, $newoption);
            }
            if ($updatecourse) {
                $evasys->aggregate_data_for_course_save($data, $newoption, $data->evasys_booking_id);
            }
        }
    }
}
