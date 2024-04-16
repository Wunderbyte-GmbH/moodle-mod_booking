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
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2017 Andraž Prinčič
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use coding_exception;
use mod_booking\option\dates_handler;
use mod_booking\price;
use mod_booking_generator;
use context_course;
use mod_booking\bo_availability\bo_info;
use stdClass;
use mod_booking\utils\csv_import;
use mod_booking\importer\bookingoptionsimporter;

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Class handling tests for booking options.
 *
 * @package mod_booking
 * @category test
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_option_bookit_test extends advanced_testcase {

    /**
     * Tests set up.
     */
    public function setUp(): void {
        $this->resetAfterTest();
    }

    /**
     * Tear Down.
     *
     * @return void
     *
     */
    public function tearDown(): void {
    }

    /**
     * Test booking, cancelation, option has started etc.
     *
     * @covers ::delete_responses_activitycompletion
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function test_booking_bookit() {
        global $DB, $CFG;

        $bdata = [
            'name' => 'Test Booking 1',
            'eventtype' => 'Test event',
            'enablecompletion' => 1,
            'bookedtext' => ['text' => 'text'],
            'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'],
            'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'],
            'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'],
            'notificationtext' => ['text' => 'text'], 'userleave' => ['text' => 'text'],
            'tags' => '',
            'completion' => 2,
            'showviews' => ['mybooking,myoptions,showall,showactive,myinstitution'],
            'cancancelbook' => 1,
        ];
        // Setup test data.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $admin = $this->getDataGenerator()->create_user();
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $student3 = $this->getDataGenerator()->create_user();
        $student4 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $bdata['name'] = 'Test Booking 2';

        $this->setUser($admin);
        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($admin->id, $course->id);
        $this->getDataGenerator()->enrol_user($student1->id, $course->id);
        $this->getDataGenerator()->enrol_user($student2->id, $course->id);
        $this->getDataGenerator()->enrol_user($student3->id, $course->id);
        $this->getDataGenerator()->enrol_user($student4->id, $course->id);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1';
        $record->courseid = 0;
        $record->maxanswers = 2;

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);

        // Book the first user without any problem.
        $boinfo = new bo_info($settings);

        // Via this line, we can get the blocking condition.
        // The true is only hardblocking, which means low blockers used to only show buttons etc. wont be shown.
        list($id, $isavailable, $description) = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals($id, MOD_BOOKING_BO_COND_BOOKITBUTTON);

        // We are allowed to book.
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        list($id, $isavailable, $description) = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals($id, MOD_BOOKING_BO_COND_CONFIRMBOOKIT);

        // Now we can actually book.
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        list($id, $isavailable, $description) = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals($id, MOD_BOOKING_BO_COND_ALREADYBOOKED);

        // When we run it again, we might want to cancel.
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        list($id, $isavailable, $description) = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals($id, MOD_BOOKING_BO_COND_CONFIRMCANCEL);

        // Now confirm cancel.
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);

        // The result is, that we see the bookingbutton again.
        list($id, $isavailable, $description) = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals($id, MOD_BOOKING_BO_COND_BOOKITBUTTON);

        // That was just for fun. Now we make sure the user is booked again.
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);

        // Now book the second user.
        $result = booking_bookit::bookit('option', $settings->id, $student2->id);
        $result = booking_bookit::bookit('option', $settings->id, $student2->id);

        // Now, all the available places are booked. We try to book the third user.
        $this->setUser($student3);
        list($id, $isavailable, $description) = $boinfo->is_available($settings->id, $student3->id, false);
        $this->assertEquals($id, MOD_BOOKING_BO_COND_FULLYBOOKED);

        // We still try to book, but no chance.
        $result = booking_bookit::bookit('option', $settings->id, $student3->id);
        list($id, $isavailable, $description) = $boinfo->is_available($settings->id, $student3->id, true);
        $this->assertEquals($id, MOD_BOOKING_BO_COND_FULLYBOOKED);

        // Now we add waitinglist to option.
        $this->setUser($admin);
        $record->id = $option1->id;
        $record->maxoverbooking = 1;
        $record->cmid = $settings->cmid;
        booking_option::update($record);

        $this->setUser($student3);
        list($id, $isavailable, $description) = $boinfo->is_available($settings->id, $student3->id, false);

        // Bookitbutton blocks.
        $result = booking_bookit::bookit('option', $settings->id, $student3->id);
        list($id, $isavailable, $description) = $boinfo->is_available($settings->id, $student3->id, false);

        // Now student3 is on waitinglist.
        $result = booking_bookit::bookit('option', $settings->id, $student3->id);
        list($id, $isavailable, $description) = $boinfo->is_available($settings->id, $student3->id, false);

        // User really is booked to waitinglist.
        $this->assertEquals($id, MOD_BOOKING_BO_COND_ONWAITINGLIST);

        // Check for guest user.
        $this->setGuestUser();
        list($id, $isavailable, $description) = $boinfo->is_available($settings->id, 1, false);
        $this->assertEquals($id, MOD_BOOKING_BO_COND_ALLOWEDTOBOOKININSTANCE);

        // Now we cancel the whole booking option.
        booking_option::cancelbookingoption($settings->id);

        // Option has already started.
        $record->coursestarttime = date('Y-m-d', strtotime('now - 1 day'));
        $record->courseendtime = date('Y-m-d', strtotime('now + 1 day'));
        $record->importing = true;
        booking_option::update($record);

        $bdata = (object)$bdata;
        $bdata->allowupdate = 0;
        $bdata->instance = $booking1->id;
        $bdata->id = $booking1->id;
        booking_update_instance($bdata);

        // Try to book again with user1.
        list($id, $isavailable, $description) = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals($id, MOD_BOOKING_BO_COND_ISCANCELLED);

        // Now we undo cancel of the booking option.
        booking_option::cancelbookingoption($settings->id, '', true);

        // Try to book again with user1.
        $this->setUser($student1);
        list($id, $isavailable, $description) = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals($id, MOD_BOOKING_BO_COND_OPTIONHASSTARTED);
    }

    /**
     * Test add to group.
     *
     * @covers ::delete_responses_activitycompletion
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function test_booking_bookit_add_to_group() {
        global $DB, $CFG;

        $bdata = [
            'name' => 'Test Booking 1',
            'eventtype' => 'Test event',
            'enablecompletion' => 1,
            'bookedtext' => ['text' => 'text'],
            'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'],
            'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'],
            'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'],
            'notificationtext' => ['text' => 'text'], 'userleave' => ['text' => 'text'],
            'tags' => '',
            'completion' => 2,
            'showviews' => ['mybooking,myoptions,showall,showactive,myinstitution'],
            'cancancelbook' => 1,
            'addtogroup' => 1,
            'autoenrol' => 1,
        ];
        // Setup test data.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $course2 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

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

        $bdata['name'] = 'Test Booking 2';

        $this->setUser($admin);
        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($admin->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student1->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student3->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student4->id, $course1->id);
        $this->getDataGenerator()->enrol_user($teacher->id, $course1->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course1->id);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1';
        $record->courseid = $course2->id;
        $record->maxanswers = 2;

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);

        // Book the first user without any problem.
        $boinfo = new bo_info($settings);

        // Book the student right away.
        $this->setUser($student1);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);

        // Via this line, we can get the blocking condition.
        // The true is only hardblocking, which means low blockers used to only show buttons etc. wont be shown.
        list($id, $isavailable, $description) = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals($id, MOD_BOOKING_BO_COND_ALREADYBOOKED);

        // Now check if the user is enrolled to the course.
        // We should get two courses.
        $courses = enrol_get_users_courses($student1->id);
        $this->assertEquals(count($courses), 2);

        // Now check if the user is enrolled in the right group.
        $groups = groups_get_all_groups($course2->id);
        $group = reset($groups);

        // First assert that the group is actually created.
        $this->assertStringContainsString($settings->text, $group->name);

        // No check if the user is in the group.
        $groupmembers = groups_get_members($group->id);

        $this->assertArrayHasKey($student1->id, $groupmembers);

        // Unenrol user again.
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);

        list($id, $isavailable, $description) = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals($id, MOD_BOOKING_BO_COND_BOOKITBUTTON);
    }

    /**
     * Test add to group.
     *
     * @covers ::delete_responses_activitycompletion
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function test_booking_bookit_bookingtime() {
        global $DB, $CFG;

        $bdata = [
            'name' => 'Test Booking 1',
            'eventtype' => 'Test event',
            'enablecompletion' => 1,
            'bookedtext' => ['text' => 'text'],
            'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'],
            'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'],
            'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'],
            'notificationtext' => ['text' => 'text'], 'userleave' => ['text' => 'text'],
            'tags' => '',
            'completion' => 2,
            'showviews' => ['mybooking,myoptions,showall,showactive,myinstitution'],
            'cancancelbook' => 1,
        ];
        // Setup test data.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $course2 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

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

        $bdata['name'] = 'Test Booking 2';

        $this->setUser($admin);
        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($admin->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student1->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student3->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student4->id, $course1->id);
        $this->getDataGenerator()->enrol_user($teacher->id, $course1->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course1->id);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1';
        $record->courseid = $course2->id;
        $record->maxanswers = 2;
        $record->bookingopeningtime = strtotime('now + 1 day');
        $record->bookingclosingtime = strtotime('now + 2 day');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);

        // Book the first user without any problem.
        $boinfo = new bo_info($settings);

        // Book the student right away.
        $this->setUser($student1);

        list($id, $isavailable, $description) = $boinfo->is_available($settings->id, $student1->id, true);

        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);

        // Via this line, we can get the blocking condition.
        // The true is only hardblocking, which means low blockers used to only show buttons etc. wont be shown.
        list($id, $isavailable, $description) = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals($id, MOD_BOOKING_BO_COND_BOOKING_TIME);

    }

    /**
     * Test add to group.
     *
     * @covers ::delete_responses_activitycompletion
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function test_booking_bookit_askforconfirmation() {
        global $DB, $CFG;

        $bdata = [
            'name' => 'Test Booking 1',
            'eventtype' => 'Test event',
            'enablecompletion' => 1,
            'bookedtext' => ['text' => 'text'],
            'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'],
            'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'],
            'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'],
            'notificationtext' => ['text' => 'text'], 'userleave' => ['text' => 'text'],
            'tags' => '',
            'completion' => 2,
            'showviews' => ['mybooking,myoptions,showall,showactive,myinstitution'],
            'cancancelbook' => 1,
        ];
        // Setup test data.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $course2 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

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

        $bdata['name'] = 'Test Booking 2';

        $this->setUser($admin);
        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($admin->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student1->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student3->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student4->id, $course1->id);
        $this->getDataGenerator()->enrol_user($teacher->id, $course1->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course1->id);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1';
        $record->courseid = $course2->id;
        $record->maxanswers = 2;
        $record->waitforconfirmation = 1;

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);

        // Book the first user without any problem.
        $boinfo = new bo_info($settings);

        // Book the student right away.
        $this->setUser($student1);

        list($id, $isavailable, $description) = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals($id, MOD_BOOKING_BO_COND_ASKFORCONFIRMATION);

        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        list($id, $isavailable, $description) = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals($id, MOD_BOOKING_BO_COND_ONWAITINGLIST);

        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        $this->setAdminUser();
        $option->user_submit_response($student1, 0, 0, 0, MOD_BOOKING_VERIFIED);

        $this->setUser($student1);
        list($id, $isavailable, $description) = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals($id, MOD_BOOKING_BO_COND_ALREADYBOOKED);
    }
}
