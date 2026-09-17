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
 * Unit tests for booking_option favorites functionality.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests for booking_option favorites methods.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\booking_option
 */
final class booking_option_favorites_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * user_has_favorite returns false for invalid userid.
     *
     * @covers \mod_booking\booking_option::user_has_favorite
     */
    public function test_user_has_favorite_invalid_userid(): void {
        $this->assertFalse(booking_option::user_has_favorite(0, 42));
        $this->assertFalse(booking_option::user_has_favorite(-1, 42));
    }

    /**
     * user_has_favorite returns false for invalid optionid.
     *
     * @covers \mod_booking\booking_option::user_has_favorite
     */
    public function test_user_has_favorite_invalid_optionid(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->assertFalse(booking_option::user_has_favorite($user->id, 0));
        $this->assertFalse(booking_option::user_has_favorite($user->id, -5));
    }

    /**
     * user_has_favorite returns false when preference is not set.
     *
     * @covers \mod_booking\booking_option::user_has_favorite
     */
    public function test_user_has_favorite_no_preference_set(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->assertFalse(booking_option::user_has_favorite($user->id, 99));
    }

    /**
     * user_has_favorite returns true when the optionid is stored in the preference.
     *
     * @covers \mod_booking\booking_option::user_has_favorite
     */
    public function test_user_has_favorite_with_matching_preference(): void {
        $user = $this->getDataGenerator()->create_user();
        set_user_preference('bookingoptionfavorites', json_encode([10, 20, 30]), $user->id);
        $this->assertTrue(booking_option::user_has_favorite($user->id, 10));
        $this->assertTrue(booking_option::user_has_favorite($user->id, 20));
        $this->assertTrue(booking_option::user_has_favorite($user->id, 30));
    }

    /**
     * user_has_favorite returns false when optionid is NOT in the stored preference.
     *
     * @covers \mod_booking\booking_option::user_has_favorite
     */
    public function test_user_has_favorite_with_non_matching_preference(): void {
        $user = $this->getDataGenerator()->create_user();
        set_user_preference('bookingoptionfavorites', json_encode([10, 20]), $user->id);
        $this->assertFalse(booking_option::user_has_favorite($user->id, 99));
    }

    /**
     * get_user_favorite_optionids returns empty array when no preference is set.
     *
     * @covers \mod_booking\booking_option::get_user_favorite_optionids
     */
    public function test_get_user_favorite_optionids_empty(): void {
        $user = $this->getDataGenerator()->create_user();
        $result = booking_option::get_user_favorite_optionids($user->id);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * get_user_favorite_optionids returns the stored ids in insertion order.
     *
     * @covers \mod_booking\booking_option::get_user_favorite_optionids
     */
    public function test_get_user_favorite_optionids_returns_stored(): void {
        $user = $this->getDataGenerator()->create_user();
        set_user_preference('bookingoptionfavorites', json_encode([5, 15, 25]), $user->id);
        $result = booking_option::get_user_favorite_optionids($user->id);
        $this->assertEquals([5, 15, 25], $result);
    }

    /**
     * get_user_favorite_optionids deduplicates on read.
     *
     * @covers \mod_booking\booking_option::get_user_favorite_optionids
     */
    public function test_get_user_favorite_optionids_deduplicates(): void {
        $user = $this->getDataGenerator()->create_user();
        // Bypass the public API to write a raw value with duplicates.
        set_user_preference('bookingoptionfavorites', json_encode([7, 7, 8, 7]), $user->id);
        $result = booking_option::get_user_favorite_optionids($user->id);
        $this->assertEquals([7, 8], $result);
    }

    /**
     * get_user_favorite_optionids filters out non-positive values.
     *
     * @covers \mod_booking\booking_option::get_user_favorite_optionids
     */
    public function test_get_user_favorite_optionids_filters_non_positive(): void {
        $user = $this->getDataGenerator()->create_user();
        set_user_preference('bookingoptionfavorites', json_encode([-1, 0, 3, 'abc', 5]), $user->id);
        $result = booking_option::get_user_favorite_optionids($user->id);
        $this->assertEquals([3, 5], $result);
    }

    /**
     * get_user_favorite_optionids returns empty array for corrupt JSON.
     *
     * @covers \mod_booking\booking_option::get_user_favorite_optionids
     */
    public function test_get_user_favorite_optionids_corrupt_json(): void {
        $user = $this->getDataGenerator()->create_user();
        set_user_preference('bookingoptionfavorites', '{not valid json', $user->id);
        $result = booking_option::get_user_favorite_optionids($user->id);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * toggle_favorite_user returns access-denied error for a guest user.
     *
     * @covers \mod_booking\booking_option::toggle_favorite_user
     */
    public function test_toggle_favorite_user_guest_denied(): void {
        $this->setGuestUser();
        $result = booking_option::toggle_favorite_user(1, 99);
        $this->assertSame(0, $result['status']);
        $this->assertNotEmpty($result['error']);
    }

    /**
     * toggle_favorite_user adds an optionid to favorites (returns status=1).
     *
     * @covers \mod_booking\booking_option::toggle_favorite_user
     */
    public function test_toggle_favorite_user_adds_favorite(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $result = booking_option::toggle_favorite_user($user->id, 42);

        $this->assertSame(1, $result['status']);
        $this->assertSame(42, $result['optionid']);
        $this->assertEmpty($result['error']);
        $this->assertTrue(booking_option::user_has_favorite($user->id, 42));
    }

    /**
     * toggle_favorite_user removes an optionid that was already a favorite (returns status=0).
     *
     * @covers \mod_booking\booking_option::toggle_favorite_user
     */
    public function test_toggle_favorite_user_removes_favorite(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        set_user_preference('bookingoptionfavorites', json_encode([42]), $user->id);

        $result = booking_option::toggle_favorite_user($user->id, 42);

        $this->assertSame(0, $result['status']);
        $this->assertSame(42, $result['optionid']);
        $this->assertEmpty($result['error']);
        $this->assertFalse(booking_option::user_has_favorite($user->id, 42));
    }

    /**
     * toggle_favorite_user is idempotent: toggling twice restores original state.
     *
     * @covers \mod_booking\booking_option::toggle_favorite_user
     */
    public function test_toggle_favorite_user_idempotent_roundtrip(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        booking_option::toggle_favorite_user($user->id, 55);
        $this->assertTrue(booking_option::user_has_favorite($user->id, 55));

        booking_option::toggle_favorite_user($user->id, 55);
        $this->assertFalse(booking_option::user_has_favorite($user->id, 55));
    }

    /**
     * toggle_favorite_user preserves existing favorites when adding a new one.
     *
     * @covers \mod_booking\booking_option::toggle_favorite_user
     */
    public function test_toggle_favorite_user_preserves_existing_favorites(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        set_user_preference('bookingoptionfavorites', json_encode([10, 20]), $user->id);

        booking_option::toggle_favorite_user($user->id, 30);

        $ids = booking_option::get_user_favorite_optionids($user->id);
        $this->assertContains(10, $ids);
        $this->assertContains(20, $ids);
        $this->assertContains(30, $ids);
    }

    /**
     * toggle_favorite_user denies toggling for a different user.
     *
     * @covers \mod_booking\booking_option::toggle_favorite_user
     */
    public function test_toggle_favorite_user_denies_cross_user(): void {
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $this->setUser($user1);

        // Try to toggle a favorite for user2 while logged in as user1.
        $result = booking_option::toggle_favorite_user($user2->id, 42);

        $this->assertSame(0, $result['status']);
        $this->assertNotEmpty($result['error']);
        $this->assertFalse(booking_option::user_has_favorite($user2->id, 42));
    }

    /**
     * toggle_favorite_user returns error for invalid optionid.
     *
     * @covers \mod_booking\booking_option::toggle_favorite_user
     */
    public function test_toggle_favorite_user_invalid_optionid(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $result = booking_option::toggle_favorite_user($user->id, 0);

        $this->assertSame(0, $result['status']);
        $this->assertNotEmpty($result['error']);
    }

    /**
     * Multiple users have independent favorite lists.
     *
     * @covers \mod_booking\booking_option::user_has_favorite
     * @covers \mod_booking\booking_option::toggle_favorite_user
     */
    public function test_favorites_are_user_specific(): void {
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();

        set_user_preference('bookingoptionfavorites', json_encode([100]), $user1->id);
        set_user_preference('bookingoptionfavorites', json_encode([200]), $user2->id);

        $this->assertTrue(booking_option::user_has_favorite($user1->id, 100));
        $this->assertFalse(booking_option::user_has_favorite($user1->id, 200));
        $this->assertFalse(booking_option::user_has_favorite($user2->id, 100));
        $this->assertTrue(booking_option::user_has_favorite($user2->id, 200));
    }
}
