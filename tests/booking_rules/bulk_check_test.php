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
 * Tests of the bulk send checker for rule mails.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use context_system;
use core\lock\lock_config;
use mod_booking\booking_rules\actions_info;
use mod_booking\booking_rules\rules_info;
use mod_booking\event\bulk_check_blocked;
use mod_booking\event\bulk_check_dismissed;
use mod_booking\event\bulk_check_released;
use mod_booking\local\bulk_check\bulk_check;
use mod_booking\local\bulk_check\bulk_check_config;
use mod_booking\local\scheduledmails;
use mod_booking\table\bulk_check_table;
use mod_booking\table\scheduledmails_table;
use mod_booking\task\bulk_check_reminder;
use mod_booking\task\release_bulk_check;
use mod_booking\task\send_mail_by_rule_adhoc;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use required_capability_exception;
use stdClass;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once(__DIR__ . '/../classes/booking_advanced_testcase.php');
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests of the bulk send checker for rule mails.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\bulk_check\bulk_check
 * @covers \mod_booking\local\bulk_check\bulk_check_config
 * @covers \mod_booking\task\send_mail_by_rule_adhoc
 */
final class bulk_check_test extends booking_advanced_testcase {
    /** @var string Subject of every rule mail these tests send. */
    private const SUBJECT = 'Bulk subject';

    /** @var string The event the react rule listens to. */
    private const BOEVENT = '\\mod_booking\\event\\bookingoption_booked';

    /** @var int */
    private int $base;

    /** @var stdClass */
    private stdClass $course;

    /** @var stdClass */
    private stdClass $booking;

    /** @var mod_booking_generator */
    private $generator;

    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->setAdminUser();

        $this->base = strtotime('2026-09-22 08:00:00');
        time_mock::set_mock_time($this->base);

