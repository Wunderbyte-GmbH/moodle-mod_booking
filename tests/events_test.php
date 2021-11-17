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
 * @category test
 * @copyright 2017 Andraž Prinčič <atletek@gmail.com>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

/**
 * Tests for forum events.
 *
 * @package mod_forum
 * @category test
 * @copyright 2014 Dan Poltawski <dan@moodle.com>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_booking_events_testcase extends advanced_testcase {

    /**
     * Tests set up.
     */
    public function setUp():void {
        $this->resetAfterTest();
    }

    public function tearDown():void {
    }

    private function returntestdata() {
        $bdata = array('name' => 'Test Booking',
                        'eventtype' => 'Test event',
                        'bookedtext' => array('text' => 'text'), 'waitingtext' => array('text' => 'text'),
                        'notifyemail' => array('text' => 'text'), 'statuschangetext' => array('text' => 'text'),
                        'deletedtext' => array('text' => 'text'), 'pollurltext' => array('text' => 'text'),
                        'pollurlteacherstext' => array('text' => 'text'),
                        'notificationtext' => array('text' => 'text'), 'userleave' => array('text' => 'text'),
                        'bookingpolicy' => 'bookingpolicy', 'tags' => '', 'showviews' => ['mybooking,myoptions,showall,showactive,myinstitution']);
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
        $record->courseid = $course->id;
        $record->description = 'Test description';

        $option = self::getDataGenerator()->get_plugin_generator('mod_booking')->create_option(
                $record);

        return array($user1, $option, $coursectx);
    }

    /**
     * Test teacher_added event.
     */
    public function test_teacher_added() {

        list($user1, $option, $coursectx) = $this->returntestdata();

        $params = array('relateduserid' => $user1->id, 'objectid' => $option->id,
            'context' => $coursectx);

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
    }

    /**
     * Test teacher_removed event.
     */
    public function test_teacher_removed() {

        list($user1, $option, $coursectx) = $this->returntestdata();

        $params = array('relateduserid' => $user1->id, 'objectid' => $option->id,
            'context' => $coursectx);

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
    }
}