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

namespace mod_booking\performance\actions;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Regestry for all available actions.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class purge_cache_action_inbetween implements performance_action_interface {
    public static function id(): string {
        return 'purge_cache_action_inbetween';
    }

    public static function label(): string {
        return 'purge_cache_action_inbetween';
    }

    public static function execution_point(): execution_point {
        return execution_point::BEFORE_EACH;
    }

    public function configure(array $config): void {
        $this->config = $config;
    }

    public function execute(): void {
        purge_all_caches();
    }

    public function export_for_template(\core\output\renderer_base $renderer): array {
        return [
            'id' => self::id(),
            'label' => self::label(),
            'html'  => $renderer->render_from_template(
            'mod_booking/performance/actions/purge_cache',
                [
                    'id' => self::id(),
                    'value' => 1,
                ]
            ),
        ];
    }
}
