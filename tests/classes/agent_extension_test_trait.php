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

namespace mod_booking\tests;

/**
 * Test helper to skip cases that depend on the optional bookingextension_agent subplugin.
 *
 * mod_booking ships the agent skill classes (mod_booking\local\wizard\...) but they extend the
 * base_skill / interfaces provided by the bookingextension_agent subplugin. That subplugin is
 * optional and lives in its own repository, so it may be absent (e.g. in CI where it is not part of
 * the plugin set). Any test that instantiates an agent skill must therefore skip when the subplugin
 * is not installed, otherwise loading the skill fatals with a "class not found" error and the whole
 * mod_booking suite fails. Call {@see self::skip_without_agent_extension()} from setUp().
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait agent_extension_test_trait {
    /**
     * Marks the current test as skipped when the bookingextension_agent subplugin is not installed.
     *
     * @return void
     */
    protected function skip_without_agent_extension(): void {
        if (!class_exists('bookingextension_agent\local\wizard\base_skill')) {
            $this->markTestSkipped(
                'The bookingextension_agent subplugin is not installed; agent skill tests require it.'
            );
        }
    }
}
