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

namespace mod_booking;

use advanced_testcase;
use mod_booking\singleton_service;
use mod_booking\booking_bookit;
use mod_booking\bo_availability\bo_info;
use tool_mocktesttime\time_mock;
use stdClass;
use mod_booking_generator;

/**
 * Test for booking_answers timecompleted field.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2026 David Ala-Flucher
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
final class booking_answers_timecompleted_test extends advanced_testcase {
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
     * Setup environment.
     * @return array
     */
    private function setup_booking_environment(): array {
        global $DB;

        $this->setAdminUser();

        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));

        // Setup test data.

        $student1 = $this->getDataGenerator()->create_user();

        // Create courses.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        $this->getDataGenerator()->enrol_user($student1->id, $course->id, 'student');

        // Create a booking module.
        $booking = $this->getDataGenerator()->create_module('booking', [
            'name' => 'Booking module 1',
            'course' => $course->id,
            'cancancelbook' => 0,
        ]);

        return [
            'course' => $course,
            'bookingmodule' => $booking,
            'student1' => $student1,
        ];
    }

    /**
     *  Tests if the completeddate field is set correctly when toggling completion for a booking option.
     * @return void
     *
     * @covers \mod_booking\booking_answers\booking_answers
     */
    public function test_completeddate(): void {
        global $DB;
        $this->resetAfterTest(true);
        // Initial config.
        $env = $this->setup_booking_environment();
        $course = $env['course'];
        $bookingmodule = $env['bookingmodule'];
        $student1 = $env['student1'];

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create booking option 1. Without price. No confirmation.
        $record = new stdClass();
        $record->bookingid = $bookingmodule->id;
        $record->courseid = $course->id;
        $record->text = 'Test option1';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->maxanswers = 3;
        $record->maxoverbooking = 3;
        $record->waitforconfirmation = 0;
        $option1 = $plugingenerator->create_option($record);
        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $optionobject = singleton_service::get_instance_of_booking_option($option1->cmid, $option1->id);
        $boinfo1 = new bo_info($settings1);

        // Book option for user.
        $this->setUser($student1);
        booking_bookit::bookit('option', $settings1->id, $student1->id); // Try to book.
        booking_bookit::bookit('option', $settings1->id, $student1->id); // Confirm booking.
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id); // Check if booked.
        $this->setAdminUser();
        // Complete option for user.
        $optionobject->toggle_user_completion($student1->id);
        $answer = $DB->get_record('booking_answers', ['optionid' => $option1->id, 'userid' => $student1->id]);
        $this->assertNotEmpty($answer->completeddate);
        $this->assertSame(time(), (int) $answer->completeddate);
        // Uncomplete option for user.
        $optionobject->toggle_user_completion($student1->id);
        $answer = $DB->get_record('booking_answers', ['optionid' => $option1->id, 'userid' => $student1->id]);
        $this->assertEmpty($answer->completeddate);
        $this->assertSame(time(), (int) $answer->timemodified);
    }
}
