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
 * Tests for the manual sync of a linked course category with a booking instance.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use core\task\manager;
use mod_booking\local\autocreateoption;
use mod_booking\local\sync\enrolmentsync;
use mod_booking\task\sync_autocreate_category_adhoc;
use mod_booking\utils\wb_payment;
use stdClass;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests for the manual sync of a linked course category with a booking instance ("Sync the linked course category now").
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\autocreateoption::sync_category
 * @covers \mod_booking\local\autocreateoption::get_category_sync_preview
 * @covers \mod_booking\task\sync_autocreate_category_adhoc
 */
final class autocreate_option_sync_category_test extends advanced_testcase {
    /**
     * Tests set up.
     *
     * The observers are not internal, so they are only delivered once the surrounding DB transaction has been
     * committed. PHPUnit wraps every test in a transaction, so the tests must reset by truncation instead.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        // Any earlier test may have frozen the mocked time of the mod_booking namespace in the past, see
        // tool_mocktesttime. Core keeps the real time, so the two must be aligned again.
        time_mock::set_mock_time(strtotime('now'));
        $this->preventResetByRollback();
        $this->setAdminUser();
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
     * Creates a booking instance in a course of its own category, linked to the given category.
     *
     * @param int $linkedcategoryid
     * @param array $extra additional instance settings
     * @return stdClass the instance record as returned by the generator (incl. id and cmid)
     */
    private function create_linked_instance(int $linkedcategoryid, array $extra = []): stdClass {
        $instancecategory = $this->getDataGenerator()->create_category();
        $instancecourse = $this->getDataGenerator()->create_course(['category' => $instancecategory->id]);
        $record = array_merge([
            'course' => $instancecourse->id,
            'autocreateoptioncategoryid' => $linkedcategoryid,
        ], $extra);
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        return $plugingenerator->create_instance($record);
    }

    /**
     * Writes keys into the JSON of a booking instance directly, bypassing the form, and refreshes the caches.
     *
     * @param stdClass $booking
     * @param array $keys key => value, null removes the key
     * @return void
     */
    private function set_instance_json(stdClass $booking, array $keys): void {
        global $DB;

        $record = $DB->get_record('booking', ['id' => $booking->id], 'id, json', MUST_EXIST);
        $json = empty($record->json) ? new stdClass() : json_decode($record->json);
        foreach ($keys as $key => $value) {
            if ($value === null) {
                unset($json->{$key});
            } else {
                $json->{$key} = $value;
            }
        }
        $DB->set_field('booking', 'json', json_encode($json), ['id' => $booking->id]);
        booking::purge_cache_for_booking_instance_by_cmid((int)$booking->cmid);
        singleton_service::destroy_instance();
    }

