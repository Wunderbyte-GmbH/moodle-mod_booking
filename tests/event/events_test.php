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
 * Tests for booking events.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Andraž Prinčič
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use context_course;
use stdClass;
use tool_mocktesttime\time_mock;

/**
 * Tests for forum events.
 *
 * @package mod_booking
 * @category test
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class events_test extends advanced_testcase {
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
     * Return test data.
     *
     * @return array
     *
     */
    private function returntestdata() {
        $bdata = ['name' => 'Test Booking', 'eventtype' => 'Test event',
                    'bookedtext' => ['text' => 'text'], 'waitingtext' => ['text' => 'text'],
                    'notifyemail' => ['text' => 'text'], 'statuschangetext' => ['text' => 'text'],
                    'deletedtext' => ['text' => 'text'], 'pollurltext' => ['text' => 'text'],
                    'pollurlteacherstext' => ['text' => 'text'],
                    'notificationtext' => ['text' => 'text'], 'userleave' => ['text' => 'text'],
                    'bookingpolicy' => 'bookingpolicy', 'tags' => '',
                    'showviews' => ['showall,showactive,mybooking,myoptions,myinstitution'],
        ];

        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $user2->username;

        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setUser($user2);
        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($user1->id, $course->id);
        $this->getDataGenerator()->enrol_user($user2->id, $course->id);

        $coursectx = context_course::instance($course->id);

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Test option';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course->id;
        $record->description = 'Test description';

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);

        return [$user1, $option, $coursectx];
    }

    /**
     * Test teacher_added event.
     *
     * @covers \mod_booking\event\teacher_added
     *
     * @throws \coding_exception
     */
    public function test_teacher_added(): void {

        [$user1, $option, $coursectx] = $this->returntestdata();

        $params = ['relateduserid' => $user1->id, 'objectid' => $option->id, 'context' => $coursectx];

        // Create event.
        $event = \mod_booking\event\teacher_added::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_booking\event\teacher_added', $event);
        $this->assertEquals($coursectx, $event->get_context());
        $this->assertEventContextNotUsed($event);

        $this->assertNotEmpty($event->get_name());

        // Mandatory to solve potential cache issues.
        singleton_service::destroy_booking_option_singleton($option->id);
    }

    /**
     * Test teacher_removed event.
     *
     * @covers \mod_booking\event\teacher_removed
     * @throws \coding_exception
     */
    public function test_teacher_removed(): void {

        [$user1, $option, $coursectx] = $this->returntestdata();

        $params = ['relateduserid' => $user1->id, 'objectid' => $option->id, 'context' => $coursectx];

        // Create event.
        $event = \mod_booking\event\teacher_removed::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_booking\event\teacher_removed', $event);
        $this->assertEquals($coursectx, $event->get_context());
        $this->assertEventContextNotUsed($event);

        $this->assertNotEmpty($event->get_name());

        // Mandatory to solve potential cache issues.
        singleton_service::destroy_booking_option_singleton($option->id);
    }
}
