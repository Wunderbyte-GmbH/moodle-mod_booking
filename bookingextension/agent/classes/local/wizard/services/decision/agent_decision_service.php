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
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\decision;

use core_text;
use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\services\risk\risk_class_resolver;
use bookingextension_agent\local\wizard\booking_issue_code_provider;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\executor;
use bookingextension_agent\local\wizard\interfaces\issue_code_provider_interface;
use bookingextension_agent\local\wizard\privacy_anonymizer;
use bookingextension_agent\local\wizard\skill_registry;
use bookingextension_agent\local\wizard\queue\queue_manager;
use bookingextension_agent\local\wizard\queue\observation_builder;
use bookingextension_agent\local\wizard\services\execution\execution_feedback_service;
use bookingextension_agent\local\wizard\services\execution_observation_ledger;
use bookingextension_agent\local\wizard\services\language_policy_service;
use bookingextension_agent\local\wizard\services\localized_string_service;
use bookingextension_agent\local\wizard\services\preflight_pipeline;
use bookingextension_agent\local\wizard\services\preview_passthrough;
use bookingextension_agent\local\wizard\services\runtime_context_block_builder;
use bookingextension_agent\local\wizard\services\queue_transition_service;
use bookingextension_agent\local\wizard\services\requester_reference;
use bookingextension_agent\local\wizard\services\security\authorization_service;
use bookingextension_agent\local\wizard\services\pending_intent_service;
use bookingextension_agent\local\wizard\services\pending_queue_command_service;

