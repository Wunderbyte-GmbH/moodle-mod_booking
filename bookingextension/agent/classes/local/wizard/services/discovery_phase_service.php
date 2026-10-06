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
 * Discovery phase service.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services;

use bookingextension_agent\local\wizard\services\turn_skill_exclusions;
use core\context;
use core_ai\manager as ai_manager;
use core_ai\aiactions\generate_text;
use core_text;
use bookingextension_agent\local\wizard\orchestrator;
use bookingextension_agent\local\wizard\agent_state;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\skill_registry;
use bookingextension_agent\local\wizard\skill_executability_evaluator;
use bookingextension_agent\local\wizard\embeddings_action_config_resolver;
use bookingextension_agent\local\wizard\contracts\skill_family_contract;
use bookingextension_agent\local\wizard\config\runtime_feature_flags;
use bookingextension_agent\local\wizard\queue\queue_manager;
use bookingextension_agent\local\wizard\services\catalog\adaptive_skill_catalog_service;
use bookingextension_agent\local\wizard\services\discovery\context_owner_resolver;
use bookingextension_agent\local\wizard\services\discovery\context_prior_builder;
use bookingextension_agent\local\wizard\services\discovery\discovery_stage_controller;
use bookingextension_agent\local\wizard\services\discovery\family_ranker;
use bookingextension_agent\local\wizard\services\discovery\family_registry_service;
use bookingextension_agent\local\wizard\services\discovery\family_signal_ranker;
use bookingextension_agent\local\wizard\services\embeddings\embeddings_readiness_service;
use bookingextension_agent\local\wizard\services\embeddings\embeddings_retrieval_service;
use bookingextension_agent\local\wizard\services\embeddings\family_embeddings_retrieval_service;
use bookingextension_agent\local\wizard\services\llm\llm_call_service;
use bookingextension_agent\local\wizard\wb_action_names;

/**
 * Runs the planner discovery phase: routing, runtime catalog assembly, embedding
 * top-k retrieval and family staging, and the discovery prompt bundle.
 *
 * Extracted verbatim from orchestrator::run_discovery_phase (orchestrator split,
 * discovery seam). Collaborators that were orchestrator fields are injected; the
 * many embedding/family sub-services are still instantiated locally per run, just
 * as before. Discovery-only helpers moved in with the phase; the shared prompt wrappers
 * (build_system_prompt / build_prompt / json_encode_or_empty) live in planner_phase_prompt_trait.
 */
class discovery_phase_service {
    use planner_phase_prompt_trait;

    /** @var conversation_store */
    private conversation_store $store;

