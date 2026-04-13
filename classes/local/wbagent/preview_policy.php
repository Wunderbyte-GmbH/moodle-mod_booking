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
 * Policy class defining which tasks support booking option preview rendering.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_booking\local\wbagent;

/**
 * Defines which agent tasks support visual preview rendering.
 *
 * Only booking.create_option and booking.update_option produce meaningful
 * booking-option row previews.  All other tasks (entities.create_entity, etc.)
 * are treated as a silent no-op — no HTML is rendered and no error is returned.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preview_policy {
    /** @var string[] Tasks that support visual preview rendering. */
    private const PREVIEW_ENABLED_TASKS = [
        'booking.create_option',
        'booking.update_option',
    ];

    /**
     * Whether a given task supports preview rendering.
     *
     * @param string $taskname
     * @return bool
     */
    public static function supports_preview(string $taskname): bool {
        return in_array($taskname, self::PREVIEW_ENABLED_TASKS, true);
    }

    /**
     * Filter a commands array to only those that support preview.
     *
     * @param array<int,array<string,mixed>> $commands
     * @return array<int,array<string,mixed>>
     */
    public static function filter_previewable_commands(array $commands): array {
        return array_values(array_filter(
            $commands,
            static fn(array $cmd): bool => self::supports_preview((string)($cmd['task'] ?? ''))
        ));
    }

    /**
     * Whether any command in the list supports preview.
     *
     * @param array<int,array<string,mixed>> $commands
     * @return bool
     */
    public static function has_previewable_command(array $commands): bool {
        foreach ($commands as $cmd) {
            if (self::supports_preview((string)($cmd['task'] ?? ''))) {
                return true;
            }
        }
        return false;
    }
}
