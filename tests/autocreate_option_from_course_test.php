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
 * Tests for the automatic creation of booking options for new courses in a linked course category.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use backup;
use context_course;
use core\task\manager;
use mod_booking\local\autocreateoption;
use mod_booking\task\create_option_for_new_course;
use mod_booking\utils\wb_payment;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');

/**
 * Tests for the automatic creation of booking options for new courses in a linked course category.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\autocreateoption
 * @covers \mod_booking\task\create_option_for_new_course
 */
final class autocreate_option_from_course_test extends advanced_testcase {
    /**
     * Tests set up.
     *
     * The observers are registered as not internal, so they are only delivered once the surrounding DB
     * transaction has been committed. PHPUnit wraps every test in a transaction, so the tests must reset by
     * truncation instead. The adhoc tasks are executed directly by the test case, without the cron user
     * setup, so the admin user is needed to get the full option form field set.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->preventResetByRollback();
        $this->setAdminUser();
        singleton_service::destroy_instance();
        autocreateoption::purge_map();
    }

    /**
     * Tests tear down.
     */
    public function tearDown(): void {
        wb_payment::override_pro_version_for_tests(null);
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
     * Runs all queued adhoc tasks, swallowing their mtrace output.
     *
     * @return void
     */
    private function run_tasks_silently(): void {
        ob_start();
        try {
            $this->run_all_adhoc_tasks();
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Queued tasks of the auto creation.
     *
     * @return array
     */
    private function queued_tasks(): array {
        return manager::get_adhoc_tasks(create_option_for_new_course::class);
    }

    /**
     * Triggers a course_restored event by hand.
     *
     * @param stdClass $course
     * @param int $target
     * @param int $mode
     * @param int $originalcourseid
     * @return void
     */
    private function trigger_course_restored(stdClass $course, int $target, int $mode, int $originalcourseid = 0): void {
        $other = [
            'type' => backup::TYPE_1COURSE,
            'target' => $target,
            'mode' => $mode,
            'operation' => backup::OPERATION_RESTORE,
            'samesite' => true,
            'settings' => [],
        ];
        if ($originalcourseid) {
            $other['originalcourseid'] = $originalcourseid;
        }
        $event = \core\event\course_restored::create([
            'objectid' => $course->id,
            'context' => context_course::instance($course->id),
            'other' => $other,
        ]);
        $event->trigger();
    }

    /**
     * A course created in the linked category gets exactly one option, named after the course and connected to it.
     *
     * @return void
     */
    public function test_option_is_created_for_new_course_without_template(): void {
        global $DB;

        $category = $this->getDataGenerator()->create_category();
        $booking = $this->create_linked_instance((int)$category->id);

        // The settings went through booking_add_instance() into the JSON.
        $this->assertSame(
            (int)$category->id,
            (int)booking::get_value_of_json_by_key((int)$booking->id, autocreateoption::JSONKEY_CATEGORY)
        );
        $this->assertNull(booking::get_value_of_json_by_key((int)$booking->id, autocreateoption::JSONKEY_TEMPLATE));
        $this->assertSame(
            1,
            (int)booking::get_value_of_json_by_key((int)$booking->id, autocreateoption::JSONKEY_LINKCOURSE)
        );
        $this->assertCount(0, $this->queued_tasks());

        $course = $this->getDataGenerator()->create_course([
            'category' => $category->id,
            'fullname' => 'Advanced Welding 2026',
        ]);
        $this->assertCount(1, $this->queued_tasks());

        $coursecount = $DB->count_records('course');
        $this->run_tasks_silently();

        $options = $DB->get_records('booking_options', ['bookingid' => $booking->id]);
        $this->assertCount(1, $options);
        $option = reset($options);
        $this->assertSame('Advanced Welding 2026', $option->text);
        $this->assertSame((int)$course->id, (int)$option->courseid);
        $json = json_decode($option->json);
        $this->assertSame((int)$course->id, (int)$json->{autocreateoption::OPTIONJSONKEY_SOURCECOURSE});
        // The automated path never creates courses.
        $this->assertSame($coursecount, $DB->count_records('course'));

        // A second delivery for the same course (e.g. created + restored) does not create a second option.
        $task = new create_option_for_new_course();
        $task->set_custom_data(['courseid' => (int)$course->id, 'bookingid' => (int)$booking->id]);
        manager::queue_adhoc_task($task);
        $this->run_tasks_silently();
        $this->assertCount(1, $DB->get_records('booking_options', ['bookingid' => $booking->id]));
    }

    /**
     * The option is pre-filled from the configured option template.
     *
     * @return void
     */
    public function test_option_is_created_from_template(): void {
        global $DB;

        $category = $this->getDataGenerator()->create_category();
        $booking = $this->create_linked_instance((int)$category->id);

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $template = $plugingenerator->create_template([
            'bookingid' => $booking->id,
            'templatename' => 'Welding template',
            'text' => 'Template option',
            'description' => 'Bring your own helmet',
            'maxanswers' => 7,
        ]);
        $templaterecord = $DB->get_record('booking_options', ['id' => $template->id], '*', MUST_EXIST);
        $this->assertSame(0, (int)$templaterecord->bookingid);

        $this->set_instance_json($booking, [autocreateoption::JSONKEY_TEMPLATE => (int)$template->id]);

        $course = $this->getDataGenerator()->create_course([
            'category' => $category->id,
            'fullname' => 'Welding for beginners',
        ]);
        $this->assertCount(1, $this->queued_tasks());
        $this->run_tasks_silently();

        $options = $DB->get_records('booking_options', ['bookingid' => $booking->id]);
        $this->assertCount(1, $options);
        $option = reset($options);
        $this->assertSame('Welding for beginners', $option->text);
        $this->assertSame((int)$course->id, (int)$option->courseid);
        $this->assertSame(7, (int)$option->maxanswers);
        $this->assertStringContainsString('Bring your own helmet', (string)$option->description);
        $this->assertNotSame($templaterecord->identifier, $option->identifier);
        $json = json_decode($option->json);
        $this->assertObjectNotHasProperty('templatename', $json);
        $this->assertSame((int)$course->id, (int)$json->{autocreateoption::OPTIONJSONKEY_SOURCECOURSE});

        // The template itself is untouched.
        $templaterecord = $DB->get_record('booking_options', ['id' => $template->id], '*', MUST_EXIST);
        $this->assertSame(0, (int)$templaterecord->bookingid);
        $this->assertSame('Template option', $templaterecord->text);
    }

    /**
     * Courses in other categories, in subcategories, or without PRO queue nothing.
     *
     * @return void
     */
    public function test_nothing_is_queued_for_unrelated_courses(): void {
        $category = $this->getDataGenerator()->create_category();
        $othercategory = $this->getDataGenerator()->create_category();
        $subcategory = $this->getDataGenerator()->create_category(['parent' => $category->id]);
        $this->create_linked_instance((int)$category->id);

        $this->getDataGenerator()->create_course(['category' => $othercategory->id]);
        $this->assertCount(0, $this->queued_tasks());

        // Exact match only: subcategories do not count.
        $this->getDataGenerator()->create_course(['category' => $subcategory->id]);
        $this->assertCount(0, $this->queued_tasks());

        // PRO only.
        wb_payment::override_pro_version_for_tests(false);
        $this->getDataGenerator()->create_course(['category' => $category->id]);
        $this->assertCount(0, $this->queued_tasks());

        wb_payment::override_pro_version_for_tests(true);
        $this->getDataGenerator()->create_course(['category' => $category->id]);
        $this->assertCount(1, $this->queued_tasks());
    }

    /**
     * course_restored only counts when the restore created a new course, and booking's own course copies are skipped.
     *
     * @return void
     */
    public function test_course_restored_is_filtered(): void {
        global $DB;

        $category = $this->getDataGenerator()->create_category();
        $booking = $this->create_linked_instance((int)$category->id);

        $course = $this->getDataGenerator()->create_course(['category' => $category->id]);
        // Forget the task of the course creation itself.
        $DB->delete_records('task_adhoc');

        // Restore into an existing course (e.g. upload course tool): ignored.
        $this->trigger_course_restored($course, backup::TARGET_CURRENT_ADDING, backup::MODE_IMPORT);
        $this->assertCount(0, $this->queued_tasks());

        // Restore as a new course: handled.
        $this->trigger_course_restored($course, backup::TARGET_NEW_COURSE, backup::MODE_GENERAL);
        $this->assertCount(1, $this->queued_tasks());
        $DB->delete_records('task_adhoc');

        // A course copy that booking started for one of its own options: the copied course is referenced by an
        // option of the instance, so the copy is skipped.
        $sourcecourse = $this->getDataGenerator()->create_course();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->create_option([
            'bookingid' => $booking->id,
            'text' => 'Option with connected course',
            'courseid' => $sourcecourse->id,
        ]);
        $DB->delete_records('task_adhoc');
        $this->trigger_course_restored($course, backup::TARGET_NEW_COURSE, backup::MODE_COPY, (int)$sourcecourse->id);
        $this->assertCount(0, $this->queued_tasks());

        // A copy of some other course is handled.
        $othercourse = $this->getDataGenerator()->create_course();
        $DB->delete_records('task_adhoc');
        $this->trigger_course_restored($course, backup::TARGET_NEW_COURSE, backup::MODE_COPY, (int)$othercourse->id);
        $this->assertCount(1, $this->queued_tasks());
    }

    /**
     * Without the link checkbox the option is created without a connected course, still only once.
     *
     * @return void
     */
    public function test_option_without_course_link(): void {
        global $DB;

        $category = $this->getDataGenerator()->create_category();
        $booking = $this->create_linked_instance((int)$category->id, ['autocreateoptionlinkcourse' => 0]);
        $this->assertSame(
            0,
            (int)booking::get_value_of_json_by_key((int)$booking->id, autocreateoption::JSONKEY_LINKCOURSE)
        );

        $course = $this->getDataGenerator()->create_course(['category' => $category->id, 'fullname' => 'Unlinked']);
        $this->run_tasks_silently();

        $options = $DB->get_records('booking_options', ['bookingid' => $booking->id]);
        $this->assertCount(1, $options);
        $option = reset($options);
        $this->assertSame('Unlinked', $option->text);
        $this->assertSame(0, (int)$option->courseid);
        $json = json_decode($option->json);
        $this->assertSame((int)$course->id, (int)$json->{autocreateoption::OPTIONJSONKEY_SOURCECOURSE});

        // Running the task again must not create a second option, even without the course link.
        $task = new create_option_for_new_course();
        $task->set_custom_data(['courseid' => (int)$course->id, 'bookingid' => (int)$booking->id]);
        manager::queue_adhoc_task($task);
        $this->run_tasks_silently();
        $this->assertCount(1, $DB->get_records('booking_options', ['bookingid' => $booking->id]));
    }

    /**
     * Settings survive a programmatic update that does not contain them, and are removed when switched off.
     *
     * @return void
     */
    public function test_settings_are_kept_on_programmatic_update_and_removed_when_disabled(): void {
        global $DB;

        $category = $this->getDataGenerator()->create_category();
        $booking = $this->create_linked_instance((int)$category->id);

        // A DB-shaped record without the form keys, as instance templates and the wizard pass it.
        $record = $DB->get_record('booking', ['id' => $booking->id], '*', MUST_EXIST);
        $record->instance = $record->id;
        $record->coursemodule = $booking->cmid;
        booking_update_instance($record);
        singleton_service::destroy_instance();
        $this->assertSame(
            (int)$category->id,
            (int)booking::get_value_of_json_by_key((int)$booking->id, autocreateoption::JSONKEY_CATEGORY)
        );
        $this->assertSame(
            1,
            (int)booking::get_value_of_json_by_key((int)$booking->id, autocreateoption::JSONKEY_LINKCOURSE)
        );

        // Switching the feature off removes all keys and the course no longer triggers anything.
        $record = $DB->get_record('booking', ['id' => $booking->id], '*', MUST_EXIST);
        $record->instance = $record->id;
        $record->coursemodule = $booking->cmid;
        $record->autocreateoptioncategoryid = 0;
        $record->autocreateoptiontemplateid = 5;
        $record->autocreateoptionlinkcourse = 1;
        booking_update_instance($record);
        singleton_service::destroy_instance();
        $this->assertNull(booking::get_value_of_json_by_key((int)$booking->id, autocreateoption::JSONKEY_CATEGORY));
        $this->assertNull(booking::get_value_of_json_by_key((int)$booking->id, autocreateoption::JSONKEY_TEMPLATE));
        $this->assertNull(booking::get_value_of_json_by_key((int)$booking->id, autocreateoption::JSONKEY_LINKCOURSE));

        $this->getDataGenerator()->create_course(['category' => $category->id]);
        $this->assertCount(0, $this->queued_tasks());
    }

    /**
     * Settings changed between queueing and execution are honoured, and a deleted course is handled.
     *
     * @return void
     */
    public function test_task_revalidates_at_execution_time(): void {
        global $DB;

        $category = $this->getDataGenerator()->create_category();
        $booking = $this->create_linked_instance((int)$category->id);

        $course = $this->getDataGenerator()->create_course(['category' => $category->id]);
        $this->assertCount(1, $this->queued_tasks());

        // The link is removed before cron runs.
        $this->set_instance_json($booking, [autocreateoption::JSONKEY_CATEGORY => null]);
        $this->run_tasks_silently();
        $this->assertCount(0, $DB->get_records('booking_options', ['bookingid' => $booking->id]));

        // A deleted course is simply skipped.
        $this->set_instance_json($booking, [autocreateoption::JSONKEY_CATEGORY => (int)$category->id]);
        $task = new create_option_for_new_course();
        $task->set_custom_data(['courseid' => (int)$course->id + 1000, 'bookingid' => (int)$booking->id]);
        manager::queue_adhoc_task($task);
        $this->run_tasks_silently();
        $this->assertCount(0, $DB->get_records('booking_options', ['bookingid' => $booking->id]));
    }

    /**
     * Loop prevention: an instance whose own course sits in the linked category never creates options.
     *
     * @return void
     */
    public function test_instance_in_linked_category_creates_nothing(): void {
        global $DB;

        $category = $this->getDataGenerator()->create_category();
        $instancecourse = $this->getDataGenerator()->create_course(['category' => $category->id]);
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance(['course' => $instancecourse->id]);
        // The form refuses this, so it is written directly.
        $this->set_instance_json($booking, [autocreateoption::JSONKEY_CATEGORY => (int)$category->id]);

        $this->getDataGenerator()->create_course(['category' => $category->id]);
        $this->run_tasks_silently();
        $this->assertCount(0, $DB->get_records('booking_options', ['bookingid' => $booking->id]));
    }

    /**
     * The observer costs no DB read when no instance links a category, and one read otherwise.
     *
     * @return void
     */
    public function test_observer_is_cheap(): void {
        global $DB;

        $category = $this->getDataGenerator()->create_category();
        $course = $this->getDataGenerator()->create_course(['category' => $category->id]);

        // No instance uses the feature: warm the cache once, then no reads at all.
        autocreateoption::get_category_map();
        $reads = $DB->perf_get_reads();
        $this->assertSame([], autocreateoption::handle_new_course((int)$course->id));
        $this->assertSame($reads, $DB->perf_get_reads());

        // An instance links another category: one read for the category of the course.
        $this->create_linked_instance((int)$this->getDataGenerator()->create_category()->id);
        autocreateoption::get_category_map();
        // The PRO check reads the plugin config once per request, that is not a cost of the observer.
        wb_payment::pro_version_is_activated();
        $reads = $DB->perf_get_reads();
        $this->assertSame([], autocreateoption::handle_new_course((int)$course->id));
        $this->assertLessThanOrEqual($reads + 1, $DB->perf_get_reads());
    }
}
