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

namespace mod_booking\booking_rules\rules;

use mod_booking\booking_rules\booking_rule;
use mod_booking\singleton_service;
use mod_booking\task\send_mail_by_rule_adhoc;
use MoodleQuickForm;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Rule do something a specified number of days before a chosen date.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rule_react_on_event implements booking_rule {

    /** @var string $rulename */
    public $rulename = null;

    /** @var string $rulejson */
    public $rulejson = null;

    /** @var int $days */
    public $days = null;

    /** @var string $datefield */
    public $datefield = null;

    /** @var string $cpfield */
    public $cpfield = null;

    /** @var string $operator */
    public $operator = null;

    /** @var string $optionfield */
    public $optionfield = null;

    /** @var string $subject */
    public $subject = null;

    /** @var string $template */
    public $template = null;

    /**
     * Load json data from DB into the object.
     * @param stdClass $record a rule record from DB
     */
    public function set_ruledata(stdClass $record) {
        $this->rulename = $record->rulename;
        $this->rulejson = $record->rulejson;
        $ruleobj = json_decode($record->rulejson);
        $this->days = (int) $ruleobj->days;
        $this->datefield = $ruleobj->datefield;
        $this->cpfield = $ruleobj->cpfield;
        $this->operator = $ruleobj->operator;
        $this->optionfield = $ruleobj->optionfield;
        $this->subject = $ruleobj->subject;
        $this->template = $ruleobj->template;
    }

    /**
     * Load data directly from JSON.
     * @param string $json a json string for a booking rule
     */
    public function set_ruledata_from_json(string $json) {
        $this->rulejson = $json;
        $ruleobj = json_decode($json);
        $this->rulename = $ruleobj->rulename;
        $this->days = (int) $ruleobj->days;
        $this->datefield = $ruleobj->datefield;
        $this->cpfield = $ruleobj->cpfield;
        $this->operator = $ruleobj->operator;
        $this->optionfield = $ruleobj->optionfield;
        $this->subject = $ruleobj->subject;
        $this->template = $ruleobj->template;
    }

    /**
     * Only customizable functions need to return their necessary form elements.
     *
     * @param MoodleQuickForm $mform
     * @param int $optionid
     * @return void
     */
    public function add_rule_to_mform(MoodleQuickForm &$mform, array &$repeateloptions) {
        global $DB;

        $numberofdaysbefore = [
            0 => get_string('choose...', 'mod_booking'),
            1 => '1',
            2 => '2',
            3 => '3',
            4 => '4',
            5 => '5',
            6 => '6',
            7 => '7',
            8 => '8',
            9 => '9',
            10 => '10',
            15 => '15',
            20 => '20',
            25 => '25',
            30 => '30'
        ];

        // Get a list of allowed option fields (only date fields allowed).
        $datefields = [
            '0' => get_string('choose...', 'mod_booking'),
            'coursestarttime' => get_string('rule_optionfield_coursestarttime', 'mod_booking'),
            'courseendtime' => get_string('rule_optionfield_courseendtime', 'mod_booking'),
            'bookingopeningtime' => get_string('rule_optionfield_bookingopeningtime', 'mod_booking'),
            'bookingclosingtime' => get_string('rule_optionfield_bookingclosingtime', 'mod_booking')
        ];

        // Workaround: We need a group to get hideif to work.
        $groupitems = [];
        $groupitems[] = $mform->createElement('static', 'rule_react_on_event_desc', '',
            get_string('rule_react_on_event_desc', 'mod_booking'));

        $mform->addElement('group', 'rule_react_on_event_desc_group', '',
            $groupitems, null, false);
        $repeateloptions['rule_react_on_event_desc_group']['hideif'] = array('bookingruletype', 'neq', 'rule_react_on_event');

        $mform->addElement('select', 'rule_react_on_event_eventlist',
            get_string('rule_react_on_event_eventlist', 'mod_booking'), [1 => 1]);
        $repeateloptions['rule_react_on_event_eventlist']['type'] = PARAM_TEXT;
        $repeateloptions['rule_react_on_event_eventlist']['hideif'] = array('bookingruletype', 'neq', 'rule_react_on_event');
    }

    /**
     * Get the name of the rule.
     * @return string the name of the rule
     */
    public function get_name_of_rule() {
        return get_string('rule_react_on_event', 'mod_booking');
    }

    /**
     * Save the JSON for all sendmail_daysbefore rules defined in form.
     * @param stdClass &$data form data reference
     */
    public static function save_rules(stdClass &$data) {
        global $DB;
        foreach ($data->bookingrule as $idx => $rulename) {
            if ($rulename == 'rule_daysbefore') {
                $ruleobj = new stdClass;
                $ruleobj->rulename = $data->bookingrule[$idx];
                $ruleobj->days = $data->rule_daysbefore_days[$idx];
                $ruleobj->datefield = $data->rule_daysbefore_datefield[$idx];
                $ruleobj->cpfield = $data->rule_daysbefore_cpfield[$idx];
                $ruleobj->operator = $data->rule_daysbefore_operator[$idx];
                $ruleobj->optionfield = $data->rule_daysbefore_optionfield[$idx];
                $ruleobj->subject = $data->rule_daysbefore_subject[$idx];
                $ruleobj->template = $data->rule_daysbefore_template[$idx]['text'];

                $record = new stdClass;
                $record->rulename = $data->bookingrule[$idx];
                $record->rulejson = json_encode($ruleobj);

                $DB->insert_record('booking_rules', $record);
            }
        }
    }

    /**
     * Sets the rule defaults when loading the form.
     * @param stdClass &$data reference to the default values
     * @param stdClass $record a record from booking_rules
     */
    public function set_defaults(stdClass &$data, stdClass $record) {
        $idx = $record->id - 1;
        $data->bookingrule[$idx] = $record->rulename;
        $ruleobj = json_decode($record->rulejson);
        $data->rule_daysbefore_days[$idx] = $ruleobj->days;
        $data->rule_daysbefore_datefield[$idx] = $ruleobj->datefield;
        $data->rule_daysbefore_cpfield[$idx] = $ruleobj->cpfield;
        $data->rule_daysbefore_operator[$idx] = $ruleobj->operator;
        $data->rule_daysbefore_optionfield[$idx] = $ruleobj->optionfield;
        $data->rule_daysbefore_subject[$idx] = $ruleobj->subject;
        $data->rule_daysbefore_template[$idx]['text'] = $ruleobj->template;
        $data->rule_daysbefore_template[$idx]['format'] = FORMAT_HTML;
    }

    /**
     * Execute the rule.
     * @param int $optionid optional
     * @param int $userid optional
     */
    public function execute(int $optionid = null, int $userid = null) {
        global $DB;

        $andoptionid = "";
        $anduserid = "";

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
    public function check_if_rule_still_applies(int $optionid, int $userid, int $nextruntime): bool {
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
