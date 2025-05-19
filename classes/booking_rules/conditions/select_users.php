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
use mod_booking\singleton_service;
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
class select_users implements booking_rule_condition {
    /** @var string $conditionname */
    public $conditionname = 'select_users';

    /** @var string $conditionnamestringid Id of localized string for name of rule condition*/
    protected $conditionnamestringid = 'selectusers';

    /** @var array $userids */
    public $userids = [];

    /** @var string $rulejson */
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
        $this->userids = $conditiondata->userids;
    }

    /**
     * Add condition to mform.
     *
     * @param MoodleQuickForm $mform
     * @param ?array $ajaxformdata
     * @return void
     */
    public function add_condition_to_mform(MoodleQuickForm &$mform, ?array &$ajaxformdata = null) {

        $options = [
            'ajax' => 'mod_booking/form_users_selector',
            'multiple' => true,
            'noselectionstring' => get_string('choose...', 'mod_booking'),
            'valuehtmlcallback' => function ($value) {
                global $OUTPUT;
                if (empty($value)) {
                    return get_string('choose...', 'mod_booking');
                }
                $user = singleton_service::get_instance_of_user((int)$value);
                $details = [
                    'id' => $user->id,
                    'email' => $user->email,
                    'firstname' => $user->firstname,
                    'lastname' => $user->lastname,
                ];
                return $OUTPUT->render_from_template(
                    'mod_booking/form-user-selector-suggestion',
                    $details
                );
            },
        ];

        $mform->addElement(
            'autocomplete',
            'condition_select_users_userids',
            get_string('conditionselectusersuserids', 'mod_booking'),
            [],
            $options
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
     * Saves the JSON for the condition into the $data object.
     * @param stdClass $data form data reference
     */
    public function save_condition(stdClass &$data): void {

        if (!isset($data->rulejson)) {
            $jsonobject = new stdClass();
        } else {
            $jsonobject = json_decode($data->rulejson);
        }

        $jsonobject->conditionname = $this->conditionname;
        $jsonobject->conditiondata = new stdClass();
        $jsonobject->conditiondata->userids = $data->condition_select_users_userids ?? '';

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

        $data->condition_select_users_userids = $conditiondata->userids;
    }

    /**
     * Execute the condition.
     *
     * @param stdClass $sql
     * @param array $params
     */
    public function execute(stdClass &$sql, array &$params): void {
        global $DB;

        [$inorequal, $inorequalparams] = $DB->get_in_or_equal($this->userids, SQL_PARAMS_NAMED);

        // We need the hack with uniqueid so we do not lose entries ...as the first column needs to be unique.
        // If the select contains optiondate, we also need to include it in uniqueid.
        if (strpos($sql->select, 'optiondate') !== false) {
            $concat = $DB->sql_concat("bo.id", "'-'", "bod.id", "'-'", "u.id");
        } else {
            $concat = $DB->sql_concat("bo.id", "'-'", "u.id");
        }

        $sql->select = " $concat uniqueid, " . $sql->select;
        $sql->select .= ", u.id userid";

        $sql->from .= " JOIN {user} u ON 1 = 1 "; // We want to join all users here.

        $sql->where .= " AND u.id $inorequal";

        foreach ($inorequalparams as $key => $value) {
            $params[$key] = $value;
        }
    }
}
