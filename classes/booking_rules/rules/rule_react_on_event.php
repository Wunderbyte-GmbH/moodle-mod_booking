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
class rule_react_on_event implements booking_rule {

    /** @var int $ruleid */
    public $ruleid = 0;

    /** @var string $rulename */
    protected $rulename = 'rule_react_on_event';

    /** @var string $name */
    public $name = null;

    /** @var string $rulejson */
    public $rulejson = null;

    /** @var int $ruleid */
    public $boevent = null;

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
        $this->boevent = $ruleobj->ruledata->boevent;
    }

    /**
     * Only customizable functions need to return their necessary form elements.
     *
     * @param MoodleQuickForm $mform
     * @param int $optionid
     * @return void
     */
    public function add_rule_to_mform(MoodleQuickForm &$mform, array &$repeateloptions) {

        // Only these events are currently supported and tested.
        $allowedeventkeys = [
            'bookingoption_cancelled',
            'bookingoption_completed',
            'optiondates_teacher_added',
            'optiondates_teacher_deleted',
        ];

        // Get a list of all booking events.
        $allevents = get_list_of_booking_events();
        $allowedevents["0"] = get_string('choose...', 'mod_booking');

        // Currently, we only allow events affecting booking options.
        foreach ($allevents as $key => $value) {
            $eventnameonly = str_replace("\\mod_booking\\event\\", "", $key);
            if (in_array($eventnameonly, $allowedeventkeys)) {
                $allowedevents[$key] = $value;
            }
        }

        // Workaround: We need a group to get hideif to work.
        $mform->addElement('static', 'rule_react_on_event_desc', '',
            get_string('rule_react_on_event_desc', 'mod_booking'));

        $mform->addElement('select', 'rule_react_on_event_event',
            get_string('rule_event', 'mod_booking'), $allowedevents);
    }

    /**
     * Get the name of the rule.
     * @param bool $localized
     * @return string
     */
    public function get_name_of_rule(bool $localized = true): string {
        return $localized ? get_string($this->rulename, 'mod_booking') : $this->rulename;
    }

    /**
     * Save the JSON for daysbefore rule defined in form.
     * The role has to determine the handler for condtion and action and get the right json object.
     * @param stdClass &$data form data reference
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
        $jsonobject->ruledata->boevent = $data->rule_react_on_event_event ?? '';

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
     * @param stdClass &$data reference to the default values
     * @param stdClass $record a record from booking_rules
     */
    public function set_defaults(stdClass &$data, stdClass $record) {

        $data->bookingruletype = $this->rulename;

        $jsonobject = json_decode($record->rulejson);
        $ruledata = $jsonobject->ruledata;

        $data->rule_name = $jsonobject->name;
        $data->rule_react_on_event_event = $ruledata->boevent;

    }

    /**
     * Execute the rule.
     * @param int $optionid optional
     * @param int $userid optional
     */
    public function execute(int $optionid = 0, int $userid = 0) {

        // This rule executes only on event.
        // And every event will have an optionid, because it's linked to a specific option.
        if ($optionid === 0) {
            return;
        }

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
            $nextruntime = time();
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

        // For this rule, we don't need to check because everything is sent directly after event was triggered.
        return true;
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
    public function get_records_for_execution(int $optionid, int $userid = 0) {
        global $DB;

        // Execution of a rule is a complexe action.
        // Going from rule to condition to action...
        // ... we need to go into actions with an array of records...
        // ... which has the keys cmid, optionid & userid.

        $jsonobject = json_decode($this->rulejson);

        $params = [
            'optionid' => $optionid,
            'userid' => $userid,
            'json' => $this->rulejson,
        ];

        $sql = new stdClass();

        $sql->select = "bo.id optionid, cm.id cmid";
        $sql->from = "{booking_options} bo
                    JOIN {course_modules} cm
                    ON cm.instance = bo.bookingid
                    JOIN {modules} m
                    ON m.name = 'booking' AND m.id = cm.module";
        $sql->where = " bo.id = :optionid";

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
