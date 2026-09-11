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

namespace mod_booking\local\wizard;

/**
 * Resolves which AI engine plugin serves the booking wizard UI.
 *
 * The engine exists in two structurally identical plugins: the standalone
 * local_wizard plugin and the bookingextension_agent subplugin. When both are
 * installed local_wizard takes precedence and the agent engine stands down
 * (its authorization_service defers). Consumers in mod_booking must therefore
 * never hardcode one engine component; they resolve strings, templates and
 * classes through this helper instead.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class engine_component {
    /**
     * The engines in precedence order: the standalone local_wizard outranks the bundled
     * agent. This is the ONE neutral bootstrap a consumer needs - it discovers the active
     * engine without the engine alias layer being registered yet (which is what breaks the
     * chicken-and-egg), so the alias layer no longer has to be vendored per component. The
     * order mirrors authorization_service::active_engine_component() (the engine-side home
     * of the same rule); the two must agree.
     *
     * @var string[]
     */
    private const ENGINES_BY_PRECEDENCE = ['local_wizard', 'bookingextension_agent'];

    /**
     * Frankenstyle name of the active engine plugin, or null if none is installed.
     *
     * @return string|null
     */
    public static function active(): ?string {
        foreach (self::ENGINES_BY_PRECEDENCE as $candidate) {
            try {
                $plugininfo = \core_plugin_manager::instance()->get_plugin_info($candidate);
            } catch (\Throwable $e) {
                $plugininfo = null;
            }
            if ($plugininfo !== null && $plugininfo->is_installed_and_upgraded()) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Fully qualified aiready class of the active engine, or null if unavailable.
     *
     * Both engines expose the panel entry point under the same relative
     * namespace, so the class name derives from the component.
     *
     * @return string|null
     */
    public static function aiready_class(): ?string {
        $component = self::active();
        if ($component === null) {
            return null;
        }
        $class = '\\' . $component . '\\local\\wizard\\aiready';
        return class_exists($class) ? $class : null;
    }

    /**
     * Register mod_booking's engine aliases via the active engine's registrar.
     *
     * Only needed on paths that touch skill/engine types WITHOUT going through the
     * engine's own skill discovery (which registers them itself) - i.e. mod_booking's
     * PHPUnit/Behat tests that instantiate skills directly. Production UI (view.php) uses
     * only active()/aiready_class() and needs no alias registration.
     *
     * @return void
     */
    public static function ensure_engine_aliases(): void {
        $component = self::active();
        if ($component === null) {
            return;
        }
        $registrar = '\\' . $component . '\\local\\wizard\\services\\engine_alias_registrar';
        if (class_exists($registrar)) {
            $registrar::ensure_component_aliases('mod_booking');
        }
    }
}
