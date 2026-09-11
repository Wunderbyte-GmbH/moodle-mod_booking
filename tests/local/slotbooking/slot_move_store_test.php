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
use mod_booking\local\slotbooking\slot_move_store;

/**
 * Tests for the slot_move_store (booking_slot_moves table).
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\slotbooking\slot_move_store
 */
final class slot_move_store_test extends advanced_testcase {
    /**
     * Set up.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * A created pending move is retrievable and counts as an active hold while not expired.
     *
     * @return void
     */
    public function test_create_pending_and_active_hold(): void {
        $now = 1800000000;
        $newslots = [['start' => $now + 3600, 'end' => $now + 7200]];
        $oldslots = [['start' => $now + 100, 'end' => $now + 200]];

        $id = slot_move_store::create_pending(11, 22, 33, $newslots, $oldslots, 5.50, $now + 600);
        $this->assertGreaterThan(0, $id);

        $record = slot_move_store::get($id);
        $this->assertNotNull($record);
        $this->assertEquals(slot_move_store::STATUS_PENDING, (int) $record->status);
        $this->assertEqualsWithDelta(5.50, (float) $record->pricedelta, 0.001);

        // Still pending and not expired -> found for the answer and counted as a hold.
        $this->assertNotNull(slot_move_store::get_pending_for_answer(22, $now));
        $holds = slot_move_store::get_active_holds_for_option(11, $now);
        $this->assertCount(1, $holds);
        $this->assertSame($newslots, $holds[0]['slots']);
        $this->assertSame(22, $holds[0]['baid']);
    }

    /**
     * An expired pending move is no longer an active hold.
     *
     * @return void
     */
    public function test_expired_hold_is_ignored(): void {
        $now = 1800000000;
        slot_move_store::create_pending(11, 22, 33, [['start' => 1, 'end' => 2]], [], 1.0, $now + 600);

        $after = $now + 601; // Past expiry.
        $this->assertNull(slot_move_store::get_pending_for_answer(22, $after));
        $this->assertCount(0, slot_move_store::get_active_holds_for_option(11, $after));
    }

    /**
     * Committing a move links the identifier and removes it from active holds.
     *
     * @return void
     */
    public function test_commit_links_identifier_and_clears_hold(): void {
        $now = 1800000000;
        $id = slot_move_store::create_pending(11, 22, 33, [['start' => 1, 'end' => 2]], [], 1.0, $now + 600);

        slot_move_store::commit($id, 987654);

        $record = slot_move_store::get($id);
        $this->assertEquals(slot_move_store::STATUS_COMMITTED, (int) $record->status);
        $this->assertEquals(987654, (int) $record->identifier);
        $this->assertNull(slot_move_store::get_pending_for_answer(22, $now), 'A committed move is no longer pending.');
        $this->assertCount(0, slot_move_store::get_active_holds_for_option(11, $now));
    }

    /**
     * Cancelling a move removes it from active holds.
     *
     * @return void
     */
    public function test_cancel_clears_hold(): void {
        $now = 1800000000;
        $id = slot_move_store::create_pending(11, 22, 33, [['start' => 1, 'end' => 2]], [], 1.0, $now + 600);

        slot_move_store::cancel($id);

        $this->assertEquals(slot_move_store::STATUS_CANCELLED, (int) slot_move_store::get($id)->status);
        $this->assertNull(slot_move_store::get_pending_for_answer(22, $now));
    }

    /**
     * purge_expired cancels only expired pending moves, leaving fresh ones untouched.
     *
     * @return void
     */
    public function test_purge_expired(): void {
        $now = 1800000000;
        $expired = slot_move_store::create_pending(11, 1, 33, [['start' => 1, 'end' => 2]], [], 1.0, $now - 10);
        $fresh = slot_move_store::create_pending(11, 2, 33, [['start' => 1, 'end' => 2]], [], 1.0, $now + 600);

        slot_move_store::purge_expired($now);

        $this->assertEquals(slot_move_store::STATUS_CANCELLED, (int) slot_move_store::get($expired)->status);
        $this->assertEquals(slot_move_store::STATUS_PENDING, (int) slot_move_store::get($fresh)->status);
    }
}
