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
 * Tests pinning that the buy-for-user resolution is strictly request-bound.
 *
 * The bookforuser session cache was removed (see Wunderbyte-GmbH/Wunderbyte-GmbH#2214,
 * follow-up to #2191 and the customer report
 * Wunderbyte-GmbH/moodle-taskflowadapter_tuines#154). These tests guarantee that
 * NO session state may ever influence which user mod_booking acts for: only an
 * explicitly passed userid or the request-bound (capability-gated) shopping cart
 * cashier parameter may redirect the resolution.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests for price::return_user_to_buy_for() and the deprecated price::set_bookforuser().
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
     * Tests tear down.
     */
    public function tearDown(): void {
        // The shopping cart cashier mechanism stores its target in the request
        // superglobal - make sure it never leaks into other tests.
        unset($_GET['_buyforuser_']);
        parent::tearDown();
    }

    /**
     * Without any explicit userid, the resolution returns the logged-in user.
     *
     * @covers \mod_booking\price::return_user_to_buy_for
     */
    public function test_argless_resolution_returns_logged_in_user(): void {
        $viewer = $this->getDataGenerator()->create_user();
        $this->setUser($viewer);

        $user = price::return_user_to_buy_for();

        $this->assertEquals($viewer->id, $user->id);
    }

    /**
     * The deprecated set_bookforuser() is a no-op: it must never influence any
     * later resolution, neither argless nor with an explicit self-target.
     *
     * This kills the session leak from taskflowadapter_tuines#154 at the root:
     * there is no session state left that one request could plant for another.
     *
     * @covers \mod_booking\price::set_bookforuser
     * @covers \mod_booking\price::return_user_to_buy_for
     */
    public function test_set_bookforuser_is_a_noop(): void {
        $viewer = $this->getDataGenerator()->create_user();
        $employee = $this->getDataGenerator()->create_user();
        $this->setUser($viewer);

        price::set_bookforuser((int)$employee->id);

        $user = price::return_user_to_buy_for();
        $this->assertEquals($viewer->id, $user->id);

        $user = price::return_user_to_buy_for((int)$viewer->id);
        $this->assertEquals($viewer->id, $user->id);

        // Simulate a request boundary in the same session: still no effect.
        singleton_service::destroy_instance();
        $user = price::return_user_to_buy_for();
        $this->assertEquals($viewer->id, $user->id);
    }

    /**
     * An explicitly passed userid always wins.
     *
     * This is the path all webservices and the cashier/multiuser checkout rely
     * on and must never regress.
     *
     * @covers \mod_booking\price::return_user_to_buy_for
     */
    public function test_explicit_userid_wins(): void {
        $viewer = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->setUser($viewer);

        $user = price::return_user_to_buy_for((int)$other->id);

        $this->assertEquals($other->id, $user->id);
    }

    /**
     * The request-bound shopping cart cashier parameter applies only WITH the
     * cashier capability: a regular user cannot redirect the resolution via the
     * request parameter.
     *
     * @covers \mod_booking\price::return_user_to_buy_for
     */
    public function test_cashier_request_param_is_capability_gated(): void {
        if (!class_exists('local_shopping_cart\shopping_cart')) {
            $this->markTestSkipped('local_shopping_cart is not installed.');
        }

        $viewer = $this->getDataGenerator()->create_user();
        $employee = $this->getDataGenerator()->create_user();
        $this->setUser($viewer);

        // The cashier page / shopping cart webservices seed the target user into
        // the request superglobal - strictly request-bound, gone with the request.
        \local_shopping_cart\shopping_cart::buy_for_user((int)$employee->id);

        // Without the cashier capability the parameter is ignored.
        $user = price::return_user_to_buy_for();
        $this->assertEquals($viewer->id, $user->id);

        // With the cashier capability the parameter applies.
        $systemcontext = \context_system::instance();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/shopping_cart:cashier', CAP_ALLOW, $roleid, $systemcontext->id);
        role_assign($roleid, $viewer->id, $systemcontext->id);

        $user = price::return_user_to_buy_for();
        $this->assertEquals($employee->id, $user->id);
    }
}
