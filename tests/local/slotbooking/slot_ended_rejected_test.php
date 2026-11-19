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
 * Tests that the server rejects ended slots for every slot type.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use mod_booking\local\slotbooking\slot_availability;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * The pickers never offer ended slots, but a request can send one directly. The server must
 * reject it for every slot type ("session" is covered in slot_session_ended_test).
 *
 * @covers \mod_booking\local\slotbooking\slot_availability::evaluate_slot_for_user
 */
final class slot_ended_rejected_test extends booking_advanced_testcase {
    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Slot types without option dates.
     *
     * @return array[]
     */
    public static function slot_type_provider(): array {
        return [
            'fixed' => ['fixed'],
            'rolling' => ['rolling'],
            'userdefined' => ['userdefined'],
        ];
    }

    /**
     * An ended slot is rejected; a running and a future slot stay bookable.
     *
     * @dataProvider slot_type_provider
     * @param string $slottype
     */
    public function test_ended_slot_is_rejected_on_server(string $slottype): void {
        [$optionid, $userid] = $this->create_slot_option($slottype);
        $now = time();

        $ended = slot_availability::evaluate_slot_for_user($optionid, $now - 2 * DAYSECS, $now - 2 * DAYSECS + HOURSECS, $userid);
        $this->assertFalse($ended['bookable']);
        $this->assertSame(get_string('slot_error_selected_unavailable', 'mod_booking'), $ended['errormessage']);

        $this->assertTrue(
            slot_availability::is_slot_available($optionid, $now - 30 * MINSECS, $now + 30 * MINSECS, $userid),
            'A running slot stays bookable.'
        );
        $this->assertTrue(
            slot_availability::is_slot_available($optionid, $now + 2 * DAYSECS, $now + 2 * DAYSECS + HOURSECS, $userid),
            'A future slot stays bookable.'
        );
    }

    /**
     * Create a slot booking option of the given type, open all day on every day.
     *
     * @param string $slottype
     * @return array{0:int, 1:int} option id and student id
     */
    private function create_slot_option(string $slottype): array {
        $course = self::getDataGenerator()->create_course();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance(['course' => $course->id]);
        $student = self::getDataGenerator()->create_user();

        $record = [
            'bookingid' => $booking->id,
            'text' => 'Slot option ' . uniqid('', true),
            'course' => $course->id,
            'optiontype' => MOD_BOOKING_OPTIONTYPE_SLOTBOOKING,
            'maxanswers' => 20,
            'slot_enabled' => 1,
            'slot_type' => $slottype,
            'slot_duration_minutes' => 60,
            'slot_interval_minutes' => 30,
            'slot_custom_max_duration' => 2 * HOURSECS,
            'slot_custom_min_duration' => 30 * MINSECS,
            'slot_custom_max_days' => DAYSECS,
            'slot_custom_start_interval_minutes' => 30,
            'slot_opening_time' => '00:00',
            'slot_closing_time' => '23:59',
            'slot_valid_from' => 0,
            'slot_valid_until' => 0,
            'slot_max_participants_per_slot' => 3,
            'slot_max_slots_per_user' => 3,
            'slot_booking_view_mode' => 'list',
            'slot_add_examiners' => 0,
            'slot_teachers_required' => 0,
        ];
        for ($day = 1; $day <= 7; $day++) {
            $record['slot_day_' . $day] = 1;
        }

        $option = $plugingenerator->create_option((object)$record);

        singleton_service::destroy_instance();
        return [(int)$option->id, (int)$student->id];
    }
}
