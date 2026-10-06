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

namespace bookingextension_agent\local;

use context_system;

/**
 * Re-applies missing archetype capability defaults to existing roles; explicit admin
 * settings always win. Needed because Moodle applies defaults only at capability creation.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class capability_defaults_sync {
    /**
     * Apply missing CAP_ALLOW archetype defaults; roles with any explicit setting are skipped.
     *
     * @return array<string,string[]> Granted capabilities per role shortname (empty when healthy).
     */
    public static function apply(): array {
        global $DB;

        $capabilities = [];
        require(__DIR__ . '/../../db/access.php');

        $syscontextid = (int)context_system::instance()->id;
        $granted = [];

        foreach ($capabilities as $capname => $definition) {
            // The capability must already exist on this site (created by install/upgrade).
            if (!$DB->record_exists('capabilities', ['name' => $capname])) {
                continue;
            }
            foreach ((array)($definition['archetypes'] ?? []) as $archetype => $permission) {
                if ((int)$permission !== CAP_ALLOW) {
                    continue;
                }
                foreach (get_archetype_roles($archetype) as $role) {
                    $hasexplicitsetting = $DB->record_exists('role_capabilities', [
                        'roleid' => (int)$role->id,
                        'capability' => $capname,
                        'contextid' => $syscontextid,
                    ]);
                    if ($hasexplicitsetting) {
                        continue;
                    }
                    assign_capability($capname, CAP_ALLOW, (int)$role->id, $syscontextid);
                    $granted[(string)$role->shortname][] = $capname;
                }
            }
        }

        return $granted;
    }
}
