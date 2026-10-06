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
 * Prompt bundle builder for orchestrator phases.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent\local\wizard\services;

use core_ai\aiactions\generate_text;
use bookingextension_agent\local\wizard\prompt_policy_builder;
use bookingextension_agent\local\wizard\skill_executability_evaluator;
use bookingextension_agent\local\wizard\skill_registry;
use bookingextension_agent\local\wizard\orchestrator;
use bookingextension_agent\local\wizard\services\orchestrator_prompt_profile_service;
use bookingextension_agent\local\wizard\services\security\authorization_service;
use bookingextension_agent\local\wizard\wb_action_names;

/**
 * Build phase-specific prompt bundles without mixing orchestration concerns.
 */
class phase_prompt_bundle_builder {
    /** Wunderbyte final reply action class name. */
    private const WB_ACTION_GENERATE_AGENT_REPLY = wb_action_names::GENERATE_AGENT_REPLY;

    /** @var skill_registry */
    private skill_registry $registry;

    /** @var orchestrator_prompt_profile_service */
    private orchestrator_prompt_profile_service $promptprofilesvc;

    /**
     * Constructor.
     *
     * @param skill_registry $registry
     * @param orchestrator_prompt_profile_service $promptprofilesvc
     */
    public function __construct(skill_registry $registry, orchestrator_prompt_profile_service $promptprofilesvc) {
        $this->registry = $registry;
        $this->promptprofilesvc = $promptprofilesvc;
    }

    /**
     * Build the state-based system prompt with compact skill metadata embedded.
     *
     * @param  int    $userid
     * @param  int    $contextid
     * @param  string $phase
     * @param  string $actionclass
     * @param  bool   $hasobservations
     * @param  array|null $adaptivecatalog Optional adaptive skill catalog (reduced by recency/tier). If null, uses full catalog.
     * @param  array  $systemskillcatalog Optional exact skill catalog to embed into SYSTEM placeholders.
     * @param  bool   $isfirstassistantturn True when no assistant message exists yet in this thread.
     * @param  bool   $includeskillcatalog If true, embed skill catalog placeholder in SYSTEM block.
     * @return string System prompt text.
     */
    public function build_system_prompt(
        int $userid,
        int $contextid,
        string $phase = orchestrator_prompt_profile_service::PHASE_SELECTION,
        string $actionclass = generate_text::class,
        bool $hasobservations = false,
        ?array $adaptivecatalog = null,
        array $systemskillcatalog = [],
        bool $isfirstassistantturn = false,
        bool $includeskillcatalog = false
    ): string {
        $evaluator = new skill_executability_evaluator($this->registry, new authorization_service());
        $skillnames = $this->registry->get_skill_names_for_context($evaluator, $userid, $contextid);
        $skilllist = implode(', ', $skillnames);
        $phaseconfigkey = $this->promptprofilesvc->get_planner_initial_prompt_config_key_for_phase($phase);
        $actiondefault = orchestrator::get_default_initial_prompt_template_for_action($actionclass);
        $isconstruction = $phase === orchestrator_prompt_profile_service::PHASE_PARAMETER_CONSTRUCTION
            && $this->is_planner_action($actionclass);
        // The construction phase has its own default: the selector/routing template must never
        // leak into constructor calls (#2199/#2200). A stored value equal to EITHER default
        // (constructor default, or the legacy shared selector seed) counts as "not customized".
        $phasedefault = $isconstruction
            ? orchestrator::get_default_constructor_prompt_template()
            : $actiondefault;
        $configuredtemplate = $this->promptprofilesvc->normalize_config_prompt_template(
            (string)(get_config('bookingextension_agent', $phaseconfigkey) ?? ''),
            $actiondefault
        );
        if ($configuredtemplate !== '' && $isconstruction) {
            $configuredtemplate = $this->promptprofilesvc->normalize_config_prompt_template(
                $configuredtemplate,
                $phasedefault
            );
        }

        // Keep core operational prompts fixed to avoid admin misconfiguration risks.
        // Only a single optional synthesis prefix is allowed via aiinitialprompt_summarise_text.
        $template = $configuredtemplate !== '' ? $configuredtemplate : $phasedefault;

        if (
            $actionclass === generate_text::class
            || $actionclass === self::WB_ACTION_GENERATE_AGENT_REPLY
        ) {
            // Only prepend a custom admin-configured prefix; the default template already
            // contains the "You are an expert..." opening, so skip when no override is set.
            // The setting is seeded with the default opening sentence — that is not a
            // customization and must not replace the template's cache-stable opening line.
            $summaryprefix = trim((string)(get_config('bookingextension_agent', 'aiinitialprompt_summarise_text') ?? ''));
            if (orchestrator::is_default_summary_prompt_prefix($summaryprefix)) {
                $summaryprefix = '';
            }
            if ($summaryprefix !== '') {
                $trimmedtemplate = ltrim($template);
                $isexpertopening = static function (string $text): bool {
                    return preg_match(
                        '/^You are an expert that composes polished, helpful answers/',
                        trim($text)
                    ) === 1;
                };

                // Avoid duplicate synthesis intros when both prefix and template start
                // with the same expert-opening sentence.
                if ($isexpertopening($summaryprefix) && $isexpertopening($trimmedtemplate)) {
                    $newlinepos = strpos($trimmedtemplate, "\n");
                    if ($newlinepos === false) {
                        $template = $summaryprefix;
                    } else {
                        $template = $summaryprefix . "\n"
                            . ltrim(substr($trimmedtemplate, $newlinepos + 1), "\n");
                    }
                } else {
                    $template = $summaryprefix . "\n\n" . $trimmedtemplate;
                }
            }
        }

        $prompt = strtr($template, [
            // Keep placeholders stable across requests for better prompt-prefix caching.
            '{{contextname}}' => '[SYSTEM_RUNTIME_STATE.context_name]',
            // Deprecated alias: the agent is site-wide, so it resolves to the generic context name.
            // Kept so admin-customized templates that still use {{bookingname}} do not dangle.
            '{{bookingname}}' => '[SYSTEM_RUNTIME_STATE.context_name]',
            '{{timezonename}}' => '[SYSTEM_RUNTIME.timezone]',
            '{{nowiso}}' => '[SYSTEM_RUNTIME_STATE.now_iso]',
            '{{skilllist}}' => $skilllist,
            '{{schemajson}}' => '[]',
            '{{skillcatalogjson}}' => '[]',
            '{{fullschemajson}}' => '{}',
        ]);

        $policybuilder = new prompt_policy_builder();
        $prompt .= $policybuilder->build_planner_policies(
            $phase,
            $hasobservations,
            $isfirstassistantturn
        );

        return $prompt;
    }

