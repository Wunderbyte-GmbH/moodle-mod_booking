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
 * Agent conversation store interface.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent\local\wizard\interfaces;

/**
 * Interface for persisting and retrieving agent conversation state.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface agent_conversation_store {
    /**
     * Get or create an active thread for the given user and context.
     *
     * @param int $userid
     * @param int $contextid
     * @return \stdClass Thread record.
     */
    public function get_or_create_thread(int $userid, int $contextid): \stdClass;

    /**
     * Append a message to the thread.
     *
     * @param int    $threadid
     * @param string $role   'user' | 'assistant' | 'system'
     * @param string $content  Raw text content.
     * @param array  $structured Optional structured state (intent, entities, draft).
     * @return int  New message id.
     */
    public function add_message(int $threadid, string $role, string $content, array $structured = []): int;

    /**
     * Return all messages for a thread ordered by timecreated ASC.
     *
     * @param int $threadid
     * @return \stdClass[]
     */
    public function get_messages(int $threadid): array;

    /**
     * Return the most recent N messages (for prompt assembly).
     *
     * @param int $threadid
     * @param int $limit
     * @return \stdClass[]
     */
    public function get_recent_messages(int $threadid, int $limit): array;

    /**
     * Return the previous thread context for a user in a booking instance.
     *
     * @param int $userid
     * @param int $contextid
     * @return \stdClass|null
     */
    public function get_last_thread_for_user(int $userid, int $contextid): ?\stdClass;

    /**
     * Return user-owned thread ids that contain messages in a date window.
     *
     * @param int $userid
     * @param int $contextid
     * @param int $fromtimestamp
     * @param int $totimestamp
     * @return int[]
     */
    public function get_user_threads_by_date_window(
        int $userid,
        int $contextid,
        int $fromtimestamp,
        int $totimestamp
    ): array;

    /**
     * Return messages for one thread fenced by both message.userid and thread.userid.
     *
     * @param int $userid
     * @param int $threadid
     * @param int|null $fromtimestamp
     * @param int|null $totimestamp
     * @param string $query
     * @return \stdClass[]
     */
    public function get_user_messages_for_thread(
        int $userid,
        int $threadid,
        ?int $fromtimestamp = null,
        ?int $totimestamp = null,
        string $query = ''
    ): array;

    /**
     * Create a run record for the thread.
     *
     * @param int    $threadid
     * @param int    $userid
     * @param int    $contextid
     * @param string $idempotencykey
     * @param array  $commands   Interpreter-validated commands.
     * @return int   New run id.
     */
    public function create_run(int $threadid, int $userid, int $contextid, string $idempotencykey, array $commands): int;

    /**
     * Update run status and optionally store results.
     *
     * @param int    $runid
     * @param string $status  'pending'|'queued'|'running'|'completed'|'failed'
     * @param array  $results Optional per-command results.
     * @return void
     */
    public function update_run_status(int $runid, string $status, array $results = []): void;

    /**
     * Return a run record by id.
     *
     * @param int $runid
     * @return \stdClass|null
     */
    public function get_run(int $runid): ?\stdClass;

    /**
     * Return the latest run for a thread.
     *
     * @param int $threadid
     * @return \stdClass|null
     */
    public function get_latest_run(int $threadid): ?\stdClass;

    /**
     * Check whether a run with the given idempotency key already exists.
     *
     * @param string $idempotencykey
     * @return bool
     */
    public function run_exists(string $idempotencykey): bool;
}
