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
 * Tests that slot booking options do not depend on "Allow booking after course start".
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\bo_availability\bo_info;
use mod_booking\bo_availability\conditions\optionhasstarted;
use mod_booking\tests\booking_advanced_testcase;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Each slot is booked on its own, so a slot option must not be blocked because its first
 * date has started. When no slot is left, the student sees "No more dates available".
 *
 * @covers \mod_booking\bo_availability\conditions\optionhasstarted
 * @covers \mod_booking\bo_availability\conditions\fullybooked
 */
final class condition_optionhasstarted_slot_test extends booking_advanced_testcase {
    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Instance setting "Allow booking after course start" off and on.
     *
     * @return array[]
     */
    public static function allowbookingafterstart_provider(): array {
        return [
            'setting off' => [0],
            'setting on' => [1],
        ];
    }

    /**
     * A session slot option whose first date has ended can still be booked for its future date.
     *
     * @dataProvider allowbookingafterstart_provider
     * @param int $allowupdate value of "Allow booking after course start"
     */
    public function test_session_slot_option_bookable_after_first_date(int $allowupdate): void {
        $now = time();
        [$optionid, $student] = $this->create_option($allowupdate, [
            'slot_type' => 'session',
        ], [
            [$now - 2 * DAYSECS, $now - 2 * DAYSECS + HOURSECS],
            [$now + 2 * DAYSECS, $now + 2 * DAYSECS + HOURSECS],
        ]);
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        $this->assertTrue((new optionhasstarted())->is_available($settings, $student));

        // The student gets the slot booking flow, not a blocking message.
        $this->setUser($student);
        [$id] = (new bo_info($settings))->is_available($optionid, $student);
        $this->assertSame(MOD_BOOKING_BO_COND_SLOTBOOKING, $id);
    }

    /**
     * When every session has ended, the student sees "No more dates available".
     *
     * @dataProvider allowbookingafterstart_provider
     * @param int $allowupdate value of "Allow booking after course start"
     */
    public function test_no_more_dates_when_all_sessions_ended(int $allowupdate): void {
        $now = time();
        [$optionid, $student] = $this->create_option($allowupdate, [
            'slot_type' => 'session',
        ], [
            [$now - 3 * DAYSECS, $now - 3 * DAYSECS + HOURSECS],
            [$now - 2 * DAYSECS, $now - 2 * DAYSECS + HOURSECS],
        ]);

        $this->assert_no_more_dates($optionid, $student);
    }

    /**
     * A fixed slot option whose validity period is over shows the same message.
     */
    public function test_no_more_dates_when_fixed_validity_ended(): void {
        $now = time();
        [$optionid, $student] = $this->create_option(0, [
            'slot_type' => 'fixed',
            'slot_valid_from' => $now - 10 * DAYSECS,
            'slot_valid_until' => $now - 3 * DAYSECS,
        ]);

        $this->assert_no_more_dates($optionid, $student);
    }

    /**
     * A normal option (no slot booking) is still blocked once it has started, as before.
     */
    public function test_normal_option_still_blocked_after_start(): void {
        $now = time();
        [$optionid, $student] = $this->create_option(0, null, [
            [$now - DAYSECS, $now - DAYSECS + HOURSECS],
            [$now + 2 * DAYSECS, $now + 2 * DAYSECS + HOURSECS],
        ]);

        $this->setUser($student);
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        [$id, $isavailable, $description] = (new bo_info($settings))->is_available($optionid, $student);
        $this->assertFalse($isavailable);
        $this->assertSame(MOD_BOOKING_BO_COND_OPTIONHASSTARTED, $id);
        $this->assertSame(get_string('bocondoptionhasstartednotavailable', 'mod_booking'), $description);
    }

    /**
     * Assert that the student is blocked with "No more dates available".
     *
     * @param int $optionid
     * @param int $student student user id
     */
    private function assert_no_more_dates(int $optionid, int $student): void {
        $this->setUser($student);
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        [$id, $isavailable, $description] = (new bo_info($settings))->is_available($optionid, $student);

        $this->assertFalse($isavailable);
        $this->assertSame(MOD_BOOKING_BO_COND_FULLYBOOKED, $id);
        $this->assertSame(get_string('bocondfullybookednomoredates', 'mod_booking'), $description);
    }

    /**
     * Create a booking option in a new booking instance.
     *
     * @param int $allowupdate value of "Allow booking after course start"
     * @param array|null $slotconfig slot settings, or null for a normal option
     * @param array $ranges option dates as list of [start, end]
     * @return array{0:int, 1:int} option id and student id
     */
    private function create_option(int $allowupdate, ?array $slotconfig, array $ranges = []): array {
        $course = self::getDataGenerator()->create_course();
        $student = self::getDataGenerator()->create_user();
        self::getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $booking = self::getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'allowupdate' => $allowupdate,
        ]);
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $record = [
            'bookingid' => $booking->id,
            'text' => 'Option ' . uniqid('', true),
            'course' => $course->id,
            'maxanswers' => 20,
        ];
        if ($slotconfig !== null) {
            $record += $slotconfig + [
                'optiontype' => MOD_BOOKING_OPTIONTYPE_SLOTBOOKING,
                'slot_enabled' => 1,
                'slot_duration_minutes' => 60,
                'slot_interval_minutes' => 60,
                'slot_opening_time' => '00:00',
                'slot_closing_time' => '23:59',
                'slot_valid_from' => 0,
                'slot_valid_until' => 0,
                'slot_max_participants_per_slot' => 3,
                'slot_max_slots_per_user' => 1,
                'slot_booking_view_mode' => 'list',
                'slot_add_examiners' => 0,
                'slot_teachers_required' => 0,
            ];
            for ($day = 1; $day <= 7; $day++) {
                $record['slot_day_' . $day] = 1;
            }
        }
        foreach (array_values($ranges) as $index => $range) {
            $record['optiondateid_' . $index] = 0;
            $record['daystonotify_' . $index] = 0;
            $record['coursestarttime_' . $index] = $range[0];
            $record['courseendtime_' . $index] = $range[1];
        }

        $option = $plugingenerator->create_option((object)$record);

        singleton_service::destroy_instance();
        return [(int)$option->id, (int)$student->id];
    }
}
