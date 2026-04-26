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

namespace mod_booking\local\wbagent;

use mod_booking\local\wbagent\interfaces\agent_conversation_store;
use stdClass;

/**
 * Persists agent conversation threads, messages, and runs in the Moodle DB.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class conversation_store implements agent_conversation_store {
    /** Default pending intent TTL in seconds. */
    private const PENDING_INTENT_TTL = 900;

    /**
     * Get the active thread for a user and cmid.
     *
     * @param int $userid
     * @param int $cmid
     * @return stdClass|null
     */
    public function get_active_thread(int $userid, int $cmid): ?stdClass {
        global $DB;

        $thread = $DB->get_record('booking_ai_threads', [
            'userid' => $userid,
            'cmid' => $cmid,
            'status' => 'active',
        ]);

        return $thread ?: null;
    }

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
     * Archive existing active threads for this user/context and create a fresh thread.
     *
     * @param int $userid
     * @param int $cmid
     * @param int $bookingid
     * @return stdClass
     */
    public function create_fresh_thread(int $userid, int $cmid, int $bookingid): stdClass {
        global $DB;

        $now = time();
        $activethreads = $DB->get_records('booking_ai_threads', [
            'userid' => $userid,
            'cmid' => $cmid,
            'status' => 'active',
        ]);

        foreach ($activethreads as $thread) {
            $update = new stdClass();
            $update->id = (int)$thread->id;
            $update->status = 'archived';
            $update->timemodified = $now;
            $DB->update_record('booking_ai_threads', $update);
        }

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
                ORDER BY timecreated DESC, id DESC';
        $records = $DB->get_records_sql($sql, ['threadid' => $threadid], 0, $limit);
        // Return in chronological order.
        return array_reverse(array_values($records));
    }

    /**
     * Return the latest assistant execution_result message for a specific run.
     *
     * @param int $threadid
     * @param int $runid
     * @return stdClass|null
     */
    public function get_latest_execution_result_message_for_run(int $threadid, int $runid): ?stdClass {
        $messages = $this->get_recent_messages($threadid, 50);
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $message = $messages[$i];
            if (($message->role ?? '') !== 'assistant') {
                continue;
            }

            $structured = json_decode((string)($message->structuredjson ?? ''), true);
            if (!is_array($structured)) {
                continue;
            }
            if ((string)($structured['response_type'] ?? '') !== 'execution_result') {
                continue;
            }
            if ((int)($structured['runid'] ?? 0) !== $runid) {
                continue;
            }

            return $message;
        }

        return null;
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

    /**
     * Check whether a run with the given idempotency key exists excluding one run id.
     *
     * @param string $idempotencykey
     * @param int $runid
     * @return bool
     */
    public function run_exists_other_than(string $idempotencykey, int $runid): bool {
        global $DB;

        $sql = 'SELECT 1
                  FROM {booking_ai_runs}
                 WHERE idempotencykey = :idempotencykey
                   AND id <> :runid';

        return $DB->record_exists_sql($sql, [
            'idempotencykey' => $idempotencykey,
            'runid' => $runid,
        ]);
    }

    /**
     * Get a metadata value from a thread.
     *
     * @param int $threadid
     * @param string $key
     * @return mixed|null
     */
    public function get_thread_metadata_value(int $threadid, string $key) {
        global $DB;

        $thread = $DB->get_record('booking_ai_threads', ['id' => $threadid], 'id, metadatajson');
        if (!$thread) {
            return null;
        }

        $metadata = json_decode((string)($thread->metadatajson ?? ''), true);
        if (!is_array($metadata) || !array_key_exists($key, $metadata)) {
            return null;
        }

        return $metadata[$key];
    }

    /**
     * Set a metadata key on a thread.
     *
     * @param int $threadid
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function set_thread_metadata_value(int $threadid, string $key, $value): void {
        global $DB;

        $thread = $DB->get_record('booking_ai_threads', ['id' => $threadid], 'id, metadatajson');
        if (!$thread) {
            return;
        }

        $metadata = json_decode((string)($thread->metadatajson ?? ''), true);
        if (!is_array($metadata)) {
            $metadata = [];
        }

        $metadata[$key] = $value;

        $update = new stdClass();
        $update->id = $threadid;
        $update->metadatajson = json_encode($metadata);
        $update->timemodified = time();
        $DB->update_record('booking_ai_threads', $update);
    }

    /**
     * Store a pending intent (commands awaiting user confirmation) for a thread.
     *
     * Call this whenever the agent returns a confirmation_request so that a
     * subsequent short confirmation ("ja", "yes", …) can re-use the commands
     * without triggering a new LLM call.
     *
     * @param int    $threadid
     * @param array  $commands  The mutation commands awaiting confirmation.
     * @param string $intentkey A caller-generated hash to identify this intent.
     * @param int    $userid
     * @param int    $cmid
     * @param int    $ttl
     * @return void
     */
    public function set_pending_intent(
        int $threadid,
        array $commands,
        string $intentkey,
        int $userid = 0,
        int $cmid = 0,
        int $ttl = self::PENDING_INTENT_TTL
    ): void {
        $now = time();
        $confirmationcode = 'C' . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $this->set_thread_metadata_value($threadid, 'pending_intent', [
            'commands' => $commands,
            'intentkey' => $intentkey,
            'checksum' => hash('sha256', json_encode($commands)),
            'timestamp' => $now,
            'expiresat' => $now + max(1, $ttl),
            'state' => 'pending',
            'userid' => $userid,
            'cmid' => $cmid,
            'confirmationcode' => $confirmationcode,
        ]);
    }

    /**
     * Retrieve the pending intent for a thread, or null if none is stored.
     *
     * @param int $threadid
     * @return array<string,mixed>|null
     */
    public function get_pending_intent(int $threadid): ?array {
        $value = $this->get_thread_metadata_value($threadid, 'pending_intent');
        if (!is_array($value) || empty($value['commands']) || !is_array($value['commands'])) {
            return null;
        }

        $state = (string)($value['state'] ?? 'pending');
        if ($state !== 'pending') {
            return null;
        }

        $expiresat = (int)($value['expiresat'] ?? 0);
        if ($expiresat > 0 && $expiresat < time()) {
            $this->clear_pending_intent($threadid);
            return null;
        }

        return $value;
    }

    /**
     * Consume a pending intent exactly once and clear it from thread metadata.
     *
     * @param int $threadid
     * @param int $userid
     * @param int $cmid
     * @return array<string,mixed>|null
     */
    public function consume_pending_intent(int $threadid, int $userid = 0, int $cmid = 0): ?array {
        $pending = $this->get_pending_intent($threadid);
        if ($pending === null) {
            return null;
        }

        if ($userid > 0 && (int)($pending['userid'] ?? 0) > 0 && (int)$pending['userid'] !== $userid) {
            return null;
        }
        if ($cmid > 0 && (int)($pending['cmid'] ?? 0) > 0 && (int)$pending['cmid'] !== $cmid) {
            return null;
        }

        $this->clear_pending_intent($threadid);
        return $pending;
    }

    /**
     * Clear the pending intent for a thread.
     *
     * Must be called after a confirmation is processed or when a new unrelated
     * message is received so that stale intents never leak into later turns.
     *
     * @param int $threadid
     * @return void
     */
    public function clear_pending_intent(int $threadid): void {
        $this->set_thread_metadata_value($threadid, 'pending_intent', null);
    }
}
