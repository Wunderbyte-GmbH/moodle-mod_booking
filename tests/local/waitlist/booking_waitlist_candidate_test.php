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
 * Tests for the booking_waitlist_candidate entity (WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md
 * §3.1) - a pure data holder, no DB needed.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist;

/**
 * Constructor/property tests for booking_waitlist_candidate.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitlist\booking_waitlist_candidate
 */
final class booking_waitlist_candidate_test extends \advanced_testcase {
    /**
     * All four constructor arguments must land on the correspondingly named property.
     */
    public function test_constructor_sets_all_properties(): void {
        $user = (object) ['id' => 33, 'firstname' => 'Test'];
        $candidate = new booking_waitlist_candidate(11, 33, 44, $user);

        $this->assertEquals(11, $candidate->optionid);
        $this->assertEquals(33, $candidate->userid);
        $this->assertEquals(44, $candidate->baid);
        $this->assertSame($user, $candidate->user);
    }
}
