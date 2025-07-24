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
 * Condition to identify the user who triggered an event or the user who was affected by an event.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\booking_rules\conditions;

use mod_booking\booking_rules\booking_rule_condition;
use moodle_exception;
use MoodleQuickForm;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Condition to identify the user who triggered an event (userid of event).
 *
 * @package mod_booking
 * @copyright 2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class select_user_from_event implements booking_rule_condition {
    /** @var string $conditionname */
    public $conditionname = 'select_user_from_event';

    /** @var string $conditionnamestringid Id of localized string for name of rule condition*/
    protected $conditionnamestringid = 'selectuserfromevent';

    /** @var string $conditiontype */
    public $userfromeventtype = '0';

    /** @var int $userid the user who triggered an event */
    public $userid = 0;

    /** @var int $relateduserid the user affected by an event */
    public $relateduserid = 0;

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

        if (!empty($ruleobj->conditiondata->userfromeventtype)) {
            $this->userfromeventtype = $ruleobj->conditiondata->userfromeventtype;
        }

        $event = $ruleobj->ruledata->boevent::restore((array)$ruleobj->datafromevent, []);

        $datafromevent = $event->get_data();

        // The user who triggered the event.
        if (!empty($datafromevent['userid'])) {
            $this->userid = $datafromevent['userid'];
        }

        // The user affected by the event.
        if (!empty($datafromevent['relateduserid'])) {
            $this->relateduserid = $datafromevent['relateduserid'];
        }
    }

    /**
     * Add condition to mform.
     *
     * @param MoodleQuickForm $mform
     * @param ?array $ajaxformdata
     * @return void
     */
    public function add_condition_to_mform(MoodleQuickForm &$mform, ?array &$ajaxformdata = null) {

        // The event selected in the form.
        $eventnameonly = '';
        if (!empty($ajaxformdata["rule_react_on_event_event"])) {
            $eventnameonly = str_replace("\\mod_booking\\event\\", "", $ajaxformdata["rule_react_on_event_event"]);
        }

        self::add_userselect_to_mform($mform);
    }

    /**
     * Add select to choose if the user should be the one who triggered the event or the related user.
     *
     * @param MoodleQuickForm $mform
     *
     * @return void
     *
     */
    public static function add_userselect_to_mform(MoodleQuickForm &$mform) {
        // This is a list of events supporting relateduserid (affected user of the event).
        $eventssupportingrelateduserid = [
            'bookingoption_completed',
            'custom_message_sent',
            'bookinganswer_confirmed',
            'bookinganswer_cancelled',
            'bookingoptionwaitinglist_booked',
            'bookingoption_booked',
            'bookinganswer_waitingforconfirmation',
            '\local_shopping_cart\event\item_bought',
            '\local_shopping_cart\event\item_canceled',
            '\local_shopping_cart\event\payment_confirmed',
            // More events yet to come...
        ];

        $mform->addElement(
            'static',
            'condition_select_user_from_event',
            '',
            get_string('conditionselectuserfromevent_desc', 'mod_booking')
        );

        // We need to check if the event supports relateduserid (affected user of the event).
        $userfromeventoptions["0"] = get_string('choose...', 'mod_booking');
        if (empty($eventnameonly) || in_array($eventnameonly, $eventssupportingrelateduserid)) {
            $userfromeventoptions["relateduserid"] = get_string('useraffectedbyevent', 'mod_booking');
        }
        // Userid (user who triggered) must be supported by every event. If not, the event was not created correctly.
        $userfromeventoptions["userid"] = get_string('userwhotriggeredevent', 'mod_booking');

        $mform->addElement(
            'select',
            'condition_select_user_from_event_type',
            get_string('conditionselectuserfromeventtype', 'mod_booking'),
            $userfromeventoptions
        );
    }

    /**
     * Get the name of the condition.
     *
     * @param bool $localized
     * @return string the name of the condition
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
        $jsonobject->conditiondata->userfromeventtype = $data->condition_select_user_from_event_type ?? '0';

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
        $data->condition_select_user_from_event_type = $jsonobject->conditiondata->userfromeventtype;
    }

    /**
     * Execute the condition.
     *
     * @param stdClass $sql
     * @param array $params
     */
    public function execute(stdClass &$sql, array &$params): void {

        global $DB;

        switch ($this->userfromeventtype) {
            case "userid":
                // The user who triggered the event.
                $chosenuserid = $this->userid;
                break;
            case "relateduserid":
                $chosenuserid = $this->relateduserid;
                // The user affected by the event.
                break;
            default:
                throw new moodle_exception('error: missing userid type for userfromevent condition');
        }

        // If the select contains optiondate, we also need to include it in uniqueid.
        if (strpos($sql->select, 'optiondate') !== false) {
            $concat = $DB->sql_concat("bo.id", "'-'", "bod.id", "'-'", "u.id");
        } else {
            $concat = $DB->sql_concat("bo.id", "'-'", "u.id");
        }

        // We need the hack with uniqueid so we do not lose entries ...as the first column needs to be unique.
        $sql->select = " $concat uniqueid, " . $sql->select;
        $sql->select .= ", u.id userid";
        $sql->from .= " JOIN {user} u ON u.id = :chosenuserid "; // We want to join only the chosen user.

        $params['chosenuserid'] = $chosenuserid;
    }
}
