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
 * DB-backed conversation store.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\agent;

use mod_booking\agent\interfaces\agent_conversation_store;
use stdClass;

/**
 * Persists agent conversation threads, messages, and runs in the Moodle DB.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class conversation_store implements agent_conversation_store {

    /**
     * Get or create an active thread for the given user and booking context.
     *
     * @param int $userid
     * @param int $cmid
     * @param int $bookingid
     * @return stdClass Thread record.
     */
    public function get_or_create_thread(int $userid, int $cmid, int $bookingid): stdClass {
        global $DB;

        $thread = $DB->get_record('booking_ai_threads', [
            'userid' => $userid,
            'cmid' => $cmid,
            'status' => 'active',
        ]);

        if ($thread) {
            return $thread;
        }

        $now = time();
        $record = new stdClass();
        $record->userid = $userid;
        $record->cmid = $cmid;
        $record->bookingid = $bookingid;
        $record->status = 'active';
        $record->metadatajson = null;
        $record->timecreated = $now;
        $record->timemodified = $now;
        $record->id = $DB->insert_record('booking_ai_threads', $record);

        return $record;
    }

    /**
     * Append a message to the thread.
     *
     * @param int    $threadid
     * @param string $role   'user' | 'assistant' | 'system'
     * @param string $content  Raw text content.
     * @param array  $structured Optional structured state.
     * @return int  New message id.
     */
    public function add_message(int $threadid, string $role, string $content, array $structured = []): int {
        global $DB;

        $record = new stdClass();
        $record->threadid = $threadid;
        $record->role = $role;
        $record->content = $content;
        $record->structuredjson = !empty($structured) ? json_encode($structured) : null;
        $record->timecreated = time();

        return (int) $DB->insert_record('booking_ai_messages', $record);
    }

    /**
     * Return all messages for a thread ordered by timecreated ASC.
     *
     * @param int $threadid
     * @return stdClass[]
     */
    public function get_messages(int $threadid): array {
        global $DB;
        return array_values($DB->get_records('booking_ai_messages', ['threadid' => $threadid], 'timecreated ASC'));
    }

    /**
     * Return the most recent N messages (for prompt assembly).
     *
     * @param int $threadid
     * @param int $limit
     * @return stdClass[]
     */
    public function get_recent_messages(int $threadid, int $limit): array {
        global $DB;

        $sql = 'SELECT * FROM {booking_ai_messages}
                WHERE threadid = :threadid
                ORDER BY timecreated DESC';
        $records = $DB->get_records_sql($sql, ['threadid' => $threadid], 0, $limit);
        // Return in chronological order.
        return array_reverse(array_values($records));
    }

    /**
     * Create a run record for the thread.
     *
     * @param int    $threadid
     * @param int    $userid
     * @param int    $cmid
     * @param string $idempotencykey
     * @param array  $commands   Interpreter-validated commands.
     * @return int   New run id.
     */
    public function create_run(int $threadid, int $userid, int $cmid, string $idempotencykey, array $commands): int {
        global $DB;

        $now = time();
        $record = new stdClass();
        $record->threadid = $threadid;
        $record->userid = $userid;
        $record->cmid = $cmid;
        $record->status = 'pending';
        $record->idempotencykey = $idempotencykey;
        $record->commandsjson = json_encode($commands);
        $record->resultsjson = null;
        $record->timecreated = $now;
        $record->timemodified = $now;

        return (int) $DB->insert_record('booking_ai_runs', $record);
    }

    /**
     * Update run status and optionally store results.
     *
     * @param int    $runid
     * @param string $status
     * @param array  $results Optional per-command results.
     * @return void
     */
    public function update_run_status(int $runid, string $status, array $results = []): void {
        global $DB;

        $record = new stdClass();
        $record->id = $runid;
        $record->status = $status;
        $record->timemodified = time();
        if (!empty($results)) {
            $record->resultsjson = json_encode($results);
        }

        $DB->update_record('booking_ai_runs', $record);
    }

    /**
     * Return a run record by id.
     *
     * @param int $runid
     * @return stdClass|null
     */
    public function get_run(int $runid): ?stdClass {
        global $DB;
        return $DB->get_record('booking_ai_runs', ['id' => $runid]) ?: null;
    }

    /**
     * Return the latest run for a thread.
     *
     * @param int $threadid
     * @return stdClass|null
     */
    public function get_latest_run(int $threadid): ?stdClass {
        global $DB;
        $records = $DB->get_records('booking_ai_runs', ['threadid' => $threadid], 'timecreated DESC', '*', 0, 1);
        $record = reset($records);
        return $record ?: null;
    }

    /**
     * Check whether a run with the given idempotency key already exists.
     *
     * @param string $idempotencykey
     * @return bool
     */
    public function run_exists(string $idempotencykey): bool {
        global $DB;
        return $DB->record_exists('booking_ai_runs', ['idempotencykey' => $idempotencykey]);
    }
}
