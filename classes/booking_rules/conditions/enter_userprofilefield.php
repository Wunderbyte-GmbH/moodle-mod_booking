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
 * Condition to identify users by entering a value which should match a custom user profile field.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\booking_rules\conditions;

use mod_booking\booking_rules\booking_rule_condition;
use MoodleQuickForm;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Class to handle condition to identify users by entering a value which should match a custom user profile field.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enter_userprofilefield implements booking_rule_condition {

    /** @var string $conditionname */
    public $conditionname = 'enter_userprofilefield';

    /** @var string $cpfield */
    public $cpfield = null;

    /** @var string $operator */
    public $operator = null;

    /** @var string $textfield */
    public $textfield = null;

    /** @var string $rulejson a json string for a booking rule */
    public $rulejson = '';

    /**
     * Function to tell if a condition can be combined with a certain booking rule type.
     * @param string $bookingruletype e.g. "rule_daysbefore" or "rule_react_on_event"
     * @return bool true if it can be combined
     */
    public function can_be_combined_with_bookingruletype(string $bookingruletype): bool {
        // This condition can currently be combined with any rule.
        return true;
    }

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
        $this->textfield = $conditiondata->textfield;
    }

    /**
     * Add condition to mform.
     *
     * @param MoodleQuickForm $mform
     * @param array $ajaxformdata
     * @return void
     */
    public function add_condition_to_mform(MoodleQuickForm &$mform, array &$ajaxformdata = null) {
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
                '~' => get_string('contains', 'mod_booking'),
            ];
            $mform->addElement('select', 'condition_enter_userprofilefield_operator',
                get_string('rule_operator', 'mod_booking'), $operators);

            $mform->addElement('text', 'condition_enter_userprofilefield_textfield',
                get_string('condition_textfield', 'mod_booking'));
            $mform->setType('condition_enter_userprofilefield_textfield', PARAM_TEXT);
        }
    }

    /**
     * Get the name of the rule.
     *
     * @param bool $localized
     * @return string the name of the rule
     */
    public function get_name_of_condition($localized = true) {
        return $localized ? get_string($this->conditionname, 'mod_booking') : $this->conditionname;
    }

    /**
     * Saves the JSON for the condition into the $data object.
     *
     * @param stdClass $data form data reference
     */
    public function save_condition(stdClass &$data) {

        if (!isset($data->rulejson)) {
            $jsonobject = new stdClass();
        } else {
            $jsonobject = json_decode($data->rulejson);
        }

        $jsonobject->conditionname = $this->conditionname;
        $jsonobject->conditiondata = new stdClass();
        $jsonobject->conditiondata->cpfield = $data->condition_enter_userprofilefield_cpfield ?? '';
        $jsonobject->conditiondata->operator = $data->condition_enter_userprofilefield_operator ?? '';
        $jsonobject->conditiondata->textfield = $data->condition_enter_userprofilefield_textfield ?? '';

        $data->rulejson = json_encode($jsonobject);
    }

    /**
     * Sets the rule defaults when loading the form.
     * @param stdClass $data reference to the default values
     * @param stdClass $record a record from booking_rules
     */
    public function set_defaults(stdClass &$data, stdClass $record) {

        $data->bookingruleconditiontype = $this->conditionname;

        $jsonobject = json_decode($record->rulejson);
        $conditiondata = $jsonobject->conditiondata;

        $data->condition_enter_userprofilefield_cpfield = $conditiondata->cpfield;
        $data->condition_enter_userprofilefield_operator = $conditiondata->operator;
        $data->condition_enter_userprofilefield_textfield = $conditiondata->textfield;
    }

    /**
     * Execute the condition.
     *
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
                    " LIKE CONCAT('%', :conditiontextfield, '%')
                      AND :conditiontextfield1 <> ''
                      AND :conditiontextfield2 IS NOT NULL";
                break;
            case '=':
            default:
                $sqlcomparepart = $DB->sql_compare_text("ud.data") . " = :conditiontextfield";
                break;
        }

        $params['conditiontextfield'] = $this->textfield;
        $params['conditiontextfield1'] = $this->textfield;
        $params['conditiontextfield2'] = $this->textfield;

        // We pass the restriction to the userid in the params.
        // If its not 0, we add the restirction.
        $anduserid = '';
        if (!empty($params['userid'])) {
            // We cannot use params twice, so we need to use userid2.
            $params['userid2'] = $params['userid'];
            $anduserid = "AND ud.userid = :userid2";
        }

        // We need the hack with uniqueid so we do not lose entries ...as the first column needs to be unique.
        $sql->select = " CONCAT(bo.id, '-', ud.userid) uniqueid, " . $sql->select;
        $sql->select .= ", ud.userid userid";

        $sql->from .= " JOIN {user_info_data} ud ON $sqlcomparepart";

        $sql->where .= " AND ud.fieldid IN (
                    SELECT DISTINCT id
                    FROM {user_info_field} uif
                    WHERE uif.shortname = :cpfield
                )
                $anduserid ";

        $params['cpfield'] = $this->cpfield;
    }
}
