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
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent\local\wizard;

use bookingextension_agent\local\wizard\config\runtime_feature_flags;
use bookingextension_agent\local\wizard\interfaces\agent_conversation_store;
use bookingextension_agent\local\wizard\services\phase_trace_normalizer;
use stdClass;

/**
 * Persists agent conversation threads, messages, and runs in the Moodle DB.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class conversation_store implements agent_conversation_store {
    /** @var int Width of bx_agent_ai_llm_debug.source (db/install.xml). */
    public const LLM_DEBUG_SOURCE_MAXLENGTH = 255;

    /** Default pending intent TTL in seconds. */
    private const PENDING_INTENT_TTL = 900;

    /** Preference key that stores session allowlist entries. */
    private const CONFIRMATION_SESSION_ALLOWLIST_KEY = 'bookingextension_agent_ai_confirmation_session_allowlist';

    /** Default lifetime for a confirmation (auto-confirm) allowlist entry in seconds (15 min). */
    public const CONFIRMATION_SESSION_ALLOWLIST_TTL = 900;

    /**
     * Read-only runtime feature-flag snapshot used by orchestration consumers.
     *
     * @return array
     */
    public static function get_runtime_feature_flags_snapshot(): array {
        return runtime_feature_flags::snapshot();
    }

    /**
     * Get the active thread for a user and contextid.
     *
     * @param int $userid
     * @param int $contextid
     * @return stdClass|null
     */
    public function get_active_thread(int $userid, int $contextid): ?stdClass {
        global $DB;

        $thread = $DB->get_record('bx_agent_ai_threads', [
            'userid' => $userid,
            'contextid' => $contextid,
            'status' => 'active',
        ]);

        return $thread ?: null;
    }

    /**
     * Get or create an active thread for the given user and context.
     *
     * @param int $userid
     * @param int $contextid
     * @return stdClass Thread record.
     */
    public function get_or_create_thread(int $userid, int $contextid): stdClass {
        global $DB;

        $thread = $DB->get_record('bx_agent_ai_threads', [
            'userid' => $userid,
            'contextid' => $contextid,
            'status' => 'active',
        ]);

        if ($thread) {
            return $thread;
        }

        $now = time();
        $record = new stdClass();
        $record->userid = $userid;
        $record->contextid = $contextid;
        $record->status = 'active';
        $record->metadatajson = null;
        $record->timecreated = $now;
        $record->timemodified = $now;
        $record->id = $DB->insert_record('bx_agent_ai_threads', $record);

        return $record;
    }

    /**
     * Archive existing active threads for this user/context and create a fresh thread.
     *
     * @param int $userid
     * @param int $contextid
     * @return stdClass
     */
    public function create_fresh_thread(int $userid, int $contextid): stdClass {
        global $DB;

        $now = time();
        $activethreads = $DB->get_records('bx_agent_ai_threads', [
            'userid' => $userid,
            'contextid' => $contextid,
            'status' => 'active',
        ]);

        foreach ($activethreads as $thread) {
            $update = new stdClass();
            $update->id = (int)$thread->id;
            $update->status = 'archived';
            $update->timemodified = $now;
            $DB->update_record('bx_agent_ai_threads', $update);
        }

        $record = new stdClass();
        $record->userid = $userid;
        $record->contextid = $contextid;
        $record->status = 'active';
        $record->metadatajson = null;
        $record->timecreated = $now;
        $record->timemodified = $now;
        $record->id = $DB->insert_record('bx_agent_ai_threads', $record);

        return $record;
    }

    /**
     * Get or create the channel-bound thread for a user and context (e.g. the MCP facade).
     *
     * Channel threads carry their channel name as status so they are invisible to every
     * chat-path query (all of which filter on status = 'active'): the chat UI must never
     * adopt a facade thread — queue items and pending intents live in thread metadata, so
     * sharing a thread would corrupt the chat queue. The channel is additionally stored
     * in the metadata (_channel) for diagnostics and future multi-channel filtering.
     *
     * @param int $userid
     * @param int $contextid
     * @param string $channel Channel name, e.g. 'mcp'. Must not be 'active' or 'archived'.
     * @return stdClass Thread record.
     */
    public function get_or_create_channel_thread(int $userid, int $contextid, string $channel): stdClass {
        global $DB;

        $channel = trim($channel);
        if ($channel === '' || in_array($channel, ['active', 'archived'], true)) {
            throw new \coding_exception('Invalid channel name for channel thread: ' . $channel);
        }

        $thread = $DB->get_record('bx_agent_ai_threads', [
            'userid' => $userid,
            'contextid' => $contextid,
            'status' => $channel,
        ]);

        if ($thread) {
            // Touch on reuse so idle-based cleanup (per-session MCP threads) reflects last activity.
            $now = time();
            $DB->set_field('bx_agent_ai_threads', 'timemodified', $now, ['id' => $thread->id]);
            $thread->timemodified = $now;
            return $thread;
        }

        $now = time();
        $record = new stdClass();
        $record->userid = $userid;
        $record->contextid = $contextid;
        $record->status = $channel;
        $record->metadatajson = json_encode(['_channel' => $channel]);
        $record->timecreated = $now;
        $record->timemodified = $now;
        $record->id = $DB->insert_record('bx_agent_ai_threads', $record);

        return $record;
    }

    /**
     * Delete idle per-session MCP threads (and their messages and runs).
     *
     * Per-session MCP threads carry a colon-suffixed channel key ('mcp:<hash>'); unlike a chat
     * thread there is no page reload to reclaim them, so they are purged once untouched for
     * $idleseconds. The shared 'mcp' singleton and chat threads never match (only the colon-suffixed
     * session keys do), so this is safe to run unconditionally.
     *
     * @param int $idleseconds Delete session threads whose timemodified is older than this.
     * @return int Number of threads deleted.
     */
    public function delete_idle_mcp_session_threads(int $idleseconds): int {
        global $DB;

        $cutoff = time() - max(0, $idleseconds);
        $like = $DB->sql_like('status', ':pfx');
        $threadids = $DB->get_fieldset_select(
            'bx_agent_ai_threads',
            'id',
            "$like AND timemodified < :cutoff",
            ['pfx' => 'mcp:%', 'cutoff' => $cutoff]
        );
        if (empty($threadids)) {
            return 0;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($threadids, SQL_PARAMS_NAMED);
        $DB->delete_records_select('bx_agent_ai_messages', "threadid $insql", $inparams);
        $DB->delete_records_select('bx_agent_ai_runs', "threadid $insql", $inparams);
        $DB->delete_records_select('bx_agent_ai_threads', "id $insql", $inparams);

        return count($threadids);
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

        $thread = $DB->get_record('bx_agent_ai_threads', ['id' => $threadid], 'id, userid', IGNORE_MISSING);
        if (!$thread) {
            throw new \coding_exception('Cannot add message to unknown thread id: ' . $threadid);
        }

        $record = new stdClass();
        $record->threadid = $threadid;
        $record->userid = (int)$thread->userid;
        $record->role = $role;
        $record->content = $content;
        $record->structuredjson = !empty($structured) ? json_encode($structured) : null;
        $record->timecreated = time();

        return (int) $DB->insert_record('bx_agent_ai_messages', $record);
    }

    /**
     * Write an ephemeral step-progress label to the thread.
     *
     * These messages (role='step') are visible to the polling frontend during
     * the agent loop and are filtered out of the normal conversation history.
     *
     * @param int    $threadid
     * @param int    $stepnum   1-based step counter.
     * @param string $label     Short human-readable label ("Step 1: provider.skill_name").
     * @param string $skill     Raw skill name for frontend icon selection.
     * @return int New message id.
     */
    public function add_step_message(int $threadid, int $stepnum, string $label, string $skill = ''): int {
        return $this->add_message($threadid, 'step', $label, [
            'stepnum' => $stepnum,
            'label'   => $label,
            'skill'   => $skill,
        ]);
    }

    /**
     * Delete all step messages for a thread.
     *
     * Called at the start of every turn so stale progress bubbles from prior
     * requests cannot reappear.
     *
     * @param int $threadid
     * @return void
     */
    public function clear_step_messages(int $threadid): void {
        global $DB;
        $DB->delete_records('bx_agent_ai_messages', ['threadid' => $threadid, 'role' => 'step']);
    }

    /**
     * Return step messages written after a given message id.
     *
     * Used by the frontend step-polling endpoint to fetch only new steps.
     *
     * @param int $threadid
     * @param int $sinceid  Return only messages with id > $sinceid (0 = all).
     * @return stdClass[]
     */
    public function get_step_messages_since(int $threadid, int $sinceid): array {
        global $DB;
        $sql = 'SELECT * FROM {bx_agent_ai_messages}
                 WHERE threadid = :threadid
                   AND role     = :role
                   AND id       > :sinceid
                 ORDER BY id ASC';
        return array_values($DB->get_records_sql($sql, [
            'threadid' => $threadid,
            'role'     => 'step',
            'sinceid'  => $sinceid,
        ]));
    }

    /**
     * Return all messages for a thread ordered by timecreated ASC.
     *
     * @param int $threadid
     * @return stdClass[]
     */
    public function get_messages(int $threadid): array {
        global $DB;
        return array_values($DB->get_records('bx_agent_ai_messages', ['threadid' => $threadid], 'timecreated ASC'));
    }

    /**
     * Return one thread by id.
     *
     * @param int $threadid
     * @return stdClass|null
     */
    public function get_thread(int $threadid): ?stdClass {
        global $DB;
        $thread = $DB->get_record('bx_agent_ai_threads', ['id' => $threadid]);
        return $thread ?: null;
    }

    /**
     * Return an active thread only when it belongs to the given user and context.
     *
     * Ownership-scoped fetch for webservice entry points that resume a client-supplied thread id, so
     * the raw, security-relevant scoping lives in the store rather than in the webservice layer.
     *
     * @param int $threadid
     * @param int $userid
     * @param int $contextid
     * @return stdClass|null
     */
    public function get_owned_active_thread(int $threadid, int $userid, int $contextid): ?stdClass {
        global $DB;
        if ($threadid <= 0) {
            return null;
        }
        $thread = $DB->get_record('bx_agent_ai_threads', [
            'id' => $threadid,
            'userid' => $userid,
            'contextid' => $contextid,
            'status' => 'active',
        ]);
        return $thread ?: null;
    }

    /**
     * Ownership gate: true only when the thread exists, belongs to the given user and lives in the
     * given context.
     *
     * Every webservice entry point that accepts a client-supplied threadid MUST pass it through here
     * before reading or mutating thread-scoped data — otherwise a caller could address another user's
     * conversation by guessing the id (the capability check alone only proves access to the context,
     * not ownership of the thread). The context match is defense-in-depth: callers have already
     * validated the context, and threads are pinned to the context they were created in.
     *
     * @param int $threadid
     * @param int $userid
     * @param int $contextid
     * @return bool
     */
    public function thread_belongs_to_user(int $threadid, int $userid, int $contextid): bool {
        global $DB;
        if ($threadid <= 0) {
            return false;
        }
        return $DB->record_exists('bx_agent_ai_threads', [
            'id' => $threadid,
            'userid' => $userid,
            'contextid' => $contextid,
        ]);
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

        $sql = 'SELECT * FROM {bx_agent_ai_messages}
                WHERE threadid = :threadid
                  AND role <> :steprole
                ORDER BY timecreated DESC, id DESC';
        $records = $DB->get_records_sql($sql, [
            'threadid' => $threadid,
            'steprole' => 'step',
        ], 0, $limit);
        // Return in chronological order.
        return array_reverse(array_values($records));
    }

    /**
     * Return the previous thread for a user in this booking instance.
     *
     * Preference order:
     * 1) latest archived thread
     * 2) latest non-active thread
     * 3) latest thread excluding the active one
     *
     * @param int $userid
     * @param int $contextid
     * @return stdClass|null
     */
    public function get_last_thread_for_user(int $userid, int $contextid): ?stdClass {
        global $DB;

        $activethread = $DB->get_record('bx_agent_ai_threads', [
            'userid' => $userid,
            'contextid' => $contextid,
            'status' => 'active',
        ], '*', IGNORE_MISSING);
        $activeid = (int)($activethread->id ?? 0);

        $sql = 'SELECT *
                  FROM {bx_agent_ai_threads}
                 WHERE userid = :userid
                   AND contextid = :contextid
                   AND status = :status
              ORDER BY timemodified DESC, id DESC';
        $archived = $DB->get_records_sql($sql, [
            'userid' => $userid,
            'contextid' => $contextid,
            'status' => 'archived',
        ], 0, 1);
        if (!empty($archived)) {
            $thread = reset($archived);
            return $thread ?: null;
        }

        $sql = 'SELECT *
                  FROM {bx_agent_ai_threads}
                 WHERE userid = :userid
                   AND contextid = :contextid
                   AND status <> :status
              ORDER BY timemodified DESC, id DESC';
        $nonactive = $DB->get_records_sql($sql, [
            'userid' => $userid,
            'contextid' => $contextid,
            'status' => 'active',
        ], 0, 1);
        if (!empty($nonactive)) {
            $thread = reset($nonactive);
            return $thread ?: null;
        }

        $params = [
            'userid' => $userid,
            'contextid' => $contextid,
        ];
        $excludewhere = '';
        if ($activeid > 0) {
            $excludewhere = ' AND id <> :activeid';
            $params['activeid'] = $activeid;
        }
        $sql = 'SELECT *
                  FROM {bx_agent_ai_threads}
                 WHERE userid = :userid
                   AND contextid = :contextid'
                . $excludewhere
                . ' ORDER BY timemodified DESC, id DESC';
        $threads = $DB->get_records_sql($sql, $params, 0, 1);
        $thread = reset($threads);
        return $thread ?: null;
    }

    /**
     * Return user-owned thread ids with messages in the given time window.
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
    ): array {
        global $DB;

        // With SELECT DISTINCT, ordering by a column that is not in the select list is invalid on
        // PostgreSQL (and MySQL under ONLY_FULL_GROUP_BY); MariaDB tolerates it. Selecting t.timemodified
        // keeps the query portable. t.id is the PK, so DISTINCT (id, timemodified) yields the same threads.
        $sql = 'SELECT DISTINCT t.id, t.timemodified
                  FROM {bx_agent_ai_threads} t
                  JOIN {bx_agent_ai_messages} m
                    ON m.threadid = t.id
                 WHERE t.userid = :userid
                   AND m.userid = :userid2
                   AND t.contextid = :contextid
                   AND m.timecreated >= :fromts
                   AND m.timecreated <= :tots
              ORDER BY t.timemodified DESC, t.id DESC';
        $records = $DB->get_records_sql($sql, [
            'userid' => $userid,
            'userid2' => $userid,
            'contextid' => $contextid,
            'fromts' => $fromtimestamp,
            'tots' => $totimestamp,
        ]);

        return array_values(array_map(static fn(stdClass $row): int => (int)$row->id, $records));
    }

    /**
     * Return messages for a user-owned thread with strict dual user fences.
     *
     * @param int $userid
     * @param int $threadid
     * @param int|null $fromtimestamp
     * @param int|null $totimestamp
     * @param string $query
     * @return stdClass[]
     */
    public function get_user_messages_for_thread(
        int $userid,
        int $threadid,
        ?int $fromtimestamp = null,
        ?int $totimestamp = null,
        string $query = ''
    ): array {
        global $DB;

        $thread = $DB->get_record('bx_agent_ai_threads', ['id' => $threadid, 'userid' => $userid], 'id', IGNORE_MISSING);
        if (!$thread) {
            return [];
        }

        $params = [
            'threadid' => $threadid,
            'userid' => $userid,
            'userid2' => $userid,
            'steprole' => 'step',
        ];
        $where = 'm.threadid = :threadid
                  AND m.userid = :userid
                  AND t.userid = :userid2
                  AND m.role <> :steprole';

        if ($fromtimestamp !== null) {
            $where .= ' AND m.timecreated >= :fromts';
            $params['fromts'] = $fromtimestamp;
        }
        if ($totimestamp !== null) {
            $where .= ' AND m.timecreated <= :tots';
            $params['tots'] = $totimestamp;
        }

        $query = trim($query);
        if ($query !== '') {
            $like = '%' . $DB->sql_like_escape(\core_text::strtolower($query)) . '%';
            $where .= ' AND (
                ' . $DB->sql_like('LOWER(' . $DB->sql_cast_to_char('m.content') . ')', ':querylike', false) . '
                OR ' . $DB->sql_like('LOWER(' . $DB->sql_cast_to_char('m.structuredjson') . ')', ':querylikestruct', false) . '
            )';
            $params['querylike'] = $like;
            $params['querylikestruct'] = $like;
        }

        $sql = 'SELECT m.*
                  FROM {bx_agent_ai_messages} m
                  JOIN {bx_agent_ai_threads} t
                    ON t.id = m.threadid
                 WHERE ' . $where . '
              ORDER BY m.timecreated ASC, m.id ASC';
        return array_values($DB->get_records_sql($sql, $params));
    }

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
    public function create_run(int $threadid, int $userid, int $contextid, string $idempotencykey, array $commands): int {
        global $DB;

        $now = time();
        $record = new stdClass();
        $record->threadid = $threadid;
        $record->userid = $userid;
        $record->contextid = $contextid;
        $record->status = 'pending';
        $record->idempotencykey = $idempotencykey;
        $record->commandsjson = json_encode($commands);
        $record->resultsjson = null;
        $record->timecreated = $now;
        $record->timemodified = $now;

        return (int) $DB->insert_record('bx_agent_ai_runs', $record);
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

        $DB->update_record('bx_agent_ai_runs', $record);
    }

    /**
     * Return a run record by id.
     *
     * @param int $runid
     * @return stdClass|null
     */
    public function get_run(int $runid): ?stdClass {
        global $DB;
        return $DB->get_record('bx_agent_ai_runs', ['id' => $runid]) ?: null;
    }

    /**
     * Return the latest run for a thread.
     *
     * @param int $threadid
     * @return stdClass|null
     */
    public function get_latest_run(int $threadid): ?stdClass {
        global $DB;
        $records = $DB->get_records('bx_agent_ai_runs', ['threadid' => $threadid], 'timecreated DESC', '*', 0, 1);
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
        return $DB->record_exists('bx_agent_ai_runs', ['idempotencykey' => $idempotencykey]);
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
                  FROM {bx_agent_ai_runs}
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

        $thread = $DB->get_record('bx_agent_ai_threads', ['id' => $threadid], 'id, metadatajson');
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

        $thread = $DB->get_record('bx_agent_ai_threads', ['id' => $threadid], 'id, metadatajson');
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
        $DB->update_record('bx_agent_ai_threads', $update);
    }

    /**
     * Store the latest planner trace history on the thread.
     *
     * @param int $threadid
     * @param string[] $plannertracehistory
     * @return void
     */
    public function set_planner_trace_history(int $threadid, array $plannertracehistory): void {
        $history = [];
        foreach ($plannertracehistory as $entry) {
            $trimmed = trim((string)$entry);
            if ($trimmed !== '') {
                $history[] = $trimmed;
            }
        }

        $this->set_thread_metadata_value($threadid, 'planner_trace_history', $history);
    }

    /**
     * Store the latest phase trace on the thread.
     *
     * @param int $threadid
     * @param array $phasetrace
     * @return void
     */
    public function set_phase_trace(int $threadid, array $phasetrace): void {
        $this->set_thread_metadata_value($threadid, 'phase_trace', phase_trace_normalizer::normalize($phasetrace));
    }

    /**
     * Store pending confirmation intent for a thread.
     *
     * @param int $threadid
     * @param string $intentkey
     * @param int $userid
     * @param int $contextid
     * @param array $metadata
     * @param int $ttl
     * @return void
     */
    public function set_pending_intent(
        int $threadid,
        string $intentkey,
        int $userid = 0,
        int $contextid = 0,
        array $metadata = [],
        int $ttl = self::PENDING_INTENT_TTL
    ): void {
        $now = time();
        $confirmationcode = 'C' . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $queueitemids = array_values(array_filter(array_map('strval', (array)($metadata['queue_item_ids'] ?? []))));
        $queueitemids = array_values(array_unique($queueitemids));

        $pendingintent = [
            'intentkey' => $intentkey,
            'checksum' => hash('sha256', json_encode($queueitemids)),
            'timestamp' => $now,
            'expiresat' => $now + max(1, $ttl),
            'state' => 'pending',
            'userid' => $userid,
            'contextid' => $contextid,
            'confirmationcode' => $confirmationcode,
            'queue_item_ids' => $queueitemids,
            'queue_authoritative' => true,
        ];

        foreach ($metadata as $key => $value) {
            $normalizedkey = trim((string)$key);
            if ($normalizedkey === '') {
                continue;
            }
            $pendingintent[$normalizedkey] = $value;
        }

        $this->set_thread_metadata_value($threadid, 'pending_intent', $pendingintent);
    }

    /**
     * Retrieve the pending intent for a thread, or null if none is stored.
     *
     * @param int $threadid
     * @return array|null
     */
    public function get_pending_intent(int $threadid): ?array {
        $value = $this->get_thread_metadata_value($threadid, 'pending_intent');
        if (!is_array($value)) {
            return null;
        }

        $hasqueueitems = !empty(array_filter(array_map('strval', (array)($value['queue_item_ids'] ?? []))));
        if (!$hasqueueitems) {
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
     * @param int $contextid
     * @return array|null
     */
    public function consume_pending_intent(int $threadid, int $userid = 0, int $contextid = 0): ?array {
        $pending = $this->get_pending_intent($threadid);
        if ($pending === null) {
            return null;
        }

        if ($userid > 0 && (int)($pending['userid'] ?? 0) > 0 && (int)$pending['userid'] !== $userid) {
            return null;
        }
        if ($contextid > 0 && (int)($pending['contextid'] ?? 0) > 0 && (int)$pending['contextid'] !== $contextid) {
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

    /**
     * Allow confirmations for a booking context for the current session window.
     *
     * Thread id is accepted for backward compatibility but not part of the key,
     * so a page reload/new thread keeps the allowance active.
     *
     * @param int $userid
     * @param int $contextid
     * @param int $threadid
     * @param int|null $expiresat
     * @return void
     */
    public function allow_confirmation_for_thread(int $userid, int $contextid, int $threadid, ?int $expiresat = null): void {
        $allowlist = $this->get_confirmation_session_allowlist($userid);
        $key = $this->make_confirmation_session_allowlist_key($contextid);
        $allowlist[$key] = [
            'contextid' => $contextid,
            'threadid' => $threadid,
            'expiresat' => $expiresat ?? (time() + self::CONFIRMATION_SESSION_ALLOWLIST_TTL),
        ];
        $this->save_confirmation_session_allowlist($userid, $allowlist);
    }

    /**
     * Check whether confirmations may be auto-approved for this Moodle context.
     *
     * @param int $userid
     * @param int $contextid
     * @return bool
     */
    public function is_confirmation_allowed_for_session(int $userid, int $contextid): bool {
        $allowlist = $this->get_confirmation_session_allowlist($userid);
        $key = $this->make_confirmation_session_allowlist_key($contextid);
        return !empty($allowlist[$key]);
    }

    /**
     * Check whether confirmations may be auto-approved for this booking context.
     *
     * Thread id is accepted for backward compatibility but ignored for matching.
     *
     * @param int $userid
     * @param int $contextid
     * @param int $threadid
     * @return bool
     */
    public function is_confirmation_allowed_for_thread(int $userid, int $contextid, int $threadid): bool {
        return $this->is_confirmation_allowed_for_session($userid, $contextid);
    }

    /**
     * Build a stable preference key per booking context.
     *
     * @param int $contextid
     * @return string
     */
    private function make_confirmation_session_allowlist_key(int $contextid): string {
        return (string)$contextid;
    }

    /**
     * Load and prune the allowlist from user preferences.
     *
     * @param int $userid
     * @return array
     */
    private function get_confirmation_session_allowlist(int $userid): array {
        $raw = (string)get_user_preferences(self::CONFIRMATION_SESSION_ALLOWLIST_KEY, '', $userid);
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $now = time();
        $allowlist = [];
        foreach ($decoded as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $contextid = (int)($entry['contextid'] ?? 0);
            // Thread id is informational only and not part of matching.
            // Keep 0 for pre-thread allowances created before first message.
            $threadid = max(0, (int)($entry['threadid'] ?? 0));
            $expiresat = (int)($entry['expiresat'] ?? 0);
            if ($contextid <= 0 || $expiresat <= $now) {
                continue;
            }

            $allowlist[(string)$key] = [
                'contextid' => $contextid,
                'threadid' => $threadid,
                'expiresat' => $expiresat,
            ];
        }

        if ($allowlist !== $decoded) {
            $this->save_confirmation_session_allowlist($userid, $allowlist);
        }

        return $allowlist;
    }

    /**
     * Persist the allowlist in user preferences.
     *
     * @param int $userid
     * @param array $allowlist
     * @return void
     */
    private function save_confirmation_session_allowlist(int $userid, array $allowlist): void {
        set_user_preference(self::CONFIRMATION_SESSION_ALLOWLIST_KEY, json_encode($allowlist), $userid);
    }

    /**
     * Persist one raw LLM request/response exchange for debug mode.
     *
     * @param int $threadid
     * @param int $userid
     * @param int $contextid
     * @param string $source
     * @param string $requesttext
     * @param string $responsetext
     * @param int $success
     * @param string $errormessage
     * @return int
     */
    public function add_llm_debug_entry(
        int $threadid,
        int $userid,
        int $contextid,
        string $source,
        string $requesttext,
        string $responsetext,
        int $success,
        string $errormessage = ''
    ): int {
        global $DB;

        $record = new stdClass();
        $record->threadid = $threadid;
        $record->userid = $userid;
        $record->contextid = $contextid;
        // The column is char(255) since 2026092201; a longer call-site string is cut rather than
        // thrown at the database (F72: an overflow here killed the user's turn).
        $record->source = \core_text::substr(trim($source), 0, self::LLM_DEBUG_SOURCE_MAXLENGTH);
        $record->requesttext = $requesttext;
        $record->responsetext = $responsetext;
        $record->success = $success ? 1 : 0;
        $record->errormessage = $errormessage;
        $record->timecreated = time();

        return (int)$DB->insert_record('bx_agent_ai_llm_debug', $record);
    }

    /**
     * Return latest raw LLM exchanges for a thread.
     *
     * @param int $threadid
     * @param int $limit
     * @return stdClass[]
     */
    public function get_llm_debug_entries(int $threadid, int $limit = 100): array {
        global $DB;

        $records = $DB->get_records(
            'bx_agent_ai_llm_debug',
            ['threadid' => $threadid],
            'id DESC',
            '*',
            0,
            max(1, $limit)
        );

        return array_reverse(array_values($records));
    }

    /**
     * Delete raw LLM debug exchanges older than the given number of days.
     *
     * Bounds the standing PII store in bx_agent_ai_llm_debug (audit 15-F01). A retention of 0 (or less)
     * is treated as "keep forever" and deletes nothing.
     *
     * @param int $days
     * @return int Number of rows deleted.
     */
    public function purge_old_llm_debug_entries(int $days): int {
        global $DB;

        if ($days <= 0) {
            return 0;
        }

        $cutoff = time() - ($days * DAYSECS);
        $count = $DB->count_records_select('bx_agent_ai_llm_debug', 'timecreated < :cutoff', ['cutoff' => $cutoff]);
        if ($count > 0) {
            $DB->delete_records_select('bx_agent_ai_llm_debug', 'timecreated < :cutoff', ['cutoff' => $cutoff]);
        }

        return (int)$count;
    }
}
