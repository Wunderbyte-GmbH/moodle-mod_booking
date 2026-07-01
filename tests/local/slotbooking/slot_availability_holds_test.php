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

use mod_booking\local\slotbooking\slot_availability;
use mod_booking\local\slotbooking\slot_dto;
use mod_booking\local\slotbooking\slot_move_store;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests that pending slot-move holds occupy slot capacity (MP-B).
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\slotbooking\slot_availability::count_bookings
 */
final class slot_availability_holds_test extends booking_advanced_testcase {
    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * A non-expired pending move holds its target slot (full for others), the holder can exclude
     * their own hold, and an expired hold does not block.
     *
     * @return void
     */
    public function test_pending_hold_occupies_capacity(): void {
        [$optionid, $userid] = $this->create_single_seat_slot_option();

        $slots = slot_dto::build_picker_slots($optionid, $userid);
        $this->assertNotEmpty($slots);
        $slot = $slots[0];
        $start = (int) $slot['start'];
        $end = (int) $slot['end'];

        // No bookings, no holds yet -> the single seat is free.
        $this->assertTrue(
            slot_availability::is_slot_available($optionid, $start, $end, $userid),
            'Slot should be available before any hold.'
        );

        // A non-expired pending move for that slot takes the only seat.
        $moveid = slot_move_store::create_pending(
            $optionid,
            0,
            $userid,
            [['start' => $start, 'end' => $end]],
            [],
            5.0,
            time() + HOURSECS
        );

        $this->assertFalse(
            slot_availability::is_slot_available($optionid, $start, $end, $userid),
            'A pending hold should make the single-seat slot full.'
        );

        // The holder re-validating their own target excludes their own hold.
        $this->assertTrue(
            slot_availability::is_slot_available($optionid, $start, $end, $userid, [], 0, $moveid),
            'The holder must not be blocked by their own pending hold.'
        );

        // An expired hold does not block.
        slot_move_store::cancel($moveid);
        $expired = slot_move_store::create_pending(
            $optionid,
            0,
            $userid,
            [['start' => $start, 'end' => $end]],
            [],
            5.0,
            time() - HOURSECS
        );
        $this->assertTrue(
            slot_availability::is_slot_available($optionid, $start, $end, $userid),
            'An expired hold must not occupy capacity.'
        );
        unset($expired);
    }

    /**
     * Create a slotbooking option with a single seat per slot.
     *
     * @return array{0:int,1:int} [optionid, studentid]
     */
    private function create_single_seat_slot_option(): array {
        $course = self::getDataGenerator()->create_course();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance(['course' => $course->id]);
        $student = self::getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $record = [
            'bookingid' => $booking->id,
            'text' => 'Single seat slot option ' . uniqid('', true),
            'course' => $course->id,
            'optiontype' => MOD_BOOKING_OPTIONTYPE_SLOTBOOKING,
            'maxanswers' => 20,
            'slot_enabled' => 1,
            'slot_type' => 'fixed',
            'slot_duration_minutes' => 60,
            'slot_interval_minutes' => 60,
            'slot_custom_max_duration' => 60 * MINSECS,
            'slot_custom_min_duration' => 60 * MINSECS,
            'slot_custom_max_days' => DAYSECS,
            'slot_custom_start_interval_minutes' => 30,
            'slot_opening_time' => '09:00',
            'slot_closing_time' => '12:00',
            'slot_valid_from' => strtotime('2050-01-07 00:00:00 UTC'),
            'slot_valid_until' => strtotime('2050-01-10 23:59:59 UTC'),
            'slot_max_participants_per_slot' => 1,
            'slot_max_slots_per_user' => 3,
            'slot_booking_view_mode' => 'list',
            'slot_add_examiners' => 0,
            'slot_teachers_required' => 0,
            'slot_allow_self_rebooking' => 1,
            'slot_change_deadline_minutes' => '',
        ];
        for ($day = 1; $day <= 7; $day++) {
            $record['slot_day_' . $day] = in_array($day, [1, 5], true) ? 1 : 0;
        }

        $option = $plugingenerator->create_option((object) $record);
        singleton_service::destroy_instance();

        return [(int) $option->id, (int) $student->id];
    }
}
