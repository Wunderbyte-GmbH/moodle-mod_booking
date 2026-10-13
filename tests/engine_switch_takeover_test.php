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

namespace mod_booking;

use advanced_testcase;
use context_system;
use core\hook\output\before_standard_head_html_generation;
use mod_booking\local\wizard\engine_component;

/**
 * With local_wizard installed only its own "Enable" switch counts - never the bundled agent's settings.
 *
 * The bundled agent's settings are no longer shown once local_wizard has taken over, so neither the
 * booking view's wizard tab nor the navbar wand may fall back on them.
 *
 * @package    mod_booking
 * @covers     \mod_booking\local\wizard\engine_component
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @runTestsInSeparateProcesses
 */
final class engine_switch_takeover_test extends advanced_testcase {
    /**
     * Mock local_wizard in as installed and set both engines' switches.
     *
     * @param int $bookingswitch The bundled agent's agent_enabled.
     * @param int $wizardswitch local_wizard's agent_enabled.
     */
    private function install_wizard(int $bookingswitch, int $wizardswitch): void {
        if (!class_exists('\\bookingextension_agent\\local\\wizard\\services\\security\\authorization_service')) {
            $this->markTestSkipped('bookingextension_agent is not installed');
        }
        $this->resetAfterTest();
        $stub = __DIR__ . '/fixtures/local_wizard_stub';
        $this->add_mocked_plugin('local', 'wizard', $stub);
        require_once($stub . '/classes/local/wizard/aiready.php');
        require_once($stub . '/classes/local/wizard/services/security/authorization_service.php');
        set_config('version', 2026100601, 'local_wizard');
        \core_plugin_manager::reset_caches();

        set_config('agent_enabled', $bookingswitch, 'bookingextension_agent');
        set_config('inject_in_navbar', 1, 'bookingextension_agent');
        set_config('agent_enabled', $wizardswitch, 'local_wizard');
        $this->assertSame('local_wizard', engine_component::active());
    }

    /**
     * Whether the bundled agent injected its navbar wand for the admin on a standard page.
     *
     * @return bool
     */
    private function bundled_wand_injected(): bool {
        global $PAGE;
        $this->setAdminUser();
        $PAGE->set_url('/my/');
        $PAGE->set_context(context_system::instance());
        $PAGE->set_pagelayout('standard');
        \bookingextension_agent\local\hooks\page_injection::extend_head(
            new before_standard_head_html_generation($PAGE->get_renderer('core'))
        );
        $amd = (new \ReflectionProperty($PAGE->requires, 'amdjscode'))->getValue($PAGE->requires);
        return str_contains(implode("\n", $amd), 'bookingextension_agent/navbar_magic_wand');
    }

    /**
     * Wizard off, booking agent on: no wizard tab and no bundled wand.
     */
    public function test_wizard_switch_off_wins_over_booking_switch_on(): void {
        $this->install_wizard(1, 0);

        $this->assertNull(engine_component::aiready_class());
        $this->assertFalse($this->bundled_wand_injected());
    }

    /**
     * Wizard on, booking agent off: the wizard tab comes from local_wizard, the bundled wand stays away.
     */
    public function test_wizard_switch_on_wins_over_booking_switch_off(): void {
        $this->install_wizard(0, 1);

        $this->assertSame('\\local_wizard\\local\\wizard\\aiready', engine_component::aiready_class());
        $this->assertFalse($this->bundled_wand_injected());
    }
}
