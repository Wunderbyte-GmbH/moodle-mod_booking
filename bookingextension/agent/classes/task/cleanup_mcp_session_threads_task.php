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
 * Scheduled task: purge idle per-session MCP threads and their messages/runs.
 *
 * Each MCP session (Mcp-Session-Id / stdio-bridge process) gets its own channel thread so
 * concurrent clients on one token do not share a pending-confirmation slot. Unlike a chat thread
 * there is no page reload to reclaim these, so they are cleaned up here once idle beyond the
 * configured retention. The shared 'mcp' singleton and chat threads are never matched.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_mcp_session_threads_task extends scheduled_task {
    /**
     * Get the name of this scheduled task.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_cleanup_mcp_session_threads', 'bookingextension_agent');
    }

    /**
     * Execute the task.
     */
    public function execute(): void {
        $days = (int)(get_config('bookingextension_agent', 'mcp_session_retention_days') ?: 2);
        $store = new conversation_store();
        $deleted = $store->delete_idle_mcp_session_threads($days * DAYSECS);
        mtrace("Cleaned {$deleted} idle MCP session thread(s) (retention: {$days} day(s) idle).");
    }
}
