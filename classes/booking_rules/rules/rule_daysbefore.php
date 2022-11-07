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

    /** @var int $days */
    public $days = null;

    /**
     * Load json data from DB into the object.
     * @param stdClass $record a rule record from DB
     */
    public function set_ruledata(stdClass $record) {
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
     * @param boolean $localized
     * @return void
     */
    public function get_name_of_rule($localized = true) {
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
            $DB->insert_record('booking_rules', $record);
        }
    }

    /**
     * Sets the rule defaults when loading the form.
     * @param stdClass &$data reference to the default values
     * @param stdClass $record a record from booking_rules
     */
    public function set_defaults(stdClass &$data, stdClass $record) {

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
    public function execute(int $optionid = null, int $userid = null) {
        global $DB;

        // Execution of a rule is a complexe action.
        // Going from rule to condition to action...
        // ... we need to go into actions with an array of records...
        // ... which has the keys cmid, optionid & userid.

        $jsonobject = json_decode($this->rulejson);
        $ruledata = $jsonobject->ruledata;

        $andoptionid = "";
        $anduserid = "";

        $params = [
            'cpfield' => $ruledata->cpfield,
            'numberofdays' => (int) $ruledata->days,
            'nowparam' => time()
        ];

        if (!empty($optionid)) {
            $andoptionid = " AND bo.id = :optionid ";
            $params['optionid'] = $optionid;
        }

        // We need the hack with uniqueid so we do not lose entries ...as the first column needs to be unique.
        $sql = "bo.id optionid,
                bo." . $ruledata->datefield . " datefield,
                FROM {booking_options} bo
                JOIN {course_modules} cm ON cm.instance=bo.bookingid
                JOIN {modules} m ON m.id=cm.module
                WHERE m.name='mod_booking'
                AND bo." . $ruledata->datefield . " >= ( :nowparam + (86400 * :numberofdays ))
                $andoptionid
        ";

        $bookingoptions = $DB->get_records_sql($sql, $params);

        // Now that we know the ids of the booking options concerend, we will determine the users concerned.
        // We will get back an array of stdClasses with the keys userid, optionid, cmid etc.

        $condition = conditions_info::get_condition($jsonobject->conditionname);

        $condition->set_conditiondata_from_json($this->rulejson);
        $records = $condition->execute($bookingoptions);

        $action = actions_info::get_action($jsonobject->actionname);
        $action->set_actiondata_from_json($this->rulejson);

        foreach ($records as $record) {
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
