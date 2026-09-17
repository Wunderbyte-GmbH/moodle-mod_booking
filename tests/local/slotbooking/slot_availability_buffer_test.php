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
use mod_booking\local\slotbooking\buffer_math;
use mod_booking\local\slotbooking\slot_availability;
use mod_booking\local\slotbooking\slot_move_store;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests for the warmup/cooldown buffer: fixed-slot generation cadence and the dynamic
 * collision gate (which also covers "userdefined"/dynamic bookings, since it is the same
 * slot-type-agnostic hook).
 *
 * Assertions deliberately compare durations/gaps/counts rather than predicted absolute
 * timestamps: get_slots_for_range() anchors its day boundaries with plain strtotime('midnight',
 * ...), which follows the *PHP process* default timezone. Moodle's phpunit bootstrap
 * randomises that timezone per run specifically to catch this class of bug, so an
 * independently pre-computed "day open" timestamp is not a safe expectation to assert against.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\slotbooking\slot_availability::get_slots_for_range
 * @covers     \mod_booking\local\slotbooking\slot_availability::has_buffer_conflict
 * @covers     \mod_booking\local\slotbooking\slot_availability::evaluate_slot_for_user
 */
final class slot_availability_buffer_test extends booking_advanced_testcase {
    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * With no buffer configured, the generated cadence is exactly the slot duration
     * (backward compatible with options that predate this feature): slots are back-to-back,
     * with the second slot's start equal to the first slot's end.
     *
     * @return void
     */
    public function test_fixed_cycle_without_buffer_is_back_to_back(): void {
        [$optionid, $day] = $this->create_fixed_slot_option([
            'slot_duration_minutes' => 30,
            'slot_buffer_warmup_minutes' => 0,
            'slot_buffer_cooldown_minutes' => 0,
            'slot_opening_time' => '08:00',
            'slot_closing_time' => '09:00',
        ]);

        $slots = $this->slots_for_test_day($optionid, $day);

        $this->assertCount(2, $slots);
        $this->assertSame(30 * MINSECS, $slots[0][1] - $slots[0][0], 'First slot must be 30 minutes.');
        $this->assertSame(30 * MINSECS, $slots[1][1] - $slots[1][0], 'Second slot must be 30 minutes.');
        $this->assertSame($slots[0][1], $slots[1][0], 'Slots must be back-to-back with no gap.');
    }

    /**
     * Summed mode: cycle = warmup + duration + cooldown, i.e. the gap between one core slot's
     * end and the next core slot's start equals cooldown + warmup (the ticket's own worked
     * example: 30 min slot, 5 min warmup, 10 min cooldown -> 15-minute gap, 45-minute cycle).
     *
     * @return void
     */
    public function test_fixed_cycle_summed_mode_bakes_warmup_and_cooldown(): void {
        [$optionid, $day] = $this->create_fixed_slot_option([
            'slot_duration_minutes' => 30,
            'slot_buffer_warmup_minutes' => 5,
            'slot_buffer_cooldown_minutes' => 10,
            'slot_buffer_combination_mode' => buffer_math::MODE_SUMMED,
            'slot_opening_time' => '08:00',
            'slot_closing_time' => '10:00',
        ]);

        $slots = $this->slots_for_test_day($optionid, $day);

        $this->assertCount(2, $slots);
        $this->assertSame(30 * MINSECS, $slots[0][1] - $slots[0][0]);
        $this->assertSame(30 * MINSECS, $slots[1][1] - $slots[1][0]);
        $this->assertSame(15 * MINSECS, $slots[1][0] - $slots[0][1], 'Gap must equal cooldown(10) + warmup(5).');
    }

    /**
     * Overlap mode: the required gap is only the longer of the two buffers, so the gap between
     * slots is shorter than summed mode would produce for the same warmup/cooldown values.
     *
     * @return void
     */
    public function test_fixed_cycle_overlap_mode_uses_shorter_cycle(): void {
        [$optionid, $day] = $this->create_fixed_slot_option([
            'slot_duration_minutes' => 30,
            'slot_buffer_warmup_minutes' => 5,
            'slot_buffer_cooldown_minutes' => 10,
            'slot_buffer_combination_mode' => buffer_math::MODE_OVERLAP,
            'slot_opening_time' => '08:00',
            'slot_closing_time' => '10:00',
        ]);

        $slots = $this->slots_for_test_day($optionid, $day);

        $this->assertCount(2, $slots);
        $this->assertSame(10 * MINSECS, $slots[1][0] - $slots[0][1], 'Gap must equal max(cooldown 10, warmup 5).');
    }

