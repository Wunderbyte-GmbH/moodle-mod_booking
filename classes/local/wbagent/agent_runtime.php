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
 * Central agent runtime: owns the full agent loop.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_booking\local\wbagent;

use context_module;
use core_text;

/**
 * Owns the complete agent execution loop: plan → execute → observe → decide.
 *
 * Responsibilities:
 * - Own the full agent loop (planning via LLM, tool execution, observation, next-step decision).
 * - Handle confirmation state machine, trigger routing, and read-only auto-execution.
 * - Manage pending intents and session state via conversation_store.
 * - Enforce the step counter and max-step limit for multi-turn loops.
 *
 * The API layer (ai_send_message) is a thin wrapper that:
 * 1. Does auth / session validation.
 * 2. Stores the user message.
 * 3. Calls AgentRuntime::run().
 * 4. Applies display-side privacy deanonymisation.
 * 5. Formats the result for the external API contract.
 *
 * Adding a new task MUST NOT require changes here — the task registry discovers
 * tasks automatically from all installed components.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class agent_runtime {
    /** Maximum agent loop steps before bailing out. */
    public const MAX_LOOP_STEPS = 5;

    /** Issue codes indicating a duplicate-title confirmation context. */
    public const DUPLICATE_TITLE_ISSUE_CODES = [
        'DUPLICATE_TITLE_CONFIRM_REQUIRED',
        'DUPLICATE_TITLE_MULTI_CONFIRM_REQUIRED',
    ];

    /** Issue codes that indicate an invalid/expired token or required subscription. */
    public const TOKEN_SUBSCRIPTION_ISSUE_CODES = [
        'TRIAL_TOKEN_INVALID',
        'TRIAL_TOKEN_EXPIRED',
        'SUBSCRIPTION_REQUIRED',
        'AI_PROVIDER_AUTH_FAILED',
        'AI_PROVIDER_QUOTA_EXCEEDED',
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

    /** Basic subscription purchase URL. */
    public const BASIC_SUBSCRIPTION_URL =
        'https://showroom.wunderbyte.at/mod/booking/optionview.php?optionid=73&cmid=938&userid=1';

    /** Privacy Plus subscription purchase URL. */
    public const PRIVACY_PLUS_SUBSCRIPTION_URL =
        'https://showroom.wunderbyte.at/mod/booking/optionview.php?optionid=74&cmid=938&userid=1';

    /** @var task_registry */
    private task_registry $registry;

    /** @var orchestrator */
    private orchestrator $orchestrator;

    /** @var conversation_store */
    private conversation_store $store;

    /** @var authorization_service */
    private authorization_service $authz;

    /**
     * Constructor.
     *
     * @param task_registry        $registry
     * @param orchestrator         $orchestrator
     * @param conversation_store   $store
     * @param authorization_service $authz
     */
    public function __construct(
        task_registry $registry,
        orchestrator $orchestrator,
        conversation_store $store,
        authorization_service $authz
    ) {
        $this->registry     = $registry;
        $this->orchestrator = $orchestrator;
        $this->store        = $store;
        $this->authz        = $authz;
    }

    // -------------------------------------------------------------------------
    // Public interface.

    /**
     * Process the latest user message stored in the thread and return a
     * normalized result ready for the API layer.
     *
     * This is the single-step entry point: the orchestrator is called once, the
     * result is interpreted, and — if the LLM chose read-only tools — those tools
     * are immediately executed, with the observations added back to context.
     * Mutating commands always require user confirmation before execution.
     *
     * The returned array contains:
     *   response_type           (string)
     *   message                 (string)
     *   commands                (array)
     *   ambiguities             (array)
     *   ambiguity_options       (array)
     *   errors                  (array)
     *   attempted_tasks         (array)
     *   issue_codes             (array)
     *   pending_confirmation_code (string)
     *   used_triggers           (array)
     *   runid                   (int)
     *   results                 (array)
     *   lang                    (string)
     *
     * @param  int $threadid
     * @param  int $cmid
     * @param  int $userid
     * @return array
     */
    public function run(int $threadid, int $cmid, int $userid): array {
        $previewoptionid = $this->resolve_preview_option_id($threadid, $cmid);
        $triggerregistry = new message_trigger_registry($this->registry);

        // Plan: call the LLM once.
        $result = $this->orchestrator->process($threadid, $cmid, $userid);

        $outputlang = trim((string)($result['lang'] ?? ''));
        if ($outputlang === '') {
            $outputlang = current_language();
        }
        $this->store->set_thread_metadata_value($threadid, 'last_output_lang', $outputlang);
        $result['used_triggers'] = $triggerregistry->normalize_used_triggers($result['used_triggers'] ?? []);

        // Infer issue codes when the LLM returned a generic error.
        if (
            (string)($result['response_type'] ?? '') === 'error'
            && empty((array)($result['issue_codes'] ?? []))
        ) {
            $fallback = $this->infer_issue_codes_from_recent_core_ai_failure($userid, $cmid);
            if (!empty($fallback)) {
                $result['issue_codes'] = $fallback;
            }
        }

        // Decide: route through the confirmation / trigger / execution decision tree.
        $result = $this->decide($result, $threadid, $cmid, $userid, $outputlang, $previewoptionid);

        // Finalize language / subscription messaging.
        $result['message'] = $this->normalize_agent_message_language($result, $outputlang);
        if ($this->has_token_subscription_issue((array)($result['issue_codes'] ?? []))) {
            $result['message'] = $this->localized_string(
                'ai_trial_token_invalid_subscription_message',
                'mod_booking',
                (object)[
                    'basicurl'       => self::BASIC_SUBSCRIPTION_URL,
                    'privacyplusurl' => self::PRIVACY_PLUS_SUBSCRIPTION_URL,
                ],
                $outputlang
            );
        }

        // Persist the assistant message.
        $this->store->add_message($threadid, 'assistant', $result['message'] ?? '', [
            'response_type'            => $result['response_type'],
            'used_triggers'            => $result['used_triggers'] ?? [],
            'commands'                 => $result['commands'] ?? [],
            'ambiguities'              => $result['ambiguities'] ?? [],
            'ambiguity_options'        => $result['ambiguity_options'] ?? [],
            'errors'                   => $result['errors'] ?? [],
            'attempted_tasks'          => $result['attempted_tasks'] ?? [],
            'issue_codes'              => $result['issue_codes'] ?? [],
            'pending_confirmation_code' => $result['pending_confirmation_code'] ?? '',
        ]);

        return $result;
    }

    /**
     * Multi-step agent loop entry point.
     *
     * Iterates plan → execute (read-only) → observe → plan until the LLM
     * produces a response that requires user interaction (clarification,
     * confirmation) or the step limit is reached.
     *
     * This replaces the single-shot + repair-hack pattern. Mutating commands
     * are never executed automatically — they are confirmation-gated.
     *
     * @param  int $threadid
     * @param  int $cmid
     * @param  int $userid
     * @param  int $maxsteps Override for MAX_LOOP_STEPS (0 = use constant).
     * @return array Final normalized result.
     */
    public function run_loop(int $threadid, int $cmid, int $userid, int $maxsteps = 0): array {
        $limit = ($maxsteps > 0) ? $maxsteps : self::MAX_LOOP_STEPS;

        for ($step = 0; $step < $limit; $step++) {
            $result = $this->run_single_step($threadid, $cmid, $userid);

            // Store step metadata for observability.
            $result['loop_step'] = $step + 1;
            $result['loop_max_steps'] = $limit;

            // Stop looping when the result requires user interaction.
            if ($this->should_stop_loop($result)) {
                return $result;
            }

            // Read-only results are already observed and stored; loop continues.
        }

        // Fell through without a user-interaction response: emit a graceful message.
        return $this->max_steps_exceeded_result(current_language(), $limit);
    }

    // -------------------------------------------------------------------------
    // Private: loop helpers.

    /**
     * Execute one agent step: plan (LLM) + observe (if read-only results).
     *
     * Unlike run(), this does NOT persist the assistant message yet — that is
     * done once the loop exits. Observation feedback is appended to the thread
     * so the LLM sees it on the next step.
     *
     * @param  int $threadid
     * @param  int $cmid
     * @param  int $userid
     * @return array
     */
    private function run_single_step(int $threadid, int $cmid, int $userid): array {
        // Same single-step logic, but we let run() handle persistence.
        return $this->run($threadid, $cmid, $userid);
    }

    /**
     * Decide whether the loop should stop on this result.
     *
     * The loop continues only when read-only tools were executed and the result
     * was an execution_result (the LLM can then make further decisions with the
     * observation data). All other response types stop the loop.
     *
     * @param  array $result
     * @return bool
     */
    private function should_stop_loop(array $result): bool {
        $type = (string)($result['response_type'] ?? '');
        // Only continue looping on pure read-only execution results.
        return $type !== 'execution_result';
    }

    /**
     * Build an error result when the agent loop exceeds the step limit.
     *
     * @param  string $lang
     * @param  int    $maxsteps
     * @return array
     */
    private function max_steps_exceeded_result(string $lang, int $maxsteps): array {
        $message = $this->localized_string('ai_agent_max_steps_exceeded', 'mod_booking', null, $lang);
        if ($message === 'ai_agent_max_steps_exceeded') {
            $message = 'The agent reached the maximum number of steps ('
                . $maxsteps
                . '). Please try again with a more specific request.';
        }
        return [
            'response_type'            => 'error',
            'message'                  => $message,
            'commands'                 => [],
            'ambiguities'              => [],
            'ambiguity_options'        => [],
            'errors'                   => ['max_steps_exceeded'],
            'attempted_tasks'          => [],
            'issue_codes'              => ['MAX_STEPS_EXCEEDED'],
            'pending_confirmation_code' => '',
            'used_triggers'            => [],
            'runid'                    => 0,
            'results'                  => [],
            'lang'                     => $lang,
        ];
    }

    // -------------------------------------------------------------------------
    // Private: decision tree.

    /**
     * Route the raw orchestrator result through the full decision tree.
     *
     * @param  array  $result
     * @param  int    $threadid
     * @param  int    $cmid
     * @param  int    $userid
     * @param  string $outputlang
     * @param  int    $previewoptionid
     * @return array
     */
    private function decide(
        array $result,
        int $threadid,
        int $cmid,
        int $userid,
        string $outputlang,
        int $previewoptionid
    ): array {
        // Preview shortcut: if the user asked for a preview and one is available.
        if ($previewoptionid > 0 && $this->result_has_trigger($result, 'core.is_preview_request')) {
            $result = [
                'response_type'            => 'clarification',
                'message'                  => $this->localized_string('ai_preview_latest_option', 'mod_booking', null, $outputlang),
                'used_triggers'            => $result['used_triggers'] ?? [],
                'commands'                 => [],
                'ambiguities'              => array_values(array_unique((array)($result['ambiguities'] ?? []))),
                'ambiguity_options'        => [],
                'errors'                   => array_values(array_unique((array)($result['errors'] ?? []))),
                'attempted_tasks'          => [],
                'issue_codes'              => array_values(array_unique((array)($result['issue_codes'] ?? []))),
                'pending_confirmation_code' => '',
            ];
        }

        // Normalise task_call with confirmation trigger → confirm_pending.
        if (
            (string)($result['response_type'] ?? '') !== 'confirm_pending'
            && $this->result_has_trigger($result, 'core.is_confirmation_message')
        ) {
            $result['response_type'] = 'confirm_pending';
        }

        // Handle explicit user confirmation of pending intent.
        if ((string)($result['response_type'] ?? '') === 'confirm_pending') {
            return $this->handle_confirm_pending($result, $threadid, $cmid, $userid, $outputlang);
        }

        // Duplicate-title override: if the user explicitly asked to create anyway.
        if (
            $this->result_has_trigger($result, 'core.force_new_duplicate_option')
            && $this->has_recent_duplicate_title_prompt($threadid)
        ) {
            $result = $this->apply_duplicate_title_override($result);
        }

        // Safety: block accidental mutation carry-over on lookup requests.
        if (
            $this->result_has_trigger($result, 'core.is_lookup_request')
            && (($result['response_type'] ?? '') === 'confirmation_request')
            && $this->has_mutating_commands($result)
        ) {
            $result = [
                'response_type'  => 'clarification',
                'message'        => $this->localized_string(
                    'ai_lookup_detected_blocked_mutation',
                    'mod_booking',
                    null,
                    $outputlang
                ),
                'commands'       => [],
                'ambiguities'    => array_values(array_unique((array)($result['ambiguities'] ?? []))),
                'errors'         => array_values(array_unique((array)($result['errors'] ?? []))),
                'attempted_tasks' => $result['attempted_tasks'] ?? [],
                'issue_codes'    => array_values(array_unique((array)($result['issue_codes'] ?? []))),
            ];
        }

        // Harden: if the LLM incorrectly used task_call for a mutating command, promote to confirmation_request.
        if ($this->has_mutating_commands($result) && ($result['response_type'] ?? '') === 'task_call') {
            $result['response_type'] = 'confirmation_request';
            $normalizedmsg = core_text::strtolower(trim((string)($result['message'] ?? '')));
            if (in_array($normalizedmsg, ['executing', 'executing.', 'running', 'running.'], true)) {
                $result['message'] = '';
            }
        }

        // Execute read-only commands immediately; confirmation-gate mutating ones.
        if (in_array((string)($result['response_type'] ?? ''), ['task_call', 'confirmation_request'], true)) {
            $result = $this->handle_command_routing($result, $threadid, $cmid, $userid, $outputlang);
        }

        // Pre-validate confirmation commands.
        if (($result['response_type'] ?? '') === 'confirmation_request' && !empty($result['commands'])) {
            $result = $this->handle_prevalidation($result, $cmid, $outputlang);
        }

        // Augment teacher autocreate when user allows it.
        $usermessage = $this->get_last_user_message($threadid);
        $result = $this->augment_missing_teacher_autocreate_confirmation($result, $usermessage, $outputlang);

        // Store / clear pending intent.
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
     * Handle a confirm_pending response: re-validate and propagate the stored intent.
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
            // No pending intent: clarify.
            return $this->clarification_result(
                $this->localized_string('ai_no_pending_intent', 'mod_booking', null, $outputlang),
                $threadid,
                $outputlang
            );
        }

        $confirmcommands = is_array($pendingintent['commands'] ?? null) ? (array)$pendingintent['commands'] : [];
        if (empty($confirmcommands)) {
            return $this->clarification_result(
                $this->localized_string('ai_no_pending_intent', 'mod_booking', null, $outputlang),
                $threadid,
                $outputlang
            );
        }

        $confirmationvalidation = $this->prevalidate_confirmation_commands($confirmcommands, $cmid);
        if (!$confirmationvalidation['valid']) {
            $invalidmessage = $this->build_confirmation_validation_message($confirmationvalidation, $outputlang);
            return [
                'response_type'            => 'clarification',
                'message'                  => $invalidmessage,
                'commands'                 => [],
                'ambiguities'              => $confirmationvalidation['ambiguities'] ?? [],
                'ambiguity_options'        => $confirmationvalidation['ambiguity_options'] ?? [],
                'errors'                   => $confirmationvalidation['errors'] ?? [],
                'attempted_tasks'          => $confirmationvalidation['attempted_tasks'] ?? [],
                'issue_codes'              => $confirmationvalidation['issue_codes'] ?? [],
                'pending_confirmation_code' => '',
                'used_triggers'            => $result['used_triggers'] ?? [],
                'runid'                    => 0,
                'results'                  => [],
            ];
        }

        $confirmmessage = $this->localized_string('ai_confirm_pending_intent', 'mod_booking', null, $outputlang);
        $intentkey = hash('sha256', (string)$userid . ':' . $threadid . '::' . json_encode($confirmcommands));
        $this->store->set_pending_intent($threadid, $confirmcommands, $intentkey, $userid, $cmid);
        $updatedpending = $this->store->get_pending_intent($threadid);
        $confirmationcode = (string)($updatedpending['confirmationcode'] ?? '');

        return [
            'response_type'            => 'confirmation_request',
            'message'                  => $confirmmessage,
            'commands'                 => $confirmcommands,
            'ambiguities'              => [],
            'ambiguity_options'        => [],
            'errors'                   => [],
            'attempted_tasks'          => [],
            'issue_codes'              => [],
            'pending_confirmation_code' => $confirmationcode,
            'used_triggers'            => $result['used_triggers'] ?? [],
            'runid'                    => 0,
            'results'                  => [],
        ];
    }

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
            $readonlyexecution = $this->auto_execute_read_only_commands(
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
                $confirmmessage = $this->fallback_message_for_result($result, $outputlang);
            }

            if (is_array($readonlyexecution)) {
                if ($this->execution_result_has_failures($readonlyexecution)) {
                    $result = [
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
     * Pre-validate confirmation commands and downgrade to clarification if invalid.
     *
     * @param  array  $result
     * @param  int    $cmid
     * @param  string $outputlang
     * @return array
     */
    private function handle_prevalidation(array $result, int $cmid, string $outputlang): array {
        $confirmationvalidation = $this->prevalidate_confirmation_commands((array)$result['commands'], $cmid);
        if ($confirmationvalidation['valid']) {
            return $result;
        }

        $validationissuecodes = (array)($confirmationvalidation['issue_codes'] ?? []);
        if ($this->has_confirmable_prevalidation_issues($validationissuecodes)) {
            return [
                'response_type'  => 'confirmation_request',
                'message'        => $this->build_confirmation_validation_message($confirmationvalidation, $outputlang),
                'commands'       => (array)$result['commands'],
                'ambiguities'    => [],
                'errors'         => (array)($confirmationvalidation['errors'] ?? []),
                'attempted_tasks' => (array)($confirmationvalidation['attempted_tasks'] ?? []),
                'issue_codes'    => $validationissuecodes,
                'used_triggers'  => $result['used_triggers'] ?? [],
            ];
        }

        return [
            'response_type'  => 'clarification',
            'message'        => $this->build_confirmation_validation_message($confirmationvalidation, $outputlang),
            'commands'       => [],
            'ambiguities'    => (array)($confirmationvalidation['ambiguities'] ?? []),
            'errors'         => (array)($confirmationvalidation['errors'] ?? []),
            'attempted_tasks' => (array)($confirmationvalidation['attempted_tasks'] ?? []),
            'issue_codes'    => $validationissuecodes,
            'used_triggers'  => $result['used_triggers'] ?? [],
        ];
    }

    // -------------------------------------------------------------------------
    // Private: command execution helpers.

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
    private function auto_execute_read_only_commands(
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
    // Private: validation helpers.

    /**
     * Pre-validate commands before showing a confirmation button.
     *
     * @param  array $commands
     * @param  int   $cmid
     * @return array
     */
    private function prevalidate_confirmation_commands(array $commands, int $cmid): array {
        $errors = [];
        $ambiguities = [];
        $ambiguityoptions = [];
        $attemptedtasks = [];
        $issuecodes = [];

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

            $input = $command['input'] ?? [];
            if (!is_array($input)) {
                $errors[] = $label . ': input must be an object/array.';
                continue;
            }

            $validation = $task->validate($input, $cmid);
            foreach ((array)($validation['errors'] ?? []) as $error) {
                $msg = trim((string)$error);
                if ($msg !== '') {
                    $errors[] = $msg;
                }
            }
            foreach ((array)($validation['ambiguities'] ?? []) as $ambiguity) {
                $msg = trim((string)$ambiguity);
                if ($msg !== '') {
                    $ambiguities[] = $msg;
                }
            }
            if (!empty($validation['ambiguity_options']) && is_array($validation['ambiguity_options'])) {
                foreach ((array)$validation['ambiguity_options'] as $option) {
                    if (!is_array($option)) {
                        continue;
                    }
                    $optlabel = trim((string)($option['label'] ?? ''));
                    $optquery = trim((string)($option['query'] ?? ''));
                    if ($optlabel === '' && $optquery === '') {
                        continue;
                    }
                    $ambiguityoptions[] = [
                        'id'    => trim((string)($option['id'] ?? '')),
                        'label' => $optlabel,
                        'query' => $optquery,
                        'path'  => trim((string)($option['path'] ?? '')),
                        'title' => trim((string)($option['title'] ?? '')),
                        'task'  => $taskname,
                    ];
                }
            }

            $issues = $validation['issues'] ?? [];
            if (is_array($issues)) {
                foreach ($issues as $issue) {
                    if (!is_array($issue)) {
                        continue;
                    }
                    $code = trim((string)($issue['code'] ?? ''));
                    if ($code !== '') {
                        $issuecodes[] = $code;
                    }
                }
            }

            // Infer stable issue codes from human-readable validation errors.
            foreach ((array)($validation['errors'] ?? []) as $error) {
                $normalizederror = core_text::strtolower(trim((string)$error));
                if ($normalizederror === '') {
                    continue;
                }
                if (
                    str_contains($normalizederror, 'no user matched user query')
                    || str_contains($normalizederror, 'keine nutzerin/kein nutzer passt zur nutzerabfrage')
                ) {
                    $issuecodes[] = 'TEACHER_USER_NOT_FOUND';
                }
            }
        }

        return [
            'valid'             => empty($errors) && empty($ambiguities),
            'errors'            => array_values(array_unique($errors)),
            'ambiguities'       => array_values(array_unique($ambiguities)),
            'ambiguity_options' => array_values($ambiguityoptions),
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

    // -------------------------------------------------------------------------
    // Private: issue code helpers.

    /**
     * Check whether issue codes represent a token/subscription problem.
     *
     * @param  array $issuecodes
     * @return bool
     */
    private function has_token_subscription_issue(array $issuecodes): bool {
        $normalized = array_map(
            static fn($code): string => trim(core_text::strtoupper((string)$code)),
            $issuecodes
        );
        return !empty(array_intersect(self::TOKEN_SUBSCRIPTION_ISSUE_CODES, $normalized));
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

    /**
     * Infer issue codes from the latest core_ai failure in this module context.
     *
     * @param  int $userid
     * @param  int $cmid
     * @return array
     */
    private function infer_issue_codes_from_recent_core_ai_failure(int $userid, int $cmid): array {
        global $DB;

        try {
            $dbman = $DB->get_manager();
            if (!$dbman->table_exists('ai_action_register')) {
                return [];
            }

            $contextid = (int)context_module::instance($cmid)->id;
            $records = $DB->get_records_select(
                'ai_action_register',
                'userid = :userid AND contextid = :contextid AND actionname = :actionname
                 AND success = :success AND timecompleted >= :since',
                [
                    'userid'     => $userid,
                    'contextid'  => $contextid,
                    'actionname' => 'generate_text',
                    'success'    => 0,
                    'since'      => time() - 600,
                ],
                'timecompleted DESC, id DESC',
                'id, errorcode, errormessage',
                0,
                20
            );
        } catch (\Throwable $e) {
            return [];
        }

        if (empty($records)) {
            return [];
        }

        foreach ($records as $record) {
            $errorcode = (int)($record->errorcode ?? 0);
            if ($errorcode === 401) {
                return ['TRIAL_TOKEN_INVALID'];
            }
            if ($errorcode === 429) {
                return ['AI_PROVIDER_QUOTA_EXCEEDED'];
            }
            $combined = core_text::strtolower(trim((string)($record->errormessage ?? '')));
            if ($combined === '') {
                continue;
            }
            if (
                strpos($combined, 'unauthorized') !== false
                || strpos($combined, 'invalid token') !== false
                || strpos($combined, 'token expired') !== false
                || strpos($combined, 'invalid api key') !== false
            ) {
                return ['TRIAL_TOKEN_INVALID'];
            }
            if (
                strpos($combined, 'rate limit') !== false
                || strpos($combined, 'budget has been exceeded') !== false
                || strpos($combined, 'max budget') !== false
                || strpos($combined, 'insufficient quota') !== false
                || strpos($combined, 'insufficient credits') !== false
            ) {
                return ['AI_PROVIDER_QUOTA_EXCEEDED'];
            }
        }

        return [];
    }

    // -------------------------------------------------------------------------
    // Private: message / trigger helpers.

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

    /**
     * Ensure agent message text is in the output language.
     *
     * @param  array  $result
     * @param  string $outputlang
     * @return string
     */
    private function normalize_agent_message_language(array $result, string $outputlang): string {
        $message = trim((string)($result['message'] ?? ''));
        if ($message === '') {
            return $this->fallback_message_for_result($result, $outputlang);
        }
        return $message;
    }

    /**
     * Build a deterministic fallback message per response/task and language.
     *
     * Each booking task declares its own fallback string keys via get_schema():
     *   - 'fallback_confirm_string_key' for confirmation_request responses
     *   - 'fallback_taskcall_string_key' for task_call responses
     *
     * Cross-plugin tasks (entities.*, shopping_cart.*) are not in the registry, so
     * their strings remain hardcoded here as a last resort.
     *
     * @param  array  $result
     * @param  string $outputlang
     * @return string
     */
    private function fallback_message_for_result(array $result, string $outputlang = ''): string {
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
            // Cross-plugin task fallbacks (entities, shopping_cart – not in the booking registry).
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
     * Resolve the preview option id from thread metadata.
     *
     * @param  int $threadid
     * @param  int $cmid
     * @return int
     */
    private function resolve_preview_option_id(int $threadid, int $cmid): int {
        global $DB;

        $optionid = (int)($this->store->get_thread_metadata_value($threadid, 'lastworkedoptionid') ?? 0);
        if ($optionid <= 0) {
            return 0;
        }

        $cm = get_coursemodule_from_id('booking', $cmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return 0;
        }

        return $DB->record_exists('booking_options', ['id' => $optionid, 'bookingid' => (int)$cm->instance])
            ? $optionid
            : 0;
    }

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
     * @param  int    $threadid
     * @param  string $outputlang
     * @return array
     */
    private function clarification_result(string $message, int $threadid, string $outputlang): array {
        return [
            'response_type'            => 'clarification',
            'message'                  => $message,
            'commands'                 => [],
            'ambiguities'              => [],
            'ambiguity_options'        => [],
            'errors'                   => [],
            'attempted_tasks'          => [],
            'issue_codes'              => [],
            'pending_confirmation_code' => '',
            'used_triggers'            => [],
            'runid'                    => 0,
            'results'                  => [],
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
