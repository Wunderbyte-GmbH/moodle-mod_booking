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
 * Evasys Class.
 *
 * @package mod_booking
 * @author David Ala
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local;

use mod_booking\customfield\booking_handler;
use context_course;
use mod_booking\singleton_service;
use stdClass;
defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/user/lib.php');


/**
 * Class for handling logic of Evasys.
 */
class evasys_evaluation {
    /**
     * Save data from form into DB.
     *
     * @param /stdClass $formdata
     * @param /stdClass $option
     * @return void
     *
     */
    public function save_form(&$formdata, &$option) {
        global $DB;
        $insertdata = self::map_form_to_record($formdata, $option);
        if (empty($formdata->evasys_booking_id)) {
            $returnid = $DB->insert_record('booking_evasys', $insertdata, true, false);
            // Returning ID so i can update record later for internal and external courseid.
            $formdata->evasys_booking_id = $returnid;
        } else {
            $DB->update_record('booking_evasys', $insertdata);
        }
    }

    /**
     * Load Data to form.
     *
     * @param object $data
     *
     * @return void
     *
     */
    public function load_form(&$data): void {
        global $DB;
        $record = $DB->get_record('booking_evasys', ['optionid' => $data->optionid], '*', IGNORE_MISSING);
        if (empty($record)) {
            return;
        }
        self::map_record_to_form($data, $record);
    }

    /**
     * Fetch periods and create array for Settings.
     *
     * @return array
     *
     */
    public function get_periods_for_settings() {
        $service = new evasys_soap_service();
        $periods = $service->fetch_periods();
        if (!isset($periods)) {
            return [];
        }
        $list = $periods->Periods;
        $periodoptions = self::transform_return_to_array($list, 'm_nPeriodId', 'm_sTitel');
        return $periodoptions;
    }


    /**
     * Feteches the periods in the query
     *
     * @param string $query
     *
     * @return array
     *
     */
    public function get_periods_for_query($query) {
        $service = new evasys_soap_service();
        $periods = $service->fetch_periods();
        $listforarray = $periods->Periods;
        $periodoptions = self::transform_return_to_array($listforarray, 'm_nPeriodId', 'm_sTitel');
        foreach ($periodoptions as $key => $value) {
            if (stripos($value, $query) !== false) {
                $list[$key] = $value;
            }
        }
        $formattedlist = [];
        foreach ($list as $id => $name) {
            $formattedlist[] = [
                'id' => $id . '-' . base64_encode($name),
                'name' => $name,
            ];
        }
        return [
                'warnings' => count($formattedlist) > 100 ? get_string('toomanyuserstoshow', 'core', '> 100') : '',
                'list' => count($formattedlist) > 100 ? [] : $formattedlist,
        ];
    }

    /**
     * Fetches all the Questionaires for the query.
     *
     * @param string $query
     *
     * @return array
     *
     */
    public function get_questionaires_for_query($query) {
        $service = new evasys_soap_service();
        $args = [
                'IncludeCustomReports' => true,
                'IncludeUsageRestrictions' => true,
                'UsageRestrictionList' => [
                        'Subunits' => get_config('booking', 'evasyssubunits'),
                ],
        ];
        $periods = $service->fetch_forms($args);
        $listforarray = $periods->SimpleForms;
        $periodoptions = self::transform_return_to_array($listforarray, 'ID', 'Name');
        foreach ($periodoptions as $key => $value) {
            if (stripos($value, $query) !== false) {
                $list[$key] = $value;
            }
        }
        $formattedlist = [];
        foreach ($list as $id => $name) {
            $formattedlist[] = [
                'id' => $id . '-' . base64_encode($name),
                'name' => $name,
            ];
        }
        return [
                'warnings' => count($formattedlist) > 100 ? get_string('toomanyuserstoshow', 'core', '> 100') : '',
                'list' => count($formattedlist) > 100 ? [] : $formattedlist,
        ];
    }

    /**
     * Feteches forms and creates array for Settings.
     *
     * @return array
     *
     */
    public function get_allforms() {
        $service = new evasys_soap_service();
        $args = [
                'IncludeCustomReports' => true,
                'IncludeUsageRestrictions' => true,
                'UsageRestrictionList' => [
                        'Subunits' => get_config('booking', 'evasyssubunits'),
                ],
        ];
        $forms = $service->fetch_forms($args);
        if (!isset($forms)) {
            return [];
        }
        $list = $forms->SimpleForms;
        $formoptions = [];
        foreach ($list as $element) {
            $formoptions[$element->ID] = $element->Name;
        }
        return $formoptions;
    }

