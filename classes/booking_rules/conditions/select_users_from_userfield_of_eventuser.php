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
 * Condition how to identify concerned users by fetching id(s) from a userprofilefield of the user of the event (triggered or concerned).
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Magdalena Holczik
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class select_users_from_userfield_of_eventuser implements booking_rule_condition {
    /** @var string $rulename */
    public $conditionname = 'select_users_from_userfield_of_eventuser';

    /** @var string $conditionnamestringid Id of localized string for name of rule condition*/
    protected $conditionnamestringid = 'selectusersfromuserfieldofeventuser';

    /** @var string $selecteduserofevent */
    public $selecteduserofevent = null;

    /** @var string $fieldofuserfromevent */
    public $fieldofuserfromevent = null;

    /** @var string $rulejson a json string for a booking rule */
    public $rulejson = '';

    /**
     * Function to tell if a condition can be combined with a certain booking rule type.
     * @param string $bookingruletype e.g. "rule_daysbefore" or "rule_react_on_event"
     * @return bool true if it can be combined
     */
    public function can_be_combined_with_bookingruletype(string $bookingruletype): bool {
        // This rule cannot be combined with the "days before" rule as it has no event.
        if ($bookingruletype == 'rule_daysbefore') {
            return false;
        } else {
            return true;
        }
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
        $this->fieldofuserfromevent = $conditiondata->fieldofuserfromevent;
        $this->selecteduserofevent = $conditiondata->selecteduserofevent;
    }

    /**
     * Only customizable functions need to return their necessary form elements.
     *
     * @param MoodleQuickForm $mform
     * @param ?array $ajaxformdata
     * @return void
     */
    public function add_condition_to_mform(MoodleQuickForm &$mform, ?array &$ajaxformdata = null) {
        global $DB;


        // Get a list of allowed option fields to compare with custom user profile field.
        // Currently we only use fields containing VARCHAR in DB.
        $userfields = profile_get_custom_fields();

        if (!empty($userfields)) {
            $customuserprofilefieldsarray = [];
            $customuserprofilefieldsarray[0] = get_string('choose...', 'mod_booking');

            // Create an array of key => value pairs for the dropdown.
            foreach ($userfields as $customuserprofilefield) {
                $customuserprofilefieldsarray[$customuserprofilefield->shortname] = $customuserprofilefield->name;
            }

            $mform->addElement(
                'select',
                'fieldofuserfromevent',
                get_string('rulecustomprofilefield', 'mod_booking'),
                $customuserprofilefieldsarray
            );

            select_user_from_event::add_userselect_to_mform($mform);
        }
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
     *
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
        $jsonobject->conditiondata->fieldofuserfromevent = $data->fieldofuserfromevent ?? '';
        $jsonobject->conditiondata->selecteduserofevent = $data->selecteduserofevent ?? '';

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

        $data->fieldofuserfromevent = $conditiondata->fieldofuserfromevent;
        $data->selecteduserofevent = $conditiondata->selecteduserofevent;
    }

    /**
     * Execute the condition.
     * We receive an array of stdclasses with the keys optionid & cmid.
     * @param stdClass $sql
     * @param array $params
     */
    public function execute(stdClass &$sql, array &$params): void {
        global $DB;

        $sqlcomparepart = "";

        // I can modify the whole sql here!
        // Userid is in params.

        // 1. Make sure it's the right user.
        // 2. Check in the given custom user profilefield for values
        // 3. Explode these in case there are multiple (",")
        // 4. if numeric -> use as userids


        // $concat = $DB->sql_concat("'%'", "bo.$this->optionfield", "'%'");
        // switch ($this->operator) {
        //     case '~':
        //         $sqlcomparepart = $DB->sql_compare_text("ud.data") .
        //             " LIKE $concat
        //               AND bo." . $this->optionfield . " <> ''
        //               AND bo." . $this->optionfield . " IS NOT NULL";
        //         break;
        //     case '=':
        //     default:
        //         $sqlcomparepart = $DB->sql_compare_text("ud.data") . " = bo." . $this->optionfield;
        //         break;
        // }

        // // We pass the restriction to the userid in the params.
        // // If its not 0, we add the restirction.
        // $anduserid = '';
        // if (!empty($params['userid'])) {
        //     // We cannot use params twice, so we need to use userid2.
        //     $params['userid2'] = $params['userid'];
        //     $anduserid = "AND ud.userid = :userid2";
        // }

        // // If the select contains optiondate, we also need to include it in uniqueid.
        // if (strpos($sql->select, 'optiondate') !== false) {
        //     $concat = $DB->sql_concat("bo.id", "'-'", "bod.id", "'-'", "ud.userid");
        // } else {
        //     $concat = $DB->sql_concat("bo.id", "'-'", "ud.userid");
        // }

        // // We need the hack with uniqueid so we do not lose entries ...as the first column needs to be unique.
        // $sql->select = " $concat uniqueid, " . $sql->select;
        // $sql->select .= ", ud.userid userid ";

        // $sql->from .= " JOIN {user_info_data} ud ON $sqlcomparepart ";

        // $sql->where .= " AND ud.fieldid IN (
        //             SELECT DISTINCT id
        //             FROM {user_info_field} uif
        //             WHERE uif.shortname = :cpfield
        //         )
        //         $anduserid ";

        // $params['cpfield'] = $this->cpfield;
    }
}
