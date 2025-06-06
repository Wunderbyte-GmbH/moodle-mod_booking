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

use context_course;
use mod_booking\local\evasys_helper_service;
use mod_booking\singleton_service;
defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/user/lib.php');


/**
 * Class for handling logic of Evasys.
 */
class evasys_handler {
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
        $helper = new evasys_helper_service();
        $insertdata = $helper->map_form_to_record($formdata, $option);
        if (empty($formdata->evasys_booking_id)) {
            $returnid = $DB->insert_record('booking_evasys', $insertdata, true, false);
            // Returning ID so i can update record later for internal and external courseid.
            $formdata->evasys_booking_id = $returnid;
        } else {
            $DB->update_record('booking_evasys', $insertdata);
        }
    }

    /**
     * Load Data for Settings Object.
     *
     * @param int $optionid
     *
     * @return object
     *
     */
    public static function load_evasys($optionid) {
        global $DB;
        return $DB->get_record('booking_evasys', ['optionid' => $optionid], '*', IGNORE_MISSING) ?: (object)[];
    }

    /**
     * Load for Optionformfield.
     *
     * @param object $data
     *
     * @return void
     *
     */
    public function load_form(&$data) {
        $helper = new evasys_helper_service();
        $settings = singleton_service::get_instance_of_booking_option_settings($data->id);
        if ((empty($settings->evasys->id))) {
            return;
        }
        $helper->map_record_to_form($data, $settings->evasys);
    }

    /**
     * Fetch periods and create array for Settings.
     *
     * @return array
     *
     */
    public function get_periods_for_settings() {
        $soap = new evasys_soap_service();
        $helper = new evasys_helper_service();
        $periods = $soap->fetch_periods();
        if (!isset($periods)) {
            return [];
        }
        $list = $periods->Periods;
        $periodoptions = $helper->transform_return_to_array($list, 'm_nPeriodId', 'm_sTitel');
        $encodedperiods = [];
        foreach ($periodoptions as $id => $label) {
            $encodedkey = $id . '-' . base64_encode($label);
            $encodedperiods[$encodedkey] = $label;
        }

        return $encodedperiods;
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
        $soap = new evasys_soap_service();
        $helper = new evasys_helper_service();
        $periods = $soap->fetch_periods();
        $listforarray = $periods->Periods;
        $periodoptions = $helper->transform_return_to_array($listforarray, 'm_nPeriodId', 'm_sTitel');
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
        $soap = new evasys_soap_service();
        $helper = new evasys_helper_service();
        $args = [
                'IncludeCustomReports' => true,
                'IncludeUsageRestrictions' => true,
                'UsageRestrictionList' => [
                        'Subunits' => get_config('booking', 'evasyssubunits'),
                ],
        ];
        $periods = $soap->fetch_forms($args);
        $listforarray = $periods->SimpleForms;
        $periodoptions = $helper->transform_return_to_array($listforarray, 'ID', 'Name');
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
        $soap = new evasys_soap_service();
        $args = [
                'IncludeCustomReports' => true,
                'IncludeUsageRestrictions' => true,
                'UsageRestrictionList' => [
                        'Subunits' => get_config('booking', 'evasyssubunits'),
                ],
        ];
        $forms = $soap->fetch_forms($args);
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
        $soap = new evasys_soap_service();
        $helper = new evasys_helper_service();
        $subunits = $soap->fetch_subunits();
        if (!isset($subunits)) {
            return [];
        }
        $list = $subunits->Units;
        $subunitoptions = $helper->transform_return_to_array($list, 'm_nId', 'm_sName');
        $encodedsubunitoptions = [];
        foreach ($subunitoptions as $id => $label) {
            $encodedkey = $id . '-' . base64_encode($label);
            $encodedsubunitoptions[$encodedkey] = $label;
        }
        return $encodedsubunitoptions;
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
        $helper = new evasys_helper_service();
        $userdata = $helper->set_args_insert_user(
            $user->id,
            $user->firstname,
            $user->lastname,
            $user->adress,
            $user->email,
            $user->phone1
        );
        $soap = new evasys_soap_service();
        $response = $soap->insert_user($userdata);
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
     * @param array $args
     * @param int $id
     *
     * @return object
     *
     */
    public function save_survey($args, $id) {
        global $DB;
        $soap = new evasys_soap_service();
        $response = $soap->insert_survey($args);
        if (isset($response)) {
            $data = [
                'id' => $id,
                'surveyid' => $response->m_nSurveyId,
            ];
            $DB->update_record('booking_evasys', $data);
        }
        return $response;
    }

    /**
     * Deletes Survey.
     *
     * @param array $args
     *
     * @return boolean
     *
     */
    public function delete_survey($args) {
         $soap = new evasys_soap_service();
         $response = $soap->delete_survey($args);
         return $response;
    }

    /**
     * Update Logic for the Survey in Evasys.
     *
     * @param int $surveyid
     * @param object $data
     * @param object $option
     *
     * @return void
     *
     */
    public function update_survey($surveyid, $data, $option) {
        global $DB;
        $soap = new evasys_soap_service();
        $helper = new evasys_helper_service();
        $coursedata = self::aggregate_data_for_course_save($data, $option, $data->evasys_courseidinternal);
        $course = $soap->update_course($coursedata);
        if (empty($course)) {
            return;
        }
        $argsdelete = $helper->set_args_delete_survey($surveyid);
        $isdeleted = $soap->delete_survey($argsdelete);
        if (!$isdeleted) {
            return;
        }
        $argsnewsurvey = $helper->set_args_insert_survey(
            $course->m_nUserId,
            $course->m_nCourseId,
            $data->evasys_form,
            $course->m_nPeriodId
        );
        $survey = $soap->insert_survey($argsnewsurvey);
        $argsqr = $helper->set_args_get_qrcode($survey->m_nSurveyId);
        $qrcode = $this->get_qrcode($data->evasys_booking_id, $argsqr);
        if (!empty($survey)) {
            $insertobject = (object) [
                'id' => $data->evasys_booking_id,
                'surveyid' => $survey->m_nSurveyId,
                'pollurl' => $qrcode,
            ];
            $DB->update_record('booking_evasys', $insertobject);
        }
    }

    /**
     * Aggregates Data for Course.
     *
     * @param object $data
     * @param object $option
     * @param int $courseid
     *
     * @return object
     *
     */
    public function aggregate_data_for_course_save($data, $option, $courseid = null) {
        $userfieldshortname = get_config('booking', 'evasyscategoryfielduser');
        $helper = new evasys_helper_service();
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
        // Sort Teachers alphabetically.
        usort($teachers, function ($a, $b) {
                $lastnamecomparison = strcmp($a->lastname, $b->lastname);
                // Fallback if both have the same Lastname.
            if ($lastnamecomparison !== 0) {
                return $lastnamecomparison;
            }
            return strcmp($a->firstname, $b->firstname);
        });

        $userfieldvalue = array_shift($teachers)->profile[$userfieldshortname];
        // Prepare Customfields for Evasys.
        $internalid = end(explode(',', $userfieldvalue));
        // Make JSON for Customfields.
        $customfields = json_encode($teachers);
        // Merge the rest of the teachers with recipients so they get an Evasys Report.
        $secondaryinstructors = array_merge($teachers ?? [], $recipients ?? []);
        $secondaryinstructorsinsert = $helper->set_secondaryinstructors_for_save($secondaryinstructors);
        if (!empty($data->evasysperiods)) {
            $perioddata = explode('-', $data->evasysperiods);
            $periodid = reset($perioddata);
        } else {
            $periodid = get_config('booking', 'evasysperiods');
        }
        $helper = new evasys_helper_service();
        $coursedata = $helper->set_args_insert_course(
            $option->text,
            $option->id,
            $internalid,
            $periodid,
            $secondaryinstructorsinsert,
            $customfields,
            $courseid,
        );
        return $coursedata;
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
        $soap = new evasys_soap_service();
        $response = $soap->insert_course($coursedata);
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
     * Deletes the Course in Evasys and the record in internal DB.
     *
     * @param array $args
     *
     * @return mixed
     *
     */
    public function delete_course($args, $id) {
        global $DB;
        $soap = new evasys_soap_service();
        $response = $soap->delete_course($args);
        $DB->delete_records('booking_evasys', $id);
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
        $soap = new evasys_soap_service();
        $response = $soap->update_course($coursedata);
        return $response;
    }

    /**
     * Gets the QR Code for Survey.
     *
     * @param int $id
     * @param array $survey
     *
     * @return string
     *
     */
    public function get_qrcode($id, $survey) {
        global $DB;
        $soap = new evasys_soap_service();
        $response = $soap->get_qr_code($survey);
        $dataobject = (object) [
            'id' => $id,
            'pollurl' => $response,
        ];
        $DB->update_record('booking_evasys', $dataobject);
        return $response;
    }
}
