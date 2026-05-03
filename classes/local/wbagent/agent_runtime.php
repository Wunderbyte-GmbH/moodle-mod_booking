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

use core_text;
use mod_booking\local\wbagent\agent_state;

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

    /** @var agent_decision_service */
    private agent_decision_service $decisionsvc;

    /**
     * Constructor.
     *
     * @param task_registry         $registry
     * @param orchestrator          $orchestrator
     * @param conversation_store    $store
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
        $this->decisionsvc  = new agent_decision_service($registry, $store, $authz);
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
        $result = $this->run_internal($threadid, $cmid, $userid, []);
        $this->persist_assistant_message($threadid, $result);
        return $result;
    }

    /**
     * Multi-step agent loop entry point.
     *
     * Implements a true internal agent loop: the LLM plans, tools execute,
     * observations are accumulated, and the next LLM call receives those
     * observations as structured context — all within a single request.
     *
     * Loop contract:
     * - Internal steps (execution_result) do NOT persist messages.
     * - Only the final step that requires user interaction persists ONE message.
     * - Observations from each step are fed back to the LLM via the orchestrator,
     *   never stored in the conversation DB.
     * - Mutating commands are never auto-executed; they always stop the loop for
     *   user confirmation.
     *
     * @param  int $threadid
     * @param  int $cmid
     * @param  int $userid
     * @param  int $maxsteps Override for MAX_LOOP_STEPS (0 = use constant).
     * @return array Final normalized result (one persistent assistant message written).
     */
    public function run_loop(int $threadid, int $cmid, int $userid, int $maxsteps = 0): array {
        $limit = ($maxsteps > 0) ? $maxsteps : self::MAX_LOOP_STEPS;
        $state = agent_state::make($limit);

        for ($step = 0; $step < $limit; $step++) {
            $state->current_step = $step + 1;

            // Plan + route — does NOT persist anything.
            $result = $this->run_internal($threadid, $cmid, $userid, $state->get_observations());

            $result['loop_step']      = $step + 1;
            $result['loop_max_steps'] = $limit;

            // If the step executed read-only tools successfully, record the observation
            // and continue the internal loop — the LLM will see the results next step.
            if ((string)($result['response_type'] ?? '') === 'execution_result') {
                $observation = result_payload_summarizer::for_observation(
                    $result['results'] ?? [],
                    $step + 1
                );
                $state->record_step(
                    $result['commands'] ?? [],
                    $result['results'] ?? [],
                    $observation
                );
                // Do NOT persist — continue to next internal step.
                continue;
            }

            // Any other response type requires user interaction or signals completion.
            // Persist the SINGLE final assistant message and return.
            $this->persist_assistant_message($threadid, $result);
            return $result;
        }

        // Maximum steps reached without a user-interaction response.
        $result = $this->max_steps_exceeded_result(current_language(), $limit);
        $this->persist_assistant_message($threadid, $result);
        return $result;
    }

    // -------------------------------------------------------------------------
    // Private: loop helpers.

    /**
     * Execute one internal agent step: plan (LLM) + decide (routing), with NO persistence.
     *
     * Unlike run(), this method never writes an assistant message to the DB.
     * It is the building block for run_loop() and is also used by run() (which
     * adds the single persistence call afterwards).
     *
     * @param  int      $threadid     Thread id.
     * @param  int      $cmid         Course-module id.
     * @param  int      $userid       User id.
     * @param  string[] $observations Structured observation strings from prior internal steps.
     *                                Injected into the LLM prompt — never stored in the DB.
     * @return array Normalized result (not yet persisted).
     */
    private function run_internal(int $threadid, int $cmid, int $userid, array $observations): array {
        $previewoptionid = $this->resolve_preview_option_id($threadid, $cmid);
        $triggerregistry = new message_trigger_registry($this->registry);

        // Plan: call the LLM once, passing any accumulated observations.
        $result = $this->orchestrator->process($threadid, $cmid, $userid, $observations);

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
            $fallback = ai_error_classifier::classify_from_db($userid, $cmid);
            if (!empty($fallback)) {
                $result['issue_codes'] = $fallback;
            }
        }

        // Decide: route through the confirmation / trigger / execution decision tree.
        $result = $this->decisionsvc->process($result, $threadid, $cmid, $userid, $outputlang, $previewoptionid);

        // Override message for token/subscription issues.
        $issuecodes = array_map(
            static fn($code): string => trim(core_text::strtoupper((string)$code)),
            (array)($result['issue_codes'] ?? [])
        );
        if (!empty(array_intersect(self::TOKEN_SUBSCRIPTION_ISSUE_CODES, $issuecodes))) {
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

        return $result;
    }

    /**
     * Persist the final assistant message to the conversation store.
     *
     * Called exactly ONCE per user-visible turn — either by run() directly
     * or by run_loop() when the loop terminates with a final response.
     *
     * @param  int   $threadid
     * @param  array $result
     * @return void
     */
    private function persist_assistant_message(int $threadid, array $result): void {
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
