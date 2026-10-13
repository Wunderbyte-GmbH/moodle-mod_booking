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
use mod_booking\local\wizard\engine_component;

/**
 * The booking view's wizard tab follows the active engine's "Enable Booking Wizard" switch.
 *
 * @package    mod_booking
 * @covers     \mod_booking\local\wizard\engine_component
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class engine_component_switch_test extends advanced_testcase {
    /**
     * The active engine component, or skip when no engine is installed.
     *
     * @return string
     */
    private function engine(): string {
        $engine = engine_component::active();
        if ($engine === null) {
            $this->markTestSkipped('no agent engine installed');
        }
        return $engine;
    }

    /**
     * Default on: with the switch never saved the panel class is resolved.
     */
    public function test_unset_switch_keeps_the_panel(): void {
        $this->resetAfterTest();
        $engine = $this->engine();
        unset_config('agent_enabled', $engine);

        $this->assertTrue(engine_component::engine_switched_on($engine));
        $this->assertNotNull(engine_component::aiready_class());
    }

    /**
     * agent_enabled = 0 on the active engine removes the panel class, so view.php adds no wizard tab.
     */
    public function test_disabled_switch_removes_the_panel(): void {
        $this->resetAfterTest();
        $engine = $this->engine();
        set_config('agent_enabled', 0, $engine);

        $this->assertFalse(engine_component::engine_switched_on($engine));
        $this->assertNull(engine_component::aiready_class());
        // The engine itself is still the resolved one: aliases and strings keep working.
        $this->assertSame($engine, engine_component::active());
    }

    /**
     * Switching back on restores the panel without any further step.
     */
    public function test_enabled_switch_restores_the_panel(): void {
        $this->resetAfterTest();
        $engine = $this->engine();
        set_config('agent_enabled', 1, $engine);

        $this->assertNotNull(engine_component::aiready_class());
    }
}
