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
 * Tests for the sync of course completions to the booking options of a linked course category.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use completion_completion;
use core\task\manager;
use mod_booking\local\autocreateoption;
use mod_booking\local\sync\completionsync;
use mod_booking\local\sync\enrolmentsync;
use mod_booking\utils\wb_payment;
use stdClass;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once($CFG->libdir . '/completionlib.php');

/**
 * Tests for the sync of course completions to the booking options of a linked course category.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\sync\completionsync
 * @covers \mod_booking\local\autocreateoption::get_option_ids_for_course
 */
final class completion_sync_course_test extends advanced_testcase {
    /**
     * Tests set up.
     *
     * The observers of the course and enrolment events are not internal, so they are only delivered once the
     * surrounding DB transaction has been committed. PHPUnit wraps every test in a transaction, so the tests must
     * reset by truncation instead.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        // Any earlier test may have frozen the mocked time of the mod_booking namespace in the past, see
        // tool_mocktesttime. Core keeps the real time, so the two must be aligned again.
        time_mock::set_mock_time(strtotime('now'));
        $this->preventResetByRollback();
        $this->setAdminUser();
        set_config('enablecompletion', 1);
        set_config('automaticbookingoptioncompletion', 0, 'booking');
        wb_payment::override_pro_version_for_tests(true);
        singleton_service::destroy_instance();
        autocreateoption::purge_map();
        enrolmentsync::reset_request_state();
    }

    /**
     * Tests tear down.
     */
    public function tearDown(): void {
        wb_payment::override_pro_version_for_tests(null);
        enrolmentsync::reset_request_state();
        singleton_service::destroy_instance();
        parent::tearDown();
    }

    /**
     * Creates a booking instance in a course of its own category, linked to a new category, with the completion
     * sync on.
     *
     * @param array $extra additional instance settings
     * @return array [booking instance, linked category]
     */
    private function create_instance(array $extra = []): array {
        $category = $this->getDataGenerator()->create_category();
        $instancecategory = $this->getDataGenerator()->create_category();
        $instancecourse = $this->getDataGenerator()->create_course(['category' => $instancecategory->id]);
        $record = array_merge([
            'course' => $instancecourse->id,
            'autocreateoptioncategoryid' => $category->id,
            'autocreateoptionsynccompletion' => 1,
        ], $extra);
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        return [$plugingenerator->create_instance($record), $category];
    }