    /**
     * A slot is only generated if its cooldown also fits before closing time, not just its
     * core time. With opening=08:00, duration=30, warmup=5, cooldown=10, the only candidate
     * cycle's core would end 35 minutes after opening (fits under a naive core-only check
     * against a 40-minute-wide window), but its cooldown needs 45 minutes -> must be excluded
     * entirely.
     *
     * @return void
     */
    public function test_last_slot_is_dropped_when_its_cooldown_would_cross_closing_time(): void {
        [$optionid, $day] = $this->create_fixed_slot_option([
            'slot_duration_minutes' => 30,
            'slot_buffer_warmup_minutes' => 5,
            'slot_buffer_cooldown_minutes' => 10,
            'slot_buffer_combination_mode' => buffer_math::MODE_SUMMED,
            'slot_opening_time' => '08:00',
            'slot_closing_time' => '08:40',
        ]);

        $slots = $this->slots_for_test_day($optionid, $day);

        $this->assertSame([], $slots);
    }

    /**
     * The same setup with just enough extra closing-time headroom (45-minute-wide window) for
     * the cooldown to fit produces exactly the one slot, confirming the boundary is inclusive.
     *
     * @return void
     */
    public function test_last_slot_is_kept_when_its_cooldown_exactly_fits_before_closing(): void {
        [$optionid, $day] = $this->create_fixed_slot_option([
            'slot_duration_minutes' => 30,
            'slot_buffer_warmup_minutes' => 5,
            'slot_buffer_cooldown_minutes' => 10,
            'slot_buffer_combination_mode' => buffer_math::MODE_SUMMED,
            'slot_opening_time' => '08:00',
            'slot_closing_time' => '08:45',
        ]);

        $slots = $this->slots_for_test_day($optionid, $day);

        $this->assertCount(1, $slots);
        $this->assertSame(30 * MINSECS, $slots[0][1] - $slots[0][0]);
    }

    /**
     * has_buffer_conflict() blocks a candidate slot that falls inside another booking's buffer
     * (simulated via a pending hold, the same mechanism slot_availability_holds_test uses), and
     * respects the configured combination mode's required gap exactly at the boundary.
     *
     * @return void
     */
    public function test_has_buffer_conflict_respects_combination_mode(): void {
        [$optionid, $day] = $this->create_fixed_slot_option([
            'slot_duration_minutes' => 30,
            'slot_buffer_warmup_minutes' => 5,
            'slot_buffer_cooldown_minutes' => 10,
            'slot_buffer_combination_mode' => buffer_math::MODE_SUMMED,
        ]);
        $user = self::getDataGenerator()->create_user();

        $heldstart = $day + (9 * HOURSECS);
        $heldend = $heldstart + (30 * MINSECS);
        slot_move_store::create_pending(
            $optionid,
            0,
            $user->id,
            [['start' => $heldstart, 'end' => $heldend]],
            [],
            5.0,
            time() + HOURSECS
        );

        // Summed mode requires a 15-minute gap (cooldown 10 + warmup 5). 14 minutes collides,
        // exactly 15 minutes does not.
        $tooclosestart = $heldend + (14 * MINSECS);
        $this->assertTrue(
            slot_availability::has_buffer_conflict($optionid, $tooclosestart, $tooclosestart + (30 * MINSECS)),
            'A candidate slot one minute inside the required gap must be flagged as a buffer conflict.'
        );

        $exactgapstart = $heldend + (15 * MINSECS);
        $this->assertFalse(
            slot_availability::has_buffer_conflict($optionid, $exactgapstart, $exactgapstart + (30 * MINSECS)),
            'A candidate slot exactly at the required gap must not be flagged.'
        );
    }

    /**
     * With buffer_warmup_minutes = buffer_cooldown_minutes = 0, has_buffer_conflict() is a
     * guaranteed no-op (short-circuits before touching the booked-slot cache at all) - the
     * acceptance criterion "0 = disabled, no performance impact".
     *
     * @return void
     */
    public function test_has_buffer_conflict_is_noop_when_buffer_disabled(): void {
        [$optionid, $day] = $this->create_fixed_slot_option([
            'slot_duration_minutes' => 30,
            'slot_buffer_warmup_minutes' => 0,
            'slot_buffer_cooldown_minutes' => 0,
        ]);
        $user = self::getDataGenerator()->create_user();

        $heldstart = $day + (9 * HOURSECS);
        $heldend = $heldstart + (30 * MINSECS);
        slot_move_store::create_pending(
            $optionid,
            0,
            $user->id,
            [['start' => $heldstart, 'end' => $heldend]],
            [],
            5.0,
            time() + HOURSECS
        );

        // Immediately adjacent (0-minute gap) to the held slot: would collide under any
        // positive buffer, but must not be flagged once warmup/cooldown are both 0.
        $this->assertFalse(
            slot_availability::has_buffer_conflict($optionid, $heldend, $heldend + (30 * MINSECS))
        );
    }

