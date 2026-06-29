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
     * Frankenstyle name of the active engine plugin, or null if none is installed.
     *
     * The selection rule (local_wizard outranks the bundled agent) lives in ONE place,
     * engine_resolver; this helper only adds the "no engine installed at all" -> null
     * contract that UI callers like view.php need.
     *
     * @return string|null
     */
    public static function active(): ?string {
        $component = engine\engine_resolver::component();
        try {
            $plugininfo = \core_plugin_manager::instance()->get_plugin_info($component);
        } catch (\Throwable $e) {
            return null;
        }
        return ($plugininfo !== null && $plugininfo->is_installed_and_upgraded()) ? $component : null;
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
}
