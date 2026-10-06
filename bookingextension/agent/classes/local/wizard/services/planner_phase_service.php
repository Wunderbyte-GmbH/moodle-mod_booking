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
 * Planner selection + parameter-construction phase service.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services;

use core\context;
use core\di;
use core_ai\manager as ai_manager;
use core_ai\aiactions\generate_text;
use bookingextension_agent\local\wizard\interpreter;
use bookingextension_agent\local\wizard\orchestrator;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\skill_registry;
use bookingextension_agent\local\wizard\provider_error_result_trait;
use bookingextension_agent\local\wizard\config\runtime_feature_flags;
use bookingextension_agent\local\wizard\interfaces\agent_interpreter;
use bookingextension_agent\local\wizard\queue\queue_manager;
use bookingextension_agent\local\wizard\services\discovery\context_prior_builder;
use bookingextension_agent\local\wizard\services\llm\llm_call_service;
use bookingextension_agent\local\wizard\services\telemetry\routing_decision_log_service;

/**
 * Runs the planner selection and parameter-construction phases.
 *
 * Extracted verbatim from orchestrator::run_selection_phase /
 * run_construction_phase (orchestrator split, planner-phase seam). The two
 * phases are coupled through the selection -> construction handoff, so they
 * share one service and one injected collaborator set. Phase-local helpers
 * (selection normalization, construction catalog enrichment, contract/provider
 * error payloads, handoff observations) move in with the phases. The shared prompt wrappers
 * (build_system_prompt / build_prompt, incl. the userid/contextid swap, + json_encode_or_empty)
 * live in planner_phase_prompt_trait.
 */
class planner_phase_service {
    use planner_phase_prompt_trait;
    use provider_error_result_trait;

    /** @var conversation_store */
    private conversation_store $store;

    /** @var skill_registry */
    private skill_registry $registry;

    /** @var agent_interpreter */
    private agent_interpreter $interpreter;

    /** @var orchestrator_routing_service */
    private orchestrator_routing_service $routingsvc;

    /** @var orchestrator_prompt_profile_service */
    private orchestrator_prompt_profile_service $promptprofilesvc;

    /** @var planner_catalog_service */
    private planner_catalog_service $catalogsvc;

    /** @var runtime_context_block_builder */
    private runtime_context_block_builder $runtimecontextsvc;

    /** @var phase_prompt_bundle_builder */
    private phase_prompt_bundle_builder $promptbundlebuilder;

    /**
     * Constructor.
     *
     * @param conversation_store $store
     * @param skill_registry $registry
     * @param agent_interpreter $interpreter
     * @param orchestrator_routing_service $routingsvc
     * @param orchestrator_prompt_profile_service $promptprofilesvc
     * @param planner_catalog_service $catalogsvc
     * @param runtime_context_block_builder $runtimecontextsvc
     * @param phase_prompt_bundle_builder $promptbundlebuilder
     */
    public function __construct(
        conversation_store $store,
        skill_registry $registry,
        agent_interpreter $interpreter,
        orchestrator_routing_service $routingsvc,
        orchestrator_prompt_profile_service $promptprofilesvc,
        planner_catalog_service $catalogsvc,
        runtime_context_block_builder $runtimecontextsvc,
        phase_prompt_bundle_builder $promptbundlebuilder
    ) {
        $this->store = $store;
        $this->registry = $registry;
        $this->interpreter = $interpreter;
        $this->routingsvc = $routingsvc;
        $this->promptprofilesvc = $promptprofilesvc;
        $this->catalogsvc = $catalogsvc;
        $this->runtimecontextsvc = $runtimecontextsvc;
        $this->promptbundlebuilder = $promptbundlebuilder;
    }

