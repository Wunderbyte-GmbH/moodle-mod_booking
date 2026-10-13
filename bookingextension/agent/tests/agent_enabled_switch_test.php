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
use context_system;
use core\hook\output\before_standard_head_html_generation;
use bookingextension_agent\local\hooks\page_injection;
use bookingextension_agent\local\wizard\services\security\authorization_service;
use required_capability_exception;

/**
 * The "Enable Booking Wizard" switch (agent_enabled) gates every entry point and is off by default.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\security\authorization_service
 * @covers     \bookingextension_agent\local\hooks\page_injection
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class agent_enabled_switch_test extends advanced_testcase {
    /**
     * Data provider: switch states that must leave the agent off, and the one that turns it on.
     *
     * @return array
     */
    public static function switch_states(): array {
        return [
            'never saved' => [null, false],
            'switched off' => [0, false],
            'switched on' => [1, true],
        ];
    }

    /**
     * Set the switch; null removes the stored value (fresh install).
     *
     * @param int|null $value
     */
    private function set_switch(?int $value): void {
        if ($value === null) {
            unset_config('agent_enabled', 'bookingextension_agent');
        } else {
            set_config('agent_enabled', $value, 'bookingextension_agent');
        }
    }

    /**
     * Run the navbar head hook for the admin on a standard page and report whether the wand was loaded.
     *
     * @return bool
     */
    private function wand_injected(): bool {
        global $PAGE;
        $this->setAdminUser();
        set_config('inject_in_navbar', 1, 'bookingextension_agent');
        $PAGE->set_url('/my/');
        $PAGE->set_context(context_system::instance());
        $PAGE->set_pagelayout('standard');

        page_injection::extend_head(new before_standard_head_html_generation($PAGE->get_renderer('core')));

        $amd = (new \ReflectionProperty($PAGE->requires, 'amdjscode'))->getValue($PAGE->requires);
        return str_contains(implode("\n", $amd), 'bookingextension_agent/navbar_magic_wand');
    }

    /**
     * The engine gate follows the switch; an unsaved value counts as off.
     *
     * @dataProvider switch_states
     * @param int|null $value
     * @param bool $expected
     */
    public function test_engine_gate_follows_switch(?int $value, bool $expected): void {
        $this->resetAfterTest();
        $this->set_switch($value);

        $this->assertSame($expected, authorization_service::is_agent_enabled());
        $this->assertSame($expected, authorization_service::is_agent_engine_active());
    }

    /**
     * The navbar magic wand only appears while the agent is switched on.
     *
     * @dataProvider switch_states
     * @param int|null $value
     * @param bool $expected
     */
    public function test_navbar_wand_follows_switch(?int $value, bool $expected): void {
        $this->resetAfterTest();
        $this->set_switch($value);

        $this->assertSame($expected, $this->wand_injected());
    }

    /**
     * A fresh install stores the switch as off and still stores the defaults of every other agent setting,
     * so switching the agent on later (also via CLI) never runs it without e.g. its privacy mode.
     */
    public function test_install_stores_switch_off_and_all_defaults(): void {
        $this->assertSame('0', get_config('bookingextension_agent', 'agent_enabled'));
        $this->assertSame('strict', get_config('bookingextension_agent', 'aiprivacymode'));
        $this->assertNotFalse(get_config('bookingextension_agent', 'aiprivacyprotectedwords'));
        $this->assertSame('1', get_config('bookingextension_agent', 'inject_in_navbar'));
        $this->assertSame('365', get_config('bookingextension_agent', 'benchmark_retention_days'));
    }

    /**
     * While the agent is off every setting stays registered but is hidden behind the enable switch.
     */
    public function test_settings_stay_registered_and_hide_behind_the_switch(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('agent_enabled', 0, 'bookingextension_agent');
        $page = admin_get_root(true, true)->locate('bookingextension_agent_aisettings');
        $this->assertNotNull($page);

        // Dependencies are kept under the form element names (s_<plugin>_<name>).
        $names = [];
        foreach ($page->settings as $setting) {
            if (!($setting instanceof \admin_setting_heading)) {
                $names[] = 's_' . $setting->plugin . '_' . $setting->name;
            }
        }
        $this->assertContains('s_bookingextension_agent_aiprivacymode', $names);

        $hidden = [];
        foreach ((new \ReflectionProperty($page, 'dependencies'))->getValue($page) as $dependency) {
            $this->assertSame('s_bookingextension_agent_agent_enabled', $dependency->dependenton);
            $hidden[] = $dependency->settingname;
        }
        $expected = array_values(array_diff($names, [
            's_bookingextension_agent_agent_enabled',
            's_bookingextension_agent_hideupdatehint',
        ]));
        $this->assertEqualsCanonicalizing($expected, $hidden);
    }

    /**
     * Webservice entry points report the agent as unavailable (not as a permission problem) and the
     * executor gate throws while the agent is off.
     *
     * @dataProvider switch_states
     * @param int|null $value
     * @param bool $expected
     */
    public function test_entry_points_follow_switch(?int $value, bool $expected): void {
        $this->resetAfterTest();
        $this->set_switch($value);
        $adminid = (int)get_admin()->id;
        $ctxid = (int)context_system::instance()->id;
        $authz = new authorization_service();

        $problem = $authz->check_use_readiness($adminid, $ctxid);
        if ($expected) {
            $this->assertNull($problem);
            $authz->require_use_capability($adminid, $ctxid);
            return;
        }
        $this->assertSame('agent_unavailable', $problem['code'] ?? null);
        $this->expectException(required_capability_exception::class);
        $authz->require_use_capability($adminid, $ctxid);
    }
}
