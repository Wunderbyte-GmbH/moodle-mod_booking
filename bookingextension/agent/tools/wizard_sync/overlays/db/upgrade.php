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

// This overlay ships verbatim into local_wizard, so its @package names that plugin.
// phpcs:disable moodle.Commenting.Package.Incorrect
/**
 * Upgrade steps for local_wizard.
 *
 * @package     local_wizard
 * @copyright   2026 Wunderbyte GmbH
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// phpcs:enable moodle.Commenting.Package.Incorrect

/**
 * Upgrade hook.
 *
 * local_wizard is a generated build artifact of the bundled booking agent engine (strict
 * one-way sync); this file is an overlay maintained in the source plugin under
 * tools/wizard_sync/overlays/.
 * The agent's upgrade history (pre-release table renames) does not apply to this plugin:
 * while it is pre-production the schema always arrives via install.xml on a fresh install.
 * Once local_wizard ships to production, schema changes need real steps here.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_wizard_upgrade($oldversion) {
    return true;
}
