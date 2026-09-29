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

namespace bookingextension_todolist;

use advanced_testcase;
use mod_booking\singleton_service;

/**
 * Tests for todolist extension class behavior.
 *
 * @package     bookingextension_todolist
 * @category    test
 * @covers      \bookingextension_todolist\todolist
 * @copyright   2026 Wunderbyte GmbH
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class todolist_extension_test extends advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    public function tearDown(): void {
        singleton_service::destroy_instance();
        parent::tearDown();
    }

    public function test_contains_option_fields_true_by_default(): void {
        set_config('enableglobally', null, 'bookingextension_todolist');

        $plugin = new todolist();
        $this->assertTrue($plugin->contains_option_fields());
    }

    public function test_contains_option_fields_false_when_disabled_globally(): void {
        set_config('enableglobally', 0, 'bookingextension_todolist');

        $plugin = new todolist();
        $this->assertFalse($plugin->contains_option_fields());
    }

    public function test_contains_option_fields_true_when_enabled_globally(): void {
        set_config('enableglobally', 1, 'bookingextension_todolist');

        $plugin = new todolist();
        $this->assertTrue($plugin->contains_option_fields());
    }
}
