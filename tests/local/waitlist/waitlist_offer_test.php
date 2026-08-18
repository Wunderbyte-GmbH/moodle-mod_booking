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
 * Tests for the waitlist_offer entity (WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §2.1) - a
 * pure data holder, no DB needed.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist;

use mod_booking\local\waitlist\offer_statuses\pending;
use mod_booking\local\waitlist\offer_statuses\declined;

/**
 * Construction/property tests for waitlist_offer.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitlist\waitlist_offer
 */
final class waitlist_offer_test extends \advanced_testcase {
    /**
     * All thirteen constructor arguments must land on the correspondingly named property,
     * unchanged - a plain field-by-field round-trip check.
     */
    public function test_constructor_sets_all_properties(): void {
        $status = new pending();
        $offer = new waitlist_offer(
            11, // Id.
            22, // Optionid.
            33, // Userid.
            44, // Baid.
            55, // Roundid.
            $status,
            66, // Sortorder.
            77, // Offeredat.
            88, // Expiresat.
            99, // Ruleid.
            1, // Version.
            111, // Timecreated.
            222 // Timemodified.
        );

        $this->assertEquals(11, $offer->id);
        $this->assertEquals(22, $offer->optionid);
        $this->assertEquals(33, $offer->userid);
        $this->assertEquals(44, $offer->baid);
        $this->assertEquals(55, $offer->roundid);
        $this->assertSame($status, $offer->status);
        $this->assertEquals(66, $offer->sortorder);
        $this->assertEquals(77, $offer->offeredat);
        $this->assertEquals(88, $offer->expiresat);
        $this->assertEquals(99, $offer->ruleid);
        $this->assertEquals(1, $offer->version);
        $this->assertEquals(111, $offer->timecreated);
        $this->assertEquals(222, $offer->timemodified);
    }

    /**
     * The status property must accept any offer_status implementation, not just one hardcoded
     * type - the entity must stay agnostic to which concrete state is currently held.
     */
    public function test_status_property_accepts_any_offer_status_implementation(): void {
        $pendingoffer = new waitlist_offer(1, 1, 1, 1, 1, new pending(), 0, 0, 0, 0, 1, 0, 0);
        $this->assertInstanceOf(pending::class, $pendingoffer->status);

        $declinedoffer = new waitlist_offer(1, 1, 1, 1, 1, new declined(), 0, 0, 0, 0, 1, 0, 0);
        $this->assertInstanceOf(declined::class, $declinedoffer->status);
    }
}
