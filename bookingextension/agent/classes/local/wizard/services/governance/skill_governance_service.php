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
 * Service for governing skill-level enable/disable settings.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\governance;

use bookingextension_agent\local\wizard\skill_registry;
use bookingextension_agent\local\wizard\skill_registry_factory;

/**
 * Handles admin-settings-level governance for individual agent skills.
 *
 * Responsible for syncing the master "enable all" toggle to the per-skill
 * config entries so that each skill's enabled state is explicitly stored and
 * readable without further indirection.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class skill_governance_service {
    /**
     * Synchronize per-skill toggle settings when "enable all skills" is triggered.
     *
     * Called as an admin_setting updatedcallback after `aiskillenableall` is saved.
     * If the trigger checkbox was set to 1, every discovered skill's individual
     * config key (`aiskillenabled_<skillname>`) is set to 1. Afterwards the trigger
     * is reset to 0 so the setting remains a one-shot action.
     *
     * @return void
     */
    public static function sync_enableall_toggles(): void {
        if (!get_config('bookingextension_agent', 'aiskillenableall')) {
            return;
        }

        try {
            $registry = skill_registry_factory::get_default();
            $contracts = $registry->get_skill_contracts();

            foreach ($contracts as $skillname => $unusedmeta) {
                $settingname = skill_registry::get_skill_toggle_setting_name((string)$skillname);
                set_config($settingname, 1, 'bookingextension_agent');
            }
        } catch (\Throwable $e) {
            debugging(
                'bookingextension_agent: unable to sync aiskillenableall toggles: ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
        } finally {
            // Keep this checkbox as a one-shot trigger, never a persistent on-state.
            set_config('aiskillenableall', 0, 'bookingextension_agent');
        }
    }
}