    /**
     * Return true for planner-style action classes.
     *
     * @param string $actionclass
     * @return bool
     */
    private function is_planner_action(string $actionclass): bool {
        return $actionclass === \core_ai\aiactions\summarise_text::class
            || wb_action_names::is_planner_action($actionclass);
    }

    /**
     * Build the full prompt string from system prompt + message history + observations.
     *
     * Observations (from prior internal loop tool executions) are injected near the [ASSISTANT]
     * marker so the LLM can incorporate tool results into its next decision without those results
     * ever being stored as conversation messages.
     *
     * Cache-friendly ordering: static [SYSTEM], per-thread-stable [SYSTEM_RUNTIME] (which carries a
     * STATIC skill catalog so the biggest block joins the cached prefix), append-only history, then
     * the per-request [SYSTEM_RUNTIME_STATE] (an adaptive catalog + execution ledgers, with now_iso
     * LAST), and finally the live [PLANNER_TRACE n]/[OBSERVATION n] blocks. Observations sit AFTER the
     * ledgers — closest to [ASSISTANT] — so decision-critical tool results outrank the "already done"
     * completed_commands by recency, while volatile content still never busts the shared prefix.
     *
     * @param  string      $systemprompt
     * @param  \stdClass[] $messages
     * @param  string[]    $observations  Structured observation strings (may be empty).
     * @param  string      $phase The current planner phase.
     * @param  string      $runtimecontext Per-thread-stable runtime facts appended after static system prompt.
     * @param  string[]    $plannertracehistory Full planner trace history from thread metadata.
     * @param  bool        $autoconfirmmode Whether confirmation is already allowed for this thread.
     * @param  array       $plannedstepintents Planned step intents for this thread.
     * @param  string      $runtimestate Per-request volatile runtime state appended after history.
     * @param  bool|null   $selectedskillisreadonly Engine-known readonly flag of the selected skill
     *                     (construction phase only; null when unknown or not applicable).
     * @param  array       $pendingclarification M1 (#2220): engine-recorded action the previous
     *                     blocking clarification was about ({skill, issue_codes, question}); empty
     *                     when no clarification chain is open. Selection phase only.
     * @param  array|null  $selectedskillinput Input contract of the selected skill ({required_input,
     *                     required_groups}); construction phase only, null when unknown (wave 30).
     * @return string
     */
    public function build_prompt(
        string $systemprompt,
        array $messages,
        array $observations = [],
        string $phase = orchestrator_prompt_profile_service::PHASE_SELECTION,
        string $runtimecontext = '',
        array $plannertracehistory = [],
        bool $autoconfirmmode = false,
        array $plannedstepintents = [],
        string $runtimestate = '',
        ?bool $selectedskillisreadonly = null,
        array $pendingclarification = [],
        ?array $selectedskillinput = null
    ): string {
        $trimmedmessages = $this->promptprofilesvc->select_history_messages($messages, $phase);

        $parts = ["[SYSTEM]\n{$systemprompt}"];

        // Authoritative output contract in the cached prefix (right after the static [SYSTEM] block, so
        // it co-locates with the routing rules and is processed once / shared across calls instead of
        // re-sent every turn). A short recency reminder is appended near [ASSISTANT] below.
        $outputcontract = $this->build_output_contract_block($phase);
        if ($outputcontract !== '') {
            $parts[] = "[OUTPUT_CONTRACT]\n{$outputcontract}";
        }

        if ($runtimecontext !== '') {
            $parts[] = "[SYSTEM_RUNTIME]\n{$runtimecontext}";
        }

        foreach ($trimmedmessages as $msg) {
            $role    = strtoupper($msg->role ?? 'user');
            $content = $msg->content ?? '';
            $parts[] = "[{$role}]\n{$content}";
        }

        if ($runtimestate !== '') {
            $parts[] = "[SYSTEM_RUNTIME_STATE]\n{$runtimestate}";
        }

        // Live planner traces + observations come AFTER the state ledgers, i.e. closest to the
        // [ASSISTANT] slot: the decision-critical tool results must outrank the "already done"
        // completed_commands by recency (and the now_iso line above them is the only thing they sit
        // under, which is volatile anyway).
        $parts = $this->append_planner_traces_and_observations($parts, $plannertracehistory, $observations);

        // M1 (#2220): when the previous turn ended in a blocking clarification about an attempted
        // action, tell the selector so a correction reply re-selects that skill instead of drifting
        // to a follow-up skill (measured misroute 3/6 embed_topk, 2/8 slim_all without this block).
        // Advisory context from engine state only — the model keeps the choice (a decline + new
        // request must still route freely), so this is NOT a routing lock.
        $pendingskill = trim((string)($pendingclarification['skill'] ?? ''));
        if ($phase === orchestrator_prompt_profile_service::PHASE_SELECTION && $pendingskill !== '') {
            $lines = ['The previous assistant turn asked the user a question about an attempted action:'];
            $lines[] = '- attempted skill: ' . $pendingskill;
            $question = trim((string)($pendingclarification['question'] ?? ''));
            if ($question !== '') {
                $lines[] = '- question asked: ' . str_replace(["\r", "\n"], ' ', $question);
            }
            $issuecodes = array_values(array_filter(array_map(
                'strval',
                (array)($pendingclarification['issue_codes'] ?? [])
            )));
            if (!empty($issuecodes)) {
                $lines[] = '- issue codes: ' . implode(', ', $issuecodes);
            }
            $lines[] = 'If the user\'s current reply answers that question or corrects a detail of that '
                . 'attempted action (for example a different name or value), select ' . $pendingskill
                . ' again — construction applies the corrected details.';
            $lines[] = 'If the user declines and asks for something different, route the new request '
                . 'normally instead.';
            $lines[] = 'Never select a skill that would operate on an entity this attempted action has '
                . 'not created yet.';
            $parts[] = "[PENDING CLARIFICATION CONTEXT]\n" . implode("\n", $lines);
        }

        if (
            $phase === orchestrator_prompt_profile_service::PHASE_SELECTION
            && !empty($plannedstepintents)
        ) {
            $lines = ['The following future steps are already planned as placeholders in the queue.'];
            $lines[] = 'Do NOT include planned_steps in your response — placeholders already exist.';
            $lines[] = 'These placeholders are NOT a pending confirmation: never answer them with '
                . 'response_type=confirm_pending. Select the real skill with a skill_call.';
            $lines[] = 'Select the real skill for the next pending step below:';
            foreach ($plannedstepintents as $i => $intent) {
                $lines[] = ($i + 1) . '. ' . $intent;
            }
            $parts[] = "[PENDING PLANNED STEPS]\n" . implode("\n", $lines);
        }

        $reminder = $this->build_output_contract_reminder($phase, $autoconfirmmode, $selectedskillisreadonly, $selectedskillinput);
        if ($reminder !== '') {
            $parts[] = "[OUTPUT_REMINDER]\n{$reminder}";
        }

        $parts[] = '[ASSISTANT]';
        return implode("\n\n", $parts);
    }

