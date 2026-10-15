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
 * The agent's upgrade history (pre-release table renames) does not apply to this plugin. local_wizard ships its
 * own steps here (settings and, from now on, schema changes); they never run in the bundled engine.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_wizard_upgrade($oldversion) {
    global $DB;

    if ($oldversion < 2026101501) {
        // Releases 1.0.1 and 1.0.2 installed "Enable Wizard" as off; from 1.0.3 the standalone Wizard ships on.
        // Switch it on where the stored off is still the installer's default: no config log entry with an old
        // value means no admin ever changed it, neither here nor in the bundled engine it was adopted from.
        $adminchoice = $DB->record_exists_select(
            'config_log',
            "plugin IN (:wizard, :agent) AND name = :name AND oldvalue IS NOT NULL",
            ['wizard' => 'local_wizard', 'agent' => 'bookingextension_agent', 'name' => 'agent_enabled']
        );
        if (!$adminchoice && (string) get_config('local_wizard', 'agent_enabled') === '0') {
            set_config('agent_enabled', 1, 'local_wizard');
        }
        upgrade_plugin_savepoint(true, 2026101501, 'local', 'wizard');
    }

    return true;
}
