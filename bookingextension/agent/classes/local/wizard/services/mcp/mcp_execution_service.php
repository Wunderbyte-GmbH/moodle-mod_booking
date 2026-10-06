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
 * MCP headless skill execution service.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\mcp;

use bookingextension_agent\local\wizard\services\telemetry\audit_logger;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\executor;
use bookingextension_agent\local\wizard\queue\queue_manager;
use bookingextension_agent\local\wizard\services\pending_intent_service;
use bookingextension_agent\local\wizard\services\preflight_pipeline;
use bookingextension_agent\local\wizard\services\queue_transition_service;
use bookingextension_agent\local\wizard\services\security\authorization_service;
use bookingextension_agent\local\wizard\skill_executability_evaluator;
use bookingextension_agent\local\wizard\skill_registry;
use core\context;
use core_text;

/**
 * Executes agent skills for an external MCP client (Claude) without the LLM engine.
 *
 * The MCP client is its own planner: it supplies {tool, args} directly, so this
 * service drives only the deterministic tail of the engine — structural check,
 * preflight pipeline, run bookkeeping and the executor. Every security gate the
 * chat path has (governance evaluator, licence gate, native capability guard,
 * guard tokens for mutations) is enforced inside that shared tail; this service
 * adds no privileged shortcut.
 *
 * All runs live on a dedicated per-user 'mcp' channel thread so the chat thread
 * (whose metadata carries the chat queue) is never touched.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mcp_execution_service {
    /** @var string Channel name for MCP-owned threads. */
    public const CHANNEL = 'mcp';

    /**
     * Result keys never echoed into structuredContent (already in content / internal).
     *
     * 'scaffold_zip_base64' is the scaffold skill's ZIP payload: it is delivered to the human via
     * the user-facing result preview only (which is itself dropped here), so no agent — chat LLM
     * or MCP client — ever sees the base64 blob. The chat path is already safe because
     * result_payload_summarizer only feeds known text channels (observation_full / usermessage /
     * detail) to the LLM; this entry closes the MCP structuredContent channel the same way.
     *
     * @var string[]
     */
    private const STRUCTURED_CONTENT_DROPPED_KEYS = ['usermessage', 'preview', 'scaffold_zip_base64'];

    /** @var int Maximum observation_full characters shipped over MCP (text and structuredContent). */
    public const MCP_OBSERVATION_FULL_MAX = 16000;

    /** @var skill_registry */
    private skill_registry $registry;

    /** @var conversation_store */
    private conversation_store $store;

    /** @var authorization_service */
    private authorization_service $authz;

    /** @var skill_executability_evaluator */
    private skill_executability_evaluator $evaluator;

    /** @var mcp_tool_catalog_service */
    private mcp_tool_catalog_service $catalog;

    /**
     * Constructor.
     *
     * @param skill_registry $registry
     * @param conversation_store $store
     * @param authorization_service $authz
     */
    public function __construct(skill_registry $registry, conversation_store $store, authorization_service $authz) {
        $this->registry = $registry;
        $this->store = $store;
        $this->authz = $authz;
        $this->evaluator = new skill_executability_evaluator($registry, $authz);
        $this->catalog = new mcp_tool_catalog_service($registry, $this->evaluator);
    }

    /**
     * List the MCP tool definitions available to this user in this context.
     *
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function list_tools(int $contextid, int $userid): array {
        if (!$this->has_mcp_access($contextid, $userid)) {
            audit_logger::action_denied('*', 'mcp_access', 'MCP_ACCESS_DENIED', $contextid, $userid, 0, 0, 'mcp');
            return [];
        }
        return $this->catalog->get_tools($userid, $contextid);
    }

    /**
     * Whether the user holds the MCP entry capability at this context.
     *
     * Enforced centrally here so every transport is gated the same way: the REST shims
     * additionally require it (defense in depth), but the tool_oauthmcp hook path reaches
     * this service directly and would otherwise bypass mcpaccess. Fail-closed on an
     * unresolvable context.
     *
     * @param int $contextid
     * @param int $userid
     * @return bool
     */
    private function has_mcp_access(int $contextid, int $userid): bool {
        $context = context::instance_by_id($contextid, IGNORE_MISSING);
        if (!$context) {
            return false;
        }
        return has_capability('bookingextension/agent:mcpaccess', $context, $userid);
    }

    /**
     * Emit an action_denied audit event for an MCP entry gate and return the MCP error result.
     *
     * The MCP entrypoint has gates of its own (mcpaccess, mutations-disabled, rate limit) that
     * sit before the executor, so their refusals would otherwise be invisible; this records them
     * on the same audit trail as executor-level denials.
     *
     * @param string $skillname requested tool/skill, or '*' when not yet resolved
     * @param string $gate       gate identifier (mcp_access | mcp_mutations_disabled | rate_limit)
     * @param string $reason     machine reason code
     * @param string $message    user-facing message for the transport
     * @param array  $issuecodes issue codes for the transport
     * @param int    $contextid
     * @param int    $userid
     * @return array
     */
    private function denied(
        string $skillname,
        string $gate,
        string $reason,
        string $message,
        array $issuecodes,
        int $contextid,
        int $userid
    ): array {
        audit_logger::action_denied($skillname, $gate, $reason, $contextid, $userid, 0, 0, 'mcp');
        return $this->error_result($message, $issuecodes);
    }

    /**
     * Resolve the channel-thread key for an MCP session.
     *
     * Each MCP session (Mcp-Session-Id over HTTP, or a per-process id from the stdio bridge) gets
     * its own channel thread, so two concurrent clients on the same token never share a pending
     * confirmation slot — the MCP analogue of a chat page reload starting a fresh thread. With no
     * session id we fall back to the single shared 'mcp' thread (backward compatible). The key must
     * fit the threads.status char(20) column, so the session id is hashed to a fixed 16-char token.
     *
     * @param string $sessionid
     * @return string
     */
    private function channel_for_session(string $sessionid): string {
        $sessionid = trim($sessionid);
        if ($sessionid === '') {
            return self::CHANNEL;
        }
        return self::CHANNEL . ':' . substr(hash('sha256', $sessionid), 0, 16);
    }

    /**
     * Execute one MCP tool call and return an MCP-shaped result.
     *
     * The returned array uses the MCP tool-result field names verbatim
     * (content / structuredContent / isError) so transports can pass it through.
     *
     * @param string $toolname MCP tool name (or canonical skill name).
     * @param array $args Tool arguments as decoded by the transport.
     * @param int $contextid Ambient context id.
     * @param int $userid Acting user id.
     * @param string $idempotencykey Client-supplied per-request key; retries reuse it.
     * @param string $sessionid MCP session id; scopes the confirm thread (empty = default session).
     * @return array
     */
    public function call_tool(
        string $toolname,
        array $args,
        int $contextid,
        int $userid,
        string $idempotencykey,
        string $sessionid = ''
    ): array {
        if (!$this->has_mcp_access($contextid, $userid)) {
            return $this->denied(
                trim($toolname) !== '' ? trim($toolname) : '*',
                'mcp_access',
                'MCP_ACCESS_DENIED',
                get_string('mcp_error_access_denied', 'bookingextension_agent'),
                ['MCP_ACCESS_DENIED'],
                $contextid,
                $userid
            );
        }
        if ($this->rate_limit_exceeded($userid)) {
            return $this->denied(
                trim($toolname) !== '' ? trim($toolname) : '*',
                'rate_limit',
                'MCP_RATE_LIMITED',
                get_string('mcp_error_rate_limited', 'bookingextension_agent'),
                ['MCP_RATE_LIMITED'],
                $contextid,
                $userid
            );
        }

        if (trim($toolname) === mcp_tool_catalog_service::CONFIRM_TOOL_NAME) {
            // Step 2 of the mutation flow, routed through the generic tool call so every
            // transport can confirm without a dedicated code path. Rate limit already counted.
            return $this->confirm_tool(
                (string)($args['queueitemid'] ?? ''),
                (string)($args['confirmationcode'] ?? ''),
                $contextid,
                $userid,
                false,
                $sessionid
            );
        }

        // Facade-level flag consumed by the pending-collision gate in call_mutating_tool().
        // Stripped before structural validation and preflight so skills never see it.
        $replacepending = !empty($args['replace_pending']);
        unset($args['replace_pending']);

        $skillname = $this->catalog->skill_for_tool_name($toolname);
        if ($skillname === null) {
            return $this->error_result(
                get_string('mcp_error_unknown_tool', 'bookingextension_agent', clean_param($toolname, PARAM_ALPHANUMEXT)),
                ['MCP_UNKNOWN_TOOL']
            );
        }

        $evaluation = $this->evaluator->evaluate_skill($skillname, $userid, $contextid);
        if ((string)($evaluation['executable_state'] ?? '') !== 'allow') {
            $denyreason = trim((string)($evaluation['deny_reason'] ?? ''));
            return $this->error_result(
                get_string('mcp_error_skill_denied', 'bookingextension_agent', $denyreason),
                ['MCP_SKILL_DENIED', $denyreason]
            );
        }

        $skill = $this->registry->get_skill($skillname);
        $structural = $skill->check_structure($args);
        if (!($structural['valid'] ?? true)) {
            return $this->error_result(
                get_string(
                    'mcp_error_invalid_input',
                    'bookingextension_agent',
                    implode('; ', (array)($structural['errors'] ?? []))
                ),
                ['MCP_INVALID_INPUT']
            );
        }

        if (!$skill->is_read_only()) {
            return $this->call_mutating_tool(
                $skill,
                $skillname,
                $args,
                $contextid,
                $userid,
                $idempotencykey,
                $sessionid,
                $replacepending
            );
        }

        return $this->execute_now($skillname, $args, $contextid, $userid, $idempotencykey, $sessionid);
    }

    /**
     * Two-call confirm flow for mutating skills, step 1: preview + pending intent.
     *
     * Mirrors the chat path mechanics: preflight resolves the prepared input, the
     * command is parked as a queue item (set_prepared_input binds the guard token
     * to skill + operating context + input), and a pending intent with a
     * confirmation code is stored on the MCP thread. Nothing is executed here —
     * the client must show the preview to the human and then call confirm_tool()
     * with the code, which proves the confirming call has seen this response.
     *
     * A thread holds exactly one pending confirmation: a second preview would silently
     * overwrite the first, whose confirm then fails with MCP_CONFIRMATION_MISMATCH. A
     * fresh mutating call while a non-expired pending action exists is therefore refused
     * with MCP_PENDING_ACTION_EXISTS unless the caller opted into replacement via the
     * facade-level replace_pending flag (stripped in call_tool(); skills never see it).
     *
     * @param object $skill
     * @param string $skillname
     * @param array $args
     * @param int $contextid
     * @param int $userid
     * @param string $idempotencykey
     * @param string $sessionid
     * @param bool $replacepending Caller explicitly allows replacing a pending action.
     * @return array
     */
    private function call_mutating_tool(
        object $skill,
        string $skillname,
        array $args,
        int $contextid,
        int $userid,
        string $idempotencykey,
        string $sessionid = '',
        bool $replacepending = false
    ): array {
        if (!get_config('bookingextension_agent', 'mcpallowmutations')) {
            return $this->denied(
                $skillname,
                'mcp_mutations_disabled',
                'MCP_MUTATIONS_NOT_AVAILABLE',
                get_string('mcp_error_mutations_not_available', 'bookingextension_agent'),
                ['MCP_MUTATIONS_NOT_AVAILABLE'],
                $contextid,
                $userid
            );
        }

        $thread = $this->store->get_or_create_channel_thread($userid, $contextid, $this->channel_for_session($sessionid));
        $threadid = (int)$thread->id;

        // Intra-session collision gate: get() treats an expired intent as absent (the store
        // clears it), so only a live pending action can block or be replaced here.
        $intentsvc = new pending_intent_service($this->store);
        $existing = $intentsvc->get($threadid);
        $replacedpending = '';
        if ($existing !== null) {
            $existingitems = array_values(array_filter(array_map('strval', (array)($existing['queue_item_ids'] ?? []))));
            $existingitemid = (string)($existingitems[0] ?? '');
            if (!$replacepending) {
                $extra = ['queueitemid' => $existingitemid];
                $existingtitle = trim((string)($existing['title'] ?? ''));
                if ($existingtitle !== '') {
                    $extra['title'] = $existingtitle;
                }
                return $this->error_result(
                    get_string('mcp_error_pending_action_exists', 'bookingextension_agent'),
                    ['MCP_PENDING_ACTION_EXISTS'],
                    $extra
                );
            }
            $replacedpending = $existingitemid;
        }

        $schema = (array)$skill->get_schema();
        $command = [
            'skill' => $skillname,
            'version' => (int)($schema['version'] ?? 1),
            'input' => $args,
            'risk_class' => (string)$skill->get_risk_class(),
        ];

        $pipeline = new preflight_pipeline($this->registry, $this->store);
        $preflight = $pipeline->run([$command], $threadid, $contextid, $userid);
        $preparedcommands = (array)($preflight['prepared_commands'] ?? []);
        $confirmreasons = [];
        if ((string)($preflight['status'] ?? '') !== 'pass' || empty($preparedcommands)) {
            // The confirmable channel of #2239 (#2336): when EVERY blocking issue merely asks for
            // confirmation AND the prepared command survived, stage it like any mutation and
            // let the human decide - only hard blocks stay terminal errors.
            $issues = (array)($preflight['issues'] ?? []);
            $confirmable = !empty($preparedcommands) && !empty($issues);
            foreach ($issues as $issue) {
                if ((string)($issue['severity'] ?? '') !== 'needs_confirmation') {
                    $confirmable = false;
                    break;
                }
            }
            if (!$confirmable) {
                return $this->error_result(
                    get_string(
                        'mcp_error_preflight_blocked',
                        'bookingextension_agent',
                        implode(' ', (array)($preflight['errors'] ?? []))
                    ),
                    array_merge(['MCP_PREFLIGHT_BLOCKED'], (array)($preflight['issue_codes'] ?? []))
                );
            }
            foreach ($issues as $issue) {
                foreach (['message', 'user_question'] as $key) {
                    $line = trim((string)($issue[$key] ?? ''));
                    if ($line !== '') {
                        $confirmreasons[] = $line;
                    }
                }
            }
        }
        $prepared = (array)$preparedcommands[0];
        $preparedinput = (array)($prepared['input'] ?? []);
        $operatingcontextid = (int)($prepared['operating_contextid'] ?? $contextid);

        $queuesvc = new queue_manager($this->store);
        $queued = $queuesvc->enqueue_command($threadid, 0, 0, $command, 'mutating', 'queued');
        $queueitemid = (string)($queued['queue_item_id'] ?? '');
        if ($queueitemid === '') {
            return $this->error_result(
                get_string('mcp_error_preflight_blocked', 'bookingextension_agent', 'queueing failed'),
                ['MCP_PREFLIGHT_BLOCKED']
            );
        }
        $queuesvc->set_prepared_input($threadid, $queueitemid, $contextid, $preparedinput, $operatingcontextid);

        $preview = $skill->describe_proposed_action($preparedinput);

        $confirmationcode = $intentsvc->set($threadid, $userid, $contextid, [
            'queue_item_ids' => [$queueitemid],
            // Kept so a later colliding call can report WHAT is pending without re-deriving it.
            'title' => is_array($preview) ? trim((string)($preview['title'] ?? '')) : '',
        ]);
        // The store owns the TTL; read the actual expiry back instead of duplicating the constant.
        $intent = (array)($intentsvc->get($threadid) ?? []);
        $expiresin = max(0, (int)($intent['expiresat'] ?? 0) - time());

        $lines = [get_string('mcp_pending_confirmation', 'bookingextension_agent')];
        foreach ($confirmreasons as $reason) {
            $lines[] = $reason;
        }
        if (is_array($preview)) {
            foreach ([trim((string)($preview['title'] ?? '')), trim((string)($preview['summary'] ?? ''))] as $line) {
                if ($line !== '') {
                    $lines[] = $line;
                }
            }
            foreach ((array)($preview['rows'] ?? []) as $row) {
                $label = trim((string)($row['label'] ?? ''));
                $value = trim((string)($row['value'] ?? ''));
                if ($label !== '' || $value !== '') {
                    $lines[] = '- ' . ($label !== '' ? $label . ': ' : '') . $value;
                }
            }
        }

        // Self-contained text (#2351): clients on pre-2025-06 protocols never see
        // structuredContent — the confirm handle must travel in the text block too.
        $lines[] = 'queueitemid: ' . $queueitemid;
        $lines[] = 'confirmationcode: ' . $confirmationcode;

        $structured = [
            'pending' => true,
            'skill' => $skillname,
            'queueitemid' => $queueitemid,
            'confirmationcode' => $confirmationcode,
            'expiresin' => $expiresin,
            'preview' => $preview,
        ];
        if (!empty($confirmreasons)) {
            $structured['confirm_reasons'] = $confirmreasons;
            $structured['issue_codes'] = (array)($preflight['issue_codes'] ?? []);
        }
        if ($replacedpending !== '') {
            $structured['replaced_pending'] = $replacedpending;
        }

        return [
            'content' => [['type' => 'text', 'text' => implode("\n", $lines)]],
            'structuredContent' => $structured,
            'isError' => false,
        ];
    }

    /**
     * Two-call confirm flow, step 2: execute a previously previewed mutation.
     *
     * Stricter than the chat UI: the confirmation code issued with the preview is
     * verified (hash_equals) before the pending intent is consumed — over MCP the
     * code is the proof that the confirming call has seen the preview response.
     * Execution then runs the same executor tail as everywhere else, including
     * guard-token verification against the queue item's prepared input.
     *
     * @param string $queueitemid
     * @param string $confirmationcode
     * @param int $contextid
     * @param int $userid
     * @param bool $checkratelimit False when the caller (call_tool routing) already counted this request.
     * @param string $sessionid MCP session id; scopes the confirm thread (empty = default session).
     * @return array
     */
    public function confirm_tool(
        string $queueitemid,
        string $confirmationcode,
        int $contextid,
        int $userid,
        bool $checkratelimit = true,
        string $sessionid = ''
    ): array {
        if (!$this->has_mcp_access($contextid, $userid)) {
            return $this->denied(
                '*',
                'mcp_access',
                'MCP_ACCESS_DENIED',
                get_string('mcp_error_access_denied', 'bookingextension_agent'),
                ['MCP_ACCESS_DENIED'],
                $contextid,
                $userid
            );
        }
        if ($checkratelimit && $this->rate_limit_exceeded($userid)) {
            return $this->denied(
                '*',
                'rate_limit',
                'MCP_RATE_LIMITED',
                get_string('mcp_error_rate_limited', 'bookingextension_agent'),
                ['MCP_RATE_LIMITED'],
                $contextid,
                $userid
            );
        }
        if (!get_config('bookingextension_agent', 'mcpallowmutations')) {
            return $this->denied(
                '*',
                'mcp_mutations_disabled',
                'MCP_MUTATIONS_NOT_AVAILABLE',
                get_string('mcp_error_mutations_not_available', 'bookingextension_agent'),
                ['MCP_MUTATIONS_NOT_AVAILABLE'],
                $contextid,
                $userid
            );
        }

        $thread = $this->store->get_or_create_channel_thread($userid, $contextid, $this->channel_for_session($sessionid));
        $threadid = (int)$thread->id;

        $intentsvc = new pending_intent_service($this->store);
        $intent = $intentsvc->get($threadid);
        if ($intent === null) {
            return $this->error_result(
                get_string('mcp_error_no_pending_confirmation', 'bookingextension_agent'),
                ['MCP_NO_PENDING_CONFIRMATION']
            );
        }

        $queueitemid = trim($queueitemid);
        $knownitems = array_map('strval', (array)($intent['queue_item_ids'] ?? []));
        if (
            !hash_equals((string)($intent['confirmationcode'] ?? ''), trim($confirmationcode))
            || !in_array($queueitemid, $knownitems, true)
        ) {
            return $this->error_result(
                get_string('mcp_error_confirmation_mismatch', 'bookingextension_agent'),
                ['MCP_CONFIRMATION_MISMATCH']
            );
        }

        if ($intentsvc->consume($threadid, $userid, $contextid) === null) {
            // Expired or owned by someone else — consume() is the authoritative check.
            return $this->error_result(
                get_string('mcp_error_no_pending_confirmation', 'bookingextension_agent'),
                ['MCP_NO_PENDING_CONFIRMATION']
            );
        }

        $queuesvc = new queue_manager($this->store);
        $item = $queuesvc->get_queue_item($threadid, $queueitemid);
        if ($item === null || empty($item['prepared_input']) || empty($item['guard_token'])) {
            return $this->error_result(
                get_string('mcp_error_no_pending_confirmation', 'bookingextension_agent'),
                ['MCP_QUEUE_ITEM_MISSING']
            );
        }

        $transitions = new queue_transition_service();
        $transitions->to_ready($queuesvc, $threadid, $queueitemid, 'CONFIRMATION_ACCEPTED');

        $command = [
            'skill' => (string)$item['skill'],
            'version' => (int)($item['version'] ?? 1),
            'input' => (array)$item['prepared_input'],
            'operating_contextid' => (int)($item['operating_contextid'] ?? $contextid),
            'guard_token' => (string)$item['guard_token'],
        ];

        $idempotencykey = hash('sha256', $userid . ':' . $contextid . ':' . $threadid . ':'
            . $queueitemid . ':' . microtime(true));
        $runid = $this->store->create_run($threadid, $userid, $contextid, $idempotencykey, [$command]);
        $this->store->update_run_status($runid, 'running');
        // ENFORCED slot acquisition (exactly-once, parity with the chat confirm path):
        // reap crash corpses first, then hard-skip when another frame holds the slot —
        // executing on a contested claim is how thread 554 double-created options.
        $queuesvc->fail_stale_running_items($threadid);
        if (!$queuesvc->try_mark_running($threadid, $queueitemid)) {
            $this->store->update_run_status($runid, 'failed');
            return $this->error_result(
                'This action is already being executed. Please wait for it to finish, then check the result.',
                ['MCP_RUNNING_SLOT_OCCUPIED']
            );
        }

        $exec = new executor($this->registry, $this->store, $this->authz);
        $exec->set_channel('mcp');
        $results = $exec->execute_commands([$command], $contextid, $userid, $idempotencykey, $runid);
        $this->store->update_run_status($runid, 'completed', $results);

        $result = is_array($results[0] ?? null) ? (array)$results[0] : [];
        $status = trim((string)($result['status'] ?? ''));
        $issuecodes = array_values(array_filter(array_map('strval', (array)($result['issue_codes'] ?? []))));
        if ($status === 'error' || $status === 'failed') {
            // No retry machinery over MCP: the client simply issues a fresh call.
            $transitions->to_failed(
                $queuesvc,
                $threadid,
                $queueitemid,
                'EXECUTION_DOMAIN_FAILED',
                $issuecodes,
                'domain_error',
                trim((string)($result['detail'] ?? ''))
            );
        } else {
            $transitions->to_succeeded($queuesvc, $threadid, $queueitemid, 'EXECUTION_SUCCEEDED', $issuecodes);
        }

        // The user approved this pending action; the execution itself is recorded by the
        // executor's skill_write_executed event.
        audit_logger::action_confirmed((string)$item['skill'], $contextid, $userid, $threadid, $runid, 'mcp');

        return $this->build_mcp_result($result);
    }

    /**
     * Whether the per-user tool-call rate limit is exhausted for the current minute.
     *
     * @param int $userid
     * @return bool
     */
    private function rate_limit_exceeded(int $userid): bool {
        $configured = get_config('bookingextension_agent', 'mcpratelimit');
        $limit = ($configured === false) ? 30 : (int)$configured;
        if ($limit <= 0) {
            // 0 = unlimited (explicit admin decision).
            return false;
        }

        $cache = \cache::make('bookingextension_agent', 'mcpratelimit');
        $key = 'u' . $userid . '_' . (int)floor(time() / 60);
        $count = (int)$cache->get($key);
        if ($count >= $limit) {
            return true;
        }
        $cache->set($key, $count + 1);
        return false;
    }

    /**
     * Run the deterministic execution tail for a read-only command.
     *
     * @param string $skillname
     * @param array $args
     * @param int $contextid
     * @param int $userid
     * @param string $idempotencykey
     * @param string $sessionid MCP session id; scopes the confirm thread (empty = default session).
     * @return array
     */
    private function execute_now(
        string $skillname,
        array $args,
        int $contextid,
        int $userid,
        string $idempotencykey,
        string $sessionid = ''
    ): array {
        if ($idempotencykey !== '' && $this->store->run_exists($idempotencykey)) {
            // A retry of a request that already ran: acknowledge instead of re-executing.
            // The runs table has a unique index on the key, so this also guards the insert.
            return $this->error_result(
                get_string('mcp_error_duplicate_request', 'bookingextension_agent'),
                ['MCP_DUPLICATE_REQUEST']
            );
        }
        if ($idempotencykey === '') {
            $idempotencykey = bin2hex(random_bytes(32));
        }

        $thread = $this->store->get_or_create_channel_thread($userid, $contextid, $this->channel_for_session($sessionid));
        $threadid = (int)$thread->id;

        $schema = (array)$this->registry->get_skill($skillname)->get_schema();
        $skillversion = (int)($schema['version'] ?? 1);
        $pipeline = new preflight_pipeline($this->registry, $this->store);
        $preflight = $pipeline->run(
            [['skill' => $skillname, 'version' => $skillversion, 'input' => $args]],
            $threadid,
            $contextid,
            $userid
        );
        $preparedcommands = (array)($preflight['prepared_commands'] ?? []);
        if ((string)($preflight['status'] ?? '') !== 'pass' || empty($preparedcommands)) {
            return $this->error_result(
                get_string(
                    'mcp_error_preflight_blocked',
                    'bookingextension_agent',
                    implode(' ', (array)($preflight['errors'] ?? []))
                ),
                array_merge(['MCP_PREFLIGHT_BLOCKED'], (array)($preflight['issue_codes'] ?? []))
            );
        }

        $runid = $this->store->create_run($threadid, $userid, $contextid, $idempotencykey, $preparedcommands);
        $this->store->update_run_status($runid, 'running');

        $exec = new executor($this->registry, $this->store, $this->authz);
        $exec->set_channel('mcp');
        $results = $exec->execute_commands($preparedcommands, $contextid, $userid, $idempotencykey, $runid);
        $this->store->update_run_status($runid, 'completed', $results);

        $result = is_array($results[0] ?? null) ? (array)$results[0] : [];

        return $this->build_mcp_result($result);
    }

    /**
     * Map one executor result entry to the MCP tool-result shape.
     *
     * The human/model-facing text goes into content; the structured payload
     * (minus the text channels) into structuredContent. observation_full is the
     * skill's rich verbatim result channel: it ships over MCP (capped) both in
     * the text — appended to the usermessage when both exist — and in
     * structuredContent, instead of being lost whenever a one-line usermessage
     * is present.
     *
     * @param array $result
     * @return array
     */
    private function build_mcp_result(array $result): array {
        $status = trim((string)($result['status'] ?? ''));

        $observation = trim((string)($result['observation_full'] ?? ''));
        if ($observation !== '') {
            $observation = $this->cap_observation_full($observation);
            $result['observation_full'] = $observation;
        }

        $text = trim((string)($result['usermessage'] ?? ''));
        if ($text !== '' && $observation !== '') {
            $text .= "\n\n" . $observation;
        } else if ($text === '') {
            $text = ($observation !== '') ? $observation : trim((string)($result['detail'] ?? ''));
        }

        $structured = array_diff_key($result, array_fill_keys(self::STRUCTURED_CONTENT_DROPPED_KEYS, true));

        return [
            'content' => [['type' => 'text', 'text' => $text]],
            'structuredContent' => $structured,
            'isError' => !in_array($status, ['executed', 'skipped'], true),
        ];
    }

    /**
     * Cap observation_full for the MCP transport, marking any truncation explicitly.
     *
     * @param string $observation
     * @return string
     */
    private function cap_observation_full(string $observation): string {
        $total = core_text::strlen($observation);
        if ($total <= self::MCP_OBSERVATION_FULL_MAX) {
            return $observation;
        }
        return rtrim(core_text::substr($observation, 0, self::MCP_OBSERVATION_FULL_MAX))
            . " …[truncated, {$total} chars total]";
    }

    /**
     * Build an MCP error tool-result.
     *
     * @param string $message
     * @param string[] $issuecodes
     * @param array $extra Additional structuredContent fields.
     * @return array
     */
    private function error_result(string $message, array $issuecodes, array $extra = []): array {
        return [
            'content' => [['type' => 'text', 'text' => $message]],
            'structuredContent' => array_merge([
                'issue_codes' => array_values(array_unique(array_filter(array_map('strval', $issuecodes)))),
            ], $extra),
            'isError' => true,
        ];
    }
}
