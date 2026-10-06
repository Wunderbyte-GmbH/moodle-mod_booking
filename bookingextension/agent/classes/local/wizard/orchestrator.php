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
 * AI orchestration layer.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent\local\wizard;

use core\context;
use core_ai\manager as ai_manager;
use core_ai\aiactions\explain_text;
use core_ai\aiactions\generate_text;
use core_ai\aiactions\summarise_text;
use core\di;
use bookingextension_agent\local\wizard\config\runtime_feature_flags;
use bookingextension_agent\local\wizard\interfaces\agent_interpreter;
use bookingextension_agent\local\wizard\queue\queue_manager;
use bookingextension_agent\local\wizard\services\assistant_state_guidance_service;
use bookingextension_agent\local\wizard\services\completed_command_history_service;
use bookingextension_agent\local\wizard\services\user_memory_service;
use bookingextension_agent\local\wizard\services\llm\llm_call_service;
use bookingextension_agent\local\wizard\services\phase_prompt_bundle_builder;
use bookingextension_agent\local\wizard\services\orchestrator_prompt_profile_service;
use bookingextension_agent\local\wizard\services\orchestrator_routing_service;
use bookingextension_agent\local\wizard\services\planner_result_composer;
use bookingextension_agent\local\wizard\services\provider_status_service;
use bookingextension_agent\local\wizard\services\planner_catalog_service;
use bookingextension_agent\local\wizard\services\runtime_context_block_builder;
use bookingextension_agent\local\wizard\services\discovery_phase_service;
use bookingextension_agent\local\wizard\services\model_authored_text;
use bookingextension_agent\local\wizard\services\turn_skill_exclusions;
use bookingextension_agent\local\wizard\services\planner_phase_service;
use bookingextension_agent\local\wizard\services\synchronizer_prompt_builder;
use bookingextension_agent\local\wizard\services\security\authorization_service;

