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
 * Tests for the adhoc task that queues the mails of date based booking rules again.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\local\waitlist\migration\upgrade_step;
use mod_booking\task\requeue_rule_mails_adhoc;
use mod_booking\task\send_mail_by_rule_adhoc;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use stdClass;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/../classes/booking_advanced_testcase.php');
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * The reminders the waiting list migration deleted are queued again, once and at their old time.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\task\requeue_rule_mails_adhoc
 */
final class requeue_rule_mails_adhoc_test extends booking_advanced_testcase {
    /** @var string Subject of the reminder mail. */
    private const SUBJECT = 'reminderrequeue';

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
     * Creates an option that starts in 10 days, a "3 days before" reminder rule and a booked user.
     *
     * @return int the time the reminder is due
     */
    private function create_booked_option_with_reminder(): int {
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->getDataGenerator()->create_user();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'name' => 'Requeue Test',
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
            'rulename' => 'rule_daysbefore',
            'ruledata' => '{"days":"3","datefield":"coursestarttime","cancelrules":[]}',
        ]);

        $start = strtotime('+10 days', time());
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

        $plugingenerator->create_answer(['optionid' => $option->id, 'userid' => $student->id]);
        singleton_service::destroy_booking_answers($option->id);

        return strtotime('-3 days', $start);
    }

    /**
     * The pending reminder tasks.
     *
     * @return stdClass[]
     */
    private function pending_reminders(): array {
        global $DB;
        return array_values($DB->get_records('task_adhoc', ['classname' => '\\' . send_mail_by_rule_adhoc::class]));
    }

    /**
     * Queues the tasks for all rules and runs them.
     *
     * @return int the number of rules a task was queued for
     */
    private function requeue(): int {
        $count = requeue_rule_mails_adhoc::queue_for_all_rules();
        ob_start();
        $this->runAdhocTasks(requeue_rule_mails_adhoc::class);
        ob_end_clean();
        return $count;
    }

    /**
     * A reminder the migration deleted is queued again at its old time, only once, and is sent.
     */
    public function test_deleted_reminder_is_queued_again(): void {
        $remindertime = $this->create_booked_option_with_reminder();
        $this->assertCount(1, $this->pending_reminders(), 'Booking the user queues the reminder.');

        // The migration of the waiting list progression deletes every pending rule mail.
        upgrade_step::run();
        $this->assertCount(0, $this->pending_reminders());

        $this->assertSame(1, $this->requeue());
        $reminders = $this->pending_reminders();
        $this->assertCount(1, $reminders);
        $this->assertSame($remindertime, (int) $reminders[0]->nextruntime);

        // Running it again reuses the queued mail.
        $this->requeue();
        $this->assertCount(1, $this->pending_reminders());

        time_mock::set_mock_time($remindertime + 60);
        unset_config('noemailever');
        ob_start();
        $sink = $this->redirectMessages();
        $this->runAdhocTasks();
        $messages = $sink->get_messages();
        $sink->close();
        ob_end_clean();
        $this->assertCount(1, array_filter($messages, fn($m) => $m->subject === self::SUBJECT));
    }

    /**
     * A reminder whose time has passed is not queued again.
     */
    public function test_reminder_in_the_past_is_not_queued_again(): void {
        $remindertime = $this->create_booked_option_with_reminder();
        upgrade_step::run();

        time_mock::set_mock_time($remindertime + HOURSECS);
        $this->requeue();
        $this->assertCount(0, $this->pending_reminders());
    }

    /**
     * Event rules and inactive rules get no task.
     */
    public function test_only_active_date_rules_get_a_task(): void {
        global $DB;

        $json = '{"actionname":"send_mail"}';
        $DB->insert_record('booking_rules', ['contextid' => 1, 'rulename' => 'rule_react_on_event', 'rulejson' => $json,
            'isactive' => 1]);
        $DB->insert_record('booking_rules', ['contextid' => 1, 'rulename' => 'rule_daysbefore', 'rulejson' => $json,
            'isactive' => 0]);
        $activeid = $DB->insert_record('booking_rules', ['contextid' => 1, 'rulename' => 'rule_specifictime',
            'rulejson' => $json, 'isactive' => 1]);

        $this->assertSame(1, requeue_rule_mails_adhoc::queue_for_all_rules());
        // Queueing twice does not add a second task for the rule.
        requeue_rule_mails_adhoc::queue_for_all_rules();

        $tasks = $DB->get_records('task_adhoc', ['classname' => '\\' . requeue_rule_mails_adhoc::class]);
        $this->assertCount(1, $tasks);
        $this->assertSame((int) $activeid, (int) json_decode(reset($tasks)->customdata)->ruleid);
    }
}
