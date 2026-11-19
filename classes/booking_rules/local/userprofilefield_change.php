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

namespace mod_booking\booking_rules\local;

use context;
use context_module;
use core_text;
use core_user;
use mod_booking\event\userprofilefield_changed;
use mod_booking\price;
use mod_booking\singleton_service;
use mod_booking\task\store_userprofilefield_baseline_adhoc;
use mod_booking\task\trigger_userprofilefield_events_adhoc;
use MoodleQuickForm;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Everything the rule "React on event" needs for the event "userprofilefield_changed".
 *
 * The event is triggered when a custom user profile field of a user changes to a value a rule is
 * waiting for, once for every booking option in which the user is booked or on the waiting list.
 * The rule which reacts on the event holds the criteria: the profile field, the value it has to
 * change to, the booking status of the user and the price the user has to pay.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class userprofilefield_change {
    /** @var string The event triggered for every booking option concerned by the change. */
    public const EVENTNAME = '\\mod_booking\\event\\userprofilefield_changed';

    /** @var string Prefix of the user preference which remembers the last known value of a profile field. */
    public const PREFERENCEPREFIX = 'mod_booking_pf_';

    /** @var string Key of the criteria within the ruledata of the rulejson. */
    public const JSONKEY = 'userprofilefield';

    /** @var string Form element for the profile field. */
    public const FORMFIELD = 'rule_react_on_event_upf_field';

    /** @var string Form element for the operator. */
    public const FORMOPERATOR = 'rule_react_on_event_upf_operator';

    /** @var string Form element for the value the field has to change to. */
    public const FORMVALUE = 'rule_react_on_event_upf_value';

    /** @var string Form element for the booking status. */
    public const FORMSTATUS = 'rule_react_on_event_upf_status';

    /** @var string Form element for the price operator. */
    public const FORMPRICEOPERATOR = 'rule_react_on_event_upf_priceoperator';

    /** @var string Form element for the price amount. */
    public const FORMPRICEVALUE = 'rule_react_on_event_upf_pricevalue';

    /** @var string Booking status: booked or on the waiting list. */
    public const STATUS_BOTH = 'both';

    /** @var string Booking status: booked only. */
    public const STATUS_BOOKED = 'booked';

    /** @var string Booking status: on the waiting list only. */
    public const STATUS_WAITINGLIST = 'waitinglist';

    /** @var string No restriction by the price. */
    public const PRICE_ANY = '0';

    /** @var string The price for the user is greater than the given amount. */
    public const PRICE_GREATER = '>';

    /** @var string The price for the user is equal to the given amount. */
    public const PRICE_EQUALS = '=';

    /** @var string The price for the user is less than the given amount. */
    public const PRICE_LESS = '<';

    /** @var int Seconds the task for new users waits, so that one task serves all the users created in the meantime. */
    private const BASELINEDELAY = 60;

    /**
     * Is this the data of the event "userprofilefield_changed"?
     *
     * @param ?object $datafromevent
     * @return bool
     */
    public static function is_event(?object $datafromevent): bool {
        return ($datafromevent->eventname ?? '') === self::EVENTNAME;
    }

    /**
     * Returns the criteria of a rule, or null if the rule has none.
     *
     * @param ?object $ruledata the ruledata of the rulejson
     * @return ?stdClass
     */
    public static function get_criteria(?object $ruledata): ?stdClass {

        if (empty($ruledata->{self::JSONKEY}->cpfield)) {
            return null;
        }

        $stored = $ruledata->{self::JSONKEY};

        $criteria = new stdClass();
        $criteria->cpfield = (string) $stored->cpfield;
        $criteria->operator = (string) ($stored->operator ?? '=');
        $criteria->value = (string) ($stored->value ?? '');
        $criteria->bookingstatus = (string) ($stored->bookingstatus ?? self::STATUS_BOTH);
        $criteria->priceoperator = (string) ($stored->priceoperator ?? self::PRICE_ANY);
        $criteria->pricevalue = (float) ($stored->pricevalue ?? 0);

        return $criteria;
    }

    /**
     * Add the form elements of the criteria to the form of the rule "React on event".
     * They are only shown when the event "userprofilefield_changed" is selected.
     *
     * @param MoodleQuickForm $mform
     * @return void
     */
    public static function add_to_mform(MoodleQuickForm &$mform) {
        global $DB;

        $eventelement = 'rule_react_on_event_event';

        $fields = [0 => get_string('choose...', 'mod_booking')];
        foreach ($DB->get_records('user_info_field', null, 'name ASC', 'id, name, shortname') as $field) {
            $fields[$field->shortname] = format_string($field->name) . " ($field->shortname)";
        }

        $mform->addElement('select', self::FORMFIELD, get_string('rulecustomprofilefield', 'mod_booking'), $fields);
        $mform->addHelpButton(self::FORMFIELD, 'ruleuserprofilefieldchanged', 'mod_booking');

        $mform->addElement(
            'select',
            self::FORMOPERATOR,
            get_string('ruleoperator', 'mod_booking'),
            self::get_operators()
        );

        $mform->addElement('text', self::FORMVALUE, get_string('ruleuserprofilefieldchangedvalue', 'mod_booking'));
        $mform->setType(self::FORMVALUE, PARAM_TEXT);

        $mform->addElement(
            'select',
            self::FORMSTATUS,
            get_string('ruleuserprofilefieldchangedstatus', 'mod_booking'),
            [
                self::STATUS_BOTH => get_string('ruleuserprofilefieldchangedstatusboth', 'mod_booking'),
                self::STATUS_BOOKED => get_string('ruleuserprofilefieldchangedstatusbooked', 'mod_booking'),
                self::STATUS_WAITINGLIST => get_string('ruleuserprofilefieldchangedstatuswaitinglist', 'mod_booking'),
            ]
        );

        $mform->addElement(
            'select',
            self::FORMPRICEOPERATOR,
            get_string('ruleuserprofilefieldchangedprice', 'mod_booking'),
            [
                self::PRICE_ANY => get_string('ruleuserprofilefieldchangedpriceany', 'mod_booking'),
                self::PRICE_GREATER => get_string('ruleuserprofilefieldchangedpricegreater', 'mod_booking'),
                self::PRICE_EQUALS => get_string('ruleuserprofilefieldchangedpriceequals', 'mod_booking'),
                self::PRICE_LESS => get_string('ruleuserprofilefieldchangedpriceless', 'mod_booking'),
            ]
        );
        $mform->addHelpButton(self::FORMPRICEOPERATOR, 'ruleuserprofilefieldchangedprice', 'mod_booking');

        $mform->addElement('text', self::FORMPRICEVALUE, get_string('ruleuserprofilefieldchangedpriceamount', 'mod_booking'));
        $mform->setType(self::FORMPRICEVALUE, PARAM_RAW_TRIMMED);
        $mform->setDefault(self::FORMPRICEVALUE, '0');
        $mform->hideIf(self::FORMPRICEVALUE, self::FORMPRICEOPERATOR, 'eq', self::PRICE_ANY);

        $elements = [
            self::FORMFIELD,
            self::FORMOPERATOR,
            self::FORMVALUE,
            self::FORMSTATUS,
            self::FORMPRICEOPERATOR,
            self::FORMPRICEVALUE,
        ];
        foreach ($elements as $element) {
            $mform->hideIf($element, $eventelement, 'neq', self::EVENTNAME);
        }
    }

    /**
     * Validation of the form elements, to be called only when the event is selected.
     *
     * @param array $data
     * @param array $errors
     * @return void
     */
    public static function validation(array $data, array &$errors) {

        if (empty($data[self::FORMFIELD])) {
            $errors[self::FORMFIELD] = get_string('error:choosevalue', 'mod_booking');
        }

        if (trim((string) ($data[self::FORMVALUE] ?? '')) === '') {
            $errors[self::FORMVALUE] = get_string('error:entervalue', 'mod_booking');
        }

        if (
            (string) ($data[self::FORMPRICEOPERATOR] ?? self::PRICE_ANY) !== self::PRICE_ANY
            && !is_numeric(self::normalize_amount($data[self::FORMPRICEVALUE] ?? ''))
        ) {
            $errors[self::FORMPRICEVALUE] = get_string('error:entervalue', 'mod_booking');
        }
    }

    /**
     * Amounts can be entered with a decimal comma.
     *
     * @param mixed $amount
     * @return string
     */
    private static function normalize_amount($amount): string {
        return str_replace(',', '.', trim((string) $amount));
    }

    /**
     * Store the criteria within the ruledata of the rulejson.
     * For all the other events, no key is added at all.
     *
     * @param stdClass $data the form data
     * @param stdClass $jsonobject the rulejson object, ruledata has to be set already
     * @return void
     */
    public static function save(stdClass $data, stdClass $jsonobject) {

        if (($data->rule_react_on_event_event ?? '') !== self::EVENTNAME) {
            unset($jsonobject->ruledata->{self::JSONKEY});
            return;
        }

        $priceoperator = (string) ($data->{self::FORMPRICEOPERATOR} ?? self::PRICE_ANY);

        $criteria = new stdClass();
        $criteria->cpfield = (string) ($data->{self::FORMFIELD} ?? '');
        $criteria->operator = (string) ($data->{self::FORMOPERATOR} ?? '=');
        $criteria->value = trim((string) ($data->{self::FORMVALUE} ?? ''));
        $criteria->bookingstatus = (string) ($data->{self::FORMSTATUS} ?? self::STATUS_BOTH);
        $criteria->priceoperator = $priceoperator;
        $criteria->pricevalue = $priceoperator === self::PRICE_ANY
            ? 0
            : (float) self::normalize_amount($data->{self::FORMPRICEVALUE} ?? 0);

        $jsonobject->ruledata->{self::JSONKEY} = $criteria;

        // From now on we have to recognize changes of this field, so we need to know the current values.
        self::prepare_baseline($criteria->cpfield);
    }

    /**
     * Set the form defaults of the criteria when an existing rule is loaded.
     *
     * @param stdClass $data reference to the default values
     * @param stdClass $ruledata the ruledata of the rulejson
     * @return void
     */
    public static function set_defaults(stdClass &$data, stdClass $ruledata) {

        $criteria = self::get_criteria($ruledata);

        if (empty($criteria)) {
            return;
        }

        $data->{self::FORMFIELD} = $criteria->cpfield;
        $data->{self::FORMOPERATOR} = $criteria->operator;
        $data->{self::FORMVALUE} = $criteria->value;
        $data->{self::FORMSTATUS} = $criteria->bookingstatus;
        $data->{self::FORMPRICEOPERATOR} = $criteria->priceoperator;
        $data->{self::FORMPRICEVALUE} = $criteria->pricevalue;
    }

    /**
     * The operators to compare the new value of the profile field with the value of the rule.
     *
     * @return array operator => localized name
     */
    public static function get_operators(): array {
        return [
            '=' => get_string('equals', 'mod_booking'),
            '!=' => get_string('equalsnot', 'mod_booking'),
            '<' => get_string('ruleuserprofilefieldchangedoperatorless', 'mod_booking'),
            '>' => get_string('ruleuserprofilefieldchangedoperatorgreater', 'mod_booking'),
            '~' => get_string('contains', 'mod_booking'),
            '!~' => get_string('containsnot', 'mod_booking'),
        ];
    }

    /**
     * Compare two values: as numbers when both are numbers (decimal comma allowed), as text otherwise.
     *
     * @param string $a
     * @param string $b
     * @return int negative when $a is less than $b, 0 when they are equal, positive when $a is greater than $b
     */
    private static function compare_values(string $a, string $b): int {

        $numbera = self::normalize_amount($a);
        $numberb = self::normalize_amount($b);

        if (is_numeric($numbera) && is_numeric($numberb)) {
            return (float) $numbera <=> (float) $numberb;
        }

        return strcmp($a, $b) <=> 0;
    }

    /**
     * Does a value of the profile field match the value of the criteria?
     *
     * @param stdClass $criteria
     * @param string $fieldvalue
     * @return bool
     */
    public static function value_matches(stdClass $criteria, string $fieldvalue): bool {

        $fieldvalue = trim($fieldvalue);
        $value = trim($criteria->value);

        if ($value === '') {
            return false;
        }

        switch ($criteria->operator) {
            case '!=':
                return self::compare_values($fieldvalue, $value) !== 0;
            case '<':
                // A user without a value is neither less nor greater than anything.
                return $fieldvalue !== '' && self::compare_values($fieldvalue, $value) < 0;
            case '>':
                return $fieldvalue !== '' && self::compare_values($fieldvalue, $value) > 0;
            case '~':
                return strpos($fieldvalue, $value) !== false;
            case '!~':
                return strpos($fieldvalue, $value) === false;
            case '=':
            default:
                return self::compare_values($fieldvalue, $value) === 0;
        }
    }

    /**
     * The statuses of booking answers the criteria apply to.
     *
     * @param ?stdClass $criteria null for all the statuses supported by the event
     * @return array
     */
    private static function get_statuses(?stdClass $criteria = null): array {
        switch ($criteria->bookingstatus ?? self::STATUS_BOTH) {
            case self::STATUS_BOOKED:
                return [MOD_BOOKING_STATUSPARAM_BOOKED];
            case self::STATUS_WAITINGLIST:
                return [MOD_BOOKING_STATUSPARAM_WAITINGLIST];
            default:
                return [MOD_BOOKING_STATUSPARAM_BOOKED, MOD_BOOKING_STATUSPARAM_WAITINGLIST];
        }
    }

    /**
     * Check if a rule reacting on the event applies to the change described by the event:
     * The profile field of the rule changed to the value of the rule, the user is (still) booked
     * or on the waiting list as chosen in the rule, and the price the user has to pay for the
     * booking option matches the price criterion.
     *
     * @param ?object $ruledata the ruledata of the rulejson
     * @param ?object $datafromevent the data of the event, as it is stored in the rulejson
     * @param int $optionid
     * @return float|null the price for the user (0 if there is none), null if the rule does not apply
     */
    public static function matches(?object $ruledata, ?object $datafromevent, int $optionid): ?float {
        global $DB;

        $criteria = self::get_criteria($ruledata);
        $userid = (int) ($datafromevent->relateduserid ?? 0);
        $other = (array) ($datafromevent->other ?? []);

        if (
            empty($criteria)
            || empty($userid)
            || empty($optionid)
            || ($other['field'] ?? '') !== $criteria->cpfield
            || !self::value_matches($criteria, (string) ($other['newvalue'] ?? ''))
            || self::value_matches($criteria, (string) ($other['oldvalue'] ?? ''))
        ) {
            return null;
        }

        [$insql, $params] = $DB->get_in_or_equal(self::get_statuses($criteria), SQL_PARAMS_NAMED);
        $params['optionid'] = $optionid;
        $params['userid'] = $userid;
        $select = "optionid = :optionid AND userid = :userid AND waitinglist $insql";

        if (!$DB->record_exists_select('booking_answers', $select, $params)) {
            return null;
        }

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        $price = null;
        if (!empty($settings->useprice)) {
            // The price category can depend on the profile field which just changed. So we need a fresh user
            // and we must not use the price category which was determined earlier in this request.
            $user = core_user::get_user($userid);
            if (empty($user)) {
                return null;
            }
            unset(singleton_service::get_instance()->userpricecategory[$userid]);
            $pricearray = price::get_price('option', $optionid, $user);
            unset(singleton_service::get_instance()->userpricecategory[$userid]);
            if (isset($pricearray['price'])) {
                $price = (float) $pricearray['price'];
            }
        }

        switch ($criteria->priceoperator) {
            case self::PRICE_GREATER:
                $matches = $price !== null && $price > $criteria->pricevalue;
                break;
            case self::PRICE_EQUALS:
                $matches = $price !== null && abs($price - $criteria->pricevalue) < 0.005;
                break;
            case self::PRICE_LESS:
                $matches = $price !== null && $price < $criteria->pricevalue;
                break;
            default:
                $matches = true;
                break;
        }

        return $matches ? ($price ?? 0.0) : null;
    }

    /**
     * Returns the criteria of all the rules reacting on the event, grouped by the profile field they watch.
     *
     * @return array shortname of the profile field => array of criteria (with isactive and contextid)
     */
    private static function get_criteria_by_field(): array {
        global $DB;

        $records = $DB->get_records(
            'booking_rules',
            ['rulename' => 'rule_react_on_event', 'eventname' => self::EVENTNAME],
            'id ASC'
        );

        $criteriabyfield = [];
        foreach ($records as $record) {
            $ruleobject = json_decode($record->rulejson);
            $criteria = self::get_criteria($ruleobject->ruledata ?? null);
            if (empty($criteria)) {
                continue;
            }
            $criteria->isactive = !empty($record->isactive);
            $criteria->contextid = (int) $record->contextid;
            $criteriabyfield[$criteria->cpfield][] = $criteria;
        }

        return $criteriabyfield;
    }

    /**
     * A rule which watches a custom user profile field is saved: make sure the values of all users get remembered.
     *
     * Moodle does not tell us the old value when a profile field changes. So from the moment on a rule
     * watches a field, we keep the last known value of every user in a user preference.
     *
     * When another rule (or this rule, saved before) watches the field already, the values are up to
     * date and there is nothing to do. Otherwise the values stored earlier are outdated, as no rule
     * watched the field in the meantime: they are removed right away, so they can't be mistaken for the
     * value before a change. The current values of all users are stored by an adhoc task, as there can
     * be a lot of them. A user who is updated before the task has run is treated like an unknown user.
     *
     * @param string $shortname shortname of the custom user profile field
     * @return void
     */
    private static function prepare_baseline(string $shortname) {
        global $DB;

        if ($shortname === '' || isset(self::get_criteria_by_field()[$shortname])) {
            return;
        }

        $DB->delete_records('user_preferences', ['name' => self::PREFERENCEPREFIX . $shortname]);

        // The rule is not stored yet, so the task has to know the field.
        $task = new store_userprofilefield_baseline_adhoc();
        $task->set_custom_data(['shortname' => $shortname]);
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * A user was created: make sure the values she starts with get remembered.
     *
     * One task stores the values of all the users we do not know yet (see store_missing_baselines),
     * no matter how many users are created. It waits a moment, and a task which has not started yet
     * serves the users created until then: so nothing is queued when such a task exists already.
     * Nothing is queued on sites without a rule watching a profile field.
     *
     * @return void
     */
    public static function queue_missing_baselines() {
        global $DB;

        if (empty(self::get_criteria_by_field())) {
            return;
        }

        $task = new store_userprofilefield_baseline_adhoc();
        $now = time();

        // This uses the index on nextruntime and classname. A task which is running already has a nextruntime in the past.
        $params = ['now' => $now, 'classname' => \core\task\manager::get_canonical_class_name($task)];
        if ($DB->record_exists_select('task_adhoc', 'nextruntime > :now AND classname = :classname', $params)) {
            return;
        }

        $task->set_next_run_time($now + self::BASELINEDELAY);
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * Remember the current values of the watched custom user profile fields for all the users we do not
     * know yet: new users, or all users when a rule starts to watch a field. One query per field does it,
     * values remembered already are left alone.
     *
     * The preferences are only used by this class, and they are read and written directly in the
     * database: there are far too many to go through the preferences API user by user.
     *
     * @param array $shortnames fields to include even if no rule watches them (yet)
     * @return void
     */
    public static function store_missing_baselines(array $shortnames = []) {
        global $DB;

        $shortnames = array_unique(array_merge(array_map('strval', array_keys(self::get_criteria_by_field())), $shortnames));

        foreach ($shortnames as $shortname) {
            if ($shortname === '') {
                continue;
            }

            $fieldid = (int) $DB->get_field('user_info_field', 'id', ['shortname' => $shortname]);
            $name = self::PREFERENCEPREFIX . $shortname;

            // A user preference can't store more than 1333 characters.
            $value = "COALESCE(" . $DB->sql_substr('uid.data', 1, 1333) . ", '')";
            $sql = "INSERT INTO {user_preferences} (userid, name, value)
                    SELECT u.id, :name, $value
                      FROM {user} u
                 LEFT JOIN {user_info_data} uid ON uid.userid = u.id AND uid.fieldid = :fieldid
                     WHERE u.deleted = 0
                       AND NOT EXISTS (
                               SELECT 1
                                 FROM {user_preferences} up
                                WHERE up.userid = u.id AND up.name = :name2
                           )";
            $DB->execute($sql, ['name' => $name, 'fieldid' => $fieldid, 'name2' => $name]);
        }
    }

    /**
     * Returns the current value of the profile field of a user and the value we remembered.
     *
     * @param int $userid
     * @param string $shortname shortname of the custom user profile field
     * @return stdClass with newvalue, oldvalue (null when we never saw this user) and preferenceid
     */
    private static function get_state(int $userid, string $shortname): stdClass {
        global $DB;

        $sql = "SELECT u.id, uid.data, up.id AS preferenceid, up.value
                  FROM {user} u
             LEFT JOIN {user_info_field} uif ON uif.shortname = :shortname
             LEFT JOIN {user_info_data} uid ON uid.userid = u.id AND uid.fieldid = uif.id
             LEFT JOIN {user_preferences} up ON up.userid = u.id AND up.name = :name
                 WHERE u.id = :userid";
        $params = ['shortname' => $shortname, 'name' => self::PREFERENCEPREFIX . $shortname, 'userid' => $userid];
        $records = $DB->get_records_sql($sql, $params, 0, 1);
        $record = reset($records);

        $state = new stdClass();
        // A user preference can't store more than 1333 characters.
        $state->newvalue = core_text::substr((string) ($record->data ?? ''), 0, 1333);
        $state->preferenceid = (int) ($record->preferenceid ?? 0);
        $state->oldvalue = empty($state->preferenceid) ? null : (string) $record->value;

        return $state;
    }

    /**
     * Remember the value of the profile field of a user.
     *
     * @param int $userid
     * @param string $shortname shortname of the custom user profile field
     * @param stdClass $state the state returned by get_state
     * @return void
     */
    private static function store_state(int $userid, string $shortname, stdClass $state) {
        global $DB;

        if (!empty($state->preferenceid)) {
            $DB->set_field('user_preferences', 'value', $state->newvalue, ['id' => $state->preferenceid]);
            return;
        }

        $DB->insert_record('user_preferences', (object) [
            'userid' => $userid,
            'name' => self::PREFERENCEPREFIX . $shortname,
            'value' => $state->newvalue,
        ]);
    }

    /**
     * After a user has been updated, we check if a custom user profile field changed to a value
     * a rule is waiting for. This is all that happens while the user is updated: the changes found are
     * handed over to an adhoc task, which triggers the events (see trigger_events). So updating a lot
     * of users at once (upload, web service, synchronisation) is not slowed down by the reactions.
     *
     * @param int $userid the user who was updated
     * @return void
     */
    public static function queue_events_for_user(int $userid) {

        $changes = self::get_changes_for_user($userid);

        if (empty($changes)) {
            return;
        }

        // No check for an existing task: the data differs for every change, and the check is expensive with a lot of tasks.
        $task = new trigger_userprofilefield_events_adhoc();
        $task->set_custom_data(['userid' => $userid, 'changes' => $changes]);
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * Find the changes and trigger the events right away. See queue_events_for_user for the way via the adhoc task.
     *
     * @param int $userid the user who was updated
     * @return void
     */
    public static function trigger_events_for_user(int $userid) {
        self::trigger_events($userid, self::get_changes_for_user($userid));
    }

    /**
     * Returns the watched custom user profile fields of a user which changed to a value a rule is
     * waiting for, and remembers the new values.
     *
     * The value before the change is the one we remembered (see prepare_baseline). Updates which do not
     * change the field (e.g. imports or synchronisations of unchanged users) end after one query per field.
     *
     * @param int $userid the user who was updated
     * @return array of changes, each one an array with field, oldvalue and newvalue
     */
    private static function get_changes_for_user(int $userid): array {

        if (empty($userid)) {
            return [];
        }

        $changes = [];

        foreach (self::get_criteria_by_field() as $shortname => $criterialist) {
            $shortname = (string) $shortname;
            $state = self::get_state($userid, $shortname);

            if ($state->oldvalue === $state->newvalue) {
                // The field did not change.
                continue;
            }
            self::store_state($userid, $shortname, $state);

            if ($state->oldvalue === null) {
                // We do not know the value before, so we can't tell if the field changed. Now we know it for the next time.
                continue;
            }

            if (empty(self::get_waiting_criteria($criterialist, $state->oldvalue, $state->newvalue))) {
                continue;
            }

            $changes[] = ['field' => $shortname, 'oldvalue' => $state->oldvalue, 'newvalue' => $state->newvalue];
        }

        return $changes;
    }

    /**
     * Returns the criteria of the active rules for which the field changed TO their value.
     *
     * @param array $criterialist criteria of the rules watching the field
     * @param string $oldvalue
     * @param string $newvalue
     * @return array
     */
    private static function get_waiting_criteria(array $criterialist, string $oldvalue, string $newvalue): array {
        return array_filter(
            $criterialist,
            fn($criteria) => $criteria->isactive
                && self::value_matches($criteria, $newvalue)
                && !self::value_matches($criteria, $oldvalue)
        );
    }

    /**
     * Trigger the event for every booking option in which the user is booked or on the waiting list
     * and for which a rule is waiting for one of the changes. The rules react on the event like on any
     * other event.
     *
     * @param int $userid the user whose profile fields changed
     * @param array $changes the changes found by get_changes_for_user (arrays or objects)
     * @return void
     */
    public static function trigger_events(int $userid, array $changes) {

        if (empty($userid) || empty($changes)) {
            return;
        }

        $criteriabyfield = self::get_criteria_by_field();
        $answers = null;

        foreach ($changes as $change) {
            $change = (array) $change;
            $shortname = (string) ($change['field'] ?? '');
            $oldvalue = (string) ($change['oldvalue'] ?? '');
            $newvalue = (string) ($change['newvalue'] ?? '');

            // The rules can have changed since the change was found.
            $waiting = self::get_waiting_criteria($criteriabyfield[$shortname] ?? [], $oldvalue, $newvalue);
            if (empty($waiting)) {
                continue;
            }

            $answers = $answers ?? self::get_answers_of_user($userid);

            foreach ($answers as $answer) {
                // Is there a rule for this booking option?
                $triggered = false;
                foreach ($waiting as $criteria) {
                    if (self::context_applies($criteria->contextid, $answer->path)) {
                        $triggered = true;
                        break;
                    }
                }
                if (!$triggered) {
                    continue;
                }

                $event = userprofilefield_changed::create([
                    'objectid' => (int) $answer->optionid,
                    'context' => context_module::instance($answer->cmid),
                    'relateduserid' => $userid,
                    'other' => [
                        'field' => $shortname,
                        'oldvalue' => core_text::substr($oldvalue, 0, 255),
                        'newvalue' => core_text::substr($newvalue, 0, 255),
                        'status' => (int) $answer->waitinglist,
                    ],
                ]);
                $event->trigger();
            }
        }
    }

    /**
     * Is the booking option within the context of the rule?
     *
     * @param int $rulecontextid
     * @param string $optioncontextpath the path of the module context of the booking option
     * @return bool
     */
    private static function context_applies(int $rulecontextid, string $optioncontextpath): bool {
        try {
            $path = context::instance_by_id($rulecontextid)->path;
        } catch (\Throwable $e) {
            return false;
        }
        return $optioncontextpath === $path || strpos($optioncontextpath, $path . '/') === 0;
    }

    /**
     * Returns the booking options (one record per option) in which the user is booked or on the waiting list.
     *
     * @param int $userid
     * @return array records with optionid, cmid, waitinglist and path of the module context
     */
    private static function get_answers_of_user(int $userid): array {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal(self::get_statuses(), SQL_PARAMS_NAMED);
        $params['userid'] = $userid;
        $params['contextlevel'] = CONTEXT_MODULE;

        $sql = "SELECT ba.id, ba.optionid, ba.waitinglist, cm.id AS cmid, c.path
                  FROM {booking_answers} ba
                  JOIN {booking_options} bo ON bo.id = ba.optionid
                  JOIN {course_modules} cm ON cm.instance = bo.bookingid
                  JOIN {modules} m ON m.name = 'booking' AND m.id = cm.module
                  JOIN {context} c ON c.instanceid = cm.id AND c.contextlevel = :contextlevel
                 WHERE ba.userid = :userid
                   AND ba.waitinglist $insql
                   AND bo.status < 1
              ORDER BY ba.optionid ASC, ba.waitinglist ASC, ba.id DESC";

        $answers = [];
        foreach ($DB->get_records_sql($sql, $params) as $record) {
            // A user can have more than one answer for the same booking option.
            if (!isset($answers[$record->optionid])) {
                $answers[$record->optionid] = $record;
            }
        }

        return $answers;
    }
}
