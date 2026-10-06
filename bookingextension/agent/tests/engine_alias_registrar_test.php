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

/**
 * Tests for the engine-side alias registration.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\base_skill;
use bookingextension_agent\local\wizard\interfaces\skill_interface;
use bookingextension_agent\local\wizard\services\engine_alias_registrar;

/**
 * Tests for engine_alias_registrar.
 *
 * @covers \bookingextension_agent\local\wizard\services\engine_alias_registrar
 */
final class engine_alias_registrar_test extends \advanced_testcase {
    /**
     * Registration defines every alias of the canonical set below the given root,
     * bound to the engine's real types, and is idempotent.
     */
    public function test_registers_full_alias_set_for_namespace_root(): void {
        $root = 'fakecomp_aliastest';

        engine_alias_registrar::register_for_namespace_root($root);

        foreach (engine_alias_registrar::ENGINE_ALIASES as $leaf => $relclass) {
            $alias = $root . '\\local\\wizard\\engine\\' . $leaf;
            $this->assertTrue(
                class_exists($alias, false) || interface_exists($alias, false) || trait_exists($alias, false),
                "Alias {$leaf} must be defined after registration."
            );
        }

        // Aliases are the engine's real types: an instanceof check crosses the alias boundary.
        $baseskillalias = $root . '\\local\\wizard\\engine\\base_skill';
        $this->assertTrue(is_a($baseskillalias, base_skill::class, true));
        $skillinterfacealias = $root . '\\local\\wizard\\engine\\skill_interface';
        $this->assertTrue(is_a($skillinterfacealias, skill_interface::class, true));

        // A second call (also via the gated entry point) must not fail on the
        // already-defined names.
        engine_alias_registrar::register_for_namespace_root($root);
        engine_alias_registrar::ensure_component_aliases($root);
    }

    /**
     * The gated entry point ignores components without a local/wizard tree and
     * never aliases into the engine's own namespace.
     */
    public function test_ensure_skips_engine_and_unrelated_components(): void {
        // The engine's own component: its classes are the real thing, no alias may shadow them.
        engine_alias_registrar::ensure_component_aliases('bookingextension_agent');
        $this->assertTrue(class_exists(base_skill::class));

        // A component without any local/wizard tree gets no aliases registered.
        engine_alias_registrar::ensure_component_aliases('mod_label');
        $this->assertFalse(
            class_exists('mod_label\\local\\wizard\\engine\\base_skill', false),
            'Components without a local/wizard tree must not receive aliases.'
        );
    }

    /**
     * The engine_resolver alias resolves to the engine's resolver, reports the active
     * engine (the bundled agent when no primary engine is installed) and builds engine
     * FQCNs - so consumers no longer need a vendored engine_resolver.php.
     */
    public function test_engine_resolver_alias_resolves_and_reports_active_engine(): void {
        $root = 'fakecomp_resolvertest';
        engine_alias_registrar::register_for_namespace_root($root);

        $alias = $root . '\\local\\wizard\\engine\\engine_resolver';
        $this->assertTrue(class_exists($alias), 'engine_resolver must be a registered alias.');

        // The alias IS the engine's resolver class (identity across the alias boundary).
        $this->assertSame(
            \bookingextension_agent\local\wizard\engine_resolver::class,
            (new \ReflectionClass($alias))->getName()
        );

        // In the test environment local_wizard is not installed, so the bundled agent is active.
        $this->assertSame('bookingextension_agent', $alias::component());
        $this->assertSame(
            '\\bookingextension_agent\\local\\wizard\\dto\\skill_risk_class',
            $alias::fqcn('dto\\skill_risk_class')
        );
    }
}