    /**
     * Selection phase: build prompt, telemetry, and debug-source payload.
     *
     * @param int $threadid
     * @param int $contextid
     * @param int $userid
     * @param array $observations
     * @param array $discoverystate
     * @param context $context
     * @param ai_manager $manager
     * @return array
     */
    public function run_selection(
        int $threadid,
        int $contextid,
        int $userid,
        array $observations,
        array $discoverystate,
        context $context,
        ai_manager $manager
    ): array {
        $contextid = (int)($discoverystate['contextid'] ?? 0);
        $routing = $this->routingsvc->resolve_action_class_for_phase(
            $manager,
            $context,
            orchestrator_routing_service::PHASE_SELECTION
        );
        $actionclass = (string)($routing['actionclass'] ?? generate_text::class);
        $messages = (array)($discoverystate['messages'] ?? []);
        $promptcontracts = (array)($discoverystate['promptcontracts'] ?? []);
        $runtimecatalog = (array)($discoverystate['runtimecatalog'] ?? []);
        $unavailableskillcatalog = (array)($discoverystate['unavailableskillcatalog'] ?? []);
        $plannertracehistory = (array)($discoverystate['plannertracehistory'] ?? []);
        $catalogselectionmode = (string)($discoverystate['catalogselectionmode'] ?? 'none');
        $embeddingstatus = (string)($discoverystate['embeddingstatus'] ?? 'off');
        $embeddingrebuildqueued = !empty($discoverystate['embeddingrebuildqueued']);
        $hasanyobservations = !empty($discoverystate['hasanyobservations']);
        $haseffectiveobservations = !empty($discoverystate['haseffectiveobservations']);
        $isfirstassistantturn = !empty($discoverystate['isfirstassistantturn']);
        $shouldincludeskillcatalog = !empty($discoverystate['shouldincludeskillcatalog']);
        $adaptivecatalog = (array)($discoverystate['adaptivecatalog'] ?? []);
        $discoverystage = (string)($discoverystate['discovery_stage'] ?? 'none');
        $discoveryconfidencescore = $discoverystate['discovery_confidence_score'] ?? null;
        $discoveryescalationreason = (string)($discoverystate['discovery_escalation_reason'] ?? 'none');

        $systemprompt = $this->build_system_prompt(
            $contextid,
            $userid,
            orchestrator::PHASE_SELECTION,
            $actionclass,
            $haseffectiveobservations,
            $adaptivecatalog,
            $runtimecatalog,
            $isfirstassistantturn,
            $shouldincludeskillcatalog
        );
        $runtimeblocks = $this->runtimecontextsvc->build(
            $threadid,
            $contextid,
            orchestrator::PHASE_SELECTION,
            $isfirstassistantturn,
            $hasanyobservations,
            $runtimecatalog,
            $unavailableskillcatalog,
            $messages,
            '',
            $observations,
            $this->catalogsvc->catalog_mode_is_static($catalogselectionmode)
        );
        $autoconfirmmode = $this->store->is_confirmation_allowed_for_thread($userid, $contextid, $threadid);
        $plannedstepintents = (new queue_manager($this->store, $this->registry))
            ->get_planned_placeholder_intents($threadid);
        // M1 (#2220): surface the engine-recorded action of an open clarification chain so a
        // correction reply re-selects the attempted skill (see agent_runtime, blueprint §4 M1).
        $pendingclarification = [];
        $pendingraw = trim((string)$this->store->get_thread_metadata_value($threadid, 'clarification_pending_action'));
        if ($pendingraw !== '') {
            $decoded = json_decode($pendingraw, true);
            if (is_array($decoded)) {
                $pendingclarification = $decoded;
            }
        }
        $prompt = $this->build_prompt(
            $systemprompt,
            $messages,
            $observations,
            orchestrator::PHASE_SELECTION,
            $runtimeblocks['stable'],
            $plannertracehistory,
            $autoconfirmmode,
            $plannedstepintents,
            $runtimeblocks['volatile'],
            null,
            $pendingclarification
        );

        $historycount = count(
            $this->promptprofilesvc->select_history_messages($messages, orchestrator::PHASE_SELECTION)
        );
        $observationcount = count($observations);
        $primaryprovider = provider_routing_util::resolve_primary_provider_for_action($manager, $actionclass);
        $debugsource = $this->routingsvc->build_debug_source(
            $actionclass,
            (string)($routing['routepolicy'] ?? 'default'),
            !empty($routing['routingfallback']),
            orchestrator_routing_service::PHASE_SELECTION,
            $primaryprovider,
            $historycount,
            $observationcount,
            $catalogselectionmode,
            $embeddingstatus,
            count($runtimecatalog),
            $embeddingrebuildqueued,
            false
        );

        $llm = new llm_call_service($this->store);
        $phaseoutput = [];
        $call = $llm->invoke_for_context_retrying_truncation($threadid, $contextid, $userid, $debugsource, $prompt, $actionclass);
        $rawtext = (string)($call['rawcontent'] ?? '');
        if (!empty($call['truncated'])) {
            // Cut off twice at the token limit: the partial content is discarded, never parsed.
            $phaseoutput = $this->build_truncated_provider_result();
        } else if (empty($call['success'])) {
            $phaseoutput = $this->build_provider_error_result($call);
        } else if ($rawtext === '') {
            $phaseoutput = $this->build_empty_provider_result();
        } else {
            $phaseoutput = $this->interpreter->interpret_phase_output(
                $rawtext,
                orchestrator::PHASE_SELECTION,
                [
                    'contextid' => $contextid,
                    'userid' => $userid,
                ]
            );
            if (is_array($phaseoutput)) {
                $phaseoutput = $this->normalize_selection_phase_output_for_handoff($phaseoutput);
                $phaseoutput['_planner_raw_response'] = $rawtext;
            }
        }

        // Persist normalized routing telemetry and a shadow-only discovery trace.
        // This must never alter the active routing decision path.
        try {
            $flagssnapshot = runtime_feature_flags::snapshot();
            $contextprior = (new context_prior_builder())->build($contextid, [
                'userid' => $userid,
                'namespace_hint' => $this->catalogsvc->resolve_namespace_hint_from_prompt_contracts($promptcontracts),
            ]);
            $routingtelemetry = [
                'catalogselectionmode' => $catalogselectionmode,
                'discovery_stage' => $discoverystage,
                'confidence_score' => $discoveryconfidencescore,
                'escalation_reason' => $discoveryescalationreason,
            ];
            (new routing_decision_log_service())->persist_thread_routing_decision(
                $this->store,
                $threadid,
                $routingtelemetry,
                $flagssnapshot,
                [
                    'promptcontracts' => $promptcontracts,
                    'contextprior' => $contextprior,
                    'recent_skill_names' => (array)($discoverystate['recentskillhistory'] ?? []),
                ]
            );
        } catch (\Throwable $e) {
            // Best-effort discovery enrichment; the base catalog is still usable without it.
            debugging('orchestrator: discovery enrichment failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        $lastusermessage = '';
        foreach (array_reverse($messages) as $msg) {
            if (($msg->role ?? '') === 'user') {
                $lastusermessage = trim((string)($msg->content ?? ''));
                break;
            }
        }

        $selectedskill = $this->extract_selected_skill_from_selection_phase_output($phaseoutput);

        $selectionstate = [
            'prompt' => $prompt,
            'debugsource' => $debugsource,
            'lastusermessage' => $lastusermessage,
            'selected_skill' => $selectedskill,
            'phase' => orchestrator::PHASE_SELECTION,
            'phase_output' => $phaseoutput,
            'response_type' => (string)($phaseoutput['response_type'] ?? ''),
            // Forward the planner's human-readable step intent to the top level so the orchestrator
            // can surface it as the progress step bubble (otherwise it falls back to "Executing
            // <skill>"). The interpreter already produced it on the phase output.
            'next_step_intent' => (string)($phaseoutput['next_step_intent'] ?? ''),
            'message' => (string)($phaseoutput['message'] ?? ''),
            'issue_codes' => (array)($phaseoutput['issue_codes'] ?? []),
            'errors' => (array)($phaseoutput['errors'] ?? []),
            // The classified provider/contract cause; the orchestrator forwards it on the
            // construction skip so finalization can route provider failures to the template.
            'error_class' => (string)($phaseoutput['error_class'] ?? ''),
            'planned_steps' => (array)($phaseoutput['planned_steps'] ?? []),
        ];
        // The selector's own words stay marked as model text (never masked again, model_authored_text).
        return is_array($phaseoutput) && model_authored_text::is_model_message($phaseoutput)
            ? model_authored_text::mark($selectionstate, (string)$phaseoutput['message'])
            : $selectionstate;
    }

    /**
     * Construction phase: execute planner call and interpret response.
     *
     * @param int $threadid
     * @param int $contextid
     * @param int $userid
     * @param array $observations
     * @param array $discoverystate
     * @param array $selectionstate
     * @return array
     */
    public function run_construction(
        int $threadid,
        int $contextid,
        int $userid,
        array $observations,
        array $discoverystate,
        array $selectionstate
    ): array {
        $llm = new llm_call_service($this->store);
        $context = context::instance_by_id($contextid, MUST_EXIST);
        $manager = di::get(ai_manager::class);
        $routing = $this->routingsvc->resolve_action_class_for_phase(
            $manager,
            $context,
            orchestrator_routing_service::PHASE_PARAMETER_CONSTRUCTION
        );
        $actionclass = (string)($routing['actionclass'] ?? generate_text::class);
        $contextid = (int)($discoverystate['contextid'] ?? 0);
        $messages = (array)($discoverystate['messages'] ?? []);
        $adaptivecatalog = (array)($discoverystate['adaptivecatalog'] ?? []);
        $runtimecatalog = (array)($discoverystate['runtimecatalog'] ?? []);
        $plannertracehistory = (array)($discoverystate['plannertracehistory'] ?? []);
        $isfirstassistantturn = !empty($discoverystate['isfirstassistantturn']);
        $haseffectiveobservations = !empty($discoverystate['haseffectiveobservations']);
        $shouldincludeskillcatalog = !empty($discoverystate['shouldincludeskillcatalog']);
        $catalogselectionmode = (string)($discoverystate['catalogselectionmode'] ?? 'none');
        $embeddingstatus = (string)($discoverystate['embeddingstatus'] ?? 'off');
        $embeddingrebuildqueued = !empty($discoverystate['embeddingrebuildqueued']);
        $unavailableskillcatalog = (array)($discoverystate['unavailableskillcatalog'] ?? []);
        $selectedskill = trim((string)($selectionstate['selected_skill'] ?? ''));

        if ($selectedskill === '') {
            return $this->build_selector_handoff_error_result();
        }

        // Skill-agnostic construction passthrough: a skill MAY declare (duck-typed) that it needs no
        // DB-grounded parameter construction — only a free-text query built from the user intent. The
        // engine then skips the construction LLM and builds that one field deterministically. This keeps
        // the engine skill-agnostic while guaranteeing such a skill is never clarified away by the
        // constructor (e.g. the wizard.search_skills fallback dead-ending in thread 531).
        $passthroughfield = $this->resolve_passthrough_construction_field($selectedskill);
        if ($passthroughfield !== '') {
            return $this->build_passthrough_construction_result(
                $selectedskill,
                $passthroughfield,
                $selectionstate,
                $messages
            );
        }

        $constructionruntimecatalog = $this->build_construction_runtime_catalog_for_selected_skill(
            $selectedskill,
            $runtimecatalog,
            $adaptivecatalog,
            $contextid,
            $userid
        );

        $constructionobservations = array_values($observations);
        $constructionobservations = array_merge(
            $constructionobservations,
            $this->build_phase_handoff_observations($discoverystate, $selectionstate)
        );

        $systemprompt = $this->build_system_prompt(
            $contextid,
            $userid,
            orchestrator::PHASE_PARAMETER_CONSTRUCTION,
            $actionclass,
            $haseffectiveobservations || !empty($constructionobservations),
            $adaptivecatalog,
            $constructionruntimecatalog,
            $isfirstassistantturn,
            $shouldincludeskillcatalog
        );
        $runtimeblocks = $this->runtimecontextsvc->build(
            $threadid,
            $contextid,
            orchestrator::PHASE_PARAMETER_CONSTRUCTION,
            $isfirstassistantturn,
            !empty($constructionobservations),
            $constructionruntimecatalog,
            $unavailableskillcatalog,
            $messages,
            '',
            $constructionobservations,
            $this->catalogsvc->catalog_mode_is_static($catalogselectionmode)
        );
        $autoconfirmmode = $this->store->is_confirmation_allowed_for_thread($userid, $contextid, $threadid);
        // Engine-known readonly flag of the selected skill: lets the prompt state the valid
        // constructor response_type deterministically instead of leaving the mutation judgement
        // to the model (#2199 issue 2).
        $selectedskillobject = $this->registry->get_skill($selectedskill);
        $selectedskillisreadonly = $selectedskillobject !== null ? $selectedskillobject->is_read_only() : null;
        // What the selected skill needs from this construction, from its declared contract (wave 30).
        $selectedskillinput = null;
        if ($selectedskillobject !== null) {
            $contract = (array)$selectedskillobject->get_prompt_contract()->to_array();
            $selectedskillinput = [
                'required_input' => (array)($contract['required_input'] ?? []),
                'required_groups' => (array)($contract['required_groups'] ?? []),
            ];
        }
        $prompt = $this->build_prompt(
            $systemprompt,
            $messages,
            $constructionobservations,
            orchestrator::PHASE_PARAMETER_CONSTRUCTION,
            $runtimeblocks['stable'],
            $plannertracehistory,
            $autoconfirmmode,
            [],
            $runtimeblocks['volatile'],
            $selectedskillisreadonly,
            [],
            $selectedskillinput
        );

        $historycount = count(
            $this->promptprofilesvc->select_history_messages($messages, orchestrator::PHASE_PARAMETER_CONSTRUCTION)
        );
        $observationcount = count($constructionobservations);
        $primaryprovider = (string)($routing['primaryprovider'] ?? '');
        $debugsource = $this->routingsvc->build_debug_source(
            $actionclass,
            (string)($routing['routepolicy'] ?? 'default'),
            !empty($routing['routingfallback']),
            orchestrator_routing_service::PHASE_PARAMETER_CONSTRUCTION,
            $primaryprovider,
            $historycount,
            $observationcount,
            $catalogselectionmode,
            $embeddingstatus,
            count($constructionruntimecatalog),
            $embeddingrebuildqueued,
            false
        );

        $call = $llm->invoke_for_context_retrying_truncation($threadid, $contextid, $userid, $debugsource, $prompt, $actionclass);
        $rawtext = (string)($call['rawcontent'] ?? '');

        if (!empty($call['truncated'])) {
            // Cut off twice at the token limit: the partial content is discarded, never parsed.
            return $this->build_truncated_provider_result();
        }

        if (empty($call['success'])) {
            return $this->build_provider_error_result($call);
        }

        if ($rawtext === '') {
            return $this->build_empty_provider_result();
        }

        $lastusermessage = (string)($selectionstate['lastusermessage'] ?? '');
        $constructionallowedskills = [$selectedskill];
        $interpreted = $this->interpreter->interpret_phase_output(
            $rawtext,
            orchestrator::PHASE_PARAMETER_CONSTRUCTION,
            [
                'contextid' => $contextid,
                'userid' => $userid,
                'lastusermessage' => $lastusermessage,
                'allowed_skills' => $constructionallowedskills,
            ]
        );
        if (is_array($interpreted)) {
            $interpreted['_planner_raw_response'] = $rawtext;
            // A "sufficient" from the constructor is not an answer, it is a missing command. The interpreter
            // passes "sufficient" through because the selector legitimately ends a turn with it; here the
            // phase is constructor-only. Baseline run 27 and re-run N18 (LM-2): the constructor read the
            // MEMORY block of its own prompt and recited the facts instead of staging wizard.list_memories,
            // so the user got text that never came from the skill. Engine state decides: a read-only skill
            // that accepts empty input is called as it stands; anything else becomes the repairable question
            // the repair round below already knows how to handle. No wording is inspected.
            if ((string)($interpreted['response_type'] ?? '') === 'sufficient') {
                $interpreted = $this->replace_constructor_sufficient($interpreted, $selectedskill);
            }
            if ((string)($interpreted['response_type'] ?? '') === 'clarification') {
                // A constructor question is about the already-selected skill: carry that
                // state on the result so the pending-action continuity record survives the
                // turn, and mark the question as a real blocking one. It is the answer of this
                // construction - wave 30 removed the repair round that called the constructor a
                // second time (outside the flowchart); a contract breach is re-planned through
                // the selector (agent_runtime, CONTRACT_CONFIRMATION_DOWNGRADED_TO_CLARIFICATION).
                $interpreted['selected_skill'] = $selectedskill;
                // A construction that states the skill does not fit (wave 30, E4) is not missing input: it must
                // not seed the next turn's continuity with the unfit skill.
                $unfit = in_array('CONSTRUCTION_SKILL_UNFIT', (array)($interpreted['issue_codes'] ?? []), true);
                $interpreted['issue_codes'] = array_values(array_unique(array_merge(
                    (array)($interpreted['issue_codes'] ?? []),
                    $unfit ? [] : ['CONSTRUCTION_INPUT_REQUIRED']
                )));
            }
        }

        return $interpreted;
    }

    /**
     * Normalize selection output to an explicit single-skill selector handoff.
     *
     * This strips accidental parameter payloads from selection commands and keeps
     * only the selected skill identity for constructor handoff.
     *
     * @param array $phaseoutput
     * @return array
     */
    private function normalize_selection_phase_output_for_handoff(array $phaseoutput): array {
        $responsetype = trim((string)($phaseoutput['response_type'] ?? ''));
        if ($responsetype !== 'skill_call') {
            if (!isset($phaseoutput['selected_skill'])) {
                $phaseoutput['selected_skill'] = '';
            }
            return $phaseoutput;
        }

        $commands = (array)($phaseoutput['commands'] ?? []);
        if (count($commands) !== 1) {
            return $this->build_selection_contract_error_result(
                'CONTRACT_SELECTION_SINGLE_COMMAND_REQUIRED',
                'CONTRACT_VIOLATION: selection phase must emit exactly one selector command.'
            );
        }

        $command = is_array($commands[0]) ? $commands[0] : [];
        $selectedskill = trim((string)($phaseoutput['selected_skill'] ?? ''));
        if ($selectedskill === '') {
            $selectedskill = trim((string)($command['skill'] ?? ''));
        }
        if ($selectedskill === '') {
            return $this->build_selection_contract_error_result(
                'CONTRACT_SELECTION_SKILL_MISSING',
                'CONTRACT_VIOLATION: selection phase skill_call did not provide a selected skill.'
            );
        }

        $version = max(1, (int)($command['version'] ?? 1));
        $phaseoutput['selected_skill'] = $selectedskill;
        $phaseoutput['commands'] = [[
            'skill' => $selectedskill,
            'version' => $version,
            'input' => [],
        ]];

        return $phaseoutput;
    }

    /**
     * Restrict construction runtime catalog to the selector-chosen skill only.
     *
     * @param string $selectedskill
     * @param array[] $runtimecatalog
     * @param array[] $adaptivecatalog
     * @param int $contextid
     * @param int $userid
     * @return array[]
     */
    private function build_construction_runtime_catalog_for_selected_skill(
        string $selectedskill,
        array $runtimecatalog,
        array $adaptivecatalog,
        int $contextid,
        int $userid
    ): array {
        $filtered = [];

        foreach ($runtimecatalog as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (trim((string)($entry['skill'] ?? '')) !== $selectedskill) {
                continue;
            }
            $filtered[] = $this->enrich_construction_catalog_entry($selectedskill, $entry, $contextid, $userid);
        }

        if (!empty($filtered)) {
            return array_values($filtered);
        }

        foreach ($adaptivecatalog as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (trim((string)($entry['skill'] ?? '')) !== $selectedskill) {
                continue;
            }
            $filtered[] = $this->enrich_construction_catalog_entry($selectedskill, $entry, $contextid, $userid);
        }

        return array_values($filtered);
    }

    /**
     * Turn a constructor "sufficient" into the command it failed to build, or into a repairable question.
     *
     * @param array $interpreted Interpreted constructor output with response_type sufficient.
     * @param string $selectedskill The skill the selection phase chose.
     * @return array
     */
    private function replace_constructor_sufficient(array $interpreted, string $selectedskill): array {
        $skill = $this->registry->get_skill($selectedskill);
        $contract = $skill !== null ? (array)$skill->get_prompt_contract()->to_array() : [];
        $callable = $skill !== null
            && $skill->is_read_only()
            && (bool)($contract['accepts_empty_input'] ?? false);

        $codes = array_values(array_unique(array_merge(
            (array)($interpreted['issue_codes'] ?? []),
            ['CONSTRUCTION_ANSWERED_INSTEAD_OF_CALLING']
        )));
        $interpreted['selected_skill'] = $selectedskill;
        $interpreted['issue_codes'] = $codes;

        if ($callable) {
            $interpreted['response_type'] = 'skill_call';
            $interpreted['message'] = '';
            $interpreted['commands'] = [['skill' => $selectedskill, 'version' => 1, 'input' => []]];
            return $interpreted;
        }

        // Not callable as it stands: a contract breach, re-planned once through the selector (wave 30).
        $interpreted['response_type'] = 'clarification';
        $interpreted['commands'] = [];
        $interpreted['issue_codes'] = array_values(array_unique(array_merge(
            $codes,
            [interpreter::CONFIRMATION_DOWNGRADE_CODE]
        )));
        return $interpreted;
    }

    /**
     * Drop the example values of query fields (F79).
     *
     * @param array $exampleparameters
     * @return array
     */
    public static function without_query_field_examples(array $exampleparameters): array {
        return array_filter(
            $exampleparameters,
            static fn($key): bool => !self::is_query_field_name((string)$key),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * A query field: its name is "query" or ends in "query".
     *
     * @param string $name
     * @return bool
     */
    private static function is_query_field_name(string $name): bool {
        $name = strtolower(trim($name));
        return $name === 'query' || str_ends_with($name, 'query');
    }

    /**
     * Attach concrete parameter examples for the selected construction skill.
     *
     * @param string $selectedskill
     * @param array $entry
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    private function enrich_construction_catalog_entry(
        string $selectedskill,
        array $entry,
        int $contextid,
        int $userid
    ): array {
        $skill = $this->registry->get_skill($selectedskill);
        if ($skill === null) {
            return $entry;
        }

        // F79 (baseline runs 25-32): an example value of a query field is bait, not help. The constructor
        // copied "completion confirmation" for "Abschlussbestätigung" and "reminder 7 days" for "rappel à 7
        // jours" - verbatim the skill's example - because the example stood next to the field while the
        // TARGET NAMES rule stood 6000 characters earlier; 21 commands in eight runs carried an example
        // value as target. A query field's value is the user's own words: no example is shown for it.
        $exampleparameters = self::without_query_field_examples((array)$skill->get_example_input());
        if (!empty($exampleparameters)) {
            // Wave 30 (UO-3, Nachlauf 32 threads 11970/11996): the example is load-bearing for the SHAPE (F36),
            // but its VALUES were copied as well - update_option's example session 18:00-20:00 became the time of a
            // session the user never gave. The rule stands directly before the example, where the value is taken.
            // Wave 32: the rule for example values lives once in the constructor template (RULES 1).
            $entry['example_parameters'] = $exampleparameters;
        }

        // The input fields of THIS skill (F29, baseline run 12): the construction phase has exactly one skill in
        // scope, so its fields travel with the prompt instead of the stubbed schema placeholder. Without them the
        // constructor cannot know what it may fill and asks the user instead of building the command — the single
        // largest cause of prompts that end in a question (16 of 34 unreached booking prompts in run 12).
        // It replaces minimal_input here on purpose: two field lists in one prompt can only drift apart, and the
        // schema is the one the executor validates against. minimal_input stays in the selector catalog.
        $inputfields = skill_input_schema_projection::for_skill($skill);
        if (!empty($inputfields)) {
            // The rule for query fields sits where the decision is made - directly before the fields (F79),
            // not only in the template block far above. Engine text, planner-only.
            // Wave 32: the rule for query fields lives once in the constructor template (RULES 3).
            $entry['input_fields'] = $inputfields;
            unset($entry['minimal_input']);
        }

        // The IS:/NOT: sibling discrimination (#2453) is for the SELECTOR, which still has to choose.
        // Here the choice is made: a sentence about what a NEIGHBOUR skill does cannot help fill a field,
        // costs tokens on every construction call, and can mislead — in baseline run 25 (TSA-4) the
        // contract named "the unit list (list_units)" and the constructor asked the user for a unit the
        // skill does not require. Dropped for the same reason minimal_input is dropped above.
        unset($entry['is'], $entry['not'], $entry['when']);

        // In the construction phase exactly one skill is in scope, so we surface ALL of its prompt-pack
        // guidance unconditionally (no lexical trigger gate). This is the only place situational rules
        // — e.g. "for several options with the same name, search first to obtain their IDs and use
        // optionid" — actually reach the constructor. Without this, get_contextual_prompt_packs() only
        // feeds the embeddings catalog and never the live planner prompt, and trigger-based gating made
        // such guidance language-dependent (it silently vanished for non-English requests).
        $guidance = $this->collect_skill_guidance_lines($skill);
        if (!empty($guidance)) {
            $entry['guidance'] = $guidance;
        }

        // Live grounding for THIS context: DB-derived facts the constructor would otherwise
        // guess (e.g. the actual price category identifiers, thread 593). Merged AFTER the static
        // example/guidance so dynamic example_parameters win and dynamic guidance is appended, not
        // overwritten. See base_skill::get_dynamic_construction_hints().
        if (method_exists($skill, 'get_dynamic_construction_hints')) {
            $dynamic = (array)$skill->get_dynamic_construction_hints($contextid, $userid);
            $dynamicexample = (array)($dynamic['example_parameters'] ?? []);
            if (!empty($dynamicexample)) {
                $entry['example_parameters'] = array_merge(
                    (array)($entry['example_parameters'] ?? []),
                    $dynamicexample
                );
            }
            $dynamicguidance = array_values(array_filter(array_map('strval', (array)($dynamic['guidance'] ?? []))));
            if (!empty($dynamicguidance)) {
                $entry['guidance'] = array_merge((array)($entry['guidance'] ?? []), $dynamicguidance);
            }
        }

        return $entry;
    }

    /**
     * Collect all contextual prompt-pack guidance lines declared by a skill, unconditionally.
     *
     * Trigger arrays on the packs are ignored on purpose: in the construction phase the skill is
     * already chosen, so relevance filtering is unnecessary and the (lexical, language-specific)
     * trigger gate would only drop useful guidance.
     *
     * @param object $skill
     * @return string[]
     */
    private function collect_skill_guidance_lines(object $skill): array {
        if (!method_exists($skill, 'get_contextual_prompt_packs')) {
            return [];
        }

        $lines = [];
        foreach ((array)$skill->get_contextual_prompt_packs() as $pack) {
            if (!is_array($pack)) {
                continue;
            }
            foreach ((array)($pack['guidance'] ?? []) as $line) {
                $line = trim((string)$line);
                if ($line !== '') {
                    $lines[] = $line;
                }
            }
        }

        return array_values(array_unique($lines));
    }

    /**
     * Extract an explicitly selected skill from selection-phase output.
     *
     * @param array $phaseoutput
     * @return string
     */
    private function extract_selected_skill_from_selection_phase_output(array $phaseoutput): string {
        return trim((string)($phaseoutput['selected_skill'] ?? ''));
    }

    /**
     * Build a standardized selection-phase contract error payload.
     *
     * @param string $issuecode
     * @param string $error
     * @return array
     */
    private function build_selection_contract_error_result(string $issuecode, string $error): array {
        return [
            'response_type' => 'error',
            // Deliberately empty: an internal contract violation is NOT a provider
            // error. The message is composed downstream — synchronizer presentation
            // fed by the error observation, or the class template as fallback.
            'message' => '',
            'error_class' => 'internal_contract',
            'commands' => [],
            'selected_skill' => '',
            'ambiguities' => [],
            'errors' => [$error],
            'issue_codes' => [$issuecode],
        ];
    }

    /**
     * Build a standardized selector-handoff error when construction lacks selected_skill.
     *
     * @return array
     */
    private function build_selector_handoff_error_result(): array {
        return [
            'response_type' => 'error',
            // Deliberately empty — internal handoff error, resolved downstream
            // (CONTRACT_SELECTION_SKILL_MISSING has a dedicated template text).
            'message' => '',
            'error_class' => 'internal_contract',
            'commands' => [],
            'ambiguities' => [],
            'errors' => ['CONTRACT_VIOLATION: selection phase did not provide a selected_skill for construction.'],
            'issue_codes' => ['CONTRACT_SELECTION_SKILL_MISSING'],
        ];
    }

    /**
     * The construction-passthrough field a skill declares, or '' when it constructs normally.
     *
     * Duck-typed, skill-agnostic: a skill opts in by exposing get_passthrough_construction_field(): string.
     * The engine never names a concrete skill — it only asks whether THIS selected skill wants passthrough.
     *
     * @param string $skillname
     * @return string the single input field to receive the query, or '' for normal LLM construction
     */
    private function resolve_passthrough_construction_field(string $skillname): string {
        $skill = $this->registry->get_skill($skillname);
        if ($skill !== null && method_exists($skill, 'get_passthrough_construction_field')) {
            return trim((string)$skill->get_passthrough_construction_field());
        }
        return '';
    }

    /**
     * Deterministic construction result for a passthrough skill (no construction LLM).
     *
     * Builds the query from the selector's next_step_intent (which describes the wanted action),
     * falling back to the latest user message. Guarantees an executable skill_call instead of the
     * construction LLM occasionally clarifying the skill away.
     *
     * @param string $skillname
     * @param string $field the input field to receive the query
     * @param array $selectionstate
     * @param \stdClass[] $messages
     * @return array
     */
    private function build_passthrough_construction_result(
        string $skillname,
        string $field,
        array $selectionstate,
        array $messages
    ): array {
        $query = trim((string)($selectionstate['next_step_intent'] ?? ''));
        if ($query === '') {
            for ($i = count($messages) - 1; $i >= 0; $i--) {
                $message = $messages[$i];
                $role = is_array($message) ? (string)($message['role'] ?? '') : (string)($message->role ?? '');
                if ($role !== 'user') {
                    continue;
                }
                $content = is_array($message) ? (string)($message['content'] ?? '') : (string)($message->content ?? '');
                $query = trim($content);
                break;
            }
        }
        if ($query === '') {
            $query = 'find a matching capability';
        }

        return [
            'response_type' => 'skill_call',
            'message' => '',
            // Populate BOTH input and parameters with the query. The executor (and a readonly skill,
            // which skips the preflight parameters->input mapping) reads `input`; this result is built
            // directly here and is NOT re-parsed by the interpreter, so a parameters-only command would
            // reach a readonly skill with an empty input — exactly the empty-query failure that made the
            // search_skills RAG fallback dead-end ("Search query must not be empty.", thread 5).
            'commands' => [[
                'skill' => $skillname,
                'version' => 1,
                'input' => [$field => $query],
                'parameters' => [$field => $query],
            ]],
            'ambiguities' => [],
            'errors' => [],
            'issue_codes' => [],
            'next_step_intent' => $query,
            'selected_skill' => $skillname,
            'lang' => (string)($selectionstate['lang'] ?? ''),
            'user_lang' => (string)($selectionstate['user_lang'] ?? ''),
        ];
    }

    /**
     * Build compact observations to hand off discovery/selection outcomes.
     *
     * @param array $discoverystate
     * @param array $selectionstate
     * @return string[]
     */
    private function build_phase_handoff_observations(array $discoverystate, array $selectionstate): array {
        $observations = [];
        $discoverypayload = [
            'phase' => orchestrator::PHASE_DISCOVERY,
            'response_type' => (string)($discoverystate['response_type'] ?? ''),
            'message' => (string)($discoverystate['message'] ?? ''),
            'issue_codes' => (array)($discoverystate['issue_codes'] ?? []),
            'errors' => (array)($discoverystate['errors'] ?? []),
        ];
        $selectionpayload = [
            'phase' => orchestrator::PHASE_SELECTION,
            'response_type' => (string)($selectionstate['response_type'] ?? ''),
            'message' => (string)($selectionstate['message'] ?? ''),
            'selected_skill' => (string)($selectionstate['selected_skill'] ?? ''),
            'issue_codes' => (array)($selectionstate['issue_codes'] ?? []),
            'errors' => (array)($selectionstate['errors'] ?? []),
        ];

        $discoveryjson = $this->json_encode_or_empty($discoverypayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($discoveryjson !== '') {
            $observations[] = 'phase_handoff.discovery=' . $discoveryjson;
        }

        $selectionjson = $this->json_encode_or_empty($selectionpayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($selectionjson !== '') {
            $observations[] = 'phase_handoff.selection=' . $selectionjson;
        }

        return $observations;
    }
}