    /**
     * Creates a course with completion enabled in the category and runs the option creation.
     *
     * @param stdClass $booking
     * @param \core_course_category $category
     * @return array [course, option record]
     */
    private function create_course_with_option(stdClass $booking, \core_course_category $category): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['category' => $category->id, 'enablecompletion' => 1]);
        $this->run_tasks_silently();
        $optionids = autocreateoption::get_option_ids_for_course((int)$booking->id, (int)$course->id);
        $this->assertCount(1, $optionids);
        $option = $DB->get_record('booking_options', ['id' => reset($optionids)], '*', MUST_EXIST);
        return [$course, $option];
    }

    /**
     * Books a user into an option by hand (no sync rule).
     *
     * @param stdClass $option
     * @param stdClass $user
     * @return void
     */
    private function book_user(stdClass $option, stdClass $user): void {
        $settings = singleton_service::get_instance_of_booking_option_settings((int)$option->id);
        $bookingoption = singleton_service::get_instance_of_booking_option((int)$settings->cmid, (int)$option->id);
        $bookingoption->user_submit_response($user, 0, 0, 0, MOD_BOOKING_VERIFIED);
        singleton_service::destroy_instance();
    }

    /**
     * Completes a course for a user at the given time, like core does, so the course_completed event fires.
     *
     * @param stdClass $course
     * @param stdClass $user
     * @param int $timecompleted
     * @return void
     */
    private function complete_course(stdClass $course, stdClass $user, int $timecompleted): void {
        $completion = new completion_completion(['course' => $course->id, 'userid' => $user->id]);
        $sink = $this->redirectMessages();
        $completion->mark_complete($timecompleted);
        $sink->close();
        singleton_service::destroy_instance();
    }

    /**
     * Runs all queued adhoc tasks, also those that wait for their delay, swallowing their output.
     *
     * @return void
     */
    private function run_tasks_silently(): void {
        ob_start();
        try {
            while ($task = manager::get_next_adhoc_task(time() + HOURSECS)) {
                $task->execute();
                manager::adhoc_task_complete($task);
            }
        } finally {
            ob_end_clean();
        }
        singleton_service::destroy_instance();
    }

    /**
     * The newest answer of a user in an option, null if the user has none.
     *
     * @param int $optionid
     * @param int $userid
     * @return stdClass|null
     */
    private function get_answer(int $optionid, int $userid): ?stdClass {
        global $DB;
        $answers = $DB->get_records('booking_answers', ['optionid' => $optionid, 'userid' => $userid], 'id DESC', '*', 0, 1);
        return empty($answers) ? null : reset($answers);
    }

    /**
     * Completing the course completes the booking with the completion date of the course; a user without a
     * booking and a user on another course are untouched.
     *
     * @return void
     */
    public function test_course_completion_completes_booking_with_course_date(): void {
        global $DB;

        [$booking, $category] = $this->create_instance();
        [$course, $option] = $this->create_course_with_option($booking, $category);
        [$othercourse, $otheroption] = $this->create_course_with_option($booking, $category);
        $user = $this->getDataGenerator()->create_user();
        $unbooked = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($unbooked->id, $course->id, 'student');
        $this->book_user($option, $user);
        $this->book_user($otheroption, $user);
        $this->run_tasks_silently();

        $answer = $this->get_answer((int)$option->id, (int)$user->id);
        $this->assertNotNull($answer);
        $this->assertSame(0, (int)$answer->completed);

        $timecompleted = time() - 3 * DAYSECS;
        $this->complete_course($course, $user, $timecompleted);

        $answer = $this->get_answer((int)$option->id, (int)$user->id);
        $this->assertSame(1, (int)$answer->completed);
        $this->assertSame($timecompleted, (int)$answer->completeddate);
        // The other option of the user and the unbooked user are untouched.
        $this->assertSame(0, (int)$this->get_answer((int)$otheroption->id, (int)$user->id)->completed);
        $this->assertNull($this->get_answer((int)$option->id, (int)$unbooked->id));
        // The history records the completion like a completion by hand.
        $this->assertTrue($DB->record_exists('booking_history', [
            'optionid' => $option->id,
            'userid' => $user->id,
            'status' => MOD_BOOKING_STATUSPARAM_COMPLETION_CHANGED,
        ]));
    }

    /**
     * An option auto-created without a connected course is found through its marker, a manual option connected
     * to the course is completed too, and a completed booking keeps its date.
     *
     * @return void
     */
    public function test_unlinked_and_manual_options_and_existing_completion(): void {
        global $DB;

        [$booking, $category] = $this->create_instance(['autocreateoptionlinkcourse' => 0]);
        [$course, $option] = $this->create_course_with_option($booking, $category);
        $this->assertSame(0, (int)$option->courseid);

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $manual = $plugingenerator->create_option([
            'bookingid' => $booking->id,
            'text' => 'Made by hand',
            'chooseorcreatecourse' => 1,
            'courseid' => $course->id,
        ]);
        $this->assertEqualsCanonicalizing(
            [(int)$option->id, (int)$manual->id],
            autocreateoption::get_option_ids_for_course((int)$booking->id, (int)$course->id)
        );

        $user = $this->getDataGenerator()->create_user();
        $done = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($done->id, $course->id, 'student');
        $this->book_user($option, $user);
        $this->book_user($manual, $user);
        $this->book_user($option, $done);
        $this->run_tasks_silently();

        // Completed by hand before, with its own date.
        $handdate = time() - 10 * DAYSECS;
        $settings = singleton_service::get_instance_of_booking_option_settings((int)$option->id);
        $bookingoption = singleton_service::get_instance_of_booking_option((int)$settings->cmid, (int)$option->id);
        $bookingoption->toggle_user_completion((int)$done->id, 0, false, $handdate);
        singleton_service::destroy_instance();

        $timecompleted = time() - DAYSECS;
        $this->complete_course($course, $user, $timecompleted);
        $this->complete_course($course, $done, $timecompleted);

        foreach ([$option, $manual] as $completedoption) {
            $answer = $this->get_answer((int)$completedoption->id, (int)$user->id);
            $this->assertSame(1, (int)$answer->completed);
            $this->assertSame($timecompleted, (int)$answer->completeddate);
        }
        $answer = $this->get_answer((int)$option->id, (int)$done->id);
        $this->assertSame(1, (int)$answer->completed);
        $this->assertSame($handdate, (int)$answer->completeddate);
    }

    /**
     * With the setting off, the course completion changes nothing; the site-wide setting still works and does not
     * override the course date when both are on.
     *
     * @return void
     */
    public function test_setting_off_and_sitewide_setting(): void {
        [$booking, $category] = $this->create_instance(['autocreateoptionsynccompletion' => 0]);
        [$course, $option] = $this->create_course_with_option($booking, $category);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->book_user($option, $user);
        $this->run_tasks_silently();

        $this->complete_course($course, $user, time() - DAYSECS);
        $this->assertSame(0, (int)$this->get_answer((int)$option->id, (int)$user->id)->completed);

        // Site-wide setting on, instance setting off: completed with the current date, as before.
        set_config('automaticbookingoptioncompletion', 1, 'booking');
        $other = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($other->id, $course->id, 'student');
        $this->book_user($option, $other);
        $this->run_tasks_silently();
        $timecompleted = time() - DAYSECS;
        $this->complete_course($course, $other, $timecompleted);
        $answer = $this->get_answer((int)$option->id, (int)$other->id);
        $this->assertSame(1, (int)$answer->completed);
        $this->assertGreaterThan($timecompleted, (int)$answer->completeddate);

        // Both on: the course date wins.
        global $DB;
        $json = json_decode($DB->get_field('booking', 'json', ['id' => $booking->id]));
        $json->{autocreateoption::JSONKEY_SYNCCOMPLETION} = 1;
        $DB->set_field('booking', 'json', json_encode($json), ['id' => $booking->id]);
        booking::purge_cache_for_booking_instance_by_cmid((int)$booking->cmid);
        singleton_service::destroy_instance();
        $third = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($third->id, $course->id, 'student');
        $this->book_user($option, $third);
        $this->run_tasks_silently();
        $this->complete_course($course, $third, $timecompleted);
        $answer = $this->get_answer((int)$option->id, (int)$third->id);
        $this->assertSame(1, (int)$answer->completed);
        $this->assertSame($timecompleted, (int)$answer->completeddate);
    }

    /**
     * Users who completed the course before the enrolment sync booked them get their booking completed with the
     * course date by the enrolment sync task.
     *
     * @return void
     */
    public function test_backfill_by_enrolment_sync(): void {
        [$booking, $category] = $this->create_instance(['autocreateoptionsyncenrolments' => 1]);
        $course = $this->getDataGenerator()->create_course(['category' => $category->id, 'enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $timecompleted = time() - 5 * DAYSECS;
        // Completed while no option exists yet: the event finds nothing to complete.
        $this->complete_course($course, $user, $timecompleted);

        $this->run_tasks_silently();

        $optionids = autocreateoption::get_option_ids_for_course((int)$booking->id, (int)$course->id);
        $this->assertCount(1, $optionids);
        $answer = $this->get_answer((int)reset($optionids), (int)$user->id);
        $this->assertNotNull($answer);
        $this->assertSame(MOD_BOOKING_STATUSPARAM_BOOKED, (int)$answer->waitinglist);
        $this->assertSame(1, (int)$answer->completed);
        $this->assertSame($timecompleted, (int)$answer->completeddate);
    }

    /**
     * The manual category sync completes the bookings of users who completed their course before.
     *
     * @return void
     */
    public function test_backfill_by_category_sync(): void {
        [$booking, $category] = $this->create_instance();
        [$course, $option] = $this->create_course_with_option($booking, $category);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->book_user($option, $user);
        $this->run_tasks_silently();

        // Switched off while the course is completed, so the event changes nothing.
        $timecompleted = time() - 2 * DAYSECS;
        global $DB;
        $json = json_decode($DB->get_field('booking', 'json', ['id' => $booking->id]));
        unset($json->{autocreateoption::JSONKEY_SYNCCOMPLETION});
        $DB->set_field('booking', 'json', json_encode($json), ['id' => $booking->id]);
        booking::purge_cache_for_booking_instance_by_cmid((int)$booking->cmid);
        singleton_service::destroy_instance();
        $this->complete_course($course, $user, $timecompleted);
        $this->assertSame(0, (int)$this->get_answer((int)$option->id, (int)$user->id)->completed);
        $this->assertSame(0, completionsync::sync_course((int)$course->id));

        $json->{autocreateoption::JSONKEY_SYNCCOMPLETION} = 1;
        $DB->set_field('booking', 'json', json_encode($json), ['id' => $booking->id]);
        booking::purge_cache_for_booking_instance_by_cmid((int)$booking->cmid);
        singleton_service::destroy_instance();

        ob_start();
        try {
            $result = autocreateoption::sync_category((int)$booking->id);
        } finally {
            ob_end_clean();
        }
        $this->assertSame(1, $result['completed']);
        $answer = $this->get_answer((int)$option->id, (int)$user->id);
        $this->assertSame(1, (int)$answer->completed);
        $this->assertSame($timecompleted, (int)$answer->completeddate);
        // A second sync changes nothing.
        $this->assertSame(0, completionsync::sync_course((int)$course->id, (int)$booking->id));
    }
}