    /**
     * Fetches all user with manager role from DB.
     *
     * @return array
     *
     */
    public function get_recipients() {
        global $COURSE, $DB;
        $context = context_course::instance($COURSE->id);
        $role = $DB->get_record('role', ['shortname' => 'organizer'], 'id');
        $users = get_role_users($role->id, $context);
        $useroptions = [];
        foreach ($users as $user) {
            $useroptions[$user->id] = "$user->firstname $user->lastname";
        }
        return $useroptions;
    }

    /**
     * Fetches subunits and creates array for Settings.
     *
     * @return array
     *
     */
    public function get_subunits() {
        $service = new evasys_soap_service();
        $subunits = $service->fetch_subunits();
        if (!isset($subunits)) {
            return [];
        }
        $list = $subunits->Units;
        $subunitoptions = self::transform_return_to_array($list, 'm_nId', 'm_sName');
        return $subunitoptions;
    }

    /**
     * Saves user in Evasys.
     *
     * @param /stdClass $user
     * @return void
     *
     */
    public function save_user($user) {
        global $CFG;
        $userdata = [
            'm_nId' => null,
            'm_nType' => null,
            'm_sLoginName' => '',
            'm_sExternalId' => "evasys_$user->id",
            'm_sTitle' => '',
            'm_sFirstName' => $user->firstname,
            'm_sSurName' => $user->lastname,
            'm_sUnitName' => 'urise', // Subunit Name
            'm_sAddress' => $user->adress ?? '',
            'm_sEmail' => $user->email,
            'm_nFbid' => (int)get_config('booking', 'evasyssubunits'),
            'm_nAddressId' => 0,
            'm_sPassword' => '',
            'm_sPhoneNumber' => $user->phone1 ?? '',
            'm_bUseLDAP' => null,
            'm_bActiveUser' => null,
            'm_aCourses' => null,
        ];
        $service = new evasys_soap_service();
        $response = $service->insert_user($userdata);
        if (isset($response)) {
            $value = [$response->m_sExternalId, $response->m_nId];
            $insert = implode(',', $value);
            $fieldshortname = get_config('booking', 'evasyscategoryfielduser');
            require_once($CFG->dirroot . "/user/profile/lib.php");
            profile_save_custom_fields($user->id, [$fieldshortname => $insert]);
        }
    }


    /**
     * Saves survey for Evasys and Surveyid to DB.
     *
     * @param object $data
     * @param object $survey
     *
     * @return object
     *
     */
    public function save_survey($data) {
        global $DB;
        $surveydata = [
            'nUserId' => $data->userid,
            'nCourseId' => $data->courseid,
            'nFormId' => $data->formid,
            'nPeriodId' => $data->periodid,
            'sSurveyType' => "c",
        ];
        $service = new evasys_soap_service();
        $response = $service->insert_survey($surveydata);
        if (isset($response)) {
            $data = [
                'id' => $data->evasysid,
                'surveyid' => $response->m_nSurveyId,
            ];
            $DB->update_record('booking_evasys', $data);
        }
        return $response;
    }

    /**
     * Update Data for Survey.
     *
     * @param object $formdata
     * @param int $surveyid
     *
     * @return object
     *
     */
    public function update_survey($surveyid, $formdata) {
        $service = new evasys_soap_service();
        $survey = $service->get_survey($surveyid);
        // since Period is a mixture of int and base64 we have to decouple them.
        $formperiod = explode("-", $formdata->evasysperiods);
        $periodid = reset($formperiod);
        $data = [
            'sPeriodId' => $periodid,
            'sPeriodIdType' => 'INTERNAL',
        ];
        $period = $service->get_period($data);
        $survey->m_oPeriod = $period;
        $teacherid = (int)$formdata->teachersforoption[0];
        $teacher = singleton_service::get_instance_of_user($teacherid, true);
        $evasysid = $teacher->profile['evasysid'];
        $evasysidinternal = end(explode(',', $evasysid));
        $survey->m_nStuid = (int)$evasysidinternal;
        $survey->m_nFrmid = $formdata->evasys_formid;
        $service->update_survey($survey);
        // Since the update survey just returns a boolean we have to get the new survey again to add it later to the course.
        $newsurvey = $service->get_survey($surveyid);
        return $newsurvey;
    }