/**
 * Orchestrates LLM interaction via core_ai.
 *
 * Responsibilities:
 *  - Assemble a state-based system prompt (not full raw chat history).
 *  - Send the conversation context to the AI provider.
 *  - Hand the raw response off to the interpreter.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class orchestrator {
    use provider_error_result_trait;

    /** Discovery planner phase identifier. */
    public const PHASE_DISCOVERY = 'discovery';

    /** Selection planner phase identifier. */
    public const PHASE_SELECTION = 'selection';

    /** Parameter construction planner phase identifier. */
    public const PHASE_PARAMETER_CONSTRUCTION = 'parameter_construction';

    /** Default model for skill-catalog embeddings. */
    public const EMBEDDINGS_DEFAULT_MODEL = 'text-embedding-3-small';

    /** Default embedding dimensions. */
    public const EMBEDDINGS_DEFAULT_DIMENSIONS = 1536;

    /** Default number of best matching skills to inject for first planner step. */
    public const EMBEDDINGS_DEFAULT_TOP_K = 12;

    /** Debounce window (seconds) for scheduling embeddings rebuild skill. */
    public const EMBEDDINGS_REBUILD_DEBOUNCE_SECONDS = 100;

    /** Wunderbyte planner action class name. */
    private const WB_ACTION_PLANNER_DECIDE = wb_action_names::PLANNER_DECIDE;

    /** Wunderbyte final reply action class name. */
    private const WB_ACTION_GENERATE_AGENT_REPLY = wb_action_names::GENERATE_AGENT_REPLY;

    /**
     * Read-only runtime feature-flag snapshot used by orchestration consumers.
     *
     * @return array
     */
    public static function get_runtime_feature_flags_snapshot(): array {
        return runtime_feature_flags::snapshot();
    }

    /** @var skill_registry */
    private skill_registry $registry;

    /** @var interpreter */
    private agent_interpreter $interpreter;

    /** @var conversation_store */
    private conversation_store $store;

    /** @var completed_command_history_service */
    private completed_command_history_service $completedhistorysvc;

    /** @var assistant_state_guidance_service */
    private assistant_state_guidance_service $assistantsummariesvc;

    /** @var orchestrator_routing_service */
    private orchestrator_routing_service $orchestratorroutingsvc;

    /** @var planner_catalog_service */
    private planner_catalog_service $plannercatalogsvc;

    /** @var runtime_context_block_builder */
    private runtime_context_block_builder $runtimecontextsvc;

    /** @var orchestrator_prompt_profile_service */
    private orchestrator_prompt_profile_service $promptprofilesvc;

    /** @var phase_prompt_bundle_builder */
    private phase_prompt_bundle_builder $promptbundlebuilder;

    /** @var synchronizer_prompt_builder */
    private synchronizer_prompt_builder $synchronizerpromptbuilder;

    /** @var discovery_phase_service */
    private discovery_phase_service $discoveryphasesvc;

    /** @var planner_phase_service */
    private planner_phase_service $plannerphasesvc;

    /**
     * Constructor.
     *
     * @param skill_registry      $registry
     * @param agent_interpreter  $interpreter
     * @param conversation_store $store
     */
    public function __construct(
        skill_registry $registry,
        agent_interpreter $interpreter,
        conversation_store $store
    ) {
        $this->registry = $registry;
        $this->interpreter = $interpreter;
        $this->store = $store;
        $this->completedhistorysvc = new completed_command_history_service($store);
        $this->assistantsummariesvc = new assistant_state_guidance_service();
        $this->orchestratorroutingsvc = new orchestrator_routing_service(
            self::WB_ACTION_PLANNER_DECIDE,
            wb_action_names::PLANNER_CONSTRUCT
        );
        $this->plannercatalogsvc = new planner_catalog_service($this->assistantsummariesvc);
        $this->runtimecontextsvc = new runtime_context_block_builder(
            $this->store,
            $this->completedhistorysvc,
            $this->plannercatalogsvc
        );
        $this->promptprofilesvc = new orchestrator_prompt_profile_service();
        $this->promptbundlebuilder = new phase_prompt_bundle_builder($this->registry, $this->promptprofilesvc);
        $this->synchronizerpromptbuilder = new synchronizer_prompt_builder();
        $this->discoveryphasesvc = new discovery_phase_service(
            $this->store,
            $this->registry,
            $this->orchestratorroutingsvc,
            $this->promptprofilesvc,
            $this->plannercatalogsvc,
            $this->runtimecontextsvc,
            $this->promptbundlebuilder
        );
        $this->plannerphasesvc = new planner_phase_service(
            $this->store,
            $this->registry,
            $this->interpreter,
            $this->orchestratorroutingsvc,
            $this->promptprofilesvc,
            $this->plannercatalogsvc,
            $this->runtimecontextsvc,
            $this->promptbundlebuilder
        );
    }

    /**
     * Check whether a Moodle core_ai provider is configured and available.
     *
     * @param int $contextid   Course-module id.
     * @param int $userid User id.
     * @return bool
     */
    /**
     * Resolve centralized provider/runtime status for booking agent execution.
     *
     * This is the single source of truth for availability checks used by both
     * readiness UI and runtime message processing.
     *
     * @param int $contextid Moodle context id (any level the agent runs at).
     * @return array
     */
    public function get_runtime_provider_status(int $contextid): array {
        // Logic lives in provider_status_service (orchestrator split, provider-status seam);
        // this thin delegator preserves the public API for aiready / ai_send_message /
        // activate_trial_context. The same routing service instance is reused.
        return (new provider_status_service($this->orchestratorroutingsvc))->get_status($contextid);
    }

    /**
     * Process a user message: call the LLM and interpret the response.
     *
     * @param  int      $threadid     Thread id.
     * @param  int      $contextid         Course-module id.
     * @param  int      $userid       User id.
     * @param  string[] $observations Optional structured observation strings from prior internal loop steps.
     *                                Injected into the prompt so the LLM can reason about tool results
     *                                before producing its next response.  Never persisted to the DB.
     * @param  agent_state|null $agentstate Optional per-run loop state for cache reuse across steps.
     * @return array  Interpreter result.
     */
    public function process(
        int $threadid,
        int $contextid,
        int $userid,
        array $observations = [],
        ?agent_state $agentstate = null
    ): array {
        $context = context::instance_by_id($contextid, MUST_EXIST);
        $manager = di::get(ai_manager::class);
        $evaluator = new skill_executability_evaluator($this->registry, new authorization_service());
        $discoverystate = $this->run_discovery_phase(
            $threadid,
            $contextid,
            $userid,
            $observations,
            $agentstate,
            $context,
            $manager,
            $evaluator
        );

        $selectionstate = $this->run_selection_phase(
            $threadid,
            $contextid,
            $userid,
            $observations,
            $discoverystate,
            $context,
            $manager
        );
        // Wave 32: a selection of a skill the construction already rejected in this turn is not constructed again
        // (never A -> B -> A). The turn ends as the construction's own honest answer about that skill.
        $selectedforturn = trim((string)($selectionstate['selected_skill'] ?? ''));
        $excludedreasons = $selectedforturn !== '' ? turn_skill_exclusions::reasons($this->store, $threadid) : [];
        if (array_key_exists($selectedforturn, $excludedreasons) && trim($excludedreasons[$selectedforturn]) !== '') {
            $selectionstate['response_type'] = 'clarification';
            $selectionstate['message'] = $excludedreasons[$selectedforturn];
            $selectionstate['selected_skill'] = '';
            $selectionstate['commands'] = [];
            $selectionstate['issue_codes'] = ['CONSTRUCTION_SKILL_UNFIT'];
            $selectionstate['errors'] = [];
        }

        $intent = trim((string)($selectionstate['next_step_intent'] ?? ''));
        $selectedskill = trim((string)($selectionstate['selected_skill'] ?? ''));
        if ($intent === '' && $selectedskill !== '') {
            $intent = 'Executing ' . $selectedskill;
        }

        // Do not announce work that already happened in this thread. The decision comes from the completed
        // command history (engine state); it replaces the regex list that used to inspect the planner's
        // wording in two of the three baseline languages (HARD RULE: no lexical detection).
        if ($intent !== '' && $selectedskill !== '') {
            $completed = $this->completedhistorysvc->merge_from_queue(
                $threadid,
                $this->completedhistorysvc->extract_from_messages($this->store->get_messages($threadid))
            );
            if ($this->completedhistorysvc->contains_skill($completed, $selectedskill)) {
                $intent = '';
            }
        }

        if ($intent !== '') {
            $stepnum = ($agentstate !== null) ? ($agentstate->step_count() + 1) : 1;
            $this->store->add_step_message($threadid, $stepnum, $intent);
        }

        $selectionresponsetype = trim((string)($selectionstate['response_type'] ?? ''));
        if ($selectionresponsetype !== 'skill_call') {
            $constructionstate = [
                'phase' => self::PHASE_PARAMETER_CONSTRUCTION,
                'response_type' => $selectionresponsetype,
                'message' => (string)($selectionstate['message'] ?? ''),
                'commands' => (array)($selectionstate['commands'] ?? []),
                'ambiguities' => (array)($selectionstate['ambiguities'] ?? []),
                'errors' => (array)($selectionstate['errors'] ?? []),
                'issue_codes' => (array)($selectionstate['issue_codes'] ?? []),
                // The classified cause must survive the construction skip: without it a
                // provider outage during selection loses its error_class and the user gets
                // the generic "could not reliably parse" fallback instead of the class text.
                'error_class' => (string)($selectionstate['error_class'] ?? ''),
                'lang' => (string)($selectionstate['lang'] ?? ''),
                'user_lang' => (string)($selectionstate['user_lang'] ?? ''),
            ];
            // The selector's own words stay marked as model text (never masked again, model_authored_text).
            if (model_authored_text::is_model_message($selectionstate)) {
                $constructionstate = model_authored_text::mark($constructionstate, (string)$selectionstate['message']);
            }
        } else {
            $constructionstate = $this->run_construction_phase(
                $threadid,
                $contextid,
                $userid,
                $observations,
                $discoverystate,
                $selectionstate
            );
        }

        $plannerresultcomposer = new planner_result_composer();
        return $plannerresultcomposer->compose(
            $discoverystate,
            $selectionstate,
            $constructionstate
        );
    }

    /**
     * Process a dedicated synchronizer finalization step.
     *
     * This path is intentionally separate from planner phase execution so that
     * final reply polishing does not reuse planner step routing.
     *
     * @param int $threadid
     * @param int $contextid
     * @param int $userid
     * @param string[] $observations
     * @param string $continuation
     * @param string[] $omittedfields Detail fields read skills did not look up this turn.
     * @param string[] $activetokens Anonymizer tokens active in this thread.
     * @param string $replylanguage ISO code of the conversation's reply language, '' = none.
     * @return array
     */
    public function process_synchronizer(
        int $threadid,
        int $contextid,
        int $userid,
        array $observations = [],
        string $continuation = synchronizer_prompt_builder::CONTINUATION_NONE,
        array $omittedfields = [],
        array $activetokens = [],
        string $replylanguage = ''
    ): array {
        $context = context::instance_by_id($contextid, MUST_EXIST);
        $manager = di::get(ai_manager::class);
        $messages = array_values(array_filter(
            $this->store->get_messages($threadid),
            static fn($msg): bool => (string)($msg->role ?? '') !== 'step'
        ));
        $contextid = (int)$context->id;
        $isfirstassistantturn = $this->is_first_assistant_turn($messages);
        $routing = $this->resolve_synchronizer_action_class($manager, $context);
        $actionclass = (string)($routing['actionclass'] ?? generate_text::class);
        $routepolicy = (string)($routing['routepolicy'] ?? 'sync_default');
        $routingfallback = !empty($routing['routingfallback']);

        $systemprompt = $this->synchronizerpromptbuilder->build_system_prompt($actionclass);
        $runtimeblocks = $this->build_runtime_context_block(
            $threadid,
            $contextid,
            self::PHASE_SELECTION,
            $isfirstassistantturn,
            !empty($observations),
            [],
            [],
            $messages,
            user_memory_service::SCOPE_SYNCHRONIZATION,
            $observations,
            false
        );
        $runtimestate = $runtimeblocks['volatile'];
        // Surface remaining planned placeholders with their TRUE execution status. Whether they
        // still run is decided by the engine's continuation state, never assumed: after a
        // confirmation_request they run once the user confirms; on every other terminal state
        // the turn is over and they will NOT run — the reply must say so instead of promising
        // automatic follow-up (thread 558: "Sprint 5 wird automatisch noch erstellt" on a
        // terminal clarification, with the orphaned placeholder still in the queue).
        $pendingintents = (new queue_manager($this->store, $this->registry))
            ->get_planned_placeholder_intents($threadid);
        if (!empty($pendingintents)) {
            // Wave 32: a list of engine state, no embedded rules (synchronizer rules 3/4 handle it). The
            // awaiting-confirmation header was unreachable - a confirmation_request never reaches the synchronizer.
            $runtimestate .= "\n\nUNEXECUTED PLANNED STEPS:\n";
            foreach ($pendingintents as $idx => $intent) {
                $runtimestate .= ($idx + 1) . '. ' . trim($intent) . "\n";
            }
        }
        $prompt = $this->synchronizerpromptbuilder->build_prompt(
            $systemprompt,
            $messages,
            $observations,
            $runtimeblocks['stable'],
            $runtimestate,
            $continuation,
            $omittedfields,
            $activetokens,
            $replylanguage
        );

        $llm = new llm_call_service($this->store);
        $debugsource = 'sync|st=sr|ac=' . ($actionclass === self::WB_ACTION_GENERATE_AGENT_REPLY ? 'agr' : 'gen')
            . '|rt=' . ($routepolicy === 'sync_wunderbyte' ? 'wb' : 'df')
            . '|fb=' . ($routingfallback ? '1' : '0')
            . '|ob=' . count($observations);

        $call = $llm->invoke_for_context_retrying_truncation($threadid, $contextid, $userid, $debugsource, $prompt, $actionclass);
        $rawtext = (string)($call['rawcontent'] ?? '');
        if (!empty($call['truncated'])) {
            // Cut off twice at the token limit: the partial reply is discarded, never relayed.
            return $this->build_truncated_provider_result();
        }

        if (empty($call['success'])) {
            return $this->build_provider_error_result($call);
        }

        if ($rawtext === '') {
            return $this->build_empty_provider_result();
        }

        $interpreted = $this->interpreter->interpret($rawtext, $contextid, $userid, '');
        if (is_array($interpreted)) {
            $interpreted['_planner_raw_response'] = $rawtext;
        }

        return $interpreted;
    }

    /**
     * Resolve synchronizer action class with dedicated fallback chain.
     *
     * @param ai_manager $manager
     * @param context $context
     * @return array{actionclass:string, routepolicy:string, routingfallback:bool}
     */
    private function resolve_synchronizer_action_class(ai_manager $manager, context $context): array {
        try {
            if ($manager->is_action_available(self::WB_ACTION_GENERATE_AGENT_REPLY)) {
                return [
                    'actionclass' => self::WB_ACTION_GENERATE_AGENT_REPLY,
                    'routepolicy' => 'sync_wunderbyte',
                    'routingfallback' => false,
                ];
            }
        } catch (\Throwable $e) {
            // Best-effort: fall through to the next available action below.
            debugging('orchestrator: provider routing resolution failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        if ($this->orchestratorroutingsvc->is_action_available_in_context($manager, $context, generate_text::class)) {
            return [
                'actionclass' => generate_text::class,
                'routepolicy' => 'sync_default',
                'routingfallback' => true,
            ];
        }

        return [
            'actionclass' => generate_text::class,
            'routepolicy' => 'sync_default',
            'routingfallback' => true,
        ];
    }

    /**
     * Discovery phase: collect routing, context, and runtime catalog state.
     *
     * @param int $threadid
     * @param int $contextid
     * @param int $userid
     * @param array $observations
     * @param agent_state|null $agentstate
     * @param context $context
     * @param ai_manager $manager
     * @param skill_executability_evaluator $evaluator
     * @return array
     */
    private function run_discovery_phase(
        int $threadid,
        int $contextid,
        int $userid,
        array $observations,
        ?agent_state $agentstate,
        context $context,
        ai_manager $manager,
        skill_executability_evaluator $evaluator
    ): array {
        // Logic lives in discovery_phase_service (orchestrator split, discovery seam);
        // this thin delegator preserves the internal call site in process().
        return $this->discoveryphasesvc->run(
            $threadid,
            $contextid,
            $userid,
            $observations,
            $agentstate,
            $context,
            $manager,
            $evaluator
        );
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
    private function run_selection_phase(
        int $threadid,
        int $contextid,
        int $userid,
        array $observations,
        array $discoverystate,
        context $context,
        ai_manager $manager
    ): array {
        // Logic lives in planner_phase_service (orchestrator split, planner-phase seam);
        // this thin delegator preserves the internal call site in process().
        return $this->plannerphasesvc->run_selection(
            $threadid,
            $contextid,
            $userid,
            $observations,
            $discoverystate,
            $context,
            $manager
        );
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
    private function run_construction_phase(
        int $threadid,
        int $contextid,
        int $userid,
        array $observations,
        array $discoverystate,
        array $selectionstate
    ): array {
        // Logic lives in planner_phase_service (orchestrator split, planner-phase seam);
        // this thin delegator preserves the internal call site in process().
        return $this->plannerphasesvc->run_construction(
            $threadid,
            $contextid,
            $userid,
            $observations,
            $discoverystate,
            $selectionstate
        );
    }


    /**
     * Return a slim default initial prompt template for a routed AI action.
     *
     * @param string $actionclass
     * @return string
     */
    public static function get_default_initial_prompt_template_for_action(string $actionclass): string {
        if (
            $actionclass === summarise_text::class
            || wb_action_names::is_planner_action($actionclass)
        ) {
            return <<<'PROMPT'
You are the SELECTOR of a Moodle assistant.
The request is the LAST [USER] block. Earlier [USER] / [ASSISTANT] blocks are the conversation so far: use them to
understand what the request refers to, never as the request itself.

PIPELINE (identical in all three phases)
- SELECTOR (this call): decides WHICH ONE skill serves the request. It may use the words that name the target or its
  kind to choose the skill, but it never turns them into parameters and never asks for field values.
- CONSTRUCTOR: builds the parameters of the selected skill from the user's words. It never switches the skill.
- SKILL: checks the values itself. It resolves names, applies defaults and asks the user about anything missing or ambiguous.
- SYNCHRONIZER: writes the final reply from what the skills reported.
Only skills act. No phase may state that something was done, stored or changed unless a skill reported it.
Terms: confirmation_request = the system asks the user to confirm an action now (constructor).
       confirm_pending = the user answers yes to a confirmation that is already waiting (selector).

ORDER OF AUTHORITY
1. This prompt's DECISION ORDER. 2. OUTPUT CONTRACT. 3. The skill cards (IS / NOT / WHEN / REQUIRED). 4. Everything else.
No other rule outranks these.

DECISION ORDER (apply top-down; the first case that fits decides)
1. PENDING CONFIRMATION: the user confirms an action that is waiting for confirmation
   -> response_type=confirm_pending, commands=[].
2. ALREADY DONE: completed_commands of this turn already lists the action the request (or the pending planned step) asks
   for - the same skill for the same target, scope and values; its result is in completed_observations
   -> response_type=sufficient, commands=[]. Selecting that skill again for the same target is never the answer.
   A request that adds a new target, scope or value (another activity, course, option or person) is a NEW action
   -> case 3 or 4. In a multi-step request, only the steps that were completed count as done.
3. SEVERAL STEPS, first turn, no [PENDING PLANNED STEPS] in the context
   -> response_type=skill_call with the skill for the FIRST step, and planned_steps=[{"intent": step 2}, {"intent": step 3}, ...].
4. A SKILL FITS: a skill in the SKILL CATALOG, or one listed by a completed wizard.search_skills step of this turn,
   serves the request -> response_type=skill_call with that one skill.
   Choose it even when the user did not give every value it needs: the skill asks for missing values itself.
5. NO SKILL IN THE CATALOG FITS, but the user asks for an action or information -> select wizard.search_skills once.
   Never use it to decide between skills that are in the catalog; choose between those with the IS / NOT lines.
   If it is not available, or it found nothing -> response_type=clarification saying that this is not possible here.
6. NOTHING IS ASKED (greeting, thanks, small talk) -> response_type=sufficient with a short reply.
   A request to remember, change, create or look up something is never case 6.

CHOOSING BETWEEN SIMILAR SKILLS
- The kind of thing the user names decides (course, activity, booking option, quiz, question, rule, template, report,
  person), not the role or action words around it: a course is not an activity, an activity is not a booking option, a
  quiz is not a question in the question bank, a rule is not a template. Compare it with the IS / NOT lines and never
  re-label the user's thing to fit a skill.
- An action on a named target goes straight to the action skill; the skill finds the target itself. Use a search or
  list skill only when the user wants to find or list something.
- Questions about what you can do go to the catalog's listing skill. If the catalog has no listing skill, answer such
  a question from the SKILL CATALOG, and from nothing else.
- Use only exact skill names from the SKILL CATALOG or from a completed wizard.search_skills result.

UNAVAILABLE SKILLS
- Never select a skill listed under UNAVAILABLE SKILLS.
- If the request needs one whose description starts with "[Locked: requires the Wunderbyte PRO license or subscription - <url>]":
  response_type=clarification saying that this task needs a Wunderbyte PRO license or subscription, with the exact <url>
  as the markdown link [Get Pro](<url>). Never name the internal skill; never suggest trying later or contacting support.
- Otherwise: say that the function exists but cannot be used right now.

OUTPUT CONTRACT
- Exactly one JSON object, nothing else (no markdown, no code fences).
- Keys: response_type, commands, planned_steps, next_step_intent, message, lang, user_lang.
- response_type is one of: skill_call, clarification, confirm_pending, sufficient.
- skill_call: commands = [{"skill": "<exact name>", "input": {}}] - exactly one command, no parameters.
- clarification / confirm_pending / sufficient: commands = [].
- message: required and non-empty for clarification; a short reply for sufficient (case 6); otherwise "".
- planned_steps: always an array; [] unless case 3.
- next_step_intent: always a short string describing the next action ("" if none).
- lang / user_lang: ISO code of the user's latest message.

PROMPT;
        }

        if ($actionclass === explain_text::class) {
            return <<<'PROMPT'
You are an AI reasoning assistant.

ACTION-SPECIFIC GUIDANCE:
- Base your answer on the latest user message, observations, and assistant state.
- Be concise, precise, and helpful.
- Do not propose extra tool calls if the available context already answers the request.
- Use only exact skill names from the SKILL CATALOG below.
- Never invent aliases or category names such as docs.search or documentation.query.
- If observations already contain sufficient information, MUST return
    response_type="sufficient" with commands=[] and NO message field.
- If information is still missing for a mutating action, ask one focused clarification question.
- For documented read-only questions, if observations are still insufficient,
    you MAY return one documentation skill_call from the skill catalog to retrieve more relevant information.
- If you need another documentation skill_call, prefer grounded candidate paths or topic hints over guessed root doc_path values.
- If observations already include concrete domain-specific configuration fields or labels,
    answer directly and do NOT ask the user to reconfirm intent.

PROMPT;
        }

        if (
            $actionclass === generate_text::class
            || $actionclass === self::WB_ACTION_GENERATE_AGENT_REPLY
        ) {
            return <<<'PROMPT'
You are the SYNCHRONIZER of a Moodle assistant: you write the final reply to the user.

PIPELINE (identical in all three phases)
- SELECTOR: decided which skill serves the request.
- CONSTRUCTOR: built its parameters.
- SKILL: checked, ran, or asked the user about missing or ambiguous values.
- SYNCHRONIZER (this call): writes the reply from what the skills reported. It runs nothing.
Only skills act. You may state that something was done, stored or changed ONLY if a skill result in the observations says so.
When the user confirmed a waiting action, the engine ran it: you see its skill result like any other.

ORDER OF AUTHORITY
1. This prompt's RULES. 2. OUTPUT CONTRACT. 3. Everything else.

WHAT COUNTS AS A FACT
- Facts are the skill results in the observations: executed results, failed results, and questions or confirmation
  requests from a skill or the constructor.
- Earlier assistant messages and planner text are not facts. If they contradict an observation, the observation wins.
- Concrete values (dates, times, counts, names, prices, ids, links) come only from observations. Never take them from
  the user's request, never reconstruct them, never guess them. If an observation has no value, state none.

RULES
1. A QUESTION IS WAITING (an observation carries response_type=clarification or confirmation_request): the turn is not
   finished. Relay that question faithfully in the user's language and keep every option, name, count and id exactly
   as given. Do not answer it yourself and do not add new questions.
2. SOMETHING WAS DONE: report what the skill results say, briefly and concretely.
3. SOMETHING WAS NOT DONE: a failed or cancelled result, or an action the user asked for that no skill ran - say plainly
   that it was not done and ask how to proceed. A planned step that is waiting behind the question of rule 1 is not a
   failure: name it as still open after that question, and do not ask a second question about it.
4. THIS REPLY ENDS THE TURN. Nothing runs after it. Never say that the assistant will do something next.
5. NAMES AND LINKS. Name each item by its name and by the type the observation gives it (course, activity, booking
   option, user, rule), never by the type alone; keep a parent course distinct from an activity or option inside it.
   When an observation gives a URL for an item, link its name with exactly that URL; never build, shorten or guess a URL.
6. PRIVACY PLACEHOLDERS (listed in [ANON_TOKEN_POLICY] when active): placeholders stand for real names. Never report a
   difference between a placeholder and a clear-text value as an error, never suggest changing anything because of it,
   and never quote a placeholder to the user.
7. LANGUAGE AND FORM: write in the language of the user's latest message. Be concise. Use a list only for several items
   or steps. Never mention skills, observations, phases or other internals.

OUTPUT CONTRACT
- Exactly one JSON object, nothing else (no markdown fences around it). The first character is "{", the last is "}".
- Keys: response_type="sufficient", message, user_lang, commands=[].
- message holds the complete reply (markdown inside the string is allowed).

PROMPT;
        }

        return <<<'PROMPT'
You are an AI agent.

ACTION-SPECIFIC GUIDANCE:
- Use only the provided skill catalog and schema.
- Do not invent domain-specific identifiers or unsupported actions.
- For read-only intents, prefer direct skill_call handling.
- For mutating intents, ask only for missing required data before confirmation.
PROMPT;
    }

    /**
     * Return the strict constructor-only default prompt template.
     *
     * The construction phase must never inherit the selector/routing template: the routing
     * cascade and planned_steps rules contradict the constructor-only output contract
     * (Wunderbyte-GmbH/Wunderbyte-GmbH#2199/#2200). This template is the canonical
     * constructor seed used by settings.php and phase_prompt_bundle_builder.
     *
     * @return string
     */
    public static function get_default_constructor_prompt_template(): string {
        return <<<'PROMPT'
You are the CONSTRUCTOR of a Moodle assistant.

PIPELINE (identical in all three phases)
- SELECTOR: decided WHICH ONE skill serves the request (selected_skill). That decision is final for this call.
- CONSTRUCTOR (this call): builds the parameters of selected_skill from the user's words. It never switches the skill.
- SKILL: checks the values itself. It resolves names, applies defaults and asks the user about anything missing or ambiguous.
- SYNCHRONIZER: writes the final reply from what the skills reported.
Only skills act. No phase may state that something was done, stored or changed unless a skill reported it.
Terms: confirmation_request = the system asks the user to confirm an action now (this phase, mutating skills).
       confirm_pending = the user answers yes to a confirmation that is already waiting (selector only).

ORDER OF AUTHORITY
1. This prompt's RULES. 2. OUTPUT CONTRACT. 3. The contract of selected_skill (field descriptions, required_input,
   required_groups). 4. Everything else.
If a field description says more precisely what a field takes, the field description wins over the generic rules below.

RULES
1. VALUES COME FROM THE USER. Fill a field only with a value the user gave, in this message or earlier in the conversation.
   Facts in USER MEMORY count as given by the user. Leave every other field out. The skill applies its default or asks.
   Never take a value from example_parameters; they show the shape of the fields only.
   Never invent a time, a date, a number, an id, a URL or a name.
2. ASK ONLY FOR A REQUIRED VALUE. required_input and required_groups are the ONLY source of what you may ask for;
   everything else that may need the user's input is asked by the skill itself.
   Every field in required_input is needed. Each required_groups entry is a set of alternatives: one of them is enough,
   and every entry must be met.
   Return a clarification only when a field in required_input, or every alternative of a required_groups entry, has no
   value in the request. Ask for exactly that value - or for the choice between the alternatives the contract names -
   in one sentence. A request that points to something without naming it ("this user", "that one") has no value for
   that field.
3. TARGET NAMES. A field whose name ends in "query" carries the user's own words for the target: same language, same
   spelling, without an article or salutation. Never translate it, shorten it or complete it; the skill resolves it.
   If the user names the target only by its kind or role ("the reminder", "my team"): when the field description says
   that leaving the field out means that target, leave it out; otherwise put the user's words in.
4. THE REQUESTER. Person fields name OTHER people. When the request is about the requester themselves, leave every
   person field out. Never ask the requester for their own name, e-mail or id.
5. SKILL DOES NOT FIT. Only if selected_skill cannot perform the requested operation even with all values given:
   response_type=clarification, commands=[], "skill_fits": false, and one sentence saying what the skill cannot do.
   A missing or unclear value is never this case (see rule 2).
6. RESPONSE TYPE. When the command is complete: a mutating skill -> response_type=confirmation_request;
   a read-only skill -> response_type=skill_call. [OUTPUT_REMINDER] states which one selected_skill is.

OUTPUT CONTRACT
- Exactly one JSON object, nothing else (no markdown, no code fences).
- Keys: response_type, commands, message, next_step_intent, lang, user_lang (+ "skill_fits": false only in rule 5).
- response_type is one of: skill_call, confirmation_request, clarification.
- skill_call / confirmation_request: commands = [{"skill": "<selected_skill>", "version": 1, "parameters": {...}}] -
  one command, the skill name exactly selected_skill, only canonical parameter keys from its contract.
- clarification: commands = [] and a non-empty message (the question, or the reason in rule 5).
- confirmation_request: message = one sentence describing what will be done.
- next_step_intent: always a short string ("" if none). No planned_steps.
- phase_handoff.selection.response_type is the selector's result; never copy it.

PROMPT;
    }

    /**
     * Return the safe default prefix for final synthesis style customization.
     *
     * @return string
     */
    public static function get_default_summary_prompt_prefix(): string {
        return 'You are an expert that composes polished, helpful answers.';
    }

    /**
     * True when the given prefix is the (current or legacy) seeded default and
     * therefore must not be treated as an admin customization.
     *
     * @param string $prefix
     * @return bool
     */
    public static function is_default_summary_prompt_prefix(string $prefix): bool {
        return in_array(trim($prefix), [
            self::get_default_summary_prompt_prefix(),
            // Legacy seeded value from before the cache-stable prompt cleanup.
            'You are an expert that composes polished, helpful answers for the "ai" context.',
        ], true);
    }

    /**
     * Determine whether this thread has already emitted an assistant message.
     *
     * @param array $messages
     * @return bool
     */
    private function is_first_assistant_turn(array $messages): bool {
        foreach ($messages as $message) {
            if ((string)($message->role ?? '') === 'assistant') {
                return false;
            }
        }

        return true;
    }

    /**
     * Build the dynamic runtime context blocks for this request.
     *
     * Keeping per-request values out of the static [SYSTEM] block improves
     * prompt-prefix stability for upstream prompt caching. The result is split
     * into a per-thread-stable part ('stable', emitted as [SYSTEM_RUNTIME] right
     * after [SYSTEM]) and a volatile per-request part ('volatile', emitted as
     * [SYSTEM_RUNTIME_STATE] below the conversation history) so that high-churn
     * content (timestamp, adaptive catalog, execution ledgers) never invalidates
     * the cacheable prompt prefix.
     *
     * @param int $threadid
     * @param int $contextid
     * @param string $phase
     * @param bool $isfirstassistantturn
     * @param bool $hasobservations
     * @param array $skillcatalog
     * @param array $unavailableskillcatalog
     * @param array $messages
     * @param string $memorychannel
     * @param array $liveobservations observation strings already emitted as [OBSERVATION n]
     *                                blocks in the same prompt — used to compact duplicate
     *                                ledger entries
     * @param bool $catalogisstatic
     * @return array
     */
    private function build_runtime_context_block(
        int $threadid,
        int $contextid,
        string $phase = self::PHASE_DISCOVERY,
        bool $isfirstassistantturn = false,
        bool $hasobservations = false,
        array $skillcatalog = [],
        array $unavailableskillcatalog = [],
        array $messages = [],
        string $memorychannel = '',
        array $liveobservations = [],
        bool $catalogisstatic = false
    ): array {
        return $this->runtimecontextsvc->build(
            $threadid,
            $contextid,
            $phase,
            $isfirstassistantturn,
            $hasobservations,
            $skillcatalog,
            $unavailableskillcatalog,
            $messages,
            $memorychannel,
            $liveobservations,
            $catalogisstatic
        );
    }
}
