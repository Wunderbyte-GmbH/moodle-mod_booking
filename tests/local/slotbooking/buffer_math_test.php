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
use coding_exception;
use mod_booking\local\slotbooking\buffer_math;
use mod_booking\local\slotbooking\buffer_strategy_overlap;
use mod_booking\local\slotbooking\buffer_strategy_summed;

/**
 * Tests for the warmup/cooldown buffer combination strategies and collision math.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\slotbooking\buffer_math
 * @covers     \mod_booking\local\slotbooking\buffer_strategy_summed
 * @covers     \mod_booking\local\slotbooking\buffer_strategy_overlap
 */
final class buffer_math_test extends advanced_testcase {
    /**
     * Summed mode requires the full length of both buffers, one after the other.
     *
     * @return void
     */
    public function test_summed_strategy_adds_both_buffers(): void {
        $strategy = new buffer_strategy_summed();
        $this->assertSame(15, $strategy->required_gap(10, 5));
        $this->assertSame(0, $strategy->required_gap(0, 0));
        $this->assertSame(10, $strategy->required_gap(10, 0));
    }

    /**
     * Overlap mode only requires the longer of the two buffers, since they may share time.
     *
     * @return void
     */
    public function test_overlap_strategy_takes_the_longer_buffer(): void {
        $strategy = new buffer_strategy_overlap();
        $this->assertSame(10, $strategy->required_gap(10, 5));
        $this->assertSame(5, $strategy->required_gap(0, 5));
        $this->assertSame(0, $strategy->required_gap(0, 0));
    }

    /**
     * The factory resolves the stored mode string to the matching strategy instance.
     *
     * @return void
     */
    public function test_create_strategy_resolves_known_modes(): void {
        $this->assertInstanceOf(buffer_strategy_summed::class, buffer_math::create_strategy(buffer_math::MODE_SUMMED));
        $this->assertInstanceOf(buffer_strategy_overlap::class, buffer_math::create_strategy(buffer_math::MODE_OVERLAP));
    }

    /**
     * An unknown mode string is a programmer error, not a silently-tolerated default.
     *
     * @return void
     */
    public function test_create_strategy_rejects_unknown_mode(): void {
        $this->expectException(coding_exception::class);
        buffer_math::create_strategy('nonsense');
    }

    /**
     * Two bookings whose gap exactly matches the required gap do not collide (boundary case).
     *
     * @return void
     */
    public function test_collides_false_when_gap_exactly_matches_required_gap(): void {
        $strategy = new buffer_strategy_summed();
        // Booking A: 09:00-09:30, cooldown 10. Booking B starts at 09:45 (15 min gap), warmup 5.
        // required_gap(10, 5) = 15, gap = 15 -> not a collision ("<", not "<=").
        $enda = 1000 + (30 * MINSECS);
        $startb = $enda + (15 * MINSECS);
        $this->assertFalse(buffer_math::collides(
            1000,
            $enda,
            0,
            10,
            $startb,
            $startb + (30 * MINSECS),
            5,
            0,
            $strategy
        ));
    }

    /**
     * One minute less than the required gap is a collision.
     *
     * @return void
     */
    public function test_collides_true_when_gap_is_one_minute_short(): void {
        $strategy = new buffer_strategy_summed();
        $enda = 1000 + (30 * MINSECS);
        $startb = $enda + (14 * MINSECS);
        $this->assertTrue(buffer_math::collides(
            1000,
            $enda,
            0,
            10,
            $startb,
            $startb + (30 * MINSECS),
            5,
            0,
            $strategy
        ));
    }

    /**
     * The overlap strategy allows a smaller gap than summed would require, for the same
     * warmup/cooldown values.
     *
     * @return void
     */
    public function test_overlap_strategy_allows_smaller_gap_than_summed(): void {
        $enda = 1000 + (30 * MINSECS);
        // 10-minute gap: summed requires 15 (cooldown 10 + warmup 5) -> collision.
        // Overlap requires only max(10, 5) = 10 -> no collision.
        $startb = $enda + (10 * MINSECS);

        $this->assertTrue(buffer_math::collides(
            1000,
            $enda,
            0,
            10,
            $startb,
            $startb + (30 * MINSECS),
            5,
            0,
            new buffer_strategy_summed()
        ));
        $this->assertFalse(buffer_math::collides(
            1000,
            $enda,
            0,
            10,
            $startb,
            $startb + (30 * MINSECS),
            5,
            0,
            new buffer_strategy_overlap()
        ));
    }

    /**
     * collides() is symmetric: it does not matter which booking is passed as A or as B.
     *
     * @return void
     */
    public function test_collides_is_symmetric_regardless_of_argument_order(): void {
        $strategy = new buffer_strategy_summed();
        $enda = 1000 + (30 * MINSECS);
        $startb = $enda + (5 * MINSECS);
        $endb = $startb + (30 * MINSECS);

        $forward = buffer_math::collides(1000, $enda, 0, 10, $startb, $endb, 5, 0, $strategy);
        $backward = buffer_math::collides($startb, $endb, 5, 0, 1000, $enda, 0, 10, $strategy);

        $this->assertTrue($forward);
        $this->assertSame($forward, $backward);
    }

    /**
     * Overlapping core times are a capacity conflict, not a buffer conflict, and are
     * deliberately never flagged here regardless of configured buffers.
     *
     * @return void
     */
    public function test_collides_false_when_core_times_overlap(): void {
        $strategy = new buffer_strategy_summed();
        $this->assertFalse(buffer_math::collides(
            1000,
            1000 + (30 * MINSECS),
            60,
            60,
            1000 + (10 * MINSECS),
            1000 + (40 * MINSECS),
            60,
            60,
            $strategy
        ));
    }

    /**
     * With both buffers at 0, any positive gap (however small) never collides.
     *
     * @return void
     */
    public function test_collides_false_when_buffers_are_disabled(): void {
        $strategy = new buffer_strategy_summed();
        $enda = 1000 + (30 * MINSECS);
        $startb = $enda + 1;
        $this->assertFalse(buffer_math::collides(
            1000,
            $enda,
            0,
            0,
            $startb,
            $startb + (30 * MINSECS),
            0,
            0,
            $strategy
        ));
    }
}
