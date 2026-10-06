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

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\embeddings;

use core\task\manager as task_manager;

/**
 * Single scheduling point for embeddings rebuild adhoc tasks (skill catalog + docs corpus).
 *
 * Consolidates the previously duplicated "debounce + queue" logic so every trigger — skill-use
 * self-heal, the docs corpus settings save, the plugin upgrade reconcile, and a query-time dangling
 * hit — enqueues through one path with one debounce strategy (a per-index config marker, deduped via
 * queue_adhoc_task's check-for-existing).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class embeddings_rebuild_scheduler {
    /**
     * Queue the rebuild task unless an enqueue happened within the debounce window.
     *
     * @param \core\task\adhoc_task $task The configured rebuild task instance.
     * @param string $debouncekey The per-index config marker name (in component bookingextension_agent).
     * @param int $debounceseconds Minimum seconds between enqueues.
     * @return bool True when a task was queued.
     */
    public static function queue_if_due(\core\task\adhoc_task $task, string $debouncekey, int $debounceseconds): bool {
        $lastqueued = (int)get_config('bookingextension_agent', $debouncekey);
        if ($lastqueued > 0 && (time() - $lastqueued) < max(0, $debounceseconds)) {
            return false;
        }
        task_manager::queue_adhoc_task($task, true);
        set_config($debouncekey, (string)time(), 'bookingextension_agent');
        return true;
    }
}