        $this->course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $this->course->id,
            'name' => 'Bulk check booking',
            'eventtype' => 'Test rules',
            'enablecompletion' => 1,
            'bookedtext' => ['text' => 'text'],
            'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'],
            'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'],
            'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'],
            'notificationtext' => ['text' => 'text'],
            'userleave' => ['text' => 'text'],
            'tags' => '',
            'completion' => 2,
            'showviews' => ['mybooking,myoptions,optionsiamresponsiblefor,showall,showactive,myinstitution'],
        ]);

        $this->generator = self::getDataGenerator()->get_plugin_generator('mod_booking');
    }

    /**
     * Switches the checker on for a rule.
     *
     * @param int $ruleid
     * @param int $limit
     * @param int $period
     * @param int $delay
     * @return void
     */
    private function enable(int $ruleid, int $limit, int $period = HOURSECS, int $delay = 120): void {
        set_config('bulkcheckenabled', 1, 'booking');
        // The window and the delay are site wide, only the limit is per rule.
        set_config('bulkcheckperiod', $period, 'booking');
        set_config('bulkcheckdelay', $delay, 'booking');
        bulk_check_config::set_settings($ruleid, true, $limit);
    }

    /**
     * A rule that mails the booked user whenever an option is booked.
     *
     * @return int The rule id.
     */
    private function create_react_rule(): int {
        $rule = $this->generator->create_rule([
            'name' => 'Bulk react rule',
            'conditionname' => 'select_user_from_event',
            'contextid' => 1,
            'conditiondata' => '{"userfromeventtype":"relateduserid"}',
            'actionname' => 'send_mail',
            'actiondata' => '{"sendical":0,"sendicalcreateorcancel":"","subject":"' . self::SUBJECT
                . '","template":"Bulk body","templateformat":"1"}',
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_booked","aftercompletion":"","condition":"0"}',
        ]);
        return (int) $rule->id;
    }

    /**
     * A rule that mails the given users a number of days before the option starts.
     *
     * @param int $days
     * @param array $userids
     * @return int The rule id.
     */
    private function create_daysbefore_rule(int $days, array $userids): int {
        $rule = $this->generator->create_rule([
            'name' => 'Bulk days rule',
            'conditionname' => 'select_users',
            'contextid' => 1,
            'conditiondata' => json_encode(['userids' => array_map('strval', $userids)]),
            'actionname' => 'send_mail',
            'actiondata' => '{"sendical":0,"sendicalcreateorcancel":"","subject":"' . self::SUBJECT
                . '","template":"Bulk body","templateformat":"1"}',
            'rulename' => 'rule_daysbefore',
            'ruledata' => '{"days":"' . $days . '","datefield":"coursestarttime","cancelrules":[]}',
        ]);
        return (int) $rule->id;
    }

    /**
     * A booking option that starts at the given time.
     *
     * @param int $starttime
     * @return stdClass
     */
    private function create_option(int $starttime): stdClass {
        $record = (object) [
            'bookingid' => $this->booking->id,
            'text' => 'Bulk option',
            'description' => 'Bulk option',
            'chooseorcreatecourse' => 1,
            'courseid' => $this->course->id,
            'optiondateid_0' => '0',
            'daystonotify_0' => '0',
            'coursestarttime_0' => $starttime,
            'courseendtime_0' => $starttime + HOURSECS,
        ];
        return $this->generator->create_option($record);
    }

    /**
     * Creates and enrols users.
     *
     * @param int $count
     * @return array
     */
    private function create_users(int $count): array {
        $users = [];
        for ($i = 0; $i < $count; $i++) {
            $user = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($user->id, $this->course->id, 'student');
            $users[] = $user;
        }
        return $users;
    }

    /**
     * Books the given number of new users onto an option, which fires the react rule per user.
     *
     * @param stdClass $option
     * @param int $count
     * @return array The booked users.
     */
    private function book(stdClass $option, int $count): array {
        $users = $this->create_users($count);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $bookingoption = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        foreach ($users as $user) {
            $bookingoption->user_submit_response($user, 0, 0, 0, MOD_BOOKING_VERIFIED);
        }
        return $users;
    }

    /**
     * Runs every adhoc task that is due at the mocked time, until nothing is due any more.
     *
     * A released burst queues its send tasks from inside a task, so one round is not enough.
     * The output of the tasks is swallowed: the test runner treats it as a failure.
     *
     * @return void
     */
    private function run_due(): void {
        global $DB;
        ob_start();
        try {
            for ($round = 0; $round < 5; $round++) {
                $this->generator->runtaskswithintime(time_mock::get_mock_time());
                $due = $DB->count_records_select('task_adhoc', 'nextruntime <= :now', ['now' => time_mock::get_mock_time()]);
                if ($due === 0) {
                    break;
                }
            }
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Runs the release task of a rule on its own.
     *
     * Not through the queue runner: when the lock is refused the task requeues itself a minute
     * ahead, which with a mocked clock that never moves would loop forever.
     *
     * @param int $ruleid
     * @return void
     */
    private function run_release_task(int $ruleid): void {
        $task = new release_bulk_check();
        $task->set_custom_data(['ruleid' => $ruleid]);
        ob_start();
        try {
            $task->execute();
        } finally {
            ob_end_clean();
        }
    }

    /**
     * The rows of a rule keyed by status.
     *
     * @param int $ruleid
     * @return array
     */
    private function count_by_status(int $ruleid): array {
        global $DB;
        $counts = [];
        foreach ($DB->get_records(bulk_check::TABLENAME, ['ruleid' => $ruleid]) as $row) {
            $counts[(int) $row->status] = ($counts[(int) $row->status] ?? 0) + 1;
        }
        ksort($counts);
        return $counts;
    }

    /**
     * The rule mails among the captured messages.
     *
     * @param array $messages
     * @return array
     */
    private function mails(array $messages): array {
        return array_values(array_filter($messages, fn($msg) => ($msg->subject ?? '') === self::SUBJECT));
    }

    /**
     * The alerts of the checker among the captured messages.
     *
     * @param array $messages
     * @return array
     */
    private function alerts(array $messages): array {
        return array_values(array_filter($messages, fn($msg) => ($msg->eventtype ?? '') === 'bulkchecknotification'));
    }

    /**
     * The queued send tasks.
     *
     * @return array
     */
    private function send_tasks(): array {
        global $DB;
        return array_values($DB->get_records('task_adhoc', ['classname' => '\\' . send_mail_by_rule_adhoc::class], 'id ASC'));
    }

    /**
     * Blocks a burst of three sends of the react rule under a limit of one.
     *
     * @return array [ruleid, option, users, row ids]
     */
    private function block_three(): array {
        global $DB;
        $ruleid = $this->create_react_rule();
        $this->enable($ruleid, 1);
        $option = $this->create_option($this->base + 10 * DAYSECS);
        $sink = $this->redirectMessages();
        $users = $this->book($option, 3);

        time_mock::set_mock_time($this->base + 120);
        $this->run_due();
        $sink->close();

        $this->assertEquals([bulk_check::STATUS_BLOCKED => 3], $this->count_by_status($ruleid));
        $ids = array_keys($DB->get_records(bulk_check::TABLENAME, ['ruleid' => $ruleid], 'id ASC', 'id'));
        return [$ruleid, $option, $users, $ids];
    }

    /**
     * A rule that is not bulk checked is queued and sent exactly as before.
     */
    public function test_rule_without_bulk_check_is_untouched(): void {
        global $DB;
        set_config('bulkcheckenabled', 1, 'booking');
        $ruleid = $this->create_react_rule();
        $option = $this->create_option($this->base + 10 * DAYSECS);

        $sink = $this->redirectMessages();
        $this->book($option, 1);

        $tasks = $this->send_tasks();
        $this->assertCount(1, $tasks);
        $this->assertEquals($this->base, (int) $tasks[0]->nextruntime);
        $this->assertCount(0, $DB->get_records(bulk_check::TABLENAME));

        $this->run_due();
        $this->assertCount(1, $this->mails($sink->get_messages()));
        $this->assertEmpty($this->count_by_status($ruleid));
        $sink->close();
    }

    /**
     * The site switch overrides every per rule row: nothing is delayed or recorded while it is off.
     */
    public function test_feature_off_is_a_true_bypass(): void {
        global $DB;
        $ruleid = $this->create_react_rule();
        bulk_check_config::set_settings($ruleid, true, 1);
        set_config('bulkcheckenabled', 0, 'booking');
        $option = $this->create_option($this->base + 10 * DAYSECS);

        $sink = $this->redirectMessages();
        $this->book($option, 3);

        $tasks = $this->send_tasks();
        $this->assertCount(3, $tasks);
        $this->assertEquals($this->base, (int) $tasks[0]->nextruntime);
        $this->assertCount(0, $DB->get_records(bulk_check::TABLENAME));

        $this->run_due();
        $this->assertCount(3, $this->mails($sink->get_messages()));
        $sink->close();
    }

    /**
     * A bulk checked send is postponed by the delay and recorded with both of its times.
     */
    public function test_delay_is_added_and_send_is_recorded(): void {
        global $DB;
        $ruleid = $this->create_react_rule();
        $this->enable($ruleid, 5);
        $option = $this->create_option($this->base + 10 * DAYSECS);
        $users = $this->book($option, 1);

        $tasks = $this->send_tasks();
        $this->assertCount(1, $tasks);
        $this->assertEquals($this->base + 120, (int) $tasks[0]->nextruntime);

        $rows = array_values($DB->get_records(bulk_check::TABLENAME));
        $this->assertCount(1, $rows);
        $this->assertEquals(bulk_check::STATUS_PENDING, (int) $rows[0]->status);
        $this->assertEquals($users[0]->id, (int) $rows[0]->userid);
        $this->assertEquals($ruleid, (int) $rows[0]->ruleid);
        $this->assertEquals($option->id, (int) $rows[0]->optionid);
        $this->assertEquals($tasks[0]->id, (int) $rows[0]->taskid);
        $this->assertEquals($this->base, (int) $rows[0]->sendtime);
        $this->assertEquals($this->base + 120, (int) $rows[0]->scheduledtime);
        $this->assertEmpty($rows[0]->taskdata);
    }

    /**
     * A reminder that is due days ahead keeps its time: its burst is queued long before it runs.
     */
    public function test_daysbefore_far_ahead_is_not_shifted(): void {
        global $DB;
        $users = $this->create_users(2);
        $ruleid = $this->create_daysbefore_rule(3, array_column($users, 'id'));
        $this->enable($ruleid, 5);
        $this->create_option($this->base + 10 * DAYSECS);

        $tasks = $this->send_tasks();
        $this->assertCount(2, $tasks);
        $this->assertEquals($this->base + 7 * DAYSECS, (int) $tasks[0]->nextruntime);

        $rows = array_values($DB->get_records(bulk_check::TABLENAME));
        $this->assertCount(2, $rows);
        $this->assertEquals($this->base + 7 * DAYSECS, (int) $rows[0]->sendtime);
        $this->assertEquals($this->base + 7 * DAYSECS, (int) $rows[0]->scheduledtime);
    }

    /**
     * A burst that stays below the limit is sent, and the rows turn into sent rows.
     */
    public function test_burst_below_limit_is_sent(): void {
        global $DB;
        $ruleid = $this->create_react_rule();
        $this->enable($ruleid, 5);
        $option = $this->create_option($this->base + 10 * DAYSECS);

        $sink = $this->redirectMessages();
        $this->book($option, 3);

        time_mock::set_mock_time($this->base + 120);
        $this->run_due();

        $this->assertCount(3, $this->mails($sink->get_messages()));
        $this->assertEquals([bulk_check::STATUS_SENT => 3], $this->count_by_status($ruleid));
        foreach ($DB->get_records(bulk_check::TABLENAME) as $row) {
            $this->assertEquals($this->base + 120, (int) $row->timesent);
            $this->assertNull($row->taskid);
        }
        $this->assertCount(0, $this->send_tasks());
        $sink->close();
    }

    /**
     * A burst over the limit sends nothing at all, informs the admins once and is logged.
     */
    public function test_burst_over_limit_blocks_everything_with_one_alert(): void {
        global $DB;
        $ruleid = $this->create_react_rule();
        $this->enable($ruleid, 2);
        $option = $this->create_option($this->base + 10 * DAYSECS);

        $sink = $this->redirectMessages();
        $this->book($option, 4);

        $events = $this->redirectEvents();
        time_mock::set_mock_time($this->base + 120);
        $this->run_due();

        // Not a single mail of the burst went out.
        $this->assertCount(0, $this->mails($sink->get_messages()));
        $this->assertEquals([bulk_check::STATUS_BLOCKED => 4], $this->count_by_status($ruleid));

        // Every blocked row carries what a release needs to rebuild the task.
        $notified = 0;
        foreach ($DB->get_records(bulk_check::TABLENAME) as $row) {
            $this->assertNotEmpty($row->taskdata);
            $this->assertEquals($ruleid, (int) json_decode($row->taskdata)->ruleid);
            $notified += (int) $row->notified;
        }
        $this->assertEquals(1, $notified);

        // The admins were told about it exactly once, and every block was logged.
        $this->assertCount(1, $this->alerts($sink->get_messages()));
        $blocked = array_filter($events->get_events(), fn($e) => $e instanceof bulk_check_blocked);
        $this->assertCount(4, $blocked);
        $this->assertEquals($ruleid, (int) reset($blocked)->other['ruleid']);

        $events->close();
        $sink->close();
    }

    /**
     * A postponed and then released days-before send still passes the rule's own re-validation.
     *
     * Those rules demand exactly the run time they computed back. The task runs later than
     * that, and a released one later still, so the checker hands the row's original time in.
     */
    public function test_daysbefore_blocked_and_released_still_applies(): void {
        global $DB;
        $users = $this->create_users(3);
        $ruleid = $this->create_daysbefore_rule(1, array_column($users, 'id'));
        $this->enable($ruleid, 2);

        // Due in a minute: within the delay, so the sends are postponed.
        $this->create_option($this->base + DAYSECS + 60);
        $tasks = $this->send_tasks();
        $this->assertCount(3, $tasks);
        $this->assertEquals($this->base + 120, (int) $tasks[0]->nextruntime);
        $row = reset($tasks);
        $this->assertEquals($this->base + 60, (int) $DB->get_field(bulk_check::TABLENAME, 'sendtime', ['taskid' => $row->id]));

        $sink = $this->redirectMessages();
        time_mock::set_mock_time($this->base + 120);
        $this->run_due();
        $this->assertCount(0, $this->mails($sink->get_messages()));
        $this->assertEquals([bulk_check::STATUS_BLOCKED => 3], $this->count_by_status($ruleid));

        // Released a good while later, when the time the rule computed is long gone.
        time_mock::set_mock_time($this->base + 3 * HOURSECS);
        $this->assertEquals(3, bulk_check::release_rule($ruleid));
        $this->assertEquals([bulk_check::STATUS_RELEASING => 3], $this->count_by_status($ruleid));
        $this->run_release_task($ruleid);
        $this->assertEquals([bulk_check::STATUS_RELEASED => 3], $this->count_by_status($ruleid));

        $this->run_due();
        $this->assertCount(3, $this->mails($sink->get_messages()));
        $this->assertEquals([bulk_check::STATUS_SENT => 3], $this->count_by_status($ruleid));
        $sink->close();
    }

    /**
     * A released burst is not measured against its own limit on the way back out.
     */
    public function test_released_burst_does_not_reblock(): void {
        [$ruleid] = $this->block_three();

        $events = $this->redirectEvents();
        $this->assertEquals(3, bulk_check::release_all());
        // A second click has nothing left to do and must not queue the burst twice.
        $this->assertEquals(0, bulk_check::release_all());
        $released = array_filter($events->get_events(), fn($e) => $e instanceof bulk_check_released);
        $this->assertCount(1, $released);
        $this->assertEquals(3, (int) reset($released)->other['count']);
        $events->close();

        $sink = $this->redirectMessages();
        $this->run_due();
        $this->assertCount(3, $this->mails($sink->get_messages()));
        $this->assertEquals([bulk_check::STATUS_SENT => 3], $this->count_by_status($ruleid));
        $this->assertEmpty(bulk_check::get_parked_bursts());
        $sink->close();
    }

    /**
     * Switching the feature off lets an already queued burst go out on its normal schedule.
     */
    public function test_switching_the_feature_off_releases_a_queued_burst(): void {
        $ruleid = $this->create_react_rule();
        $this->enable($ruleid, 2);
        $option = $this->create_option($this->base + 10 * DAYSECS);

        $sink = $this->redirectMessages();
        $this->book($option, 5);

        // The burst is over the limit and would be blocked, but the site switches off.
        set_config('bulkcheckenabled', 0, 'booking');

        time_mock::set_mock_time($this->base + 120);
        $this->run_due();

        // Every one of them goes out. Switching the checker off has to bypass the check
        // outright, not fall back to the default limit and weigh the burst against that.
        $this->assertCount(5, $this->mails($sink->get_messages()));
        $this->assertCount(0, $this->alerts($sink->get_messages()));
        $this->assertEquals([bulk_check::STATUS_SENT => 5], $this->count_by_status($ruleid));
        $sink->close();
    }

    /**
     * Dismissing a parked burst gives up on it: never sent, logged, and out of the count.
     */
    public function test_dismiss_gives_up_on_a_parked_burst(): void {
        global $DB;
        [$ruleid] = $this->block_three();

        $events = $this->redirectEvents();
        $this->assertEquals(3, bulk_check::dismiss_rule($ruleid));
        $this->assertEquals(0, bulk_check::dismiss_rule($ruleid));
        $dismissed = array_filter($events->get_events(), fn($e) => $e instanceof bulk_check_dismissed);
        $this->assertCount(1, $dismissed);
        $this->assertEquals(3, (int) reset($dismissed)->other['count']);
        $events->close();

        $sink = $this->redirectMessages();
        $this->run_due();
        $this->assertCount(0, $this->mails($sink->get_messages()));
        $this->assertCount(0, $DB->get_records(bulk_check::TABLENAME));
        $this->assertEmpty(bulk_check::get_parked_bursts());
        $sink->close();
    }

    /**
     * A rule that queues the same send again reuses its task, and the row is updated, not doubled.
     */
    public function test_reschedule_keeps_one_row(): void {
        global $DB;
        $ruleid = $this->create_react_rule();
        $this->enable($ruleid, 1);
        $option = $this->create_option($this->base + 10 * DAYSECS);
        $users = $this->create_users(1);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        $rule = $DB->get_record('booking_rules', ['id' => $ruleid]);
        $action = actions_info::get_action('send_mail');
        $action->set_actiondata($rule);
        $action->ruleid = $ruleid;
        $record = (object) [
            'rulename' => 'rule_react_on_event',
            'userid' => $users[0]->id,
            'optionid' => $option->id,
            'cmid' => $settings->cmid,
            'nextruntime' => $this->base,
        ];

        $action->execute($record);
        time_mock::set_mock_time($this->base + 30);
        $action->execute($record);

        $this->assertCount(1, $this->send_tasks());
        $rows = array_values($DB->get_records(bulk_check::TABLENAME));
        $this->assertCount(1, $rows);
        $this->assertEquals(bulk_check::STATUS_PENDING, (int) $rows[0]->status);
        // The second run moved the task, and the row moved with it.
        $this->assertEquals($this->base + 30 + 120, (int) $rows[0]->scheduledtime);
        $this->assertEquals($this->base + 30 + 120, (int) $this->send_tasks()[0]->nextruntime);
    }

    /**
     * A task that finishes without sending takes its row with it, so it cannot count.
     */
    public function test_non_send_exit_drops_the_row(): void {
        global $DB;
        $ruleid = $this->create_react_rule();
        $this->enable($ruleid, 5);
        $option = $this->create_option($this->base + 10 * DAYSECS);

        $sink = $this->redirectMessages();
        $this->book($option, 2);
        $this->assertEquals([bulk_check::STATUS_PENDING => 2], $this->count_by_status($ruleid));

        // The rule is switched off before the sends run: they no longer apply.
        $DB->set_field('booking_rules', 'isactive', 0, ['id' => $ruleid]);

        time_mock::set_mock_time($this->base + 120);
        $this->run_due();

        $this->assertCount(0, $this->mails($sink->get_messages()));
        $this->assertCount(0, $DB->get_records(bulk_check::TABLENAME));
        $this->assertCount(0, $this->send_tasks());
        $sink->close();
    }

    /**
     * The scheduled mails page validates a postponed task against the rule's time, and deleting
     * a task there drops the row.
     */
    public function test_scheduledmails_validity_and_delete_follow_the_row(): void {
        global $DB;
        $users = $this->create_users(1);
        $ruleid = $this->create_daysbefore_rule(1, array_column($users, 'id'));
        $this->enable($ruleid, 5);
        $this->create_option($this->base + DAYSECS + 60);

        $tasks = $this->send_tasks();
        $this->assertCount(1, $tasks);
        $task = $tasks[0];
        $this->assertEquals($this->base + 120, (int) $task->nextruntime);

        $rule = $DB->get_record('booking_rules', ['id' => $ruleid]);
        $values = (object) [
            'id' => $task->id,
            'ruleid' => $ruleid,
            'customdata' => $task->customdata,
            'isactive' => $rule->isactive,
            'contextid' => $rule->contextid,
            'nextruntime' => $task->nextruntime,
        ];
        // Judged by the task's own run time the postponed send would be invalid, and the daily
        // cleanup would delete it. Judged by the row's time it is fine.
        $this->assertTrue(scheduledmails::is_task_still_valid($values));

        $table = new scheduledmails_table('bulkchecktest');
        $result = $table->action_deleteitem((int) $task->id);
        $this->assertEquals(1, $result['success']);
        $this->assertCount(0, $this->send_tasks());
        $this->assertCount(0, $DB->get_records(bulk_check::TABLENAME));
    }

    /**
     * The cleanup removes orphans and aged sent rows, and gives a stalled release its task back.
     */
    public function test_cleanup_removes_orphans_purges_sent_and_rescues_releasing(): void {
        global $DB;
        $ruleid = $this->create_react_rule();
        $this->enable($ruleid, 5);
        $option = $this->create_option($this->base + 10 * DAYSECS);
        $sink = $this->redirectMessages();
        $this->book($option, 2);
        $sink->close();
        $this->assertEquals([bulk_check::STATUS_PENDING => 2], $this->count_by_status($ruleid));

        $rows = array_values($DB->get_records(bulk_check::TABLENAME, ['ruleid' => $ruleid], 'id ASC'));
        $DB->delete_records('task_adhoc', ['id' => $rows[0]->taskid]);

        // Too early: for all the cleanup knows this is a send that is merely late.
        $this->assertEquals(['orphaned' => 0, 'sentpurged' => 0, 'rescued' => 0], bulk_check::cleanup());

        time_mock::set_mock_time($this->base + 120 + HOURSECS + 1);
        $this->assertEquals(1, bulk_check::cleanup()['orphaned']);
        $this->assertFalse($DB->record_exists(bulk_check::TABLENAME, ['id' => $rows[0]->id]));

        // A sent row is kept for two periods, then it is out of every window and goes.
        $DB->update_record(bulk_check::TABLENAME, (object) [
            'id' => $rows[1]->id,
            'status' => bulk_check::STATUS_SENT,
            'taskid' => null,
            'timesent' => $this->base + 120,
        ]);
        time_mock::set_mock_time($this->base + 120 + 2 * HOURSECS);
        $this->assertEquals(0, bulk_check::cleanup()['sentpurged']);
        time_mock::set_mock_time($this->base + 120 + 2 * HOURSECS + 1);
        $this->assertEquals(1, bulk_check::cleanup()['sentpurged']);
        $this->assertCount(0, $DB->get_records(bulk_check::TABLENAME));

        // A releasing row nobody works on any more gets its task back, but only once.
        $DB->insert_record(bulk_check::TABLENAME, (object) [
            'ruleid' => $ruleid,
            'optionid' => $option->id,
            'userid' => 2,
            'taskid' => null,
            'status' => bulk_check::STATUS_RELEASING,
            'notified' => 0,
            'sendtime' => $this->base,
            'scheduledtime' => $this->base + 120,
            'timesent' => 0,
            'taskdata' => '{}',
            'timecreated' => $this->base,
            'timemodified' => time_mock::get_mock_time(),
        ]);
        $classname = '\\' . release_bulk_check::class;
        $this->assertEquals(0, bulk_check::cleanup()['rescued']);
        time_mock::set_mock_time(time_mock::get_mock_time() + bulk_check::STALERELEASE + 1);
        $this->assertEquals(1, bulk_check::cleanup()['rescued']);
        $this->assertEquals(1, $DB->count_records('task_adhoc', ['classname' => $classname]));
        $this->assertEquals(0, bulk_check::cleanup()['rescued']);
        $this->assertEquals(1, $DB->count_records('task_adhoc', ['classname' => $classname]));
    }

    /**
     * Two workers never drain the same rule at once: the second stands down and comes back.
     */
    public function test_release_task_stands_down_while_the_rule_is_locked(): void {
        global $CFG, $DB;

        // The database lock factories hand the same lock to the same session twice, and the
        // whole test runs in one session, so holding the lock here would hold nothing back.
        // File locks go by the open file, which is what makes the stand down observable.
        $CFG->lock_factory = '\\core\\lock\\file_lock_factory';

        [$ruleid] = $this->block_three();
        $this->assertEquals(3, bulk_check::release_rule($ruleid));

        $classname = '\\' . release_bulk_check::class;
        $this->assertEquals(1, $DB->count_records('task_adhoc', ['classname' => $classname]));

        $lock = lock_config::get_lock_factory(bulk_check::LOCKTYPE)->get_lock('release' . $ruleid, 0);
        $this->assertNotFalse($lock);

        try {
            $this->run_release_task($ruleid);

            // Nothing was queued for sending, and the work was not dropped either: it was
            // handed to a fresh task that comes back once the other worker is done.
            $this->assertEquals([bulk_check::STATUS_RELEASING => 3], $this->count_by_status($ruleid));
            $this->assertEquals(2, $DB->count_records('task_adhoc', ['classname' => $classname]));
        } finally {
            // A lock that is not released fails the test run itself, assertions or not.
            $lock->release();
        }

        // With the rule free again the very same task gets through.
        $this->run_release_task($ruleid);
        $this->assertEquals([bulk_check::STATUS_RELEASED => 3], $this->count_by_status($ruleid));
        $this->assertCount(3, $this->send_tasks());

        // The leftover release tasks find nothing to do.
        $DB->delete_records('task_adhoc', ['classname' => $classname]);
        $sink = $this->redirectMessages();
        $this->run_due();
        $this->assertCount(3, $this->mails($sink->get_messages()));
        $this->assertEquals([bulk_check::STATUS_SENT => 3], $this->count_by_status($ruleid));
        $sink->close();
    }

    /**
     * The table actions are gated, act only on what was ticked, and take their scope from the data.
     */
    public function test_table_actions_are_gated_and_act_on_ticked_rows(): void {
        global $DB;
        [$ruleid, , , $ids] = $this->block_three();
        // Running the tasks leaves the user of the last task logged in, not the admin.
        $this->setAdminUser();
        $table = new bulk_check_table('bulkcheckdummy');

        // Nothing ticked, nothing happens - the ids are never guessed from the row id.
        $result = $table->action_releaseselected(-1, json_encode(['id' => -1]));
        $this->assertEquals(1, $result['success']);
        $this->assertEquals([bulk_check::STATUS_BLOCKED => 3], $this->count_by_status($ruleid));

        // A payload that is not a list of ids is ignored rather than fatal, and so is rubbish.
        $table->action_releaseselected(-1, json_encode(['checkedids' => 'nonsense']));
        $table->action_releaseselected(-1, json_encode(['checkedids' => [0, -1, 'x', $ids[0] + 1000]]));
        $this->assertEquals([bulk_check::STATUS_BLOCKED => 3], $this->count_by_status($ruleid));

        // The ids arrive as strings, exactly as the checkboxes hand them over.
        $table->action_releaseselected(-1, json_encode(['checkedids' => [(string) $ids[0]]]));
        $this->assertEquals(
            [bulk_check::STATUS_BLOCKED => 2, bulk_check::STATUS_RELEASING => 1],
            $this->count_by_status($ruleid)
        );
        // The very same selection has nothing left to do the second time round.
        $this->assertEquals(0, bulk_check::release_rows([$ids[0]]));

        $table->action_dismissselected(-1, json_encode(['checkedids' => [$ids[1]]]));
        $this->assertEquals(
            [bulk_check::STATUS_BLOCKED => 1, bulk_check::STATUS_RELEASING => 1],
            $this->count_by_status($ruleid)
        );

        // The buttons that act on everything take their scope from the data, not the list.
        $table->action_dismissall(-1, json_encode(['ruleid' => $ruleid + 999]));
        $this->assertEquals(
            [bulk_check::STATUS_BLOCKED => 1, bulk_check::STATUS_RELEASING => 1],
            $this->count_by_status($ruleid)
        );
        $table->action_dismissall(-1, json_encode(['ruleid' => $ruleid]));
        $this->assertEquals([bulk_check::STATUS_RELEASING => 1], $this->count_by_status($ruleid));

        // The parked list names the recipient, the rule and the option, and scopes by rule.
        [$fields, $from, $where, $params] = bulk_check::get_parked_mails_sql();
        $this->assertCount(0, $DB->get_records_sql("SELECT $fields FROM $from WHERE $where", $params));

        // Without the capability none of the actions may be used.
        $this->setUser($this->create_users(1)[0]);
        $this->expectException(required_capability_exception::class);
        $table->action_releaseall(-1, json_encode(['ruleid' => $ruleid]));
    }

    /**
     * The parked list carries what the page shows, one row per mail.
     */
    public function test_parked_mails_sql_lists_one_row_per_mail(): void {
        global $DB;
        [$ruleid, $option, $users] = $this->block_three();

        [$fields, $from, $where, $params] = bulk_check::get_parked_mails_sql();
        $rows = $DB->get_records_sql("SELECT $fields FROM $from WHERE $where", $params);
        $this->assertCount(3, $rows);

        $row = reset($rows);
        $expected = [
            'id', 'ruleid', 'optionid', 'userid', 'sendtime', 'scheduledtime', 'timemodified',
            'rulename', 'optionname', 'firstname', 'lastname', 'email', 'recipient',
        ];
        foreach ($expected as $field) {
            $this->assertObjectHasProperty($field, $row);
        }
        $this->assertEquals('Bulk react rule', $row->rulename);
        $this->assertEquals('Bulk option', $row->optionname);
        $this->assertContains((int) $row->userid, array_map(fn($u) => (int) $u->id, $users));

        [$fields, $from, $where, $params] = bulk_check::get_parked_mails_sql($ruleid);
        $this->assertCount(3, $DB->get_records_sql("SELECT $fields FROM $from WHERE $where", $params));
        [$fields, $from, $where, $params] = bulk_check::get_parked_mails_sql($ruleid + 999);
        $this->assertEmpty($DB->get_records_sql("SELECT $fields FROM $from WHERE $where", $params));

        $bursts = bulk_check::get_parked_bursts();
        $this->assertCount(1, $bursts);
        $this->assertEquals(3, (int) reset($bursts)->parked);
        $this->assertEquals('Bulk react rule', reset($bursts)->rulename);
        $this->assertEquals(3, bulk_check::count_parked());
        $this->assertEquals(3, bulk_check::count_parked($ruleid));
        $this->assertEquals(0, bulk_check::count_parked($ruleid + 999));
    }

    /**
     * The checker's own events go through the catch-all observer without waking any rule.
     */
    public function test_own_events_do_not_trigger_rules(): void {
        $ruleid = $this->create_react_rule();
        rules_info::$rulestoexecute = [];

        bulk_check_blocked::create([
            'context' => context_system::instance(),
            'other' => ['ruleid' => $ruleid, 'count' => 1],
        ])->trigger();
        bulk_check_released::create([
            'context' => context_system::instance(),
            'other' => ['ruleid' => $ruleid, 'count' => 1],
        ])->trigger();

        $this->assertEmpty(rules_info::$rulestoexecute);
        $this->assertCount(0, $this->send_tasks());
    }

    /**
     * The reminder nags while something is parked, stays quiet once it is not, and while off.
     */
    public function test_reminder_nags_until_the_burst_is_dealt_with(): void {
        [$ruleid] = $this->block_three();
        $task = new bulk_check_reminder();

        $firstsink = $this->redirectMessages();
        $task->execute();
        $alerts = $this->alerts($firstsink->get_messages());
        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('Bulk react rule', $alerts[0]->fullmessagehtml);
        $firstsink->close();

        $secondsink = $this->redirectMessages();
        $task->execute();
        $this->assertCount(1, $this->alerts($secondsink->get_messages()));
        $secondsink->close();

        set_config('bulkcheckenabled', 0, 'booking');
        $offsink = $this->redirectMessages();
        $task->execute();
        $this->assertCount(0, $this->alerts($offsink->get_messages()));
        $offsink->close();
        set_config('bulkcheckenabled', 1, 'booking');

        bulk_check::dismiss_rule($ruleid);
        $thirdsink = $this->redirectMessages();
        $task->execute();
        $this->assertCount(0, $this->alerts($thirdsink->get_messages()));
        $thirdsink->close();
    }

    /**
     * Sent rows keep counting inside the window, so a slow flood is caught too.
     */
    public function test_sent_rows_keep_counting_inside_the_window(): void {
        $ruleid = $this->create_react_rule();
        $this->enable($ruleid, 4);
        $option = $this->create_option($this->base + 10 * DAYSECS);
        $sink = $this->redirectMessages();

        // Three go out.
        $this->book($option, 3);
        time_mock::set_mock_time($this->base + 120);
        $this->run_due();
        $this->assertCount(3, $this->mails($sink->get_messages()));
        $this->assertEquals([bulk_check::STATUS_SENT => 3], $this->count_by_status($ruleid));

        // Two more, due half an hour later: three sent plus two pending is over four.
        time_mock::set_mock_time($this->base + 1800);
        $this->book($option, 2);
        time_mock::set_mock_time($this->base + 1800 + 120);
        $this->run_due();
        $this->assertCount(3, $this->mails($sink->get_messages()));
        $this->assertEquals(
            [bulk_check::STATUS_SENT => 3, bulk_check::STATUS_BLOCKED => 2],
            $this->count_by_status($ruleid)
        );
        $this->assertEquals(2, bulk_check::dismiss_rule($ruleid));

        // Two more, due a full period after the first three: those are out of the window.
        time_mock::set_mock_time($this->base + 120 + HOURSECS + 1);
        $this->book($option, 2);
        time_mock::set_mock_time($this->base + 120 + HOURSECS + 1 + 120);
        $this->run_due();
        $this->assertCount(5, $this->mails($sink->get_messages()));
        $this->assertEquals([bulk_check::STATUS_SENT => 5], $this->count_by_status($ruleid));
        $sink->close();
    }

    /**
     * The configuration survives a round trip, the cache does not go stale, and the rule form
     * reads and removes it.
     */
    public function test_configuration_round_trip_and_rule_form(): void {
        set_config('bulkcheckenabled', 1, 'booking');
        $ruleid = $this->create_react_rule();

        // Nothing stored yet, so the rule is not bulk checked.
        $this->assertFalse(bulk_check_config::applies($ruleid));
        $this->assertNull(bulk_check_config::get_record($ruleid));

        bulk_check_config::set_settings($ruleid, true, 7);
        $this->assertTrue(bulk_check_config::applies($ruleid));
        $this->assertEquals(7, bulk_check_config::get_limit($ruleid));

        // The window and the delay come from the site settings, the same for every rule.
        set_config('bulkcheckperiod', 2 * HOURSECS, 'booking');
        set_config('bulkcheckdelay', 300, 'booking');
        $this->assertEquals(2 * HOURSECS, bulk_check_config::get_global_period());
        $this->assertEquals(300, bulk_check_config::get_global_delay());
        set_config('bulkcheckdelay', 0, 'booking');
        $this->assertEquals(0, bulk_check_config::get_global_delay());

        // Writing again has to be visible immediately, not after the cache expires.
        bulk_check_config::set_settings($ruleid, true, 9);
        $this->assertEquals(9, bulk_check_config::get_limit($ruleid));

        // The form reads the stored row back.
        $data = (object) ['id' => $ruleid];
        rules_info::set_data_for_form($data);
        $this->assertEquals(1, $data->bulkcheckactive);
        $this->assertEquals(9, $data->bulkchecklimit);

        // Unticking leaves the number in place but stops the checking.
        bulk_check_config::set_settings($ruleid, false, 9);
        $this->assertFalse(bulk_check_config::applies($ruleid));
        $this->assertEquals(9, (int) bulk_check_config::get_record($ruleid)->limitcount);

        // The master switch overrides every per rule row.
        bulk_check_config::set_settings($ruleid, true, 9);
        set_config('bulkcheckenabled', 0, 'booking');
        $this->assertFalse(bulk_check_config::applies($ruleid));
        set_config('bulkcheckenabled', 1, 'booking');

        // Only the mail actions can be checked.
        $this->assertTrue(bulk_check_config::is_checkable_action('send_mail'));
        $this->assertTrue(bulk_check_config::is_checkable_action('send_copy_of_mail'));
        $this->assertFalse(bulk_check_config::is_checkable_action('send_mail_interval'));
        $this->assertFalse(bulk_check_config::is_checkable_action(null));

        // Deleting the rule takes its configuration and its parked mails with it.
        bulk_check_config::set_settings($ruleid, true, 1);
        $option = $this->create_option($this->base + 10 * DAYSECS);
        $sink = $this->redirectMessages();
        $this->book($option, 3);
        time_mock::set_mock_time($this->base + 120);
        $this->run_due();
        $sink->close();
        $this->assertEquals(3, bulk_check::count_parked($ruleid));

        $events = $this->redirectEvents();
        rules_info::delete_rule($ruleid);
        $this->assertEquals(0, bulk_check::count_parked($ruleid));
        $this->assertNull(bulk_check_config::get_record($ruleid));
        $this->assertFalse(bulk_check_config::applies($ruleid));
        $this->assertCount(1, array_filter($events->get_events(), fn($e) => $e instanceof bulk_check_dismissed));
        $events->close();
    }

    /**
     * Saving the rule form stores the per rule setting, before the rule is run again.
     */
    public function test_save_booking_rule_stores_the_setting_before_the_rule_runs(): void {
        set_config('bulkcheckenabled', 1, 'booking');
        $users = $this->create_users(2);
        $this->create_option($this->base + 10 * DAYSECS);

        $data = (object) [
            'id' => 0,
            'contextid' => 1,
            'rule_name' => 'Form rule',
            'ruleisactive' => 1,
            'useastemplate' => 0,
            'bookingruletype' => 'rule_daysbefore',
            'rule_daysbefore_days' => 3,
            'rule_daysbefore_datefield' => 'coursestarttime',
            'rule_daysbefore_cancelrules' => [],
            'bookingruleconditiontype' => 'select_users',
            'condition_select_users_userids' => array_map('strval', array_column($users, 'id')),
            'bookingruleactiontype' => 'send_mail',
            'action_send_mail_subject' => self::SUBJECT,
            'action_send_mail_template' => ['text' => 'Bulk body', 'format' => FORMAT_HTML],
            'action_send_mail_sendical' => 0,
            'action_send_mail_sendicalcreateorcancel' => '',
            'bulkcheckactive' => 1,
            'bulkchecklimit' => 3,
        ];
        $ruleid = rules_info::save_booking_rule($data);

        $record = bulk_check_config::get_record($ruleid);
        $this->assertNotNull($record);
        $this->assertEquals(1, (int) $record->enabled);
        $this->assertEquals(3, (int) $record->limitcount);

        // The run that saving the rule triggered already went through the checker.
        $tasks = $this->send_tasks();
        $this->assertCount(2, $tasks);
        $this->assertEquals([bulk_check::STATUS_PENDING => 2], $this->count_by_status($ruleid));

        // Saving again without the tick switches the check off but keeps the number.
        $data->id = $ruleid;
        $data->bulkcheckactive = 0;
        rules_info::save_booking_rule($data);
        $this->assertFalse(bulk_check_config::applies($ruleid));
        $this->assertEquals(3, (int) bulk_check_config::get_record($ruleid)->limitcount);

        // While the site switch is off the form does not touch the stored setting at all.
        set_config('bulkcheckenabled', 0, 'booking');
        $data->bulkcheckactive = 1;
        $data->bulkchecklimit = 8;
        rules_info::save_booking_rule($data);
        $this->assertEquals(0, (int) bulk_check_config::get_record($ruleid)->enabled);
        $this->assertEquals(3, (int) bulk_check_config::get_record($ruleid)->limitcount);
    }
}
