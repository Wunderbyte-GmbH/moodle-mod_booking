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

namespace mod_booking;

use mod_booking\booking_rules\rules_info;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use stdClass;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * End date reminders of self-learning courses also cover running previously booked answers
 * (Wunderbyte-GmbH/Wunderbyte-GmbH#2545).
 *
 * Per user and option there is exactly one booked answer; a previously booked answer (status 6) can still be a
 * valid, running period (#2543), e.g. a second license for another site. Its "expires soon" reminder is queued at
 * purchase and must neither be skipped nor aborted because the answer was demoted by a later purchase.
 * The rule mirrors showroom rule 29 ("Erinnerung Pro 1 Jahr": 7 days before selflearningcourseenddate).
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\booking_rules\conditions\select_student_in_bo::execute
 * @covers \mod_booking\booking_rules\rules\rule_specifictime
 * @covers \mod_booking\booking_rules\rules\rule_daysbefore
 */
final class selflearning_enddate_reminder_previouslybooked_test extends booking_advanced_testcase {
    /** @var int One year. */
    private const DURATION = 31536000;

    /** @var int Seven days before the end, as showroom rule 29. */
    private const BEFORE = 604800;

    /** @var int Now: 2026-10-02 19:00 Europe/Vienna. */
    private const NOW = 1790960400;

    /** @var stdClass */
    private $bookingmodule;

    /** @var stdClass */
    private $course;

    /** @var mod_booking_generator */
    private $plugingenerator;

    /**
     * Booking instance and generator.
     */
    protected function setUp(): void {
        parent::setUp();
        time_mock::set_mock_time(self::NOW);
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course();
        $this->bookingmodule = $this->getDataGenerator()->create_module('booking', ['course' => $this->course->id]);
        $this->plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
    }

    /**
     * Creates a reminder rule on the end of self-learning courses.
     *
     * @param string $rulename rule_specifictime or rule_daysbefore
     * @param string $datefield
     */
    private function create_reminder_rule(
        string $rulename = 'rule_specifictime',
        string $datefield = 'selflearningcourseenddate'
    ): void {
        $ruledata = $rulename === 'rule_specifictime'
            ? ['seconds' => self::BEFORE, 'datefield' => $datefield]
            : ['days' => 7, 'datefield' => $datefield];
        $this->plugingenerator->create_rule([
            'name' => 'Erinnerung Pro 1 Jahr',
            'conditionname' => 'select_student_in_bo',
            'conditiondata' => '{"borole":"0"}',
            'actionname' => 'send_mail',
            'actiondata' => '{"sendical":0,"sendicalcreateorcancel":"","subject":"License expires",'
                . '"template":"Your license expires in one week.","templateformat":"1"}',
            'rulename' => $rulename,
            'ruledata' => json_encode($ruledata),
        ]);
    }

    /**
     * A self-learning option with a duration of one year.
     *
     * @return int option id
     */
    private function create_selflearning_option(): int {
        $option = $this->plugingenerator->create_option((object) [
            'bookingid' => $this->bookingmodule->id,
            'text' => 'Booking Pro License - 1 year',
            'description' => 'Booking Pro License - 1 year',
            'importing' => 1,
            'selflearningcourse' => 1,
            'duration' => self::DURATION,
            'useprice' => 0,
        ]);
        return (int) $option->id;
    }

    /**
     * Inserts an answer in the shape of a real license purchase.
     *
     * @param int $optionid
     * @param int $userid
     * @param int $waitinglist
     * @param int $end selflearningendofsubscription
     * @return int answer id
     */
    private function add_answer(int $optionid, int $userid, int $waitinglist, int $end): int {
        global $DB;
        $start = $end - self::DURATION;
        return (int) $DB->insert_record('booking_answers', (object) [
            'bookingid' => $this->bookingmodule->id,
            'userid' => $userid,
            'optionid' => $optionid,
            'waitinglist' => $waitinglist,
            'timecreated' => $start,
            'timebooked' => $start,
            'timemodified' => $start,
            'completed' => 0,
            'json' => json_encode(['selflearningendofsubscription' => $end]),
        ]);
    }

    /**
     * Runs the option's rules for the user, like after a purchase.
     *
     * @param int $optionid
     * @param int $userid
     */
    private function run_rules(int $optionid, int $userid): void {
        booking_option::purge_cache_for_answers($optionid);
        singleton_service::destroy_instance();
        rules_info::destroy_singletons();
        rules_info::execute_rules_for_option($optionid, $userid);
    }

    /**
     * Queued reminder tasks as [userid, nextruntime], sorted.
     *
     * @return array
     */
    private function queued(): array {
        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        $queued = [];
        foreach ($tasks as $task) {
            $queued[] = [(int) $task->get_custom_data()->userid, (int) $task->get_next_run_time()];
        }
        sort($queued);
        return $queued;
    }

    /**
     * Showroom shape (second license for another site): the older license is previously booked and still runs
     * until 2027-01-23, the current one runs until 2027-08-04. Both get their reminder.
     */
    public function test_running_previously_booked_license_gets_its_reminder(): void {
        $this->create_reminder_rule();
        $optionid = $this->create_selflearning_option();
        $user = $this->getDataGenerator()->create_user();
        $previous = strtotime('2027-01-23 10:29:00 Europe/Vienna');
        $current = strtotime('2027-08-04 11:15:44 Europe/Vienna');
        $this->add_answer($optionid, $user->id, MOD_BOOKING_STATUSPARAM_PREVIOUSLYBOOKED, $previous);
        $this->add_answer($optionid, $user->id, MOD_BOOKING_STATUSPARAM_BOOKED, $current);

        $this->run_rules($optionid, $user->id);

        $this->assertSame(
            [[(int) $user->id, $previous - self::BEFORE], [(int) $user->id, $current - self::BEFORE]],
            $this->queued()
        );
    }

    /**
     * The same with a "days before" rule.
     */
    public function test_running_previously_booked_license_gets_its_reminder_with_days_before_rule(): void {
        $this->create_reminder_rule('rule_daysbefore');
        $optionid = $this->create_selflearning_option();
        $user = $this->getDataGenerator()->create_user();
        $previous = strtotime('2027-01-23 10:29:00 Europe/Vienna');
        $current = strtotime('2027-08-04 11:15:44 Europe/Vienna');
        $this->add_answer($optionid, $user->id, MOD_BOOKING_STATUSPARAM_PREVIOUSLYBOOKED, $previous);
        $this->add_answer($optionid, $user->id, MOD_BOOKING_STATUSPARAM_BOOKED, $current);

        $this->run_rules($optionid, $user->id);

        $this->assertCount(2, $this->queued());
    }

    /**
     * A reminder queued at purchase is still sent after the answer was demoted by a later purchase.
     */
    public function test_queued_reminder_is_sent_after_the_answer_was_demoted(): void {
        global $DB;
        $this->create_reminder_rule();
        $optionid = $this->create_selflearning_option();
        $user = $this->getDataGenerator()->create_user();
        $end = self::NOW + 10 * DAYSECS;
        $answerid = $this->add_answer($optionid, $user->id, MOD_BOOKING_STATUSPARAM_BOOKED, $end);
        $this->run_rules($optionid, $user->id);
        $this->assertSame([[(int) $user->id, $end - self::BEFORE]], $this->queued());

        // A later purchase (another site) demotes the answer - its period still runs.
        $DB->set_field('booking_answers', 'waitinglist', MOD_BOOKING_STATUSPARAM_PREVIOUSLYBOOKED, ['id' => $answerid]);
        booking_option::purge_cache_for_answers($optionid);
        singleton_service::destroy_instance();
        rules_info::destroy_singletons();

        unset_config('noemailever');
        time_mock::set_mock_time($end - self::BEFORE + 60);
        $sink = $this->redirectMessages();
        ob_start();
        $this->plugingenerator->runtaskswithintime(time_mock::get_mock_time());
        ob_end_clean();
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertCount(1, $messages);
        $this->assertEquals($user->id, $messages[0]->useridto);
    }

    /**
     * Expired previously booked licenses, deleted answers and other users get no reminder; a single booked
     * license gets exactly one (behaviour as before).
     */
    public function test_expired_deleted_and_single_answers(): void {
        $this->create_reminder_rule();
        $optionid = $this->create_selflearning_option();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $current = strtotime('2027-10-01 13:10:38 Europe/Vienna');
        $expired = strtotime('2026-10-01 08:21:36 Europe/Vienna');
        $this->add_answer($optionid, $user->id, MOD_BOOKING_STATUSPARAM_PREVIOUSLYBOOKED, $expired);
        $this->add_answer($optionid, $user->id, MOD_BOOKING_STATUSPARAM_DELETED, self::NOW + 100 * DAYSECS);
        $this->add_answer($optionid, $user->id, MOD_BOOKING_STATUSPARAM_BOOKED, $current);
        $this->add_answer($optionid, $other->id, MOD_BOOKING_STATUSPARAM_DELETED, self::NOW + 100 * DAYSECS);

        $this->run_rules($optionid, $user->id);
        $this->run_rules($optionid, $other->id);

        $this->assertSame([[(int) $user->id, $current - self::BEFORE]], $this->queued());
    }

    /**
     * Rules on other date fields keep selecting booked answers only.
     */
    public function test_other_datefields_ignore_previously_booked_answers(): void {
        global $DB;
        $this->create_reminder_rule('rule_specifictime', 'courseendtime');
        $optionid = $this->create_selflearning_option();
        $DB->set_field('booking_options', 'type', 0, ['id' => $optionid]);
        $DB->set_field('booking_options', 'courseendtime', self::NOW + 30 * DAYSECS, ['id' => $optionid]);
        booking_option::purge_cache_for_option($optionid);
        $booked = $this->getDataGenerator()->create_user();
        $previous = $this->getDataGenerator()->create_user();
        $this->add_answer($optionid, $booked->id, MOD_BOOKING_STATUSPARAM_BOOKED, self::NOW + 30 * DAYSECS);
        $this->add_answer($optionid, $previous->id, MOD_BOOKING_STATUSPARAM_PREVIOUSLYBOOKED, self::NOW + 30 * DAYSECS);

        $this->run_rules($optionid, 0);

        $this->assertSame([[(int) $booked->id, self::NOW + 30 * DAYSECS - self::BEFORE]], $this->queued());
    }
}
