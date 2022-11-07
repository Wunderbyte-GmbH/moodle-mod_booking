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

namespace mod_booking\booking_rules\conditions;

use mod_booking\booking_rules\booking_rule;
use mod_booking\booking_rules\booking_rule_condition;
use mod_booking\singleton_service;
use mod_booking\task\send_mail_by_rule_adhoc;
use MoodleQuickForm;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Condition how to identify concerned users by matching booking option field and user profile field.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class match_userprofilefield implements booking_rule_condition {

    /** @var string $rulename */
    public $conditionname = 'match_userprofilefield';

    /** @var string $cpfield */
    public $cpfield = null;

    /** @var string $operator */
    public $operator = null;

    /** @var string $optionfield */
    public $optionfield = null;


    /**
     * Load json data from DB into the object.
     * @param stdClass $record a rule condition record from DB
     */
    public function set_conditiondata(stdClass $record) {
        $this->set_conditiondata_from_json($record->rulejson);
    }

    /**
     * Load data directly from JSON.
     * @param string $json a json string for a booking rule
     */
    public function set_conditiondata_from_json(string $json) {
        $this->rulejson = $json;
        $ruleobj = json_decode($json);
        $conditiondata = $ruleobj->conditiondata;
        $this->cpfield = $conditiondata->cpfield;
        $this->operator = $conditiondata->operator;
        $this->optionfield = $conditiondata->optionfield;
    }

    /**
     * Only customizable functions need to return their necessary form elements.
     *
     * @param MoodleQuickForm $mform
     * @param int $optionid
     * @return void
     */
    public function add_condition_to_mform(MoodleQuickForm &$mform, array &$repeateloptions) {
        global $DB;

        // Get a list of allowed option fields to compare with custom user profile field.
        // Currently we only use fields containing VARCHAR in DB.
        $allowedoptionfields = [
            '0' => get_string('choose...', 'mod_booking'),
            'text' => get_string('rule_optionfield_text', 'mod_booking'),
            'location' => get_string('rule_optionfield_location', 'mod_booking'),
            'address' => get_string('rule_optionfield_address', 'mod_booking')
        ];

        // Custom user profile field to be checked.
        $customuserprofilefields = $DB->get_records('user_info_field', null, '', 'id, name, shortname');
        if (!empty($customuserprofilefields)) {
            $customuserprofilefieldsarray = [];
            $customuserprofilefieldsarray[0] = get_string('choose...', 'mod_booking');

            // Create an array of key => value pairs for the dropdown.
            foreach ($customuserprofilefields as $customuserprofilefield) {
                $customuserprofilefieldsarray[$customuserprofilefield->shortname] = $customuserprofilefield->name;
            }

            $mform->addElement('select', 'condition_match_userprofilefield_cpfield',
                get_string('rule_customprofilefield', 'mod_booking'), $customuserprofilefieldsarray);

            $operators = [
                '=' => get_string('equals', 'mod_booking'),
                '~' => get_string('contains', 'mod_booking')
            ];
            $mform->addElement('select', 'condition_match_userprofilefield_operator',
                get_string('rule_operator', 'mod_booking'), $operators);

            $mform->addElement('select', 'condition_match_userprofilefield_optionfield',
                get_string('rule_optionfield', 'mod_booking'), $allowedoptionfields);

        }

    }

    /**
     * Get the name of the rule.
     * @return string the name of the rule
     */
    public function get_name_of_condition($localized = true) {
        return $localized ? get_string($this->conditionname, 'mod_booking') : $this->conditionname;
    }

    /**
     * Save the JSON for all sendmail_daysbefore rules defined in form.
     * @param stdClass &$data form data reference
     */
    public function save_condition(stdClass &$data) {
        global $DB;

        if (!isset($data->rulejson)) {
            $jsonobject = new stdClass();
        } else {
            $jsonobject = json_decode($data->rulejson);
        }

        $jsonobject->conditionname = $this->conditionname;
        $jsonobject->conditiondata = new stdClass();
        $jsonobject->conditiondata->optionfield = $data->condition_match_userprofilefield_optionfield ?? '';
        $jsonobject->conditiondata->operator = $data->condition_match_userprofilefield_operator ?? '';
        $jsonobject->conditiondata->cpfield = $data->condition_match_userprofilefield_cpfield ?? '';

        $data->rulejson = json_encode($jsonobject);
    }

    /**
     * Sets the rule defaults when loading the form.
     * @param stdClass &$data reference to the default values
     * @param stdClass $record a record from booking_rules
     */
    public function set_defaults(stdClass &$data, stdClass $record) {

        $jsonobject = json_decode($record->rulejson);
        $conditiondata = $jsonobject->conditiondata;

        $data->condition_match_userprofilefield_optionfield = $conditiondata->optionfield;
        $data->condition_match_userprofilefield_operator = $conditiondata->operator;
        $data->condition_match_userprofilefield_cpfield = $conditiondata->cpfield;

    }

    /**
     * Execute the condition.
     * We receive an array of stdclasses with the keys optinid & cmid.
     * @param array $records
     * @return array
     */
    public function execute(array $records = null) {
        global $DB;

        // Get an array of optionids.
        $keys = array_keys($records);

        $optionids = implode(",", $keys);

        $params = [
            'cpfield' => $this->cpfield,
            'numberofdays' => (int) $this->days,
            'nowparam' => time()
        ];

        if (!empty($optionid)) {
            $andoptionid = "AND bo.id = :optionid";
            $params['optionid'] = $optionid;
        }
        if (!empty($userid)) {
            $anduserid = "AND ud.userid = :userid";
            $params['userid'] = $userid;
        }

        $sqlcomparepart = "";
        switch ($this->operator) {
            case '~':
                $sqlcomparepart = $DB->sql_compare_text("ud.data") .
                    " LIKE CONCAT('%', bo." . $this->optionfield . ", '%')
                      AND bo." . $this->optionfield . " <> ''
                      AND bo." . $this->optionfield . " IS NOT NULL";
                break;
            case '=':
            default:
                $sqlcomparepart = $DB->sql_compare_text("ud.data") . " = bo." . $this->optionfield;
                break;
        }

        // We need the hack with uniqueid so we do not lose entries ...as the first column needs to be unique.
        $sql = "SELECT CONCAT(bo.id, '-', ud.userid) uniqueid,
                        bo.id optionid,
                        bo." . $this->datefield . " datefield,
                        ud.userid
                FROM {user_info_data} ud
                JOIN {booking_options} bo
                ON $sqlcomparepart
                WHERE ud.fieldid IN (
                    SELECT DISTINCT id
                    FROM {user_info_field} uif
                    WHERE uif.shortname = :cpfield
                )
                AND bo." . $this->datefield . " >= ( :nowparam + (86400 * :numberofdays ))
                $andoptionid
                $anduserid
        ";

        if ($recordsforadhoctasks = $DB->get_records_sql($sql, $params)) {
            foreach ($recordsforadhoctasks as $record) {
                // Create the adhoc task to handle the rule.
                $task = new send_mail_by_rule_adhoc();

                // Generate the data needed by the task.
                $optionsettings = singleton_service::get_instance_of_booking_option_settings($record->optionid);
                $taskdata = [
                    // We need the JSON, so we can check if the rule still applies...
                    // ...on task execution.
                    'rulename' => $this->rulename,
                    'rulejson' => $this->rulejson,
                    'userid' => $record->userid,
                    'optionid' => $record->optionid,
                    'cmid' => $optionsettings->cmid,
                    'customsubject' => $this->subject,
                    'custommessage' => $this->template
                ];
                $task->set_custom_data($taskdata);

                // Set the time of when the task should run.
                $nextruntime = (int) $record->datefield - ((int) $this->days * 86400);
                $task->set_next_run_time($nextruntime);

                // Now queue the task or reschedule it if it already exists (with matching data).
                \core\task\manager::reschedule_or_queue_adhoc_task($task);
            }
        }
        return [];
    }

    /**
     * This function is called on execution of adhoc tasks,
     * so we can see if the rule still applies and the adhoc task
     * shall really be executed.
     *
     * @param int $optionid
     * @param int $userid
     * @param int $nextruntime
     * @return bool true if the rule still applies, false if not
     */
    public function check_if_condition_still_applies(int $optionid, int $userid, int $nextruntime): bool {
        global $DB;

        $rulestillapplies = false;

        $params = [
            'optionid' => $optionid,
            'userid' => $userid,
            'cpfield' => $this->cpfield,
            'numberofdays' => (int) $this->days
        ];

        $sqlcomparepart = "";
        switch ($this->operator) {
            case '~':
                $sqlcomparepart = $DB->sql_compare_text("ud.data") .
                    " LIKE CONCAT('%', bo." . $this->optionfield . ", '%')
                      AND bo." . $this->optionfield . " <> ''
                      AND bo." . $this->optionfield . " IS NOT NULL";
                break;
            case '=':
            default:
                $sqlcomparepart = $DB->sql_compare_text("ud.data") . " = bo." . $this->optionfield;
                break;
        }

        // We need the hack with uniqueid so we do not lose entries ...as the first column needs to be unique.
        $sql = "SELECT CONCAT(bo.id, '-', ud.userid) uniqueid,
                        bo.id optionid,
                        bo." . $this->datefield . " datefield,
                        ud.userid
                FROM {user_info_data} ud
                JOIN {booking_options} bo
                ON $sqlcomparepart
                WHERE ud.fieldid IN (
                    SELECT DISTINCT id
                    FROM {user_info_field} uif
                    WHERE uif.shortname = :cpfield
                )
                AND bo.id = :optionid
                AND ud.userid = :userid";

        if ($records = $DB->get_records_sql($sql, $params)) {
            // There should only be one record actually.
            foreach ($records as $record) {
                // Set the time of when the task should run.
                $calculatedruntime = (int) $record->datefield - ((int) $this->days * 86400);
                if ($calculatedruntime == $nextruntime) {
                    // Only if both a record was found and the runtime is still the same.
                    $rulestillapplies = true;
                }
            }
        }
        return $rulestillapplies;
    }
}