    /**
     * Aggregates Data for Course.
     *
     * @param object $data
     * @param object $option
     * @param int $courseid
     * @param object $survey
     *
     * @return object
     *
     */
    public function aggregate_data_for_course_save($data, $option, $courseid = null, $survey = null) {
        $userfieldshortname = get_config('booking', 'evasyscategoryfielduser');
        foreach ($data->teachersforoption as $teacherid) {
            $teacher = singleton_service::get_instance_of_user($teacherid, true);
            $teachers[$teacherid] = $teacher;
            if (empty($teacher->profile[$userfieldshortname])) {
                $this->save_user($teacher);
                singleton_service::destroy_user($teacherid);
                $teacher = singleton_service::get_instance_of_user($teacherid, true);
                $teachers[$teacherid] = $teacher;
            } else {
                continue;
            }
        }
        foreach ($data->evasys_other_report_recipients as $recipientid) {
            $recipient = singleton_service::get_instance_of_user($recipientid, true);
            $recipients[$recipientid] = $recipient;
            if (empty($recipient->profile[$userfieldshortname])) {
                $this->save_user($recipient);
                singleton_service::destroy_user($recipientid);
                $recipient = singleton_service::get_instance_of_user($recipientid, true);
                $recipients[$recipientid] = $recipient;
            } else {
                continue;
            }
        }
        $userfieldvalue = array_shift($teachers)->profile[$userfieldshortname];
        $internalid = end(explode(',', $userfieldvalue));
        $secondaryinstructors = array_merge($teachers ?? [], $recipients ?? []);
        $secondaryinstructorsinsert = self::set_secondaryinstructors_for_save($secondaryinstructors);
        if (!empty($data->evasysperiods)) {
            $perioddata = explode('-', $data->evasysperiods);
            $periodid = reset($perioddata);
        } else {
            $periodid = get_config('booking', 'evasysperiods');
        }
        $coursedata = (object) [
            'm_nCourseId' => $courseid,
            'm_sProgramOfStudy' => 'urise', // Subunit name.
            'm_sCourseTitle' => "$option->text",
            'm_sRoom' => '',
            'm_nCourseType' => 5,
            'm_sPubCourseId' => "evasys_$option->id",
            'm_sExternalId' => "evasys_$option->id",
            'm_nCountStud' => null,
            'm_sCustomFieldsJSON' => '',
            'm_nUserId' => $internalid,
            'm_nFbid' => (int)get_config('booking', 'evasyssubunits'),
            'm_nPeriodId' => (int)$periodid,
            'currentPosition' => null,
            'hasAnonymousParticipants' => false,
            'isModuleCourse' => null,
            'm_aoParticipants' => [],
            'm_aoSecondaryInstructors' => $secondaryinstructorsinsert,
            'm_oSurveyHolder' => $survey,
        ];
        return $coursedata;
    }

    /**
     * Sets the secondary Instructors for course insert or update.
     *
     * @param mixed $array
     *
     * @return array
     *
     */
    public function set_secondaryinstructors_for_save($secondaryinstructors) {
        $userfieldshortname = get_config('booking', 'evasyscategoryfielduser');
        $userlist = [];

        foreach ($secondaryinstructors as $instructor) {
            $parts = explode(',', $instructor->profile[$userfieldshortname]);
            $internalid = end($parts);

            $userobj = new stdClass();
            $userobj->m_nId = (int)$internalid;
            $userobj->m_nType = 1;
            $userobj->m_sLoginName = '';
            $userobj->m_sExternalId = "evasys_{$instructor->id}";
            $userobj->m_sTitle = '';
            $userobj->m_sFirstName = $instructor->firstname;
            $userobj->m_sSurName = $instructor->lastname;
            $userobj->m_sUnitName = '';
            $userobj->m_sAddress = $instructor->address ?? '';
            $userobj->m_sEmail = $instructor->email;
            $userobj->m_nFbid = (int)get_config('booking', 'evasyssubunits');
            $userobj->m_nAddressId = 0;
            $userobj->m_sPassword = '';
            $userobj->m_sPhoneNumber = $instructor->phone1 ?? '';
            $userobj->m_bUseLDAP = null;
            $userobj->m_bActiveUser = null;
            $userobj->m_bTechnicalAdmin = null;
            $userobj->m_aCourses = null;
            $userlist[] = $userobj;
        }
        return $userlist;
    }

