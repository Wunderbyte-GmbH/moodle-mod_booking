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
 * Tests for the canonical slot DTO builder.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\booking_advanced_testcase;
use mod_booking\form\condition\slotbooking_form;
use mod_booking\local\slotbooking\slot_dto;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * PHPUnit tests for slot_dto.
 *
 * @covers \mod_booking\local\slotbooking\slot_dto
 */
final class slot_dto_test extends booking_advanced_testcase {
    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * The canonical picker slot DTO carries the expected fields and only selectable slots.
     */
    public function test_build_picker_slots_shape(): void {
        [$option, $userid] = $this->create_fixed_slot_option();

        $slots = slot_dto::build_picker_slots($option->id, $userid);
        $this->assertNotEmpty($slots);

        $expectedkeys = [
            'key', 'start', 'end', 'daykey', 'daylabel', 'timelabel', 'statuslabel',
            'status', 'selectable', 'bookable', 'bookings', 'capacity', 'warningmessage',
            'teachers', 'price', 'currency', 'priceformatted',
        ];

        foreach ($slots as $slot) {
            foreach ($expectedkeys as $key) {
                $this->assertArrayHasKey($key, $slot);
            }

            // Only selectable statuses are returned.
            $this->assertContains($slot['status'], ['open', 'warning', 'booked']);

            // Key is the canonical "start:end" pair.
            $this->assertSame($slot['start'] . ':' . $slot['end'], $slot['key']);

            // The picker DTO keeps timelabel clean; the status suffix lives in statuslabel.
            $this->assertStringNotContainsString('(!)', $slot['timelabel']);
            if ($slot['status'] === 'open') {
                $this->assertSame('', $slot['statuslabel']);
            }

            // Only weekday Monday (1) and Friday (5) slots exist for this config.
            $this->assertContains((int)date('N', (int)$slot['start']), [1, 5]);
        }
    }

    /**
     * The form's open-slot shape stays stable while delegating to the DTO.
     *
     * Guards that get_open_slots() returns exactly the expected key set
     * (no DTO-internal fields leaking) and folds the status suffix back into timelabel.
     */
    public function test_get_open_slots_contract(): void {
        [$option, $userid] = $this->create_fixed_slot_option();

        $openslots = $this->invoke_private_static(
            slotbooking_form::class,
            'get_open_slots',
            [$option->id, $userid]
        );
        $this->assertNotEmpty($openslots);

        $expectedkeys = [
            'key', 'start', 'end', 'status', 'selectable',
            'daylabel', 'timelabel', 'teachers', 'price', 'currency', 'priceformatted',
        ];

        foreach ($openslots as $slot) {
            $this->assertSame($expectedkeys, array_keys($slot));
            // DTO-internal helper fields must not leak into the open-slot structure.
            $this->assertArrayNotHasKey('statuslabel', $slot);
            $this->assertArrayNotHasKey('daykey', $slot);

            // For an open slot the timelabel is the clean range (empty status suffix).
            if ($slot['status'] === 'open') {
                $this->assertSame(
                    slot_dto::time_range_label((int)$slot['start'], (int)$slot['end']),
                    $slot['timelabel']
                );
            }
        }
    }

    /**
     * The meta DTO reflects the stored slot configuration.
     */
    public function test_build_meta_reflects_config(): void {
        [$option, $userid] = $this->create_fixed_slot_option();

        $meta = slot_dto::build_meta($option->id, $userid);

        $this->assertSame($option->id, $meta['optionid']);
        $this->assertSame($userid, $meta['userid']);
        $this->assertSame('fixed', $meta['slottype']);
        $this->assertSame('list', $meta['viewmode']);
        $this->assertSame(3, $meta['maxselection']);
        $this->assertSame(0, $meta['teachersrequired']);
        $this->assertArrayHasKey('useprices', $meta);
        $this->assertArrayHasKey('timezone', $meta);
        $this->assertSame(strtotime('2050-01-07 00:00:00 UTC'), $meta['validfrom']);
    }

    /**
     * Formatting helpers produce the expected label / price shapes.
     */
    public function test_format_helpers(): void {
        $start = strtotime('2050-01-07 09:00:00 UTC');
        $end = strtotime('2050-01-07 10:00:00 UTC');

        $this->assertNotEmpty(slot_dto::day_label($start));
        $this->assertStringContainsString(' - ', slot_dto::time_range_label($start, $end));

        [$option, $userid] = $this->create_fixed_slot_option();
        $pricedata = slot_dto::price_data($option->id, $start, $end, $userid);

        foreach (['price', 'currency', 'priceformatted', 'pricecategoryidentifier'] as $key) {
            $this->assertArrayHasKey($key, $pricedata);
        }
        $this->assertIsFloat($pricedata['price']);
        $this->assertIsString($pricedata['priceformatted']);
        if ($pricedata['currency'] !== '') {
            $this->assertStringEndsWith($pricedata['currency'], $pricedata['priceformatted']);
        }
    }

    /**
     * Create a fixed-slot booking option (Mon + Fri, 09:00-12:00, 60-min slots) in 2050.
     *
     * @return array{0:\stdClass, 1:int} option and a (non-enrolled) student user id
     */
    private function create_fixed_slot_option(): array {
        $course = self::getDataGenerator()->create_course();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance(['course' => $course->id]);
        $student = self::getDataGenerator()->create_user();

        $record = [
            'bookingid' => $booking->id,
            'text' => 'Slot DTO option ' . uniqid('', true),
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
            'slot_max_participants_per_slot' => 3,
            'slot_max_slots_per_user' => 3,
            'slot_booking_view_mode' => 'list',
            'slot_add_examiners' => 0,
            'slot_teachers_required' => 0,
        ];
        // Allow Monday (1) and Friday (5) only.
        for ($day = 1; $day <= 7; $day++) {
            $record['slot_day_' . $day] = in_array($day, [1, 5], true) ? 1 : 0;
        }

        $option = $plugingenerator->create_option((object)$record);

        singleton_service::destroy_instance();
        return [$option, (int)$student->id];
    }

    /**
     * Call a private static method via reflection.
     *
     * @param string $classname
     * @param string $methodname
     * @param array $args
     * @return mixed
     */
    private function invoke_private_static(string $classname, string $methodname, array $args = []) {
        $method = new \ReflectionMethod($classname, $methodname);
        $method->setAccessible(true);

        return $method->invokeArgs(null, $args);
    }
}
