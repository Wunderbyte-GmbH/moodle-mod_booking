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

    /**
     * Creates an auto-created option for a new course in a linked category.
     *
     * @return array [booking instance, course, option record]
     */
    private function create_autocreated_option(): array {
        global $DB;

        wb_payment::override_pro_version_for_tests(true);
        $category = $this->getDataGenerator()->create_category();
        $booking = $this->create_linked_instance((int)$category->id);
        $course = $this->getDataGenerator()->create_course(['category' => $category->id]);
        $this->run_tasks_silently();
        singleton_service::destroy_instance();
        $option = $DB->get_record('booking_options', ['bookingid' => $booking->id], '*', MUST_EXIST);
        $this->assertTrue($this->has_marker($option->json));
        return [$booking, $course, $option];
    }

    /**
     * Whether a JSON string carries the auto-creation marker.
     *
     * @param string|null $json
     * @return bool
     */
    private function has_marker(?string $json): bool {
        $json = empty($json) ? null : json_decode($json);
        return isset($json->{autocreateoption::OPTIONJSONKEY_SOURCECOURSE});
    }

    /**
     * Whether the stored option still carries the marker.
     *
     * @param int $optionid
     * @return bool
     */
    private function option_has_marker(int $optionid): bool {
        global $DB;
        return $this->has_marker($DB->get_field('booking_options', 'json', ['id' => $optionid], MUST_EXIST));
    }

    /**
     * Templates never carry the marker: neither copied with copytotemplate() nor saved via "add as template", and
     * a template that still carries one (saved before) does not pass it on.
     *
     * @return void
     */
    public function test_marker_is_not_copied_into_templates(): void {
        global $DB;

        [$booking, , $option] = $this->create_autocreated_option();
        $context = \context_module::instance((int)$booking->cmid);

        // Copy to template.
        $bo = singleton_service::get_instance_of_booking_option((int)$booking->cmid, (int)$option->id);
        $templateid = (int)$bo->copytotemplate();
        $this->assertSame(0, (int)$DB->get_field('booking_options', 'bookingid', ['id' => $templateid]));
        $this->assertFalse($this->option_has_marker($templateid));

        // Saved via "add as template" from the data of the option, as the option form does.
        $data = (object)[
            'cmid' => (int)$booking->cmid,
            'id' => (int)$option->id,
            'optionid' => (int)$option->id,
            'bookingid' => (int)$booking->id,
            'copyoptionid' => 0,
            'oldcopyoptionid' => 0,
            'returnurl' => '',
        ];
        option\fields_info::set_data($data);
        $this->assertTrue($this->has_marker($data->json));
        $data->id = 0;
        $data->optionid = 0;
        $data->identifier = '';
        $data->addastemplate = 1;
        $data->templatename = 'From auto-created option';
        foreach (array_keys((array)$data) as $key) {
            if (strpos($key, MOD_BOOKING_FORM_OPTIONDATEID) !== false) {
                $data->{$key} = 0;
            }
        }
        $savedid = (int)booking_option::update($data, $context);
        $this->assertSame(0, (int)$DB->get_field('booking_options', 'bookingid', ['id' => $savedid]));
        $this->assertFalse($this->option_has_marker($savedid));

        // A template that still carries the marker does not pass it on when applied in the option form.
        $json = json_decode($DB->get_field('booking_options', 'json', ['id' => $templateid]));
        $json->{autocreateoption::OPTIONJSONKEY_SOURCECOURSE} = 12345;
        $DB->set_field('booking_options', 'json', json_encode($json), ['id' => $templateid]);
        \cache_helper::purge_by_event('setbackoptionsettings');
        singleton_service::destroy_instance();

        $formdata = (object)[
            'id' => 0,
            'cmid' => (int)$booking->cmid,
            'bookingid' => (int)$booking->id,
            'btn_changetemplate' => 1,
            'optiontemplateid' => $templateid,
        ];
        try {
            option\fields\template::set_data($formdata, singleton_service::get_instance_of_booking_option_settings(0));
            $this->fail('Applying a template reloads the form with an exception.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorloadingtemplate', $e->errorcode);
        }
        $this->assertFalse($this->has_marker($formdata->json));

        // The auto-created option itself keeps it.
        $this->assertTrue($this->option_has_marker((int)$option->id));
    }

    /**
     * Duplicating an auto-created option does not copy the marker.
     *
     * @return void
     */
    public function test_marker_is_not_copied_by_duplication(): void {
        [$booking, , $option] = $this->create_autocreated_option();

        $formdata = (object)[
            'id' => 0,
            'cmid' => (int)$booking->cmid,
            'bookingid' => (int)$booking->id,
            'copyoptionid' => (int)$option->id,
        ];
        try {
            option\fields\duplication::set_data($formdata, singleton_service::get_instance_of_booking_option_settings(0));
            $this->fail('Duplicating reloads the form with an exception.');
        } catch (\moodle_exception $e) {
            $this->assertSame('loadtemplate', $e->errorcode);
        }
        $this->assertSame($option->text, $formdata->text);
        $this->assertFalse($this->has_marker($formdata->json));
        $this->assertTrue($this->option_has_marker((int)$option->id));
    }

    /**
     * Recurring children and the options split from the dates of an auto-created option do not get the marker.
     *
     * @return void
     */
    public function test_marker_is_not_copied_to_recurring_or_split_options(): void {
        global $DB;

        [$booking, , $option] = $this->create_autocreated_option();
        $start = strtotime('+1 week 10:00');

        // Give the option two dates and repeat it twice.
        $record = (object)[
            'id' => (int)$option->id,
            'cmid' => (int)$booking->cmid,
            'bookingid' => (int)$booking->id,
            'text' => $option->text,
            'importing' => 1,
            'optiondateid_1' => 0,
            'daystonotify_1' => 0,
            'coursestarttime_1' => $start,
            'courseendtime_1' => $start + HOURSECS,
            'optiondateid_2' => 0,
            'daystonotify_2' => 0,
            'coursestarttime_2' => $start + DAYSECS,
            'courseendtime_2' => $start + DAYSECS + HOURSECS,
            'repeatthisbooking' => 1,
            'howmanytimestorepeat' => 2,
            'howoftentorepeat' => WEEKSECS,
            'requirepreviousoptionstobebooked' => 0,
        ];
        booking_option::update($record);
        singleton_service::destroy_instance();

        $children = $DB->get_records('booking_options', ['bookingid' => $booking->id, 'parentid' => $option->id]);
        $this->assertCount(2, $children);
        foreach ($children as $child) {
            $this->assertFalse($this->has_marker($child->json));
        }
        $this->assertTrue($this->option_has_marker((int)$option->id));

        // Split the option into one option per date.
        $before = array_keys($DB->get_records('booking_options', ['bookingid' => $booking->id], '', 'id'));
        $bo = singleton_service::get_instance_of_booking_option((int)$booking->cmid, (int)$option->id);
        $bo->create_booking_options_from_optiondates();
        $new = $DB->get_records_select(
            'booking_options',
            'bookingid = :bookingid AND id NOT IN (' . implode(',', $before) . ')',
            ['bookingid' => $booking->id]
        );
        $this->assertCount(1, $new);
        $this->assertFalse($this->has_marker(reset($new)->json));
        $this->assertTrue($this->option_has_marker((int)$option->id));
    }

    /**
     * Options restored from a backup (here: duplicating the booking instance) do not carry the marker.
     *
     * @return void
     */
    public function test_marker_is_not_restored(): void {
        global $DB;

        [$booking, , $option] = $this->create_autocreated_option();
        $instancecourse = get_course((int)$booking->course);
        $cm = get_fast_modinfo($instancecourse)->get_cm($booking->cmid);
        // Moodle 5.2 deprecated duplicate_module() (MDL-86858); 4.5-5.1 have no cmactions::duplicate() yet.
        $newcm = method_exists(\core_courseformat\local\cmactions::class, 'duplicate')
            ? \core_courseformat\formatactions::cm($instancecourse->id)->duplicate($cm->id)
            : duplicate_module($instancecourse, $cm);
        $this->run_tasks_silently();

        $restored = $DB->get_records('booking_options', ['bookingid' => $newcm->instance]);
        $this->assertCount(1, $restored);
        $this->assertFalse($this->has_marker(reset($restored)->json));
        $this->assertTrue($this->option_has_marker((int)$option->id));
    }
}