    /**
     * Build a local output contract reminder close to the assistant output slot.
     *
     * @param string $phase
     * @return string
     */
    private function build_output_contract_block(string $phase): string {
        // Wave 32 (frozen prompt spec): selection and construction carry their OUTPUT CONTRACT inside the base template;
        // a second contract block restated it with differing rules (constructor 'sufficient', search_skills in static mode).
        $normalizedphase0 = trim(strtolower($phase));
        if (
            $normalizedphase0 === orchestrator_prompt_profile_service::PHASE_SELECTION
            || $normalizedphase0 === orchestrator_prompt_profile_service::PHASE_PARAMETER_CONSTRUCTION
        ) {
            return '';
        }
        $normalizedphase = trim(strtolower($phase));
        $lines = [
            'Return exactly one valid JSON object and nothing else.',
            'Do not output markdown, code fences, prose, or bullet lists outside JSON.',
        ];

        if ($normalizedphase === orchestrator_prompt_profile_service::PHASE_PARAMETER_CONSTRUCTION) {
            $lines[] = 'Apply constructor semantics only; do not perform routing in this phase.';
            $lines[] = 'Allowed response_type: skill_call, confirmation_request, confirm_pending, ' .
                'clarification, sufficient, error.';
            $lines[] = 'For skill_call/confirmation_request: commands must contain one or more command objects.';
            $lines[] = 'For clarification/confirm_pending/sufficient/error: commands must be [].';
            // Wave 30 (DMD-2, thread 11878): the constructor wrote "this skill cannot confirm the delivery" but left
            // the flag out - it stood only in a rule far above. The contract states it where the answer is shaped.
            $lines[] = 'A clarification because selected_skill cannot perform the request at all carries "skill_fits": '
                . 'false (never for a missing value).';
            $lines[] = 'For mutating intents, do not use skill_call; '
                . 'use confirmation_request unless already completed -> sufficient.';
            $lines[] = 'phase_handoff.selection.response_type records the SELECTION phase result only '
                . '(a selector always reports skill_call). Never copy it; derive YOUR response_type '
                . 'from the rules here and from the [OUTPUT_REMINDER].';
            $lines[] = 'Constructor-only phase: do not discover/switch skills. Build parameters for selected_skill only.';
            $lines[] = 'Each command.skill must equal selected_skill from phase_handoff.selection.';
            $lines[] = 'Use canonical command envelope keys only: skill, version, parameters.';
            $lines[] = 'Do not emit non-canonical command-level keys: params, command_id, id, cid.';
            $lines[] = 'Do NOT include planned_steps — selector phase only.';
            $lines[] = 'next_step_intent MUST be a string (never null; use "" if no follow-up).';
            $lines[] = 'Canonical example: {"skill":"<selected_skill>","version":1,"parameters":{...}}';
            // Self-reference contract (#2246): agnostic, no word lists. Person parameters describe OTHER
            // people; the requester is engine state (current_user in [SYSTEM_RUNTIME_STATE]), so a
            // request about the requester omits the person parameter and never asks for their name.
            $lines[] = 'Person parameters (user selectors such as a user query/id) name OTHER people. '
                . 'When the request concerns the requester themselves, OMIT every person parameter: the skill '
                . 'then acts for the current user. Never ask the requester for their own name, e-mail or id, '
                . 'and never fill a person parameter with a placeholder for the requester.';
        } else {
            $lines[] = 'Apply routing semantics from [SYSTEM] decision order; do not override them here.';
            $lines[] = 'Allowed response_type: skill_call, clarification, confirm_pending, sufficient, error.';
            $lines[] = 'For skill_call: commands must contain exactly one command object that selects exactly one skill; '
                . 'do not include full parameter payloads.';
            $lines[] = 'Each element in commands[] must be a direct command object with skill at top level. '
                . 'Do not wrap commands in helper objects like current, next, action, command, or step.';
            $lines[] = 'Selection command input must be omitted or {}: no field-level construction, no inferred defaults.';
            $lines[] = 'For clarification/confirm_pending/sufficient/error: commands must be [].';
            $lines[] = 'This phase is a tool-selector call: it chooses exactly one skill, and construction handles parameters.';
            $lines[] = 'planned_steps REQUIRED: always include as a top-level array.';
            $lines[] = '  - Single-step request or [PENDING PLANNED STEPS] already in context: planned_steps=[].';
            $lines[] = '  - Multi-step request (multiple sequential mutations) on first turn: '
                . 'planned_steps=[{"intent":"..."},{"intent":"..."}] listing ALL future steps beyond the current one.';
            $lines[] = 'next_step_intent REQUIRED: always a string (never null).';
            $lines[] = 'Valid example: {"response_type":"skill_call","commands":[{"skill":"example.create_record","input":{}}],'
                . '"planned_steps":[{"intent":"Set assignee"},{"intent":"Notify user"}],"next_step_intent":"Create record 2"}';
            $lines[] = 'Invalid example: {"response_type":"skill_call","commands":['
                . '{"current":{"skill":"example.create_record"}}]}';
            $lines[] = 'If NONE of the skills in the SKILL CATALOG can fulfill the request, do NOT answer that no '
                . 'capability exists. Instead select wizard.search_skills to search the full tool registry: '
                . '{"response_type":"skill_call","commands":[{"skill":"wizard.search_skills","input":{}}],'
                . '"planned_steps":[],"next_step_intent":"Search for a skill that can <capability>"}. '
                . 'Do this at most once per request; if the follow-up still finds nothing, return clarification or error.';
        }

        return implode("\n", $lines);
    }

