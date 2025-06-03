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
 * Tests for booking reminder mails.
 *
 * @package mod_booking
 * @category test
 * @copyright 2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2024 Andrii Semenets
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use coding_exception;
use mod_booking\task\send_reminder_mails;
use mod_booking\teachers_handler;
use mod_booking_generator;
use context_system;
use stdClass;
use core\event\notification_sent;
use tool_mocktesttime\time_mock;

/**
 * Class handling tests for booking reminder mails.
 *
 * @package mod_booking
 * @category test
 * @copyright 2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class send_reminder_mails_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        global $DB;

        parent::tearDown();
        // Mandatory clean-up.
        singleton_service::destroy_instance();
    }

    /**
     * Test delete responses.
     *
     * @covers \mod_booking\task\send_reminder_mails
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function test_send_teacher_remimder(): void {
        global $DB, $CFG;

        self::tearDown();

        // It is important to set timezone to have all dates correct!
        $this->setTimezone('Europe/London');

        $CFG->enablecompletion = 1;

        $bdata = ['name' => 'Booking Test Reminders', 'eventtype' => 'Test event', 'enablecompletion' => 1,
            'bookedtext' => ['text' => 'text'], 'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'], 'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'], 'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'],
            'notificationtext' => ['text' => 'text'], 'userleave' => ['text' => 'text'],
            'bookingpolicy' => 'bookingpolicy', 'tags' => '', 'completion' => 2,
            'showviews' => ['mybooking,myoptions,optionsiamresponsiblefor,showall,showactive,myinstitution'],
        ];

        // Spoecific setting to notify teachers.
        $bdata['daystonotify'] = 3;
        $bdata['daystonotify2'] = 2;
        $bdata['daystonotifyteachers'] = 2;
        $bdata['notifyemailteachers'] = 'Your booking will start soon:
            {bookingdetails} You have {numberparticipants} booked participants';

        // Setup test data.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        $this->setAdminUser();

        // Create and enroll users in course with roles.
        $user1 = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $user2 = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $user3 = $this->getDataGenerator()->create_and_enrol($course, 'manager');

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $user3->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        // Acting as a teacher.
        $this->setUser($user2);

        $time = new \DateTimeImmutable('now', new \DateTimeZone('Europe/London'));
        $onedaysbefore = $time->modify('+27 hour');
        $twodaysbefore = $time->modify('+52 hour');
        $threedaysbefore = $time->modify('+3 day');
        $fourdaysbefore = $time->modify('+4 day');

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Option Test Reminders 1';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course->id;
        $record->description = 'Test description';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = $onedaysbefore->getTimestamp();
        $record->courseendtime_0 = $threedaysbefore->getTimestamp();
        $record->optiondateid_1 = "0";
        $record->daystonotify_1 = "0";
        $record->coursestarttime_1 = $twodaysbefore->getTimestamp();
        $record->courseendtime_1 = $fourdaysbefore->getTimestamp();

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($record);

        $teacherhandler = new teachers_handler($option1->id);
        $teacherdata = new stdClass();
        $teacherdata->teachersforoption = [$user2->id];
        $teacherhandler->save_from_form($teacherdata);

        // Replace StdClass with instance of Booking.
        $booking1 = singleton_service::get_instance_of_booking_by_bookingid($booking1->id);

        $bookingoption1 = singleton_service::get_instance_of_booking_option($booking1->cmid, $option1->id);

        // Book option by student.
        // The circumvent to baypass some checks. Use booking_bookit::bookit for prices, shoppingcart, etc.
        $bookingoption1->user_submit_response($user1, $bookingoption1->id, 0, 0, MOD_BOOKING_VERIFIED);

        // Run the send_reminder_mails scheduled task.
        $sink = $this->redirectEvents();

        ob_start();

        $reminder = new send_reminder_mails();
        $reminder->execute();
        $events = $sink->get_events();

        $res = ob_get_clean();

        $this->assertStringContainsString("booking - send notification triggered", $res);
        $this->assertStringContainsString("send teacher notifications - START", $res);
        $this->assertStringContainsString("send teacher notifications - DONE", $res);

        // Eliminate notification_sent even from processing for now.
        foreach ($events as $key => $event) {
            if ($event instanceof notification_sent) {
                unset($events[$key]);
            }
        }
        $events = array_values($events);

        $this->assertCount(6, $events);

        // Checking that the 1st event - message to student 1 - contains the expected values.
        $this->assertInstanceOf('\mod_booking\event\message_sent', $events[0]);
        $this->assertEquals(context_system::instance(), $events[0]->get_context());
        $this->assertNotNull($events[0]->objectid);
        $this->assertEquals("sent", $events[0]->action);
        $this->assertEquals($user1->id, $events[0]->userid);
        $this->assertEquals("Your booking will start soon", $events[0]->other["subject"]);
        // GitHub require $user1->id. Unable to obtain bookingmanager in message_controller (reason unknow) so $USER has been used.
        // phpcs:ignore
        // $this->assertEquals($user3->id, $events[0]->relateduserid);

        // Checking that the 2nd event - reminder 1 - contains the expected values.
        $this->assertInstanceOf('\mod_booking\event\reminder1_sent', $events[1]);
        $this->assertEquals(context_system::instance(), $events[1]->get_context());
        $this->assertEquals($option1->id, $events[1]->objectid);
        $this->assertEquals("sent", $events[1]->action);
        $this->assertEquals($user2->id, $events[1]->userid); // Alawys current user.

        // Checking that the 3rd event - message to student 2 - contains the expected values.
        $this->assertInstanceOf('\mod_booking\event\message_sent', $events[2]);
        $this->assertEquals(context_system::instance(), $events[2]->get_context());
        $this->assertNotNull($events[2]->objectid);
        $this->assertEquals("sent", $events[2]->action);
        $this->assertEquals($user1->id, $events[2]->userid);
        $this->assertEquals("Your booking will start soon", $events[0]->other["subject"]);
        // GitHub require $user1->id. Unable to obtain bookingmanager in message_controller (reason unknow) so $USER has been used.
        // phpcs:ignore
        // $this->assertEquals($user3->id, $events[2]->relateduserid);

        // Checking that the 4th event - reminder 2 - contains the expected values.
        $this->assertInstanceOf('\mod_booking\event\reminder2_sent', $events[3]);
        $this->assertEquals(context_system::instance(), $events[1]->get_context());
        $this->assertEquals($option1->id, $events[3]->objectid);
        $this->assertEquals("sent", $events[3]->action);
        $this->assertEquals($user2->id, $events[3]->userid);

        // Checking that the 5th event - message to teacher 2 - contains the expected values.
        $this->assertInstanceOf('\mod_booking\event\message_sent', $events[4]);
        $this->assertEquals(context_system::instance(), $events[4]->get_context());
        $this->assertNotNull($events[4]->objectid);
        $this->assertEquals("sent", $events[4]->action);
        $this->assertEquals($user2->id, $events[4]->userid);
        $this->assertEquals("Your booking will start soon", $events[4]->other["subject"]);
        // GitHub require $user1->id. Unable to obtain bookingmanager in message_controller (reason unknow) so $USER has been used.
        // phpcs:ignore
        // $this->assertEquals($user3->id, $events[4]->relateduserid);

        // Checking that the 5th event - teacher reminder - contains the expected values.
        $this->assertInstanceOf('\mod_booking\event\reminder_teacher_sent', $events[5]);
        $this->assertEquals(context_system::instance(), $events[5]->get_context());
        $this->assertEquals($option1->id, $events[5]->objectid);
        $this->assertEquals("sent", $events[5]->action);
        $this->assertEquals($user2->id, $events[5]->userid);
        $this->assertEquals(2, $events[5]->other["daystonotifyteachers"]);
    }
}
