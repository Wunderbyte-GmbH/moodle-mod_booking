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
 * Resolves which AI engine plugin backs the engine alias layer of this component.
 *
 * The engine exists in two structurally identical plugins: the standalone local_wizard
 * plugin and the bundled bookingextension_agent subplugin; local_wizard outranks the
 * bundled engine (see the engine's authorization_service::primary_engine_takes_over()).
 * Skill code never names an engine component: it references engine contract types only
 * through the class_alias files in this directory, which all bind to the one engine
 * this resolver picks. Every skill-providing component carries an identical copy of
 * this layer in its own namespace (scaffolded plugins included) - do not fork the
 * pattern.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\wizard\engine;

/**
 * Resolver behind the engine alias layer (see the file docblock).
 */
final class engine_resolver {
    /** @var bool Whether the whole alias layer has been eagerly loaded. */
    private static bool $preloaded = false;

    /**
     * Frankenstyle component of the active engine plugin.
     *
     * @return string
     */
    public static function component(): string {
        try {
            $wizard = \core_plugin_manager::instance()->get_plugin_info('local_wizard');
            if ($wizard !== null && $wizard->is_installed_and_upgraded()) {
                return 'local_wizard';
            }
        } catch (\Throwable $e) {
            // Fall through to the bundled engine.
            $e = null;
        }
        return 'bookingextension_agent';
    }

    /**
     * Fully qualified name of an engine class, resolved against the active engine.
     *
     * @param string $relclass Class path below the engine's local\wizard namespace.
     * @return string
     */
    public static function fqcn(string $relclass): string {
        self::preload();
        return '\\' . self::component() . '\\local\\wizard\\' . $relclass;
    }

    /**
     * Eagerly load every alias of this layer the first time any alias is touched.
     *
     * PHP verifies typed parameters, properties and return values WITHOUT autoloading,
     * so an alias that only appears in a signature must already be defined when the
     * check runs. extends/implements/enum accesses autoload one alias; this hook then
     * pulls in all the others. Re-entrant alias loads are cut off by the flag, and a
     * failing class_exists (e.g. bundle files required manually in tests) is harmless.
     */
    private static function preload(): void {
        if (self::$preloaded) {
            return;
        }
        self::$preloaded = true;
        foreach (glob(__DIR__ . '/*.php') ?: [] as $file) {
            $leaf = basename($file, '.php');
            if ($leaf !== 'engine_resolver') {
                class_exists(__NAMESPACE__ . '\\' . $leaf);
            }
        }
    }
}
