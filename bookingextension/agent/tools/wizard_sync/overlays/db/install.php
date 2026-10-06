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
 * Install hook for local_wizard: adopt the bundled agent's data on takeover.
 *
 * This file is an OVERLAY maintained in the source plugin under
 * tools/wizard_sync/overlays/ — it exists only in the generated local_wizard
 * artifact, never in the installed bookingextension_agent plugin, and it names
 * the agent on purpose (it migrates FROM it), so the sync generator ships it
 * verbatim.
 *
 * @package     local_wizard
 * @copyright   2026 Wunderbyte GmbH
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// phpcs:enable moodle.Commenting.Package.Incorrect

/**
 * Copy the bundled agent's table data, settings and role assignments.
 *
 * When local_wizard is installed on a site where bookingextension_agent is
 * already active, the wizard takes over as primary engine (the agent's
 * authorization_service defers). So admins and users keep their threads,
 * memories, benchmarks, settings and granted capabilities, everything is
 * COPIED — never moved — into the wizard's own tables and component:
 *
 * - table rows bx_agent_* -> local_wizard_* (ids preserved; the tables are
 *   structurally identical by construction, and cross-table references such
 *   as threadid stay valid),
 * - plugin settings except 'version',
 * - role capability assignments bookingextension/agent:* -> local/wizard:*.
 *
 * The agent's originals stay untouched: uninstalling local_wizard later lets
 * the bundled engine resume exactly where it stood at takeover (divergence
 * accumulated while the wizard was active is intentionally not synced back).
 * Every step is idempotent/defensive: rows are only copied into empty wizard
 * tables, settings and assignments only where none exist yet.
 *
 * @return bool
 */
function xmldb_local_wizard_install(): bool {
    global $DB;

    if (\core_component::get_component_directory('bookingextension_agent') === null) {
        return true;
    }

    $dbman = $DB->get_manager();
    $suffixes = [
        'ai_threads', 'ai_messages', 'ai_runs', 'ai_llm_debug',
        'bm_runs', 'bm_scenarios', 'bm_baselines', 'bm_metrics',
        'user_memory', 'embeddings', 'embeddings_meta',
        'search_state', 'search_scope',
    ];

    $transaction = $DB->start_delegated_transaction();

    $copiedrows = 0;
    foreach ($suffixes as $suffix) {
        $source = 'bx_agent_' . $suffix;
        $target = 'local_wizard_' . $suffix;
        if (!$dbman->table_exists(new xmldb_table($source))) {
            continue;
        }
        if ($DB->count_records($target) > 0) {
            // Never clobber existing wizard data (e.g. reinstall after use).
            continue;
        }
        // Copy by explicit shared column list (ids included), robust against
        // one side lagging a schema field behind the other.
        $cols = array_intersect(
            array_keys($DB->get_columns($source)),
            array_keys($DB->get_columns($target))
        );
        $collist = implode(', ', $cols);
        $DB->execute("INSERT INTO {{$target}} ({$collist}) SELECT {$collist} FROM {{$source}}");
        $copiedrows += $DB->count_records($target);
    }

    // Plugin settings: everything except the version marker, never overwriting
    // a wizard setting that already exists.
    $copiedsettings = 0;
    $settings = $DB->get_records('config_plugins', ['plugin' => 'bookingextension_agent']);
    foreach ($settings as $setting) {
        if ($setting->name === 'version') {
            continue;
        }
        if (get_config('local_wizard', $setting->name) === false) {
            set_config($setting->name, $setting->value, 'local_wizard');
            $copiedsettings++;
        }
    }

    // Role capability assignments: same capability set under the new prefix.
    // Written as raw rows: this hook runs BEFORE Moodle registers local_wizard's
    // capabilities, so assign_capability() would die on the unknown names. The
    // rows become effective the moment the capabilities are registered (directly
    // after this hook), and the upgrade's final cache purge covers accesslib.
    $mappedcaps = 0;
    $assignments = $DB->get_records_select(
        'role_capabilities',
        $DB->sql_like('capability', '?'),
        ['bookingextension/agent:%']
    );
    foreach ($assignments as $assignment) {
        $capability = str_replace('bookingextension/agent:', 'local/wizard:', $assignment->capability);
        $exists = $DB->record_exists('role_capabilities', [
            'roleid' => $assignment->roleid,
            'capability' => $capability,
            'contextid' => $assignment->contextid,
        ]);
        if (!$exists) {
            $row = new stdClass();
            $row->contextid = $assignment->contextid;
            $row->roleid = $assignment->roleid;
            $row->capability = $capability;
            $row->permission = $assignment->permission;
            $row->timemodified = time();
            $row->modifierid = 0;
            $DB->insert_record('role_capabilities', $row);
            $mappedcaps++;
        }
    }

    $transaction->allow_commit();

    // Surface the takeover in the install/upgrade log so admins see exactly
    // what was adopted from the bundled agent.
    mtrace("local_wizard takeover: adopted {$copiedrows} table rows, "
        . "{$copiedsettings} settings and {$mappedcaps} role capability "
        . "assignments from bookingextension_agent.");
    return true;
}
