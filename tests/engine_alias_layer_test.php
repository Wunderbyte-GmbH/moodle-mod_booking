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

use mod_booking\local\wizard\engine\engine_resolver;

/**
 * Guards the per-component engine alias layer.
 *
 * Skill code references engine contract types only through the class_alias files in
 * classes/local/wizard/engine/. These tests pin three invariants: every alias resolves
 * into the active engine plugin, the enum identity holds, and the (legacy vendored)
 * layer is byte-uniform across the components that still carry it (mod_booking and the
 * oneclick extension) and matches the engine's canonical alias set - there must never
 * be a second variant of this pattern anywhere. Scaffolded third-party plugins ship NO
 * alias files any more: the engine registers the aliases itself (engine_alias_registrar).
 *
 * @package    mod_booking
 * @category   test
 * @covers \mod_booking\local\wizard\engine\engine_resolver
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class engine_alias_layer_test extends \advanced_testcase {
    /**
     * Every alias file binds its leaf name to a type of the active engine plugin.
     */
    public function test_every_alias_binds_to_the_active_engine(): void {
        if (!$this->active_engine_available()) {
            $this->markTestSkipped('No active engine plugin installed - the alias layer is dormant.');
        }
        $component = engine_resolver::component();
        $checked = 0;
        foreach ($this->alias_leaves(__DIR__ . '/../classes/local/wizard/engine') as $leaf) {
            $alias = 'mod_booking\\local\\wizard\\engine\\' . $leaf;
            $this->assertTrue(
                class_exists($alias) || interface_exists($alias) || trait_exists($alias) || enum_exists($alias),
                "Alias {$alias} does not resolve - engine contract drift?"
            );
            $real = (new \ReflectionClass($alias))->getName();
            $this->assertStringStartsWith(
                $component . '\\local\\wizard\\',
                $real,
                "Alias {$leaf} is bound to {$real}, not to the active engine {$component}."
            );
            $checked++;
        }
        $this->assertGreaterThanOrEqual(18, $checked, 'Unexpectedly small alias layer.');
    }

    /**
     * The aliased risk enum IS the active engine's enum (identity, not a copy).
     */
    public function test_risk_enum_identity(): void {
        if (!$this->active_engine_available()) {
            $this->markTestSkipped('No active engine plugin installed - the alias layer is dormant.');
        }
        $enginecase = constant(
            engine_resolver::fqcn('dto\\skill_risk_class') . '::R2'
        );
        $this->assertSame($enginecase, \mod_booking\local\wizard\engine\skill_risk_class::R2);
    }

    /**
     * The oneclick extension carries a byte-identical alias layer (modulo namespace).
     */
    public function test_alias_layer_is_uniform_with_oneclick(): void {
        $own = __DIR__ . '/../classes/local/wizard/engine';
        $oneclick = __DIR__ . '/../bookingextension/oneclick/classes/local/wizard/engine';
        if (!is_dir($oneclick)) {
            $this->markTestSkipped('oneclick extension not present.');
        }

        $ownleaves = $this->alias_leaves($own);
        $ownleaves[] = 'engine_resolver';
        $oneclickleaves = $this->alias_leaves($oneclick);
        $oneclickleaves[] = 'engine_resolver';
        $this->assertSame($ownleaves, $oneclickleaves, 'Alias file sets differ.');

        foreach ($ownleaves as $leaf) {
            $expected = (string)file_get_contents($own . '/' . $leaf . '.php');
            $actual = str_replace(
                'bookingextension_oneclick',
                'mod_booking',
                (string)file_get_contents($oneclick . '/' . $leaf . '.php')
            );
            $this->assertSame($expected, $actual, "Alias layer variant detected in oneclick/{$leaf}.php.");
        }
    }

    /**
     * Scaffolds ship no alias files; the vendored layer matches the engine's canonical set.
     *
     * Since the engine registers the per-component aliases itself (engine_alias_registrar),
     * a scaffold bundle must not contain any engine files. The legacy vendored layer this
     * component still carries is instead pinned against the registrar's canonical alias
     * set, so the two can never drift apart.
     */
    public function test_alias_layer_is_uniform_with_scaffold_template(): void {
        // Probe the engine BEFORE calling fqcn(): fqcn() eagerly preloads the whole alias
        // layer, whose class_alias() calls bind to the active engine plugin. With no engine
        // installed (mod_booking standalone) those targets do not exist, so we must skip
        // here first — exactly as the binding/identity guards above do.
        if (!$this->active_engine_available()) {
            $this->markTestSkipped('No active engine plugin installed - the alias layer is dormant.');
        }
        $generator = engine_resolver::fqcn('services\\scaffold\\skill_template_generator');
        if (!class_exists($generator)) {
            $this->markTestSkipped('Active engine ships no scaffold generator.');
        }

        $bundle = $generator::generate([
            'component' => 'local/fakeplugin',
            'description' => 'Do a fake thing.',
        ]);

        $enginefiles = array_values(array_filter(
            array_keys($bundle['files']),
            static fn(string $path): bool => str_starts_with($path, 'classes/local/wizard/engine/')
        ));
        $this->assertSame([], $enginefiles, 'Scaffold bundles must not ship engine alias files.');

        $registrar = engine_resolver::fqcn('services\\engine_alias_registrar');
        $this->assertTrue(
            class_exists($registrar),
            'Active engine must provide engine_alias_registrar - it replaces the emitted alias layer.'
        );
        $canonical = array_keys($registrar::ENGINE_ALIASES);
        sort($canonical);
        $this->assertSame(
            $canonical,
            $this->alias_leaves(__DIR__ . '/../classes/local/wizard/engine'),
            'Vendored alias layer differs from the canonical engine alias set.'
        );
    }

    /**
     * Whether the active engine plugin actually ships its code in this install.
     *
     * Probes a real engine class directly (not an alias) so the dormant alias layer is
     * not preloaded. When mod_booking is tested standalone (no local_wizard and no
     * bundled bookingextension_agent), no engine exists and the alias layer has nothing
     * to bind to, so the binding/identity guards do not apply.
     *
     * @return bool
     */
    private function active_engine_available(): bool {
        return class_exists('\\' . engine_resolver::component() . '\\local\\wizard\\base_skill');
    }

    /**
     * Sorted alias leaf names (without engine_resolver) of an engine alias directory.
     *
     * @param string $dir
     * @return string[]
     */
    private function alias_leaves(string $dir): array {
        $leaves = [];
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $leaf = basename($file, '.php');
            if ($leaf !== 'engine_resolver') {
                $leaves[] = $leaf;
            }
        }
        sort($leaves);
        return $leaves;
    }
}
