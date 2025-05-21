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

use mod_booking\booking_rules\booking_rule_condition;
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
class select_student_in_bo implements booking_rule_condition {
    /** @var string $rulename */
    public $conditionname = 'select_student_in_bo';

    /** @var string $conditionnamestringid Id of localized string for name of rule condition*/
    protected $conditionnamestringid = 'selectstudentinbo';

    /** @var string $role */
    public $borole = null;

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
        $this->borole = $conditiondata->borole;
    }

    /**
     * Only customizable functions need to return their necessary form elements.
     *
     * @param MoodleQuickForm $mform
     * @param ?array $ajaxformdata
     * @return void
     */
    public function add_condition_to_mform(MoodleQuickForm &$mform, ?array &$ajaxformdata = null) {

        $mform->addElement(
            'static',
            'condition_select_student_in_bo',
            '',
            get_string('conditionselectstudentinbo_desc', 'mod_booking')
        );

        $courseroles = [
            -1 => get_string('choose...', 'mod_booking'),
            MOD_BOOKING_STATUSPARAM_BOOKED => get_string('studentbooked', 'mod_booking'),
            MOD_BOOKING_STATUSPARAM_WAITINGLIST => get_string('studentwaitinglist', 'mod_booking'),
            MOD_BOOKING_STATUSPARAM_NOTIFYMELIST => get_string('studentnotificationlist', 'mod_booking'),
            MOD_BOOKING_STATUSPARAM_DELETED => get_string('studentdeleted', 'mod_booking'),
            "smallerthan" . MOD_BOOKING_STATUSPARAM_WAITINGLIST => get_string('studentbookedandwaitinglist', 'mod_booking'),
        ];

        $mform->addElement(
            'select',
            'condition_select_student_in_bo_borole',
            get_string('conditionselectstudentinboroles', 'mod_booking'),
            $courseroles
        );
    }

    /**
     * Get the name of the rule.
     *
     * @param bool $localized
     * @return string the name of the rule
     */
    public function get_name_of_condition($localized = true) {
        return $localized ? get_string($this->conditionnamestringid, 'mod_booking') : $this->conditionname;
    }

    /**
     * Save the JSON for all sendmail_daysbefore rules defined in form.
     * @param stdClass $data form data reference
     */
    public function save_condition(stdClass &$data): void {
        global $DB;

        if (!isset($data->rulejson)) {
            $jsonobject = new stdClass();
        } else {
            $jsonobject = json_decode($data->rulejson);
        }

        $jsonobject->conditionname = $this->conditionname;
        $jsonobject->conditiondata = new stdClass();
        $jsonobject->conditiondata->borole = $data->condition_select_student_in_bo_borole ?? '';

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

        $data->condition_select_student_in_bo_borole = $conditiondata->borole;
    }

    /**
     * Execute the condition.
     * We receive an array of stdclasses with the keys optinid & cmid.
     * @param stdClass $sql
     * @param array $params
     *
     */
    public function execute(stdClass &$sql, array &$params): void {

        global $DB;

        // We pass the restriction to the userid in the params.
        // If its not 0, we add the restirction.
        $anduserid = '';
        if (!empty($params['userid'])) {
            // We cannot use params twice, so we need to use userid2.
            $params['userid2'] = $params['userid'];
            $anduserid = "AND ba.userid = :userid2";
        }

        // If the select contains optiondate, we also need to include it in uniqueid.
        // We need the hack with uniqueid so we do not lose entries.
        if (strpos($sql->select, 'optiondate') !== false) {
            $concat = $DB->sql_concat("ba.id", "'-'", "bod.id");
        } else {
            $concat = "ba.id";
        }
        // Also select userid.
        $sql->select = "$concat AS uniqueid, ba.id AS baid, ba.userid, " . $sql->select;

        $sql->from .= " JOIN {booking_answers} ba ON bo.id = ba.optionid ";

        switch ($this->borole) {
            case 'smallerthan' . MOD_BOOKING_STATUSPARAM_WAITINGLIST:
                $operator = '<=';
                $borole = MOD_BOOKING_STATUSPARAM_WAITINGLIST;
                break;
            default:
                $operator = '=';
                $borole = $this->borole;
        }

        $sql->where .= " AND ba.waitinglist $operator :borole $anduserid ";
        // Add sorting in case we use this condition for interval notification.
        $sql->sort = " ba.timemodified ASC ";
        $params['borole'] = $borole;
    }
}