    /**
     * Variante B / dynamic (userdefined) bookings: evaluate_slot_for_user() is the single,
     * slot-type-agnostic gate used by the form validation, the hard-block check and the live
     * commit re-check alike (see classes/form/condition/slotbooking_form.php validation() and
     * classes/bo_availability/conditions/slotbooking.php hard_block()/add_json_to_booking_
     * answer()). Since has_buffer_conflict() is wired into that same shared gate, a freely
     * chosen (not grid-aligned) start/end already respects the configured buffer with no
     * separate code path - this test is the regression guard for that architectural claim.
     *
     * @return void
     */
    public function test_userdefined_dynamic_slot_respects_buffer_via_shared_evaluator(): void {
        [$optionid, $day] = $this->create_fixed_slot_option([
            'slot_type' => 'userdefined',
            'slot_buffer_warmup_minutes' => 10,
            'slot_buffer_cooldown_minutes' => 10,
            'slot_buffer_combination_mode' => buffer_math::MODE_SUMMED,
        ]);
        $user = self::getDataGenerator()->create_user();

        // An existing dynamic booking with a freely chosen, non-grid-aligned time.
        $heldstart = $day + (9 * HOURSECS) + (37 * MINSECS);
        $heldend = $heldstart + (53 * MINSECS);
        slot_move_store::create_pending(
            $optionid,
            0,
            $user->id,
            [['start' => $heldstart, 'end' => $heldend]],
            [],
            5.0,
            time() + HOURSECS
        );

        // Required gap = 10 + 10 = 20 minutes. A freely chosen candidate 12 minutes after the
        // held booking's end collides.
        $collidingstart = $heldend + (12 * MINSECS);
        $collision = slot_availability::evaluate_slot_for_user(
            $optionid,
            $collidingstart,
            $collidingstart + (20 * MINSECS),
            $user->id
        );
        $this->assertFalse($collision['bookable']);
        $this->assertSame(get_string('slot_error_buffer_conflict', 'mod_booking'), $collision['errormessage']);

        // A freely chosen candidate a full hour after the held booking's end does not collide.
        $freestart = $heldend + HOURSECS;
        $free = slot_availability::evaluate_slot_for_user(
            $optionid,
            $freestart,
            $freestart + (20 * MINSECS),
            $user->id
        );
        $this->assertTrue($free['bookable']);
    }

    /**
     * Generated slots for the option's single configured day, sorted by start time. Passes a
     * range with a full day of margin on both sides of $day, so the option's own valid_from/
     * valid_until (both pinned to $day in create_fixed_slot_option()) are what actually bound
     * the scan - this call's own arguments only need to overlap that window, not match it
     * exactly, which sidesteps get_slots_for_range()'s timezone-sensitive day-boundary maths.
     *
     * @param int $optionid booking option id
     * @param int $day the option's valid_from/valid_until anchor (unix timestamp)
     * @return array<int, array{0:int,1:int}>
     */
    private function slots_for_test_day(int $optionid, int $day): array {
        $slots = slot_availability::get_slots_for_range($optionid, $day - DAYSECS, $day + (2 * DAYSECS));
        usort($slots, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        return $slots;
    }

    /**
     * Create a slotbooking option on a fixed, far-future day with every weekday enabled (so
     * day-of-week never matters).
     *
     * @param array $overrides option-form field overrides (slot_* keys, see classes/option/
     *                         fields/slotbooking.php)
     * @return array{0:int,1:int} [optionid, day anchor (unix timestamp, UTC midnight)]
     */
    private function create_fixed_slot_option(array $overrides): array {
        $course = self::getDataGenerator()->create_course();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance(['course' => $course->id]);

        $day = strtotime('2050-01-07 00:00:00 UTC');

        $record = array_merge([
            'bookingid' => $booking->id,
            'text' => 'Buffer test option ' . uniqid('', true),
            'course' => $course->id,
            'optiontype' => MOD_BOOKING_OPTIONTYPE_SLOTBOOKING,
            'maxanswers' => 20,
            'slot_enabled' => 1,
            'slot_type' => 'fixed',
            'slot_duration_minutes' => 30,
            'slot_interval_minutes' => 30,
            'slot_custom_max_duration' => 60 * MINSECS,
            'slot_custom_min_duration' => 30 * MINSECS,
            'slot_custom_max_days' => DAYSECS,
            'slot_custom_start_interval_minutes' => 30,
            'slot_opening_time' => '08:00',
            'slot_closing_time' => '18:00',
            'slot_valid_from' => $day,
            'slot_valid_until' => $day,
            'slot_max_participants_per_slot' => 5,
            'slot_max_slots_per_user' => 5,
            'slot_booking_view_mode' => 'list',
            'slot_add_examiners' => 0,
            'slot_teachers_required' => 0,
            'slot_allow_self_rebooking' => 1,
            'slot_change_deadline_minutes' => '',
            'slot_buffer_warmup_minutes' => 0,
            'slot_buffer_cooldown_minutes' => 0,
            'slot_buffer_combination_mode' => buffer_math::MODE_SUMMED,
        ], $overrides);
        for ($weekday = 1; $weekday <= 7; $weekday++) {
            $record['slot_day_' . $weekday] = 1;
        }

        $option = $plugingenerator->create_option((object) $record);
        singleton_service::destroy_instance();

        return [(int) $option->id, $day];
    }
}
