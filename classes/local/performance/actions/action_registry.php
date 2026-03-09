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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Regestry for all available actions.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class action_registry {
    /**
     * All class.
     * @return class-string<performance_action_interface>[]
     */
    public static function all(): array {
        return [
            execution_times::class,
            purge_cache_action_before::class,
            purge_cache_action_inbetween::class,
        ];
    }

    /**
     * Get instantiated actions
     *
     * @return performance_action_interface[]
     */
    public static function instances(): array {
        return array_map(
            fn(string $class) => new $class(),
            self::all()
        );
    }

    /**
     * For execution point class.
     * @param execution_point $point
     * @return class-string<performance_action_interface>[]
     */
    public static function for_execution_point(execution_point $point): array {
        return array_filter(
            self::all(),
            fn(string $class) => $class::execution_point() === $point
        );
    }

    /**
     * Export all for template.
     * @param mixed $renderer
     *
     * @return array[]
     */
    public static function export_all_for_template($renderer): array {
        $out = [];
        foreach (self::instances() as $action) {
            $out[] = $action->export_for_template($renderer);
        }
        return $out;
    }
}
