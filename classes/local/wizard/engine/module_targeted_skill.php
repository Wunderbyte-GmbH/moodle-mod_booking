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
 * Engine alias: binds module_targeted_skill to the active AI engine plugin.
 *
 * Part of the per-component engine alias layer (see engine_resolver): skill code
 * references engine contract types only through these stable aliases, so the same
 * class runs unchanged under bookingextension_agent and local_wizard.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\wizard\engine;

// Idempotence guard: engine_resolver's eager preload can re-enter the file whose
// load triggered it, and manual requires (scaffold tests) may load it twice.
if (
    !class_exists(module_targeted_skill::class, false)
    && !interface_exists(module_targeted_skill::class, false)
    && !trait_exists(module_targeted_skill::class, false)
) {
    class_alias(
        engine_resolver::fqcn('module_targeted_skill'),
        module_targeted_skill::class
    );
}
