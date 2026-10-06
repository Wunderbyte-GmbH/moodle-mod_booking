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
 * Engine-side registration of per-component engine aliases.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services;

use core_component;

/**
 * Registers the engine alias layer for skill-providing components at runtime.
 *
 * Skill code in a component references engine contract types only through
 * component-local names (\<component>\local\wizard\engine\<leaf>), so the same
 * class runs unchanged under either engine plugin. Historically every component
 * had to VENDOR that alias layer as ~20 boilerplate files; the engine now
 * registers the aliases itself via class_alias() before it loads a component's
 * skill classes, so scaffolded components ship no engine files at all.
 *
 * Vendored alias layers from older scaffolds remain supported: both sides guard
 * with class_exists() before aliasing, so whichever defines a name first wins
 * and the other side skips it.
 *
 * Out-of-engine loading (e.g. a component's own unit tests instantiating its
 * skill directly) uses the one-line bootstrap:
 * engine_alias_registrar::ensure_component_aliases('<component>').
 */
class engine_alias_registrar {
    /**
     * Canonical engine contract surface exposed through the per-component aliases.
     *
     * Alias leaf name => class path below the engine's local\wizard namespace.
     *
     * @var array<string,string>
     */
    public const ENGINE_ALIASES = [
        'base_skill' => 'base_skill',
        'module_targeted_skill' => 'module_targeted_skill',
        'course_targeted_skill' => 'course_targeted_skill',
        'skill_interface' => 'interfaces\\skill_interface',
        'skill_provider_interface' => 'interfaces\\skill_provider_interface',
        'skill_input_normalizer_interface' => 'interfaces\\skill_input_normalizer_interface',
        'skill_input_normalizer_provider_interface' => 'interfaces\\skill_input_normalizer_provider_interface',
        'skill_trigger_provider_interface' => 'interfaces\\skill_trigger_provider_interface',
        'queue_identity_provider_interface' => 'interfaces\\queue_identity_provider_interface',
        'issue_code_provider_interface' => 'interfaces\\issue_code_provider_interface',
        'attachment_resolver' => 'interfaces\\attachment_resolver',
        'thread_memory' => 'interfaces\\thread_memory',
        'skill_catalog' => 'interfaces\\skill_catalog',
        'skill_risk_class' => 'dto\\skill_risk_class',
        'target_selector' => 'dto\\target_selector',
        'observation_time' => 'services\\observation_time',
        'skill_catalog_discovery' => 'services\\skill_catalog_discovery',
        'localized_string_service' => 'services\\localized_string_service',
        // The resolver itself, so consumers reach the active engine (and any engine
        // service, via engine_resolver::fqcn) through the alias layer without a vendored
        // copy. Retires the per-component engine_resolver.php.
        'engine_resolver' => 'engine_resolver',
    ];

    /** @var array<string,bool> Namespace roots already handled in this request. */
    private static array $done = [];

    /**
     * Ensure the alias layer exists for a component before its skill classes load.
     *
     * Cheap no-op for components without a classes/local/wizard tree, for the
     * engine component itself (its classes are the real thing) and on repeated
     * calls, so callers can invoke it for every installed component.
     *
     * @param string $component Component name in any frankenstyle notation.
     * @return void
     */
    public static function ensure_component_aliases(string $component): void {
        $namespaceroot = core_component::normalize_componentname($component);
        if ($namespaceroot === '' || isset(self::$done[$namespaceroot])) {
            return;
        }
        if ($namespaceroot === self::engine_root()) {
            self::$done[$namespaceroot] = true;
            return;
        }

        [$plugintype, $pluginname] = core_component::normalize_component($component);
        if ($plugintype === 'core' || empty($pluginname)) {
            return;
        }
        $plugindir = core_component::get_plugin_directory($plugintype, $pluginname);
        if (empty($plugindir) || !is_dir($plugindir . '/classes/local/wizard')) {
            self::$done[$namespaceroot] = true;
            return;
        }

        self::register_for_namespace_root($namespaceroot);
    }

    /**
     * Register every engine alias below the given namespace root.
     *
     * Public so out-of-engine callers (a component's own tests, CLI helpers)
     * can bootstrap the aliases without a plugin-directory lookup.
     *
     * @param string $namespaceroot Namespace root the aliases live under, e.g. "local_myplugin".
     * @return void
     */
    public static function register_for_namespace_root(string $namespaceroot): void {
        self::$done[$namespaceroot] = true;
        $engineprefix = '\\' . self::engine_root() . '\\local\\wizard\\';

        foreach (self::ENGINE_ALIASES as $leaf => $relclass) {
            $alias = $namespaceroot . '\\local\\wizard\\engine\\' . $leaf;
            // A vendored alias layer (older scaffolds) may already define the
            // name; its own guard is symmetric, so first definition wins.
            if (
                class_exists($alias, false)
                || interface_exists($alias, false)
                || trait_exists($alias, false)
            ) {
                continue;
            }
            class_alias($engineprefix . $relclass, $alias);
        }
    }

    /**
     * Frankenstyle root of the active engine, derived from this very class.
     *
     * @return string
     */
    private static function engine_root(): string {
        return explode('\\', self::class)[0];
    }
}
