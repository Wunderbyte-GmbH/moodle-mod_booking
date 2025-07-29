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
 * Condition how to identify concerned users by fetching id(s) from a
 * userprofilefield of the user of the event (triggered or concerned).
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

    /** @var string $fieldofuserfromevent */
    public $fieldofuserfromevent = null;

    /** @var string $userfromeventtype */
    public $userfromeventtype = null;

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
        $this->userfromeventtype = $conditiondata->userfromeventtype;
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
                if (($customuserprofilefield->datatype ?? '') !== 'text') {
                    continue;
                }
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
        $jsonobject->conditiondata->userfromeventtype = $data->condition_select_user_from_event_type ?? '';

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
        $data->condition_select_user_from_event_type = $conditiondata->userfromeventtype;
    }

    /**
     * Execute the condition.
     * We receive an array of stdclasses with the keys optionid & cmid.
     * @param stdClass $sql
     * @param array $params
     */
    public function execute(stdClass &$sql, array &$params): void {
        global $DB;

        // We need multiple Queries here because the splitting of multiple userids is not working correctly for MariaDB/MySQL.
        $data = json_decode($params['json']);
        $usertype = $data->conditiondata->userfromeventtype;
        $userid = $data->datafromevent->$usertype;
        $customfieldname = $data->conditiondata->fieldofuserfromevent ?? '';
        $subsql = "
            SELECT uid.data
            FROM {user_info_data} uid
            JOIN {user_info_field} uif ON uid.fieldid = uif.id
            WHERE uid.userid = :userid AND uif.shortname = :shortname
        ";

        $subparams = [
            'userid' => $userid,
            'shortname' => $customfieldname,
        ];

        $rawvalue = $DB->get_field_sql($subsql, $subparams, IGNORE_MISSING);

        if (empty($rawvalue)) {
            return; // No data, exit early.
        }

        // Split by comma and trim values.
        $userids = array_filter(array_map('trim', explode(',', $rawvalue)));

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');

        $sql->from .= "
            JOIN {user} u ON u.id $insql";

        // First col is uniqueid.
        $select = "CONCAT(bo.id, '_', u.id) AS uniqueid, u.id userid, " . $sql->select;
        $sql->select = $select;

        // Merge new params into existing params.
        $params = array_merge($params, $inparams);
    }
}
