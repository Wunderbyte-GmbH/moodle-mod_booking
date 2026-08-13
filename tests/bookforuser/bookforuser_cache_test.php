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
 * Characterization tests for the bookforuser session cache in mod_booking\price.
 *
 * IMPORTANT: These tests document CURRENT behaviour, including known defects,
 * as a safety net before the planned fix. See Wunderbyte-GmbH/Wunderbyte-GmbH#2191
 * (detailed analysis) and Wunderbyte-GmbH/moodle-taskflowadapter_tuines#154
 * (customer report). The expiry check in price::return_user_to_buy_for() is
 * currently inverted: a VALID cache entry is discarded while an EXPIRED entry
 * keeps being applied. When Phase 1 of #2191 fixes this, the assertions marked
 * "documents inverted expiry" below must be updated deliberately in the same
 * commit as the fix.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use cache;
use mod_booking\tests\booking_advanced_testcase;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Characterization tests for price::set_bookforuser() and price::return_user_to_buy_for().
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class bookforuser_cache_test extends booking_advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
    }

    /**
     * A fresh, still-valid override written via set_bookforuser() is currently DISCARDED.
     *
     * This documents the inverted expiry check (price.php, return_user_to_buy_for):
     * intended behaviour would be to return the override user within the validity window.
     *
     * @covers \mod_booking\price::set_bookforuser
     * @covers \mod_booking\price::return_user_to_buy_for
     */
    public function test_valid_override_is_discarded_documents_inverted_expiry(): void {
        $viewer = $this->getDataGenerator()->create_user();
        $employee = $this->getDataGenerator()->create_user();
        $this->setUser($viewer);

        price::set_bookforuser((int)$employee->id);
        $user = price::return_user_to_buy_for();

        // CURRENT (buggy) behaviour: the valid override is thrown away, the acting user is returned.
        $this->assertEquals($viewer->id, $user->id);
    }

    /**
     * An EXPIRED override currently keeps being applied instead of being discarded.
     *
     * This is the core of the cross-tab leak reported in taskflowadapter_tuines#154:
     * once the 10-second window has passed, the stale entry poisons every following
     * argument-less return_user_to_buy_for() call in the same session.
     *
     * @covers \mod_booking\price::return_user_to_buy_for
     */
    public function test_expired_override_keeps_applying_documents_inverted_expiry(): void {
        $viewer = $this->getDataGenerator()->create_user();
        $employee = $this->getDataGenerator()->create_user();
        $this->setUser($viewer);

        $cache = cache::make('mod_booking', 'bookforuser');
        $cache->set('bookforuser', [(int)$employee->id, time() - 1]);

        $user = price::return_user_to_buy_for();

        // CURRENT (buggy) behaviour: the stale entry wins over the acting user.
        $this->assertEquals($employee->id, $user->id);
    }

    /**
     * The stale entry survives request boundaries and is not cleaned up on read.
     *
     * singleton_service::destroy_instance() simulates the start of a new request in
     * the same Moodle session (MODE_SESSION cache persists). The leak repeats on
     * every read until something overwrites the slot.
     *
     * @covers \mod_booking\price::return_user_to_buy_for
     */
    public function test_expired_override_survives_request_boundary(): void {
        $viewer = $this->getDataGenerator()->create_user();
        $employee = $this->getDataGenerator()->create_user();
        $this->setUser($viewer);

        $cache = cache::make('mod_booking', 'bookforuser');
        $cache->set('bookforuser', [(int)$employee->id, time() - 1]);

        // Simulate a fresh request in the same session.
        singleton_service::destroy_instance();
        $first = price::return_user_to_buy_for();
        $this->assertEquals($employee->id, $first->id);

        // The entry is NOT deleted after being read - the leak repeats.
        singleton_service::destroy_instance();
        $second = price::return_user_to_buy_for();
        $this->assertEquals($employee->id, $second->id);
    }

    /**
     * An explicit foreign userid bypasses the cache entirely.
     *
     * This is the path the cashier/multiuser checkout tests already rely on and
     * must never regress.
     *
     * @covers \mod_booking\price::return_user_to_buy_for
     */
    public function test_explicit_foreign_userid_bypasses_cache(): void {
        $viewer = $this->getDataGenerator()->create_user();
        $employee = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->setUser($viewer);

        $cache = cache::make('mod_booking', 'bookforuser');
        $cache->set('bookforuser', [(int)$employee->id, time() - 1]);

        $user = price::return_user_to_buy_for((int)$other->id);
        $this->assertEquals($other->id, $user->id);
    }

    /**
     * Passing one's OWN userid explicitly still consults the cache and gets hijacked.
     *
     * return_user_to_buy_for($USER->id) enters the cache branch because of the
     * "$USER->id == $userid" condition - so even an explicit self-booking call is
     * redirected to the stale leaked user. This is how the wrong target reaches
     * answer_booking_option() in the reported scenario.
     *
     * @covers \mod_booking\price::return_user_to_buy_for
     */
    public function test_explicit_own_userid_is_hijacked_by_stale_cache(): void {
        $viewer = $this->getDataGenerator()->create_user();
        $employee = $this->getDataGenerator()->create_user();
        $this->setUser($viewer);

        $cache = cache::make('mod_booking', 'bookforuser');
        $cache->set('bookforuser', [(int)$employee->id, time() - 1]);

        $user = price::return_user_to_buy_for((int)$viewer->id);

        // CURRENT (buggy) behaviour: the stale entry overrides the explicit self-target.
        $this->assertEquals($employee->id, $user->id);
    }
}