    /**
     * Runs the sync of the linked category, swallowing the task output.
     *
     * @param stdClass $booking
     * @return array{created:int, skipped:int, syncqueued:int}
     */
    private function sync_silently(stdClass $booking): array {
        ob_start();
        try {
            return autocreateoption::sync_category((int)$booking->id);
        } finally {
            ob_end_clean();
        }
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
     * The option of a booking instance that was created for a course, null if there is none.
     *
     * @param stdClass $booking
     * @param stdClass $course
     * @return stdClass|null
     */
    private function get_option(stdClass $booking, stdClass $course): ?stdClass {
        global $DB;
        $option = $DB->get_record('booking_options', ['bookingid' => $booking->id, 'courseid' => $course->id]);
        return $option ?: null;
    }

    /**
     * The course sync rule of an option.
     *
     * @param int $optionid
     * @return stdClass|false
     */
    private function get_rule(int $optionid) {
        global $DB;
        return $DB->get_record('booking_sync_rules', [
            'bookingoptionid' => $optionid,
            'sourcetype' => enrolmentsync::SOURCETYPE,
        ]);
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
     * Courses that exist before the category is linked get their options from the sync: hidden courses too, courses
     * with an option already, in subcategories or in other categories not. A second sync creates nothing.
     *
     * @return void
     */
    public function test_sync_creates_options_for_existing_courses(): void {
        global $DB;

        $category = $this->getDataGenerator()->create_category();
        $subcategory = $this->getDataGenerator()->create_category(['parent' => $category->id]);
        $othercategory = $this->getDataGenerator()->create_category();
        $course1 = $this->getDataGenerator()->create_course(['category' => $category->id, 'fullname' => 'Existing one']);
        $course2 = $this->getDataGenerator()->create_course(['category' => $category->id, 'fullname' => 'Existing two']);
        $hidden = $this->getDataGenerator()->create_course([
            'category' => $category->id,
            'fullname' => 'Hidden one',
            'visible' => 0,
        ]);
        $subcourse = $this->getDataGenerator()->create_course(['category' => $subcategory->id]);
        $othercourse = $this->getDataGenerator()->create_course(['category' => $othercategory->id]);

        // The instance is linked after the courses exist, so the observers never saw them.
        $booking = $this->create_linked_instance((int)$category->id);
        $this->assertCount(0, $DB->get_records('booking_options', ['bookingid' => $booking->id]));

        // One course has an option made by hand, connected to it.
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $manual = $plugingenerator->create_option([
            'bookingid' => $booking->id,
            'text' => 'Made by hand',
            'chooseorcreatecourse' => 1,
            'courseid' => $course2->id,
        ]);

        $preview = autocreateoption::get_category_sync_preview((int)$booking->id);
        $this->assertSame((int)$category->id, $preview['categoryid']);
        $this->assertSame(3, $preview['courses']);
        $this->assertSame(2, $preview['missing']);
        $this->assertFalse($preview['syncenrolments']);

        $result = $this->sync_silently($booking);
        $this->assertSame(['created' => 2, 'skipped' => 1, 'syncqueued' => 0, 'completed' => 0], $result);

        $options = $DB->get_records('booking_options', ['bookingid' => $booking->id]);
        $this->assertCount(3, $options);
        foreach ([$course1, $hidden] as $course) {
            $option = $this->get_option($booking, $course);
            $this->assertNotNull($option);
            $this->assertSame($course->fullname, $option->text);
            $json = json_decode($option->json);
            $this->assertSame((int)$course->id, (int)$json->{autocreateoption::OPTIONJSONKEY_SOURCECOURSE});
        }
        // The manual option is kept as it is, it gets no marker.
        $manualrecord = $DB->get_record('booking_options', ['id' => $manual->id], '*', MUST_EXIST);
        $this->assertSame('Made by hand', $manualrecord->text);
        $this->assertObjectNotHasProperty(
            autocreateoption::OPTIONJSONKEY_SOURCECOURSE,
            json_decode($manualrecord->json ?: '{}')
        );
        $this->assertNull($this->get_option($booking, $subcourse));
        $this->assertNull($this->get_option($booking, $othercourse));
        // No sync rules without the enrolment sync setting.
        $this->assertSame(0, $DB->count_records('booking_sync_rules', ['sourcetype' => enrolmentsync::SOURCETYPE]));

        $preview = autocreateoption::get_category_sync_preview((int)$booking->id);
        $this->assertSame(0, $preview['missing']);
        $result = $this->sync_silently($booking);
        $this->assertSame(['created' => 0, 'skipped' => 3, 'syncqueued' => 0, 'completed' => 0], $result);
        $this->assertCount(3, $DB->get_records('booking_options', ['bookingid' => $booking->id]));
    }

    /**
     * The button queues one task per instance, whatever the number of clicks, and the task does the sync.
     *
     * @return void
     */
    public function test_task_is_queued_once_and_syncs(): void {
        global $DB;

        $category = $this->getDataGenerator()->create_category();
        $course = $this->getDataGenerator()->create_course(['category' => $category->id]);
        $booking = $this->create_linked_instance((int)$category->id);

        foreach ([1, 2] as $click) {
            $task = new sync_autocreate_category_adhoc();
            $task->set_custom_data(['bookingid' => (int)$booking->id]);
            manager::queue_adhoc_task($task, true);
        }
        $this->assertCount(1, manager::get_adhoc_tasks(sync_autocreate_category_adhoc::class));

        $this->run_tasks_silently();
        $this->assertCount(0, manager::get_adhoc_tasks(sync_autocreate_category_adhoc::class));
        $option = $this->get_option($booking, $course);
        $this->assertNotNull($option);
        $this->assertSame($course->fullname, $option->text);
        $this->assertCount(1, $DB->get_records('booking_options', ['bookingid' => $booking->id]));
    }

    /**
     * With the enrolment sync on, the users already enrolled in the courses are booked into the created options,
     * owned by the course sync rules; course editors are not.
     *
     * @return void
     */
    public function test_sync_books_enrolled_users(): void {
        $category = $this->getDataGenerator()->create_category();
        $course1 = $this->getDataGenerator()->create_course(['category' => $category->id]);
        $course2 = $this->getDataGenerator()->create_course(['category' => $category->id]);
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student1->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher->id, $course1->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($student2->id, $course2->id, 'student');

        $booking = $this->create_linked_instance((int)$category->id, ['autocreateoptionsyncenrolments' => 1]);
        $preview = autocreateoption::get_category_sync_preview((int)$booking->id);
        $this->assertTrue($preview['syncenrolments']);

        $result = $this->sync_silently($booking);
        $this->assertSame(['created' => 2, 'skipped' => 0, 'syncqueued' => 2, 'completed' => 0], $result);
        $this->run_tasks_silently();

        $option1 = $this->get_option($booking, $course1);
        $option2 = $this->get_option($booking, $course2);
        $rule1 = $this->get_rule((int)$option1->id);
        $rule2 = $this->get_rule((int)$option2->id);
        $this->assertNotEmpty($rule1);
        $this->assertNotEmpty($rule2);
        $this->assertSame(1, (int)$rule1->isenabled);

        foreach ([$student1, $student2] as $student) {
            $answer = $this->get_answer((int)$option1->id, (int)$student->id);
            $this->assertNotNull($answer);
            $this->assertSame(MOD_BOOKING_STATUSPARAM_BOOKED, (int)$answer->waitinglist);
            $this->assertSame((int)$rule1->id, (int)$answer->syncruleid);
        }
        $this->assertNull($this->get_answer((int)$option1->id, (int)$teacher->id));
        $this->assertNull($this->get_answer((int)$option2->id, (int)$student1->id));
        $answer = $this->get_answer((int)$option2->id, (int)$student2->id);
        $this->assertNotNull($answer);
        $this->assertSame((int)$rule2->id, (int)$answer->syncruleid);
    }

    /**
     * An option auto-created while the enrolment sync was off gets its rule from the sync once the setting is on,
     * and the users enrolled in its course are booked.
     *
     * @return void
     */
    public function test_sync_gives_rule_to_options_created_before(): void {
        $category = $this->getDataGenerator()->create_category();
        $booking = $this->create_linked_instance((int)$category->id);

        // Created after the link, so the observer creates the option - without a rule, the setting is off.
        $course = $this->getDataGenerator()->create_course(['category' => $category->id]);
        $this->run_tasks_silently();
        $option = $this->get_option($booking, $course);
        $this->assertNotNull($option);
        $this->assertEmpty($this->get_rule((int)$option->id));

        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->run_tasks_silently();
        $this->assertNull($this->get_answer((int)$option->id, (int)$user->id));

        // Switched on directly, so the toggle task of the instance form does not run; the sync does the work.
        $this->set_instance_json($booking, [autocreateoption::JSONKEY_SYNCENROLMENTS => 1]);
        $result = $this->sync_silently($booking);
        $this->assertSame(['created' => 0, 'skipped' => 1, 'syncqueued' => 1, 'completed' => 0], $result);
        $this->run_tasks_silently();

        $rule = $this->get_rule((int)$option->id);
        $this->assertNotEmpty($rule);
        $answer = $this->get_answer((int)$option->id, (int)$user->id);
        $this->assertNotNull($answer);
        $this->assertSame((int)$rule->id, (int)$answer->syncruleid);
    }

    /**
     * With the enrolment sync off, the sync creates the options but books nobody and creates no rule.
     *
     * @return void
     */
    public function test_sync_respects_enrolment_setting_off(): void {
        global $DB;

        $category = $this->getDataGenerator()->create_category();
        $course = $this->getDataGenerator()->create_course(['category' => $category->id]);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $booking = $this->create_linked_instance((int)$category->id);

        $result = $this->sync_silently($booking);
        $this->assertSame(['created' => 1, 'skipped' => 0, 'syncqueued' => 0, 'completed' => 0], $result);
        $this->run_tasks_silently();

        $option = $this->get_option($booking, $course);
        $this->assertNotNull($option);
        $this->assertEmpty($this->get_rule((int)$option->id));
        $this->assertSame(0, $DB->count_records('booking_answers', ['optionid' => $option->id]));
    }

    /**
     * Nothing is synced without a linked category, without PRO, or when the instance lives in the linked category.
     *
     * @return void
     */
    public function test_sync_does_nothing_when_not_applicable(): void {
        global $DB;

        $category = $this->getDataGenerator()->create_category();
        $this->getDataGenerator()->create_course(['category' => $category->id]);
        $nothing = ['created' => 0, 'skipped' => 0, 'syncqueued' => 0, 'completed' => 0];

        // No category linked.
        $booking = $this->create_linked_instance((int)$category->id);
        $this->set_instance_json($booking, [autocreateoption::JSONKEY_CATEGORY => null]);
        $preview = autocreateoption::get_category_sync_preview((int)$booking->id);
        $this->assertSame(0, $preview['categoryid']);
        $this->assertSame($nothing, $this->sync_silently($booking));

        // No PRO.
        $this->set_instance_json($booking, [autocreateoption::JSONKEY_CATEGORY => (int)$category->id]);
        wb_payment::override_pro_version_for_tests(false);
        $this->assertSame($nothing, $this->sync_silently($booking));
        wb_payment::override_pro_version_for_tests(true);
        $this->assertCount(0, $DB->get_records('booking_options', ['bookingid' => $booking->id]));

        // The instance lives in the linked category (the form refuses this, so it is written directly).
        $instancecourse = $this->getDataGenerator()->create_course(['category' => $category->id]);
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $owncategorybooking = $plugingenerator->create_instance(['course' => $instancecourse->id]);
        $this->set_instance_json($owncategorybooking, [autocreateoption::JSONKEY_CATEGORY => (int)$category->id]);
        $this->assertSame($nothing, $this->sync_silently($owncategorybooking));
        $this->assertCount(0, $DB->get_records('booking_options', ['bookingid' => $owncategorybooking->id]));
    }
}
