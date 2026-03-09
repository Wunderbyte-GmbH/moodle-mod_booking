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
 * Measures time performance for better tracking.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface performance_action_interface {
    /**
     * Unique ID (machine name).
     * @return string
     */
    public static function id(): string;

    /**
     * Human readable label.
     * @return string
     */
    public static function label(): string;

    /**
     * When this action runs.
     * @return execution_point
     */
    public static function execution_point(): execution_point;

    /**
     * Configure the action.
     * @param array $config
     */
    public function configure(array $config): void;

    /**
     * Execute the action.
     * @return execution_point
     */
    public function execute(): void;

    /**
     * Export data for template.
     * @param \core\output\renderer_base $renderer
     * @return array
     */
    public function export_for_template(\core\output\renderer_base $renderer): array;
}
