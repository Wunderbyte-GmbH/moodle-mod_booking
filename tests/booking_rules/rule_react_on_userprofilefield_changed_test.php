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

namespace mod_booking\booking_rules;

use mod_booking\booking_rules\local\userprofilefield_change;
use mod_booking\event\userprofilefield_changed;
use mod_booking\singleton_service;
use mod_booking\tests\booking_advanced_testcase;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once(__DIR__ . '/../classes/booking_advanced_testcase.php');
require_once($CFG->dirroot . '/user/profile/lib.php');

/**
 * The rule "React on event" reacts on the change of a custom user profile field of a user,
 * who is booked or on the waiting list of booking options.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \mod_booking\booking_rules\local\userprofilefield_change
 * @covers \mod_booking\event\userprofilefield_changed
 * @covers \mod_booking\booking_rules\rules\rule_react_on_event
 */
final class rule_react_on_userprofilefield_changed_test extends booking_advanced_testcase {
    /** @var string Class of the adhoc task which sends the mails of rules. */
    private const MAILTASK = '\mod_booking\task\send_mail_by_rule_adhoc';

    /** @var string Class of the adhoc task which remembers the profile field values of the users not known yet. */
    private const BASELINETASK = '\mod_booking\task\store_userprofilefield_baseline_adhoc';

    /** @var string Class of the adhoc task which triggers the events for the changes of a user. */
    private const TRIGGERTASK = '\mod_booking\task\trigger_userprofilefield_events_adhoc';

    /** @var stdClass the booking instance */
    private stdClass $booking;

    /** @var stdClass the course */
    private stdClass $course;

    /** @var stdClass the user who is informed about the changes */
    private stdClass $office;

    /**
     * Profile field "affiliation" is the price category field: students pay 50, externals 80, all others 20.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->setAdminUser();

        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'affiliation',
            'name' => 'Affiliation',
        ]);
        set_config('pricecategoryfield', 'affiliation', 'booking');

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        foreach (['default' => 20, 'student' => 50, 'extern' => 80] as $identifier => $price) {
            $plugingenerator->create_pricecategory((object) [
                'ordernum' => 1,
                'name' => $identifier,
                'identifier' => $identifier,
                'defaultvalue' => $price,
                'pricecatsortorder' => 1,
            ]);
        }

        $this->course = $this->getDataGenerator()->create_course();
        $this->booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $this->course->id,
            'bookedtext' => ['text' => 'text'],
            'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'],
            'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'],
            'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'],
            'notificationtext' => ['text' => 'text'],
            'userleave' => ['text' => 'text'],
        ]);

        $this->office = $this->getDataGenerator()->create_user(['firstname' => 'Olivia', 'lastname' => 'Office']);
    }

    /**
     * Create a booking option.
     *
     * @param string $title
     * @param bool $useprice
     * @param ?stdClass $booking the booking instance, by default the one created in setUp
     * @return int the id of the booking option
     */
    private function create_option(string $title, bool $useprice = true, ?stdClass $booking = null): int {
        $booking = $booking ?? $this->booking;
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = $title;
        $record->courseid = $booking->course;
        $record->useprice = $useprice ? 1 : 0;
        $record->maxanswers = 10;
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $option = $this->getDataGenerator()->get_plugin_generator('mod_booking')->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        return (int) $option->id;
    }

