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
class execution_times implements performance_action_interface {
    /** @var int */
    private int $times = 1;

    /**
     * Returns id.
     * @return string
     */
    public static function id(): string {
        return 'execution_times';
    }

    /**
     * Returns label.
     * @return string
     */
    public static function label(): string {
        return get_string('executiontimes', 'mod_booking');
    }

    /**
     * When does this action is being used.
     * @param execution_point
     */
    public static function execution_point(): execution_point {
        return execution_point::EXECUTION_TIMES;
    }

    /**
     * Configure the run times.
     * @param array
     */
    public function configure(array $config): void {
        if (isset($config['counter']) && is_numeric($config['counter'])) {
            $this->times = max(1, (int)$config['counter']);
        }
    }

    /**
     * No-op: this action does not execute.
     * @return void
     */
    public function execute(): void {
        // Intentionally empty.
        return;
    }

    /**
     * Returns sidebar.
     * @return int
     */
    public function get_times(): int {
        return $this->times;
    }

    /**
     * Returns mustache template.
     * @param \core\output\renderer_base $renderer
     * @return array
     */
    public function export_for_template(\core\output\renderer_base $renderer): array {
        return [
            'id' => self::id(),
            'label' => self::label(),
            'html'  => $renderer->render_from_template(
                'mod_booking/performance/actions/execution_times',
                [
                    'id' => self::id(),
                    'value' => $this->times,
                ]
            ),
        ];
    }
}
