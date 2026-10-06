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
 * Agent command executor.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent\local\wizard;

use context_module;
use core\context;
use bookingextension_agent\local\wizard\interfaces\agent_executor;
use bookingextension_agent\local\wizard\privacy_anonymizer;
use bookingextension_agent\local\wizard\services\discovery\skill_discovery_service;
use bookingextension_agent\local\wizard\services\introspection\skill_introspection_service;
use bookingextension_agent\local\wizard\services\preflight_execution_gate;
use bookingextension_agent\local\wizard\dto\agent_context;
use bookingextension_agent\local\wizard\services\security\authorization_service;
use bookingextension_agent\local\wizard\services\security\native_capability_guard;
use bookingextension_agent\local\wizard\services\security\skill_operating_context_resolver;
use bookingextension_agent\local\wizard\services\telemetry\audit_logger;

/**
 * Dispatches interpreter-validated commands to the appropriate skill.
 *
 * Commands reaching execute_commands() are expected to carry prepared_input
 * plus a deterministic guard_token for mutating skills, both produced during
 * decision-service preflight. A verified guard token replaces any structural
 * re-check (the prepared input is preflight's contract, not the planner
 * schema's); only token-less read-only commands get the lightweight
 * check_structure() guard. DB validation is never re-run here.
 *
 * Enforces idempotency, capability checks, and produces structured per-command
 * results.  Partial success is allowed; no rollback is performed.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class executor implements agent_executor {
    /** @var skill_registry */
    private skill_registry $registry;

    /** @var conversation_store */
    private conversation_store $store;

    /** @var authorization_service */
    private authorization_service $authz;

    /** @var string Entrypoint channel for audit events: chat | mcp | api. */
    private string $channel = 'chat';

    /**
     * Set the entrypoint channel recorded on audit events (default 'chat').
     *
     * The executor is the shared execution tail for every entrypoint; callers that are not the
     * chat runtime (e.g. the MCP facade) label their channel so the audit trail is accurate.
     *
     * @param string $channel chat | mcp | api
     */
    public function set_channel(string $channel): void {
        $channel = trim($channel);
        if ($channel !== '') {
            $this->channel = $channel;
        }
    }

    /**
     * Constructor.
     *
     * @param skill_registry         $registry
     * @param conversation_store    $store
     * @param authorization_service $authz
     */
    public function __construct(
        skill_registry $registry,
        conversation_store $store,
        authorization_service $authz
    ) {
        $this->registry = $registry;
        $this->store    = $store;
        $this->authz    = $authz;
    }

        /**
         * Execute a list of validated commands.
         *
         * Commands are expected to carry prepared_input (resolved IDs, normalised values)
         * and, for mutating skills, a guard_token produced during decision-service
         * preflight. The executor MUST NOT repeat DB-resolution logic.
         *
         * @param array  $commands
         * @param int    $contextid
         * @param int    $userid
         * @param string $idempotencykey
         * @param int    $runid
         * @return array
         */
    public function execute_commands(array $commands, int $contextid, int $userid, string $idempotencykey, int $runid): array {
        $context = context::instance_by_id($contextid, MUST_EXIST);
        // Cmid is only needed by booking-style skills (e.g. preview-option memory);
        // 0 outside a module context.
        $cmid = ($context instanceof context_module) ? (int)$context->instanceid : 0;
        // Re-check authorization (always re-verify in adhoc context).
        $this->authz->require_use_capability($userid, $contextid);
        $this->authz->require_valid_context($contextid);
        $evaluator = new skill_executability_evaluator($this->registry, $this->authz);

        // Idempotency guard.
        if ($this->store->run_exists_other_than($idempotencykey, $runid)) {
            return [[
                'status' => 'skipped',
                'detail' => get_string('agent_executor_run_already_executed', 'bookingextension_agent'),
                'issue_codes' => ['EXECUTOR_ALREADY_EXECUTED'],
                'idempotency_reason' => 'EXECUTOR_RUN_EXISTS',
                'resultid' => null,
            ]];
        }

        $results = [];
        $run = $this->store->get_run($runid);
        $threadid = (int)($run->threadid ?? 0);
        $anonymizer = new privacy_anonymizer($this->store);
        foreach ($commands as $cmd) {
            $skillname = $cmd['skill'] ?? '';
            $input     = $cmd['input'] ?? [];
            if ($threadid > 0 && is_array($input)) {
                // Safety-net deanonymization: any remaining ANON tokens not resolved
                // earlier are resolved here (e.g. commands arriving via adhoc tasks
                // that bypassed the decision service preflight).
                $input = $anonymizer->deanonymize_command_input($threadid, $input);
            }

            // Fail closed: if a command parameter still carries an unresolved ANON_USER token after
            // de-anonymization (e.g. a placeholder surfaced from another thread/turn), do not execute
            // the skill with a meaningless placeholder - ask the planner to restate instead.
            if (
                $threadid > 0
                && is_array($input)
                && $anonymizer->should_anonymize_llm_backend_data()
                && $anonymizer->has_unresolved_anon_tokens($input)
            ) {
                $results[] = [
                    'status' => 'error',
                    'detail' => get_string('agent_privacy_unresolved_reference', 'bookingextension_agent'),
                    'issue_codes' => ['RECOVERABLE_INPUT_ERROR'],
                    'resultid' => null,
                    'skill' => $skillname,
                ];
                continue;
            }

            $skill = $this->registry->get_skill($skillname);
            if (!$skill) {
                $results[] = [
                    'status' => 'error',
                    'detail' => get_string('agent_executor_skill_not_registered', 'bookingextension_agent', $skillname),
                    'resultid' => null,
                ];
                continue;
            }

            $evaluation = $evaluator->evaluate_skill((string)$skillname, $userid, $contextid);
            if ((string)($evaluation['executable_state'] ?? '') !== 'allow') {
                $denyreason = trim((string)($evaluation['deny_reason'] ?? skill_contract_validator::DENY_NOT_REGISTERED));
                $denymessage = skill_contract_validator::get_user_facing_deny_message($denyreason, (string)$skillname);
                $results[] = [
                    'status' => 'error',
                    'detail' => $denymessage
                        ?? ('Skill denied by governance gate (' . $denyreason . '): ' . (string)$skillname),
                    'resultid' => null,
                    'deny_reason' => $denyreason,
                    'issue_codes' => [
                        $denyreason === skill_contract_validator::DENY_REQUIRES_PRO
                            ? 'REQUIRES_PRO'
                            : 'SKILL_DENIED',
                    ],
                    'diagnostics' => (array)($evaluation['diagnostics'] ?? []),
                ];
                audit_logger::action_denied(
                    (string)$skillname,
                    'governance',
                    $denyreason,
                    $contextid,
                    $userid,
                    $threadid,
                    $runid,
                    $this->channel,
                    $skill
                );
                continue;
            }

            // Operating context for this command — THE single resolution chokepoint every command
            // passes: resolved EARLY by the preflight pipeline when the command went through it
            // (previews, Gate 2, queue persistence carry the id), or resolved LATE right here when
            // it did not (the chat read-only path executes without the pipeline, thread 542). With
            // this, the target-contract traits work identically for chat read-only, chat mutating,
            // confirm and MCP commands. Gate 1 (governance, above) stays at the ambient context;
            // Gate 2 (the skill's own native capability check) and execution run at the operating
            // context.
            $operatingcontextid = (int)($cmd['operating_contextid'] ?? 0);
            if ($operatingcontextid <= 0) {
                $operatingcontextid = $this->resolve_late_operating_contextid($skill, $input, $contextid, $userid);
            }

            // Fail-closed for cross-context module targets (skill-agnostic): a skill that targets a
            // module INSTANCE (get_target_context_level() === CONTEXT_MODULE) must execute at a
            // resolved module context. If the operating context is not a context_module — e.g. a
            // stale ambient course/site context slipped through because resolution did not persist —
            // refuse rather than execute against the wrong scope (which surfaces as a raw
            // "Invalid course module"). Surface a clarification so the planner names the activity.
            // Mutating only: read-only module-targeted skills may legitimately fall back to the
            // ambient context when their target does not resolve — they must never be blocked
            // (thread 515). Their targets are resolved by the late resolution above (or, in the
            // course family's legacy pattern, eagerly inside execute()).
            if (!$skill->is_read_only() && $this->skill_requires_module_target($skill)) {
                $opcontext = context::instance_by_id($operatingcontextid, IGNORE_MISSING);
                // Selector-aware refinement: a CONDITIONAL module target (e.g. rule_targeted_skill,
                // whose rule may live at the SYSTEM context) only demands a module operating context
                // when its selector actually named a module for THIS input. Unconditional module
                // targets (module_targeted_skill always returns a module selector, even empty) keep
                // the strict fail-closed behaviour.
                $inputselector = $skill->get_target_selector($input);
                $demandsmodule = $inputselector !== null
                    && method_exists($inputselector, 'is_module_target')
                    && $inputselector->is_module_target();
                if (!($opcontext instanceof context_module) && $demandsmodule) {
                    $results[] = [
                        'status' => 'error',
                        'detail' => get_string('agent_target_not_resolved_to_module', 'bookingextension_agent'),
                        'issue_codes' => ['CONTEXT_TARGET_UNRESOLVED'],
                        'resultid' => null,
                        'skill' => $skillname,
                    ];
                    continue;
                }
            }

            if (!$skill->is_read_only()) {
                $guardtoken = trim((string)($cmd['guard_token'] ?? ''));
                if ($guardtoken === '') {
                    $results[] = [
                        'status' => 'error',
                        'detail' => 'Execution guard missing for mutating command.',
                        'issue_codes' => ['EXECUTION_GUARD_MISSING'],
                        'resultid' => null,
                        'skill' => $skillname,
                    ];
                    audit_logger::action_denied(
                        (string)$skillname,
                        'guard',
                        'EXECUTION_GUARD_MISSING',
                        $operatingcontextid,
                        $userid,
                        $threadid,
                        $runid,
                        $this->channel,
                        $skill
                    );
                    continue;
                }

                if (!preflight_execution_gate::verify_guard_token($guardtoken, (string)$skillname, $operatingcontextid, $input)) {
                    $results[] = [
                        'status' => 'error',
                        'detail' => 'Execution guard mismatch for mutating command.',
                        'issue_codes' => ['EXECUTION_GUARD_MISMATCH'],
                        'resultid' => null,
                        'skill' => $skillname,
                    ];
                    audit_logger::action_denied(
                        (string)$skillname,
                        'guard',
                        'EXECUTION_GUARD_MISMATCH',
                        $operatingcontextid,
                        $userid,
                        $threadid,
                        $runid,
                        $this->channel,
                        $skill
                    );
                    continue;
                }

                // A verified guard token attests this input is byte-for-byte the prepared input
                // that preflight deep-validated, so NO structural re-check runs here. The prepared
                // input is a different contract than the planner schema check_structure() validates
                // (ch. 14 §1: check_structure runs in the planner right after parsing): it may
                // legitimately carry engine-canonical keys the planner must never send — e.g.
                // coursequery mapped from linkedcoursequery. Re-validating it against the planner
                // schema made the engine reject its own preflight output (thread 590 N1).
            } else {
                // Token-less path: chat read-only commands execute without the preflight pipeline
                // (thread 542), so deep validation never ran for them — keep the lightweight
                // structural guard (pure, no DB access).
                $structural = $skill->check_structure($input);
                if (!($structural['valid'] ?? true)) {
                    $detail = implode('; ', (array)($structural['errors'] ?? []));
                    $entry = [
                        'status' => 'error',
                        'detail' => get_string('agent_executor_structural_failure', 'bookingextension_agent', $detail),
                        'resultid' => null,
                    ];
                    if (!empty($structural['observation_full']) && is_string($structural['observation_full'])) {
                        $entry['observation_full'] = trim($structural['observation_full']);
                    }
                    $results[] = $entry;
                    continue;
                }
            }

            // Hand the current thread id to skills that need it (duck-typed, executor stays
            // skill-agnostic) - e.g. recall_memory re-anchors recalled tokens into this thread's map.
            if (method_exists($skill, 'set_runtime_threadid')) {
                $skill->set_runtime_threadid($threadid);
            }

            // Inject the engine-provided introspection/discovery services (duck-typed) so the
            // list_skills/search_skills skills depend on contracts, not on engine machinery.
            if (method_exists($skill, 'set_introspection_provider')) {
                $skill->set_introspection_provider(new skill_introspection_service());
            }
            if (method_exists($skill, 'set_discovery_provider')) {
                $skill->set_discovery_provider(new skill_discovery_service());
            }

            // Gate 2 backstop (central, authoritative): enforce the skill's declared native Moodle
            // capabilities at the operating context immediately before execution. The engine never
            // relies on a skill to guard itself — a missing/wrong-context per-skill check, or a
            // crafted/replayed command, is denied here before any mutation.
            $missingcaps = native_capability_guard::missing_capabilities($skill, $operatingcontextid, $userid);
            if (!empty($missingcaps)) {
                $results[] = [
                    'status' => 'error',
                    'detail' => get_string('nopermissions', 'error', reset($missingcaps)),
                    'issue_codes' => ['NO_NATIVE_CAPABILITY'],
                    'resultid' => null,
                    'skill' => $skillname,
                ];
                audit_logger::action_denied(
                    (string)$skillname,
                    'native_capability',
                    'NO_NATIVE_CAPABILITY',
                    $operatingcontextid,
                    $userid,
                    $threadid,
                    $runid,
                    $this->channel,
                    $skill
                );
                continue;
            }

            $executionstartedat = microtime(true);
            $result = $skill->execute($input, $operatingcontextid, $userid);
            $executiondurationms = (microtime(true) - $executionstartedat) * 1000;
            if (is_array($result) && !isset($result['skill'])) {
                $result['skill'] = $skillname;
            }
            if (is_array($result) && !isset($result['executed_input']) && is_array($input)) {
                // Keep normalized executed input in loop results so follow-up planner turns
                // can deterministically avoid repeating already completed commands.
                $result['executed_input'] = $this->build_safe_executed_input($skillname, $input);
            }
            if (
                !empty($result['previewoptionids'])
                && is_array($result['previewoptionids'])
                && method_exists($skill, 'remember_preview_options')
            ) {
                // The skill owns its domain-specific preview-option memory (duck-typed, optional).
                // The executor stays generic and carries no booking knowledge.
                $skill->remember_preview_options(
                    array_map('intval', $result['previewoptionids']),
                    $cmid,
                    $userid
                );
            }
            // Resolve the skill's preview here, on the RAW result, while its bespoke fields
            // (doc_path, previewoptionids, …) are still present. The result is a self-contained data
            // block {type, html, js_module, payload} that travels downstream as the single source of
            // truth for previews — so result sanitization never has to whitelist per-skill fields and
            // preview_passthrough no longer re-derives anything. Best-effort: never break execution.
            if (is_array($result) && method_exists($skill, 'get_result_preview')) {
                try {
                    $preview = $skill->get_result_preview($result, $contextid, $userid);
                    if (is_array($preview) && trim((string)($preview['type'] ?? '')) !== '') {
                        $result['preview'] = $preview;
                    }
                } catch (\Throwable $e) {
                    debugging(
                        'wizard: get_result_preview failed for ' . $skillname . ': ' . $e->getMessage(),
                        DEBUG_DEVELOPER
                    );
                }
            }
            $results[] = $result;

            // Audit the execution (any outcome) from the single chokepoint every entrypoint
            // funnels through. Fail-safe: audit_logger never lets a logging error break execution.
            audit_logger::skill_executed(
                $skill,
                (string)$skillname,
                is_array($input) ? $input : [],
                $result,
                $operatingcontextid,
                $userid,
                $threadid,
                $runid,
                $this->channel,
                $executiondurationms
            );
        }

        return $results;
    }

    /**
     * Build a result-safe echo of the executed input.
     *
     * @param string $skillname
     * @param array $input
     * @return array
     */
    private function build_safe_executed_input(string $skillname, array $input): array {
        $skill = $this->registry->get_skill($skillname);
        $allowedkeys = [];
        if ($skill !== null) {
            $schema = $skill->get_schema();
            $allowedkeys = array_fill_keys(array_keys((array)($schema['properties'] ?? [])), true);
        }

        $safe = [];
        foreach ($input as $key => $value) {
            if (!is_string($key) || ($skill !== null && !isset($allowedkeys[$key]))) {
                continue;
            }
            $safe[$key] = $value;
        }

        // Duck-typed: skills that carry sensitive fields declare get_sensitive_input_fields().
        // Executor stays skill-agnostic — no hardcoded field names per skill name.
        if ($skill !== null && method_exists($skill, 'get_sensitive_input_fields')) {
            foreach ((array)$skill->get_sensitive_input_fields() as $fieldname) {
                unset($safe[(string)$fieldname]);
            }
        }

        return $safe;
    }

    /**
     * Late operating-context resolution for commands that did not pass the preflight pipeline.
     *
     * The chat read-only path executes commands directly (agent_decision_service::
     * execute_readonly_commands — no pipeline), so a cross-context target named in the input
     * (optionid/optionquery/activityquery/cmid) would silently fall back to the ambient context
     * and the target-contract traits would be inert in exactly the modality most users are in
     * (threads 542/539). This chokepoint resolves it with the same
     * skill_operating_context_resolver the pipeline uses, so early (pipeline) and late (here)
     * resolution cannot diverge. Thread-515 semantics are preserved: an unresolvable target
     * falls back to the ambient context and never blocks a read-only skill. A genuinely
     * ambiguous target is clarified BEFORE execution by the decision service
     * (gate_readonly_ambiguous_targets, flowchart PP_RUN); the fallback here is the safety net;
     * a module-targeted MUTATION whose context did not resolve to a module is still refused by
     * the fail-closed check at the call site.
     *
     * @param object $skill The skill instance (duck-typed opt-in contract).
     * @param array $input The command input.
     * @param int $contextid The ambient context id.
     * @param int $userid Acting user id.
     * @return int Operating context id; the ambient context id when resolution does not apply.
     */
    private function resolve_late_operating_contextid($skill, array $input, int $contextid, int $userid): int {
        if (
            !method_exists($skill, 'supports_target_context')
            || !method_exists($skill, 'get_target_selector')
            || !(bool)$skill->supports_target_context()
        ) {
            return $contextid;
        }

        try {
            $resolver = new skill_operating_context_resolver();
            $operating = $resolver->resolve(
                $skill,
                $input,
                agent_context::from_contextid($contextid),
                $userid
            );
            return (int)$operating->id();
        } catch (\Throwable $e) {
            // Unresolvable or ambiguous target: stay ambient. Read-only skills execute there
            // (their no-instance guard clarifies instead of crashing); module-targeted mutations
            // are refused by the fail-closed check at the call site.
            return $contextid;
        }
    }

    /**
     * Whether a skill targets a concrete module instance (CONTEXT_MODULE) via the opt-in
     * targeting contract — duck-typed so the executor stays skill-agnostic. Such a skill must
     * run at a resolved module context; the caller fails closed when it does not.
     *
     * @param object $skill
     * @return bool
     */
    private function skill_requires_module_target($skill): bool {
        return method_exists($skill, 'supports_target_context')
            && method_exists($skill, 'get_target_context_level')
            && (bool)$skill->supports_target_context()
            && (int)$skill->get_target_context_level() === CONTEXT_MODULE;
    }
}
