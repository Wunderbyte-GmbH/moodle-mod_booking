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

use core_component;
use core_plugin_manager;
use mod_booking\booking_rules\actions_info;
use mod_booking\booking_rules\booking_rule;
use mod_booking\booking_rules\booking_rules;
use mod_booking\booking_rules\conditions_info;
use mod_booking\booking_rules\rules_info;
use mod_booking\option\fields\applybookingrules;
use mod_booking\singleton_service;
use moodle_url;
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
class rule_react_on_event implements booking_rule {
    /** @var int $ruleid */
    public $ruleid = 0;

    /** @var string $rulename */
    protected $rulename = 'rule_react_on_event';

    /** @var string $rulenamestringid ID of localized string for name of rule*/
    protected $rulenamestringid = 'rulereactonevent';

    /** @var string $name */
    public $name = null;

    /** @var string $rulejson */
    public $rulejson = null;

    /** @var int $ruleid */
    public $boevent = null;

    /** @var object $intervaldata */
    public $intervaldata = null;

    /** @var bool $ruleisactive */
    public $ruleisactive = true;

    /** Const state of booking option */
    public const ALWAYS = 0;

    /** Const state of booking option */
    public const FULLYBOOKED = 1;

    /** Const state of booking option */
    public const NOTFULLYBOOKED = 2;

    /** Const state of booking option */
    public const FULLWAITINGLIST = 3;

    /** Const state of booking option */
    public const NOTFULLWAITINGLIST = 4;

    /**
     * Load json data from DB into the object.
     * @param stdClass $record a rule record from DB
     */
    public function set_ruledata(stdClass $record) {
        $this->ruleid = $record->id ?? 0;
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
        $this->boevent = $ruleobj->ruledata->boevent;
        $this->intervaldata = $ruleobj->intervaldata ?? null;
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

        // Only these events are currently supported and tested.
        $allowedeventkeys = [
            'bookingoption_freetobookagain',
            'bookinganswer_cancelled',
            'bookingoption_booked',
            'bookingoptionwaitinglist_booked',
            'bookingoption_completed',
            'bookinganswer_confirmed',
            'bookinganswer_waitingforconfirmation',
            'bookingoption_updated',
            'bookingoption_cancelled',
            'custom_message_sent',
            'custom_bulk_message_sent',
            'optiondates_teacher_added',
            'optiondates_teacher_deleted',
            'rest_script_success',
            'enrollink_triggered',
            'bookingoption_bookedviaautoenrol',
        ];

        // Get a list of all booking events.
        $allevents = get_list_of_booking_events();
        foreach (core_plugin_manager::instance()->get_plugins_of_type('bookingextension') as $plugin) {
            $class = "\\bookingextension_{$plugin->name}\\{$plugin->name}";
            if (!class_exists($class)) {
                continue; // Skip if the class does not exist.
            }
            $pluginevents = $class::get_allowedruleeventkeys();
            $allowedeventkeys = array_merge($allowedeventkeys, $pluginevents);
            $events = core_component::get_component_classes_in_namespace("bookingextension_{$plugin->name}", 'event');
            foreach (array_keys($events) as $event) {
                $event = (string) $event; // Just for linting.
                // We need to filter all classes that extend event base, or the base class itself.
                if (is_a($event, \core\event\base::class, true)) {
                    $parts = explode('\\', $event);
                    $eventwithnamespace = "\\{$event}";
                    $allevents[$eventwithnamespace] = $eventwithnamespace::get_name() .
                        " (" . array_pop($parts) . ")";
                }
            }
        }
        $allowedevents["0"] = get_string('choose...', 'mod_booking');

        foreach ($allevents as $key => $value) {
            // Get the class name (last part of fully qualified class).
            $eventnameonly = substr(strrchr($key, '\\'), 1);
            if (in_array($eventnameonly, $allowedeventkeys)) {
                $allowedevents[$key] = $value;
            }
        }

        // If shoppingcart is installed, we add events from shoppingcart.
        $pluginman = core_plugin_manager::instance();
        $shoppingcart = $pluginman->get_plugin_info('local_shopping_cart');
        if ($shoppingcart) {
            global $CFG;
            require_once($CFG->dirroot . '/local/shopping_cart/lib.php');
            $eventkeysfromshoppingcart = [
                'payment_confirmed',
                'item_bought',
                'item_canceled',
            ];
            $shoppingcartevents = get_list_of_shoppingcart_events();
            foreach ($shoppingcartevents as $key => $value) {
                $eventnameonly = str_replace("\\local_shopping_cart\\event\\", "", $key);
                if (in_array($eventnameonly, $eventkeysfromshoppingcart)) {
                    $scallowedevents[$key] = $value;
                }
            }

            $allowedevents = array_merge($allowedevents, $scallowedevents);
        }

        // Workaround: We need a group to get hideif to work.
        $mform->addElement(
            'static',
            'rule_react_on_event_desc',
            '',
            get_string('rulereactonevent_desc', 'mod_booking')
        );

        $mform->addElement(
            'select',
            'rule_react_on_event_event',
            get_string('ruleevent', 'mod_booking'),
            $allowedevents
        );

        // Add info about settings concerning bookingoption_updated event.
        $url = new moodle_url('/admin/category.php', ['category' => 'modbookingfolder']);
        $linktosettings = $url->out();

        $mform->addElement(
            'static',
            'react_on_change_info',
            '',
            get_string('rulereactonchangeevent_desc', 'mod_booking', $linktosettings)
        );

        $conditions = [
            self::ALWAYS => get_string('always', 'mod_booking'),
            self::FULLYBOOKED => get_string('fullybooked', 'mod_booking'),
            self::NOTFULLYBOOKED => get_string('notfullybooked', 'mod_booking'),
            self::FULLWAITINGLIST => get_string('fullwaitinglist', 'mod_booking'),
            self::NOTFULLWAITINGLIST => get_string('notfullwaitinglist', 'mod_booking'),
        ];

        $mform->addElement(
            'select',
            'rule_react_on_event_condition',
            get_string('ruleeventcondition', 'mod_booking'),
            $conditions
        );

        $mform->addElement(
            'text',
            'rule_react_on_event_after_completion',
            get_string('rulereactoneventaftercompletion', 'mod_booking')
        );
        $mform->setType('rule_react_on_event_after_completion', PARAM_INT);
        $mform->setDefault('rule_react_on_event_after_completion', 1);
        $mform->addHelpButton('rule_react_on_event_after_completion', 'rulereactoneventaftercompletion', 'mod_booking');

        $notborelatedevents = [
            '\mod_booking\event\custom_message_sent',
            '\mod_booking\event\custom_bulk_message_sent',
            '\mod_booking\event\rest_script_success',
        ];

        $mform->hideIf('rule_react_on_event_after_completion', 'rule_react_on_event_event', 'in', $notborelatedevents);

        $rules = booking_rules::get_list_of_saved_rules_by_context($ajaxformdata['contextid'] ?? 1);

        $rulesselect = [];
        foreach ($rules as $rule) {
            if (empty($rule)) {
                continue;
            }

            // Todo: Better description where this rule comes from. For the moment we simply hand over the contextid.
            $ruleobject = json_decode($rule->rulejson);
            $rulesselect[$rule->id] = $ruleobject->name . " ($rule->contextid)";
        }

        $options = [
            'multiple' => true,
            'noselectionstring' => get_string('noselection', 'mod_booking'),
        ];

        $mform->addElement(
            'autocomplete',
            'rule_react_on_event_cancelrules',
            get_string('rulereactoneventcancelrules', 'mod_booking'),
            $rulesselect,
            $options
        );
    }

