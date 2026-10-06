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
 * Shadow queue manager backed by thread metadata.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\queue;

use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\services\risk\risk_class_resolver;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\interfaces\queue_identity_provider_interface;
use bookingextension_agent\local\wizard\services\preflight_execution_gate;
use bookingextension_agent\local\wizard\services\queue_status_policy;
use bookingextension_agent\local\wizard\services\queue_transition_service;
use bookingextension_agent\local\wizard\skill_registry;

/**
 * Shadow queue manager for queue status tracking.
 */
class queue_manager {
    /** Metadata key for queue items. */
    private const META_QUEUE_ITEMS = '_skill_queue_items';

    /** Metadata key for queue sequence. */
    private const META_QUEUE_SEQ = '_skill_queue_seq';

    /** @var conversation_store */
    private conversation_store $store;

    /** @var skill_registry|null */
    private ?skill_registry $registry;

    /** @var int Default TTL for blocked confirmations in seconds. */
    private const DEFAULT_BLOCKED_TTL_SECONDS = 900;

    /** @var string[] Queue fields allowed via update_status extra payload. */
    private const ALLOWED_EXTRA_STATUS_FIELDS = [
        'preflight_retry_count',
        'retry_after_ms',
        'backoff_ms',
        'blocked_expires_at',
        'next_retry_at',
        'retry_count',
        'retry_layers',
        'retry_layer',
        'retry_origin',
        'retry_reason',
        'retry_attempt',
        'retry_hint_category',
        'retry_terminal_reason',
        'reason_code',
    ];

    /**
     * Constructor.
     *
     * @param conversation_store $store
     * @param skill_registry|null $registry
     */
    public function __construct(conversation_store $store, ?skill_registry $registry = null) {
        $this->store = $store;
        $this->registry = $registry;
    }

