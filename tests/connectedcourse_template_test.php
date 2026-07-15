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
 * Tests for creating a connected course from a template.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use core_tag_tag;
use mod_booking\local\connectedcourse;
use mod_booking\task\finalize_template_course;
use stdClass;

/**
 * Tests for \mod_booking\local\connectedcourse course-from-template creation.
 *
 * The duplication used to switch the global $USER to the admin user around a synchronous
 * core_course_external::duplicate_course() call. Because $USER is bound to the session by
 * reference, a failure before the restore persisted the admin user into the editing user's
 * session. These tests pin down that the duplication is now asynchronous and never touches
 * the session user, including on failure.
 *
 * @package mod_booking
 * @category test
 * @covers \mod_booking\local\connectedcourse::create_course_from_template_course
 * @covers \mod_booking\task\finalize_template_course
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class connectedcourse_template_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
    }

    /**
     * The happy path: a course shell is created and linked immediately, the heavy duplication is
     * deferred to adhoc tasks, and the session user is never swapped.
     */
    public function test_create_course_from_template_queues_tasks_and_keeps_user(): void {
        global $DB, $USER;

        $this->setAdminUser();
        $beforeuserid = $USER->id;

        // A course to copy and the target category for the copy.
        $template = $this->getDataGenerator()->create_course(['shortname' => 'tmplsrc', 'fullname' => 'Template source']);
        $category = $this->getDataGenerator()->create_category(['name' => 'TemplateTargetCat']);

        // Make the target category deterministic via the custom field branch of retrieve_categoryid()
        // by passing a numeric (already existing) category id, so no category lookup is needed.
        set_config('newcoursecategorycfield', 'coursecat', 'booking');

        $newoption = new stdClass();
        $formdata = new stdClass();
        $formdata->coursetemplateid = $template->id;
        $formdata->text = 'My templated option';
        $formdata->titleprefix = 'PRE';
        $formdata->createnewmoodlecoursefromtemplatewithusers = 0;
        $formdata->customfield_coursecat = $category->id;

        connectedcourse::create_course_from_template_course($newoption, $formdata);

        // A new course shell exists, is linked to the option, and is not the template itself.
        $this->assertNotEmpty($newoption->courseid);
        $this->assertEquals($newoption->courseid, $formdata->courseid);
        $this->assertTrue($DB->record_exists('course', ['id' => $newoption->courseid]));
        $this->assertNotEquals($template->id, $newoption->courseid);
        $this->assertEquals($category->id, (int) $DB->get_field('course', 'category', ['id' => $newoption->courseid]));

        // The privileged backup/restore is deferred to the core async copy task...
        $copytasks = \core\task\manager::get_adhoc_tasks(\core\task\asynchronous_copy_task::class);
        $this->assertCount(1, $copytasks);
        // ...and our finalizer task is queued to strip the inherited template tags afterwards.
        $finalizetasks = \core\task\manager::get_adhoc_tasks(finalize_template_course::class);
        $this->assertCount(1, $finalizetasks);

        // The session user was never swapped.
        $this->assertEquals($beforeuserid, $USER->id);
    }

    /**
     * Regression guard for the session-takeover bug: if the duplication fails, the interactive
     * user's session must NOT be left switched to the admin user.
     *
     * Against the pre-fix code this assertion fails: the method set $USER = get_admin() and only
     * restored it on the success path, so an exception from duplicate_course() left the (reference
     * bound) session as admin. With the fix the global $USER is never touched, so it holds.
     */
    public function test_session_user_unchanged_when_copy_fails(): void {
        global $USER;

        // Make category retrieval capability-free and deterministic (returns $COURSE->category).
        set_config('newcoursecategorycfield', 'currentcategory', 'booking');

        // Act as a regular, non-admin user so a leak to the admin user would be detectable.
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->assertNotEquals(get_admin()->id, $USER->id);
        $beforeuserid = $USER->id;

        // Point the copy at a course that does not exist, so the duplication must fail.
        $deleted = $this->getDataGenerator()->create_course();
        $missingcourseid = $deleted->id;
        delete_course($missingcourseid, false);

        $newoption = new stdClass();
        $formdata = new stdClass();
        $formdata->coursetemplateid = $missingcourseid;
        $formdata->text = 'Doomed option';
        $formdata->titleprefix = '';
        $formdata->createnewmoodlecoursefromtemplatewithusers = 0;

        $threw = false;
        try {
            connectedcourse::create_course_from_template_course($newoption, $formdata);
        } catch (\Throwable $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'A duplication from a non-existent template course must fail.');

        // The crux: the failure must not have switched the session to the admin user.
        $this->assertEquals(
            $beforeuserid,
            $USER->id,
            'The interactive user must remain unchanged after a failed course duplication.'
        );
        $this->assertNotEquals(
            get_admin()->id,
            $USER->id,
            'The session must not be left logged in as the admin user.'
        );
    }

    /**
     * The finalizer task strips the template tags that the async restore copies onto the new course.
     */
    public function test_finalize_template_course_strips_tags(): void {
        $this->setAdminUser();

        $this->getDataGenerator()->create_tag(['name' => 'tmpltag', 'isstandard' => 1]);
        $course = $this->getDataGenerator()->create_course(['tags' => ['tmpltag']]);

        // Sanity: the duplicated course starts out carrying the template tag.
        $this->assertNotEmpty(core_tag_tag::get_item_tags('core', 'course', $course->id));

        $task = new finalize_template_course();
        $task->set_custom_data(['newcourseid' => $course->id]);

        ob_start();
        $task->execute();
        ob_get_clean();

        $this->assertEmpty(core_tag_tag::get_item_tags('core', 'course', $course->id));
    }

    /**
     * The finalizer task is a no-op (no exception) when the target course no longer exists.
     */
    public function test_finalize_template_course_handles_missing_course(): void {
        $this->setAdminUser();

        $task = new finalize_template_course();
        $task->set_custom_data(['newcourseid' => 987654]);

        ob_start();
        $task->execute();
        $output = ob_get_clean();

        $this->assertStringContainsString('no longer exists', $output);
    }

    /**
     * End-to-end: an option teacher must end up enrolled in the asynchronously duplicated course.
     *
     * The teacher is enrolled into the (empty) course shell when the option is saved, the async
     * restore then rebuilds the course's enrolment instances and drops that enrolment, and the
     * finalizer task re-enrols the teacher. Asserting the enrolment after the adhoc tasks run
     * exercises that whole chain.
     */
    public function test_teacher_reenrolled_in_async_duplicated_template_course(): void {
        global $DB;

        $this->setAdminUser();

        // Role used to enrol teachers into the connected course.
        $teacherroleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        // A tagged template course with a page activity, used to confirm the async copy actually ran.
        $tag = $this->getDataGenerator()->create_tag(['name' => 'tmpltag', 'isstandard' => 1]);
        $templatecourse = $this->getDataGenerator()->create_course(['tags' => ['tmpltag']]);
        $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $templatecourse->id,
            'name' => 'Template page',
        ]);
        set_config('templatetags', $tag->id, 'booking');

        // Booking instance with teacherroleid set, so teachers are enrolled into the connected course.
        $bdata = [
            'name' => 'Teacher enrol booking',
            'eventtype' => 'Test event',
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
            'course' => $course->id,
            'bookingmanager' => $bookingmanager->username,
            'teacherroleid' => $teacherroleid,
        ];
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $taggedcourses = connectedcourse::return_tagged_template_courses();
        $taggedcourse = reset($taggedcourses);

        // Create a booking option from the template, with a teacher assigned to it.
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Templated option with teacher';
        $record->chooseorcreatecourse = 3; // Create new Moodle course from template.
        $record->coursetemplateid = $taggedcourse->id;
        $record->teachersforoption = $teacher->username;
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('now + 3 days');
        $record->courseendtime_0 = strtotime('now + 6 days');

        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $newcourseid = (int) $settings->courseid;
        $this->assertNotEmpty($newcourseid);
        $this->assertNotEquals($templatecourse->id, $newcourseid);

        // Run the async copy task and the finalizer task.
        ob_start();
        $this->runAdhocTasks();
        ob_get_clean();

        // The copy actually ran: the template's page activity now exists in the duplicated course.
        $modinfo = get_fast_modinfo($newcourseid);
        $this->assertArrayHasKey(
            'page',
            $modinfo->get_instances(),
            'The async copy should have populated the duplicated course.'
        );

        // The teacher, whose shell enrolment the restore dropped, is re-enrolled by the finalizer.
        $coursecontext = \context_course::instance($newcourseid);
        $this->assertTrue(
            is_enrolled($coursecontext, $teacher->id),
            'The option teacher must be enrolled in the duplicated template course after finalization.'
        );
    }

    /**
     * The "with users" checkbox must transfer the template course's own enrolled users into the copy;
     * without it, only the booking option's users are present.
     *
     * @param bool $withusers
     * @dataProvider with_users_provider
     */
    public function test_template_users_transferred_only_when_withusers_checked(bool $withusers): void {
        $this->setAdminUser();

        // Template course (tagged) with a page activity and a user enrolled directly in it.
        $tag = $this->getDataGenerator()->create_tag(['name' => 'tmpltag', 'isstandard' => 1]);
        $templatecourse = $this->getDataGenerator()->create_course(['tags' => ['tmpltag']]);
        $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $templatecourse->id,
            'name' => 'Template page',
        ]);
        $templatestudent = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($templatestudent->id, $templatecourse->id, 'student');
        set_config('templatetags', $tag->id, 'booking');

        $course = $this->getDataGenerator()->create_course();
        $bookingmanager = $this->getDataGenerator()->create_user();
        $bdata = [
            'name' => 'With-users booking',
            'eventtype' => 'Test event',
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
            'course' => $course->id,
            'bookingmanager' => $bookingmanager->username,
        ];
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $taggedcourses = connectedcourse::return_tagged_template_courses();
        $taggedcourse = reset($taggedcourses);

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Templated option';
        $record->chooseorcreatecourse = 3; // Create new Moodle course from template.
        $record->coursetemplateid = $taggedcourse->id;
        $record->createnewmoodlecoursefromtemplatewithusers = $withusers ? 1 : 0;
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('now + 3 days');
        $record->courseendtime_0 = strtotime('now + 6 days');

        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $newcourseid = (int) $settings->courseid;
        $this->assertNotEmpty($newcourseid);

        ob_start();
        $this->runAdhocTasks();
        ob_get_clean();

        // Sanity: the copy ran.
        $modinfo = get_fast_modinfo($newcourseid);
        $this->assertArrayHasKey('page', $modinfo->get_instances());

        // The template's own enrolled student is present only when "with users" was ticked.
        $coursecontext = \context_course::instance($newcourseid);
        $this->assertEquals(
            $withusers,
            is_enrolled($coursecontext, $templatestudent->id),
            $withusers
                ? 'Template course users must be transferred when "with users" is checked.'
                : 'Template course users must NOT be transferred when "with users" is unchecked.'
        );
    }

    /**
     * Two options created from the same template with an identical title must both end up with a
     * course named exactly after the option.
     *
     * Core's async restore forces the course fullname to be unique via
     * restore_dbops::calculate_course_names(), appending a " copy N" suffix as soon as another course
     * already carries that fullname. The finalizer resets it back to the intended name, so both
     * duplicated courses share the exact same fullname (while keeping distinct, unique shortnames).
     */
    public function test_duplicate_option_name_keeps_exact_course_fullname(): void {
        global $DB;

        $this->setAdminUser();

        // A tagged template course with a page activity, so we can confirm the async copy actually ran.
        $tag = $this->getDataGenerator()->create_tag(['name' => 'tmpltag', 'isstandard' => 1]);
        $templatecourse = $this->getDataGenerator()->create_course(['tags' => ['tmpltag']]);
        $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $templatecourse->id,
            'name' => 'Template page',
        ]);
        set_config('templatetags', $tag->id, 'booking');

        $course = $this->getDataGenerator()->create_course();
        $bookingmanager = $this->getDataGenerator()->create_user();
        $bdata = [
            'name' => 'Duplicate name booking',
            'eventtype' => 'Test event',
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
            'course' => $course->id,
            'bookingmanager' => $bookingmanager->username,
        ];
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $taggedcourses = connectedcourse::return_tagged_template_courses();
        $taggedcourse = reset($taggedcourses);

        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create the option.
        $optiontitle = 'Same templated option';
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = $optiontitle;
        $record->chooseorcreatecourse = 3; // Create new Moodle course from template.
        $record->coursetemplateid = $taggedcourse->id;
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('now + 3 days');
        $record->courseendtime_0 = strtotime('now + 6 days');

        $option1 = $plugingenerator->create_option($record);
        $option2 = $plugingenerator->create_option($record);

        $courseid1 = (int) singleton_service::get_instance_of_booking_option_settings($option1->id)->courseid;
        $courseid2 = (int) singleton_service::get_instance_of_booking_option_settings($option2->id)->courseid;

        $this->assertNotEmpty($courseid1);
        $this->assertNotEmpty($courseid2);
        $this->assertNotEquals($courseid1, $courseid2);

        // Run the async copy tasks and the finalizers for both options.
        ob_start();
        $this->runAdhocTasks();
        ob_get_clean();
        $fullname1 = $DB->get_field('course', 'fullname', ['id' => $courseid1]);
        $fullname2 = $DB->get_field('course', 'fullname', ['id' => $courseid2]);

        // We assert that the options hav ethe same name and the courses too.
        $this->assertSame($option1->text, $option2->text);
        $this->assertSame($option1->text, $fullname1);
        $this->assertSame($option2->text, $fullname2);
        $this->assertSame($fullname1, $fullname2);

        // We assert that shortnames stay unique.
        $shortname1 = $DB->get_field('course', 'shortname', ['id' => $courseid1]);
        $shortname2 = $DB->get_field('course', 'shortname', ['id' => $courseid2]);
        $this->assertNotEquals($shortname1, $shortname2);
    }

    /**
     * Data provider: with-users checkbox on/off.
     *
     * @return array
     */
    public static function with_users_provider(): array {
        return [
            'with users' => [true],
            'without users' => [false],
        ];
    }
}
