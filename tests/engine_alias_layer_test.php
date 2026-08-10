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

use mod_booking\local\wizard\engine_component;

/**
 * Guards mod_booking's engine binding after the vendored alias layer was retired.
 *
 * mod_booking no longer ships classes/local/wizard/engine/ - it is treated like any
 * third-party plugin: the active engine registers the component-local aliases at runtime
 * (engine_alias_registrar), and mod_booking triggers that via the one neutral bootstrap
 * engine_component::ensure_engine_aliases(). These tests pin that after the bootstrap
 * every engine alias resolves into the active engine, the risk enum identity holds, and
 * the engine_resolver alias reports the active engine.
 *
 * @package    mod_booking
 * @category   test
 * @covers \mod_booking\local\wizard\engine_component
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class engine_alias_layer_test extends \advanced_testcase {
    /**
     * After the neutral bootstrap, every canonical engine alias resolves as a
     * mod_booking-namespaced name bound to a type of the active engine plugin.
     */
    public function test_registered_aliases_bind_to_the_active_engine(): void {
        $engine = engine_component::active();
        if ($engine === null) {
            $this->markTestSkipped('No active engine plugin installed - the alias layer is dormant.');
        }
        engine_component::ensure_engine_aliases();

        $registrar = '\\' . $engine . '\\local\\wizard\\services\\engine_alias_registrar';
        $checked = 0;
        foreach (array_keys($registrar::ENGINE_ALIASES) as $leaf) {
            $alias = 'mod_booking\\local\\wizard\\engine\\' . $leaf;
            $this->assertTrue(
                class_exists($alias) || interface_exists($alias) || trait_exists($alias) || enum_exists($alias),
                "Alias {$alias} does not resolve - engine contract drift?"
            );
            $real = (new \ReflectionClass($alias))->getName();
            $this->assertStringStartsWith(
                $engine . '\\local\\wizard\\',
                $real,
                "Alias {$leaf} is bound to {$real}, not to the active engine {$engine}."
            );
            $checked++;
        }
        $this->assertGreaterThanOrEqual(19, $checked, 'Unexpectedly small alias set.');
    }

    /**
     * The aliased risk enum IS the active engine's enum (identity, not a copy).
     */
    public function test_risk_enum_identity(): void {
        $engine = engine_component::active();
        if ($engine === null) {
            $this->markTestSkipped('No active engine plugin installed - the alias layer is dormant.');
        }
        engine_component::ensure_engine_aliases();

        $enginecase = constant('\\' . $engine . '\\local\\wizard\\dto\\skill_risk_class::R2');
        $this->assertSame($enginecase, \mod_booking\local\wizard\engine\skill_risk_class::R2);
    }

    /**
     * The engine_resolver alias reports the active engine and builds engine FQCNs.
     */
    public function test_engine_resolver_alias_reports_active_engine(): void {
        $engine = engine_component::active();
        if ($engine === null) {
            $this->markTestSkipped('No active engine plugin installed - the alias layer is dormant.');
        }
        engine_component::ensure_engine_aliases();

        $resolver = 'mod_booking\\local\\wizard\\engine\\engine_resolver';
        $this->assertSame($engine, $resolver::component());
        $this->assertSame(
            '\\' . $engine . '\\local\\wizard\\dto\\skill_risk_class',
            $resolver::fqcn('dto\\skill_risk_class')
        );
    }
}
