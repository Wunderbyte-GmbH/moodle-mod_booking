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

namespace bookingextension_agent\local\wizard;

use bookingextension_agent\local\wizard\services\security\authorization_service;

/**
 * Engine-side resolver behind the per-component alias layer.
 *
 * Consumer components reference this through the registered alias
 * \<component>\local\wizard\engine\engine_resolver (engine_alias_registrar), so they
 * reach the active engine's classes without ever naming an engine plugin. The
 * active-engine precedence (local_wizard outranks the bundled agent) lives in exactly
 * one place, authorization_service::active_engine_component(); this class only exposes
 * it plus a fully-qualified-name helper. Unlike the retired vendored copy it needs no
 * eager preload: engine_alias_registrar::register_for_namespace_root() defines every
 * alias of a component in one loop before its skill classes load.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class engine_resolver {
    /**
     * Frankenstyle component of the active engine plugin.
     *
     * @return string
     */
    public static function component(): string {
        return authorization_service::active_engine_component();
    }

    /**
     * Fully qualified name of an engine class, resolved against the active engine.
     *
     * @param string $relclass Class path below the engine's local\wizard namespace.
     * @return string
     */
    public static function fqcn(string $relclass): string {
        return '\\' . self::component() . '\\local\\wizard\\' . $relclass;
    }
}
