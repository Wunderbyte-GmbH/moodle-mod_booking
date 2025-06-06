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
 * Evasys Helper Class.
 *
 * @package mod_booking
 * @author David Ala
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local;

use stdClass;
defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/user/lib.php');


/**
 * Helperclass for Evasys.
 */
class evasys_helper_service {
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
     * Maps DB of Form to DB for saving.
     *
     * @param /stdClass $formdata
     * @param /stdClass $option
     *
     * @return object
     *
     */
    public function map_form_to_record($formdata, $option) {
        global $USER;
        $insertdata = new stdClass();
        $now = time();
        $insertdata->optionid = $option->id;
        $insertdata->formid = $formdata->evasys_questionaire;
        if (empty((int)$formdata->evasys_timemode)) {
            $insertdata->starttime = (int) $option->courseendtime + (int) $formdata->evasys_evaluation_durationbeforestart;
            $insertdata->endtime = (int) $option->courseendtime + (int) $formdata->evasys_evaluation_durationafterend;
        } else {
            $insertdata->starttime = $formdata->evasys_starttime;
            $insertdata->endtime = $formdata->evasys_endtime;
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
    public function map_record_to_form(&$data, $record) {
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
     * Transforms Array of objects to an associates array for the settings.
     *
     * @param array $list
     * @param string $key
     * @param string $value
     *
     * @return array
     *
     */
    public function transform_return_to_array($list, $key, $value) {
        $array = [];
        foreach ($list as $element) {
            $array[$element->$key] = $element->$value;
        }
        return $array;
    }

    /**
     * Helperfunction to set Args for inserting or updating the Course via SOAP call. For Insert leave courseid null.
     *
     * @param mixed $courseid
     * @param string $title
     * @param int $optionid
     * @param int $internalid
     * @param int $periodid
     * @param array $secondaryinstructors
     *
     * @return object
     *
     */
    public function set_args_insert_course($title, $optionid, $internalid, $periodid, $secondaryinstructors, $courseid = null) {
        $coursedata = (object) [
            'm_nCourseId' => $courseid,
            'm_sProgramOfStudy' => 'urise', // Subunit name.
            'm_sCourseTitle' => "$title",
            'm_sRoom' => '',
            'm_nCourseType' => 5,
            'm_sPubCourseId' => "evasys_ $optionid",
            'm_sExternalId' => "evasys_ $optionid",
            'm_nCountStud' => null,
            'm_sCustomFieldsJSON' => '',
            'm_nUserId' => $internalid,
            'm_nFbid' => (int)get_config('booking', 'evasyssubunits'),
            'm_nPeriodId' => (int)$periodid,
            'currentPosition' => null,
            'hasAnonymousParticipants' => false,
            'isModuleCourse' => null,
            'm_aoParticipants' => [],
            'm_aoSecondaryInstructors' => $secondaryinstructors,
            'm_oSurveyHolder' => null,
        ];
        return $coursedata;
    }
    /**
     * Helperfunction to set Args for inserting a User via SOAP call.
     *
     * @param int $userid
     * @param string $firstname
     * @param string $lastname
     * @param string $adress
     * @param string $email
     * @param int $phone
     *
     * @return object
     *
     */
    public function set_args_insert_user($userid, $firstname, $lastname, $adress, $email, $phone) {
         $user = (object) [
            'm_nId' => null,
            'm_nType' => null,
            'm_sLoginName' => '',
            'm_sExternalId' => "evasys_$userid",
            'm_sTitle' => '',
            'm_sFirstName' => $firstname,
            'm_sSurName' => $lastname,
            'm_sUnitName' => 'urise', // Subunit Name
            'm_sAddress' => $adress ?? '',
            'm_sEmail' => $email,
            'm_nFbid' => (int)get_config('booking', 'evasyssubunits'),
            'm_nAddressId' => 0,
            'm_sPassword' => '',
            'm_sPhoneNumber' => $phone ?? '',
            'm_bUseLDAP' => null,
            'm_bActiveUser' => null,
            'm_aCourses' => null,
         ];
         return $user;
    }

    /**
     * Helperfunction to set Args for inserting a Survey via SOAP call.
     *
     * @param int $userid
     * @param int $internalcourseid
     * @param int $formid
     * @param int $periodid
     *
     * @return array
     *
     */
    public function set_args_insert_survey($userid, $internalcourseid, $formid, $periodid) {
        $survey = [
            'nUserId' => $userid,
            'nCourseId' => $internalcourseid,
            'nFormId' => $formid,
            'nPeriodId' => $periodid,
            'sSurveyType' => "c",
        ];
        return $survey;
    }
    /**
     * Helperfunction to set Args for deleting a Survey via SOAP call.
     *
     * @param int $surveyid
     *
     * @return array
     *
     */
    public function set_args_delete_survey($surveyid) {
        $survey = [
            'SurveyId' => $surveyid,
            'IgnoreTwoStepDelete' => false,
        ];
        return $survey;
    }

    /**
     * Helperfunction to set Args for deleting a Course via SOAP call.
     *
     * @param int $internalcourseid
     *
     * @return array
     *
     */
    public function set_args_delete_course($internalcourseid) {
        $course = [
            'CourseId' => $internalcourseid,
            'IdType' => 'INTERNAL',
        ];
        return $course;
    }
    /**
     * Helperfunction to set Args for getting QR-Code.
     *
     * @param int $surveyid
     *
     * @return array
     *
     */
    public function set_args_get_qrcode($surveyid) {
        $survey = [
            'SurveyId' => $surveyid,
        ];
        return $survey;
    }
}
