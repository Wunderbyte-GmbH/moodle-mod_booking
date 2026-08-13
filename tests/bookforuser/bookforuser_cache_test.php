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
 * Tests for the bookforuser session cache in mod_booking\price.
 *
 * These tests pin the contract of the short-lived bookforuser override
 * (see Wunderbyte-GmbH/Wunderbyte-GmbH#2191 and the customer report
 * Wunderbyte-GmbH/moodle-taskflowadapter_tuines#154): a VALID override applies
 * within its 10 second window, an EXPIRED entry is discarded AND deleted so it
 * can never poison later requests of the same session again.
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
 * Tests for price::set_bookforuser() and price::return_user_to_buy_for().
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
     * A fresh, still-valid override written via set_bookforuser() is applied.
     *
     * This is the flow local_taskflow and the bookit webservice rely on:
     * the override targets the given user within the validity window.
     *
     * @covers \mod_booking\price::set_bookforuser
     * @covers \mod_booking\price::return_user_to_buy_for
     */
    public function test_valid_override_is_applied(): void {
        $viewer = $this->getDataGenerator()->create_user();
        $employee = $this->getDataGenerator()->create_user();
        $this->setUser($viewer);

        price::set_bookforuser((int)$employee->id);
        $user = price::return_user_to_buy_for();

        $this->assertEquals($employee->id, $user->id);
    }

    /**
     * An EXPIRED override is discarded and DELETED from the cache.
     *
     * This kills the cross-tab leak reported in taskflowadapter_tuines#154:
     * once the validity window has passed, the entry may never be applied again.
     *
     * @covers \mod_booking\price::return_user_to_buy_for
     */
    public function test_expired_override_is_discarded_and_deleted(): void {
        $viewer = $this->getDataGenerator()->create_user();
        $employee = $this->getDataGenerator()->create_user();
        $this->setUser($viewer);

        $cache = cache::make('mod_booking', 'bookforuser');
        $cache->set('bookforuser', [(int)$employee->id, time() - 1]);

        $user = price::return_user_to_buy_for();

        $this->assertEquals($viewer->id, $user->id);
        // The stale entry has been cleaned up for good.
        $this->assertFalse($cache->get('bookforuser'));
    }

    /**
     * A valid override survives a request boundary within its window, an expired
     * one is cleaned up across request boundaries too.
     *
     * singleton_service::destroy_instance() simulates the start of a new request
     * in the same Moodle session (MODE_SESSION cache persists).
     *
     * @covers \mod_booking\price::return_user_to_buy_for
     */
    public function test_override_lifecycle_across_request_boundaries(): void {
        $viewer = $this->getDataGenerator()->create_user();
        $employee = $this->getDataGenerator()->create_user();
        $this->setUser($viewer);

        // Valid override set in "request 1" still applies in "request 2".
        price::set_bookforuser((int)$employee->id);
        singleton_service::destroy_instance();
        $first = price::return_user_to_buy_for();
        $this->assertEquals($employee->id, $first->id);

        // An expired entry is discarded in a later request as well.
        $cache = cache::make('mod_booking', 'bookforuser');
        $cache->set('bookforuser', [(int)$employee->id, time() - 1]);
        singleton_service::destroy_instance();
        $second = price::return_user_to_buy_for();
        $this->assertEquals($viewer->id, $second->id);
        $this->assertFalse($cache->get('bookforuser'));
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
     * Passing one's OWN userid explicitly consults the cache: a valid override
     * still wins (intended, e.g. bookit webservice re-rendering), while a stale
     * entry no longer hijacks the call.
     *
     * @covers \mod_booking\price::return_user_to_buy_for
     */
    public function test_explicit_own_userid_follows_override_lifecycle(): void {
        $viewer = $this->getDataGenerator()->create_user();
        $employee = $this->getDataGenerator()->create_user();
        $this->setUser($viewer);

        // Valid override wins over the explicit self-target.
        price::set_bookforuser((int)$employee->id);
        $user = price::return_user_to_buy_for((int)$viewer->id);
        $this->assertEquals($employee->id, $user->id);

        // A stale entry does NOT hijack the explicit self-target.
        $cache = cache::make('mod_booking', 'bookforuser');
        $cache->set('bookforuser', [(int)$employee->id, time() - 1]);
        $user = price::return_user_to_buy_for((int)$viewer->id);
        $this->assertEquals($viewer->id, $user->id);
    }
}
