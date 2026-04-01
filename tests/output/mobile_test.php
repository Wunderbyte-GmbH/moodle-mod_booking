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

namespace mod_booking\output;

use advanced_testcase;
use mod_booking\singleton_service;
use mod_booking_generator;
use tool_mocktesttime\time_mock;

/**
 * Tests for mobile booking output.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class mobile_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }

    /**
     * Ensure a single booked option is rendered in the mobile course view.
     *
     * @covers \mod_booking\output\mobile::mobile_course_view
     */
    public function test_mobile_course_view_renders_single_booked_option(): void {
        global $PAGE;

        $this->setAdminUser();
        set_config('mobileviewoptions', 'showall,mybooking', 'booking');

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user([
            'username' => 'student1',
            'firstname' => 'Student',
            'lastname' => 'One',
            'email' => 'student1@example.com',
        ]);

        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'showviews' => 'showall,mybooking',
            'whichview' => 'mybooking',
        ]);

        $cm = get_coursemodule_from_instance('booking', $booking->id, $course->id, false, MUST_EXIST);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option([
            'bookingid' => $booking->id,
            'text' => 'Mobile booking option',
            'description' => 'Booked option shown in the mobile app.',
            'chooseorcreatecourse' => 1,
            'courseid' => $course->id,
            'optiondateid_0' => 0,
            'daystonotify_0' => 0,
            'coursestarttime_0' => strtotime('+2 days 10:00'),
            'courseendtime_0' => strtotime('+2 days 12:00'),
        ]);

        $plugingenerator->create_answer([
            'optionid' => $option->id,
            'userid' => $student->id,
        ]);

        singleton_service::destroy_instance();

        $this->setUser($student);
        $PAGE->set_url('/mod/booking/view.php', ['id' => $cm->id]);
        $result = mobile::mobile_course_view([
            'cmid' => $cm->id,
            'whichview' => 'mybooking',
        ]);

        $this->assertCount(1, $result['templates']);
        $this->assertStringContainsString('Mobile booking option', $result['templates'][0]['html']);
        $this->assertStringContainsString('mobile_booking_option_details', $result['templates'][0]['html']);
    }
}
