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

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services;

use bookingextension_agent\local\wizard\dto\preflight_result_v2;
use core\context;
use context_module;
use bookingextension_agent\local\wizard\interfaces\external_dependency_checker_interface;
use bookingextension_agent\local\wizard\interfaces\skill_interface;
use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\services\risk\risk_class_resolver;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\privacy_anonymizer;
use bookingextension_agent\local\wizard\skill_registry;
use bookingextension_agent\local\wizard\dto\agent_context;
use bookingextension_agent\local\wizard\services\security\skill_operating_context_resolver;
use bookingextension_agent\local\wizard\services\security\context_target_unresolved_exception;
use bookingextension_agent\local\wizard\dto\context_target_resolution;
use bookingextension_agent\local\wizard\services\security\native_capability_guard;

/**
 * Unified preflight pipeline for mutating command batches.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preflight_pipeline {
    /** @var skill_registry */
    private skill_registry $registry;

    /** @var conversation_store */
    private conversation_store $store;

    /** @var preflight_contract_validator */
    private preflight_contract_validator $contractvalidator;

    /** @var preflight_domain_check_runner */
    private preflight_domain_check_runner $domainrunner;

    /** @var preflight_execution_gate */
    private preflight_execution_gate $executiongate;

    /** @var external_dependency_checker_interface */
    private external_dependency_checker_interface $externaldependencychecker;

    /**
     * Constructor.
     *
     * @param skill_registry $registry
     * @param conversation_store $store
     * @param external_dependency_checker_interface|null $externaldependencychecker
     */
    public function __construct(
        skill_registry $registry,
        conversation_store $store,
        ?external_dependency_checker_interface $externaldependencychecker = null
    ) {
        $this->registry = $registry;
        $this->store = $store;
        $this->contractvalidator = new preflight_contract_validator($registry);
        $this->domainrunner = new preflight_domain_check_runner();
        $this->executiongate = new preflight_execution_gate();
        $this->externaldependencychecker = $externaldependencychecker ?? new noop_external_dependency_checker();
    }

    /** Issue code of the anonymizer person-reference gate (#2226 D3, #2363). */
    public const ISSUE_ANON_PERSON_REFERENCE = 'ANON_PERSON_REFERENCE_VALIDATION';

    /**
     * Low-confidence anonymizer tokens of a raw command input that a read-only skill would use
     * as a person (#2363): the token sits in a person-reference field, the skill is read-only,
     * the thread carries no person context and the user has not decided on the word.
     *
     * Shared by the preflight pipeline (confirmation path) and the decision service's
     * read-only chat path, so both channels apply the identical rule.
     *
     * @param skill_interface $skill Resolved skill of the command.
     * @param array $rawinput Command input BEFORE de-anonymization.
     * @param int $threadid
     * @param int $userid
     * @return array<int,array{field:string,token:string,original:string}> Empty when nothing gates.
     */
    public function find_person_reference_collisions(
        skill_interface $skill,
        array $rawinput,
        int $threadid,
        int $userid
    ): array {
        if ($threadid <= 0 || $userid <= 0) {
            return [];
        }
        $anonymizer = new privacy_anonymizer($this->store);
        $rawsuspectrefs = $anonymizer->find_low_confidence_token_references($threadid, $userid, $rawinput);
        return $this->person_reference_collisions($skill, $rawsuspectrefs, $threadid, $anonymizer);
    }

    /**
     * Clarification text for a genuinely ambiguous target: the question plus one line per
     * candidate carrying its unique id (shared by the preflight and the read-only chat path).
     *
     * @param array $candidates Candidate payloads of the target resolution.
     * @return string
     */
    public static function ambiguous_target_message(array $candidates): string {
        $lines = [];
        foreach (array_slice($candidates, 0, 10) as $candidate) {
            $lines[] = '- ' . self::format_ambiguous_candidate((array)$candidate);
        }
        return get_string('agent_target_ambiguous_choose', 'bookingextension_agent') . "\n" . implode("\n", $lines);
    }

    /**
     * Whether the ambient context is itself one of the ambiguous candidates.
     *
     * Then staying ambient is a valid, deliberate pick (the user works inside one of the
     * same-named places; architecture ch. 09 §2b: a read-only option query stays in the ambient
     * activity), so the ambiguity is not genuine. Module-level candidates carry a coursename and
     * their id is the cmid; course-level candidates carry the course id.
     *
     * @param int $contextid
     * @param array $candidates
     * @return bool
     */
    private static function ambient_is_candidate(int $contextid, array $candidates): bool {
        $context = \context::instance_by_id($contextid, IGNORE_MISSING);
        if (!$context || !in_array((int)$context->contextlevel, [CONTEXT_MODULE, CONTEXT_COURSE], true)) {
            return false;
        }
        $ambientismodule = (int)$context->contextlevel === CONTEXT_MODULE;
        foreach ($candidates as $candidate) {
            $candidate = (array)$candidate;
            $candidateismodule = trim((string)($candidate['coursename'] ?? '')) !== '';
            if ($candidateismodule === $ambientismodule && (int)($candidate['id'] ?? 0) === (int)$context->instanceid) {
                return true;
            }
        }
        return false;
    }

    /**
     * Ambiguous-target clarification for a READ-ONLY command (flowchart PP_RUN).
     *
     * Read-only commands skip this pipeline and resolve their operating context late, at the
     * executor, where an unresolvable target falls back to the ambient context (thread 515:
     * read-only never blocks on resolution). A GENUINELY ambiguous target must clarify instead:
     * the fallback would silently drop the candidates (thread 1304: two activities named
     * "booking", the planner retried 21 times without ever seeing both). Same resolver and the
     * same candidate list as the preflight; not-found and unsupported targets stay ambient, and so
     * do an unnamed target (auto-pick) and an ambiguity whose candidates include the ambient
     * context (ambient_is_candidate()).
     *
     * @param skill_interface $skill
     * @param array $rawinput Command input BEFORE de-anonymization.
     * @param int $threadid
     * @param int $contextid Ambient context id.
     * @param int $userid
     * @return array|null needs_clarification issue with the candidates; null when not ambiguous.
     */
    public function find_ambiguous_target(
        skill_interface $skill,
        array $rawinput,
        int $threadid,
        int $contextid,
        int $userid
    ): ?array {
        if (
            !method_exists($skill, 'supports_target_context')
            || !method_exists($skill, 'get_target_selector')
            || !(bool)$skill->supports_target_context()
        ) {
            return null;
        }
        $input = $rawinput;
        if ($threadid > 0 && $userid > 0) {
            $input = (new privacy_anonymizer($this->store))->deanonymize_command_input($threadid, $input);
        }
        // Only a NAMED target (an id or a query) can be genuinely ambiguous. Without one the
        // resolver auto-picks and reports every accessible instance as a candidate — that is not
        // ambiguity the user created, so the skill's own no-instance guard answers instead
        // (live thread 1314: the activity name had gone into the option search "query").
        $selector = $skill->get_target_selector($input);
        if ($selector === null || $selector->is_empty()) {
            return null;
        }
        try {
            (new skill_operating_context_resolver())->resolve($skill, $input, agent_context::from_contextid($contextid), $userid);
        } catch (context_target_unresolved_exception $e) {
            $resolution = $e->get_resolution();
            $candidates = $resolution->candidates();
            if (
                $resolution->status() === context_target_resolution::STATUS_AMBIGUOUS
                && !empty($candidates)
                && !self::ambient_is_candidate($contextid, $candidates)
            ) {
                return [
                    'code'     => 'CONTEXT_TARGET_UNRESOLVED',
                    'severity' => 'needs_clarification',
                    'message'  => self::ambiguous_target_message($candidates),
                ];
            }
        } catch (\Throwable $e) {
            return null;
        }
        return null;
    }

    /**
     * The needs_clarification issue of the person-reference gate, including the decision-chip
     * preview (source C) the frontend renders for the colliding word.
     *
     * The message names the TOKEN, never the word: it is LLM input (synchronizer, stored
     * history) and no masked value may reach an LLM in clear text (HARD RULE 2026-09-11).
     * The display layer resolves the token for the user (with the privacy marker). Only the
     * UI-only chip payload carries the original word, which the precheck WS records.
     *
     * @param string $token The anonymizer token bound to the person field.
     * @param string $word The original word behind the token (chip payload only).
     * @return array
     */
    public static function build_person_reference_issue(string $token, string $word): array {
        return [
            'code'     => self::ISSUE_ANON_PERSON_REFERENCE,
            'severity' => 'needs_clarification',
            'message'  => get_string('agent_anon_person_reference_clarify', 'bookingextension_agent', $token),
            // Clarification preview (source C): the frontend renders two decision chips for the
            // word; the chosen decision is recorded structurally via the ai_privacy_precheck WS
            // parameter — never parsed from reply text.
            'preview'  => [
                'type' => 'anon_word_decision',
                'payload' => ['word' => $word],
            ],
        ];
    }

    /**
     * Filter suspect token references down to those a read-only skill binds to a person field.
     *
     * @param skill_interface $skill
     * @param array $rawsuspectrefs Output of find_low_confidence_token_references().
     * @param int $threadid
     * @param privacy_anonymizer $anonymizer
     * @return array
     */
    private function person_reference_collisions(
        skill_interface $skill,
        array $rawsuspectrefs,
        int $threadid,
        privacy_anonymizer $anonymizer
    ): array {
        if (empty($rawsuspectrefs) || !$skill->is_read_only()) {
            return [];
        }
        $personrefs = [];
        foreach ($rawsuspectrefs as $ref) {
            if (!$this->is_person_field($skill, (string)($ref['field'] ?? ''), $anonymizer)) {
                continue;
            }
            if ($this->thread_has_person_context_for_word($threadid, $anonymizer, (string)($ref['original'] ?? ''))) {
                continue;
            }
            $personrefs[] = $ref;
        }
        return $personrefs;
    }

    /**
     * Whether an input field of a skill resolves persons (#2363, F23).
     *
     * A field qualifies by the engine-wide naming convention
     * ({@see privacy_anonymizer::is_person_reference_field()}: userquery, teacherquery,
     * targetuserquery, *userquery) or because the skill declares it through the duck-typed
     * get_person_reference_fields() — e.g. core.search_users, whose free-text query IS the
     * person lookup. No skill-name lists in the engine.
     *
     * @param skill_interface|null $skill
     * @param string $field
     * @param privacy_anonymizer $anonymizer
     * @return bool
     */
    private function is_person_field(?skill_interface $skill, string $field, privacy_anonymizer $anonymizer): bool {
        return requester_reference::is_person_field($skill, $field, $anonymizer);
    }

    /**
     * Whether the thread already resolved THIS word as part of a person (#2226 R3, narrowed by F23).
     *
     * Engine state only (observation ledger + token-map original): an executed command whose
     * person field (see is_person_field()) carried the word TOGETHER WITH further identity
     * material — a full name or an e-mail address containing it — makes a later single-word
     * mention of the same word a plausible follow-up, so the gate stays silent. A lookup of a
     * different person no longer switches the gate off for every word of the thread, and a
     * lookup that consisted of the suspect word alone is no evidence (it may itself have been
     * the collision).
     *
     * @param int $threadid
     * @param privacy_anonymizer $anonymizer
     * @param string $word Original word behind the low-confidence token.
     * @return bool
     */
    private function thread_has_person_context_for_word(int $threadid, privacy_anonymizer $anonymizer, string $word): bool {
        $target = array_values(array_unique($anonymizer->name_words($word)));
        if ($threadid <= 0 || empty($target)) {
            return false;
        }

        $ledger = new execution_observation_ledger($this->store);
        foreach ($ledger->get_recent_for_runtime($threadid, 25) as $row) {
            if ((string)($row['status'] ?? '') !== 'executed') {
                continue;
            }
            $skillname = trim((string)($row['skill'] ?? ''));
            $rowskill = $skillname !== '' ? $this->registry->get_skill($skillname) : null;
            foreach ((array)($row['input'] ?? []) as $field => $value) {
                if (!is_string($field) || !is_scalar($value) || !$this->is_person_field($rowskill, $field, $anonymizer)) {
                    continue;
                }
                $words = array_values(array_unique($anonymizer->name_words((string)$value)));
                if (count($words) > count($target) && empty(array_diff($target, $words))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Run full preflight L1->L2->L3 for a command batch.
     *
     * @param mixed[] $commands
     * @param int $threadid
     * @param int $contextid
     * @param int $userid
     * @return array{status:string,issue_codes:string[],blocking_layer:string,retry_after_ms:int,retry_count:int,duration_ms:int,prepared_commands:array[],errors:string[],attempted_skills:string[],issues:array[]}
     */
    public function run(array $commands, int $threadid, int $contextid, int $userid): array {
        $preparedcommands = [];
        $errors = [];
        $attemptedskills = [];
        $issuecodes = [];
        $issues = [];
        $layer1issuecodes = [];
        $anonymizer = new privacy_anonymizer($this->store);
        $startedat = microtime(true);
        $batchriskclass = $this->resolve_batch_risk_class($commands);
        $context = context::instance_by_id($contextid, MUST_EXIST);
        // The cmid is only needed by booking-style skills; 0 outside a module context.
        $cmid = ($context instanceof context_module) ? (int)$context->instanceid : 0;
        // The chat/thread ambient context; a command may resolve a different operating context
        // (cross-context target). Skills that do not opt in keep the ambient context unchanged.
        $ambient = agent_context::from_contextid($contextid);
        $operatingresolver = new skill_operating_context_resolver();

        foreach ($commands as $idx => $command) {
            $label = 'Command #' . ($idx + 1);
            if (!is_array($command)) {
                $errors[] = $label . ': malformed command payload.';
                $issuecodes[] = 'SCHEMA_ERROR';
                continue;
            }

            $skillname = trim((string)($command['skill'] ?? ''));
            if ($skillname === '') {
                $errors[] = $label . ': missing skill.';
                $issuecodes[] = 'SCHEMA_ERROR';
                continue;
            }
            $attemptedskills[] = $skillname;

            $skipcontractschema = !empty($command['_structural_validated']);
            if (!$skipcontractschema) {
                $schemavalidation = $this->contractvalidator->validate($command);
                $layer1issuecodes = array_values(array_unique(array_merge(
                    $layer1issuecodes,
                    (array)($schemavalidation['issue_codes'] ?? [])
                )));
                if (($schemavalidation['valid'] ?? false) !== true) {
                    $result = new preflight_result_v2(
                        'hard_block',
                        !empty($schemavalidation['issue_codes'])
                            ? (array)$schemavalidation['issue_codes']
                            : ['SCHEMA_ERROR'],
                        preflight_result_v2::BLOCKING_LAYER_SCHEMA,
                        0,
                        0,
                        (int)max(0, (microtime(true) - $startedat) * 1000)
                    );

                    return $this->build_output(
                        false,
                        $preparedcommands,
                        array_values(array_unique(array_merge($errors, (array)($schemavalidation['errors'] ?? [])))),
                        $attemptedskills,
                        array_values(array_unique(array_merge($issuecodes, (array)($result->issuecodes ?? [])))),
                        $issues,
                        $result
                    );
                }
            }

            $skill = $this->registry->get_skill($skillname);
            if ($skill === null) {
                $errors[] = $label . ': skill ' . $skillname . ' is not registered.';
                $issuecodes[] = preflight_contract_validator::ISSUE_SKILL_NOT_REGISTERED;
                continue;
            }

            $input = is_array($command['input'] ?? null) ? (array)$command['input'] : [];

            // Anonymizer collision guard (#2226 D3, widened by #2363) — evaluated on the RAW
            // input while the ANON tokens are still present, entirely from engine state
            // (token-map confidence, stored user decisions, field binding, observation ledger).
            // R3 gate: a READ-ONLY skill whose person parameter carries a low-confidence
            // single-word token, in a thread without person context, must clarify instead of
            // executing — read-only skills run without a confirmation preview, so this gate is
            // the only net (baseline SO-4). The binding to a person-reference field IS the
            // evidence that the engine treats the token as a person; no skill attribute needed.
            // R2 enrichment (further down, in the target-unresolved catch): a suspect token in
            // a NON-person slot passes through normally; only an unresolvable target names the
            // suspect word in the existing clarification.
            $rawsuspectrefs = [];
            if ($threadid > 0 && $userid > 0) {
                $rawsuspectrefs = $anonymizer->find_low_confidence_token_references($threadid, $userid, $input);
            }
            $personrefs = $this->person_reference_collisions($skill, $rawsuspectrefs, $threadid, $anonymizer);
            if (!empty($personrefs)) {
                $issue = self::build_person_reference_issue(
                    (string)$personrefs[0]['token'],
                    (string)$personrefs[0]['original']
                );
                $issuecodes[] = self::ISSUE_ANON_PERSON_REFERENCE;
                $errors[] = $label . ': ' . $issue['message'];
                $issues[] = $issue;
                continue;
            }

            if ($threadid > 0 && $userid > 0) {
                // De-anonymize against THIS thread's token map. Never re-derive the thread from
                // (userid, contextid): that lookup filters on status='active' and is blind to MCP
                // channel threads (status=<session channel>), so preflight resolution would see
                // raw ANON_USER_* tokens for every MCP session — and with a chat thread open at
                // the same context it would even read the WRONG map.
                $input = $anonymizer->deanonymize_command_input($threadid, $input);
            }

            try {
                $operatingcontextid = $operatingresolver->resolve($skill, $input, $ambient, $userid)->id();
            } catch (context_target_unresolved_exception $e) {
                if ($skill->is_read_only()) {
                    // Read-only never blocks on its operating context (thread 515, ch. 09 §2): an unknown or
                    // unnamed target falls back to the ambient context and the skill's own guards speak. The
                    // genuinely ambiguous NAMED target is clarified one step earlier by the decision service
                    // (gate_readonly_ambiguous_targets). Since wave 25 read-only commands pass this pipeline
                    // too, so the rule lives here and holds for the chat and the MCP channel alike.
                    $operatingcontextid = $ambient->id();
                } else {
                    // An opted-in skill named (or implied) a target that could not be resolved uniquely
                    // (ambiguous / not found / unsupported) → surface as a clarification. For an
                    // ambiguous outcome we list the candidates so the user can pick instead of being told
                    // a bare "could not resolve" — the resolution carries them.
                    $issuecodes[] = 'CONTEXT_TARGET_UNRESOLVED';
                    $resolution = $e->get_resolution();
                    $candidates = $resolution->candidates();
                    if ($resolution->status() === context_target_resolution::STATUS_AMBIGUOUS && !empty($candidates)) {
                        $message = self::ambiguous_target_message($candidates);
                    } else if ($resolution->status() === context_target_resolution::STATUS_NOT_FOUND) {
                        // Level-aware wording (C2): a COURSE-level target miss must talk about the
                        // missing course, not about an activity — telling a user who asked about a
                        // course to open an activity sends the repair down the wrong path.
                        $iscourselevel = method_exists($skill, 'get_target_context_level')
                            && (int)$skill->get_target_context_level() === CONTEXT_COURSE;
                        $message = get_string(
                            $iscourselevel ? 'agent_target_not_found_course' : 'agent_target_not_found',
                            'bookingextension_agent'
                        );
                    } else {
                        $message = $e->getMessage();
                    }
                    // R2 enrichment (#2226): when the unresolvable target request carried a
                    // low-confidence anon token, name the concrete word — the user then learns
                    // WHY the target may have gone missing (the word doubled as a person name)
                    // and can answer precisely. No extra LLM call: this clarification happens anyway.
                    // The text carries the TOKEN (LLM input); the display resolves it (HARD RULE 2026-09-11).
                    foreach ($rawsuspectrefs as $suspectref) {
                        $message .= "\n" . get_string(
                            'agent_anon_collision_word_hint',
                            'bookingextension_agent',
                            (string)($suspectref['token'] ?? '')
                        );
                        break;
                    }
                    $errors[] = $label . ': ' . $message;
                    $issues[] = [
                        'code'     => 'CONTEXT_TARGET_UNRESOLVED',
                        'severity' => 'needs_clarification',
                        'message'  => $message,
                    ];
                    continue;
                }
            }

            // Gate 2 (central): the user must natively hold the skill's declared capabilities at the
            // operating context. Enforced here so a skill that forgets or mis-scopes its own check is
            // still denied cleanly (no guard token is issued); the executor re-checks as the backstop.
            $missingcaps = native_capability_guard::missing_capabilities($skill, $operatingcontextid, $userid);
            if (!empty($missingcaps)) {
                foreach ($missingcaps as $missingcap) {
                    $issuecodes[] = 'NO_NATIVE_CAPABILITY';
                    $errors[] = $label . ': ' . get_string('nopermissions', 'error', $missingcap);
                }
                continue;
            }

            $preflightresult = $skill->preflight($input, $operatingcontextid, $userid);
            foreach ($preflightresult->issuecodes as $code) {
                if ($code !== '') {
                    $issuecodes[] = $code;
                }
            }
            $issues = array_merge($issues, $preflightresult->issues);

            if ($preflightresult->status !== 'pass' && $preflightresult->status !== 'soft_block') {
                foreach ($preflightresult->issues as $issue) {
                    $msg = trim((string)($issue['message'] ?? ''));
                    if ($msg !== '') {
                        $errors[] = $msg;
                    }
                }
                continue;
            }

            $skillriskclass = risk_class_resolver::resolve_for_command($command, $this->registry);
            if ($skillriskclass === skill_risk_class::R3) {
                $externalresult = $this->externaldependencychecker->check($command, $contextid, $userid);
                foreach ($externalresult->issuecodes as $code) {
                    if ($code !== '') {
                        $issuecodes[] = $code;
                    }
                }
                $issues = array_merge($issues, $externalresult->issues);
                if ($externalresult->status !== 'pass') {
                    foreach ($externalresult->issues as $issue) {
                        $msg = trim((string)($issue['message'] ?? ''));
                        if ($msg !== '') {
                            $errors[] = $msg;
                        }
                    }

                    $result = $externalresult;
                    break;
                }
            }

            $updatedcommand = $command;
            $updatedcommand['input'] = $preflightresult->preparedinput;
            // Carry the resolved operating context so the guard token and the executor target the
            // same context. Equals the ambient context today (no skill opts into cross-context yet).
            $updatedcommand['operating_contextid'] = $operatingcontextid;
            $preparedcommands[] = $updatedcommand;
        }

        $issuecodes = array_values(array_unique(array_filter(array_map('strval', $issuecodes))));
        $combinedissuecodes = array_values(array_unique(array_merge($issuecodes, $layer1issuecodes)));
        $legacyvalid = empty($errors);
        $domainresult = $this->domainrunner->run($combinedissuecodes, $startedat, count($commands));

        if (!$legacyvalid && $domainresult->status === 'pass') {
            $domainresult = new preflight_result_v2(
                'hard_block',
                $combinedissuecodes,
                preflight_result_v2::BLOCKING_LAYER_DOMAIN,
                0,
                0,
                $domainresult->durationms
            );
        }

        if ($domainresult->status === 'soft_block' && empty($preparedcommands)) {
            // A soft block promises a confirmable continuation, which needs at least one
            // preflight-prepared command to stage (prepared input + guard token, ch. 08 §5).
            // When every command hard-blocked per-command — e.g. a duplicate-signature hard
            // stop riding along with a confirmable duplicate-title code — that promise is
            // unfulfillable: report the truthful hard_block so the queue transition does not
            // park token-less items in blocked_confirmation that no executor could ever release.
            $domainresult = new preflight_result_v2(
                'hard_block',
                $combinedissuecodes,
                preflight_result_v2::BLOCKING_LAYER_DOMAIN,
                0,
                0,
                $domainresult->durationms
            );
        }

        $errorclass = preflight_error_classifier::infer_from_issue_codes($combinedissuecodes);
        $result = $domainresult;
        if (
            in_array($batchriskclass, [skill_risk_class::R2, skill_risk_class::R3], true)
            && preflight_error_classifier::is_retryable_error_class($errorclass)
        ) {
            $result = $this->executiongate->evaluate($errorclass, 0, $combinedissuecodes);
        }

        if ($result->status === 'pass' && !empty($layer1issuecodes)) {
            $result = new preflight_result_v2(
                'pass',
                $layer1issuecodes,
                '',
                $result->retryafterms,
                $result->retrycount,
                $result->durationms
            );
        }

        $valid = $result->status === 'pass' && $legacyvalid;
        if ($result->status === 'retry_hint') {
            $errors[] = 'Preflight retry requested. Please retry after backoff.';
        } else if (($result->status === 'hard_block' || $result->status === 'soft_block') && empty($errors)) {
            $errors[] = $result->status === 'soft_block'
                ? 'Preflight requires clarification/confirmation before execution.'
                : 'Preflight blocked execution.';
        }

        return $this->build_output(
            $valid,
            $preparedcommands,
            array_values(array_unique($errors)),
            $attemptedskills,
            array_values(array_unique(array_merge($combinedissuecodes, (array)$result->issuecodes))),
            $issues,
            $result
        );
    }

    /**
     * Format one ambiguity candidate so a follow-up call can target it uniquely.
     *
     * Listing bare names is not enough: two candidates may share the same display name
     * (e.g. two courses both called "Agent Smoke Course"), leaving the list unresolvable.
     * Every line therefore carries the unique id. Module-level candidates (they carry a
     * 'coursename') render as "name (coursename, cmid <id>)"; course-level candidates
     * render as "fullname (shortname, id <id>)".
     *
     * @param array $candidate One candidate payload from the target resolution.
     * @return string
     */
    private static function format_ambiguous_candidate(array $candidate): string {
        $id = (int)($candidate['id'] ?? 0);
        $name = trim((string)($candidate['name'] ?? ''));
        if ($name === '') {
            $name = '#' . $id;
        }

        $coursename = trim((string)($candidate['coursename'] ?? ''));
        if ($coursename !== '') {
            // Module-level candidate: the id is the course-module id.
            $details = [$coursename];
            if ($id > 0) {
                $details[] = 'cmid ' . $id;
            }
            return $name . ' (' . implode(', ', $details) . ')';
        }

        // Course-level candidate: the id is the course id.
        $details = [];
        $shortname = trim((string)($candidate['shortname'] ?? ''));
        if ($shortname !== '') {
            $details[] = $shortname;
        }
        if ($id > 0) {
            $details[] = 'id ' . $id;
        }
        return $name . ($details !== [] ? ' (' . implode(', ', $details) . ')' : '');
    }

    /**
     * Resolve the highest-risk class present in the batch.
     *
     * @param mixed[] $commands
     * @return string
     */
    private function resolve_batch_risk_class(array $commands): string {
        $highest = skill_risk_class::R0;
        foreach ($commands as $command) {
            if (!is_array($command)) {
                continue;
            }
            $skillriskclass = risk_class_resolver::resolve_for_command($command, $this->registry);
            if (risk_class_resolver::rank($skillriskclass) > risk_class_resolver::rank($highest)) {
                $highest = $skillriskclass;
            }
        }

        return $highest;
    }


    /**
     * Map internal values to the public preflight batch output shape.
     *
     * @param bool $valid
     * @param array[] $preparedcommands
     * @param string[] $errors
     * @param string[] $attemptedskills
     * @param string[] $issuecodes
     * @param array[] $issues
     * @param preflight_result_v2 $result
     * @return array{status:string,issue_codes:string[],blocking_layer:string,retry_after_ms:int,retry_count:int,duration_ms:int,prepared_commands:array[],errors:string[],attempted_skills:string[],issues:array[]}
     */
    private function build_output(
        bool $valid,
        array $preparedcommands,
        array $errors,
        array $attemptedskills,
        array $issuecodes,
        array $issues,
        preflight_result_v2 $result
    ): array {
        $v2result = $result->to_array();
        if (!$valid && ($v2result['status'] ?? '') === 'pass') {
            $v2result['status'] = 'hard_block';
            $v2result['blocking_layer'] = preflight_result_v2::BLOCKING_LAYER_DOMAIN;
        }

        return array_merge($v2result, [
            'prepared_commands' => $preparedcommands,
            'errors' => array_values(array_unique(array_map('strval', $errors))),
            'attempted_skills' => array_values(array_unique(array_map('strval', $attemptedskills))),
            'issue_codes' => array_values(array_unique(array_map('strval', $issuecodes))),
            'issues' => array_values(array_filter($issues, static fn($issue): bool => is_array($issue))),
        ]);
    }
}
