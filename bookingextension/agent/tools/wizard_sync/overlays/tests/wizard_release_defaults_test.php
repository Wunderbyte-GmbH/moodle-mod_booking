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

namespace local_wizard;

use advanced_testcase;
use local_wizard\local\wizard\services\security\authorization_service;

/**
 * Release defaults of the standalone Wizard: switched on, no experimental badge.
 *
 * This file is an OVERLAY maintained in the source plugin under tools/wizard_sync/overlays/; it exists only in the
 * generated local_wizard artifact. The bundled engine keeps its own defaults (off, experimental badge).
 *
 * @package    local_wizard
 * @covers     ::xmldb_local_wizard_upgrade
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class wizard_release_defaults_test extends advanced_testcase {
    /** @var int Last release that installed the switch as off. */
    private const VERSION_DEFAULT_OFF = 2026101500;

    /**
     * Load the upgrade hook.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/wizard/db/upgrade.php');
    }

    /**
     * A fresh install stores the switch as on, so the Wizard works right after installation.
     */
    public function test_install_stores_switch_on(): void {
        $this->assertSame('1', get_config('local_wizard', 'agent_enabled'));
        $this->assertTrue(authorization_service::is_agent_enabled());
    }

    /**
     * Neither the switch label nor the settings badge carries the experimental badge markup.
     */
    public function test_no_experimental_badge(): void {
        foreach (['en', 'de'] as $lang) {
            foreach (['agent_enabled', 'badge_experimental'] as $key) {
                $string = get_string_manager()->get_string($key, 'local_wizard', null, $lang);
                $this->assertStringNotContainsString('badge', $string, "{$lang}/{$key}");
            }
        }
    }

    /**
     * The upgrade switches on a site that still holds the old default: the value was only ever written by the
     * installer (config log entry without an old value).
     */
    public function test_upgrade_switches_on_untouched_default(): void {
        global $DB;
        $this->resetAfterTest();
        $DB->delete_records('config_log', ['plugin' => 'local_wizard', 'name' => 'agent_enabled']);
        $this->log_setting('local_wizard', null, '0');
        set_config('agent_enabled', 0, 'local_wizard');
        set_config('version', self::VERSION_DEFAULT_OFF, 'local_wizard');

        $this->assertTrue(xmldb_local_wizard_upgrade(self::VERSION_DEFAULT_OFF));

        $this->assertSame('1', get_config('local_wizard', 'agent_enabled'));
    }

    /**
     * An admin who switched the Wizard off keeps it off.
     */
    public function test_upgrade_keeps_admin_choice(): void {
        global $DB;
        $this->resetAfterTest();
        $DB->delete_records('config_log', ['plugin' => 'local_wizard', 'name' => 'agent_enabled']);
        $this->log_setting('local_wizard', null, '1');
        $this->log_setting('local_wizard', '1', '0');
        set_config('agent_enabled', 0, 'local_wizard');
        set_config('version', self::VERSION_DEFAULT_OFF, 'local_wizard');

        $this->assertTrue(xmldb_local_wizard_upgrade(self::VERSION_DEFAULT_OFF));

        $this->assertSame('0', get_config('local_wizard', 'agent_enabled'));
    }

    /**
     * A value adopted from the bundled engine on takeover keeps the admin choice made there.
     */
    public function test_upgrade_keeps_adopted_admin_choice(): void {
        global $DB;
        $this->resetAfterTest();
        $DB->delete_records('config_log', ['plugin' => 'local_wizard', 'name' => 'agent_enabled']);
        $this->log_setting('bookingextension_agent', '1', '0');
        set_config('agent_enabled', 0, 'local_wizard');
        set_config('version', self::VERSION_DEFAULT_OFF, 'local_wizard');

        $this->assertTrue(xmldb_local_wizard_upgrade(self::VERSION_DEFAULT_OFF));

        $this->assertSame('0', get_config('local_wizard', 'agent_enabled'));
    }

    /**
     * Sites that already run a release with the switch on are not touched again.
     */
    public function test_upgrade_from_newer_release_is_noop(): void {
        global $DB;
        $this->resetAfterTest();
        $DB->delete_records('config_log', ['plugin' => 'local_wizard', 'name' => 'agent_enabled']);
        set_config('agent_enabled', 0, 'local_wizard');

        $this->assertTrue(xmldb_local_wizard_upgrade(self::VERSION_DEFAULT_OFF + 1));

        $this->assertSame('0', get_config('local_wizard', 'agent_enabled'));
    }

    /**
     * Write one config log row for agent_enabled.
     *
     * @param string $plugin
     * @param string|null $oldvalue
     * @param string $value
     */
    private function log_setting(string $plugin, ?string $oldvalue, string $value): void {
        global $DB;
        $DB->insert_record('config_log', (object) [
            'userid' => 2,
            'timemodified' => time(),
            'plugin' => $plugin,
            'name' => 'agent_enabled',
            'value' => $value,
            'oldvalue' => $oldvalue,
        ]);
    }
}