    /**
     * Create another booking instance, in the course created in setUp or in a new course.
     *
     * @param bool $newcourse
     * @return stdClass the booking instance
     */
    private function create_booking_instance(bool $newcourse = false): stdClass {
        $course = $newcourse ? $this->getDataGenerator()->create_course() : $this->course;

        return $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'bookedtext' => ['text' => 'text'],
            'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'],
            'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'],
            'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'],
            'notificationtext' => ['text' => 'text'],
            'userleave' => ['text' => 'text'],
        ]);
    }

    /**
     * Returns the id of the module context of a booking instance, which is the context of the rules of this instance.
     *
     * @param stdClass $booking
     * @return int
     */
    private function get_instance_contextid(stdClass $booking): int {
        return (int) \context_module::instance($booking->cmid)->id;
    }

    /**
     * Change the profile field of a user without telling the rules, and return the booking options
     * for which the event is triggered when the rules are told afterwards.
     *
     * @param stdClass $user
     * @param string $affiliation
     * @return array ids of the booking options, sorted
     */
    private function get_options_of_triggered_events(stdClass $user, string $affiliation): array {
        $sink = $this->redirectEvents();
        profile_save_data((object) ['id' => $user->id, 'profile_field_affiliation' => $affiliation]);
        userprofilefield_change::trigger_events_for_user((int) $user->id);
        $optionids = [];
        foreach ($sink->get_events() as $event) {
            if ($event instanceof userprofilefield_changed) {
                $this->assertSame((int) $user->id, (int) $event->relateduserid);
                $optionids[] = (int) $event->objectid;
            }
        }
        $sink->close();
        sort($optionids);

        return $optionids;
    }

    /**
     * Create a user with a value in the profile field, whose value is known already to the booking rules.
     *
     * @param string $affiliation
     * @param string $firstname
     * @param string $lastname
     * @return stdClass
     */
    private function create_student(
        string $affiliation = 'student',
        string $firstname = 'Stefanie',
        string $lastname = 'Student'
    ): stdClass {
        $user = $this->getDataGenerator()->create_user(['firstname' => $firstname, 'lastname' => $lastname]);
        $this->getDataGenerator()->enrol_user($user->id, $this->course->id, 'student');
        $this->change_affiliation($user, $affiliation);

        return $user;
    }

    /**
     * Book a user into a booking option and set the status of the answer.
     *
     * @param int $optionid
     * @param stdClass $user
     * @param int $status
     * @return void
     */
    private function book(int $optionid, stdClass $user, int $status = MOD_BOOKING_STATUSPARAM_BOOKED): void {
        global $DB;

        $this->getDataGenerator()->get_plugin_generator('mod_booking')->create_answer([
            'optionid' => $optionid,
            'userid' => $user->id,
        ]);
        $params = ['optionid' => $optionid, 'userid' => $user->id];
        $this->assertTrue($DB->record_exists('booking_answers', $params));
        $DB->set_field('booking_answers', 'waitinglist', $status, $params);
        // Booking sends its own mails. We only want to see those of our rule.
        $DB->delete_records('task_adhoc');
    }

    /**
     * Change the profile field of a user, like the profile form or the user synchronisation does.
     *
     * @param stdClass $user
     * @param string $affiliation
     * @return void
     */
    private function change_affiliation(stdClass $user, string $affiliation): void {
        $this->change_profilefield($user, 'affiliation', $affiliation);
    }

    /**
     * Change any custom profile field of a user, like the profile form or the user synchronisation does,
     * and let cron run the task which triggers the events for the change.
     *
     * @param stdClass $user
     * @param string $shortname
     * @param string $value
     * @return void
     */
    private function change_profilefield(stdClass $user, string $shortname, string $value): void {
        profile_save_data((object) ['id' => $user->id, 'profile_field_' . $shortname => $value]);
        \core\event\user_updated::create_from_userid($user->id)->trigger();
        $this->runAdhocTasks(self::TRIGGERTASK);
    }

    /**
     * Create a rule which reacts on the change of the profile field "affiliation".
     *
     * @param array $criteria overrides the default criteria (changes to "extern", price > 0)
     * @param string $conditionname
     * @param string $conditiondata
     * @param int $contextid the context of the rule: 1 for a rule of the whole site, the module context for a rule of an instance
     * @return stdClass the record of the rule
     */
    private function create_rule(
        array $criteria = [],
        string $conditionname = 'select_users',
        string $conditiondata = '',
        int $contextid = 1
    ): stdClass {
        $criteria = array_merge([
            'cpfield' => 'affiliation',
            'operator' => '=',
            'value' => 'extern',
            'bookingstatus' => userprofilefield_change::STATUS_BOTH,
            'priceoperator' => userprofilefield_change::PRICE_GREATER,
            'pricevalue' => 0,
        ], $criteria);

        if ($conditiondata === '') {
            $conditiondata = json_encode(['userids' => [(string) $this->office->id]]);
        }

        return $this->getDataGenerator()->get_plugin_generator('mod_booking')->create_rule([
            'name' => 'affiliation changed',
            'contextid' => $contextid,
            'conditionname' => $conditionname,
            'conditiondata' => $conditiondata,
            'actionname' => 'send_mail',
            'actiondata' => json_encode([
                'sendical' => 0,
                'sendicalcreateorcancel' => '',
                'subject' => 'Affiliation changed',
                'template' => 'USER:{firstnamerelated} {lastnamerelated}|OPTION:{title}|PRICE:{price}|{eventdescription}',
                'templateformat' => '1',
            ]),
            'rulename' => 'rule_react_on_event',
            'ruledata' => json_encode([
                'boevent' => userprofilefield_change::EVENTNAME,
                'aftercompletion' => '',
                'condition' => '0',
                'cancelrules' => [],
                userprofilefield_change::JSONKEY => $criteria,
            ]),
        ]);
    }

    /**
     * Returns the queued mails of rules as "optionid-recipientid", sorted.
     *
     * @return array
     */
    private function get_queued_mails(): array {
        $mails = [];
        foreach (\core\task\manager::get_adhoc_tasks(self::MAILTASK) as $task) {
            $data = $task->get_custom_data();
            $mails[] = $data->optionid . '-' . $data->userid;
        }
        sort($mails);
        return $mails;
    }

    /**
     * A user in several booking options changes to the value of the rule: one mail for every option
     * the user has to pay for, with the option and the user affected in the placeholders.
     */
    public function test_one_mail_per_affected_option(): void {
        global $DB;

        $this->create_rule();
        $student = $this->create_student('student');

        $booked1 = $this->create_option('Booked one');
        $booked2 = $this->create_option('Booked two');
        $waiting = $this->create_option('Waiting');
        $free = $this->create_option('Free', false);
        // A booking option the user has nothing to do with.
        $this->create_option('Not booked');
        $this->book($booked1, $student);
        $this->book($booked2, $student);
        $this->book($waiting, $student, MOD_BOOKING_STATUSPARAM_WAITINGLIST);
        $this->book($free, $student);

        // Nothing happens as long as the field does not change.
        \core\event\user_updated::create_from_userid($student->id)->trigger();
        $this->assertSame([], $this->get_queued_mails());

        $this->change_affiliation($student, 'extern');

        $expected = ["$booked1-{$this->office->id}", "$booked2-{$this->office->id}", "$waiting-{$this->office->id}"];
        sort($expected);
        $this->assertSame($expected, $this->get_queued_mails());

        unset_config('noemailever');
        ob_start();
        $messagesink = $this->redirectMessages();
        $this->runAdhocTasks(self::MAILTASK);
        $messages = $messagesink->get_messages();
        ob_get_clean();
        $messagesink->close();

        $texts = [];
        foreach ($messages as $message) {
            if ((int) $message->useridto === (int) $this->office->id) {
                $texts[] = $message->fullmessage . ' ' . $message->fullmessagehtml;
            }
        }
        $this->assertCount(3, $texts);
        foreach (['Booked one', 'Booked two', 'Waiting'] as $title) {
            $found = array_filter($texts, fn($text) => str_contains($text, "OPTION:$title"));
            $this->assertCount(1, $found, "One mail for the option $title");
            $text = reset($found);
            $this->assertStringContainsString('USER:Stefanie Student', $text);
            $this->assertStringContainsString('PRICE:80', $text);
            $this->assertStringContainsString('"student"', $text);
            $this->assertStringContainsString('"extern"', $text);
        }

        // Updating the user again without changing the field does not trigger anything.
        $DB->delete_records('task_adhoc');
        \core\event\user_updated::create_from_userid($student->id)->trigger();
        $this->assertSame([], $this->get_queued_mails());

        // Changing away and back again triggers the rule again.
        $this->change_affiliation($student, 'student');
        $this->assertSame([], $this->get_queued_mails());
        $this->change_affiliation($student, 'extern');
        $this->assertSame($expected, $this->get_queued_mails());
    }

    /**
     * A change to another value than the one of the rule does not trigger anything.
     */
    public function test_other_value_is_ignored(): void {
        $this->create_rule();
        $student = $this->create_student('student');
        $this->book($this->create_option('Booked'), $student);

        $sink = $this->redirectEvents();
        profile_save_data((object) ['id' => $student->id, 'profile_field_affiliation' => 'staff']);
        userprofilefield_change::trigger_events_for_user((int) $student->id);
        $this->assertSame([], $sink->get_events());
        $sink->close();

        $this->assertSame([], $this->get_queued_mails());
    }

    /**
     * The operator "contains" matches values like "extern@example.com".
     */
    public function test_operator_contains(): void {
        $this->create_rule(['operator' => '~', 'value' => 'ext']);
        $student = $this->create_student('student');
        $optionid = $this->create_option('Booked');
        $this->book($optionid, $student);

        $this->change_affiliation($student, 'extern@example.com');

        $this->assertSame(["$optionid-{$this->office->id}"], $this->get_queued_mails());
    }

    /**
     * The operators compare numbers as numbers and everything else as text.
     *
     * @dataProvider value_matches_provider
     * @param string $operator
     * @param string $value the value of the rule
     * @param string $fieldvalue the value of the profile field
     * @param bool $expected
     */
    public function test_value_matches(string $operator, string $value, string $fieldvalue, bool $expected): void {
        $criteria = (object) ['operator' => $operator, 'value' => $value];
        $this->assertSame($expected, userprofilefield_change::value_matches($criteria, $fieldvalue));
    }

    /**
     * Provider: operator, value of the rule, value of the profile field, expected match.
     *
     * @return array
     */
    public static function value_matches_provider(): array {
        return [
            'equals text' => ['=', 'extern', 'extern', true],
            'equals other text' => ['=', 'extern', 'student', false],
            'equals is case sensitive' => ['=', 'extern', 'Extern', false],
            'equals ignores spaces around' => ['=', 'extern', ' extern ', true],
            'equals number' => ['=', '5', '5', true],
            'equals number in other notation' => ['=', '5', '5.0', true],
            'equals number with decimal comma' => ['=', '1.5', '1,5', true],
            'equals other number' => ['=', '5', '6', false],
            'equals empty field' => ['=', 'extern', '', false],
            'not equals text' => ['!=', 'extern', 'student', true],
            'not equals same text' => ['!=', 'extern', 'extern', false],
            'not equals number' => ['!=', '5', '6', true],
            'not equals same number in other notation' => ['!=', '5', '5.00', false],
            'not equals empty field' => ['!=', 'extern', '', true],
            'less than number' => ['<', '10', '9', true],
            'less than number is not text comparison' => ['<', '10', '9.5', true],
            'less than equal number' => ['<', '10', '10', false],
            'less than greater number' => ['<', '10', '11', false],
            'less than negative number' => ['<', '0', '-1', true],
            'less than text' => ['<', 'm', 'extern', true],
            'less than greater text' => ['<', 'm', 'student', false],
            'less than empty field' => ['<', '10', '', false],
            'greater than number' => ['>', '9', '10', true],
            'greater than number with decimal comma' => ['>', '9', '9,5', true],
            'greater than equal number' => ['>', '9', '9', false],
            'greater than smaller number' => ['>', '9', '8', false],
            'greater than text' => ['>', 'm', 'student', true],
            'greater than smaller text' => ['>', 'm', 'extern', false],
            'greater than number and text are compared as text' => ['>', '9', 'extern', true],
            'greater than empty field' => ['>', '9', '', false],
            'contains' => ['~', 'ext', 'extern@example.com', true],
            'contains not found' => ['~', 'ext', 'student', false],
            'does not contain' => ['!~', 'ext', 'student', true],
            'does not contain but found' => ['!~', 'ext', 'extern', false],
            'does not contain empty field' => ['!~', 'ext', '', true],
            'a rule without value never matches' => ['!=', '', 'extern', false],
        ];
    }

    /**
     * The rule only reacts when the field changes INTO the range of the operator, not within or out of it.
     *
     * @dataProvider operator_transition_provider
     * @param string $operator
     * @param string $value the value of the rule
     * @param string $before the value of the profile field before the change
     * @param string $after the value of the profile field after the change
     * @param bool $expected whether the rule reacts
     */
    public function test_operator_transitions(
        string $operator,
        string $value,
        string $before,
        string $after,
        bool $expected
    ): void {
        $this->create_rule([
            'operator' => $operator,
            'value' => $value,
            'priceoperator' => userprofilefield_change::PRICE_ANY,
        ]);
        $student = $this->create_student($before);
        $optionid = $this->create_option('Booked');
        $this->book($optionid, $student);

        $this->change_affiliation($student, $after);

        $this->assertSame($expected ? ["$optionid-{$this->office->id}"] : [], $this->get_queued_mails());
    }

    /**
     * Provider: operator, value of the rule, field before, field after, expected reaction.
     *
     * @return array
     */
    public static function operator_transition_provider(): array {
        return [
            'not equals: text changes away from the value' => ['!=', 'student', 'student', 'extern', true],
            'not equals: text changes between other values' => ['!=', 'student', 'extern', 'staff', false],
            'not equals: text changes to the value' => ['!=', 'student', 'extern', 'student', false],
            'not equals: number changes away from the value' => ['!=', '5', '5', '6', true],
            'not equals: same number in other notation' => ['!=', '5', '5', '5.0', false],
            'greater: number rises above the value' => ['>', '3', '2', '5', true],
            'greater: number rises above the value, no text comparison' => ['>', '9', '8', '10', true],
            'greater: number was above already' => ['>', '3', '4', '5', false],
            'greater: number stays below' => ['>', '3', '1', '2', false],
            'greater: text' => ['>', 'm', 'extern', 'student', true],
            'less: number falls below the value' => ['<', '3', '5', '2', true],
            'less: number was below already' => ['<', '3', '2', '1', false],
            'less: number falls but stays above' => ['<', '3', '9', '5', false],
            'less: text' => ['<', 'm', 'student', 'extern', true],
            'equals: number in other notation' => ['=', '5', '4', '5.0', true],
            'does not contain: text loses the value' => ['!~', 'ext', 'extern', 'student', true],
            'does not contain: text never had the value' => ['!~', 'ext', 'staff', 'student', false],
        ];
    }

    /**
     * Only users who are booked or on the waiting list are concerned, and the rule can restrict it further.
     */
    public function test_booking_status(): void {
        $this->create_rule(['bookingstatus' => userprofilefield_change::STATUS_BOOKED]);
        $student = $this->create_student('student');

        $booked = $this->create_option('Booked');
        $this->book($booked, $student);
        $this->book($this->create_option('Waiting'), $student, MOD_BOOKING_STATUSPARAM_WAITINGLIST);
        $this->book($this->create_option('Reserved'), $student, MOD_BOOKING_STATUSPARAM_RESERVED);
        $this->book($this->create_option('Deleted'), $student, MOD_BOOKING_STATUSPARAM_DELETED);
        $this->book($this->create_option('Previously'), $student, MOD_BOOKING_STATUSPARAM_PREVIOUSLYBOOKED);

        $this->change_affiliation($student, 'extern');

        $this->assertSame(["$booked-{$this->office->id}"], $this->get_queued_mails());
    }

    /**
     * Reserved, cancelled and previously booked answers never trigger the event.
     */
    public function test_no_event_without_active_booking(): void {
        $this->create_rule(['priceoperator' => userprofilefield_change::PRICE_ANY]);
        $student = $this->create_student('student');
        $this->book($this->create_option('Reserved'), $student, MOD_BOOKING_STATUSPARAM_RESERVED);
        $this->book($this->create_option('Deleted'), $student, MOD_BOOKING_STATUSPARAM_DELETED);
        $this->book($this->create_option('Previously'), $student, MOD_BOOKING_STATUSPARAM_PREVIOUSLYBOOKED);

        $sink = $this->redirectEvents();
        profile_save_data((object) ['id' => $student->id, 'profile_field_affiliation' => 'extern']);
        userprofilefield_change::trigger_events_for_user((int) $student->id);
        $this->assertSame([], $sink->get_events());
        $sink->close();
    }

    /**
     * The price criterion decides about booking options without a price.
     */
    public function test_price_criterion(): void {
        global $DB;

        $rule = $this->create_rule(['priceoperator' => userprofilefield_change::PRICE_ANY]);
        $student = $this->create_student('student');
        $priced = $this->create_option('Priced');
        $free = $this->create_option('Free', false);
        $this->book($priced, $student);
        $this->book($free, $student);

        $this->change_affiliation($student, 'extern');
        $expected = ["$free-{$this->office->id}", "$priced-{$this->office->id}"];
        sort($expected);
        $this->assertSame($expected, $this->get_queued_mails());

        // Externals pay 80. A rule waiting for a price below 80 does not react.
        $DB->delete_records('task_adhoc');
        $DB->delete_records('booking_rules', ['id' => $rule->id]);
        $this->create_rule(['priceoperator' => userprofilefield_change::PRICE_LESS, 'pricevalue' => 80]);
        $this->change_affiliation($student, 'student');
        $this->change_affiliation($student, 'extern');
        $this->assertSame([], $this->get_queued_mails());

        // A rule waiting for exactly 80 does.
        $this->create_rule(['priceoperator' => userprofilefield_change::PRICE_EQUALS, 'pricevalue' => 80]);
        $this->change_affiliation($student, 'student');
        $this->change_affiliation($student, 'extern');
        $this->assertSame(["$priced-{$this->office->id}"], $this->get_queued_mails());
    }

    /**
     * The condition "user from event" sends the mail to the user whose field changed.
     */
    public function test_mail_to_user_affected(): void {
        $this->create_rule([], 'select_user_from_event', '{"userfromeventtype":"relateduserid"}');
        $student = $this->create_student('student');
        $optionid = $this->create_option('Booked');
        $this->book($optionid, $student);

        $this->change_affiliation($student, 'extern');

        $this->assertSame(["$optionid-{$student->id}"], $this->get_queued_mails());
    }

    /**
     * Without a rule waiting for the event, nothing is triggered and nothing is remembered.
     */
    public function test_nothing_happens_without_rule(): void {
        global $DB;

        $student = $this->create_student('student');
        $this->book($this->create_option('Booked'), $student);

        $sink = $this->redirectEvents();
        profile_save_data((object) ['id' => $student->id, 'profile_field_affiliation' => 'extern']);
        userprofilefield_change::trigger_events_for_user((int) $student->id);
        $this->assertSame([], $sink->get_events());
        $sink->close();

        $this->assertFalse($DB->record_exists(
            'user_preferences',
            ['name' => userprofilefield_change::PREFERENCEPREFIX . 'affiliation']
        ));
    }

    /**
     * Returns the remembered value of the profile field of a user, false if there is none.
     *
     * @param int $userid
     * @return string|false
     */
    private function get_remembered_value(int $userid) {
        global $DB;

        return $DB->get_field(
            'user_preferences',
            'value',
            ['userid' => $userid, 'name' => userprofilefield_change::PREFERENCEPREFIX . 'affiliation']
        );
    }

    /**
     * Saving a rule with the event remembers the current value of the field for all users,
     * so an existing site reacts on the very first change, and only on changes.
     */
    public function test_saving_the_rule_stores_baseline_for_all_users(): void {
        global $DB;

        // Users of an existing site: with a value, without a value, and a deleted one.
        $extern = $this->getDataGenerator()->create_user();
        profile_save_data((object) ['id' => $extern->id, 'profile_field_affiliation' => 'extern']);
        $student = $this->getDataGenerator()->create_user();
        profile_save_data((object) ['id' => $student->id, 'profile_field_affiliation' => 'student']);
        $novalue = $this->getDataGenerator()->create_user();
        $DB->delete_records('user_info_data', ['userid' => $novalue->id]);
        $deleted = $this->getDataGenerator()->create_user();
        delete_user($deleted);
        $bookedasextern = $this->create_option('Booked as extern');
        $bookedasstudent = $this->create_option('Booked as student');
        $this->book($bookedasextern, $extern);
        $this->book($bookedasstudent, $student);

        // An outdated value from a time when another rule watched the field.
        $DB->insert_record('user_preferences', (object) [
            'userid' => $student->id,
            'name' => userprofilefield_change::PREFERENCEPREFIX . 'affiliation',
            'value' => 'outdated',
        ]);
        $this->assertFalse($this->get_remembered_value((int) $extern->id));

        // Saving the rule in the form.
        $data = (object) [
            'rule_react_on_event_event' => userprofilefield_change::EVENTNAME,
            userprofilefield_change::FORMFIELD => 'affiliation',
            userprofilefield_change::FORMOPERATOR => '=',
            userprofilefield_change::FORMVALUE => 'extern',
            userprofilefield_change::FORMSTATUS => userprofilefield_change::STATUS_BOTH,
            userprofilefield_change::FORMPRICEOPERATOR => userprofilefield_change::PRICE_GREATER,
            userprofilefield_change::FORMPRICEVALUE => '0',
        ];
        $jsonobject = (object) ['ruledata' => new stdClass()];
        userprofilefield_change::save($data, $jsonobject);
        $this->assertSame('extern', $jsonobject->ruledata->{userprofilefield_change::JSONKEY}->value);
        $this->create_rule();

        // The outdated values are gone right away, the current ones are stored by one adhoc task.
        $this->assertFalse($this->get_remembered_value((int) $student->id));
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(self::BASELINETASK));
        $this->runAdhocTasks(self::BASELINETASK);
        $this->assertSame([], \core\task\manager::get_adhoc_tasks(self::BASELINETASK));

        $this->assertSame('extern', $this->get_remembered_value((int) $extern->id));
        $this->assertSame('student', $this->get_remembered_value((int) $student->id));
        $this->assertSame('', $this->get_remembered_value((int) $novalue->id));
        $this->assertFalse($this->get_remembered_value((int) $deleted->id));
        $this->assertSame('', $this->get_remembered_value((int) get_admin()->id));

        // A user who has the value already is updated (e.g. by an import): nothing changed, nothing happens.
        \core\event\user_updated::create_from_userid($extern->id)->trigger();
        \core\event\user_updated::create_from_userid($student->id)->trigger();
        $this->assertSame([], $this->get_queued_mails());

        // The first real change is recognized.
        $this->change_affiliation($student, 'extern');
        $this->assertSame(["$bookedasstudent-{$this->office->id}"], $this->get_queued_mails());
        $this->assertSame('extern', $this->get_remembered_value((int) $student->id));

        // Saving the rule again, or another rule for the same field, keeps the values: they are up to date.
        $DB->set_field(
            'user_preferences',
            'value',
            'kept',
            ['userid' => $extern->id, 'name' => userprofilefield_change::PREFERENCEPREFIX . 'affiliation']
        );
        userprofilefield_change::save($data, (object) ['ruledata' => new stdClass()]);
        $this->assertSame([], \core\task\manager::get_adhoc_tasks(self::BASELINETASK));
        $this->assertSame('kept', $this->get_remembered_value((int) $extern->id));
    }

    /**
     * Another event in the rule does not store anything.
     */
    public function test_saving_another_event_stores_nothing(): void {
        global $DB;

        $data = (object) [
            'rule_react_on_event_event' => '\mod_booking\event\bookingoption_booked',
            userprofilefield_change::FORMFIELD => 'affiliation',
            userprofilefield_change::FORMVALUE => 'extern',
        ];
        $jsonobject = (object) ['ruledata' => (object) [userprofilefield_change::JSONKEY => new stdClass()]];
        userprofilefield_change::save($data, $jsonobject);

        $this->assertObjectNotHasProperty(userprofilefield_change::JSONKEY, $jsonobject->ruledata);
        $this->assertSame([], \core\task\manager::get_adhoc_tasks(self::BASELINETASK));
        $this->assertFalse($DB->record_exists(
            'user_preferences',
            ['name' => userprofilefield_change::PREFERENCEPREFIX . 'affiliation']
        ));
    }

    /**
     * Users created while a rule watches the field start with the value they were created with.
     * Without a remembered value, we can't tell if the field changed: nothing is triggered.
     */
    public function test_new_and_unknown_users(): void {
        global $DB;

        $this->create_rule();

        $newuser = $this->getDataGenerator()->create_user();
        // The value is remembered by an adhoc task, not while the user is created.
        $this->assertFalse($this->get_remembered_value((int) $newuser->id));
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(self::BASELINETASK));
        $this->runAdhocTasks(self::BASELINETASK);
        $this->assertNotFalse($this->get_remembered_value((int) $newuser->id));
        $this->getDataGenerator()->enrol_user($newuser->id, $this->course->id, 'student');
        $optionid = $this->create_option('Booked');
        $this->book($optionid, $newuser);

        // For some reason, we do not know anything about this user.
        $DB->delete_records('user_preferences', ['userid' => $newuser->id]);
        $this->change_affiliation($newuser, 'extern');
        $this->assertSame([], $this->get_queued_mails());
        $this->assertSame('extern', $this->get_remembered_value((int) $newuser->id));

        // From now on, changes are recognized.
        $this->change_affiliation($newuser, 'student');
        $this->change_affiliation($newuser, 'extern');
        $this->assertSame(["$optionid-{$this->office->id}"], $this->get_queued_mails());
    }

    /**
     * A rule of a booking instance only concerns the booking options of this instance: not those of
     * another instance in the same course, and not those of an instance in another course.
     */
    public function test_rule_of_instance_only_concerns_options_of_this_instance(): void {
        global $DB;

        $samecourse = $this->create_booking_instance();
        $othercourse = $this->create_booking_instance(true);

        $this->create_rule([], 'select_users', '', $this->get_instance_contextid($this->booking));

        $student = $this->create_student('student');
        $this->getDataGenerator()->enrol_user($student->id, $othercourse->course, 'student');
        $own1 = $this->create_option('Own one');
        $own2 = $this->create_option('Own two');
        $foreign1 = $this->create_option('Same course', true, $samecourse);
        $foreign2 = $this->create_option('Other course', true, $othercourse);
        foreach ([$own1, $own2, $foreign1, $foreign2] as $optionid) {
            $this->book($optionid, $student);
        }

        // The event is only triggered for the booking options of the instance of the rule.
        $expectedoptions = [$own1, $own2];
        sort($expectedoptions);
        $this->assertSame($expectedoptions, $this->get_options_of_triggered_events($student, 'extern'));

        // And so the rule is only executed for them.
        $this->change_affiliation($student, 'student');
        $DB->delete_records('task_adhoc');
        $this->change_affiliation($student, 'extern');
        $expected = ["$own1-{$this->office->id}", "$own2-{$this->office->id}"];
        sort($expected);
        $this->assertSame($expected, $this->get_queued_mails());
    }

    /**
     * A user who is only booked in other instances than the one of the rule does not trigger anything.
     */
    public function test_rule_of_instance_ignores_user_booked_in_other_instances_only(): void {
        $samecourse = $this->create_booking_instance();
        $othercourse = $this->create_booking_instance(true);

        $this->create_rule([], 'select_users', '', $this->get_instance_contextid($this->booking));

        $student = $this->create_student('student');
        $this->getDataGenerator()->enrol_user($student->id, $othercourse->course, 'student');
        // The instance of the rule has a booking option, but the user is not booked in it.
        $this->create_option('Own, not booked');
        $this->book($this->create_option('Same course', true, $samecourse), $student);
        $this->book($this->create_option('Other course', true, $othercourse), $student);

        $this->assertSame([], $this->get_options_of_triggered_events($student, 'extern'));

        $this->change_affiliation($student, 'student');
        $this->change_affiliation($student, 'extern');
        $this->assertSame([], $this->get_queued_mails());
    }

    /**
     * Rules of two instances waiting for the same change do not mix: each one is executed for the
     * booking options of its own instance only.
     */
    public function test_rules_of_two_instances_do_not_mix(): void {
        global $DB;

        $second = $this->create_booking_instance();
        $third = $this->create_booking_instance(true);

        // The rule of the first instance informs the office, the rule of the second one the user affected.
        $this->create_rule([], 'select_users', '', $this->get_instance_contextid($this->booking));
        $this->create_rule(
            [],
            'select_user_from_event',
            '{"userfromeventtype":"relateduserid"}',
            $this->get_instance_contextid($second)
        );

        $student = $this->create_student('student');
        $this->getDataGenerator()->enrol_user($student->id, $third->course, 'student');
        $infirst = $this->create_option('In first');
        $insecond = $this->create_option('In second', true, $second);
        $inthird = $this->create_option('In third', true, $third);
        foreach ([$infirst, $insecond, $inthird] as $optionid) {
            $this->book($optionid, $student);
        }

        // No event for the instance without a rule.
        $expectedoptions = [$infirst, $insecond];
        sort($expectedoptions);
        $this->assertSame($expectedoptions, $this->get_options_of_triggered_events($student, 'extern'));

        $this->change_affiliation($student, 'student');
        $DB->delete_records('task_adhoc');
        $this->change_affiliation($student, 'extern');
        $expected = ["$infirst-{$this->office->id}", "$insecond-{$student->id}"];
        sort($expected);
        $this->assertSame($expected, $this->get_queued_mails());
    }

    /**
     * A rule of the whole site concerns the booking options of all instances. Together with a rule
     * of an instance, the options of this instance get both reactions, all the others only one.
     */
    public function test_rule_of_site_concerns_all_instances(): void {
        $samecourse = $this->create_booking_instance();
        $othercourse = $this->create_booking_instance(true);

        // The rule of the site informs the office, the rule of the instance the user affected.
        $this->create_rule();
        $this->create_rule(
            [],
            'select_user_from_event',
            '{"userfromeventtype":"relateduserid"}',
            $this->get_instance_contextid($samecourse)
        );

        $student = $this->create_student('student');
        $this->getDataGenerator()->enrol_user($student->id, $othercourse->course, 'student');
        $option1 = $this->create_option('First instance');
        $option2 = $this->create_option('Same course', true, $samecourse);
        $option3 = $this->create_option('Other course', true, $othercourse);
        foreach ([$option1, $option2, $option3] as $optionid) {
            $this->book($optionid, $student);
        }

        $this->change_affiliation($student, 'extern');

        $expected = [
            "$option1-{$this->office->id}",
            "$option2-{$this->office->id}",
            "$option3-{$this->office->id}",
            "$option2-{$student->id}",
        ];
        sort($expected);
        $this->assertSame($expected, $this->get_queued_mails());
    }

    /**
     * Two rules watch different profile fields and wait for the same value: only the rule of the
     * field which changed is executed. Fields no rule watches do not trigger anything.
     */
    public function test_only_rules_of_the_changed_field_react(): void {
        global $DB;

        foreach (['department', 'unwatched'] as $shortname) {
            $this->getDataGenerator()->create_custom_profile_field([
                'datatype' => 'text',
                'shortname' => $shortname,
                'name' => ucfirst($shortname),
            ]);
        }

        // The rule for the affiliation informs the office, the rule for the department the user affected.
        $this->create_rule();
        $this->create_rule(
            ['cpfield' => 'department'],
            'select_user_from_event',
            '{"userfromeventtype":"relateduserid"}'
        );

        $student = $this->create_student('student');
        $optionid = $this->create_option('Booked');
        $this->book($optionid, $student);

        $this->change_profilefield($student, 'unwatched', 'extern');
        $this->assertSame([], $this->get_queued_mails());
        $this->assertFalse($DB->record_exists(
            'user_preferences',
            ['name' => userprofilefield_change::PREFERENCEPREFIX . 'unwatched']
        ));

        $this->change_profilefield($student, 'department', 'extern');
        $this->assertSame(["$optionid-{$student->id}"], $this->get_queued_mails());

        $DB->delete_records('task_adhoc');
        $this->change_affiliation($student, 'extern');
        $this->assertSame(["$optionid-{$this->office->id}"], $this->get_queued_mails());
    }

    /**
     * Only the user whose field changed is concerned, not the other users booked in the same booking
     * option. The placeholders of the mail show the user affected and the price this user has to pay.
     */
    public function test_other_users_in_the_same_option_are_not_concerned(): void {
        // One rule informs the office, another one the user affected.
        $this->create_rule();
        $this->create_rule([], 'select_user_from_event', '{"userfromeventtype":"relateduserid"}');

        $changed = $this->create_student('student');
        $unchanged = $this->create_student('student', 'Udo', 'Unchanged');
        $optionid = $this->create_option('Booked');
        $this->book($optionid, $changed);
        $this->book($optionid, $unchanged);

        $this->assertSame([$optionid], $this->get_options_of_triggered_events($changed, 'extern'));
        // The other user is updated in the same moment, without a change of the field.
        $this->assertSame([], $this->get_options_of_triggered_events($unchanged, 'student'));

        $this->change_affiliation($changed, 'student');
        $this->change_affiliation($changed, 'extern');
        \core\event\user_updated::create_from_userid($unchanged->id)->trigger();

        $expected = ["$optionid-{$this->office->id}", "$optionid-{$changed->id}"];
        sort($expected);
        $this->assertSame($expected, $this->get_queued_mails());

        unset_config('noemailever');
        ob_start();
        $messagesink = $this->redirectMessages();
        $this->runAdhocTasks(self::MAILTASK);
        $messages = $messagesink->get_messages();
        ob_get_clean();
        $messagesink->close();

        $texts = [];
        foreach ($messages as $message) {
            $this->assertNotEquals($unchanged->id, $message->useridto);
            if ((int) $message->useridto === (int) $this->office->id) {
                $texts[] = $message->fullmessage . ' ' . $message->fullmessagehtml;
            }
        }
        $this->assertCount(1, $texts);
        $text = reset($texts);
        $this->assertStringContainsString('USER:Stefanie Student', $text);
        $this->assertStringNotContainsString('Udo', $text);
        // The user affected pays 80 as an external now, the other user still 50.
        $this->assertStringContainsString('PRICE:80', $text);
    }

    /**
     * On a site without a rule watching a profile field, no task is queued for new users.
     */
    public function test_no_baseline_task_without_rule(): void {
        global $DB;

        $this->getDataGenerator()->create_user();

        $this->assertSame([], \core\task\manager::get_adhoc_tasks(self::BASELINETASK));
        $this->assertFalse($DB->record_exists(
            'user_preferences',
            ['name' => userprofilefield_change::PREFERENCEPREFIX . 'affiliation']
        ));
    }

    /**
     * One task remembers the values of all new users, however many are created, with the values they
     * have when it is executed. It is queued for the rule of an instance too, it leaves users alone
     * who were deleted in the meantime, and it does not touch values which are known already.
     */
    public function test_baseline_task_of_new_users(): void {
        global $DB;

        $this->create_rule([], 'select_users', '', $this->get_instance_contextid($this->booking));
        // The office user was created before the rule, and her value is known already.
        $this->change_affiliation($this->office, 'staff');
        $DB->set_field(
            'user_preferences',
            'value',
            'known',
            ['userid' => $this->office->id, 'name' => userprofilefield_change::PREFERENCEPREFIX . 'affiliation']
        );
        $DB->delete_records('task_adhoc');

        $newusers = [];
        foreach (['student', 'extern', 'staff'] as $affiliation) {
            $newusers[$affiliation] = $this->getDataGenerator()->create_user();
            profile_save_data((object) ['id' => $newusers[$affiliation]->id, 'profile_field_affiliation' => $affiliation]);
        }
        $deleted = $this->getDataGenerator()->create_user();
        delete_user($deleted);

        // One task for all of them, without data of a user, waiting for further users.
        $tasks = \core\task\manager::get_adhoc_tasks(self::BASELINETASK);
        $this->assertCount(1, $tasks);
        $task = reset($tasks);
        $this->assertEmpty((array) $task->get_custom_data());
        $this->assertGreaterThan(time(), $task->get_next_run_time());
        $this->assertFalse($this->get_remembered_value((int) $newusers['student']->id));

        $this->runAdhocTasks(self::BASELINETASK);

        foreach ($newusers as $affiliation => $newuser) {
            $this->assertSame($affiliation, $this->get_remembered_value((int) $newuser->id));
        }
        $this->assertFalse($this->get_remembered_value((int) $deleted->id));
        $this->assertSame('known', $this->get_remembered_value((int) $this->office->id));

        // The task is done. The next new user needs a new one.
        $this->assertSame([], \core\task\manager::get_adhoc_tasks(self::BASELINETASK));
        $this->getDataGenerator()->create_user();
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(self::BASELINETASK));

        // The value is known: the first change of a new user is recognized.
        $newuser = $newusers['student'];
        $this->getDataGenerator()->enrol_user($newuser->id, $this->course->id, 'student');
        $optionid = $this->create_option('Booked');
        $this->book($optionid, $newuser);
        $this->change_affiliation($newuser, 'extern');
        $this->assertSame(["$optionid-{$this->office->id}"], $this->get_queued_mails());
    }

    /**
     * A task which is running already does not serve the users created from now on: a new one is queued.
     */
    public function test_baseline_task_which_started_does_not_count(): void {
        global $DB;

        $this->create_rule();
        $DB->delete_records('task_adhoc');

        $this->getDataGenerator()->create_user();
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(self::BASELINETASK));

        // Cron picked up the task: its time to run has come.
        $DB->set_field('task_adhoc', 'nextruntime', time() - 1);
        $this->getDataGenerator()->create_user();
        $this->assertCount(2, \core\task\manager::get_adhoc_tasks(self::BASELINETASK));
    }

    /**
     * While a user is updated, only the change is detected. The events are triggered and the rules are
     * executed by an adhoc task, so updating a lot of users at once is not slowed down by the reactions.
     */
    public function test_events_are_triggered_by_adhoc_task(): void {
        global $DB;

        $this->create_rule();
        $student = $this->create_student('student');
        $optionid = $this->create_option('Booked');
        $this->book($optionid, $student);

        // An update without a change, and a change no rule is waiting for: no task at all.
        \core\event\user_updated::create_from_userid($student->id)->trigger();
        profile_save_data((object) ['id' => $student->id, 'profile_field_affiliation' => 'staff']);
        \core\event\user_updated::create_from_userid($student->id)->trigger();
        $this->assertSame([], \core\task\manager::get_adhoc_tasks(self::TRIGGERTASK));
        $this->assertSame('staff', $this->get_remembered_value((int) $student->id));

        // The change a rule is waiting for: nothing but one task with the change.
        $sink = $this->redirectEvents();
        profile_save_data((object) ['id' => $student->id, 'profile_field_affiliation' => 'extern']);
        userprofilefield_change::queue_events_for_user((int) $student->id);
        $this->assertSame([], $sink->get_events());
        $sink->close();

        $tasks = \core\task\manager::get_adhoc_tasks(self::TRIGGERTASK);
        $this->assertCount(1, $tasks);
        $data = reset($tasks)->get_custom_data();
        $this->assertSame((int) $student->id, (int) $data->userid);
        $this->assertEquals(
            [(object) ['field' => 'affiliation', 'oldvalue' => 'staff', 'newvalue' => 'extern']],
            $data->changes
        );
        $this->assertSame([], $this->get_queued_mails());
        $this->assertSame('extern', $this->get_remembered_value((int) $student->id));

        // The task triggers the event and the rule is executed.
        $this->runAdhocTasks(self::TRIGGERTASK);
        $this->assertSame([], \core\task\manager::get_adhoc_tasks(self::TRIGGERTASK));
        $this->assertSame(["$optionid-{$this->office->id}"], $this->get_queued_mails());

        // A rule deleted before the task runs does not react anymore.
        $DB->delete_records('task_adhoc');
        $this->change_affiliation($student, 'student');
        profile_save_data((object) ['id' => $student->id, 'profile_field_affiliation' => 'extern']);
        userprofilefield_change::queue_events_for_user((int) $student->id);
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(self::TRIGGERTASK));
        $DB->delete_records('booking_rules');
        $this->runAdhocTasks(self::TRIGGERTASK);
        $this->assertSame([], $this->get_queued_mails());
    }

    /**
     * When the mail is sent, the rule is checked again: The user has to be booked still.
     */
    public function test_rule_does_not_apply_anymore_after_cancellation(): void {
        global $DB;

        $record = $this->create_rule();
        $student = $this->create_student('student');
        $optionid = $this->create_option('Booked');
        $this->book($optionid, $student);

        $this->change_affiliation($student, 'extern');
        $tasks = \core\task\manager::get_adhoc_tasks(self::MAILTASK);
        $this->assertCount(1, $tasks);
        $taskdata = reset($tasks)->get_custom_data();

        $rule = rules_info::get_rule('rule_react_on_event');
        $record = $DB->get_record('booking_rules', ['id' => $record->id], '*', MUST_EXIST);
        $record->rulejson = $taskdata->rulejson;
        $rule->set_ruledata($record);

        $this->assertTrue($rule->check_if_rule_still_applies($optionid, (int) $this->office->id, time()));

        $DB->set_field(
            'booking_answers',
            'waitinglist',
            MOD_BOOKING_STATUSPARAM_DELETED,
            ['optionid' => $optionid, 'userid' => $student->id]
        );

        $this->assertFalse($rule->check_if_rule_still_applies($optionid, (int) $this->office->id, time()));
    }
}
