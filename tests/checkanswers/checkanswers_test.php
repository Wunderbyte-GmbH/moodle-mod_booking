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
 * Tests for booking option events.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use cache_helper;
use context_module;
use mod_booking\bo_availability\bo_info;
use mod_booking\local\checkanswers\checkanswers;
use stdClass;
use mod_booking_generator;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once($CFG->dirroot . '/enrol/manual/externallib.php');

/**
 * Class handling tests for booking options.
 *
 * @package mod_booking
 * @category test
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
final class checkanswers_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
        set_config('uselegacymailtemplates', 0, 'booking');
        set_config('unenroluserswithoutaccessareyousure', 1, 'booking');
        set_config('unenroluserswithoutaccess', 1, 'booking');
    }

    /**
     * Test of booking option with price as well as cancellation by user.
     *
     * @covers \mod_booking\booking_bookit::bookit
     * @covers \mod_booking\local\checkanswers\checkanswers::create_bookinganswers_check_tasks
     *
     * @param array $bdata
     * @dataProvider booking_common_settings_provider
     *
     * @return void
     *
     */
    public function test_booking_bookit_with_price_and_cancellation(array $bdata): void {
        global $DB, $CFG, $PAGE;

        $bdata['cancancelbook'] = 1;

        // Setup test data.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $course2 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Define the group data.
        $group = new stdClass();
        $group->courseid = $course1->id; // Set your course ID.
        $group->name = 'Hidden Group';
        $group->description = 'This group is used for restricting visibility.';
        $group->idnumber = 'group_hidden_001';
        $group->timecreated = time();
        $group->timemodified = time();

        // Insert the group into the database.
        $groupid = groups_create_group($group);

        // Create users.
        $admin = $this->getDataGenerator()->create_user();
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $student3 = $this->getDataGenerator()->create_user();
        $student4 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($admin->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student1->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student3->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student4->id, $course1->id);
        $this->getDataGenerator()->enrol_user($teacher->id, $course1->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course1->id);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1 (option time)';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course2->id;

        [$course, $cm] = get_course_and_cm_from_cmid($booking1->cmid);

        // Create restriction: The section of the cm should only be visible to users not enrolled in group.
        $section = $cm->get_section_info();
        $availability = '{"op":"!&","c":[{"type":"group","id":' . (int)$groupid . '}],"show":true}';
        $DB->set_field('course_sections', 'availability', $availability, ['id' => $section->id]);
        cache_helper::purge_all();

        // Before the creation, we need to fix the Page context.
        $PAGE->set_cm($cm, $course);
        $PAGE->set_context(context_module::instance($booking1->cmid));

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);

        // Book the first student.
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);

        // Book the second student.
        $result = booking_bookit::bookit('option', $settings->id, $student2->id);
        $result = booking_bookit::bookit('option', $settings->id, $student2->id);

        $boinfo = new bo_info($settings);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        checkanswers::create_bookinganswers_check_tasks(1);

        // Four backslashes needed so it does not get lost in MariaDB.
        $taskssql = "SELECT * FROM {task_adhoc} WHERE classname LIKE '%mod_booking%task%check_answers%'";
        $tasks = $DB->get_records_sql($taskssql);

        $this->assertCount(1, $tasks);

        $this->runAdhocTasks();
        singleton_service::destroy_instance();
        cache_helper::purge_all();

        // Four backslashes needed so it does not get lost in MariaDB.
        $tasks = $DB->get_records_sql($taskssql);

        $this->assertCount(0, $tasks);

        // We expect student 1 to be still enrolled because he is enrolled in the course.
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Student 2 should not be booked anymore because he is not enrolled in the course.
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);

        // Student2 is now enrolled in course and rebooked in option.
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id);
        // Book the second student.
        $result = booking_bookit::bookit('option', $settings->id, $student2->id);
        $result = booking_bookit::bookit('option', $settings->id, $student2->id);

        // Now we turn off the global visibility of the activity.
        set_coursemodule_visible($settings->cmid, 0);
        $cm = get_fast_modinfo($course1)->get_cm($settings->cmid);
        $event = \core\event\course_module_updated::create_from_cm($cm);
        $event->trigger();

        // The eventobserver of the updated course module should be enough to create our tasks.
        $this->runAdhocTasks();
        singleton_service::destroy_instance();
        cache_helper::purge_all();

        // Students should remain enrolled.
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Set coursemodule visible again.
        set_coursemodule_visible($settings->cmid, 1);
        singleton_service::destroy_instance();
        cache_helper::purge_all();

        $taskssql = "SELECT * FROM {task_adhoc} WHERE classname LIKE '%mod_booking%task%check_answers%'";
        $tasks = $DB->get_records_sql($taskssql);
        $this->assertCount(0, $tasks);

        groups_add_member($groupid, $student1->id);
        cache_helper::purge_all();

        $taskssql = "SELECT * FROM {task_adhoc} WHERE classname LIKE '%mod_booking%task%check_answers%'";
        $tasks = $DB->get_records_sql($taskssql);
        $this->assertCount(1, $tasks);
        $this->runAdhocTasks();

        // Student1 is not enrolled since he now belongs to the group and section is invisible for this group.
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Book the third student.
        $result = booking_bookit::bookit('option', $settings->id, $student3->id);
        $result = booking_bookit::bookit('option', $settings->id, $student3->id);

        // Book the fourth student.
        $result = booking_bookit::bookit('option', $settings->id, $student4->id);
        $result = booking_bookit::bookit('option', $settings->id, $student4->id);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student3->id, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student4->id, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        \enrol_manual_external::unenrol_users([
            ['userid' => $student3->id, 'courseid' => $course1->id],
        ]);

        $this->runAdhocTasks();

        singleton_service::destroy_instance();
        cache_helper::purge_all();

        // The unenrolled user is not booked anymore.
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student3->id, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);

        // The user who stayed in the course is unaffected.
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student4->id, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);
    }

    /**
     * Data provider for condition_bookingpolicy_test
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    public static function booking_common_settings_provider(): array {
        $bdata = [
            'name' => 'Test Booking Policy 1',
            'eventtype' => 'Test event',
            'enablecompletion' => 1,
            'pollurltext' => ['text' => 'text'],
            'tags' => '',
            'sendmail' => 0,
            'completion' => 2,
            'showviews' => ['mybooking,myoptions,optionsiamresponsiblefor,showall,showactive,myinstitution'],
        ];
        return ['bdata' => [$bdata]];
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        // Mandatory clean-up.
        singleton_service::destroy_instance();
    }
}