    /**
     * Saves Course in Evasys.
     *
     * @param /stdClass $formdata
     * @param int $userid
     *
     * @return object
     *
     */
    public function save_course($formdata, $coursedata) {
        global $DB;
        $service = new evasys_soap_service();
        $response = $service->insert_course($coursedata);
        if (isset($response)) {
            $dataobject = (object)[
                'id' => $formdata->evasys_booking_id,
                'courseidinternal' => $response->m_nCourseId,
                'courseidexternal' => $response->m_sExternalId,
            ];
            $DB->update_record('booking_evasys', $dataobject);
        }
        return $response;
    }

    /**
     * Updates Course to Evasys.
     *
     * @param object $coursedata
     *
     * @return object
     *
     */
    public function update_course($coursedata) {
        $service = new evasys_soap_service();
        $response = $service->update_course($coursedata);
        return $response;
    }
    /**
     * Maps DB of Form to DB for saving.
     *
     * @param /stdClass $formdata
     * @param /stdClass $option
     *
     * @return object
     *
     */
    private static function map_form_to_record($formdata, $option) {
        global $USER;
        $insertdata = new stdClass();
        $now = time();
        $insertdata->optionid = $option->id;
        $insertdata->formid = $formdata->evasys_questionaire;
        if (empty((int)$formdata->evasys_timemode)) {
            $insertdata->starttime = (int) $option->courseendtime + (int) $formdata->evasys_evaluation_durationbeforestart;
            $insertdata->endtime = (int) $option->courseendtime + (int) $formdata->evasys_evaluation_durationafterend;
        } else {
            $insertdata->starttime = $formdata->evasys_evaluation_starttime;
            $insertdata->endtime = $formdata->evasys_evaluation_endtime;
        }
        $insertdata->trainers = implode(',', ($formdata->teachersforoption ?? []));
        $insertdata->organizers = implode(',', ($formdata->evasys_other_report_recipients ?? []));
        $insertdata->notifyparticipants = $formdata->evasys_notifyparticipants;
        $insertdata->usermodified = $USER->id;
        $insertdata->periods = $formdata->evasysperiods;

        if (empty($formdata->evasys_booking_id)) {
            $insertdata->timecreated = $now;
        } else {
            $insertdata->id = $formdata->evasys_booking_id;
            $insertdata->timemodified = $now;
        }
        return $insertdata;
    }

    /**
     * Maps Data of DB record to Formdata.
     *
     * @param /stdClass $data
     * @param /stdClass $record
     *
     * @return void
     *
     */
    private static function map_record_to_form(&$data, $record) {
        $data->evasys_questionaire = $record->formid;
        $data->evasys_evaluation_starttime = $record->starttime;
        $data->evasys_evaluation_endtime = $record->endtime;
        $data->evasys_other_report_recipients = explode(',', $record->organizers);
        $data->evasys_notifyparticipants = $record->notifyparticipants;
        $data->evasys_booking_id = $record->id;
        $data->evasys_timecreated = $record->timecreated;
        $data->evasys_formid = $record->formid;
        $data->evasysperiods = $record->periods;
        $data->evasys_surveyid = $record->surveyid;
        $data->evasys_courseidinternal = $record->courseidinternal;
        $data->evasys_courseidexternal = $record->courseidexternal;
    }

    /**
     * [Description for get_teacher_with_first_lastname]
     *
     * @param array $teachers
     *
     * @return [type]
     *
     */
    public function get_teacher_with_first_lastname(array $teachers) {
        if (empty($teachers)) {
            return [];
        }
        // Initialize with the first teacher
        $firstteacher = $teachers[0];

        foreach ($teachers as $teacher) {
            if (strcmp($teacher->lastname, $firstteacher->lastname) < 0) {
                $firstteacher = $teacher;
            }
        }

        return $firstteacher;
    }

    /**
     * Transforms Array of objects to an associates array for the settings.
     *
     * @param array $list
     * @param string $key
     * @param string $value
     *
     * @return array
     *
     */
    private static function transform_return_to_array($list, $key, $value) {
        $array = [];
        foreach ($list as $element) {
            $array[$element->$key] = $element->$value;
        }
        return $array;
    }
}
