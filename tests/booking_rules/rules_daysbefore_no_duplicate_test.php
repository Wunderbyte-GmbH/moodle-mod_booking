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
 * Tests that a "days before" reminder is sent only once.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use stdClass;
use tool_mocktesttime\time_mock;
use mod_booking_generator;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/../classes/booking_advanced_testcase.php');
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once($CFG->dirroot . '/user/lib.php');

/**
 * Tests that a "days before" reminder is not sent a second time when rules are re-evaluated
 * within the tolerance window after the reminder was delivered (Wunderbyte-GmbH/moodle-mod_booking#1625).
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rules_daysbefore_no_duplicate_test extends booking_advanced_testcase {
    /** @var string Subject of the reminder mail. */
    private const SUBJECT = 'reminder1625';

    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        time_mock::set_mock_time(strtotime('now'));
        $this->preventResetByRollback();
    }

    /**
     * Creates a booking option with a "3 days before course start" reminder rule for booked users.
     *
     * @param string $rulename rule_daysbefore or rule_specifictime
     * @param int $start course start, defaults to 10 days from now
     * @return array [stdClass $option, stdClass $student, int $remindertime, stdClass $record]
     */
    private function create_option_with_reminder(string $rulename = 'rule_daysbefore', int $start = 0): array {
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->getDataGenerator()->create_user();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'name' => 'Reminder Test',
            'eventtype' => 'Test rules',
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
            'tags' => '',
        ]);
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"subject":"' . self::SUBJECT . '","template":"starts in 3 days","templateformat":"1"}';
        $plugingenerator->create_rule([
            'name' => '3daysbefore',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"0"}',
            'actionname' => 'send_mail',
            'actiondata' => $actstr,
            'rulename' => $rulename,
            'ruledata' => $rulename === 'rule_specifictime'
                ? '{"seconds":"' . (3 * DAYSECS) . '","datefield":"coursestarttime","cancelrules":[]}'
                : '{"days":"3","datefield":"coursestarttime","cancelrules":[]}',
        ]);

        $start = $start ?: strtotime('+10 days', time());
        $record = new stdClass();
        $record->text = 'Option with reminder';
        $record->description = 'Starts in 10 days';
        $record->chooseorcreatecourse = 1; // Required.
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->bookingid = $booking->id;
        $record->courseid = $course->id;
        $record->coursestarttime_0 = $start;
        $record->courseendtime_0 = strtotime('+1 hour', $start);
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        $remindertime = $rulename === 'rule_specifictime' ? $start - 3 * DAYSECS : strtotime('-3 days', $start);
        return [$option, $student, $remindertime, $record];
    }

    /**
     * Books the user into the option.
     *
     * @param stdClass $option
     * @param stdClass $user
     * @return void
     */
    private function book(stdClass $option, stdClass $user): void {
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->create_answer(['optionid' => $option->id, 'userid' => $user->id]);
        singleton_service::destroy_booking_answers($option->id);
    }

    /**
     * Runs the due adhoc tasks at the given time and returns the number of reminder mails sent.
     *
     * @param int $time
     * @return int
     */
    private function run_tasks_and_count_reminders(int $time): int {
        time_mock::set_mock_time($time);
        unset_config('noemailever');
        ob_start();
        $sink = $this->redirectMessages();
        $this->runAdhocTasks();
        $messages = $sink->get_messages();
        $sink->close();
        ob_end_clean();

        return count(array_filter($messages, fn($m) => $m->subject === self::SUBJECT));
    }

    /**
     * A user update (e.g. SSO login syncing the profile) right after the reminder was sent
     * must not send the reminder a second time.
     *
     * @covers \mod_booking_observer::user_updated
     * @covers \mod_booking\booking_rules\rules\rule_daysbefore::execute
     * @covers \mod_booking\booking_rules\actions\send_mail::execute
     */
    public function test_user_updated_after_reminder_does_not_resend(): void {
        [$option, $student, $remindertime] = $this->create_option_with_reminder();
        $this->book($option, $student);

        $this->assertSame(1, $this->run_tasks_and_count_reminders($remindertime + 60));

        // Three minutes later the user logs in and the profile sync updates the user record.
        time_mock::set_mock_time($remindertime + 180);
        $student->firstname = 'Updated';
        user_update_user($student, false, true);

        $this->assertSame(0, $this->run_tasks_and_count_reminders($remindertime + 240));
    }

    /**
     * Saving the booking option right after the reminder was sent must not send the reminder again.
     *
     * @covers \mod_booking\booking_option::update
     * @covers \mod_booking\booking_rules\rules\rule_daysbefore::execute
     * @covers \mod_booking\booking_rules\actions\send_mail::execute
     */
    public function test_option_updated_after_reminder_does_not_resend(): void {
        [$option, $student, $remindertime, $record] = $this->create_option_with_reminder();
        $this->book($option, $student);

        $this->assertSame(1, $this->run_tasks_and_count_reminders($remindertime + 60));

        // Ten minutes later a manager saves the option without changing its dates.
        time_mock::set_mock_time($remindertime + 600);
        $this->setAdminUser();
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $record->id = $option->id;
        $record->cmid = $settings->cmid;
        $record->text .= ' (edited)';
        booking_option::update($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        $this->assertSame(0, $this->run_tasks_and_count_reminders($remindertime + 660));
    }

    /**
     * A user who books after the reminder time does not get the (already overdue) reminder,
     * neither at booking nor on a later profile update.
     *
     * @covers \mod_booking\booking_option::user_submit_response
     * @covers \mod_booking\booking_rules\rules\rule_daysbefore::execute
     */
    public function test_late_booking_after_reminder_time_gets_no_reminder(): void {
        [$option, $student, $remindertime] = $this->create_option_with_reminder();

        // Nobody is booked at reminder time.
        $this->assertSame(0, $this->run_tasks_and_count_reminders($remindertime + 60));

        // The student books 20 minutes after the reminder time.
        time_mock::set_mock_time($remindertime + 1200);
        $this->book($option, $student);
        $this->assertSame(0, $this->run_tasks_and_count_reminders($remindertime + 1260));

        time_mock::set_mock_time($remindertime + 1500);
        $student->firstname = 'Updated';
        user_update_user($student, false, true);
        $this->assertSame(0, $this->run_tasks_and_count_reminders($remindertime + 1560));
    }

    /**
     * Across the spring DST switch, "3 days before" is 71 hours before course start. A user booking
     * 30 minutes before that reminder time is still scheduled and gets the reminder exactly once.
     *
     * @covers \mod_booking\booking_rules\rules\rule_daysbefore::execute
     * @covers \mod_booking\booking_rules\rules\rule_daysbefore::get_records_for_execution
     */
    public function test_booking_shortly_before_reminder_across_dst_gets_reminder_once(): void {
        set_config('timezone', 'Europe/Vienna');
        set_config('forcetimezone', 'Europe/Vienna');
        \core_date::set_default_server_timezone();

        // DST starts on 28 March 2027 in Vienna.
        $start = strtotime('2027-03-30 09:00:00 Europe/Vienna');
        $remindertime = strtotime('2027-03-27 09:00:00 Europe/Vienna');
        $this->assertSame(3 * DAYSECS - HOURSECS, $start - $remindertime);

        time_mock::set_mock_time($remindertime - 1800);
        [$option, $student] = $this->create_option_with_reminder('rule_daysbefore', $start);
        $this->book($option, $student);

        $this->assertSame(1, $this->run_tasks_and_count_reminders($remindertime + 60));

        time_mock::set_mock_time($remindertime + 180);
        $student->firstname = 'Updated';
        user_update_user($student, false, true);
        $this->assertSame(0, $this->run_tasks_and_count_reminders($remindertime + 240));
    }

    /**
     * Same as the user update case, for the "specific time" rule.
     *
     * @covers \mod_booking_observer::user_updated
     * @covers \mod_booking\booking_rules\rules\rule_specifictime::execute
     */
    public function test_specifictime_user_updated_after_reminder_does_not_resend(): void {
        [$option, $student, $remindertime] = $this->create_option_with_reminder('rule_specifictime');
        $this->book($option, $student);

        $this->assertSame(1, $this->run_tasks_and_count_reminders($remindertime + 60));

        time_mock::set_mock_time($remindertime + 180);
        $student->firstname = 'Updated';
        user_update_user($student, false, true);
        $this->assertSame(0, $this->run_tasks_and_count_reminders($remindertime + 240));
    }

    /**
     * The check before sending keeps its tolerance for self-learning end date reminders:
     * a cron running 30 minutes late still sends the reminder.
     *
     * @covers \mod_booking\booking_rules\rules\rule_daysbefore::check_if_rule_still_applies
     * @covers \mod_booking\task\send_mail_by_rule_adhoc::execute
     */
    public function test_selflearning_enddate_reminder_sent_when_cron_is_late(): void {
        global $DB;
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id]);
        $student = $this->getDataGenerator()->create_user();

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->create_rule([
            'name' => 'selflearning end reminder',
            'conditionname' => 'select_student_in_bo',
            'conditiondata' => '{"borole":"0"}',
            'actionname' => 'send_mail',
            'actiondata' => '{"sendical":0,"sendicalcreateorcancel":"","subject":"' . self::SUBJECT . '",'
                . '"template":"Your access ends in one week.","templateformat":"1"}',
            'rulename' => 'rule_daysbefore',
            'ruledata' => '{"days":"7","datefield":"selflearningcourseenddate"}',
        ]);
        $option = $plugingenerator->create_option((object) [
            'bookingid' => $booking->id,
            'text' => 'Self-learning',
            'description' => 'Self-learning',
            'importing' => 1,
            'selflearningcourse' => 1,
            'duration' => 60 * DAYSECS,
            'useprice' => 0,
        ]);

        $end = strtotime('+30 days', time());
        $DB->insert_record('booking_answers', (object) [
            'bookingid' => $booking->id,
            'userid' => $student->id,
            'optionid' => $option->id,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
            'timecreated' => time(),
            'timebooked' => time(),
            'timemodified' => time(),
            'completed' => 0,
            'json' => json_encode(['selflearningendofsubscription' => $end]),
        ]);
        booking_option::purge_cache_for_answers($option->id);
        singleton_service::destroy_instance();
        booking_rules\rules_info::destroy_singletons();
        booking_rules\rules_info::execute_rules_for_option($option->id, $student->id);

        $this->assertSame(1, $this->run_tasks_and_count_reminders(strtotime('-7 days', $end) + 1800));
    }
}
