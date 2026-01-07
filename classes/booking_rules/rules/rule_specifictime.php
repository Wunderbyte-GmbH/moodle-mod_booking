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

use context;
use mod_booking\bo_availability\bo_info;
use mod_booking\booking_rules\actions_info;
use mod_booking\booking_rules\booking_rule;
use mod_booking\booking_rules\conditions_info;
use mod_booking\option\fields\applybookingrules;
use mod_booking\singleton_service;
use MoodleQuickForm;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Rule to do something at a specific time before or after a chosen date.
 *
 * @package     mod_booking
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Bernhard Fischer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rule_specifictime implements booking_rule {
    /** @var string $rulename */
    protected $rulename = 'rule_specifictime';

    /** @var string $rulenamestringid ID of localized string for name of rule */
    protected $rulenamestringid = 'rulespecifictime';

    /** @var int $contextid */
    public $contextid = 1;

    /** @var string $name */
    public $name = null;

    /** @var string $rulejson */
    public $rulejson = null;

    /** @var int $ruleid from database! */
    public $ruleid = null;

    /** @var int $seconds has been changed from days to seconds for more flexibility */
    public $seconds = null;

    /** @var string $datefield */
    public $datefield = null;

    /** @var bool $ruleisactive */
    public $ruleisactive = true;

    /**
     * Load json data from DB into the object.
     * @param stdClass $record a rule record from DB
     */
    public function set_ruledata(stdClass $record) {
        $this->ruleid = $record->id ?? 0;
        $this->contextid = $record->contextid ?? 1; // 1 is system.
        $this->ruleisactive = $record->isactive;
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

        if (
            isset($ruleobj->ruledata->seconds)
            && is_number($ruleobj->ruledata->seconds)
        ) {
            $this->seconds = (int) $ruleobj->ruledata->seconds;
        } else {
            // Should never happen, but just in case.
            $this->seconds = 0;
        }

        $this->datefield = $ruleobj->ruledata->datefield;
    }

    /**
     * Only customizable functions need to return their necessary form elements.
     *
     * @param MoodleQuickForm $mform
     * @param array $repeateloptions
     * @param array $ajaxformdata
     * @return void
     */
    public function add_rule_to_mform(MoodleQuickForm &$mform, array &$repeateloptions, array $ajaxformdata = []) {
        global $DB;

        // Get a list of allowed option fields (only date fields allowed).
        $datefields = [
            '0' => get_string('choose...', 'mod_booking'),
            'coursestarttime' => get_string('ruleoptionfieldcoursestarttime', 'mod_booking'),
            'courseendtime' => get_string('ruleoptionfieldcourseendtime', 'mod_booking'),
            'optiondatestarttime' => get_string('ruleoptionfieldoptiondatestarttime', 'mod_booking'),
            'bookingopeningtime' => get_string('ruleoptionfieldbookingopeningtime', 'mod_booking'),
            'bookingclosingtime' => get_string('ruleoptionfieldbookingclosingtime', 'mod_booking'),
            'selflearningcourseenddate' => get_string('ruleoptionfieldselflearningcourseenddate', 'mod_booking'),
        ];

        // We support special treatments for shopping cart notifications.
        if (class_exists('local_shopping_cart\shopping_cart')) {
            $datefields['installmentpayment'] = get_string('installment', 'local_shopping_cart')
                . " (" . get_string('pluginname', 'local_shopping_cart') . ")";
        }

        $mform->addElement(
            'static',
            'rulespecifictime_desc',
            '',
            get_string('rulespecifictime_desc', 'mod_booking')
        );

        // Duration field for number of seconds before or after the chosen date field.
        $mform->addElement(
            'duration',
            'rulespecifictimeduration',
            get_string('rulespecifictimeduration', 'mod_booking')
        );

        // Duration field for number of seconds before or after the chosen date field.
        $mform->addElement(
            'select',
            'rulespecifictimebeforeafter',
            get_string('rulespecifictimebeforeafter', 'mod_booking'),
            [
                1 => get_string('rulespecifictimebefore', 'mod_booking'),
                -1 => get_string('rulespecifictimeafter', 'mod_booking'),
            ]
        );
        $mform->addHelpButton('rulespecifictimebeforeafter', 'rulespecifictimebeforeafter', 'mod_booking');
        $repeateloptions['rulespecifictimebeforeafter']['default'] = 1;
        $repeateloptions['rulespecifictimebeforeafter']['type'] = PARAM_INT;

        // Date field needed in combination with the number of seconds before or after.
        $mform->addElement(
            'select',
            'rulespecifictimedatefield',
            get_string('ruledatefield', 'mod_booking'),
            $datefields
        );
        $mform->setType('rulespecifictimedatefield', PARAM_TEXT);
    }

    /**
     * Get the name of the rule.
     * @param bool $localized
     * @return string the name of the rule
     */
    public function get_name_of_rule(bool $localized = true): string {
        return $localized ? get_string($this->rulenamestringid, 'mod_booking') : $this->rulename;
    }

    /**
     * Save the JSON for daysbefore rule defined in form.
     * The role has to determine the handler for condtion and action and get the right json object.
     * @param stdClass $data form data reference
     */
    public function save_rule(stdClass &$data): int {
        global $DB;

        $record = new stdClass();

        if (!isset($data->rulejson)) {
            $jsonobject = new stdClass();
        } else {
            $jsonobject = json_decode($data->rulejson);
        }

        $jsonobject->name = $data->rule_name ?? $data->rulename;
        $jsonobject->rulename = $this->rulename;
        $jsonobject->ruledata = new stdClass();
        // Before: Positive seconds (multiplied with 1).
        // After: Negative seconds (multiplied with -1).
        $jsonobject->ruledata->seconds = (
            (int) $data->rulespecifictimebeforeafter ?? 1) * ((int) $data->rulespecifictimeduration ?? 0
        );

        $jsonobject->ruledata->datefield = $data->rulespecifictimedatefield ?? '';
        if (isset($data->useastemplate)) {
            $jsonobject->useastemplate = $data->useastemplate;
            $record->useastemplate = $data->useastemplate;
        }

        $record->rulejson = json_encode($jsonobject);
        $record->rulename = $this->rulename;
        $record->contextid = $data->contextid ?? 1;
        $record->isactive = $data->ruleisactive;

        // If we can update, we add the id here.
        if ($data->id ?? false) {
            $record->id = $data->id;
            $DB->update_record('booking_rules', $record);
            $ruleid = $data->id;
        } else {
            $ruleid = $DB->insert_record('booking_rules', $record);
            $this->ruleid = $ruleid;
        }
        return $ruleid;
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
        if (isset($ruledata->seconds) && is_number($ruledata->seconds)) {
            if ((int) $ruledata->seconds < 0) {
                $data->rulespecifictimebeforeafter = -1;
            } else {
                $data->rulespecifictimebeforeafter = 1;
            }
            $data->rulespecifictimeduration = (int) abs($ruledata->seconds);
        } else {
            // Fallback.
            $data->rulespecifictimebeforeafter = 1;
            $data->rulespecifictimeduration = 0;
        }
        $data->rulespecifictimedatefield = $ruledata->datefield;
        $data->ruleisactive = $record->isactive;
    }

    /**
     * Execute the rule.
     * @param int $optionid optional
     * @param int $userid optional
     */
    public function execute(int $optionid = 0, int $userid = 0) {
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $jsonobject = json_decode($this->rulejson);

        if (!applybookingrules::apply_rule($optionid, $this->ruleid)) {
            return;
        }

        // Self-learning courses use coursestarttime only for sorting #684.
        // So if a rule is dependent on coursestarttime or courseendtime, we just skip the execution.
        if (!empty($settings->selflearningcourse)) {
            if (
                !empty($jsonobject->ruledata->datefield)
                && (
                    ($jsonobject->ruledata->datefield == 'coursestarttime')
                    || ($jsonobject->ruledata->datefield == 'courseendtime')
                )
            ) {
                return;
            }
        }

        // We reuse this code when we check for validity, therefore we use a separate function.
        $records = $this->get_records_for_execution($optionid, $userid);

        // Now we finally execution the action, where we pass on every record.
        $action = actions_info::get_action($jsonobject->actionname);
        $action->set_actiondata_from_json($this->rulejson);
        // For the execution, we need a rule id, otherwise we can't test for consistency.
        $action->ruleid = $this->ruleid;

        foreach ($records as $record) {
            // The override happens within the SQL of get_records_for_execution.
            // So $record->secondstonotify will have the correct value.
            if (isset($record->secondstonotify)) {
                $this->seconds = (int) $record->secondstonotify;
            }
            // Set the time of when the task should run.
            $nextruntime = (int) $record->datefield - $this->seconds;
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
     * @param int $optiondateid
     * @return bool true if the rule still applies, false if not
     */
    public function check_if_rule_still_applies(int $optionid, int $userid, int $nextruntime, int $optiondateid = 0): bool {

        if (empty($this->ruleisactive)) {
            return false;
        }

        if (!applybookingrules::apply_rule($optionid, $this->ruleid)) {
            return false;
        }

        // We retrieve the same sql we also use in the execute function.
        $records = $this->get_records_for_execution($optionid, $userid, true);

        // If there are multiple records (like for reminders for optiondates)...
        // ...we need to make sure that at least one runtime matches.
        if (empty($records)) {
            return false;
        }

        $rulestillapplies = true;
        foreach ($records as $record) {
            // Check if this record matches the optiondateid.
            if (
                !empty($optiondateid)
                && isset($record->optiondateid)
            ) {
                // If the optiondateid doesn't macht, look for other matches.
                // If no match is found, rule doesn't apply anymore.
                if ($record->optiondateid != $optiondateid) {
                    $rulestillapplies = false;
                    continue;
                }
                // Match found, now compare the records.
                $oldnextruntime = (int) $record->datefield - (int) $record->secondstonotify;

                if ($oldnextruntime == $nextruntime) {
                    $rulestillapplies = true;
                    break;
                }
                // If we found a matching optiondateid but times don't match,
                // set to false - maybe rules has changed.
                $rulestillapplies = false;
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
     * @param int $nextruntime
     * @return array
     */
    public function get_records_for_execution(
        int $optionid = 0,
        int $userid = 0,
        bool $testmode = false,
        int $nextruntime = 0
    ) {
        global $DB;

        // Execution of a rule is a complex action.
        // Going from rule to condition to action...
        // ... we need to go into actions with an array of records...
        // ... which has the keys cmid, optionid & userid.

        $jsonobject = json_decode($this->rulejson);
        $ruledata = $jsonobject->ruledata;

        $andoptionid = "";
        $anduserid = "";

        // In case it's a deprecated rule with a "days" field, we convert it to seconds.
        $params = [
            'numberofseconds' => ((int) $ruledata->seconds) ?? 0,
            'nowparam' => time(),
        ];

        if (!empty($optionid)) {
            $andoptionid = " AND bo.id = :optionid ";
            $params['optionid'] = $optionid;
        }

        // When we want to restrict the userid, we just pass on the param to the condition like this.
        if (!empty($userid)) {
            $params['userid'] = $userid;
        }

        // A rule might apply from the start only to a specific context. To check this, sql needs to take care of this.

        $context = context::instance_by_id($this->contextid);
        $path = $context->path;

        $params['path'] = "$path%";

        $sql = new stdClass();

        $sql->where = " c.path LIKE :path ";
        $sql->where .= " $andoptionid $anduserid ";

        // Initialize optiondates join.
        $joinoptiondates = "";

        switch ($ruledata->datefield) {
            case 'selflearningcourseenddate':
                // We need a special treatment for selflearningcourseneddate.
                $stringfordatefield = bo_info::check_for_sqljson_key_in_object(
                    'ba.json',
                    'selflearningendofsubscription',
                    'bigint'
                );
                $sql->select = "bo.id optionid, cm.id cmid, $stringfordatefield datefield";

                // In testmode we don't check the timestamp.
                $sql->where .= " AND $stringfordatefield";
                // We multiply with 1 for implicit typecast to BIGINT (needed for postgres).
                // Also, add one hour (3600 seconds) of tolerance to avoid issues with cronjob runtimes.
                $sql->where .= !$testmode ? " >= (:nowparam - 3600  + (1 * :numberofseconds))" : " IS NOT NULL ";
                break;
            case 'optiondatestarttime':
                // We need the numberofseconds both in select and where clause.

                // Get the start of every session (optiondate).
                // Only for optiondates, we can specify daystonotify which can override the numberofseconds of the rule.
                $sql->select = "bo.id optionid, bod.id optiondateid, cm.id cmid, bod.coursestarttime datefield,
                CASE
                    WHEN bod.daystonotify > 0 THEN (bod.daystonotify * 86400)
                    ELSE :numberofseconds
                END AS secondstonotify";

                $sql->where .= " AND bod.coursestarttime";
                // In testmode we don't check the timestamp.
                // For optiondates, we can specify the numberofdays individually for each optiondate (daystonotify column).
                // Only if it's 0 for the optiondate, we use the value specified in the rule.
                $params['numberofseconds2'] = $params['numberofseconds'];
                // We multiply with 1 for implicit typecast to BIGINT (needed for postgres).
                // Also, add one hour (3600 seconds) of tolerance to avoid issues with cronjob runtimes.
                $sql->where .= !$testmode ? " >= (:nowparam - 3600 + (1 * (
                    CASE
                        WHEN bod.daystonotify > 0 THEN (bod.daystonotify * 86400)
                        ELSE :numberofseconds2
                    END
                )))" : " IS NOT NULL ";

                $joinoptiondates = "JOIN {booking_optiondates} bod ON bo.id = bod.optionid";
                break;
            default:
                $sql->select = "bo.id optionid, cm.id cmid, bo." . $ruledata->datefield . " datefield";

                $sql->where .= " AND bo." . $ruledata->datefield;
                // In testmode we don't check the timestamp.
                // We multiply with 1 for implicit typecast to BIGINT (needed for postgres).
                // Also, add one hour (3600 seconds) of tolerance to avoid issues with cronjob runtimes.
                $sql->where .= !$testmode ? " >= (:nowparam - 3600 + (1 * :numberofseconds))" : " IS NOT NULL ";
                break;
        }
        // Make sure, cancelled options aren't fetched.
        $sql->where .= " AND bo.status < 1 ";

        $sql->from = "{booking_options} bo
                    JOIN {course_modules} cm
                    ON cm.instance = bo.bookingid
                    JOIN {modules} m
                    ON m.name = 'booking' AND m.id = cm.module
                    JOIN {context} c
                    ON c.instanceid = cm.id
                    $joinoptiondates";

        // Now that we know the ids of the booking options concerned, we will determine the users concerned.
        // The condition execution will add their own code to the sql.
        $condition = conditions_info::get_condition($jsonobject->conditionname);
        $condition->set_conditiondata_from_json($this->rulejson);
        $condition->execute($sql, $params, $testmode, $nextruntime);

        $sql->select = " DISTINCT " . $sql->select; // Required to eliminate potential duplication in case inoptimal query.
        $sqlstring = "SELECT $sql->select FROM $sql->from WHERE $sql->where";

        $records = $DB->get_records_sql($sqlstring, $params);

        return $records;
    }
}