    /**
     * Enqueue a command into the shadow queue.
     *
     * @param int $threadid
     * @param int $runid
     * @param int $stepid
     * @param array $command
     * @param string $mutability readonly|mutating
     * @param string $status
     * @param array $dependson
     * @return array
     */
    public function enqueue_command(
        int $threadid,
        int $runid,
        int $stepid,
        array $command,
        string $mutability,
        string $status,
        array $dependson = []
    ): array {
        $items = $this->get_queue_items($threadid);
        $dependson = array_values(array_map('strval', $dependson));
        $contextid = $this->resolve_thread_contextid($threadid);

        if (
            !empty($dependson)
            && !$this->validate_depends_on_is_dag($items, $dependson)
        ) {
            $now = time();
            $seq = $this->next_sequence($threadid);
            $faileditem = [
                'queue_item_id' => 'q' . $threadid . '_' . $seq,
                'thread_id' => $threadid,
                'contextid' => $contextid,
                'run_id' => $runid,
                'step_id' => $stepid,
                'skill' => trim((string)($command['skill'] ?? '')),
                'input' => is_array($command['input'] ?? null) ? (array)$command['input'] : [],
                'prepared_input' => null,
                'guard_token' => '',
                'input_signature' => '',
                'input_signature_mode' => 'none',
                'input_signature_payload' => [],
                'mutability' => $mutability,
                'risk_class' => risk_class_resolver::normalize((string)($command['risk_class'] ?? '')),
                'depends_on' => $dependson,
                'status' => queue_status_policy::failed_status(),
                'retry_count' => 0,
                'preflight_retry_count' => 0,
                'next_retry_at' => null,
                'retry_after_ms' => 0,
                'backoff_ms' => 0,
                'blocked_expires_at' => null,
                'issue_codes' => ['DEPENDENCY_CYCLE'],
                'error_class' => 'dependency_cycle',
                'last_error_message' => 'depends_on cycle detected during queue ingestion.',
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $items[] = $faileditem;
            $this->save_queue_items($threadid, $items);
            return $faileditem;
        }

        $seq = $this->next_sequence($threadid);
        $now = time();

        $skill = trim((string)($command['skill'] ?? ''));
        $input = is_array($command['input'] ?? null) ? (array)$command['input'] : [];
        $signaturedetails = $this->build_input_signature_details($skill, $input);
        $signature = (string)($signaturedetails['signature'] ?? '');
        $signaturemode = (string)($signaturedetails['mode'] ?? 'raw_input');
        $signaturepayload = is_array($signaturedetails['payload'] ?? null) ? (array)$signaturedetails['payload'] : [];
        $riskclass = risk_class_resolver::normalize((string)($command['risk_class'] ?? ''));

        // Idempotency: if an equivalent item (same signature) is already in a
        // non-terminal state, return it instead of creating a duplicate.
        foreach ($items as $existing) {
            if (
                (string)($existing['input_signature'] ?? '') === $signature
                && !queue_status_policy::is_terminal_status((string)($existing['status'] ?? ''))
            ) {
                $reused = $existing;
                $reused['idempotency_reused'] = true;
                $reused['idempotency_reason'] = 'QUEUE_SIGNATURE_REUSE';
                return $reused;
            }
        }

        $item = [
            'queue_item_id' => 'q' . $threadid . '_' . $seq,
            'thread_id' => $threadid,
            'contextid' => $contextid,
            // Operating context for cross-context execution; equals the ambient contextid when the
            // skill does not target another context. Carried so async confirmed runs and the guard
            // token target the same context as preflight did.
            'operating_contextid' => (int)($command['operating_contextid'] ?? $contextid),
            'run_id' => $runid,
            'step_id' => $stepid,
            'skill' => $skill,
            'version' => max(1, (int)($command['version'] ?? 1)),
            'input' => $input,
            'prepared_input' => null,
            'guard_token' => '',
            'input_signature' => $signature,
            'input_signature_mode' => $signaturemode,
            'input_signature_payload' => $signaturepayload,
            'mutability' => $mutability,
            'risk_class' => $riskclass,
            'depends_on' => $dependson,
            'status' => $status,
            'retry_count' => 0,
            'preflight_retry_count' => 0,
            'next_retry_at' => null,
            'retry_after_ms' => 0,
            'backoff_ms' => 0,
            'blocked_expires_at' => $this->resolve_blocked_expires_at($status, $now, $riskclass),
            'issue_codes' => [],
            'error_class' => '',
            'last_error_message' => '',
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $items[] = $item;
        $this->save_queue_items($threadid, $items);
        return $item;
    }

    /**
     * Update queue item status and optional issue metadata.
     *
     * @param int $threadid
     * @param string $queueitemid
     * @param string $status
     * @param array $issuecodes
     * @param string $errorclass
     * @param string $lasterrormessage
     * @param array $extrafields
     * @return void
     */
    public function update_status(
        int $threadid,
        string $queueitemid,
        string $status,
        array $issuecodes = [],
        string $errorclass = '',
        string $lasterrormessage = '',
        array $extrafields = []
    ): void {
        $items = $this->get_queue_items($threadid);
        $now = time();
        foreach ($items as &$item) {
            if ((string)($item['queue_item_id'] ?? '') !== $queueitemid) {
                continue;
            }
            $item['status'] = $status;
            $item['updated_at'] = $now;
            $item['blocked_expires_at'] = $this->resolve_blocked_expires_at($status, $now);
            if (!empty($issuecodes)) {
                $item['issue_codes'] = array_values(array_unique(array_map('strval', $issuecodes)));
            }
            if ($errorclass !== '') {
                $item['error_class'] = $errorclass;
            }
            if ($lasterrormessage !== '') {
                $item['last_error_message'] = $lasterrormessage;
            }
            if (!empty($extrafields)) {
                foreach ($extrafields as $key => $value) {
                    $normalizedkey = trim((string)$key);
                    if (!in_array($normalizedkey, self::ALLOWED_EXTRA_STATUS_FIELDS, true)) {
                        continue;
                    }
                    $item[$normalizedkey] = $value;
                }
            }
            break;
        }
        unset($item);

        // A real item's terminal transition settles its bound placeholder (F5): success is the
        // only path that may mark a placeholder succeeded; hard failures fail it; a preflight
        // needs_clarification block leaves it to ensure_blocked_step_representation().
        $this->settle_bound_placeholder(
            $items,
            $queueitemid,
            $status,
            trim((string)($extrafields['reason_code'] ?? ''))
        );

        $this->save_queue_items($threadid, $items);
    }

    /**
     * Return all queue items for a thread.
     *
     * @param int $threadid
     * @return array[]
     */
    public function get_queue_items(int $threadid): array {
        $items = $this->store->get_thread_metadata_value($threadid, self::META_QUEUE_ITEMS);
        return is_array($items) ? array_values(array_filter($items, static fn($row): bool => is_array($row))) : [];
    }

    /**
     * Return a single queue item by id.
     *
     * @param int $threadid
     * @param string $queueitemid
     * @return array|null
     */
    public function get_queue_item(int $threadid, string $queueitemid): ?array {
        $queueitemid = trim($queueitemid);
        if ($queueitemid === '') {
            return null;
        }

        foreach ($this->get_queue_items($threadid) as $item) {
            if ((string)($item['queue_item_id'] ?? '') === $queueitemid) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Save queue items for a thread.
     *
     * @param int $threadid
     * @param array $items
     * @return void
     */
    public function save_queue_items(int $threadid, array $items): void {
        $this->store->set_thread_metadata_value($threadid, self::META_QUEUE_ITEMS, array_values($items));
    }

    /**
     * Persist prepared_input for a queue item once preflight resolved it.
     *
     * @param int $threadid
     * @param string $queueitemid
     * @param int $contextid
     * @param array $preparedinput
     * @param int|null $operatingcontextid
     * @return void
     */
    public function set_prepared_input(
        int $threadid,
        string $queueitemid,
        int $contextid,
        array $preparedinput,
        ?int $operatingcontextid = null
    ): void {
        $items = $this->get_queue_items($threadid);
        $now = time();
        foreach ($items as &$item) {
            if ((string)($item['queue_item_id'] ?? '') !== $queueitemid) {
                continue;
            }
            $item['prepared_input'] = $preparedinput;
            // Persist the operating context resolved during preflight (e.g. a cross-context module
            // target) back onto the queue item, so the confirmed/async execution AND the guard token
            // target the SAME context preflight resolved. Without this the item kept its creation-time
            // (ambient) operating_contextid and a cross-context target silently fell back to the
            // ambient scope at execution (thread 562: a site-home create_option ran against the site
            // context → cmid 0 → "Invalid course module").
            if ($operatingcontextid !== null && $operatingcontextid > 0) {
                $item['operating_contextid'] = $operatingcontextid;
            }
            $skillname = trim((string)($item['skill'] ?? ''));
            // Bind the guard to the item's operating context (cross-context target), falling back
            // to the passed contextid — must match what the executor verifies.
            $opcontextid = (int)($item['operating_contextid'] ?? $contextid);
            $item['guard_token'] = $skillname !== ''
                ? preflight_execution_gate::build_guard_token($skillname, $opcontextid, $preparedinput)
                : '';
            $item['updated_at'] = $now;
            break;
        }
        unset($item);

        $this->save_queue_items($threadid, $items);
    }

    /**
     * Atomically acquire the running slot for a queue item.
     *
     * Uses a DB-level row lock (FOR UPDATE on MySQL/PostgreSQL) so concurrent
     * requests cannot both pass the "no other running item" check and both
     * proceed to execute.  On MSSQL the lock clause is omitted; the method
     * still works correctly in single-user scenarios.
     *
     * @param int    $threadid     Thread that owns the queue.
     * @param string $queueitemid  The item that wants to become 'running'.
     * @return bool  true  – slot acquired, item is now persisted as 'running'.
     *               false – another item (or this item) is already running, or item not found.
     */
    public function try_mark_running(int $threadid, string $queueitemid): bool {
        global $DB;

        $queueitemid = trim($queueitemid);
        if ($queueitemid === '') {
            return false;
        }

        try {
            $transaction = $DB->start_delegated_transaction();

            // Lock the thread row so concurrent callers serialise behind this transaction.
            // FOR UPDATE is supported on MySQL/MariaDB and PostgreSQL; skip on MSSQL.
            $forupdate = $DB->get_dbfamily() !== 'mssql' ? ' FOR UPDATE' : '';
            $thread = $DB->get_record_sql(
                "SELECT id, metadatajson FROM {bx_agent_ai_threads} WHERE id = :id{$forupdate}",
                ['id' => $threadid]
            );

            if (!$thread) {
                $transaction->allow_commit();
                return false;
            }

            $metadata = json_decode((string)($thread->metadatajson ?? ''), true);
            if (!is_array($metadata)) {
                $transaction->allow_commit();
                return false;
            }

            $items = is_array($metadata[self::META_QUEUE_ITEMS] ?? null)
                ? $metadata[self::META_QUEUE_ITEMS]
                : [];

            // Reject if ANY item is already running (including the target itself,
            // which would indicate a concurrent request already acquired the slot).
            foreach ($items as $item) {
                if ((string)($item['status'] ?? '') === 'running') {
                    $transaction->allow_commit();
                    return false;
                }
            }

            // Mark the target item as running.
            $found = false;
            foreach ($items as &$item) {
                if ((string)($item['queue_item_id'] ?? '') === $queueitemid) {
                    $item['status'] = 'running';
                    $item['updated_at'] = time();
                    $found = true;
                    break;
                }
            }
            unset($item);

            if (!$found) {
                $transaction->allow_commit();
                return false;
            }

            $metadata[self::META_QUEUE_ITEMS] = array_values($items);
            $update = new \stdClass();
            $update->id = $threadid;
            $update->metadatajson = json_encode($metadata);
            $update->timemodified = time();
            $DB->update_record('bx_agent_ai_threads', $update);

            $transaction->allow_commit();
            return true;
        } catch (\Throwable $e) {
            // Transaction rolled back automatically on exception in Moodle.
            // Log so a genuine DB failure is not silently indistinguishable from
            // "slot already taken" (which would quietly block the queue).
            debugging('try_mark_running failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }

    /**
     * Determine whether a queue item can be picked up right now.
     *
     * @param array $item
     * @param int|null $now
     * @param array[]|null $items
     * @return bool
     */
    public function can_pickup_now(array $item, ?int $now = null, ?array $items = null): bool {
        $now = $now ?? time();
        $status = trim((string)($item['status'] ?? ''));
        if (!queue_status_policy::is_pickup_ready_status($status)) {
            return false;
        }

        $blockedexpiresat = (int)($item['blocked_expires_at'] ?? 0);
        if ($blockedexpiresat > 0 && $blockedexpiresat > $now) {
            return false;
        }

        $nextretryat = (int)($item['next_retry_at'] ?? 0);
        if ($nextretryat > 0 && $nextretryat > $now) {
            return false;
        }

        if (!$this->dependencies_succeeded_from_items($item, $items)) {
            return false;
        }

        return true;
    }

    /**
     * Check whether all dependencies for a queue item have succeeded.
     *
     * @param int $threadid
     * @param array $item
     * @return bool
     */
    public function dependencies_succeeded(int $threadid, array $item): bool {
        return $this->dependencies_succeeded_from_items($item, $this->get_queue_items($threadid));
    }

    /**
     * Check dependencies against a provided queue snapshot.
     *
     * @param array $item
     * @param array[]|null $items
     * @return bool
     */
    private function dependencies_succeeded_from_items(array $item, ?array $items = null): bool {
        $dependson = array_values(array_filter(array_map('strval', (array)($item['depends_on'] ?? []))));
        if (empty($dependson)) {
            return true;
        }

        if ($items === null) {
            $threadid = (int)($item['thread_id'] ?? 0);
            if ($threadid <= 0) {
                return false;
            }
            $items = $this->get_queue_items($threadid);
        }

        $byid = [];
        foreach ($items as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $id = trim((string)($candidate['queue_item_id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $byid[$id] = $candidate;
        }

        foreach ($dependson as $dependencyid) {
            if (!isset($byid[$dependencyid])) {
                return false;
            }
            if (!queue_status_policy::is_dependency_satisfied_status((string)($byid[$dependencyid]['status'] ?? ''))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate that appending a node with given dependencies keeps graph acyclic.
     *
     * @param array[] $existingitems
     * @param string[] $newdependson
     * @return bool
     */
    public function validate_depends_on_is_dag(array $existingitems, array $newdependson): bool {
        if (empty($newdependson)) {
            return true;
        }

        $graph = [];
        foreach ($existingitems as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = trim((string)($item['queue_item_id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $graph[$id] = array_values(array_map('strval', (array)($item['depends_on'] ?? [])));
        }

        $newid = '__new__';
        $graph[$newid] = array_values(array_map('strval', $newdependson));
        $state = [];
        foreach (array_keys($graph) as $node) {
            if ($this->dfs_cycle_detect($node, $graph, $state)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Fail blocked confirmation queue items where TTL expired.
     *
     * @param int $threadid
     * @return int Number of changed items.
     */
    public function fail_expired_blocked_items(int $threadid): int {
        $changed = 0;
        $now = time();
        $items = $this->get_queue_items($threadid);
        foreach ($items as &$item) {
            if (!is_array($item)) {
                continue;
            }
            if (!queue_status_policy::is_blocked_confirmation_status((string)($item['status'] ?? ''))) {
                continue;
            }
            $expiresat = (int)($item['blocked_expires_at'] ?? 0);
            if ($expiresat <= 0 || $expiresat > $now) {
                continue;
            }
            $item['status'] = queue_status_policy::failed_status();
            $item['issue_codes'] = ['BLOCKED_CONFIRMATION_TIMEOUT'];
            $item['error_class'] = 'blocked_timeout';
            $item['last_error_message'] = 'blocked_confirmation TTL expired.';
            $item['updated_at'] = $now;
            $expiredids[] = (string)($item['queue_item_id'] ?? '');
            $changed++;
        }
        unset($item);

        if ($changed > 0) {
            // The step never executed: its bound placeholder fails with it (F5).
            foreach ($expiredids ?? [] as $expiredid) {
                $this->settle_bound_placeholder($items, $expiredid, queue_status_policy::failed_status(), '');
            }
            $this->save_queue_items($threadid, $items);
        }
        return $changed;
    }

    /** @var int Seconds after which a 'running' queue item counts as a crash corpse. */
    private const STALE_RUNNING_MAX_AGE_SECONDS = 600;

    /**
     * Fail 'running' queue items whose claim is older than the stale threshold.
     *
     * A Throwable between the running claim and the terminal transition strands a 'running'
     * corpse. Once the claim is ENFORCED (a lost try_mark_running claim hard-skips execution),
     * such a corpse would block every further confirm on the thread — reaping stale corpses
     * first is therefore the precondition for claim enforcement (audit 554 addendum, fix 2).
     *
     * @param int $threadid
     * @return int Number of reaped items.
     */
    public function fail_stale_running_items(int $threadid): int {
        $changed = 0;
        $now = time();
        $items = $this->get_queue_items($threadid);
        $reapedids = [];
        foreach ($items as &$item) {
            if (!is_array($item)) {
                continue;
            }
            $status = (string)($item['status'] ?? '');
            $updatedat = (int)($item['updated_at'] ?? 0);
            $tooold = $updatedat > 0 && ($now - $updatedat) >= self::STALE_RUNNING_MAX_AGE_SECONDS;

            if ($status === 'running') {
                if (!$tooold) {
                    continue;
                }
                $item['status'] = queue_status_policy::failed_status();
                $item['issue_codes'] = ['RUNNING_REAPED'];
                $item['error_class'] = 'stale_running';
                $item['last_error_message'] = 'running claim exceeded the stale threshold (crash corpse reaped).';
                $item['updated_at'] = $now;
                $reapedids[] = (string)($item['queue_item_id'] ?? '');
                $changed++;
                continue;
            }

            // Orphaned realizing placeholders (audit C7, F5 edge case): a realizing placeholder
            // settles ONLY through its bound command's terminal transition. If that command
            // vanished (crash between save points, GC) or hangs, nothing settles it — after the
            // stale window it is an undead step in every pending-list computation. Neither the
            // blocked-TTL sweep (blocked_confirmation only) nor the running reaper (running only)
            // covers this state, so reap it here. TTL-based: a legitimate in-flight command
            // settles well within the window, so only genuinely stuck placeholders are hit.
            if (queue_status_policy::is_realizing_status($status)) {
                if (!$tooold) {
                    continue;
                }
                $item['status'] = queue_status_policy::failed_status();
                $item['issue_codes'] = ['REALIZING_ORPHAN_REAPED'];
                $item['error_class'] = 'stale_realizing';
                $item['last_error_message'] = 'realizing placeholder orphaned beyond the stale threshold.';
                $item['updated_at'] = $now;
                $changed++;
            }
        }
        unset($item);

        if ($changed > 0) {
            // A reaped running corpse never finished its step: the bound placeholder fails with it
            // (F5). Realizing placeholders are themselves the step and were failed directly above.
            foreach ($reapedids as $reapedid) {
                $this->settle_bound_placeholder($items, $reapedid, queue_status_policy::failed_status(), '');
            }
            $this->save_queue_items($threadid, $items);
        }
        return $changed;
    }

    /**
     * Enqueue a planned placeholder for a future multi-step skill.
     *
     * Placeholders carry an intent string only — no real skill name or parameters. A planned
     * placeholder is BOUND to the real command that realizes its step
     * ({@see self::bind_next_placeholder()}) and settles with that command's terminal state —
     * it never claims success at staging time (F5, threads 544/589).
     *
     * @param int $threadid
     * @param int $runid
     * @param int $stepid
     * @param string $intent Human-readable description of the future step.
     * @return array The created queue item.
     */
    public function enqueue_placeholder(int $threadid, int $runid, int $stepid, string $intent): array {
        $items = $this->get_queue_items($threadid);
        $contextid = $this->resolve_thread_contextid($threadid);
        $seq = $this->next_sequence($threadid);
        $now = time();

        $item = [
            'queue_item_id' => 'q' . $threadid . '_' . $seq,
            'thread_id' => $threadid,
            'contextid' => $contextid,
            'run_id' => $runid,
            'step_id' => $stepid,
            'skill' => '__placeholder__',
            'version' => 1,
            'input' => ['intent' => trim($intent)],
            'prepared_input' => null,
            'guard_token' => '',
            'input_signature' => '',
            'input_signature_mode' => 'none',
            'input_signature_payload' => [],
            'mutability' => 'mutating',
            'risk_class' => '',
            'depends_on' => [],
            'status' => queue_status_policy::planned_status(),
            'retry_count' => 0,
            'preflight_retry_count' => 0,
            'next_retry_at' => null,
            'retry_after_ms' => 0,
            'backoff_ms' => 0,
            'blocked_expires_at' => null,
            'issue_codes' => [],
            'error_class' => '',
            'last_error_message' => '',
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $items[] = $item;
        $this->save_queue_items($threadid, $items);
        return $item;
    }

    /**
     * Whether any planned placeholder items exist in the queue.
     *
     * Used by confirm_run_service to trigger CONF_FOLLOW even when the
     * executable queue is empty.
     *
     * @param int $threadid
     * @return bool
     */
    public function has_planned_placeholders(int $threadid): bool {
        foreach ($this->get_queue_items($threadid) as $item) {
            if (queue_status_policy::is_planned_status((string)($item['status'] ?? ''))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Bind the oldest planned placeholder to the real command that starts realizing it.
     *
     * Replaces the old consume-at-enqueue semantics (the placeholder lie of threads 544/589:
     * the placeholder was marked succeeded the moment the real command was STAGED, so a step
     * whose command then failed preflight vanished from the plan without ever executing, and
     * the queue claimed success at zero runs). Binding moves the placeholder to the realizing
     * state instead; it settles together with the real item in {@see self::update_status()}:
     * succeeded on success, failed on hard failure, back to planned when the command is blocked
     * by a preflight clarification ({@see self::ensure_blocked_step_representation()}).
     *
     * @param int $threadid
     * @param string $realqueueitemid Queue item id of the staged real command.
     * @return string|null The bound placeholder's queue item id, or null when none is planned.
     */
    public function bind_next_placeholder(int $threadid, string $realqueueitemid): ?string {
        $realqueueitemid = trim($realqueueitemid);
        if ($realqueueitemid === '') {
            return null;
        }

        $items = $this->get_queue_items($threadid);
        $realindex = null;
        foreach ($items as $index => $item) {
            if ((string)($item['queue_item_id'] ?? '') === $realqueueitemid) {
                $realindex = $index;
                break;
            }
        }
        if ($realindex === null) {
            return null;
        }

        $placeholderid = null;
        foreach ($items as $index => $item) {
            if (!queue_status_policy::is_planned_status((string)($item['status'] ?? ''))) {
                continue;
            }
            $placeholderid = (string)($item['queue_item_id'] ?? '');
            $items[$index]['status'] = queue_status_policy::realizing_status();
            $items[$index]['realized_by'] = $realqueueitemid;
            $items[$index]['updated_at'] = time();
            break;
        }
        if ($placeholderid === null) {
            return null;
        }

        $items[$realindex]['realizes_placeholder'] = $placeholderid;
        $this->save_queue_items($threadid, $items);
        return $placeholderid;
    }

    /**
     * Keep a clarification-blocked step represented in the pending plan (F5, thread 589).
     *
     * A mutating command that failed preflight on a needs_clarification issue is not done: the
     * user is being asked for the missing input and the step is still owed. Without
     * representation the next selector turn works through the WRONG list (thread 589: the
     * category question orphaned create_course; the selector prompt then directed the model to
     * the scaffold step and the course was never created). For each such item:
     *  - its bound placeholder (realizing) reverts to planned — the step reappears FIRST in the
     *    pending list because that placeholder is the oldest;
     *  - an unbound item (the first multi-step turn's current command never had a placeholder)
     *    gets a planned placeholder PLANTED at the front of the queue, but only while a plan is
     *    in flight (other placeholders planned/realizing). Single-command threads keep the
     *    plain clarification flow without a pending-step entry.
     *
     * @param int $threadid
     * @param string[] $queueitemids Queue item ids of the just-preflighted batch.
     * @return void
     */
    public function ensure_blocked_step_representation(int $threadid, array $queueitemids): void {
        $queueitemids = array_values(array_unique(array_filter(array_map(
            static fn($id): string => trim((string)$id),
            $queueitemids
        ))));
        if (empty($queueitemids)) {
            return;
        }

        $items = $this->get_queue_items($threadid);
        $changed = false;

        foreach ($queueitemids as $queueitemid) {
            $realindex = null;
            foreach ($items as $index => $item) {
                if ((string)($item['queue_item_id'] ?? '') === $queueitemid) {
                    $realindex = $index;
                    break;
                }
            }
            if ($realindex === null) {
                continue;
            }

            $realitem = $items[$realindex];
            if (
                (string)($realitem['skill'] ?? '') === '__placeholder__'
                || (string)($realitem['mutability'] ?? '') !== 'mutating'
                || !queue_status_policy::is_failed_status((string)($realitem['status'] ?? ''))
                || (string)($realitem['reason_code'] ?? '') !== queue_transition_service::REASON_PREFLIGHT_NEEDS_CLARIFICATION
            ) {
                continue;
            }

            $link = trim((string)($realitem['realizes_placeholder'] ?? ''));
            if ($link !== '') {
                // Bound step: revert the realizing placeholder to planned. The stale
                // realized_by marker stays informational; the next re-derive re-binds it.
                foreach ($items as $index => $item) {
                    if ((string)($item['queue_item_id'] ?? '') !== $link) {
                        continue;
                    }
                    if (queue_status_policy::is_realizing_status((string)($item['status'] ?? ''))) {
                        $items[$index]['status'] = queue_status_policy::planned_status();
                        $items[$index]['updated_at'] = time();
                        $changed = true;
                    }
                    break;
                }
                continue;
            }

            // Unbound step (first-turn current command): plant a placeholder at the front,
            // but only while a plan is in flight.
            $planinflight = false;
            foreach ($items as $item) {
                $status = (string)($item['status'] ?? '');
                if (
                    (string)($item['skill'] ?? '') === '__placeholder__'
                    && (queue_status_policy::is_planned_status($status)
                        || queue_status_policy::is_realizing_status($status))
                ) {
                    $planinflight = true;
                    break;
                }
            }
            if (!$planinflight) {
                continue;
            }

            $seq = $this->next_sequence($threadid);
            $now = time();
            $placeholderid = 'q' . $threadid . '_' . $seq;
            $placeholder = [
                'queue_item_id' => $placeholderid,
                'thread_id' => $threadid,
                'contextid' => (int)($realitem['contextid'] ?? 0),
                'run_id' => (int)($realitem['run_id'] ?? 0),
                'step_id' => (int)($realitem['step_id'] ?? 0),
                'skill' => '__placeholder__',
                'version' => 1,
                'input' => ['intent' => $this->compose_step_intent($realitem)],
                'prepared_input' => null,
                'guard_token' => '',
                'input_signature' => '',
                'input_signature_mode' => 'none',
                'input_signature_payload' => [],
                'mutability' => 'mutating',
                'risk_class' => '',
                'depends_on' => [],
                'status' => queue_status_policy::planned_status(),
                'realized_by' => $queueitemid,
                'retry_count' => 0,
                'preflight_retry_count' => 0,
                'next_retry_at' => null,
                'retry_after_ms' => 0,
                'backoff_ms' => 0,
                'blocked_expires_at' => null,
                'issue_codes' => [],
                'error_class' => '',
                'last_error_message' => '',
                'created_at' => $now,
                'updated_at' => $now,
            ];
            array_unshift($items, $placeholder);
            // Re-locate the real item (unshift moved every index by one) and link it, so a
            // second pass over the same batch cannot plant a duplicate.
            foreach ($items as $index => $item) {
                if ((string)($item['queue_item_id'] ?? '') === $queueitemid) {
                    $items[$index]['realizes_placeholder'] = $placeholderid;
                    break;
                }
            }
            $changed = true;
        }

        if ($changed) {
            $this->save_queue_items($threadid, $items);
        }
    }

    /**
     * Compose a deterministic pending-step intent from a real queue item.
     *
     * Planner-facing only (pending-step lists in the selector prompt and the synchronizer's
     * unexecuted-steps block): skill name plus up to two scalar input fields, so the step stays
     * identifiable after its command failed preflight. Structural — no language processing.
     *
     * @param array $item Real queue item.
     * @return string
     */
    private function compose_step_intent(array $item): string {
        $skill = trim((string)($item['skill'] ?? ''));
        $parts = [];
        foreach ((array)($item['input'] ?? []) as $key => $value) {
            // Outputlang is a framework transport field, never step-identifying.
            if ($key === 'outputlang' || !is_scalar($value)) {
                continue;
            }
            $text = trim((string)$value);
            if ($text === '') {
                continue;
            }
            if (\core_text::strlen($text) > 60) {
                $text = \core_text::substr($text, 0, 57) . '...';
            }
            $parts[] = $key . ': ' . $text;
            if (count($parts) >= 2) {
                break;
            }
        }

        return $skill . (empty($parts) ? '' : ' (' . implode(', ', $parts) . ')');
    }

    /**
     * Settle the placeholder bound to a real item when that item reaches a terminal state.
     *
     * succeeded => the placeholder becomes succeeded (the ONLY path that may claim success);
     * failed => the placeholder becomes failed, EXCEPT a preflight needs_clarification block,
     * which is handled by {@see self::ensure_blocked_step_representation()} (revert to planned)
     * right after the transition. Other statuses leave the placeholder untouched.
     *
     * @param array $items Queue items (modified in place).
     * @param string $realqueueitemid The transitioned real item's queue item id.
     * @param string $newstatus The status the real item just transitioned to.
     * @param string $reasoncode Transition reason code (from update_status extra fields).
     * @return void
     */
    private function settle_bound_placeholder(
        array &$items,
        string $realqueueitemid,
        string $newstatus,
        string $reasoncode
    ): void {
        $link = '';
        foreach ($items as $item) {
            if ((string)($item['queue_item_id'] ?? '') !== $realqueueitemid) {
                continue;
            }
            if ((string)($item['skill'] ?? '') === '__placeholder__') {
                return;
            }
            $link = trim((string)($item['realizes_placeholder'] ?? ''));
            break;
        }
        if ($link === '') {
            return;
        }

        if (queue_status_policy::is_succeeded_status($newstatus)) {
            $target = queue_status_policy::succeeded_status();
        } else if (queue_status_policy::is_failed_status($newstatus)) {
            if ($reasoncode === queue_transition_service::REASON_PREFLIGHT_NEEDS_CLARIFICATION) {
                return;
            }
            $target = queue_status_policy::failed_status();
        } else {
            return;
        }

        foreach ($items as $index => $item) {
            if ((string)($item['queue_item_id'] ?? '') !== $link) {
                continue;
            }
            if (queue_status_policy::is_realizing_status((string)($item['status'] ?? ''))) {
                $items[$index]['status'] = $target;
                $items[$index]['updated_at'] = time();
            }
            break;
        }
    }

    /**
     * Return intent strings of all remaining planned placeholders.
     *
     * Used to inject pending steps into the selector prompt context.
     *
     * @param int $threadid
     * @return string[]
     */
    public function get_planned_placeholder_intents(int $threadid): array {
        $intents = [];
        foreach ($this->get_queue_items($threadid) as $item) {
            if (queue_status_policy::is_planned_status((string)($item['status'] ?? ''))) {
                $intent = trim((string)($item['input']['intent'] ?? ''));
                if ($intent !== '') {
                    $intents[] = $intent;
                }
            }
        }
        return $intents;
    }

    /**
     * Build deterministic input signature plus debug metadata.
     *
     * @param string $skill
     * @param array $input
     * @return array{signature:string,mode:string,payload:array}
     */
    private function build_input_signature_details(string $skill, array $input): array {
        $signaturepayload = $input;
        $mode = 'raw_input';

        if ($this->registry !== null) {
            $skillinstance = $this->registry->get_skill($skill);
            if ($skillinstance instanceof queue_identity_provider_interface) {
                try {
                    $businessidentity = $skillinstance->build_queue_business_identity($input);
                    if (!empty($businessidentity)) {
                        $mode = 'skill_business';
                        $signaturepayload = [
                            '__identity_mode' => 'skill_business',
                            'identity' => $businessidentity,
                        ];
                    }
                } catch (\Throwable $e) {
                    // Fallback to raw input signature if skill-provided identity fails.
                    $mode = 'raw_input';
                    $signaturepayload = $input;
                }
            }
        }

        $normalized = $this->normalize_for_signature($signaturepayload);
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return [
            'signature' => hash('sha256', $skill . ':' . (string)$json),
            'mode' => $mode,
            'payload' => is_array($normalized) ? $normalized : ['value' => $normalized],
        ];
    }

    /**
     * Normalize input recursively for stable signature hashing.
     *
     * @param mixed $value
     * @return mixed
     */
    private function normalize_for_signature($value) {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(fn($entry) => $this->normalize_for_signature($entry), $value);
            }
            ksort($value);
            foreach ($value as $key => $entry) {
                $value[$key] = $this->normalize_for_signature($entry);
            }
            return $value;
        }
        if (is_string($value)) {
            return trim($value);
        }
        return $value;
    }

    /**
     * Increment and return per-thread queue sequence.
     *
     * @param int $threadid
     * @return int
     */
    private function next_sequence(int $threadid): int {
        $raw = $this->store->get_thread_metadata_value($threadid, self::META_QUEUE_SEQ);
        $seq = max(0, (int)$raw) + 1;
        $this->store->set_thread_metadata_value($threadid, self::META_QUEUE_SEQ, $seq);
        return $seq;
    }

    /**
     * Resolve thread context id for queue metadata anchoring.
     *
     * @param int $threadid
     * @return int
     */
    private function resolve_thread_contextid(int $threadid): int {
        if ($threadid <= 0) {
            return 0;
        }

        $thread = $this->store->get_thread($threadid);
        return $thread ? max(0, (int)($thread->contextid ?? 0)) : 0;
    }

    /**
     * Resolve blocked_confirmation expiry timestamp by config.
     *
     * @param string $status
     * @param int $now
     * @param string $riskclass
     * @return int|null
     */
    private function resolve_blocked_expires_at(string $status, int $now, string $riskclass = ''): ?int {
        if (!queue_status_policy::is_blocked_confirmation_status($status)) {
            return null;
        }
        $ttl = $this->resolve_blocked_ttl_seconds($riskclass);
        if ($ttl <= 0) {
            return null;
        }
        $ttl = max(1, $ttl);
        return $now + $ttl;
    }

    /**
     * Resolve blocked_confirmation TTL in seconds.
     *
     * @param string $riskclass
     * @return int
     */
    private function resolve_blocked_ttl_seconds(string $riskclass): int {
        $riskclass = risk_class_resolver::normalize($riskclass);
        if ($riskclass === skill_risk_class::R2) {
            return 300;
        }

        if (in_array($riskclass, [skill_risk_class::R1, skill_risk_class::R3], true)) {
            return self::DEFAULT_BLOCKED_TTL_SECONDS;
        }

        $configuredttl = (int)get_config('bookingextension_agent', 'queue_blocked_ttl_seconds');
        return $configuredttl > 0 ? $configuredttl : self::DEFAULT_BLOCKED_TTL_SECONDS;
    }

    /**
     * DFS helper for cycle detection.
     *
     * @param string $node
     * @param array $graph
     * @param array $state
     * @return bool
     */
    private function dfs_cycle_detect(string $node, array $graph, array &$state): bool {
        $mark = (int)($state[$node] ?? 0);
        if ($mark === 1) {
            return true;
        }
        if ($mark === 2) {
            return false;
        }

        $state[$node] = 1;
        foreach ((array)($graph[$node] ?? []) as $dep) {
            $dep = trim((string)$dep);
            if ($dep === '' || !array_key_exists($dep, $graph)) {
                continue;
            }
            if ($this->dfs_cycle_detect($dep, $graph, $state)) {
                return true;
            }
        }
        $state[$node] = 2;
        return false;
    }
}
