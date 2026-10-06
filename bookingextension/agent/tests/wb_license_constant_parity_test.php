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

namespace bookingextension_agent;

use advanced_testcase;
use bookingextension_agent\local\wb_license;

/**
 * Guards the literal product token against drift from mod_booking's constant.
 *
 * wb_license deliberately mirrors wb_payment::PRODUCT_BOOKING_AGENT as a literal so the class
 * loads without mod_booking (the engine also ships standalone as local_wizard). This test pins
 * the two constants together wherever mod_booking is available.
 *
 * @package    bookingextension_agent
 * @category   test
 * @covers \bookingextension_agent\local\wb_license
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class wb_license_constant_parity_test extends advanced_testcase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    public function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }

    /**
     * The mirrored combined-license product token must match mod_booking's constant.
     */
    public function test_combined_product_token_matches_booking(): void {
        $this->assertSame(
            \mod_booking\utils\wb_payment::PRODUCT_BOOKING_AGENT,
            wb_license::PRODUCT_BOOKING_AGENT,
            'wb_license mirrors the token as a literal (load-time decoupling); update it to match wb_payment.'
        );
    }
}