    /** @var skill_registry */
    private skill_registry $registry;

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
     * @param orchestrator_routing_service $routingsvc
     * @param orchestrator_prompt_profile_service $promptprofilesvc
     * @param planner_catalog_service $catalogsvc
     * @param runtime_context_block_builder $runtimecontextsvc
     * @param phase_prompt_bundle_builder $promptbundlebuilder
     */
    public function __construct(
        conversation_store $store,
        skill_registry $registry,
        orchestrator_routing_service $routingsvc,
        orchestrator_prompt_profile_service $promptprofilesvc,
        planner_catalog_service $catalogsvc,
        runtime_context_block_builder $runtimecontextsvc,
        phase_prompt_bundle_builder $promptbundlebuilder
    ) {
        $this->store = $store;
        $this->registry = $registry;
        $this->routingsvc = $routingsvc;
        $this->promptprofilesvc = $promptprofilesvc;
        $this->catalogsvc = $catalogsvc;
        $this->runtimecontextsvc = $runtimecontextsvc;
        $this->promptbundlebuilder = $promptbundlebuilder;
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
    public function run(
        int $threadid,
        int $contextid,
        int $userid,
        array $observations,
        ?agent_state $agentstate,
        context $context,
        ai_manager $manager,
        skill_executability_evaluator $evaluator
    ): array {
        $contextid = (int)$context->id;

        $routing = $this->routingsvc->resolve_action_class_for_phase(
            $manager,
            $context,
            orchestrator_routing_service::PHASE_SELECTION
        );
        $actionclass = (string)($routing['actionclass'] ?? '');

        $messages = array_values(array_filter(
            $this->store->get_messages($threadid),
            static fn($msg): bool => (string)($msg->role ?? '') !== 'step'
        ));

        $recentskillhistory = $this->extract_recent_skill_names_from_messages($messages);
        $isfirstassistantturn = $this->is_first_assistant_turn($messages);
        // ONE evaluator pass drives the whole catalog gate: the verdict partition below is the same
        // source of truth the interpreter/executor re-derive later, so the catalog can never offer
        // a skill governance would deny (issue #2223). allow → selectable; requires_pro → the
        // UNAVAILABLE upsell list; every other deny (inactive, missing capability, not registered,
        // runtime disabled, invalid context) → hidden from the planner entirely.
        $skillverdicts = $evaluator->evaluate_all_skills($userid, $contextid);
        [$promptcontracts, $lockedpromptcontracts] =
            $this->catalogsvc->partition_prompt_contracts_by_executability(
                $this->registry->get_prompt_contracts_for_context($evaluator, $userid, $contextid, true),
                $skillverdicts
            );
        $adaptivecatalogresult = adaptive_skill_catalog_service::get_adaptive_catalog(
            $promptcontracts,
            $recentskillhistory,
            orchestrator_routing_service::PHASE_DISCOVERY
        );
        $adaptivecatalog = $adaptivecatalogresult['active_skills'];

        $hasanyobservations = !empty($observations);
        $haseffectiveobservations = $hasanyobservations
            && !$this->promptprofilesvc->observations_are_framework_retry_hints($observations);
        $plannertracehistory = $this->normalize_planner_trace_history(
            $this->store->get_thread_metadata_value($threadid, 'planner_trace_history')
        );
        // Keep skill catalog available in every loop iteration so follow-up
        // selection rounds (B, C, ...) never run with an empty catalog.
        $shouldincludeskillcatalog = true;

        $runtimecatalog = [];
        $allpromptcontracts = [];
        $unavailableskillcatalog = [];
        $catalogselectionmode = 'none';
        $embeddingstatus = 'off';
        $embeddingrebuildqueued = false;
        $usedembeddingcache = false;
        $discoverystage = 'none';
        $discoveryconfidencescore = null;
        $discoveryescalationreason = 'none';
        $selectedfamilies = [];
        $embeddingcall = [];
        $status = [];
        $llm = new llm_call_service($this->store);

        if ($shouldincludeskillcatalog) {
            // The verdict partition above already removed every denied skill: PRO-locked write
            // skills sit in the locked list (so the planner can point at the upgrade path instead
            // of failing late in governance), all other denied skills are simply absent.
            $allpromptcontracts = $promptcontracts;
            $unavailableskillcatalog = $lockedpromptcontracts;
            $runtimecatalog = $this->catalogsvc->slim_prompt_catalog_for_planner($allpromptcontracts);
            $catalogselectionmode = 'slim_all';

            $iswunderbyteplanner =
                $this->routingsvc->is_wunderbyte_routepolicy((string)($routing['routepolicy'] ?? ''))
                && wb_action_names::is_planner_action($actionclass);

            if ($iswunderbyteplanner) {
                $embeddingstatus = 'check';
                $embeddingsettings = (new embeddings_action_config_resolver())->resolve();
                $embeddingmodel = (string)($embeddingsettings['model'] ?? orchestrator::EMBEDDINGS_DEFAULT_MODEL);
                $embeddingdimensions = (int)($embeddingsettings['dimensions'] ?? orchestrator::EMBEDDINGS_DEFAULT_DIMENSIONS);
                $querytext = '';
                foreach (array_reverse($messages) as $msg) {
                    if (($msg->role ?? '') === 'user') {
                        $querytext = trim((string)($msg->content ?? ''));
                        break;
                    }
                }
                // When the latest user message is a short, low-semantic follow-up (an answer to a prior
                // clarification/confirmation: "medium", "yes", "the second one", "Biology"), embedding it
                // alone carries no task semantics and drops the originally requested skill out of top-K
                // (see Blueprint thread223). Prepend the originating task so it stays discoverable.
                // B (deterministic): a clarification chain recorded the task that opened it — prefer that.
                // C (stateless fallback): otherwise, reach back to the most recent SUBSTANTIAL user message.
                // Either way a genuine topic switch (a rich new request) is unaffected: it dominates the
                // embedding on its own, and the recorded task is cleared as soon as a turn resolves.
                $origintask = trim((string)$this->store->get_thread_metadata_value($threadid, 'clarification_origin_task'));
                if ($origintask !== '' && strpos($querytext, $origintask) === false) {
                    $querytext = $origintask . ' ' . $querytext;
                } else if (self::is_low_semantic_followup($querytext)) {
                    $heuristictask = $this->find_recent_substantial_user_text($messages);
                    if ($heuristictask !== '' && strpos($querytext, $heuristictask) === false) {
                        $querytext = $heuristictask . ' ' . $querytext;
                    }
                }
                $pendingstepintent = trim((string)$this->store->get_thread_metadata_value($threadid, 'next_step_intent'));
                if ($pendingstepintent !== '' && $pendingstepintent !== $querytext) {
                    $querytext = $querytext . ' ' . $pendingstepintent;
                }
                // Also augment with all remaining planned placeholder intents so the embedding
                // retrieval surfaces the right skills for each pending step, not just the next one.
                $plannedintents = (new queue_manager($this->store, $this->registry))
                    ->get_planned_placeholder_intents($threadid);
                foreach ($plannedintents as $plannedintent) {
                    $plannedintent = trim($plannedintent);
                    if ($plannedintent !== '' && strpos($querytext, $plannedintent) === false) {
                        $querytext = $querytext . ' ' . $plannedintent;
                    }
                }

                $cachekey = '';
                if ($querytext !== '') {
                    $cachekey = sha1(
                        $querytext
                        . '|m=' . $embeddingmodel
                        . '|d=' . $embeddingdimensions
                        . '|u=' . $userid
                        . '|c=' . $contextid
                    );
                }

                if ($cachekey !== '' && $agentstate !== null) {
                    $cachedcatalog = $agentstate->get_discovery_family_cache($cachekey);
                    if ($cachedcatalog !== null) {
                        // Re-intersect the cached catalog with the CURRENT selectable partition: a
                        // skill toggled off (or gone) since the cache write must not resurface as a
                        // selectable entry. The UNAVAILABLE list is not cached at all — it comes
                        // from the fresh partition above and does not depend on embeddings.
                        $runtimecatalog = $this->catalogsvc->filter_catalog_rows_to_skills(
                            $this->catalogsvc->sanitize_runtime_catalog_for_prompt(
                                (array)($cachedcatalog['runtimecatalog'] ?? $runtimecatalog)
                            ),
                            array_column($allpromptcontracts, 'skill')
                        );
                        $catalogselectionmode = (string)($cachedcatalog['catalogselectionmode'] ?? 'embed_topk_cache');
                        $embeddingstatus = 'cached_' . trim((string)($cachedcatalog['embeddingstatus'] ?? 'applied'));
                        $embeddingrebuildqueued = !empty($cachedcatalog['embeddingrebuildqueued']);
                        $usedembeddingcache = true;
                    }
                }

                if (!$usedembeddingcache) {
                    $readiness = new embeddings_readiness_service();
                    if ($readiness->is_wunderbyte_embeddings_available()) {
                        $status = $readiness->get_catalog_status($this->registry, $embeddingmodel, $embeddingdimensions);
                        $embeddingstatus = (string)($status['status'] ?? 'unknown');
                        $embeddingrebuildqueued = $readiness->ensure_rebuild_scheduled_if_needed(
                            $status,
                            $embeddingmodel,
                            $embeddingdimensions,
                            orchestrator::EMBEDDINGS_REBUILD_DEBOUNCE_SECONDS
                        );

                        if (!empty($status['ready']) && !empty($status['rows']) && is_array($status['rows']) && $querytext !== '') {
                            // Cross-language bridge (SKILL_REWORK.md §5.7, Weg B): embed an English-normalised
                            // query so non-English requests match the English-only anchors. Fail-open.
                            $embedquery = (new \bookingextension_agent\local\wizard\services\llm\query_english_normalizer())
                                ->to_english($querytext, (int)$contextid, (int)$userid, (int)$threadid);
                            $embeddingcall = $llm->invoke_embeddings_for_context(
                                $threadid,
                                $contextid,
                                $userid,
                                'orc|p=disc|st=tcp|ac=emb|rt=wb',
                                $embedquery,
                                $embeddingdimensions
                            );

                            if (!empty($embeddingcall['success']) && !empty($embeddingcall['embedding'])) {
                                $retrieval = new embeddings_retrieval_service();
                                // Multi-vector: cosine over all anchor rows, MAX per skill, top-12
                                // DISTINCT skills (SKILL_REWORK.md §5).
                                $toprows = $retrieval->search_top_k_skills(
                                    (array)$embeddingcall['embedding'],
                                    $status['rows'],
                                    orchestrator::EMBEDDINGS_DEFAULT_TOP_K
                                );
                                // The embeddings index is availability-agnostic (global store,
                                // per-user/context executability), so retrieval output must be
                                // intersected with the selectable partition: a stale row for a
                                // removed/disabled/denied skill must never re-enter the
                                // selectable catalog through top-k (issue #2223 leak path).
                                $toprows = $this->catalogsvc->filter_catalog_rows_to_skills(
                                    $toprows,
                                    array_column($allpromptcontracts, 'skill')
                                );

                                if (runtime_feature_flags::is_enabled(runtime_feature_flags::FAMILY_EMBEDDINGS_ENABLED)) {
                                    $familycontextprior = (new context_prior_builder())->build($contextid, [
                                        'userid' => $userid,
                                        'namespace_hint' =>
                                            $this->catalogsvc->resolve_namespace_hint_from_prompt_contracts($allpromptcontracts),
                                        'page_type' => $this->page_type_of_thread($threadid),
                                        'context_namespace' => (new context_owner_resolver())->resolve(
                                            $context,
                                            $this->page_type_of_thread($threadid),
                                            $this->namespaces_of($allpromptcontracts)
                                        ),
                                    ]);
                                    $familydiscovery = (new family_registry_service())->discover(
                                        $allpromptcontracts,
                                        $familycontextprior
                                    )->to_array();
                                    $families = (array)($familydiscovery['families'] ?? []);
                                    if (!empty($families)) {
                                        $signalscores = (new family_signal_ranker())->score_families(
                                            $families,
                                            $familycontextprior,
                                            $recentskillhistory
                                        );
                                        $semanticscores = (new family_embeddings_retrieval_service())->score_families(
                                            $families,
                                            (array)$embeddingcall['embedding'],
                                            (array)$status['rows']
                                        );
                                        $rankedfamilies = (new family_ranker())->rank(
                                            $families,
                                            $signalscores,
                                            $semanticscores
                                        );
                                        $familyscores = [];
                                        foreach ($rankedfamilies as $row) {
                                            $family = trim((string)($row['family'] ?? ''));
                                            if ($family === '') {
                                                continue;
                                            }
                                            $familyscores[$family] = (float)($row['score'] ?? 0.0);
                                        }

                                        if (!empty($familyscores)) {
                                            $toprows = (new family_embeddings_retrieval_service())
                                                ->boost_skill_rows($toprows, $familyscores);
                                            $embeddingstatus = 'family_boosted';
                                        }
                                    }
                                }

                                if (!empty($toprows)) {
                                    $runtimecatalog = $this->catalogsvc->sanitize_runtime_catalog_for_prompt(
                                        array_values($toprows)
                                    );
                                    $catalogselectionmode = $embeddingstatus === 'family_boosted'
                                        ? 'embed_topk_family_boost'
                                        : 'embed_topk';
                                    $embeddingstatus = 'applied';
                                } else {
                                    $embeddingstatus = 'nomatch';
                                }
                            } else {
                                $embeddingstatus = 'callfail';
                            }
                        }
                    } else {
                        $embeddingstatus = 'unavailable';
                    }

                    if ($cachekey !== '' && $agentstate !== null) {
                        $agentstate->set_discovery_family_cache($cachekey, [
                            'runtimecatalog' => $runtimecatalog,
                            'catalogselectionmode' => $catalogselectionmode,
                            'embeddingstatus' => $embeddingstatus,
                            'embeddingrebuildqueued' => $embeddingrebuildqueued,
                        ]);
                    }
                }
            }

            if (runtime_feature_flags::is_enabled(runtime_feature_flags::FAMILY_DISCOVERY_ENABLED)) {
                $namespacehint = $this->catalogsvc->resolve_namespace_hint_from_prompt_contracts($allpromptcontracts);
                $familycontextprior = (new context_prior_builder())->build($contextid, [
                    'userid' => $userid,
                    'namespace_hint' => $namespacehint,
                ]);
                $familydiscovery = (new family_registry_service())->discover(
                    $allpromptcontracts,
                    $familycontextprior
                )->to_array();
                $families = (array)($familydiscovery['families'] ?? []);
                if (!empty($families)) {
                    $signalscores = (new family_signal_ranker())->score_families(
                        $families,
                        $familycontextprior,
                        $recentskillhistory
                    );

                    $semanticscores = [];
                    if (
                        !empty($embeddingcall['success'])
                        && !empty($embeddingcall['embedding'])
                        && !empty($status['rows'])
                        && is_array($status['rows'])
                    ) {
                        $semanticscores = (new family_embeddings_retrieval_service())->score_families(
                            $families,
                            (array)$embeddingcall['embedding'],
                            (array)$status['rows']
                        );
                    }

                    $rankedfamilies = (new family_ranker())->rank(
                        $families,
                        $signalscores,
                        $semanticscores
                    );
                    $stageresult = (new discovery_stage_controller())->resolve(
                        $rankedfamilies,
                        (array)($familydiscovery['context_families'] ?? []),
                        (array)($familydiscovery['core_families'] ?? [])
                    );

                    $discoverystage = (string)($stageresult['discovery_stage'] ?? 'none');
                    $discoveryconfidencescore = $stageresult['confidence_score'] ?? null;
                    $discoveryescalationreason = (string)($stageresult['escalation_reason'] ?? 'none');
                    $selectedfamilies = array_values(array_filter(array_map(
                        static fn($family): string => skill_family_contract::normalize_family((string)$family),
                        (array)($stageresult['selected_families'] ?? [])
                    )));

                    if (!empty($selectedfamilies)) {
                        $runtimecatalog = $this->catalogsvc->filter_catalog_by_selected_families(
                            $runtimecatalog,
                            $selectedfamilies
                        );
                        if ($catalogselectionmode === 'slim_all') {
                            $catalogselectionmode = 'slim_family_stage_' . strtolower($discoverystage);
                        } else if (str_starts_with($catalogselectionmode, 'embed_topk')) {
                            $catalogselectionmode .= '_stage_' . strtolower($discoverystage);
                        }
                    }
                }
            }
        }

        // Skill discovery is purely semantic (embedding multi-vector top-k); there is no lexical
        // intent-trigger injection. A skill that is hard to reach is an embedding problem and is
        // fixed via its anchors (description + example_utterances) or the embedding model, never by
        // substring/keyword routing. See docs/Blueprints/SKILL_REWORK.md §5.
        //
        // The discovery meta-skills (wizard.list_skills / wizard.search_skills) only make sense while
        // the catalog is a SEMANTIC SUBSET (embed_topk): there, search_skills is the SOLE sanctioned
        // force-include (the RAG fallback — its anchors never semantically match task queries, so top-k
        // can never surface it, yet the planner must be able to fall back to it). When the catalog is
        // STATIC (slim_all / slim_family — no embeddings), the planner already sees every skill, so
        // both meta-skills are removed: advertising "list/search skills" would only imply non-existent
        // hidden skills (thread 565).
        // Wave 32: a skill the construction rejected ("skill_fits": false) is out for the rest of this turn.
        $runtimecatalog = turn_skill_exclusions::filter_catalog(
            $runtimecatalog,
            turn_skill_exclusions::excluded($this->store, (int)$threadid)
        );
        if ($this->catalogsvc->catalog_mode_is_static($catalogselectionmode)) {
            $runtimecatalog = $this->catalogsvc->exclude_discovery_meta_skills($runtimecatalog);
        } else {
            $runtimecatalog = $this->ensure_search_skills_fallback($runtimecatalog, $allpromptcontracts);
        }

        $systemprompt = $this->build_system_prompt(
            $contextid,
            $userid,
            orchestrator::PHASE_DISCOVERY,
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
            orchestrator::PHASE_DISCOVERY,
            $isfirstassistantturn,
            $hasanyobservations,
            $runtimecatalog,
            $unavailableskillcatalog,
            $messages,
            '',
            $observations,
            $this->catalogsvc->catalog_mode_is_static($catalogselectionmode)
        );
        $runtimeblocks = $this->append_missing_fallback_policy(
            $runtimeblocks,
            $runtimecatalog,
            $this->catalogsvc->catalog_mode_is_static($catalogselectionmode)
        );
        $autoconfirmmode = $this->store->is_confirmation_allowed_for_thread($userid, $contextid, $threadid);
        $prompt = $this->build_prompt(
            $systemprompt,
            $messages,
            $observations,
            orchestrator::PHASE_DISCOVERY,
            $runtimeblocks['stable'],
            $plannertracehistory,
            $autoconfirmmode,
            [],
            $runtimeblocks['volatile']
        );

        $historycount = count(
            $this->promptprofilesvc->select_history_messages($messages, orchestrator::PHASE_DISCOVERY)
        );
        $observationcount = count($observations);
        $primaryprovider = (string)($routing['primaryprovider'] ?? '');
        $debugsource = $this->routingsvc->build_debug_source(
            $actionclass,
            (string)($routing['routepolicy'] ?? 'default'),
            !empty($routing['routingfallback']),
            orchestrator_routing_service::PHASE_DISCOVERY,
            $primaryprovider,
            $historycount,
            $observationcount,
            $catalogselectionmode,
            $embeddingstatus,
            count($runtimecatalog),
            $embeddingrebuildqueued,
            false
        );

        $phaseoutput = [
            'response_type' => 'sufficient',
            'message' => '',
            'commands' => [],
            'ambiguities' => [],
            'errors' => [],
            'issue_codes' => [],
            'next_step_intent' => '',
            'phase' => orchestrator::PHASE_DISCOVERY,
            'catalogselectionmode' => $catalogselectionmode,
            'embeddingstatus' => $embeddingstatus,
            'discovery_stage' => $discoverystage,
            'discovery_confidence_score' => $discoveryconfidencescore,
            'discovery_escalation_reason' => $discoveryescalationreason,
            'selected_families' => $selectedfamilies,
        ];

        return [
            'contextid' => $contextid,
            'routing' => $routing,
            'actionclass' => $actionclass,
            'messages' => $messages,
            'recentskillhistory' => $recentskillhistory,
            'isfirstassistantturn' => $isfirstassistantturn,
            'promptcontracts' => $promptcontracts,
            'adaptivecatalog' => $adaptivecatalog,
            'hasanyobservations' => $hasanyobservations,
            'haseffectiveobservations' => $haseffectiveobservations,
            'plannertracehistory' => $plannertracehistory,
            'shouldincludeskillcatalog' => $shouldincludeskillcatalog,
            'runtimecatalog' => $runtimecatalog,
            'unavailableskillcatalog' => $unavailableskillcatalog,
            'catalogselectionmode' => $catalogselectionmode,
            'embeddingstatus' => $embeddingstatus,
            'embeddingrebuildqueued' => $embeddingrebuildqueued,
            'discovery_stage' => $discoverystage,
            'discovery_confidence_score' => $discoveryconfidencescore,
            'discovery_escalation_reason' => $discoveryescalationreason,
            'selected_families' => $selectedfamilies,
            'prompt' => $prompt,
            'debugsource' => $debugsource,
            'phase' => orchestrator::PHASE_DISCOVERY,
            'phase_output' => $phaseoutput,
            'response_type' => (string)($phaseoutput['response_type'] ?? ''),
            'message' => (string)($phaseoutput['message'] ?? ''),
            'issue_codes' => (array)($phaseoutput['issue_codes'] ?? []),
            'errors' => (array)($phaseoutput['errors'] ?? []),
        ];
    }

    /**
     * Force-add the wizard.search_skills RAG fallback to the planner catalog (deduplicated).
     *
     * The ONLY sanctioned exception to semantic-only discovery: search_skills is a meta-skill whose
     * anchors never match task queries semantically, so it can never be retrieved by top-k — but the
     * planner must always be able to fall back to it (the last-resort full-registry search).
     *
     * @param array $runtimecatalog Slimmed selector catalog (semantic top-k).
     * @param array $allpromptcontracts All prompt contracts for the context (source of the slim entry).
     * @return array
     */
    private function ensure_search_skills_fallback(array $runtimecatalog, array $allpromptcontracts): array {
        $name = 'wizard.search_skills';
        foreach ($runtimecatalog as $entry) {
            if (trim((string)($entry['skill'] ?? '')) === $name) {
                return $runtimecatalog; // Already present (e.g. slim_all full catalog) — never duplicate.
            }
        }
        foreach ($allpromptcontracts as $contract) {
            if (trim((string)($contract['skill'] ?? '')) === $name) {
                $slim = $this->catalogsvc->slim_prompt_catalog_for_planner([$contract]);
                return array_merge($runtimecatalog, array_values($slim));
            }
        }
        return $runtimecatalog;
    }

    /**
     * When the fallback skill is unavailable (capability withdrawn), instruct the planner to
     * answer unroutable requests with an honest clarification, never a permission claim.
     *
     * @param array $runtimeblocks Stable/volatile runtime block strings.
     * @param array $runtimecatalog Final planner catalog for this turn.
     * @param bool $isstatic Static catalogs carry no meta-skills by design — no policy.
     * @return array The runtime blocks, volatile part possibly extended.
     */
    private function append_missing_fallback_policy(
        array $runtimeblocks,
        array $runtimecatalog,
        bool $isstatic
    ): array {
        if ($isstatic) {
            return $runtimeblocks;
        }
        foreach ($runtimecatalog as $entry) {
            if (trim((string)($entry['skill'] ?? '')) === 'wizard.search_skills') {
                return $runtimeblocks;
            }
        }
        $policy = "[FALLBACK POLICY]\n"
            . 'The registry fallback (wizard.search_skills) is not available in this session. '
            . 'When NO catalog skill matches the request, reply with response_type=clarification '
            . 'stating that this request cannot be mapped to an available action here. Do NOT '
            . 'attribute this to missing user permissions and do NOT name internal skills.';
        $volatile = trim((string)($runtimeblocks['volatile'] ?? ''));
        $runtimeblocks['volatile'] = $volatile === '' ? $policy : $volatile . "\n\n" . $policy;

        return $runtimeblocks;
    }

    /**
     * Extract skill names from recent messages for recency boosting.
     *
     * Scans assistant responses for attempted/executed skill calls (from message metadata).
     *
     * @param \stdClass[] $messages
     * @return string[] Skill names in reverse chronological order (most recent first).
     */
    private function extract_recent_skill_names_from_messages(array $messages): array {
        $skillnames = [];
        for ($i = count($messages) - 1; $i >= 0; --$i) {
            $msg = $messages[$i];
            if ((string)($msg->role ?? '') === 'assistant' && isset($msg->structuredjson)) {
                $meta = (array)json_decode((string)($msg->structuredjson ?? ''), true);
                // Extract skill names from attempted_skills or commands.
                $attemptedskills = (array)($meta['attempted_skills'] ?? []);
                if (!empty($attemptedskills)) {
                    foreach ($attemptedskills as $skillname) {
                        if (!in_array($skillname, $skillnames, true)) {
                            $skillnames[] = (string)$skillname;
                        }
                    }
                }
                // Also check commands if no attempted_skills (fallback).
                $commands = (array)($meta['commands'] ?? []);
                foreach ($commands as $cmd) {
                    if (is_array($cmd) && isset($cmd['skill'])) {
                        $skillname = (string)($cmd['skill'] ?? '');
                        if ($skillname !== '' && !in_array($skillname, $skillnames, true)) {
                            $skillnames[] = $skillname;
                        }
                    }
                }
            }
        }
        return $skillnames;
    }

    /**
     * Determine whether this thread has already emitted an assistant message.
     *
     * Duplicated from orchestrator::is_first_assistant_turn (shared by other phases).
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
     * Heuristic: is this user text a short, low-semantic follow-up (an answer to a prior question)?
     *
     * Short answers like "medium", "yes", "the second one", "Biology", "category 6" carry no task
     * semantics and would, on their own, embed to unrelated skills. Pure word-count heuristic — no state.
     *
     * @param string $text
     * @return bool
     */
    private static function is_low_semantic_followup(string $text): bool {
        $text = trim($text);
        if ($text === '') {
            return false;
        }
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        // Three words or fewer is treated as a follow-up answer, not a standalone request.
        return count($words) <= 3;
    }

    /**
     * Return the most recent SUBSTANTIAL earlier user message (the original task), skipping the latest
     * user message (the short follow-up itself). Capped in length so a pasted document cannot blow up
     * the embedding query. Empty string when none qualifies.
     *
     * @param \stdClass[] $messages
     * @return string
     */
    private function find_recent_substantial_user_text(array $messages): string {
        $skippedlatest = false;
        foreach (array_reverse($messages) as $message) {
            if ((string)($message->role ?? '') !== 'user') {
                continue;
            }
            $text = trim((string)($message->content ?? ''));
            if (!$skippedlatest) {
                // The most recent user message is the short follow-up already in the query; skip it.
                $skippedlatest = true;
                continue;
            }
            if ($text !== '' && !self::is_low_semantic_followup($text)) {
                return core_text::substr($text, 0, 600);
            }
        }
        return '';
    }

    /**
     * Normalize planner trace history values from thread metadata.
     *
     * @param mixed $value
     * @return string[]
     */
    private function normalize_planner_trace_history($value): array {
        if (!is_array($value)) {
            return [];
        }

        $history = [];
        foreach ($value as $entry) {
            if (is_string($entry)) {
                if ($entry !== '') {
                    $history[] = $entry;
                }
                continue;
            }

            if (is_array($entry)) {
                $json = $this->json_encode_or_empty($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($json !== '') {
                    $history[] = $json;
                }
            }
        }

        return $history;
    }

    /**
     * Moodle page type the thread was started from, '' when the client sent none.
     *
     * @param int $threadid
     * @return string
     */
    private function page_type_of_thread(int $threadid): string {
        try {
            $pagecontext = $this->store->get_thread_metadata_value($threadid, '_page_context');
        } catch (\Throwable $e) {
            return '';
        }
        if (is_string($pagecontext)) {
            $pagecontext = json_decode($pagecontext, true);
        }

        return is_array($pagecontext) ? trim((string)($pagecontext['pagetype'] ?? '')) : '';
    }

    /**
     * The distinct skill namespaces the registry offers, so no preference is ever invented for a plugin
     * that has no skills at all.
     *
     * @param array $promptcontracts
     * @return string[]
     */
    private function namespaces_of(array $promptcontracts): array {
        $namespaces = [];
        foreach ($promptcontracts as $contract) {
            if (!is_array($contract)) {
                continue;
            }
            $namespace = trim((string)($contract['namespace'] ?? ''));
            if ($namespace !== '') {
                $namespaces[$namespace] = true;
            }
        }

        return array_keys($namespaces);
    }
}
