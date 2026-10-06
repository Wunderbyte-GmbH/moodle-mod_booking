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

use bookingextension_agent\local\wizard\services\trial\trial_provisioner;

/**
 * Tests for storing a purchased API key via the provisioner.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\services\trial\trial_provisioner::configure_from_apikey
 */
final class configure_from_apikey_test extends \advanced_testcase {
    /**
     * A malformed key is rejected by the format guard before any network/store call.
     */
    public function test_malformed_key_is_rejected(): void {
        $this->resetAfterTest(true);

        if (
            !class_exists('\\core_ai\\manager')
            || !\core_component::get_plugin_directory('aiprovider', 'wunderbyte')
        ) {
            $this->markTestSkipped('core_ai or aiprovider_wunderbyte not available in this environment');
        }

        // No "sk-" prefix -> rejected by the shape check, deterministically and without an HTTP call.
        $result = (new trial_provisioner())->configure_from_apikey(
            \context_system::instance()->id,
            'definitely-not-a-valid-key'
        );

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['message']);
    }

    /**
     * An empty/whitespace key is rejected too (no key is ever stored).
     */
    public function test_empty_key_is_rejected(): void {
        $this->resetAfterTest(true);

        if (
            !class_exists('\\core_ai\\manager')
            || !\core_component::get_plugin_directory('aiprovider', 'wunderbyte')
        ) {
            $this->markTestSkipped('core_ai or aiprovider_wunderbyte not available in this environment');
        }

        $result = (new trial_provisioner())->configure_from_apikey(\context_system::instance()->id, '   ');

        $this->assertFalse($result['success']);
    }
}
