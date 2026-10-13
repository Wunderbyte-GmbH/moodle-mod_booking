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
use bookingextension_agent\local\wizard\services\security\authorization_service;

/**
 * Once local_wizard has taken over, the bundled engine registers no settings pages at all.
 *
 * The settings were imported into local_wizard, which carries its own pages; the bundled ones must
 * not show up next to them.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\security\authorization_service
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class settings_handover_test extends advanced_testcase {
    /**
     * Build the full admin tree as admin.
     *
     * @return \admin_root
     */
    private function admin_tree(): \admin_root {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $this->setAdminUser();
        return admin_get_root(true, true);
    }

    /**
     * Without local_wizard the bundled engine shows its settings pages.
     */
    public function test_bundled_engine_shows_its_settings(): void {
        $this->resetAfterTest();
        $this->assertFalse(authorization_service::primary_engine_takes_over());

        $root = $this->admin_tree();
        $this->assertNotNull($root->locate('bookingextension_agent_aisettings'));
        $this->assertNotNull($root->locate('bookingextension_agent_benchmark'));
    }

    /**
     * With local_wizard installed none of the bundled engine's settings pages is registered.
     *
     * @runInSeparateProcess
     */
    public function test_no_settings_once_local_wizard_takes_over(): void {
        $this->resetAfterTest();
        $this->add_mocked_plugin('local', 'wizard', __DIR__ . '/fixtures/local_wizard_stub');
        set_config('version', 2026100601, 'local_wizard');
        \core_plugin_manager::reset_caches();
        $this->assertTrue(authorization_service::primary_engine_takes_over());

        $root = $this->admin_tree();
        $this->assertNull($root->locate('bookingextension_agent_aisettings'));
        $this->assertNull($root->locate('bookingextension_agent_benchmark'));
        $this->assertNull($root->locate('bookingextension_agent_skillgovernance'));
    }
}
