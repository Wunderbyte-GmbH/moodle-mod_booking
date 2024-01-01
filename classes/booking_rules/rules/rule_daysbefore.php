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

use mod_booking\booking_rules\actions_info;
use mod_booking\booking_rules\booking_rule;
use mod_booking\booking_rules\conditions_info;
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
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rule_daysbefore implements booking_rule {

    /** @var string $rulename */
    protected $rulename = 'rule_daysbefore';

    /** @var string $name */
    public $name = null;

    /** @var string $rulejson */
    public $rulejson = null;

    /** @var int $ruleid from database! */
    public $ruleid = null;

    /** @var int $days */
    public $days = null;

    /** @var string $datefield */
    public $datefield = null;

    /**
     * Load json data from DB into the object.
     * @param stdClass $record a rule record from DB
     */
    public function set_ruledata(stdClass $record) {
        $this->ruleid = $record->id ?? 0;
        $this->set_ruledata_from_json($record->rulejson);
    }

    /**
     * Load data directly from JSON.
     * @param string $json a json string for a booking rule
     */
    public function set_ruledata_from_json(string $json) {
        $this->rulejson = $json;
        $ruleobj = json_decode($json);
        $this->name = $ruleobj->name;
        $this->days = (int) $ruleobj->ruledata->days;
        $this->datefield = $ruleobj->ruledata->datefield;
    }

    /**
     * Only customizable functions need to return their necessary form elements.
     *
     * @param MoodleQuickForm $mform
     * @param array $repeateloptions
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
            30 => '30',
        ];

        // Get a list of allowed option fields (only date fields allowed).
        $datefields = [
            '0' => get_string('choose...', 'mod_booking'),
            'coursestarttime' => get_string('rule_optionfield_coursestarttime', 'mod_booking'),
            'courseendtime' => get_string('rule_optionfield_courseendtime', 'mod_booking'),
            'bookingopeningtime' => get_string('rule_optionfield_bookingopeningtime', 'mod_booking'),
            'bookingclosingtime' => get_string('rule_optionfield_bookingclosingtime', 'mod_booking'),
        ];

        $mform->addElement('static', 'rule_daysbefore_desc', '',
            get_string('rule_daysbefore_desc', 'mod_booking'));

        // Number of days before.
        $mform->addElement('select', 'rule_daysbefore_days',
            get_string('rule_days', 'mod_booking'), $numberofdaysbefore);
        $repeateloptions['rule_daysbefore_days']['type'] = PARAM_TEXT;

        // Date field needed in combination with the number of days before.
        $mform->addElement('select', 'rule_daysbefore_datefield',
            get_string('rule_datefield', 'mod_booking'), $datefields);
        $repeateloptions['rule_daysbefore_datefield']['type'] = PARAM_TEXT;

    }

    /**
     * Get the name of the rule.
     * @param bool $localized
     * @return string the name of the rule
     */
    public function get_name_of_rule(bool $localized = true): string {
        return $localized ? get_string($this->rulename, 'mod_booking') : $this->rulename;
    }

    /**
     * Save the JSON for daysbefore rule defined in form.
     * The role has to determine the handler for condtion and action and get the right json object.
     * @param stdClass $data form data reference
     */
    public function save_rule(stdClass &$data) {
        global $DB;

        $record = new stdClass();

        if (!isset($data->rulejson)) {
            $jsonobject = new stdClass();
        } else {
            $jsonobject = json_decode($data->rulejson);
        }

        $jsonobject->name = $data->rule_name;
        $jsonobject->rulename = $this->rulename;
        $jsonobject->ruledata = new stdClass();
        $jsonobject->ruledata->days = $data->rule_daysbefore_days ?? 0;
        $jsonobject->ruledata->datefield = $data->rule_daysbefore_datefield ?? '';

        $record->rulejson = json_encode($jsonobject);
        $record->rulename = $this->rulename;
        $record->bookingid = $data->bookingid ?? 0;

        // If we can update, we add the id here.
        if ($data->id) {
            $record->id = $data->id;
            $DB->update_record('booking_rules', $record);
        } else {
            $ruleid = $DB->insert_record('booking_rules', $record);
            $this->ruleid = $ruleid;
        }
    }

    /**
     * Sets the rule defaults when loading the form.
     * @param stdClass $data reference to the default values
     * @param stdClass $record a record from booking_rules
     */
    public function set_defaults(stdClass &$data, stdClass $record) {

        $data->bookingruletype = $this->rulename;

        $jsonobject = json_decode($record->rulejson);
        $ruledata = $jsonobject->ruledata;

        $data->rule_name = $jsonobject->name;
        $data->rule_daysbefore_days = $ruledata->days;
        $data->rule_daysbefore_datefield = $ruledata->datefield;

    }

    /**
     * Execute the rule.
     * @param int $optionid optional
     * @param int $userid optional
     */
    public function execute(int $optionid = 0, int $userid = 0) {
        global $DB;

        $jsonobject = json_decode($this->rulejson);

        // We reuse this code when we check for validity, therefore we use a separate function.
        $records = $this->get_records_for_execution($optionid, $userid);

        // Now we finally execution the action, where we pass on every record.
        $action = actions_info::get_action($jsonobject->actionname);
        $action->set_actiondata_from_json($this->rulejson);
        // For the execution, we need a rule id, otherwise we can't test for consistency.
        $action->ruleid = $this->ruleid;

        foreach ($records as $record) {

            // Set the time of when the task should run.
            $nextruntime = (int) $record->datefield - ((int) $this->days * 86400);
            $record->rulename = $this->rulename;
            $record->nextruntime = $nextruntime;
            $action->execute($record);
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

        $rulestillapplies = true;

        // We retrieve the same sql we also use in the execute function.
        $records = $this->get_records_for_execution($optionid, $userid, true);

        foreach ($records as $record) {
            $oldnextruntime = (int) $record->datefield - ((int) $this->days * 86400);

            if ($oldnextruntime != $nextruntime) {
                $rulestillapplies = false;
                break;
            }
        }

        return $rulestillapplies;
    }

    /**
     * This helperfunction builds the sql with the help of the condition and returns the records.
     * Testmode means that we don't limit by now timestamp.
     *
     * @param int $optionid
     * @param int $userid
     * @param bool $testmode
     * @return array
     */
    public function get_records_for_execution(int $optionid = 0, int $userid = 0, bool $testmode = false) {
        global $DB;

        // Execution of a rule is a complex action.
        // Going from rule to condition to action...
        // ... we need to go into actions with an array of records...
        // ... which has the keys cmid, optionid & userid.

        $jsonobject = json_decode($this->rulejson);
        $ruledata = $jsonobject->ruledata;

        $andoptionid = "";
        $anduserid = "";

        $params = [
            'numberofdays' => (int) $ruledata->days,
            'nowparam' => time(),
        ];

        if (!empty($optionid)) {
            $andoptionid = " AND bo.id = :optionid ";
            $params['optionid'] = $optionid;
        }

        if (!empty($userid)) {
            $anduserid = "AND ud.userid = :userid";
            $params['userid'] = $userid;
        }

        $sql = new stdClass();

        $sql->select = "bo.id optionid, cm.id cmid, bo." . $ruledata->datefield . " datefield";

        $sql->from = "{booking_options} bo
                    JOIN {course_modules} cm
                    ON cm.instance = bo.bookingid
                    JOIN {modules} m
                    ON m.name = 'booking' AND m.id = cm.module";

        // In testmode we don't check the timestamp.
        $sql->where = " bo." . $ruledata->datefield;
        $sql->where .= !$testmode ? " >= ( :nowparam + (86400 * :numberofdays ))" : " IS NOT NULL ";
        $sql->where .= " $andoptionid $anduserid ";

        // Now that we know the ids of the booking options concerend, we will determine the users concerned.
        // The condition execution will add their own code to the sql.

        $condition = conditions_info::get_condition($jsonobject->conditionname);

        $condition->set_conditiondata_from_json($this->rulejson);

        $condition->execute($sql, $params);

        $sqlstring = "SELECT $sql->select FROM $sql->from WHERE $sql->where";

        $records = $DB->get_records_sql($sqlstring, $params);

        return $records;
    }
}