/**
 * Routing and decision layer for the agent runtime.
 *
 * Owns ALL routing logic previously embedded in AgentRuntime::decide():
 *  - Preview shortcuts
 *  - Confirmation flow (confirm_pending state machine)
 *  - Duplicate-title overrides
 *  - Lookup-safety mutation guard
 *  - Mutating command promotion from skill_call → confirmation_request
 *  - Read-only command auto-execution
 *  - Pre-validation of confirmation commands (with deanonymization)
 *  - Teacher autocreate augmentation
 *  - Pending intent storage and clearing
 *
 * AgentRuntime delegates entirely to this class so it remains a thin
 * coordinator that owns only the loop, state, and persistence.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class agent_decision_service {
    /** Issue code for a preflight clarification that offers the existing choices (wave 30). */
    public const CHOICES_OFFERED_CODE = 'PREFLIGHT_CHOICES_OFFERED';

    /** Response type constant used in routing decisions. */
    private const RESPONSE_TYPE_SKILL_CALL = 'skill_call';

    /** Response type constant used in routing decisions. */
    private const RESPONSE_TYPE_CONFIRMATION_REQUEST = 'confirmation_request';

    /** Response type constant used in routing decisions. */
    private const RESPONSE_TYPE_CONFIRM_PENDING = 'confirm_pending';

    /** Response type constant used in routing decisions. */
    private const RESPONSE_TYPE_CLARIFICATION = 'clarification';

    /** @var skill_registry */
    private skill_registry $registry;

    /** @var conversation_store */
    private conversation_store $store;

    /** @var authorization_service */
    private authorization_service $authz;

    /** @var issue_code_provider_interface */
    private issue_code_provider_interface $issuecodeprovider;

    /** @var queue_manager */
    private queue_manager $queuesvc;

    /** @var observation_builder */
    private observation_builder $observationbuilder;

    /** @var preflight_pipeline */
    private preflight_pipeline $preflightpipeline;

    /** @var language_policy_service */
    private language_policy_service $languagepolicy;

    /** @var pending_intent_service */
    private pending_intent_service $pendingintentsvc;

    /** @var queue_transition_service */
    private queue_transition_service $queuetransitionsvc;

    /** @var pending_queue_command_service */
    private pending_queue_command_service $pendingqueuecommandsvc;

    /**
     * Constructor.
     *
     * @param skill_registry                   $registry
     * @param conversation_store              $store
     * @param authorization_service          $authz
     * @param issue_code_provider_interface|null $issuecodeprovider
     */
    public function __construct(
        skill_registry $registry,
        conversation_store $store,
        authorization_service $authz,
        ?issue_code_provider_interface $issuecodeprovider = null
    ) {
        $this->registry = $registry;
        $this->store    = $store;
        $this->authz    = $authz;
        $this->issuecodeprovider = $issuecodeprovider ?? new booking_issue_code_provider();
        $this->queuesvc = new queue_manager($store, $registry);
        $this->observationbuilder = new observation_builder();
        $this->preflightpipeline = new preflight_pipeline($registry, $store);
        $this->languagepolicy = new language_policy_service();
        $this->pendingintentsvc = new pending_intent_service($store);
        $this->queuetransitionsvc = new queue_transition_service();
        $this->pendingqueuecommandsvc = new pending_queue_command_service($this->queuesvc);
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
     * @param  int    $contextid
     * @param  int    $userid
     * @param  string $outputlang
     * @return array  Normalized result ready for persistence or loop continuation.
     */
    public function process(
        array $result,
        int $threadid,
        int $contextid,
        int $userid,
        string $outputlang
    ): array {
        // Context id is provided directly by the caller (context-agnostic decision path).
        // Stale blocked_confirmation items are always expired (no admin toggle).
        $expiredblocked = $this->queuesvc->fail_expired_blocked_items($threadid);
        if ($expiredblocked > 0) {
            $result['issue_codes'] = array_values(array_unique(array_merge(
                (array)($result['issue_codes'] ?? []),
                ['BLOCKED_CONFIRMATION_TIMEOUT']
            )));
        }
        // 1b. Step-8 guard: when a confirmation intent is pending, block unrelated
        // new intents until the user either confirms or explicitly discards.
        $pendingintent = $this->pendingintentsvc->get($threadid);
        if ($pendingintent !== null && $this->should_block_new_intent_while_pending($result)) {
            return $this->build_pending_resolution_clarification($result, $pendingintent, $threadid, $outputlang);
        }

        // 2. Handle explicit user confirmation of pending intent.
        if ((string)($result['response_type'] ?? '') === self::RESPONSE_TYPE_CONFIRM_PENDING) {
            return $this->handle_confirm_pending($result, $threadid, $contextid, $userid, $outputlang);
        }

        // 4. Harden: if the LLM incorrectly used skill_call for a mutating command, promote.
        if ($this->has_mutating_commands($result) && ($result['response_type'] ?? '') === self::RESPONSE_TYPE_SKILL_CALL) {
            $result['response_type'] = self::RESPONSE_TYPE_CONFIRMATION_REQUEST;
            $normalizedmsg = core_text::strtolower(trim((string)($result['message'] ?? '')));
            if (in_array($normalizedmsg, ['executing', 'executing.', 'running', 'running.'], true)) {
                $result['message'] = '';
            }
        }

        // 5. Execute read-only commands immediately; confirmation-gate mutating ones.
        if (
            in_array(
                (string)($result['response_type'] ?? ''),
                [
                    self::RESPONSE_TYPE_SKILL_CALL,
                    self::RESPONSE_TYPE_CONFIRMATION_REQUEST,
                ],
                true
            )
        ) {
            $result = $this->handle_command_routing($result, $threadid, $contextid, $userid, $outputlang);
        }

        // 6. Run preflight on confirmation commands: resolve entities, detect conflicts,
        // update commands to carry prepared_input, route based on preflight result.
        if (($result['response_type'] ?? '') === self::RESPONSE_TYPE_CONFIRMATION_REQUEST && !empty($result['commands'])) {
            $result = $this->handle_preflight($result, $threadid, $contextid, $userid, $outputlang);
        }

        // 7. Store / clear pending intent.
        if (($result['response_type'] ?? '') === self::RESPONSE_TYPE_CONFIRMATION_REQUEST && !empty($result['commands'])) {
            $result['pending_confirmation_code'] = $this->persist_pending_intent_pointer(
                $threadid,
                $userid,
                $contextid,
                $result['queue_item_ids'] ?? []
            );
        } else {
            $this->pendingintentsvc->clear($threadid);
            $result['pending_confirmation_code'] = '';
        }

        return $result;
    }

    /**
     * Decide whether current model output should be blocked while a pending
     * confirmation intent exists.
     *
     * @param array $result
     * @return bool
     */
    private function should_block_new_intent_while_pending(array $result): bool {
        $responsetype = (string)($result['response_type'] ?? '');
        if ($responsetype === self::RESPONSE_TYPE_CONFIRM_PENDING) {
            return false;
        }

        if (!empty((array)($result['commands'] ?? []))) {
            return true;
        }

        return in_array(
            $responsetype,
            [self::RESPONSE_TYPE_SKILL_CALL, self::RESPONSE_TYPE_CONFIRMATION_REQUEST, 'sufficient'],
            true
        );
    }

    /**
     * Build the clarification response instructing the user to resolve the
     * current pending confirmation before starting a new intent.
     *
     * @param array $result
     * @param array $pendingintent
     * @param int $threadid
     * @param string $outputlang
     * @return array
     */
    private function build_pending_resolution_clarification(
        array $result,
        array $pendingintent,
        int $threadid,
        string $outputlang
    ): array {
        // Phase 2: queue is single source of truth; no fallback to stored commands.
        $pendingcommands = $this->pendingqueuecommandsvc->build_mutating_commands_from_pending_intent($pendingintent, $threadid);
        $summary = $this->build_pending_intent_summary($pendingcommands, $outputlang);
        $confirmationcode = trim((string)($pendingintent['confirmationcode'] ?? ''));
        $message = $this->localized(
            'ai_pending_intent_resolution_required',
            (object)[
                'action' => $summary !== ''
                    ? $summary
                    : $this->localized('ai_status_confirm_default', null, $outputlang),
                'code' => $confirmationcode !== '' ? $confirmationcode : '-',
            ],
            $outputlang
        );

        return $this->clarification_result_with_context(
            $message,
            $result,
            [
                'attempted_skills' => array_values(array_unique((array)($result['attempted_skills'] ?? []))),
                'issue_codes' => array_values(array_unique(array_merge(
                    (array)($result['issue_codes'] ?? []),
                    ['PENDING_CONFIRMATION_EXISTS']
                ))),
                'pending_confirmation_code' => $confirmationcode,
            ]
        );
    }

    /**
     * Create a concise human-readable summary of the currently pending commands.
     *
     * @param array $pendingcommands
     * @param string $outputlang
     * @return string
     */
    private function build_pending_intent_summary(array $pendingcommands, string $outputlang): string {
        if (empty($pendingcommands)) {
            return '';
        }

        return trim($this->build_fallback_message([
            'response_type' => self::RESPONSE_TYPE_CONFIRMATION_REQUEST,
            'commands' => $pendingcommands,
            'message' => '',
        ], $outputlang));
    }

    /**
     * Build a human note naming the target context(s) a mutation will act on.
     *
     * The user must always see WHERE a write will be carried out before confirming — including the
     * common same-course case (e.g. "create a label here"). A command that carries no explicit
     * operating_contextid falls back to the ambient context, so the target is named in every
     * mutation confirmation, not only for cross-context targets. When the context resolves to a
     * course, the note names the course with its id so a mis-resolved course is caught before the
     * write happens.
     *
     * @param  array  $commands       The mutating commands (may carry operating_contextid).
     * @param  int    $ambientcontextid The context the chat/thread lives in.
     * @param  string $outputlang
     * @return string
     */
    private function build_operating_context_note(array $commands, int $ambientcontextid, string $outputlang = ''): string {
        $labels = [];
        foreach ($commands as $command) {
            if (!is_array($command)) {
                continue;
            }
            // Same-course mutations carry no explicit operating context — fall back to the ambient
            // context so the target course is still named (this is the case point 4 must catch).
            $operatingcontextid = (int)($command['operating_contextid'] ?? 0);
            if ($operatingcontextid <= 0) {
                $operatingcontextid = $ambientcontextid;
            }
            if ($operatingcontextid <= 0) {
                continue;
            }
            try {
                $context = \context::instance_by_id($operatingcontextid, IGNORE_MISSING);
            } catch (\Throwable $e) {
                $context = null;
            }
            if ($context) {
                $labels[$operatingcontextid] = $this->describe_target_context($context, $outputlang);
            }
        }

        if (empty($labels)) {
            return '';
        }

        return $this->localized('agent_confirm_operating_context_note', implode(', ', array_unique($labels)), $outputlang);
    }

    /**
     * Human label for a mutation's target context, naming the course (with its id) when resolvable
     * so a wrong course shows up in the confirmation before the write.
     *
     * @param  \context $context
     * @param  string   $outputlang
     * @return string
     */
    private function describe_target_context(\context $context, string $outputlang = ''): string {
        // Module target: name the ACTIVITY itself (with its course for orientation), not the
        // enclosing course. get_course_context() collapses a module context to its course, so a
        // mutation of the option in activity "no content" was confirmed as "course 'ai' (ID 11)"
        // (thread 590) — the user could not see WHICH activity was touched.
        if ($context instanceof \context_module) {
            try {
                [$course, $cm] = get_course_and_cm_from_cmid((int)$context->instanceid);
                return $this->localized('agent_confirm_target_activity', (object)[
                    'activity' => format_string($cm->name),
                    'course' => format_string($course->fullname),
                    'id' => (int)$course->id,
                ], $outputlang);
            } catch (\Throwable $e) {
                // Fall through to the course/generic naming when the module cannot be resolved.
                unset($e);
            }
        }

        $coursecontext = $context->get_course_context(false);
        if ($coursecontext) {
            $courseid = (int)$coursecontext->instanceid;
            // The front-page "course" (SITEID) is the ambient context of a site/system-level
            // action; it must read as the site, not an ordinary course "…(ID 1)" (thread 590).
            if ($courseid == SITEID) {
                return $this->localized('agent_confirm_target_site', null, $outputlang);
            }
            try {
                $course = get_course($courseid);
                return $this->localized('agent_confirm_target_course', (object)[
                    'name' => format_string($course->fullname),
                    'id' => (int)$course->id,
                ], $outputlang);
            } catch (\Throwable $e) {
                // Fall through to the generic context name when the course cannot be loaded.
                unset($e);
            }
        }

        // A user or block context is never a real place for a mutation — it is only the ambient
        // chat context a context-free system skill (e.g. course.create_course, which creates the
        // course in a category regardless of where the chat runs) was invoked from. Render it as the
        // site instead of "User: Admin User", which is doubly wrong: a user profile is not where the
        // write lands (thread 591, create_course ran in the admin's CONTEXT_USER). The actual target
        // (the course category) is named by the P7 proposed-data block, not this location note.
        if ($context instanceof \context_user || $context instanceof \context_block) {
            return $this->localized('agent_confirm_target_site', null, $outputlang);
        }
        return $context->get_context_name();
    }

    /**
     * Build a deterministic fallback message per response type and language.
     *
     * @param  array  $result
     * @param  string $outputlang
     * @return string
     */
    private function build_fallback_message(array $result, string $outputlang = ''): string {
        $responsetype = (string)($result['response_type'] ?? '');
        $commands = $result['commands'] ?? [];
        $firstskill = '';
        if (is_array($commands) && !empty($commands) && is_array($commands[0] ?? null)) {
            $firstskill = (string)($commands[0]['skill'] ?? '');
        }

        if ($responsetype === 'confirmation_request') {
            if ($firstskill !== '') {
                $skill = $this->registry->get_skill($firstskill);
                if ($skill !== null) {
                    $key = (string)($skill->get_schema()['fallback_confirm_string_key'] ?? '');
                    if ($key !== '') {
                        return localized_string_service::get($key, 'bookingextension_agent', null, $outputlang);
                    }
                }
            }
            return localized_string_service::get('ai_status_confirm_default', 'bookingextension_agent', null, $outputlang);
        }

        if ($responsetype === 'skill_call') {
            if ($firstskill !== '') {
                $skill = $this->registry->get_skill($firstskill);
                if ($skill !== null) {
                    $key = (string)($skill->get_schema()['fallback_skillcall_string_key'] ?? '');
                    if ($key !== '') {
                        return localized_string_service::get($key, 'bookingextension_agent', null, $outputlang);
                    }
                }
            }
            // Any skill not registered in the booking registry (e.g. cross-plugin skills)
            // falls back to the generic default string.
            return localized_string_service::get('ai_status_skillcall_default', 'bookingextension_agent', null, $outputlang);
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
     * @param  int    $contextid
     * @param  int    $userid
     * @param  string $outputlang
     * @return array
     */
    private function handle_confirm_pending(
        array $result,
        int $threadid,
        int $contextid,
        int $userid,
        string $outputlang
    ): array {
        // Context id is provided directly by the caller (context-agnostic decision path).
        $modelmessage = trim((string)($result['message'] ?? ''));
        $normalizedmessage = core_text::strtolower($modelmessage);
        $isplaceholdermessage = in_array($normalizedmessage, ['executing', 'executing.', 'running', 'running.'], true);
        $pendingintent = $this->pendingintentsvc->get($threadid);

        if ($pendingintent === null) {
            return $this->build_confirm_pending_no_intent_fallback(
                $result,
                $modelmessage,
                $isplaceholdermessage,
                $outputlang,
                $threadid
            );
        }

        // Phase 2: queue is single source of truth; no fallback to stored commands.
        $confirmcommands = $this->pendingqueuecommandsvc->build_mutating_commands_from_pending_intent(
            $pendingintent,
            $threadid
        );
        if (empty($confirmcommands)) {
            return $this->build_confirm_pending_no_intent_fallback(
                $result,
                $modelmessage,
                $isplaceholdermessage,
                $outputlang,
                $threadid
            );
        }

        // Re-run preflight so that prepared_input is refreshed for the executor.
        $preflightresult = $this->preflightpipeline->run(
            $confirmcommands,
            $threadid,
            $contextid,
            $userid
        );
        if (trim((string)($preflightresult['status'] ?? '')) !== 'pass') {
            $invalidmessage = implode(' ', array_values(array_unique(array_filter((array)($preflightresult['errors'] ?? [])))));
            return $this->clarification_result_with_context(
                $invalidmessage !== '' ? $invalidmessage
                    : localized_string_service::get('ai_no_pending_intent', 'bookingextension_agent', null, $outputlang),
                $result,
                [
                    'errors' => $preflightresult['errors'] ?? [],
                    'attempted_skills' => $preflightresult['attempted_skills'] ?? [],
                    'issue_codes' => $preflightresult['issue_codes'] ?? [],
                ]
            );
        }

        // Use the prepared commands (with resolved inputs) for the pending intent.
        $preparedcommands = $preflightresult['prepared_commands'];
        $queueitemids = $this->normalize_queue_item_ids($pendingintent['queue_item_ids'] ?? []);
        foreach ($preparedcommands as $idx => $preparedcommand) {
            $queueitemid = (string)($queueitemids[$idx] ?? '');
            $preparedinput = is_array($preparedcommand['input'] ?? null) ? (array)$preparedcommand['input'] : [];
            if ($queueitemid === '' || empty($preparedinput)) {
                continue;
            }
            $this->queuesvc->set_prepared_input(
                $threadid,
                $queueitemid,
                $contextid,
                $preparedinput,
                (int)($preparedcommand['operating_contextid'] ?? 0)
            );
        }

        $preparedcommands = $this->apply_execution_guard_tokens(
            $preparedcommands,
            $contextid
        );

        $confirmmessage = $this->localized('ai_confirm_pending_intent', null, $outputlang);
        $confirmationcode = $this->persist_pending_intent_pointer(
            $threadid,
            $userid,
            $contextid,
            $queueitemids
        );

        return [
            'response_type'             => 'confirmation_request',
            'message'                   => $confirmmessage,
            'commands'                  => $preparedcommands,
            'ambiguities'               => [],
            'ambiguity_options'         => [],
            'errors'                    => [],
            'attempted_skills'          => [],
            'issue_codes'               => [],
            'pending_confirmation_code' => $confirmationcode,
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
     * @param  int    $contextid
     * @param  int    $userid
     * @param  string $outputlang
     * @return array
     */
    private function handle_command_routing(
        array $result,
        int $threadid,
        int $contextid,
        int $userid,
        string $outputlang
    ): array {
        $commands = $this->inject_output_language_into_commands((array)($result['commands'] ?? []), $outputlang);
        $nextstepintent = trim((string)($result['next_step_intent'] ?? ''));
        if (!is_array($commands) || empty($commands)) {
            return $result;
        }
        // Structural self-reference resolution (#2246, #2569): a person parameter the constructor filled
        // with the requester's own identity (anonymized token, e-mail, username, name) carries the
        // requester marker instead, which every person resolver reads as the acting user. Dropping the
        // field only worked where an empty person means the requester; a trainer or a restriction list
        // then silently lost the requester.
        $commands = $this->mark_self_references($commands, $threadid, $userid);

        $split = $this->split_commands_by_mutability($commands);
        $readonlycommands = $split['readonly'];
        $mutatingcommands = $split['mutating'];
        $readonlyqueueids = [];
        $mutatingqueueids = [];
        $readonlyexecution = null;

        // Queue ingestion records commands before preflight; mutating status is
        // assigned later from the preflight decision.
        $runid = 0;
        if (is_int($result['runid'] ?? null)) {
            $runid = (int)$result['runid'];
        }
        $stepid = (int)($result['loop_step'] ?? 0);

        // Enqueue planned placeholders from selector's planned_steps output.
        // Only on the first multi-step turn — skip if placeholders already exist.
        // Track whether placeholders existed BEFORE this turn so we know whether the
        // current command is replacing a planned step (subsequent turns) or is the
        // initial step that created the plan (first turn).
        $hadplaceholders = $this->queuesvc->has_planned_placeholders($threadid);
        $plannedsteps = (array)($result['planned_steps'] ?? []);
        if (!empty($plannedsteps) && !$hadplaceholders) {
            foreach ($plannedsteps as $plannedstep) {
                $intent = trim((string)($plannedstep['intent'] ?? $plannedstep));
                if ($intent !== '') {
                    $this->queuesvc->enqueue_placeholder($threadid, $runid, $stepid, $intent);
                }
            }
        }

        foreach ($readonlycommands as $readonlycommand) {
            $queued = $this->queuesvc->enqueue_command(
                $threadid,
                $runid,
                $stepid,
                (array)$readonlycommand,
                'readonly',
                'ready'
            );
            $readonlyqueueids[] = (string)($queued['queue_item_id'] ?? '');
        }

        // Anonymizer person-reference gate for the read-only chat path (#2363). Read-only
        // commands never enter the preflight pipeline here (R0: no queue layers, no
        // confirmation), so the #2226 D3 gate — built for exactly these skills — has to run
        // before execution: a low-confidence single-word token bound to a person field must
        // end the turn as a clarification (decision chips) instead of being de-anonymized to a
        // real, possibly foreign, user. Evaluated on the RAW (still anonymized) input.
        $personclarification = $this->gate_readonly_person_references(
            $readonlycommands,
            $readonlyqueueids,
            $threadid,
            $userid,
            $result
        );
        if ($personclarification !== null) {
            return $personclarification;
        }

        // Ambiguous-target gate for the read-only chat path (flowchart PP_RUN): a read-only
        // command never blocks on target resolution — an unresolvable target falls back to the
        // ambient context (thread 515) — but a GENUINELY ambiguous one ends the turn as a
        // clarification that lists the candidates, the same list the preflight shows.
        $targetclarification = $this->gate_readonly_ambiguous_targets(
            $readonlycommands,
            $readonlyqueueids,
            $threadid,
            $contextid,
            $userid,
            $result
        );
        if ($targetclarification !== null) {
            return $targetclarification;
        }

        // Exactly-once cursor for confirm CONTINUATION frames (audit 554): a nested planner
        // frame spawned by confirm_run_service exists solely to advance the already-confirmed
        // plan. A mutating command in such a frame is legitimate only while un-consumed
        // placeholders back it (or the frame declares a NEW plan via planned_steps). Without
        // either, it is a re-derive of an already-executed step — thread 554 enqueued Jour 3-5
        // a second time exactly this way — and is refused instead of enqueued.
        $iscontinuation = !empty($this->store->get_thread_metadata_value($threadid, '_confirm_continuation'));
        if (
            $iscontinuation
            && !empty($mutatingcommands)
            && !$hadplaceholders
            && empty($plannedsteps)
        ) {
            return [
                'response_type' => 'clarification',
                'message' => localized_string_service::get(
                    'ai_plan_completed_mutation_blocked',
                    'bookingextension_agent',
                    null,
                    $outputlang
                ),
                'commands' => [],
                'queue_item_ids' => [],
                'ambiguities' => [],
                'errors' => [],
                'issue_codes' => array_values(array_unique(array_merge(
                    (array)($result['issue_codes'] ?? []),
                    ['PLAN_COMPLETED_MUTATION_BLOCKED']
                ))),
            ];
        }

        $firstmutatingenqueued = false;
        foreach ($mutatingcommands as $idx => $mutatingcommand) {
            $status = 'queued';
            $dependson = array_values(array_map('strval', (array)($mutatingcommand['depends_on'] ?? [])));
            if ($idx > 0 && !empty($mutatingqueueids[$idx - 1])) {
                $dependson[] = (string)$mutatingqueueids[$idx - 1];
            }
            $dependson = array_values(array_unique(array_filter($dependson)));
            // Queue depends_on links are always validated as an acyclic graph (no admin toggle).
            $existingitems = $this->queuesvc->get_queue_items($threadid);
            if (!$this->queuesvc->validate_depends_on_is_dag($existingitems, $dependson)) {
                $result['issue_codes'] = array_values(array_unique(array_merge(
                    (array)($result['issue_codes'] ?? []),
                    ['DEPENDENCY_CYCLE']
                )));
                $status = 'failed';
            }
            $queued = $this->queuesvc->enqueue_command(
                $threadid,
                $runid,
                $stepid,
                (array)$mutatingcommand,
                'mutating',
                $status,
                $dependson
            );
            $mutatingqueueids[] = (string)($queued['queue_item_id'] ?? '');
            // Bind (not consume) the oldest planned placeholder to the first real mutating
            // command of the turn: the placeholder leaves the pending list while the command
            // is in flight and settles with its terminal state — succeeded only on succeeded
            // execution, back to planned on a preflight clarification (F5, threads 544/589;
            // the old consume-at-enqueue marked it succeeded at staging time, so a step whose
            // command never executed vanished from the plan while the queue claimed success).
            // On the first multi-step turn ($hadplaceholders=false) the current command IS the
            // initial step — placeholders represent future steps only, so nothing is bound.
            if (!$firstmutatingenqueued) {
                if ($hadplaceholders) {
                    $this->queuesvc->bind_next_placeholder(
                        $threadid,
                        (string)($queued['queue_item_id'] ?? '')
                    );
                }
                $firstmutatingenqueued = true;
            }
        }

        if (!empty($readonlycommands)) {
            $preflighted = $this->preflight_readonly_commands(
                $readonlycommands,
                $readonlyqueueids,
                $threadid,
                $contextid,
                $userid,
                $outputlang,
                $result
            );
            if (($preflighted['clarification'] ?? null) !== null) {
                return $preflighted['clarification'];
            }
            $readonlycommands = $preflighted['commands'];
            $readonlyexecution = $this->execute_readonly_commands(
                $readonlycommands,
                $readonlyqueueids,
                $threadid,
                $contextid,
                $userid,
                $outputlang,
                $nextstepintent
            );
        }

        if (!empty($mutatingcommands)) {
            // Write operations remain confirmation-gated.
            $result['response_type'] = 'confirmation_request';
            // Phase 2 T5: include ALL mutating commands and ALL queue item ids so the
            // ai_confirm_run call can execute the full batch in a single round-trip.
            $result['commands'] = array_values(
                array_filter($mutatingcommands, static fn($e): bool => is_array($e))
            );
            $result['queue_item_ids'] = array_values(
                array_filter($mutatingqueueids, static fn($id): bool => $id !== '')
            );

            $confirmmessage = trim((string)($result['message'] ?? ''));
            if ($confirmmessage === '') {
                $confirmmessage = $this->build_fallback_message($result, $outputlang);
            }

            // The target-context note (WHERE the write happens) is appended later in handle_preflight,
            // once the prepared commands carry their resolved operating_contextid — naming it here from
            // the raw commands would mislabel cross-context targets as the ambient context.

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

            // Mark non-first staged mutating items as skipped if the first fails later.
            // Current runtime stages only one mutation command per confirmation step.
            if (count($mutatingqueueids) > 1) {
                $result['issue_codes'] = array_values(array_unique(array_merge(
                    (array)($result['issue_codes'] ?? []),
                    ['QUEUE_MUTATION_STAGED']
                )));
            }
        } else if (is_array($readonlyexecution)) {
            $result = $readonlyexecution;
        }

        return $result;
    }

    /**
     * Run preflight validation on confirmation commands.
     *
     * Calls skill->preflight() for each command, which:
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
     * @param  int    $contextid
     * @param  int    $userid
     * @param  string $outputlang
     * @return array
     */
    private function handle_preflight(
        array $result,
        int $threadid,
        int $contextid,
        int $userid,
        string $outputlang
    ): array {
        // Context id is provided directly by the caller (context-agnostic decision path).
        $commands = (array)($result['commands'] ?? []);

        $preflightresult = $this->with_output_language(
            $outputlang,
            fn() => $this->preflightpipeline->run(
                $commands,
                $threadid,
                $contextid,
                $userid
            )
        );
        $preparedcommands = array_values(array_filter(
            (array)($preflightresult['prepared_commands'] ?? []),
            static fn($command): bool => is_array($command)
        ));
        $status = trim((string)($preflightresult['status'] ?? ''));
        $allissuecodes = array_values(array_unique(array_map('strval', (array)($preflightresult['issue_codes'] ?? []))));
        $attemptedskills = array_values(array_unique(array_map('strval', (array)($preflightresult['attempted_skills'] ?? []))));
        $allissues = array_values(array_filter(
            (array)($preflightresult['issues'] ?? []),
            static fn($issue): bool => is_array($issue)
        ));
        $blockingerrors = array_values(array_unique(array_map('strval', (array)($preflightresult['errors'] ?? []))));
        $hasclarificationissues = false;
        foreach ($allissues as $issue) {
            if (trim((string)($issue['severity'] ?? '')) === 'needs_clarification') {
                $hasclarificationissues = true;
                break;
            }
        }
        $v2result = [
            'status' => $status,
            'issue_codes' => $allissuecodes,
            'blocking_layer' => trim((string)($preflightresult['blocking_layer'] ?? '')),
            'retry_after_ms' => (int)($preflightresult['retry_after_ms'] ?? 0),
            'retry_count' => (int)($preflightresult['retry_count'] ?? 0),
            'duration_ms' => (int)($preflightresult['duration_ms'] ?? 0),
            // Lets the transition service label clarification-class fails distinctly
            // (PREFLIGHT_NEEDS_CLARIFICATION), which drives the F5 placeholder settle.
            'has_clarification_issues' => $hasclarificationissues,
        ];
        $queueitemids = $this->normalize_queue_item_ids($result['queue_item_ids'] ?? []);
        $autoconfirmmode = $this->store->is_confirmation_allowed_for_thread(
            $userid,
            $contextid,
            $threadid
        );
        $this->queuetransitionsvc->apply_preflight_decision(
            $this->queuesvc,
            $threadid,
            $queueitemids,
            $status,
            $allissuecodes,
            $blockingerrors,
            $v2result,
            $autoconfirmmode
        );
        if ($hasclarificationissues) {
            // F5 (thread 589): a step blocked on a user answer stays represented in the
            // pending plan — its bound placeholder reverts to planned, or (first-turn current
            // command, never placeholdered) a placeholder is planted at the queue front.
            $this->queuesvc->ensure_blocked_step_representation($threadid, $queueitemids);
        }
        foreach ($preparedcommands as $idx => $preparedcommand) {
            $queueitemid = trim((string)($queueitemids[$idx] ?? ''));
            $preparedinput = is_array($preparedcommand['input'] ?? null) ? (array)$preparedcommand['input'] : [];
            if ($queueitemid !== '' && !empty($preparedinput)) {
                $this->queuesvc->set_prepared_input(
                    $threadid,
                    $queueitemid,
                    $contextid,
                    $preparedinput,
                    (int)($preparedcommand['operating_contextid'] ?? 0)
                );
            }
        }

        $preparedcommands = $this->apply_execution_guard_tokens(
            $preparedcommands,
            $contextid
        );

        // If there were blocking errors, decide whether to allow confirmable continuation.
        if ($status !== 'pass') {
            // F3 user_cause channel: the user-facing wording prefers each issue's
            // user_question over its (often technical) message, and never carries the
            // planner-only "Command #N:" label — thread 586 showed the NOT_EMPTY guard's
            // message although its user_question held the actual question. The raw
            // $blockingerrors stay untouched in 'errors' for the planner/debug channels.
            $validationmessage = $this->compose_user_cause_from_issues($allissues, $blockingerrors);
            if ($status === 'retry_hint') {
                // Nothing was executed and nothing is awaiting confirmation (the queue items sit
                // in retry_waiting, no pending intent exists) — so this must NOT be a
                // confirmation_request: the UI would draw a confirm button that can only ever
                // answer "invalid or stale queue item id" (threads 544/549). The user gets the
                // localized retry message; the raw engine error text stays in 'errors' for the
                // debug meta only.
                $retrymessage = localized_string_service::get(
                    $this->languagepolicy->preflight_retry_hint_string_id(),
                    'bookingextension_agent',
                    null,
                    $outputlang
                );
                return [
                    'response_type'   => 'clarification',
                    'message'         => $retrymessage,
                    'commands'        => !empty($preparedcommands) ? $preparedcommands : (array)$result['commands'],
                    'queue_item_ids'  => $queueitemids,
                    'ambiguities'     => [],
                    'errors'          => $blockingerrors,
                    'attempted_skills' => $attemptedskills,
                    'issue_codes'     => $allissuecodes,
                ];
            }

            // The clarification flag was computed before the preflight decision was applied
            // (it also feeds the transition service's clarification-vs-hard-block labeling).
            if (
                ($status === 'soft_block' || $this->has_confirmable_prevalidation_issues($allissuecodes))
                && !$hasclarificationissues
                && !empty($preparedcommands)
            ) {
                // Only preflight-prepared commands may be staged for confirmation: they carry the
                // prepared input and the execution guard token the executor's release gate demands.
                // Raw planner commands are token-less by definition, so a Confirm button drawn over
                // them can only ever answer EXECUTION_GUARD_MISSING — same doctrine as the
                // retry_hint branch above (threads 544/549). Without this gate, a hard block
                // co-occurring with a confirmable code (duplicate signature + duplicate title)
                // staged an unexecutable confirmation; such mixes now fall through to the
                // clarification below.
                $confirmcommands = $preparedcommands;
                // Soft-confirmable: show confirmation_request with augmented message.
                $softmessage = $validationmessage !== '' ? $validationmessage : (string)$result['message'];
                $softnote = $this->build_operating_context_note($confirmcommands, $contextid, $outputlang);
                if ($softnote !== '') {
                    $softmessage = trim($softmessage) !== '' ? trim($softmessage) . "\n\n" . $softnote : $softnote;
                }
                return [
                    'response_type'   => 'confirmation_request',
                    'message'         => $softmessage,
                    'commands'        => $confirmcommands,
                    'queue_item_ids'  => $this->normalize_queue_item_ids($result['queue_item_ids'] ?? []),
                    'ambiguities'     => [],
                    'errors'          => $blockingerrors,
                    'attempted_skills' => $attemptedskills,
                    'issue_codes'     => $allissuecodes,
                ];
            }

            // Source C of the preview channel: a needs_clarification issue may carry a
            // skill-provided preview block (e.g. a form the user should fill instead of
            // answering in chat). The result dict is rebuilt several times on its way to
            // the endpoint, so the block travels via thread metadata and is consumed by
            // ai_send_message / ai_confirm_run when the final response ships.
            $clarificationpreview = preview_passthrough::extract_clarification_preview_from_issues($allissues);
            if ($clarificationpreview !== null) {
                preview_passthrough::stash_clarification_preview($this->store, $threadid, $clarificationpreview);
            }

            $clarification = [
                'response_type'   => 'clarification',
                'message'         => $validationmessage !== '' ? $validationmessage : localized_string_service::get(
                    'ai_no_pending_intent',
                    'bookingextension_agent',
                    null,
                    $outputlang
                ),
                'commands'        => [],
                'ambiguities'     => [],
                'errors'          => $blockingerrors,
                'attempted_skills' => $attemptedskills,
                'issue_codes'     => $allissuecodes,
            ];
            // Wave 30: a clarification issue that carries `candidates` offers the existing choices (a rule the
            // query did not match, thread 11716). The runtime hands them to the selector for one re-plan; if
            // that does not settle it, this clarification - with the choices in its text - is the answer.
            $offeredchoices = self::offered_choices($allissues);
            if (!empty($offeredchoices)) {
                $clarification['offered_choices'] = $offeredchoices;
                $clarification['issue_codes'] = array_values(array_unique(array_merge(
                    $allissuecodes,
                    [self::CHOICES_OFFERED_CODE]
                )));
            }
            return $clarification;
        }

        // All commands passed preflight.  Swap raw commands for prepared-input versions.
        $result['commands']      = $preparedcommands;
        $result['issue_codes']   = array_values(array_unique(array_merge(
            (array)($result['issue_codes'] ?? []),
            $allissuecodes
        )));
        $result['attempted_skills'] = $attemptedskills;

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
        }

        // Always name WHERE the write will be carried out (target course + id) on the prepared
        // commands, so a mis-resolved course is visible in the confirmation before anything is
        // created or changed.
        $targetnote = $this->build_operating_context_note($preparedcommands, $contextid, $outputlang);
        if ($targetnote !== '') {
            $base = trim((string)($result['message'] ?? ''));
            $result['message'] = $base !== '' ? $base . "\n\n" . $targetnote : $targetnote;
            $result['operating_context_label'] = $targetnote;
        }

        return $result;
    }

    /**
     * Attach deterministic execution guard tokens to mutating prepared commands.
     *
     * @param array[] $commands
     * @param int $contextid
     * @return array[]
     */
    private function apply_execution_guard_tokens(array $commands, int $contextid): array {
        foreach ($commands as &$command) {
            if (!is_array($command)) {
                continue;
            }

            $skillname = trim((string)($command['skill'] ?? ''));
            if ($skillname === '') {
                continue;
            }

            $skill = $this->registry->get_skill($skillname);
            if ($skill === null || $skill->is_read_only()) {
                unset($command['guard_token']);
                continue;
            }

            $preparedinput = is_array($command['input'] ?? null) ? (array)$command['input'] : [];
            // Bind the guard to the operating context resolved during preflight (cross-context
            // target), or the ambient context when none — must match what the executor verifies.
            $operatingcontextid = (int)($command['operating_contextid'] ?? $contextid);
            $command['guard_token'] = \bookingextension_agent\local\wizard\services\preflight_execution_gate::build_guard_token(
                $skillname,
                $operatingcontextid,
                $preparedinput
            );
        }
        unset($command);

        return $commands;
    }

    /**
     * Persist the pending-intent pointer for confirmation-bound queue items.
     *
     * @param int $threadid
     * @param int $userid
     * @param int $contextid
     * @param mixed[] $queueitemids
     * @return string
     */
    private function persist_pending_intent_pointer(
        int $threadid,
        int $userid,
        int $contextid,
        array $queueitemids
    ): string {
        $queueitemids = $this->normalize_queue_item_ids($queueitemids);
        $queueriskclasses = $this->resolve_queue_item_risk_classes($threadid, $queueitemids);
        return $this->pendingintentsvc->set(
            $threadid,
            $userid,
            $contextid,
            [
                'queue_item_ids' => $queueitemids,
                'queue_risk_classes' => $queueriskclasses,
            ]
        );
    }

    /**
     * Resolve risk classes for a set of queue items.
     *
     * @param int $threadid
     * @param string[] $queueitemids
     * @return string[]
     */
    private function resolve_queue_item_risk_classes(int $threadid, array $queueitemids): array {
        $riskclasses = [];
        foreach ($queueitemids as $queueitemid) {
            $item = $this->queuesvc->get_queue_item($threadid, $queueitemid);
            if (!is_array($item)) {
                continue;
            }

            $riskclass = trim((string)($item['risk_class'] ?? ''));
            if (!skill_risk_class::is_valid($riskclass)) {
                $riskclass = skill_risk_class::R3;
            }
            $riskclasses[] = $riskclass;
        }

        return array_values(array_unique($riskclasses));
    }

    // -------------------------------------------------------------------------
    // Private: read-only command execution.

    /**
     * Mark person parameters that carry the requester's own identity (#2246, #2569).
     *
     * Compares raw (still anonymized) values of person fields - by naming convention or declared by the skill,
     * {@see requester_reference::is_person_field()} - with the requester's anonymized identity tokens and,
     * defensively, the clear-text identifiers. A match becomes {@see requester_reference::MARKER}; lists are
     * mapped per entry. Pure equality on engine state - no word lists.
     *
     * @param array $commands
     * @param int $threadid
     * @param int $userid Acting user.
     * @return array
     */
    private function mark_self_references(array $commands, int $threadid, int $userid): array {
        $anonymizer = new privacy_anonymizer($this->store);
        $identities = requester_reference::identities($anonymizer, $this->store, $threadid, $userid);
        if (empty($identities)) {
            return $commands;
        }

        foreach ($commands as &$command) {
            if (!is_array($command) || !is_array($command['input'] ?? null)) {
                continue;
            }
            $skillname = trim((string)($command['skill'] ?? ''));
            $skill = $skillname !== '' ? $this->registry->get_skill($skillname) : null;
            foreach ($command['input'] as $field => $value) {
                if (!is_string($field) || !requester_reference::is_person_field($skill, $field, $anonymizer)) {
                    continue;
                }
                $command['input'][$field] = requester_reference::map_value($value, $identities, (string)$userid);
            }
        }
        unset($command);
        return $commands;
    }

    /**
     * Read-only path counterpart of the preflight person-reference gate (#2363).
     *
     * Returns the clarification result when a read-only command binds a low-confidence
     * anonymizer token to a person field (see preflight_pipeline::find_person_reference_collisions),
     * null when every read-only command may execute. On a hit the read-only queue items are
     * failed with a preflight-class reason and the decision-chip preview is stashed for the
     * same-turn response (source C), exactly as a preflight clarification does.
     *
     * @param array $readonlycommands Raw read-only commands of the turn.
     * @param string[] $readonlyqueueids Their queue item ids (same order).
     * @param int $threadid
     * @param int $userid
     * @param array $result Planner result (issue codes are carried over).
     * @return array|null
     */
    private function gate_readonly_person_references(
        array $readonlycommands,
        array $readonlyqueueids,
        int $threadid,
        int $userid,
        array $result
    ): ?array {
        if (empty($readonlycommands) || $threadid <= 0 || $userid <= 0) {
            return null;
        }
        foreach ($readonlycommands as $idx => $command) {
            if (!is_array($command)) {
                continue;
            }
            $skillname = trim((string)($command['skill'] ?? ''));
            $skill = $skillname !== '' ? $this->registry->get_skill($skillname) : null;
            if ($skill === null) {
                continue;
            }
            $input = is_array($command['input'] ?? null) ? (array)$command['input'] : [];
            $collisions = $this->preflightpipeline->find_person_reference_collisions($skill, $input, $threadid, $userid);
            if (empty($collisions)) {
                continue;
            }

            $issue = preflight_pipeline::build_person_reference_issue(
                (string)$collisions[0]['token'],
                (string)$collisions[0]['original']
            );
            $message = (string)$issue['message'];
            $issuecodes = array_values(array_unique(array_merge(
                (array)($result['issue_codes'] ?? []),
                [preflight_pipeline::ISSUE_ANON_PERSON_REFERENCE]
            )));

            // Nothing executes this turn: the read-only items settle like a preflight
            // clarification (failed, preflight_block) so the queue never reports them as ready.
            foreach ($readonlyqueueids as $queueitemid) {
                $queueitemid = trim((string)$queueitemid);
                if ($queueitemid === '') {
                    continue;
                }
                $this->queuetransitionsvc->to_failed(
                    $this->queuesvc,
                    $threadid,
                    $queueitemid,
                    'READONLY_PERSON_REFERENCE_CLARIFICATION',
                    [preflight_pipeline::ISSUE_ANON_PERSON_REFERENCE],
                    'preflight_block',
                    $message
                );
            }
            preview_passthrough::stash_clarification_preview($this->store, $threadid, (array)$issue['preview']);

            return [
                'response_type'    => 'clarification',
                'message'          => $message,
                'commands'         => [],
                'queue_item_ids'   => [],
                'ambiguities'      => [],
                'errors'           => ['Command #' . ((int)$idx + 1) . ': ' . $message],
                'attempted_skills' => [$skillname],
                'issue_codes'      => $issuecodes,
            ];
        }
        return null;
    }

    /**
     * Read-only counterpart of the preflight's ambiguous-target clarification (flowchart PP_RUN).
     *
     * Returns the clarification result listing the candidates when a read-only command names a
     * genuinely ambiguous target (see preflight_pipeline::find_ambiguous_target()), null when every
     * read-only command may execute. On a hit the read-only queue items are failed with a
     * preflight-class reason, exactly as the person-reference gate does.
     *
     * @param array $readonlycommands Raw read-only commands of the turn.
     * @param string[] $readonlyqueueids Their queue item ids (same order).
     * @param int $threadid
     * @param int $contextid Ambient context id.
     * @param int $userid
     * @param array $result Planner result (issue codes are carried over).
     * @return array|null
     */
    private function gate_readonly_ambiguous_targets(
        array $readonlycommands,
        array $readonlyqueueids,
        int $threadid,
        int $contextid,
        int $userid,
        array $result
    ): ?array {
        foreach ($readonlycommands as $idx => $command) {
            if (!is_array($command)) {
                continue;
            }
            $skillname = trim((string)($command['skill'] ?? ''));
            $skill = $skillname !== '' ? $this->registry->get_skill($skillname) : null;
            if ($skill === null) {
                continue;
            }
            $input = is_array($command['input'] ?? null) ? (array)$command['input'] : [];
            $issue = $this->preflightpipeline->find_ambiguous_target($skill, $input, $threadid, $contextid, $userid);
            if ($issue === null) {
                continue;
            }

            $message = (string)$issue['message'];
            $code = (string)$issue['code'];
            $issuecodes = array_values(array_unique(array_merge((array)($result['issue_codes'] ?? []), [$code])));
            foreach ($readonlyqueueids as $queueitemid) {
                $queueitemid = trim((string)$queueitemid);
                if ($queueitemid === '') {
                    continue;
                }
                $this->queuetransitionsvc->to_failed(
                    $this->queuesvc,
                    $threadid,
                    $queueitemid,
                    'READONLY_TARGET_AMBIGUOUS_CLARIFICATION',
                    [$code],
                    'preflight_block',
                    $message
                );
            }

            return [
                'response_type'    => 'clarification',
                'message'          => $message,
                'commands'         => [],
                'queue_item_ids'   => [],
                'ambiguities'      => [],
                'errors'           => ['Command #' . ((int)$idx + 1) . ': ' . $message],
                'attempted_skills' => [$skillname],
                'issue_codes'      => $issuecodes,
            ];
        }
        return null;
    }

    /**
     * Read-only commands pass their skill's preflight before they execute (F20).
     *
     * Until wave 25 the R0 path executed the planner's raw input: skills that resolve their targets in
     * run_preflight() - as the developer guide asks - had to resolve again in execute() and, when nothing
     * matched, ended as an execution error ("No user matches ...", "No message template name contains ...":
     * 22 honest errors in baseline runs 25-32) although a clarification with candidates and an issue code
     * had been available one layer earlier. The same pipeline as for mutating commands runs here: a
     * clarification issue ends the turn as a clarification and nothing runs; a pass hands execute() the
     * prepared input. Anything else (no issues, unknown status) executes as before.
     *
     * @param array $readonlycommands
     * @param array $readonlyqueueids
     * @param int $threadid
     * @param int $contextid
     * @param int $userid
     * @param string $outputlang
     * @param array $result
     * @return array{commands:array,clarification:?array}
     */
    private function preflight_readonly_commands(
        array $readonlycommands,
        array $readonlyqueueids,
        int $threadid,
        int $contextid,
        int $userid,
        string $outputlang,
        array $result
    ): array {
        $preflightresult = $this->with_output_language(
            $outputlang,
            fn() => $this->preflightpipeline->run($readonlycommands, $threadid, $contextid, $userid)
        );
        $issues = array_values(array_filter((array)($preflightresult['issues'] ?? []), 'is_array'));
        $clarificationissues = array_values(array_filter(
            $issues,
            static fn(array $issue): bool => trim((string)($issue['severity'] ?? '')) === 'needs_clarification'
        ));
        if (empty($clarificationissues)) {
            $prepared = array_values(array_filter((array)($preflightresult['prepared_commands'] ?? []), 'is_array'));
            $status = trim((string)($preflightresult['status'] ?? ''));
            return [
                'commands' => ($status === 'pass' && count($prepared) === count($readonlycommands))
                    ? $prepared
                    : $readonlycommands,
                'clarification' => null,
            ];
        }

        $issuecodes = array_values(array_unique(array_merge(
            array_map('strval', (array)($result['issue_codes'] ?? [])),
            array_map('strval', (array)($preflightresult['issue_codes'] ?? []))
        )));
        $errors = array_values(array_unique(array_map('strval', (array)($preflightresult['errors'] ?? []))));
        $message = $this->compose_user_cause_from_issues($clarificationissues, $errors);
        foreach ($readonlyqueueids as $queueitemid) {
            $queueitemid = trim((string)$queueitemid);
            if ($queueitemid === '') {
                continue;
            }
            $this->queuetransitionsvc->to_failed(
                $this->queuesvc,
                $threadid,
                $queueitemid,
                'READONLY_PREFLIGHT_CLARIFICATION',
                $issuecodes,
                'preflight_block',
                $message
            );
        }

        $clarification = [
            'response_type'    => 'clarification',
            'message'          => $message,
            'commands'         => [],
            'queue_item_ids'   => [],
            'ambiguities'      => [],
            'errors'           => $errors,
            'attempted_skills' => array_values(array_unique(array_map(
                'strval',
                (array)($preflightresult['attempted_skills'] ?? [])
            ))),
            'issue_codes'      => $issuecodes,
        ];
        // Same choice contract as for staged cards (wave 30): offered choices go to the selector once.
        $offeredchoices = self::offered_choices($clarificationissues);
        if (!empty($offeredchoices)) {
            $clarification['offered_choices'] = $offeredchoices;
            $clarification['issue_codes'] = array_values(array_unique(array_merge($issuecodes, [self::CHOICES_OFFERED_CODE])));
        }

        return [
            'commands' => $readonlycommands,
            'clarification' => $clarification,
        ];
    }

    /**
     * Execute read-only commands directly and return an execution result payload.
     *
     * @param  array  $commands
     * @param  array  $queueitemids
     * @param  int    $threadid
     * @param  int    $contextid
     * @param  int    $userid
     * @param  string $outputlang
     * @param  string $nextstepintent
     * @return array
     */
    private function execute_readonly_commands(
        array $commands,
        array $queueitemids,
        int $threadid,
        int $contextid,
        int $userid,
        string $outputlang,
        string $nextstepintent = ''
    ): array {
        // Context id is provided directly by the caller (context-agnostic decision path).
        // Read-only auto-execution must use deanonymized inputs, otherwise person names
        // replaced during privacy precheck can degrade exact option/user lookups.
        $preparedcommands = $this->inject_output_language_into_commands($commands, $outputlang);
        if ($threadid > 0 && $userid > 0) {
            $anonymizer = new privacy_anonymizer($this->store);
            foreach ($preparedcommands as &$command) {
                if (!is_array($command)) {
                    continue;
                }
                $input = is_array($command['input'] ?? null) ? (array)$command['input'] : [];
                // De-anonymize against THIS thread's token map — the (userid, contextid) active-
                // thread lookup is blind to MCP channel threads (status=<session channel>) and
                // can pick the wrong map when a chat thread is open at the same context.
                $command['input'] = $anonymizer->deanonymize_command_input($threadid, $input);
            }
            unset($command);
        }

        $idempotencykey = hash(
            'sha256',
            $userid . ':' . $contextid . ':' . $threadid
                . ':' . json_encode($preparedcommands) . ':' . microtime(true)
        );
        $runid = $this->store->create_run(
            $threadid,
            $userid,
            $contextid,
            $idempotencykey,
            $preparedcommands
        );

        try {
            $this->store->update_run_status($runid, 'running');
            $feedback = $this->with_output_language($outputlang, function () use (
                $preparedcommands,
                $queueitemids,
                $contextid,
                $userid,
                $idempotencykey,
                $runid,
                $threadid,
                $outputlang
            ): array {
                $exec = new executor($this->registry, $this->store, $this->authz);
                $rawresults = $exec->execute_commands(
                    $preparedcommands,
                    $contextid,
                    $userid,
                    $idempotencykey,
                    $runid
                );
                $feedbackservice = new execution_feedback_service($this->store, $this->registry);
                $feedback = $feedbackservice->build_completion_feedback(
                    $threadid,
                    $contextid,
                    $userid,
                    $preparedcommands,
                    $rawresults,
                    $outputlang
                );

                // Queue status projection: running -> succeeded/failed per readonly item.
                foreach ($queueitemids as $idx => $queueitemid) {
                    $queueitemid = (string)$queueitemid;
                    if ($queueitemid === '') {
                        continue;
                    }

                    // Atomically acquire the running slot (checks + sets in one DB transaction).
                    if (!$this->queuesvc->try_mark_running($threadid, $queueitemid)) {
                        // Slot already occupied by a concurrent request; skip status update.
                        continue;
                    }
                    $entry = is_array($rawresults[$idx] ?? null) ? (array)$rawresults[$idx] : [];
                    $status = trim((string)($entry['status'] ?? ''));
                    $failed = ($status === 'error' || $status === 'failed');
                    $issuecodes = array_values(array_map('strval', (array)($entry['issue_codes'] ?? [])));

                    if ($failed) {
                        $this->queuetransitionsvc->to_failed(
                            $this->queuesvc,
                            $threadid,
                            $queueitemid,
                            'READONLY_EXECUTION_FAILED',
                            $issuecodes,
                            'domain_error',
                            trim((string)($entry['detail'] ?? ''))
                        );
                    } else {
                        $this->queuetransitionsvc->to_succeeded(
                            $this->queuesvc,
                            $threadid,
                            $queueitemid,
                            'READONLY_EXECUTION_SUCCEEDED',
                            $issuecodes
                        );
                    }
                }

                return $feedback;
            });
            $results = $feedback['results'];
            $this->store->update_run_status($runid, 'completed', $results);
            $observationledger = new execution_observation_ledger($this->store);
            $observationledger->append_from_results(
                $threadid,
                (array)$results,
                [
                    'source' => 'readonly_execute',
                    'run_id' => (int)$runid,
                    'commands' => $preparedcommands,
                    'queue_item_ids' => $queueitemids,
                ]
            );
            $message = trim((string)($feedback['message'] ?? ''));
            if ($message === '') {
                $message = localized_string_service::get('ai_run_executed', 'bookingextension_agent', null, $outputlang);
            }

            $queueobservation = $this->observationbuilder->build_observation($this->queuesvc->get_queue_items($threadid));
            if ($queueobservation !== '') {
                $message .= "\n\n" . $queueobservation;
            }

            $payload = [
                'response_type' => 'execution_result',
                'message'       => $message,
                'commands'      => $preparedcommands,
                'ambiguities'   => [],
                'errors'        => [],
                'runid'         => (int)$runid,
                'results'       => $results,
            ];

            $disambiguationrequired = false;
            foreach ($results as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                if (!empty($entry['disambiguation_required'])) {
                    $disambiguationrequired = true;
                    $candidate = trim((string)($entry['usermessage'] ?? $entry['detail'] ?? ''));
                    if ($candidate !== '') {
                        $payload['message'] = $candidate;
                    }
                    break;
                }
            }

            if ($disambiguationrequired) {
                $payload['response_type'] = 'clarification';
                $payload['commands'] = [];
                $payload['issue_codes'] = ['DOCS_DISAMBIGUATION_REQUIRED'];
            }

            if (trim($nextstepintent) !== '') {
                $payload['next_step_intent'] = trim($nextstepintent);
            }

            return $payload;
        } catch (\Throwable $e) {
            $failureresults = [[
                'status'   => 'error',
                'detail'   => $e->getMessage(),
                'resultid' => null,
            ]];

            foreach ($queueitemids as $queueitemid) {
                $queueitemid = (string)$queueitemid;
                if ($queueitemid === '') {
                    continue;
                }
                $this->queuetransitionsvc->to_failed(
                    $this->queuesvc,
                    $threadid,
                    $queueitemid,
                    'READONLY_PROVIDER_EXCEPTION',
                    [],
                    'provider_error',
                    $e->getMessage()
                );
            }

            $this->store->update_run_status($runid, 'failed', $failureresults);

            return [
                'response_type' => 'error',
                // A skill exception is NOT a provider error (threads 323/326 surfaced
                // an invalid-cmid crash as a provider message). Message stays empty —
                // the synchronizer presents the cause from the error observation
                // (errors[] + failed result details), template class as fallback.
                'message'       => '',
                'error_class'   => 'skill_exception',
                'commands'      => $preparedcommands,
                'ambiguities'   => [],
                'errors'        => [$e->getMessage()],
                'runid'         => (int)$runid,
                'results'       => $failureresults,
            ];
        }
    }

    /**
     * Inject a canonical output language into each command input.
     *
     * This is framework-wide and avoids per-skill language plumbing.
     * Skills may still override outputlang explicitly.
     *
     * @param array $commands
     * @param string $outputlang
     * @return array
     */
    private function inject_output_language_into_commands(array $commands, string $outputlang): array {
        $lang = trim($outputlang);
        if ($lang === '') {
            return $commands;
        }

        foreach ($commands as &$command) {
            if (!is_array($command)) {
                continue;
            }
            $input = is_array($command['input'] ?? null) ? (array)$command['input'] : [];
            $input['outputlang'] = $lang;
            $command['input'] = $input;
        }
        unset($command);

        return $commands;
    }

    /**
     * Run a callback while forcing the current language when requested.
     *
     * @param string $outputlang
     * @param callable $callback
     * @return mixed
     */
    private function with_output_language(string $outputlang, callable $callback) {
        $targetlang = trim($outputlang);
        if ($targetlang === '') {
            return $callback();
        }

        $currentlang = current_language();
        $switched = $targetlang !== $currentlang;
        if ($switched) {
            force_current_language($targetlang);
        }

        try {
            return $callback();
        } finally {
            if ($switched) {
                force_current_language($currentlang);
            }
        }
    }

    // Private: preflight helpers.

    // -------------------------------------------------------------------------
    // Private: command classification helpers.

    /**
     * Check whether a response contains at least one mutating (non-read-only) command.
     *
     * @param  array $result
     * @return bool
     */
    private function has_mutating_commands(array $result): bool {
        $commands = $this->inject_risk_class_into_commands((array)($result['commands'] ?? []));
        if (!is_array($commands) || empty($commands)) {
            return false;
        }
        foreach ($commands as $command) {
            if (!is_array($command)) {
                continue;
            }
            if (risk_class_resolver::resolve_for_command($command, $this->registry) !== skill_risk_class::R0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Split commands into risk-class groups.
     *
     * Unknown or malformed commands are treated as R3 for safety.
     *
     * @param  array $commands
     * @return array{r0:array[],r1:array[],r2:array[],r3:array[]}
     */
    private function split_commands_by_risk_class(array $commands): array {
        $groups = [
            'r0' => [],
            'r1' => [],
            'r2' => [],
            'r3' => [],
        ];

        foreach ($commands as $command) {
            if (!is_array($command)) {
                $groups['r3'][] = ['skill' => '', 'input' => [], 'risk_class' => skill_risk_class::R3];
                continue;
            }
            $riskclass = risk_class_resolver::resolve_for_command($command, $this->registry);
            $command['risk_class'] = $riskclass;
            if ($riskclass === skill_risk_class::R0) {
                $groups['r0'][] = $command;
            } else if ($riskclass === skill_risk_class::R1) {
                $groups['r1'][] = $command;
            } else if ($riskclass === skill_risk_class::R2) {
                $groups['r2'][] = $command;
            } else {
                $groups['r3'][] = $command;
            }
        }

        return $groups;
    }

    /**
     * Split commands into read-only and mutating groups.
     *
     * @param  array $commands
     * @return array ['readonly' => array, 'mutating' => array]
     */
    private function split_commands_by_mutability(array $commands): array {
        $groups = $this->split_commands_by_risk_class($commands);

        return [
            'readonly' => $groups['r0'],
            'mutating' => array_values(array_merge($groups['r1'], $groups['r2'], $groups['r3'])),
        ];
    }

    /**
     * Inject resolved risk_class into commands.
     *
     * @param array[] $commands
     * @return array[]
     */
    private function inject_risk_class_into_commands(array $commands): array {
        foreach ($commands as &$command) {
            if (!is_array($command)) {
                continue;
            }
            $command['risk_class'] = risk_class_resolver::resolve_for_command($command, $this->registry);
        }
        unset($command);

        return $commands;
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
            if (in_array($status, ['error', 'failed'], true)) {
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
        $confirmablecodes = $this->issuecodeprovider->get_prevalidation_confirmable_issue_codes();
        return !empty(array_intersect($confirmablecodes, $normalized));
    }

    // -------------------------------------------------------------------------
    // Private: trigger helpers.

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
            'attempted_skills'          => [],
            'issue_codes'               => [],
            'pending_confirmation_code' => '',
            'runid'                     => 0,
            'results'                   => [],
        ];
    }

    /**
     * Build clarification result with contextual carry-over fields.
     *
     * @param string $message
     * @param array $contextresult
     * @param array $overrides
     * @return array
     */
    private function clarification_result_with_context(
        string $message,
        array $contextresult,
        array $overrides = []
    ): array {
        $clarification = $this->clarification_result($message);
        $clarification['ambiguities'] = array_values(array_unique((array)($contextresult['ambiguities'] ?? [])));
        $clarification['errors'] = array_values(array_unique((array)($contextresult['errors'] ?? [])));

        foreach ($overrides as $key => $value) {
            $clarification[$key] = $value;
        }

        return $clarification;
    }

    /**
     * Build clarification fallback when confirm_pending has no usable pending intent.
     *
     * @param array $result
     * @param string $modelmessage
     * @param bool $isplaceholdermessage
     * @param string $outputlang
     * @param int $threadid
     * @return array
     */
    private function build_confirm_pending_no_intent_fallback(
        array $result,
        string $modelmessage,
        bool $isplaceholdermessage,
        string $outputlang,
        int $threadid
    ): array {
        // Planned placeholders but no pending confirmation: the planner mistook the queued
        // "pending steps" for a pending confirmation (confirm_pending is only valid while a
        // confirmation is awaiting). That is a recoverable planner flake, not a user question —
        // surface it as a retryable contract error so the runtime loop re-plans the step once
        // via skill_call instead of ending the turn and orphaning the remaining series steps.
        if ($this->queuesvc->has_planned_placeholders($threadid)) {
            $cause = 'confirm_pending without a pending confirmation while planned steps remain in the queue.';
            $fallback = $this->clarification_result($cause);
            $fallback['response_type'] = 'error';
            $fallback['errors'] = [$cause];
            $fallback['issue_codes'] = ['CONFIRM_PENDING_NO_INTENT_PLANNED_STEPS'];
            return $fallback;
        }

        if ($modelmessage !== '' && !$isplaceholdermessage) {
            // Terminal clarification. Deliberately does NOT carry the model's next_step_intent:
            // nothing follows this turn, and a copied intent feeds the synchronizer a false
            // "further actions are queued" signal (thread 558's "Sprint 5 wird automatisch
            // noch erstellt" on a turn that was over).
            return $this->clarification_result($modelmessage);
        }

        return $this->clarification_result(
            localized_string_service::get('ai_no_pending_intent', 'bookingextension_agent', null, $outputlang)
        );
    }

    // -------------------------------------------------------------------------
    // Private: localisation + normalization helpers.

    /**
     * User-facing cause text from preflight issues (F3): user_question preferred over
     * message per issue, planner-only "Command #N:" labels stripped; falls back to the
     * stripped blocking errors when no issue carries usable text.
     *
     * @param array $issues
     * @param string[] $fallbackerrors
     * @return string
     */
    private function compose_user_cause_from_issues(array $issues, array $fallbackerrors): string {
        $striplabel = static fn(string $text): string =>
            trim((string)preg_replace('/^\s*Command\s*#\d+\s*:\s*/i', '', $text));

        $parts = [];
        foreach ($issues as $issue) {
            if (!is_array($issue)) {
                continue;
            }
            $text = $striplabel(trim((string)($issue['user_question'] ?? '')));
            if ($text === '') {
                $text = $striplabel(trim((string)($issue['message'] ?? '')));
            }
            if ($text !== '' && !in_array($text, $parts, true)) {
                $parts[] = $text;
            }
        }

        if (empty($parts)) {
            foreach ($fallbackerrors as $error) {
                $text = $striplabel(trim((string)$error));
                if ($text !== '' && !in_array($text, $parts, true)) {
                    $parts[] = $text;
                }
            }
        }

        return trim(implode(' ', $parts));
    }

    /**
     * Resolve a localized plugin string.
     *
     * @param string $identifier
     * @param mixed $a
     * @param string $lang
     * @return string
     */
    private function localized(string $identifier, $a = null, string $lang = ''): string {
        return localized_string_service::get($identifier, 'bookingextension_agent', $a, $lang);
    }

    /**
     * Normalize queue item identifiers to non-empty strings.
     *
     * @param mixed $value
     * @return string[]
     */
    private function normalize_queue_item_ids($value): array {
        return array_values(array_filter(array_map('strval', (array)$value)));
    }

    /**
     * The choices offered by clarification issues: every needs_clarification issue with a non-empty `candidates`
     * list (engine contract, wave 30). Structural only - no issue code or wording is inspected.
     *
     * @param array $issues Preflight issues.
     * @return array<int,array{message:string,candidates:array}>
     */
    public static function offered_choices(array $issues): array {
        $choices = [];
        foreach ($issues as $issue) {
            if (!is_array($issue) || (string)($issue['severity'] ?? '') !== 'needs_clarification') {
                continue;
            }
            $candidates = array_values(array_filter((array)($issue['candidates'] ?? []), 'is_array'));
            if (empty($candidates)) {
                continue;
            }
            $choices[] = [
                'message' => (string)($issue['message'] ?? ''),
                'field' => (string)($issue['field'] ?? ''),
                'candidates' => $candidates,
            ];
        }
        return $choices;
    }
}
