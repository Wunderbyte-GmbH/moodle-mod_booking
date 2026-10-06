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

namespace bookingextension_agent\local\wizard\testing;

/**
 * Test-time guard for tests that exercise booking-coupled paths.
 *
 * The agent subplugin declares a hard mod_booking dependency, so the guard
 * never skips here. In the generated local_wizard plugin (which drops the
 * mod_booking dependency) the very same test code skips cleanly on sites
 * without mod_booking instead of failing on missing generators or classes.
 *
 * @package     bookingextension_agent
 * @copyright   2026 Wunderbyte GmbH
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_booking_dependency {
    /**
     * Skip the current test when mod_booking is not installed.
     *
     * @return void
     */
    public static function require_installed(): void {
        if (\core_component::get_component_directory('mod_booking') !== null) {
            return;
        }

        \PHPUnit\Framework\Assert::markTestSkipped('Requires mod_booking (booking-coupled test).');
    }
}
