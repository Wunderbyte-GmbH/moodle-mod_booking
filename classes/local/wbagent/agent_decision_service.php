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
 * Agent decision/routing layer.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_booking\local\wbagent;

use core_text;

/**
 * Routing and decision layer for the agent runtime.
 *
 * Owns ALL routing logic previously embedded in AgentRuntime::decide():
 *  - Preview shortcuts
 *  - Confirmation flow (confirm_pending state machine)
 *  - Duplicate-title overrides
 *  - Lookup-safety mutation guard
 *  - Mutating command promotion from task_call → confirmation_request
 *  - Read-only command auto-execution
 *  - Pre-validation of confirmation commands (with deanonymization)
 *  - Teacher autocreate augmentation
 *  - Pending intent storage and clearing
 *
 * AgentRuntime delegates entirely to this class so it remains a thin
 * coordinator that owns only the loop, state, and persistence.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class agent_decision_service {

    /** Issue codes indicating a duplicate-title confirmation context. */
    public const DUPLICATE_TITLE_ISSUE_CODES = [
        'DUPLICATE_TITLE_CONFIRM_REQUIRED',
        'DUPLICATE_TITLE_MULTI_CONFIRM_REQUIRED',
    ];

    /** Issue codes that may remain confirmation-gated despite pre-validation errors. */
    public const PREVALIDATION_CONFIRMABLE_ISSUE_CODES = [
        'DUPLICATE_TITLE_CONFIRM_REQUIRED',
        'DUPLICATE_TITLE_MULTI_CONFIRM_REQUIRED',
        'CONFIRMATION_REQUIRED',
        'MISSING_LOCATION_CONFIRM_REQUIRED',
        'LOCATION_NOT_FOUND_POSSIBLE',
        'SLOTBOOKING_DURATION_EQUALS_WINDOW',
        'TEACHER_USER_NOT_FOUND',
    ];

    /** @var task_registry */
    private task_registry $registry;

    /** @var conversation_store */
    private conversation_store $store;

    /** @var authorization_service */
    private authorization_service $authz;

    /**
     * Constructor.
     *
     * @param task_registry         $registry
     * @param conversation_store    $store
     * @param authorization_service $authz
     */
    public function __construct(
        task_registry $registry,
        conversation_store $store,
        authorization_service $authz
    ) {
        $this->registry = $registry;
        $this->store    = $store;
        $this->authz    = $authz;
    }

    // -------------------------------------------------------------------------
    // Public interface.

    /**
     * Route the raw orchestrator result through the full decision tree.
     *
     * This is the single authoritative routing method.  AgentRuntime calls it
     * once per internal loop step after the LLM has responded.
     *
     * @param  array  $result          Interpreter result from orchestrator::process().
     * @param  int    $threadid
     * @param  int    $cmid
     * @param  int    $userid
     * @param  string $outputlang
     * @param  int    $previewoptionid Resolved preview option id (0 = none).
     * @return array  Normalized result ready for persistence or loop continuation.
     */
    public function process(
        array $result,
        int $threadid,
        int $cmid,
        int $userid,
        string $outputlang,
        int $previewoptionid
    ): array {
        // 1. Preview shortcut: if the user asked for a preview and one is available.
        if ($previewoptionid > 0 && $this->result_has_trigger($result, 'core.is_preview_request')) {
            return [
                'response_type'             => 'clarification',
                'message'                   => $this->localized_string(
                    'ai_preview_latest_option', 'mod_booking', null, $outputlang
                ),
                'used_triggers'             => $result['used_triggers'] ?? [],
                'commands'                  => [],
                'ambiguities'               => array_values(array_unique((array)($result['ambiguities'] ?? []))),
                'ambiguity_options'         => [],
                'errors'                    => array_values(array_unique((array)($result['errors'] ?? []))),
                'attempted_tasks'           => [],
                'issue_codes'               => array_values(array_unique((array)($result['issue_codes'] ?? []))),
                'pending_confirmation_code' => '',
            ];
        }

        // 2. Normalise task_call with confirmation trigger → confirm_pending.
        if (
            (string)($result['response_type'] ?? '') !== 'confirm_pending'
            && $this->result_has_trigger($result, 'core.is_confirmation_message')
        ) {
            $result['response_type'] = 'confirm_pending';
        }

        // 3. Handle explicit user confirmation of pending intent.
        if ((string)($result['response_type'] ?? '') === 'confirm_pending') {
            return $this->handle_confirm_pending($result, $threadid, $cmid, $userid, $outputlang);
        }

        // 4. Duplicate-title override: if the user explicitly asked to create anyway.
        if (
            $this->result_has_trigger($result, 'core.force_new_duplicate_option')
            && $this->has_recent_duplicate_title_prompt($threadid)
        ) {
            $result = $this->apply_duplicate_title_override($result);
        }

        // 5. Safety: block accidental mutation carry-over on lookup requests.
        if (
            $this->result_has_trigger($result, 'core.is_lookup_request')
            && (($result['response_type'] ?? '') === 'confirmation_request')
            && $this->has_mutating_commands($result)
        ) {
            return [
                'response_type'   => 'clarification',
                'message'         => $this->localized_string(
                    'ai_lookup_detected_blocked_mutation', 'mod_booking', null, $outputlang
                ),
                'commands'        => [],
                'ambiguities'     => array_values(array_unique((array)($result['ambiguities'] ?? []))),
                'errors'          => array_values(array_unique((array)($result['errors'] ?? []))),
                'attempted_tasks' => $result['attempted_tasks'] ?? [],
                'issue_codes'     => array_values(array_unique((array)($result['issue_codes'] ?? []))),
            ];
        }

        // 6. Harden: if the LLM incorrectly used task_call for a mutating command, promote.
        if ($this->has_mutating_commands($result) && ($result['response_type'] ?? '') === 'task_call') {
            $result['response_type'] = 'confirmation_request';
            $normalizedmsg = core_text::strtolower(trim((string)($result['message'] ?? '')));
            if (in_array($normalizedmsg, ['executing', 'executing.', 'running', 'running.'], true)) {
                $result['message'] = '';
            }
        }

        // 7. Execute read-only commands immediately; confirmation-gate mutating ones.
        if (in_array((string)($result['response_type'] ?? ''), ['task_call', 'confirmation_request'], true)) {
            $result = $this->handle_command_routing($result, $threadid, $cmid, $userid, $outputlang);
        }

        // 8. Run preflight on confirmation commands: resolve entities, detect conflicts,
        //    update commands to carry prepared_input, route based on preflight result.
        if (($result['response_type'] ?? '') === 'confirmation_request' && !empty($result['commands'])) {
            $result = $this->handle_preflight($result, $threadid, $cmid, $userid, $outputlang);
        }

        // 9. Augment teacher autocreate when user allows it.
        $usermessage = $this->get_last_user_message($threadid);
        $result = $this->augment_missing_teacher_autocreate_confirmation($result, $usermessage, $outputlang);

        // 10. Ensure message is never empty before storing pending intent.
        $message = trim((string)($result['message'] ?? ''));
        if ($message === '') {
            $result['message'] = $this->build_fallback_message($result, $outputlang);
        }

        // 11. Store / clear pending intent.
        if (($result['response_type'] ?? '') === 'confirmation_request' && !empty($result['commands'])) {
            $intentkey = hash('sha256', (string)$userid . ':' . $threadid . '::' . json_encode($result['commands']));
            $this->store->set_pending_intent($threadid, $result['commands'], $intentkey, $userid, $cmid);
            $pendingintent = $this->store->get_pending_intent($threadid);
            $result['pending_confirmation_code'] = (string)($pendingintent['confirmationcode'] ?? '');
        } else {
            $this->store->clear_pending_intent($threadid);
            $result['pending_confirmation_code'] = '';
        }

        return $result;
    }

    /**
     * Build a deterministic fallback message per response type and language.
     *
     * Made public so that AgentRuntime can call it if needed after process().
     * Each booking task declares its own fallback string keys via get_schema():
     *   - 'fallback_confirm_string_key'  for confirmation_request responses
     *   - 'fallback_taskcall_string_key' for task_call responses
     *
     * Cross-plugin tasks (entities.*, shopping_cart.*) are not in the registry, so
     * their strings remain hardcoded here as a last resort.
     *
     * @param  array  $result
     * @param  string $outputlang
     * @return string
     */
    public function build_fallback_message(array $result, string $outputlang = ''): string {
        $responsetype = (string)($result['response_type'] ?? '');
        $commands = $result['commands'] ?? [];
        $firsttask = '';
        if (is_array($commands) && !empty($commands) && is_array($commands[0] ?? null)) {
            $firsttask = (string)($commands[0]['task'] ?? '');
        }

        if ($responsetype === 'confirmation_request') {
            if ($firsttask !== '') {
                $task = $this->registry->get_task($firsttask);
                if ($task !== null) {
                    $key = (string)($task->get_schema()['fallback_confirm_string_key'] ?? '');
                    if ($key !== '') {
                        return $this->localized_string($key, 'mod_booking', null, $outputlang);
                    }
                }
            }
            return $this->localized_string('ai_status_confirm_default', 'mod_booking', null, $outputlang);
        }

        if ($responsetype === 'task_call') {
            if ($firsttask !== '') {
                $task = $this->registry->get_task($firsttask);
                if ($task !== null) {
                    $key = (string)($task->get_schema()['fallback_taskcall_string_key'] ?? '');
                    if ($key !== '') {
                        return $this->localized_string($key, 'mod_booking', null, $outputlang);
                    }
                }
            }
            // Cross-plugin task fallbacks (entities, shopping_cart — not in the booking registry).
            if ($firsttask === 'entities.list_all_entities') {
                return $this->localized_string('ai_status_taskcall_entities_list_all', 'mod_booking', null, $outputlang);
            }
            if ($firsttask === 'entities.search') {
                return $this->localized_string('ai_status_taskcall_entities_search', 'mod_booking', null, $outputlang);
            }
            if ($firsttask === 'entities.create_entity') {
                return $this->localized_string('ai_status_taskcall_entities_create', 'mod_booking', null, $outputlang);
            }
            if ($firsttask === 'shopping_cart.get_items') {
                return $this->localized_string('ai_status_taskcall_shopping_cart_items', 'mod_booking', null, $outputlang);
            }
            if ($firsttask === 'shopping_cart.get_totals') {
                return $this->localized_string('ai_status_taskcall_shopping_cart_totals', 'mod_booking', null, $outputlang);
            }
            return $this->localized_string('ai_status_taskcall_default', 'mod_booking', null, $outputlang);
        }

        return trim((string)($result['message'] ?? ''));
    }

    // -------------------------------------------------------------------------
    // Private: confirmation flow.

    /**
     * Handle a confirm_pending response: run preflight on stored commands and propagate the intent.
     *
     * @param  array  $result
     * @param  int    $threadid
     * @param  int    $cmid
     * @param  int    $userid
     * @param  string $outputlang
     * @return array
     */
    private function handle_confirm_pending(
        array $result,
        int $threadid,
        int $cmid,
        int $userid,
        string $outputlang
    ): array {
        $pendingintent = $this->store->get_pending_intent($threadid);

        if ($pendingintent === null) {
            return $this->clarification_result(
                $this->localized_string('ai_no_pending_intent', 'mod_booking', null, $outputlang)
            );
        }

        $confirmcommands = is_array($pendingintent['commands'] ?? null) ? (array)$pendingintent['commands'] : [];
        if (empty($confirmcommands)) {
            return $this->clarification_result(
                $this->localized_string('ai_no_pending_intent', 'mod_booking', null, $outputlang)
            );
        }

        // Re-run preflight so that prepared_input is refreshed for the executor.
        $preflightresult = $this->run_preflight_on_commands($confirmcommands, $threadid, $cmid, $userid);
        if (!$preflightresult['valid']) {
            $invalidmessage = implode(' ', array_values(array_unique(array_filter((array)($preflightresult['errors'] ?? [])))));
            return [
                'response_type'             => 'clarification',
                'message'                   => $invalidmessage !== '' ? $invalidmessage
                    : $this->localized_string('ai_no_pending_intent', 'mod_booking', null, $outputlang),
                'commands'                  => [],
                'ambiguities'               => [],
                'ambiguity_options'         => [],
                'errors'                    => $preflightresult['errors'] ?? [],
                'attempted_tasks'           => $preflightresult['attempted_tasks'] ?? [],
                'issue_codes'               => $preflightresult['issue_codes'] ?? [],
                'pending_confirmation_code' => '',
                'used_triggers'             => $result['used_triggers'] ?? [],
                'runid'                     => 0,
                'results'                   => [],
            ];
        }

        // Use the prepared commands (with resolved inputs) for the pending intent.
        $preparedcommands = $preflightresult['prepared_commands'];

        $confirmmessage = $this->localized_string('ai_confirm_pending_intent', 'mod_booking', null, $outputlang);
        $intentkey = hash('sha256', (string)$userid . ':' . $threadid . '::' . json_encode($preparedcommands));
        $this->store->set_pending_intent($threadid, $preparedcommands, $intentkey, $userid, $cmid);
        $updatedpending = $this->store->get_pending_intent($threadid);
        $confirmationcode = (string)($updatedpending['confirmationcode'] ?? '');

        return [
            'response_type'             => 'confirmation_request',
            'message'                   => $confirmmessage,
            'commands'                  => $preparedcommands,
            'ambiguities'               => [],
            'ambiguity_options'         => [],
            'errors'                    => [],
            'attempted_tasks'           => [],
            'issue_codes'               => [],
            'pending_confirmation_code' => $confirmationcode,
            'used_triggers'             => $result['used_triggers'] ?? [],
            'runid'                     => 0,
            'results'                   => [],
        ];
    }

    // -------------------------------------------------------------------------
    // Private: command routing.

    /**
     * Route commands: execute read-only immediately, confirmation-gate mutating ones.
     *
     * @param  array  $result
     * @param  int    $threadid
     * @param  int    $cmid
     * @param  int    $userid
     * @param  string $outputlang
     * @return array
     */
    private function handle_command_routing(
        array $result,
        int $threadid,
        int $cmid,
        int $userid,
        string $outputlang
    ): array {
        $commands = $result['commands'] ?? [];
        if (!is_array($commands) || empty($commands)) {
            return $result;
        }

        $split = $this->split_commands_by_mutability($commands);
        $readonlycommands = $split['readonly'];
        $mutatingcommands = $split['mutating'];
        $readonlyexecution = null;

        if (!empty($readonlycommands)) {
            $readonlyexecution = $this->execute_readonly_commands(
                $readonlycommands,
                $threadid,
                $cmid,
                $userid,
                $outputlang
            );
        }

        if (!empty($mutatingcommands)) {
            // Write operations remain confirmation-gated.
            $result['response_type'] = 'confirmation_request';
            $result['commands'] = $mutatingcommands;

            $confirmmessage = trim((string)($result['message'] ?? ''));
            if ($confirmmessage === '') {
                $confirmmessage = $this->build_fallback_message($result, $outputlang);
            }

            if (is_array($readonlyexecution)) {
                if ($this->execution_result_has_failures($readonlyexecution)) {
                    return [
                        'response_type'  => 'clarification',
                        'message'        => trim((string)($readonlyexecution['message'] ?? '')),
                        'commands'       => [],
                        'ambiguities'    => array_values(array_unique((array)($result['ambiguities'] ?? []))),
                        'errors'         => array_values(array_unique(array_merge(
                            (array)($result['errors'] ?? []),
                            (array)($readonlyexecution['errors'] ?? [])
                        ))),
                        'runid'          => (int)($readonlyexecution['runid'] ?? 0),
                        'results'        => is_array($readonlyexecution['results'] ?? null)
                            ? $readonlyexecution['results']
                            : [],
                        'issue_codes'    => array_values(array_unique((array)($result['issue_codes'] ?? []))),
                    ];
                } else {
                    $readonlymessage = trim((string)($readonlyexecution['message'] ?? ''));
                    $result['message'] = $readonlymessage !== ''
                        ? $readonlymessage . "\n\n" . $confirmmessage
                        : $confirmmessage;
                    $result['runid'] = (int)($readonlyexecution['runid'] ?? 0);
                    $result['results'] = is_array($readonlyexecution['results'] ?? null)
                        ? $readonlyexecution['results']
                        : [];
                }
            } else {
                $result['message'] = $confirmmessage;
            }
        } else if (is_array($readonlyexecution)) {
            $result = $readonlyexecution;
        }

        return $result;
    }

    /**
     * Run preflight validation on confirmation commands.
     *
     * Calls task->preflight() for each command, which:
     *  - resolves entity IDs (options, users, etc.)
     *  - detects conflicts (duplicate titles, missing fields, etc.)
     *  - normalises input
     *  - does NOT perform writes
     *
     * On success: updates each command's 'input' to prepared_input so the
     * executor never has to re-resolve anything.
     *
     * On failure: routes to confirmation_request (if confirmable soft issues) or
     * clarification (if hard blocking issues).
     *
     * @param  array  $result
     * @param  int    $threadid
     * @param  int    $cmid
     * @param  int    $userid
     * @param  string $outputlang
     * @return array
     */
    private function handle_preflight(
        array $result,
        int $threadid,
        int $cmid,
        int $userid,
        string $outputlang
    ): array {
        $commands = (array)($result['commands'] ?? []);
        $anonymizer = new privacy_anonymizer($this->store);
        $updatedcommands = [];
        $allissuecodes = [];
        $allissues = [];
        $blockingerrors = [];
        $attemptedtasks = [];

        foreach ($commands as $idx => $command) {
            if (!is_array($command)) {
                $blockingerrors[] = get_string('agent_decision_command_malformed', 'mod_booking', $idx + 1);
                continue;
            }

            $taskname = trim((string)($command['task'] ?? ''));
            if ($taskname === '') {
                $blockingerrors[] = get_string('agent_decision_command_missing_task', 'mod_booking', $idx + 1);
                continue;
            }
            $attemptedtasks[] = $taskname;

            $task = $this->registry->get_task($taskname);
            if ($task === null) {
                $blockingerrors[] = get_string('agent_decision_command_task_not_registered', 'mod_booking', (object)[
                    'idx' => $idx + 1,
                    'task' => $taskname,
                ]);
                continue;
            }

            $input = is_array($command['input'] ?? null) ? (array)$command['input'] : [];

            // Deanonymize before preflight so task sees real values.
            if ($threadid > 0 && $userid > 0) {
                $input = $anonymizer->deanonymize_command_input_for_active_user($cmid, $userid, $input);
            }

            $preflightresult = $task->preflight($input, $cmid, $userid);

            // Collect issue codes.
            foreach ($preflightresult->get_issue_codes() as $code) {
                if ($code !== '') {
                    $allissuecodes[] = $code;
                }
            }
            $allissues = array_merge($allissues, $preflightresult->issues);

            if (!$preflightresult->is_valid) {
                // Collect blocking issues.
                foreach ($preflightresult->get_issues_by_severity('needs_clarification') as $issue) {
                    $msg = trim((string)($issue['message'] ?? ''));
                    if ($msg !== '') {
                        $blockingerrors[] = $msg;
                    }
                }
                // Confirmable issues from an invalid preflight result are still blocking
                // at this point — they were not confirmed yet.
                foreach ($preflightresult->get_issues_by_severity('needs_confirmation') as $issue) {
                    $msg = trim((string)($issue['message'] ?? ''));
                    if ($msg !== '') {
                        $blockingerrors[] = $msg;
                    }
                }
                continue;
            }

            // Preflight succeeded: store prepared_input so executor never re-resolves.
            $updatedcommand = $command;
            $updatedcommand['input'] = $preflightresult->prepared_input;
            $updatedcommands[] = $updatedcommand;
        }

        $allissuecodes = array_values(array_unique($allissuecodes));
        $attemptedtasks = array_values(array_unique($attemptedtasks));

        // If there were blocking errors, decide whether to allow confirmable continuation.
        if (!empty($blockingerrors)) {
            $validationmessage = trim(implode(' ', $blockingerrors));

            if ($this->has_confirmable_prevalidation_issues($allissuecodes) && !empty($result['commands'])) {
                // Soft-confirmable: show confirmation_request with augmented message.
                return [
                    'response_type'   => 'confirmation_request',
                    'message'         => $validationmessage !== '' ? $validationmessage : $result['message'],
                    'commands'        => (array)$result['commands'],
                    'ambiguities'     => [],
                    'errors'          => $blockingerrors,
                    'attempted_tasks' => $attemptedtasks,
                    'issue_codes'     => $allissuecodes,
                    'used_triggers'   => $result['used_triggers'] ?? [],
                ];
            }

            return [
                'response_type'   => 'clarification',
                'message'         => $validationmessage !== '' ? $validationmessage : $this->localized_string(
                    'ai_no_pending_intent',
                    'mod_booking',
                    null,
                    $outputlang
                ),
                'commands'        => [],
                'ambiguities'     => [],
                'errors'          => $blockingerrors,
                'attempted_tasks' => $attemptedtasks,
                'issue_codes'     => $allissuecodes,
                'used_triggers'   => $result['used_triggers'] ?? [],
            ];
        }

        // All commands passed preflight.  Swap raw commands for prepared-input versions.
        $result['commands']      = $updatedcommands;
        $result['issue_codes']   = array_values(array_unique(array_merge(
            (array)($result['issue_codes'] ?? []),
            $allissuecodes
        )));
        $result['attempted_tasks'] = $attemptedtasks;

        // If preflight returned confirmable issues (but is_valid=true), surface them.
        $confirmableissues = array_filter(
            $allissues,
            static fn(array $i): bool => ($i['severity'] ?? '') === 'needs_confirmation'
        );
        if (!empty($confirmableissues)) {
            $confirmationmessage = trim((string)($result['message'] ?? ''));
            if ($confirmationmessage === '') {
                $parts = [];
                foreach ($confirmableissues as $issue) {
                    $q = trim((string)($issue['user_question'] ?? $issue['message'] ?? ''));
                    if ($q !== '') {
                        $parts[] = $q;
                    }
                }
                $confirmationmessage = implode(' ', $parts);
            }
            $result['message'] = $confirmationmessage;

            // Augment commands with issue-specific override tokens.
            $result['commands'] = $this->apply_confirmable_overrides($result['commands'], $confirmableissues);
        }

        return $result;
    }

    /**
     * Apply override tokens to commands based on confirmable issue codes.
     *
     * When a confirmable issue is known to require an override token in the
     * command input (e.g. MISSING_LOCATION_CONFIRM_REQUIRED → override=location),
     * this method mutates the commands array so that execute() sees the right
     * override flags.
     *
     * @param  array $commands
     * @param  array $confirmableissues
     * @return array
     */
    private function apply_confirmable_overrides(array $commands, array $confirmableissues): array {
        $codeset = [];
        foreach ($confirmableissues as $issue) {
            $code = trim((string)($issue['code'] ?? ''));
            if ($code !== '') {
                $codeset[$code] = true;
            }
        }

        foreach ($commands as &$command) {
            if (!is_array($command)) {
                continue;
            }
            if (!is_array($command['input'] ?? null)) {
                $command['input'] = [];
            }
            if (isset($codeset['MISSING_LOCATION_CONFIRM_REQUIRED'])) {
                $overrides = is_array($command['input']['override'] ?? null)
                    ? $command['input']['override']
                    : [];
                $overrides[] = 'location';
                $overrides[] = 'address';
                $command['input']['override'] = array_values(array_unique(array_map(
                    static fn($t): string => strtolower(trim((string)$t)),
                    $overrides
                )));
            }
            if (isset($codeset['SOFT_BOOKING_OVERRIDE_CONFIRM_REQUIRED'])) {
                $command['input']['confirmed'] = true;
            }
        }
        unset($command);

        return $commands;
    }

    // -------------------------------------------------------------------------
    // Private: read-only command execution.

    /**
     * Execute read-only commands directly and return an execution result payload.
     *
     * @param  array  $commands
     * @param  int    $threadid
     * @param  int    $cmid
     * @param  int    $userid
     * @param  string $outputlang
     * @return array
     */
    private function execute_readonly_commands(
        array $commands,
        int $threadid,
        int $cmid,
        int $userid,
        string $outputlang
    ): array {
        $idempotencykey = hash(
            'sha256',
            $userid . ':' . $cmid . ':' . $threadid . ':' . json_encode($commands) . ':' . microtime(true)
        );
        $runid = $this->store->create_run($threadid, $userid, $cmid, $idempotencykey, $commands);

        try {
            $this->store->update_run_status($runid, 'running');
            $exec = new executor($this->registry, $this->store, $this->authz);
            $rawresults = $exec->execute_commands($commands, $cmid, $userid, $idempotencykey, $runid);
            $feedbackservice = new execution_feedback_service($this->store);
            $feedback = $feedbackservice->build_completion_feedback(
                $threadid,
                $cmid,
                $userid,
                $commands,
                $rawresults,
                $outputlang
            );
            $results = $feedback['results'];
            $this->store->update_run_status($runid, 'completed', $results);
            $message = trim((string)($feedback['message'] ?? ''));
            if ($message === '') {
                $message = $this->localized_string('ai_run_executed', 'mod_booking', null, $outputlang);
            }

            return [
                'response_type' => 'execution_result',
                'message'       => $message,
                'commands'      => [],
                'ambiguities'   => [],
                'errors'        => [],
                'runid'         => (int)$runid,
                'results'       => $results,
            ];
        } catch (\Throwable $e) {
            $failureresults = [[
                'status'   => 'error',
                'detail'   => $e->getMessage(),
                'resultid' => null,
            ]];
            $this->store->update_run_status($runid, 'failed', $failureresults);

            return [
                'response_type' => 'error',
                'message'       => $this->localized_string('ai_provider_error', 'mod_booking', null, $outputlang),
                'commands'      => [],
                'ambiguities'   => [],
                'errors'        => [$e->getMessage()],
                'runid'         => (int)$runid,
                'results'       => $failureresults,
            ];
        }
    }

    // -------------------------------------------------------------------------
    // Private: preflight helpers.

    /**
     * Run preflight validation on a list of commands.
     *
     * Calls task->preflight() for each command (with deanonymization) and
     * returns:
     *   valid             — bool: whether all commands passed
     *   prepared_commands — the commands with input replaced by prepared_input
     *   errors            — human-readable error messages (blocking)
     *   attempted_tasks   — list of task names
     *   issue_codes       — all issue codes from all commands
     *
     * @param  array $commands
     * @param  int   $threadid
     * @param  int   $cmid
     * @param  int   $userid
     * @return array{valid:bool,prepared_commands:array,errors:array,attempted_tasks:array,issue_codes:array}
     */
    private function run_preflight_on_commands(
        array $commands,
        int $threadid,
        int $cmid,
        int $userid
    ): array {
        $preparedcommands = [];
        $errors = [];
        $attemptedtasks = [];
        $issuecodes = [];

        $anonymizer = new privacy_anonymizer($this->store);

        foreach ($commands as $idx => $command) {
            $label = 'Command #' . ($idx + 1);
            if (!is_array($command)) {
                $errors[] = $label . ': malformed command payload.';
                continue;
            }

            $taskname = trim((string)($command['task'] ?? ''));
            if ($taskname === '') {
                $errors[] = $label . ': missing task.';
                continue;
            }
            $attemptedtasks[] = $taskname;

            $task = $this->registry->get_task($taskname);
            if ($task === null) {
                $errors[] = $label . ': task ' . $taskname . ' is not registered.';
                continue;
            }

            $input = is_array($command['input'] ?? null) ? (array)$command['input'] : [];

            // Deanonymize before preflight so task sees real values.
            if ($threadid > 0 && $userid > 0) {
                $input = $anonymizer->deanonymize_command_input_for_active_user($cmid, $userid, $input);
            }

            $preflightresult = $task->preflight($input, $cmid, $userid);

            foreach ($preflightresult->get_issue_codes() as $code) {
                if ($code !== '') {
                    $issuecodes[] = $code;
                }
            }

            if (!$preflightresult->is_valid) {
                foreach ($preflightresult->issues as $issue) {
                    $msg = trim((string)($issue['message'] ?? ''));
                    if ($msg !== '') {
                        $errors[] = $msg;
                    }
                }
                // Infer TEACHER_USER_NOT_FOUND from message text for backward-compatible
                // fallback-message generation in build_confirmation_validation_message().
                foreach ($errors as $error) {
                    $normalizederror = core_text::strtolower(trim((string)$error));
                    if (
                        str_contains($normalizederror, 'no user matched user query')
                        || str_contains($normalizederror, 'keine nutzerin/kein nutzer passt zur nutzerabfrage')
                    ) {
                        $issuecodes[] = 'TEACHER_USER_NOT_FOUND';
                    }
                }
                continue;
            }

            // Preflight passed: update command input with resolved prepared_input.
            $updatedcommand = $command;
            $updatedcommand['input'] = $preflightresult->prepared_input;
            $preparedcommands[] = $updatedcommand;
        }

        return [
            'valid'             => empty($errors),
            'prepared_commands' => $preparedcommands,
            'errors'            => array_values(array_unique($errors)),
            'attempted_tasks'   => array_values(array_unique($attemptedtasks)),
            'issue_codes'       => array_values(array_unique($issuecodes)),
        ];
    }

    /**
     * Build a user-facing clarification text from pre-confirmation validation result.
     *
     * @param  array  $validation
     * @param  string $outputlang
     * @return string
     */
    private function build_confirmation_validation_message(array $validation, string $outputlang): string {
        $errors = (array)($validation['errors'] ?? []);
        $ambiguities = (array)($validation['ambiguities'] ?? []);
        $attemptedtasks = array_map(
            static fn($task): string => trim((string)$task),
            (array)($validation['attempted_tasks'] ?? [])
        );
        $issuecodes = array_map(
            static fn($code): string => trim(core_text::strtoupper((string)$code)),
            (array)($validation['issue_codes'] ?? [])
        );

        if (
            in_array('TEACHER_USER_NOT_FOUND', $issuecodes, true)
            && in_array('booking.create_option', $attemptedtasks, true)
            && $this->has_confirmable_prevalidation_issues($issuecodes)
        ) {
            $teacherquery = $this->extract_teacher_query_from_validation_errors($errors);
            if ($teacherquery === '') {
                $teacherquery = $this->localized_string('ai_property_teacherquery', 'mod_booking', null, $outputlang);
            }
            return $this->localized_string(
                'ai_confirm_missing_teacher_user_create_option',
                'mod_booking',
                (object)['userquery' => $teacherquery],
                $outputlang
            );
        }

        $parts = [];
        if (!empty($errors)) {
            $parts[] = trim(implode(' ', array_map(static fn($v): string => trim((string)$v), $errors)));
        }
        if (!empty($ambiguities)) {
            $parts[] = trim(implode(' ', array_map(static fn($v): string => trim((string)$v), $ambiguities)));
        }

        $message = trim(implode(' ', array_filter($parts)));
        if ($message !== '') {
            return $message;
        }

        return $this->localized_string('ai_no_pending_intent', 'mod_booking', null, $outputlang);
    }

    /**
     * Extract teacher query value from validation error text.
     *
     * @param  array $errors
     * @return string
     */
    private function extract_teacher_query_from_validation_errors(array $errors): string {
        foreach ($errors as $error) {
            $text = trim((string)$error);
            if ($text === '' || preg_match('/"([^"]+)"/', $text, $matches) !== 1) {
                continue;
            }
            return trim((string)($matches[1] ?? ''));
        }
        return '';
    }

    // -------------------------------------------------------------------------
    // Private: command classification helpers.

    /**
     * Check whether a response contains at least one mutating (non-read-only) command.
     *
     * @param  array $result
     * @return bool
     */
    private function has_mutating_commands(array $result): bool {
        $commands = $result['commands'] ?? [];
        if (!is_array($commands) || empty($commands)) {
            return false;
        }
        foreach ($commands as $command) {
            if (!is_array($command)) {
                continue;
            }
            $taskname = trim((string)($command['task'] ?? ''));
            if ($taskname !== '' && !$this->registry->is_read_only_task($taskname)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Split commands into read-only and mutating groups.
     *
     * Unknown or malformed commands are treated as mutating for safety.
     *
     * @param  array $commands
     * @return array ['readonly' => array, 'mutating' => array]
     */
    private function split_commands_by_mutability(array $commands): array {
        $readonly = [];
        $mutating = [];

        foreach ($commands as $command) {
            if (!is_array($command)) {
                $mutating[] = ['task' => '', 'input' => []];
                continue;
            }
            $taskname = trim((string)($command['task'] ?? ''));
            if ($taskname !== '' && $this->registry->is_read_only_task($taskname)) {
                $readonly[] = $command;
            } else {
                $mutating[] = $command;
            }
        }

        return ['readonly' => $readonly, 'mutating' => $mutating];
    }

    /**
     * Detect failed read-only execution.
     *
     * @param  array $execution
     * @return bool
     */
    private function execution_result_has_failures(array $execution): bool {
        if ((string)($execution['response_type'] ?? '') === 'error') {
            return true;
        }
        $results = $execution['results'] ?? [];
        if (!is_array($results)) {
            return false;
        }
        foreach ($results as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $status = core_text::strtolower(trim((string)($entry['status'] ?? '')));
            if (in_array($status, ['error', 'failed', 'skipped'], true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check whether pre-validation issue codes support keeping confirmation flow.
     *
     * @param  array $issuecodes
     * @return bool
     */
    private function has_confirmable_prevalidation_issues(array $issuecodes): bool {
        $normalized = array_map(
            static fn($code): string => trim(core_text::strtoupper((string)$code)),
            $issuecodes
        );
        return !empty(array_intersect(self::PREVALIDATION_CONFIRMABLE_ISSUE_CODES, $normalized));
    }

    // -------------------------------------------------------------------------
    // Private: trigger helpers.

    /**
     * Check whether a normalized interpreter result includes a specific trigger id.
     *
     * @param  array  $result
     * @param  string $triggerid
     * @return bool
     */
    private function result_has_trigger(array $result, string $triggerid): bool {
        $usedtriggers = $result['used_triggers'] ?? [];
        if (!is_array($usedtriggers) || trim($triggerid) === '') {
            return false;
        }
        foreach ($usedtriggers as $candidate) {
            if (trim((string)$candidate) === $triggerid) {
                return true;
            }
        }
        return false;
    }

    // -------------------------------------------------------------------------
    // Private: duplicate-title helpers.

    /**
     * Check whether the recent assistant response asked about duplicate titles.
     *
     * @param  int $threadid
     * @return bool
     */
    private function has_recent_duplicate_title_prompt(int $threadid): bool {
        $messages = $this->store->get_recent_messages($threadid, 8);
        if (empty($messages)) {
            return false;
        }
        foreach ($messages as $msg) {
            if ((string)($msg->role ?? '') !== 'assistant') {
                continue;
            }
            $structured = json_decode((string)($msg->structuredjson ?? ''), true);
            if (!is_array($structured)) {
                continue;
            }
            if ((string)($structured['response_type'] ?? '') !== 'confirmation_request') {
                continue;
            }
            $issuecodes = $structured['issue_codes'] ?? [];
            if (!is_array($issuecodes)) {
                continue;
            }
            $normalizedcodes = array_values(array_filter(array_map(
                static fn($code): string => strtoupper(trim((string)$code)),
                $issuecodes
            )));
            if (!empty(array_intersect(self::DUPLICATE_TITLE_ISSUE_CODES, $normalizedcodes))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Ensure create_option commands include duplicate_title override after explicit user confirmation.
     *
     * @param  array $result
     * @return array
     */
    private function apply_duplicate_title_override(array $result): array {
        if (!in_array((string)($result['response_type'] ?? ''), ['task_call', 'confirmation_request'], true)) {
            return $result;
        }
        $commands = $result['commands'] ?? [];
        if (!is_array($commands) || empty($commands)) {
            return $result;
        }
        $changed = false;
        foreach ($commands as $idx => $command) {
            if (!is_array($command) || (string)($command['task'] ?? '') !== 'booking.create_option') {
                continue;
            }
            $input = $command['input'] ?? [];
            if (!is_array($input)) {
                continue;
            }
            $overrides = $input['override'] ?? [];
            if (!is_array($overrides)) {
                $overrides = [];
            }
            if (!in_array('duplicate_title', $overrides, true)) {
                $overrides[] = 'duplicate_title';
                $input['override'] = array_values(array_unique($overrides));
                $commands[$idx]['input'] = $input;
                $changed = true;
            }
        }
        if ($changed) {
            $result['commands'] = array_values($commands);
        }
        return $result;
    }

    // -------------------------------------------------------------------------
    // Private: teacher autocreate augmentation.

    /**
     * Prepend booking.create_user when user explicitly allows creating missing teacher accounts.
     *
     * @param  array  $result
     * @param  string $usermessage
     * @param  string $outputlang
     * @return array
     */
    private function augment_missing_teacher_autocreate_confirmation(
        array $result,
        string $usermessage,
        string $outputlang = ''
    ): array {
        if ((string)($result['response_type'] ?? '') !== 'confirmation_request') {
            return $result;
        }
        if ($this->registry->get_task('booking.create_user') === null) {
            return $result;
        }
        if (!$this->user_allows_missing_user_autocreate($usermessage)) {
            return $result;
        }

        $issuecodes = array_map(
            static fn($code): string => trim(core_text::strtoupper((string)$code)),
            (array)($result['issue_codes'] ?? [])
        );
        $errors = array_map(
            static fn($error): string => core_text::strtolower(trim((string)$error)),
            (array)($result['errors'] ?? [])
        );

        $hasteachernotfounderror = false;
        foreach ($errors as $error) {
            if (
                $error !== ''
                && (
                    str_contains($error, 'no user matched user query')
                    || str_contains($error, 'keine nutzerin/kein nutzer passt zur nutzerabfrage')
                )
            ) {
                $hasteachernotfounderror = true;
                break;
            }
        }

        if (!in_array('TEACHER_USER_NOT_FOUND', $issuecodes, true) && !$hasteachernotfounderror) {
            return $result;
        }

        $commands = is_array($result['commands'] ?? null) ? (array)$result['commands'] : [];
        if (empty($commands)) {
            return $result;
        }
        foreach ($commands as $command) {
            if (!is_array($command)) {
                continue;
            }
            if ((string)($command['task'] ?? '') === 'booking.create_user') {
                return $result;
            }
        }

        $teacherquery = '';
        foreach ($commands as $command) {
            if (!is_array($command) || (string)($command['task'] ?? '') !== 'booking.create_option') {
                continue;
            }
            $input = is_array($command['input'] ?? null) ? (array)$command['input'] : [];
            $candidate = trim((string)($input['teacherquery'] ?? ''));
            if ($candidate !== '') {
                $teacherquery = $candidate;
                break;
            }
        }

        if ($teacherquery === '') {
            return $result;
        }

        array_unshift($commands, [
            'task'    => 'booking.create_user',
            'version' => 1,
            'input'   => ['userquery' => $teacherquery, 'outputlang' => $outputlang],
        ]);
        $result['commands'] = array_values($commands);
        return $result;
    }

    /**
     * Detect user intent that permits creating missing users.
     *
     * @param  string $usermessage
     * @return bool
     */
    private function user_allows_missing_user_autocreate(string $usermessage): bool {
        $normalized = core_text::strtolower(trim(preg_replace('/\s+/', ' ', $usermessage) ?? $usermessage));
        if ($normalized === '') {
            return false;
        }
        return (bool)preg_match(
            '/('
            . 'auch\s+wenn\s+.*benutzer.*nicht\s+existiert|'
            . 'if\s+.*user.*does\s+not\s+exist|'
            . 'even\s+if\s+.*user.*does\s+not\s+exist'
            . ')/u',
            $normalized
        );
    }

    // -------------------------------------------------------------------------
    // Private: store / thread helpers.

    /**
     * Retrieve the last user message from the thread.
     *
     * @param  int $threadid
     * @return string
     */
    private function get_last_user_message(int $threadid): string {
        $messages = $this->store->get_recent_messages($threadid, 8);
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]->role ?? '') === 'user') {
                return (string)($messages[$i]->content ?? '');
            }
        }
        return '';
    }

    /**
     * Build a minimal clarification result.
     *
     * @param  string $message
     * @return array
     */
    private function clarification_result(string $message): array {
        return [
            'response_type'             => 'clarification',
            'message'                   => $message,
            'commands'                  => [],
            'ambiguities'               => [],
            'ambiguity_options'         => [],
            'errors'                    => [],
            'attempted_tasks'           => [],
            'issue_codes'               => [],
            'pending_confirmation_code' => '',
            'used_triggers'             => [],
            'runid'                     => 0,
            'results'                   => [],
        ];
    }

    // -------------------------------------------------------------------------
    // Private: localisation helper.

    /**
     * Resolve a localised string in the requested language.
     *
     * @param  string $identifier
     * @param  string $component
     * @param  mixed  $a
     * @param  string $lang
     * @return string
     */
    private function localized_string(string $identifier, string $component, $a = null, string $lang = ''): string {
        $currentlang = current_language();
        $targetlang  = trim($lang);
        $switched    = $targetlang !== '' && $targetlang !== $currentlang;

        if ($switched) {
            force_current_language($targetlang);
        }

        try {
            return get_string($identifier, $component, $a);
        } finally {
            if ($switched) {
                force_current_language($currentlang);
            }
        }
    }
}