    /**
     * Get the name of the rule.
     * @param bool $localized
     * @return string
     */
    public function get_name_of_rule(bool $localized = true): string {
        return $localized ? get_string($this->rulenamestringid, 'mod_booking') : $this->rulename;
    }

    /**
     * Save the JSON for daysbefore rule defined in form.
     * The role has to determine the handler for condtion and action and get the right json object.
     * @param stdClass $data form data reference
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
        $jsonobject->ruledata->boevent = $data->rule_react_on_event_event ?? '';
        $jsonobject->ruledata->condition = $data->rule_react_on_event_condition ?? '';
        $jsonobject->ruledata->aftercompletion = $data->rule_react_on_event_after_completion ?? '';
        $jsonobject->ruledata->cancelrules = $data->rule_react_on_event_cancelrules ?? [];

        $record->rulejson = json_encode($jsonobject);
        $record->rulename = $this->rulename;
        $record->eventname = $data->rule_react_on_event_event ?? '';
        $record->contextid = $data->contextid ?? 1;
        $record->isactive = $data->ruleisactive;
        if (isset($data->useastemplate)) {
            $jsonobject->useastemplate = $data->useastemplate;
            $record->useastemplate = $data->useastemplate;
        }

        // If we can update, we add the id here.
        if (!empty($data->id)) {
            $record->id = $data->id;
            $DB->update_record('booking_rules', $record);
        } else {
            $ruleid = $DB->insert_record('booking_rules', $record);
            $this->ruleid = $ruleid;
        }
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
        $data->ruleisactive = $record->isactive;
        $data->rule_react_on_event_event = $ruledata->boevent;
        $data->rule_react_on_event_condition = $ruledata->condition;
        $data->rule_react_on_event_after_completion = $ruledata->aftercompletion;
        $data->rule_react_on_event_cancelrules = $ruledata->cancelrules;
    }

    /**
     * Execute the rule.
     * @param int $optionid optional
     * @param int $userid optional
     */
    public function execute(int $optionid = 0, int $userid = 0) {

        $jsonobject = json_decode($this->rulejson);
        $datafromevent = $jsonobject->datafromevent ?? null;

        // This rule executes only on event.
        // And every event will have an optionid, because it's linked to a specific option.
        if ($optionid === 0) {
            // But there is one special case.
            // The payment_confirmed event may have a couple of options in the cart.
            // We still need one optionid, so we look in the cart and take the first matching optionid.
            if (isset($datafromevent->other->cart)) {
                $cart = json_decode($datafromevent->other->cart);
                foreach (($cart->historyitems ?? []) as $item) {
                    if (
                        $item->componentname === 'mod_booking'
                        && $item->area === 'option'
                    ) {
                        $optionid = $item->itemid;
                        break;
                    }
                }
            }
            // If there is still no option id, we abort.
            if ($optionid == 0) {
                return;
            }
        }

        if (!applybookingrules::apply_rule($optionid, $this->ruleid)) {
            return;
        }

        // Only execute rules for bookingoption_changed event according to settings.
        if (
            !empty(get_config('booking', 'limitchangestrackinginrules'))
            && $datafromevent->eventname == '\mod_booking\event\bookingoption_updated'
        ) {
            if (!empty($datafromevent->other->changes)) {
                $changes = $datafromevent->other->changes;
                foreach ($changes as $index => $change) {
                    if (empty($change->fieldname)) {
                        continue;
                    }
                    if ($this->ruleevent_excluded_via_config($change->fieldname, (array) $change)) {
                        unset($datafromevent->other->changes[$index]);
                    }
                }
            }
            // If there are no more changes to be handled, we can skip the execution.
            if (empty($datafromevent->other->changes)) {
                return;
            }
        }

        // We reuse this code when we check for validity, therefore we use a separate function.
        $records = $this->get_records_for_execution($optionid, $userid);

        // Now we finally execution the action, where we pass on every record.
        $action = actions_info::get_action($jsonobject->actionname);

        $jsonobject->datafromevent = $datafromevent;
        $this->rulejson = json_encode($jsonobject);

        $action->set_actiondata_from_json($this->rulejson);
        // For the execution, we need a rule id, otherwise we can't test for consistency.
        $action->ruleid = $this->ruleid;

        foreach ($records as $record) {
            // Set the time of when the task should run.
            $nextruntime = time();
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
     * @return bool true if the rule still applies, false if not
     */
    public function check_if_rule_still_applies(int $optionid, int $userid, int $nextruntime): bool {

        if (empty($this->ruleisactive)) {
            return false;
        }

        $jsonobject = json_decode($this->rulejson);
        $ruledata = $jsonobject->ruledata;
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $ba = singleton_service::get_instance_of_booking_answers($settings);

        if (!$this->rule_still_in_time($jsonobject, $settings)) {
            return false;
        }

        switch ($ruledata->condition ?? self::ALWAYS) {
            case self::ALWAYS:
                return true;
            case self::FULLYBOOKED:
                if ($ba->is_fully_booked()) {
                    return true;
                }
                return false;
            case self::NOTFULLYBOOKED:
                if ($ba->is_fully_booked()) {
                    return false;
                }
                return true;
            case self::FULLWAITINGLIST:
                if ($ba->is_fully_booked_on_waitinglist()) {
                    return true;
                }
                return false;
            case self::NOTFULLWAITINGLIST:
                if ($ba->is_fully_booked_on_waitinglist()) {
                    return false;
                }
                return true;
        }

        // For this rule, we don't need to check because everything is sent directly after event was triggered.
        return true;
    }

    /**
     * Checks if the courseendtime defined in bookingoption is not after time defined in rule.
     *
     * @param object $ruledata
     * @param object $bookingoption
     *
     * @return bool
     *
     */
    private static function rule_still_in_time(object $ruledata, object $bookingoption): bool {
        $aftercompletiondays = $ruledata->ruledata->aftercompletion ?? null;
        if (empty($aftercompletiondays)) {
            return true;
        };

        $endtime = (int)$bookingoption->courseendtime ?? 0;
        if (empty($endtime)) {
            return true;
        }

        $now = time();
        $days = (int)$aftercompletiondays;
        $add = $days * 24 * 60 * 60;
        if ($endtime + $add <= $now) {
            return false;
        } else {
            return true;
        }
    }

    /**
     * This helperfunction builds the sql with the help of the condition and returns the records.
     * Testmode means that we don't limit by now timestamp.
     *
     * @param int $optionid
     * @param int $userid
     * @return array
     */
    public function get_records_for_execution(int $optionid, int $userid = 0) {
        global $DB;

        // Execution of a rule is a complexe action.
        // Going from rule to condition to action...
        // ... we need to go into actions with an array of records...
        // ... which has the keys cmid, optionid & userid.

        $jsonobject = json_decode($this->rulejson);

        $params = [
            'optionid' => $optionid,
            'userid' => $userid,
            'json' => $this->rulejson,
        ];

        $sql = new stdClass();

        $sql->select = "bo.id optionid, cm.id cmid";
        $sql->from = "{booking_options} bo
                    JOIN {course_modules} cm
                    ON cm.instance = bo.bookingid
                    JOIN {modules} m
                    ON m.name = 'booking' AND m.id = cm.module";
        $sql->where = " bo.id = :optionid";

        // Now that we know the ids of the booking options concerend, we will determine the users concerned.
        // The condition execution will add their own code to the sql.

        $condition = conditions_info::get_condition($jsonobject->conditionname);

        $condition->set_conditiondata_from_json($this->rulejson);

        $condition->execute($sql, $params);

        $sqlstring = "SELECT $sql->select FROM $sql->from WHERE $sql->where";

        // Sorting is used for interval notification (action send_mail_interval).
        if (isset($sql->sort)) {
            $sqlstring .= "ORDER BY $sql->sort";
        }

        $records = $DB->get_records_sql($sqlstring, $params);

        return $records;
    }

    /**
     * Check if event is excluded via config.
     *
     * @param string $fieldname
     * @param array $changes
     *
     * @return bool
     *
     */
    private function ruleevent_excluded_via_config(string $fieldname, array $changes): bool {

        if (empty(get_config('booking', 'limitchangestrackinginrules'))) {
            return false;
        }

        switch ($fieldname) {
            // Teacher.
            case "text":
                $config = get_config('booking', 'listentotextchange');
                break;
            case "teachers":
                $config = get_config('booking', 'listentoteacherschange');
                break;
            // Responsiblecontact.
            case "responsiblecontact":
                $config = get_config('booking', 'listentoresponsiblepersonchange');
                break;
            // Beginning and ending or location of date.
            case "dates":
                $datesconfig = get_config('booking', 'listentotimestampchange');
                // Case 1: Dates changes are tracked.
                if ($datesconfig) {
                    $config = $datesconfig;
                    if (get_config('booking', 'listentoaddresschange')) {
                        break;
                    }
                    $datechange = false;
                    // If only adress was changed and we don't track adress changes, ruleevent is still excluded.
                    foreach ($changes['oldvalue'] as $index => $oldvalue) {
                        if (
                            $oldvalue->coursestarttime != $changes['newvalue'][$index]->coursestarttime
                            || $oldvalue->courseendtime != $changes['newvalue'][$index]->courseendtime
                        ) {
                            $datechange = true;
                        }
                    }
                    if (!$datechange) {
                        return true;
                    }
                } else if (get_config('booking', 'listentoaddresschange')) {
                    // Case 2: Dates changes are not tracked, but adress changes are tracked.
                    $entitychange = false;
                    // If only adress was changed and we don't track adress changes, ruleevent is still excluded.
                    foreach ($changes['oldvalue'] as $index => $oldvalue) {
                        // Given entities plugin is installed.
                        $ov = $oldvalue->entityid ?? 0;
                        $nv = $changes['newvalue'][$index]->entityid ?? 0;
                        if (
                            !(empty($ov) && empty($nv))
                            && $ov != $nv
                        ) {
                            $entitychange = true;
                        }
                    }
                    $config = $entitychange;
                } else {
                    $config = false;
                }
                break;
            // Address can be with or without entities plugin.
            case "address":
                $config = get_config('booking', 'listentoaddresschange');
                break;
            case "entities":
                $config = get_config('booking', 'listentoaddresschange');
                break;
            case "location":
                $config = get_config('booking', 'listentoaddresschange');
                break;
            case "customfields":
                // We never allow customfields.
                $config = null;
                break;
            default:
                return true;
        }

        // Empty means excluded from tracking.
        if (empty($config)) {
            return true;
        } else {
            return false;
        }
    }
}
