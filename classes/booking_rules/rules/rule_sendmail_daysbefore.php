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
use MoodleQuickForm;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Rule to send a mail notification a specified number of days before a chosen date.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rule_sendmail_daysbefore implements booking_rule {

    /** @var string $rulename */
    public $rulename = null;

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

    /** @var string $template */
    public $template = null;

    /**
     * Load json data form DB into the object.
     * @param stdClass $record a rule record from DB
     */
    public function set_ruledata(stdClass $record) {
        $this->rulename = $record->rulename;
        $ruleobj = json_decode($record->rulejson);
        $this->days = (int) $ruleobj->days;
        $this->datefield = $ruleobj->datefield;
        $this->cpfield = $ruleobj->cpfield;
        $this->operator = $ruleobj->operator;
        $this->optionfield = $ruleobj->optionfield;
        $this->template = $ruleobj->template;
    }

    /**
     * Only customizable functions need to return their necessary form elements.
     *
     * @param MoodleQuickForm $mform
     * @param int $optionid
     * @return void
     */
    public function add_rule_to_mform(MoodleQuickForm &$mform,
        array &$repeatedrules, array &$repeateloptions) {
        global $DB;

        $numberofdaysbefore = [
            0 => '0',
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
            'coursestarttime' => get_string('rule_optionfield_coursestarttime', 'mod_booking'),
            'courseendtime' => get_string('rule_optionfield_courseendtime', 'mod_booking'),
            'bookingopeningtime' => get_string('rule_optionfield_bookingopeningtime', 'mod_booking'),
            'bookingclosingtime' => get_string('rule_optionfield_bookingclosingtime', 'mod_booking')
        ];

        // Get a list of allowed option fields to compare with custom user profile field.
        // Currently we only use fields containing VARCHAR in DB.
        $allowedoptionfields = [
            'text' => get_string('rule_optionfield_text', 'mod_booking'),
            'location' => get_string('rule_optionfield_location', 'mod_booking'),
            'address' => get_string('rule_optionfield_address', 'mod_booking')
        ];

        // Workaround: We need a group to get hideif to work.
        $groupitems = [];
        $groupitems[] = $mform->createElement('static', 'rule_sendmail_daysbefore_desc', '',
            get_string('rule_sendmail_daysbefore_desc', 'mod_booking'));
        $repeatedrules[] = $mform->createElement('group', 'rule_sendmail_daysbefore_desc_group', '',
            $groupitems, null, false);
        $repeateloptions['rule_sendmail_daysbefore_desc_group']['hideif'] = array('bookingrule', 'neq', 'rule_sendmail_daysbefore');

        // Number of days before.
        $repeatedrules[] = $mform->createElement('select', 'rule_sendmail_daysbefore_days',
            get_string('rule_days', 'mod_booking'), $numberofdaysbefore);
        $repeateloptions['rule_sendmail_daysbefore_days']['type'] = PARAM_TEXT;
        $repeateloptions['rule_sendmail_daysbefore_days']['hideif'] = array('bookingrule', 'neq', 'rule_sendmail_daysbefore');

        // Date field needed in combination with the number of days before.
        $repeatedrules[] = $mform->createElement('select', 'rule_sendmail_daysbefore_datefield',
            get_string('rule_datefield', 'mod_booking'), $datefields);
        $repeateloptions['rule_sendmail_daysbefore_datefield']['type'] = PARAM_TEXT;
        $repeateloptions['rule_sendmail_daysbefore_datefield']['hideif'] = array('bookingrule', 'neq', 'rule_sendmail_daysbefore');

        // Custom user profile field to be checked.
        $customuserprofilefields = $DB->get_records('user_info_field', null, '', 'id, name, shortname');
        if (!empty($customuserprofilefields)) {
            $customuserprofilefieldsarray = [];
            $customuserprofilefieldsarray[0] = get_string('userinfofieldoff', 'mod_booking');

            // Create an array of key => value pairs for the dropdown.
            foreach ($customuserprofilefields as $customuserprofilefield) {
                $customuserprofilefieldsarray[$customuserprofilefield->shortname] = $customuserprofilefield->name;
            }

            $repeatedrules[] = $mform->createElement('select', 'rule_sendmail_daysbefore_cpfield',
                get_string('rule_customprofilefield', 'mod_booking'), $customuserprofilefieldsarray);
            $repeateloptions['rule_sendmail_daysbefore_cpfield']['hideif'] =
                array('bookingrule', 'neq', 'rule_sendmail_daysbefore');

            $operators = [
                '=' => get_string('equals', 'mod_booking'),
                '~' => get_string('contains', 'mod_booking')
            ];
            $repeatedrules[] = $mform->createElement('select', 'rule_sendmail_daysbefore_operator',
                get_string('rule_operator', 'mod_booking'), $operators);
            $repeateloptions['rule_sendmail_daysbefore_operator']['hideif'] =
                array('bookingrule', 'neq', 'rule_sendmail_daysbefore');

            $repeatedrules[] = $mform->createElement('select', 'rule_sendmail_daysbefore_optionfield',
                get_string('rule_optionfield', 'mod_booking'), $allowedoptionfields);
            $repeateloptions['rule_sendmail_daysbefore_optionfield']['hideif'] =
                array('bookingrule', 'neq', 'rule_sendmail_daysbefore');
        }

        // Mail template. We need to use text area as editor does not work correctly.
        $repeatedrules[] = $mform->createElement('textarea', 'rule_sendmail_daysbefore_template',
            get_string('rule_mailtemplate', 'mod_booking'), 'wrap="virtual" rows="20" cols="25"');
        $repeateloptions['rule_sendmail_daysbefore_template']['hideif'] = array('bookingrule', 'neq', 'rule_sendmail_daysbefore');

    }

    /**
     * Get the name of the rule.
     * @return string the name of the rule
     */
    public function get_name_of_rule() {
        return get_string('rule_sendmail_daysbefore', 'mod_booking');
    }

    /**
     * Save the JSON for all sendmail_daysbefore rules defined in form.
     * @param stdClass &$data form data reference
     */
    public static function save_rules(stdClass &$data) {
        global $DB;
        foreach ($data->bookingrule as $idx => $rulename) {
            if ($rulename == 'rule_sendmail_daysbefore') {
                $ruleobj = new stdClass;
                $ruleobj->rulename = $data->bookingrule[$idx];
                $ruleobj->days = $data->rule_sendmail_daysbefore_days[$idx];
                $ruleobj->datefield = $data->rule_sendmail_daysbefore_datefield[$idx];
                $ruleobj->cpfield = $data->rule_sendmail_daysbefore_cpfield[$idx];
                $ruleobj->operator = $data->rule_sendmail_daysbefore_operator[$idx];
                $ruleobj->optionfield = $data->rule_sendmail_daysbefore_optionfield[$idx];
                $ruleobj->template = $data->rule_sendmail_daysbefore_template[$idx];

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
        $data->rule_sendmail_daysbefore_days[$idx] = $ruleobj->days;
        $data->rule_sendmail_daysbefore_datefield[$idx] = $ruleobj->datefield;
        $data->rule_sendmail_daysbefore_cpfield[$idx] = $ruleobj->cpfield;
        $data->rule_sendmail_daysbefore_operator[$idx] = $ruleobj->operator;
        $data->rule_sendmail_daysbefore_optionfield[$idx] = $ruleobj->optionfield;
        $data->rule_sendmail_daysbefore_template[$idx] = $ruleobj->template;
    }

    /**
     * Execute the rule.
     */
    public function execute() {
        global $DB;

        $sqlcomparepart = "";
        switch ($this->operator) {
            case '~':
                $sqlcomparepart = "ud.data LIKE '%bo." . $this->optionfield . "%'";
                break;
            case '=':
            default:
                $sqlcomparepart = $DB->sql_compare_text("ud.data") . " = ". $DB->sql_compare_text("bo." . $this->optionfield . "");
                break;
        }

        // We need the hack with uniqueid so we do not lose entries as the first column needs to be unique.
        $sql = "SELECT CONCAT(bo.id, '-', ud.userid) as uniqueid, bo.id optionid, ud.userid
                FROM {user_info_data} ud
                -- Join with all options having the same value in the specified option field.
                LEFT JOIN {booking_options} bo
                ON $sqlcomparepart
                -- Identify the users having the custom profile field.
                WHERE ud.fieldid IN (
                    SELECT DISTINCT id
                    FROM {user_info_field} uif
                    WHERE uif.shortname = :cpfield
                )
                -- Only select future options, so the reminder won't be in the past.
                AND bo." . $this->datefield . " >= (:now + (86400 * :numberofdays))
        ";

        $params = [
            'cpfield' => $this->cpfield,
            'numberofdays' => $this->days,
            'now' => time()
        ];

        if ($recordsforadhoctasks = $DB->get_records_sql($sql, $params)) {
            foreach ($recordsforadhoctasks as $recordforadhoctask) {
                // TODO: Create the adhoc task.
                $var1 = 'bla';
            }
        }

        // TODO.
        return;
    }
}
