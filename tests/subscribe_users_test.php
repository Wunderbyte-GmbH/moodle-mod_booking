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

use mod_booking\tests\booking_advanced_testcase;
use context_module;
use mod_booking_generator;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests the subscribe-users selectors when "book again" (multiplebookings) is enabled.
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\booking_potential_user_selector
 * @covers \mod_booking\booking_existing_user_selector
 */
final class subscribe_users_test extends booking_advanced_testcase {
    /**
     * Test set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
    }

    /**
     * Creates a course, a booking module, two enrolled students and one booking option.
     *
     * @param array $optionoverrides extra option settings (e.g. multiplebookings)
     * @return array [$course, $booking, $option, $student1, $student2]
     */
    private function create_environment(array $optionoverrides): array {
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student1->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course->id, 'student');

        $bookingmanager = $this->getDataGenerator()->create_user();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'bookingmanager' => $bookingmanager->username,
        ]);

        /** @var mod_booking_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $generator->create_option((object)array_merge([
            'bookingid' => $booking->id,
            'courseid' => $course->id,
            'text' => 'Option test',
            'chooseorcreatecourse' => 1,
            'maxanswers' => 10,
        ], $optionoverrides));

        singleton_service::destroy_instance();

        return [$course, $booking, $option, $student1, $student2];
    }

    /**
     * Books a user directly (as a trainer would), forcing a verified booking.
     *
     * @param \stdClass $booking
     * @param \stdClass $option
     * @param \stdClass $user
     * @return void
     */
    private function book_user($booking, $option, $user): void {
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $bookingoption = singleton_service::get_instance_of_booking_option($settings->cmid, $option->id);
        $bookingoption->user_submit_response($user, 0, 0, 0, MOD_BOOKING_VERIFIED);
        singleton_service::destroy_booking_answers($option->id);
    }

    /**
     * Instantiates the "potential users" (right column) selector for the given option and finds users.
     *
     * @param stdClass $booking
     * @param stdClass $option
     * @return array flat map of userid => user object
     */
    private function find_potential_users($booking, $option): array {
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $cm = get_coursemodule_from_instance('booking', $booking->id);
        $context = context_module::instance($cm->id);
        $course = get_course($booking->course);

        $selector = new booking_potential_user_selector('addselect', [
            'bookingid' => $booking->id,
            'accesscontext' => $context,
            'optionid' => $option->id,
            'cm' => $cm,
            'course' => $course,
            'potentialusers' => [],
        ]);

        $grouped = $selector->find_users('');
        $flat = [];
        foreach ($grouped as $users) {
            foreach ($users as $uid => $user) {
                $flat[$uid] = $user;
            }
        }
        return [$flat, $selector];
    }

    /**
     * With book-again enabled and the timing gate due, an already-booked user is offered again
     * on the right and flagged for the "(is already booked)" label.
     */
    public function test_bookagain_due_user_is_offered_and_flagged(): void {
        // MODE_AFTER_DURATION with a 0-second wait => the gate is due immediately after booking.
        [$course, $booking, $option, $student1, $student2] = $this->create_environment([
            'multiplebookings' => 1,
            'allowtobookagainafter' => 0,
        ]);

        $this->book_user($booking, $option, $student1);

        $this->setAdminUser();
        [$found, $selector] = $this->find_potential_users($booking, $option);

        // Both the booked (due) user and the never-booked user are selectable.
        $this->assertArrayHasKey($student1->id, $found);
        $this->assertArrayHasKey($student2->id, $found);
        // The booked one is flagged; the other is not.
        $this->assertNotEmpty($found[$student1->id]->bookingalreadybooked ?? null);
        $this->assertEmpty($found[$student2->id]->bookingalreadybooked ?? null);
        // And the flag drives the "(is already booked)" label.
        $this->assertStringContainsString(
            get_string('subscribealreadybooked', 'mod_booking'),
            $selector->output_user($found[$student1->id])
        );
    }

    /**
     * With book-again enabled but the timing gate NOT yet due, the booked user is not offered again.
     */
    public function test_bookagain_not_due_user_is_excluded(): void {
        [$course, $booking, $option, $student1, $student2] = $this->create_environment([
            'multiplebookings' => 1,
            'allowtobookagainafter' => 99999, // Far in the future => never due within the test.
        ]);

        $this->book_user($booking, $option, $student1);

        $this->setAdminUser();
        [$found] = $this->find_potential_users($booking, $option);

        $this->assertArrayNotHasKey($student1->id, $found);
        $this->assertArrayHasKey($student2->id, $found);
    }

    /**
     * Regression: with book-again disabled, an already-booked user stays excluded from the right column.
     */
    public function test_bookagain_disabled_user_is_excluded(): void {
        [$course, $booking, $option, $student1, $student2] = $this->create_environment([]);

        $this->book_user($booking, $option, $student1);

        $this->setAdminUser();
        [$found] = $this->find_potential_users($booking, $option);

        $this->assertArrayNotHasKey($student1->id, $found);
        $this->assertArrayHasKey($student2->id, $found);
    }

    /**
     * The left column shows "(booked X times)" and the count grows on each re-booking.
     */
    public function test_existing_selector_shows_booking_count(): void {
        [$course, $booking, $option, $student1, $student2] = $this->create_environment([
            'multiplebookings' => 1,
            'allowtobookagainafter' => 0,
        ]);

        // First booking.
        $this->book_user($booking, $option, $student1);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $cm = get_coursemodule_from_instance('booking', $booking->id);
        $context = context_module::instance($cm->id);
        $existing = new booking_existing_user_selector('removeselect', [
            'bookingid' => $booking->id,
            'accesscontext' => $context,
            'optionid' => $option->id,
            'cm' => $cm,
            'course' => get_course($booking->course),
            'potentialusers' => [$student1->id => $student1],
        ]);

        $out = $existing->output_user($student1);
        $this->assertStringContainsString(
            get_string('subscribebookedxtimes', 'mod_booking', 1),
            $out
        );

        // Re-book (gate is due) => a second booking record, count becomes 2.
        $this->book_user($booking, $option, $student1);
        singleton_service::destroy_instance();

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $count = singleton_service::get_instance_of_booking_answers($settings)->count_previous_bookings($student1->id);
        $this->assertSame(2, $count);
    }
}
