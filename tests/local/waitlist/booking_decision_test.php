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
 * Tests for the booking_decision enum (WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §3.1).
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist;

/**
 * Enum shape tests.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitlist\booking_decision
 */
final class booking_decision_test extends \advanced_testcase {
    /**
     * Exactly the two documented cases must exist, with the expected backing values.
     */
    public function test_has_exactly_the_two_documented_cases(): void {
        $this->assertEquals('autobook', booking_decision::AUTOBOOK->value);
        $this->assertEquals('offer', booking_decision::OFFER->value);
        $this->assertCount(
            2,
            booking_decision::cases(),
            'booking_decision must have exactly two cases: AUTOBOOK and OFFER.'
        );
    }
}
