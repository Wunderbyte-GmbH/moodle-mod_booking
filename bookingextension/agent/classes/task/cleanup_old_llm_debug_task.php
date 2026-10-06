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

namespace bookingextension_agent\task;

use bookingextension_agent\local\wizard\conversation_store;
use core\task\scheduled_task;

/**
 * Scheduled task: purge raw LLM debug exchanges older than the retention period.
 *
 * Bounds the standing PII store in bx_agent_ai_llm_debug (audit 15-F01). Logging is only written when
 * the aidebugmode setting is on; this task caps how long those rows are retained.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_old_llm_debug_task extends scheduled_task {
    /**
     * Get the name of this scheduled task.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_cleanup_old_llm_debug', 'bookingextension_agent');
    }

    /**
     * Execute the task.
     */
    public function execute(): void {
        $days = (int)(get_config('bookingextension_agent', 'llm_debug_retention_days') ?: 30);
        $store = new conversation_store();
        $deleted = $store->purge_old_llm_debug_entries($days);
        mtrace("Cleaned {$deleted} old LLM debug exchange(s) (retention: {$days} days).");
    }
}
