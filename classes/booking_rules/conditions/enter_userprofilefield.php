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
class enter_userprofilefield implements booking_rule_condition {

    /** @var string $rulename */
    public $conditionname = 'enter_userprofilefield';

    /** @var string $cpfield */
    public $cpfield = null;

    /** @var string $operator */
    public $operator = null;

    /** @var string $optionfield */
    public $textfield = null;


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

        // Custom user profile field to be checked.
        $customuserprofilefields = $DB->get_records('user_info_field', null, '', 'id, name, shortname');
        if (!empty($customuserprofilefields)) {
            $customuserprofilefieldsarray = [];
            $customuserprofilefieldsarray[0] = get_string('choose...', 'mod_booking');

            // Create an array of key => value pairs for the dropdown.
            foreach ($customuserprofilefields as $customuserprofilefield) {
                $customuserprofilefieldsarray[$customuserprofilefield->shortname] = $customuserprofilefield->name;
            }

            $mform->addElement('select', 'condition_enter_userprofilefield_cpfield',
                get_string('rule_customprofilefield', 'mod_booking'), $customuserprofilefieldsarray);

            $operators = [
                '=' => get_string('equals', 'mod_booking'),
                '~' => get_string('contains', 'mod_booking')
            ];
            $mform->addElement('select', 'condition_enter_userprofilefield_operator',
                get_string('rule_operator', 'mod_booking'), $operators);

            $mform->addElement('text', 'condition_enter_userprofilefield_textfield',
                get_string('condition_textfield', 'mod_booking'));

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
        $jsonobject->conditiondata->cpfield = $data->condition_enter_userprofilefield_cpfield ?? '';
        $jsonobject->conditiondata->operator = $data->condition_enter_userprofilefield_operator ?? '';
        $jsonobject->conditiondata->textfield = $data->condition_match_userprofilefield_textfield ?? '';

        $data->rulejson = json_encode($jsonobject);
    }

    /**
     * Sets the rule defaults when loading the form.
     * @param stdClass &$data reference to the default values
     * @param stdClass $record a record from booking_rules
     */
    public function set_defaults(stdClass &$data, stdClass $record) {

        $data->bookingruleconditiontype = $this->conditionname;

        $jsonobject = json_decode($record->rulejson);
        $conditiondata = $jsonobject->conditiondata;

        $data->condition_match_userprofilefield_textfield = $conditiondata->textfield;
        $data->condition_enter_userprofilefield_operator = $conditiondata->operator;
        $data->condition_matchcondition_enter_userprofilefield_cpfield_userprofilefield_cpfield = $conditiondata->cpfield;

    }

    /**
     * Execute the condition.
     * We receive an array of stdclasses with the keys optinid & cmid.
     * @param stdClass $sql
     * @param array $params
     * @return array
     */
    public function execute(stdClass &$sql, array &$params) {
        global $DB;

        $sqlcomparepart = "";
        switch ($this->operator) {
            case '~':
                $sqlcomparepart = $DB->sql_compare_text("ud.data") .
                    " LIKE CONCAT('%', ':conditiontextfield', '%')
                      AND ':conditiontextfield1' <> ''
                      AND ':conditiontextfield2' IS NOT NULL";
                break;
            case '=':
            default:
                $sqlcomparepart = $DB->sql_compare_text("ud.data") . " = ':conditiontextfield'";
                break;
        }

        $params['conditiontextfield'] = $this->textfield;
        $params['conditiontextfield1'] = $this->textfield;
        $params['conditiontextfield2'] = $this->textfield;

        // We pass the restriction to the userid in the params.
        // If its not 0, we add the restirction.
        $anduserid = '';
        if (!empty($params['userid'])) {
            $anduserid = "AND ud.userid = :userid";
        }

        // We need the hack with uniqueid so we do not lose entries ...as the first column needs to be unique.

        $sql->select = " CONCAT(bo.id, '-', ud.userid) uniqueid, " . $sql->select;
        $sql->select .= ", ud.userid userid,
        cm.id cmid ";

        $sql->from .= " JOIN {user_info_data} ud ON $sqlcomparepart
        JOIN {course_modules} cm ON cm.instance=bo.bookingid
        JOIN {modules} m ON m.id=cm.module ";

        $sql->where .= " AND m.name='booking'
            AND ud.fieldid IN (
                    SELECT DISTINCT id
                    FROM {user_info_field} uif
                    WHERE uif.shortname = :cpfield
                )
                $anduserid ";

        $params['cpfield'] = $this->cpfield;
    }
}
