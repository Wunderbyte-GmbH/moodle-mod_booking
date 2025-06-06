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
    public static $evasyskeys = ['evasys_form', 'evasys_starttime', 'evasys_endtime', 'evasys_other_report_recipients', 'evasysperiods', 'evasys_notifyparticipants'];

    /**
     * Relevant Keys to update survey to API.
     *
     * @var array
     */
    public static $relevantkeyssurvey = ['evasys_form', 'evasysperiods'];

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
     * This function adds error keys for form validation.
     * @param array $formdata
     * @param array $files
     * @param array $errors
     * @return array
     */
    public static function validation(array $formdata, array $files, array &$errors) {
        $settings = singleton_service::get_instance_of_booking_option_settings($formdata['id']);
        if (
            empty($formdata['evasys_form'])
            && !empty($settings->evasys->formid)
            && empty($formdata['evasys_confirmdelete'])
        ) {
                $errors['evasys_confirmdelete'] = get_string('evasys:delete', 'mod_booking');
        }
        if (
            !empty($formdata['evasys_form'])
            && empty($formdata['evasys_timemode'])
            && empty($formdata['courseendtime_1'])
        ) {
            $errors['evasys_timemode'] = get_string('evasys:setcourseendtime', 'mod_booking');
        }
        return $errors;
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
            'evasys_form',
            get_string('evasys:questionaire', 'mod_booking'),
            [],
            $forms,
        );
        $mform->addHelpButton('evasys_form', 'evasys:questionaire', 'mod_booking');
        $mform->addElement(
            'advcheckbox',
            'evasys_confirmdelete',
            get_string('evasys:confirmdelete', 'mod_booking'),
            '',
            [],
            [0, 1],
        );

        $mform->hideIf('evasys_confirmdelete', 'evasys_delete', 'eq', 0);
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
        $mform->setDefault('evasys_durationbeforestart', -7200);
        $afterendoptions = [
            7200 => "2",
            86400 => "24",
            172800 => "48",
            604800 => "168",
            1209600 => "336",
        ];

        $mform->addElement(
            'select',
            'evasys_durationafterend',
            get_string('evasys:evaluation:durationafterend', 'mod_booking'),
            $afterendoptions
        );
        $mform->setDefault('evasys_durationafterend', 7200);

        $mform->addElement(
            'date_time_selector',
            'evasys_starttime',
            get_string('evasys:evaluation_starttime', 'mod_booking')
        );
        $starttimestamp = strtotime('+1 days');
        $mform->setDefault('evasys_starttime', $starttimestamp);

        $mform->addElement(
            'date_time_selector',
            'evasys_endtime',
            get_string('evasys:evaluation_endtime', 'mod_booking')
        );
        $endtimestamp = strtotime('+2 days');
        $mform->setDefault('evasys_endtime', $endtimestamp);

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
        $mform->setDefault('evasysperiods', get_config('booking', 'evasysperiods'));
        $mform->addElement(
            'advcheckbox',
            'evasys_notifyparticipants',
            get_string('evasys:notifyparticipants', 'mod_booking'),
            '',
            [],
            [0, 1],
        );
        // Hide Everything if no Questionaire was chosen.
        $mform->hideIf('evasys_timemode', 'evasys_form', 'eq', '');
        $mform->hideIf('evasys_other_report_recipients', 'evasys_form', 'eq', '');
        $mform->hideIf('evasysperiods', 'evasys_form', 'eq', '');
        $mform->hideIf('evasys_notifyparticipants', 'evasys_form', 'eq', '');
        $mform->hideIf('evasys_durationafterend', 'evasys_form', 'eq', '');
        $mform->hideIf('evasys_durationbeforestart', 'evasys_form', 'eq', '');

        $mform->addElement(
            'hidden',
            'evasys_booking_id',
            0
        );
        $mform->setType('evasys_booking_id', PARAM_INT);

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

        $mform->addElement('hidden', 'evasys_surveyid', 0);
        $mform->setType('evasys_surveyid', PARAM_INT);

        $mform->addElement('hidden', 'evasys_delete', 0);
        $mform->setDefault('evasys_delete', 0);
        $mform->setType('evasys_delete', PARAM_INT);

        $mform->addElement('hidden', 'evasys_qr', 0);
        $mform->setType('evasys_qr', PARAM_TEXT);
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
     * Definition after data callback.
     *
     * @param MoodleQuickForm $mform
     * @param mixed $formdata
     *
     * @return void
     *
     */
    public static function definition_after_data(MoodleQuickForm &$mform, $formdata) {
        $nosubmit = false;
        foreach ($mform->_noSubmitButtons as $key) {
            if (isset($formdata[$key])) {
                $nosubmit = true;
                break;
            }
        }
        if (
            !$nosubmit
            && isset($formdata['evasys_form'])
            && ($mform->_flagSubmitted ?? false)
            && empty($formdata['evasys_form'])
        ) {
            $validationelement = $mform->getElement('evasys_delete');
            $validationelement->setValue(1);
        }

        $freezetime = time();
        $evaluationstarttime = (int)($mform->_defaultValues['evasys_starttime'] ?? 0);
        if (empty($evaluationstarttime)) {
            return;
        }
        if ($evaluationstarttime < $freezetime) {
            $mform->freeze([
                'evasys_form',
                'evasys_timemode',
                'evasys_durationbeforestart',
                'evasys_durationafterend',
                'evasys_starttime',
                'evasys_endtime',
                'evasys_other_report_recipients',
                'evasysperiods',
                'evasys_notifyparticipants',
            ]);
        };
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
            $argssurvey = $helper->set_args_insert_survey(
                $courseresponse->m_nUserId,
                $courseresponse->m_nCourseId,
                $data->evasys_form,
                $courseresponse->m_nPeriodId,
            );
            $id = $data->evasys_booking_id;
            $survey = $evasys->save_survey($argssurvey, $id);
            $argsqr = $helper->set_args_get_qrcode($survey->m_nSurveyId);
            $qrcode = $evasys->get_qrcode($id, $argsqr);
        } else {
            // $now = time();
            // if ($now > $data->evasys_starttime) {
            //     return;
            // }
            if (!empty($data->evasys_confirmdelete)) {
                    // Delete Survey.
                    $helper = new evasys_helper_service();
                    $argssurvey = $helper->set_args_delete_survey($data->evasys_surveyid);
                    $evasys->delete_survey($argssurvey);

                    // Delete Course.
                    $argscourse = $helper->set_args_delete_course($data->evasys_courseidinternal);
                    $evasys->delete_course($argscourse, ['id' => $data->evasys_booking_id]);
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
                $coursedata = $evasys->aggregate_data_for_course_save($data, $newoption, $data->evasys_booking_id);
                $evasys->update_course($coursedata);
            }
        }
    }
}