    /**
     * Build the short, volatile output reminder placed near the [ASSISTANT] slot: a recency pointer to
     * the cached [OUTPUT_CONTRACT] above, plus the auto-confirm guidance (which is per-turn volatile and
     * therefore must NOT live in the cached prefix).
     *
     * @param string $phase
     * @param bool $autoconfirmmode
     * @param bool|null $selectedskillisreadonly Engine-known readonly flag of the selected skill.
     * @param array|null $selectedskillinput Input contract of the selected skill (required_input, required_groups).
     * @return string
     */
    private function build_output_contract_reminder(
        string $phase,
        bool $autoconfirmmode = false,
        ?bool $selectedskillisreadonly = null,
        ?array $selectedskillinput = null
    ): string {
        $normalizedphase = trim(strtolower($phase));
        $lines = [];

        // Wave 32 (frozen prompt spec): the constructor reminder states two engine facts - read-only or mutating, and the
        // declared required values - and nothing else; the rules live once in the constructor template.
        $isconstruction = $normalizedphase === orchestrator_prompt_profile_service::PHASE_PARAMETER_CONSTRUCTION;
        if ($isconstruction && $selectedskillisreadonly !== null) {
            $lines[] = $selectedskillisreadonly
                ? 'selected_skill is READ-ONLY -> emit skill_call.'
                : 'selected_skill is MUTATING -> emit confirmation_request.';
        }
        if ($isconstruction && $selectedskillinput !== null) {
            $required = array_values(array_filter(array_map('strval', (array)($selectedskillinput['required_input'] ?? []))));
            $groups = [];
            foreach ((array)($selectedskillinput['required_groups'] ?? []) as $group) {
                $group = array_values(array_filter(array_map('strval', (array)$group)));
                if (!empty($group)) {
                    $groups[] = 'one of ' . implode(' | ', $group);
                }
            }
            $needs = array_merge($required, $groups);
            $lines[] = empty($needs)
                ? 'Its required values: none - ask for nothing.'
                : 'Its required values: ' . implode('; ', $needs) . '.';
        }
        if ($isconstruction && $autoconfirmmode) {
            $lines[] = 'Auto-confirm is active: the engine runs a confirmation_request without asking the user. '
                . 'Write its message as a statement of what is being done, not as a question.';
        }

        $lines[] = 'Respond now with exactly one JSON object as defined in OUTPUT CONTRACT.';

        return implode("\n", $lines);
    }

    /**
     * Append planner traces and observations in interleaved order.
     *
     * Desired shape: USER, PLANNER_TRACE 1, OBSERVATION 1, PLANNER_TRACE 2, OBSERVATION 2, ...
     *
     * @param string[] $parts
     * @param string[] $plannertracehistory
     * @param string[] $observations
     * @return string[]
     */
    private function append_planner_traces_and_observations(
        array $parts,
        array $plannertracehistory,
        array $observations
    ): array {
        $max = max(count($plannertracehistory), count($observations));
        for ($i = 0; $i < $max; $i++) {
            $num = $i + 1;

            if (isset($plannertracehistory[$i])) {
                $parts[] = "[PLANNER_TRACE {$num}]\n" . $plannertracehistory[$i];
            }

            if (isset($observations[$i])) {
                $parts[] = "[OBSERVATION {$num}]\n" . (string)$observations[$i];
            }
        }

        return $parts;
    }
}
