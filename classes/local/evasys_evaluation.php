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
        if (empty($formdata->evasys_id)) {
            $DB->insert_record('booking_evasys', $insertdata, false, false);
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
    public function get_periods() {
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
        $list = $forms->Simpleform;
        $formoptions = self::transform_return_to_array($list, 'OriginalID', 'Name');
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
            'm_sUnitName' => '', // Subunit Name
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
     * Saves Course in Evasys.
     *
     * @param /stdClass $option
     * @param int $userid
     *
     * @return void
     *
     */
    public function save_course($option, $userid) {
        $coursedata = [
            'm_nCourseId' => null,
            'm_sProgramOfStudy' => 'Test1234', //Subunit name.
            'm_sCourseTitle' => "$option->text",
            'm_sRoom' => '',
            'm_nCourseType' => 5,
            'm_sPubCourseId' => "evasys_$option->id",
            'm_sExternalId' => "evasys_$option->id",
            'm_nCountStud' => null,
            'm_sCustomFieldsJSON' => '',
            'm_nUserId' => (int)$userid,
            'm_nFbid' => (int)get_config('booking', 'evasyssubunits'),
            'm_nPeriodId' => (int)get_config('booking', 'evasysperiods'),
            'currentPosition' => null,
            'hasAnonymousParticipants' => false,
            'isModuleCourse' => null,
            'm_aoParticipants' => [],
            'm_aoSecondaryInstructors' => [],
            'm_oSurveyHolder' => null,
        ];
        $service = new evasys_soap_service();
        $repsonse = $service->insert_course($coursedata);
        if (isset($repsonse)) {
            $fieldshortname = get_config('booking', 'evasyscategoryfieldoption');
            $handler = booking_handler::create();
            $handler->field_save($option->id, $fieldshortname, $repsonse->m_sExternalId);
        }
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
        $insertdata->pollurl = $formdata->evasys_questionaire;
        $insertdata->starttime = $formdata->evasys_evaluation_starttime;
        $insertdata->endtime = $formdata->evasys_evaluation_endtime;
        $insertdata->trainers = implode(',', ($formdata->teachersforoption ?? []));
        $insertdata->organizers = implode(',', ($formdata->evasys_other_report_recipients ?? []));
        $insertdata->notifyparticipants = $formdata->evasys_notifyparticipants;
        $insertdata->usermodified = $USER->id;

        if (empty($formdata->evasys_id)) {
            $insertdata->timecreated = $now;
        } else {
            $insertdata->id = $formdata->evasys_id;
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
    private static function map_record_to_form($data, $record) {
        $data->evasys_questionaire = $record->pollurl;
        $data->evasys_evaluation_starttime = $record->starttime;
        $data->evasys_evaluation_endtime = $record->endtime;
        $data->trainers = explode(',', $record->trainers);
        $data->evasys_other_report_recipients = explode(',', $record->organizers);
        $data->evasys_notifyparticipants = $record->notifyparticipants;
        $data->evasys_id = $record->id;
        $data->evasys_timecreated = $record->timecreated;
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
