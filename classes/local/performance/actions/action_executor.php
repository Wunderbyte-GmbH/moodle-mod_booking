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
 * Handle fields for booking option.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\performance\actions;

use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Regestry for all available actions.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class action_executor {
    /**
     * Action executor.
     *
     * @param execution_point $point
     * @param stdClass $actions
     *
     * @return void
     *
     */
    public function execute(execution_point $point, $actions): void {
        foreach (action_registry::for_execution_point($point) as $actionclass) {
            // Static id defined by the action class (e.g. purge_cache_action_before).
            $id = $actionclass::id();
            // Only execute if enabled.
            if (!$this->is_enabled($actions, $id)) {
                continue;
            }

            $action = new $actionclass();
            $action->execute();
        }
    }

    /**
     * Check if action should be executed.
     *
     * @param stdClass $actions
     * @param string $id
     *
     * @return void
     *
     */
    private function is_enabled($actions, string $id): bool {
        if (!is_object($actions) || !property_exists($actions, $id)) {
            return false;
        }

        $cfg = $actions->{$id};
        // Your structure: $actions->purge_cache_action_before->enabled = 0|1.
        if (is_object($cfg) && property_exists($cfg, 'enabled')) {
            return !empty($cfg->enabled);
        }

        return false;
    }
}
