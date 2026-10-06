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
 * LLM output interpreter pipeline.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent\local\wizard;

use bookingextension_agent\local\wizard\services\construction\parameter_constructor;
use bookingextension_agent\local\wizard\services\construction\parameter_contract_validator;
use bookingextension_agent\local\wizard\services\input_payload_pruner;
use bookingextension_agent\local\wizard\services\model_authored_text;
use bookingextension_agent\local\wizard\services\selection\lazy_skill_loader;
use bookingextension_agent\local\wizard\services\selection\skill_selector;
use bookingextension_agent\local\wizard\interfaces\agent_interpreter;
use bookingextension_agent\local\wizard\services\security\authorization_service;

/**
 * Mandatory trust boundary between raw LLM output and the executor.
 *
 * Pipeline stages:
 *  1. JSON/structure parsing
 *  2. Response-type classification (allow-list)
 *  3. Structural validation for skill_call / confirmation_request (check_structure() — pure, no DB)
 *  4. Normalisation (dates, IDs)
 *  5. Emission of structurally-valid command objects for routing
 *
 * Deep validation (DB lookups, entity resolution, conflict detection) is NOT
 * performed here.  It is delegated to agent_decision_service via skill->preflight()
 * during the routing phase.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class interpreter implements agent_interpreter {
    /** A confirmation_request without commands, relayed as an honest clarification (re-planned once, wave 30). */
    public const CONFIRMATION_DOWNGRADE_CODE = 'CONTRACT_CONFIRMATION_DOWNGRADED_TO_CLARIFICATION';

    /** Issue code: a command named a skill that is not registered (planner slip, retried once — F62). */
    public const ISSUE_SKILL_NOT_REGISTERED = 'SKILL_NOT_REGISTERED';

    /** Allowed response_type values from the LLM. */
    private const ALLOWED_RESPONSE_TYPES = [
        'clarification',
        'confirmation_request',
        'skill_call',
        'error',
        'confirm_pending',
        'sufficient',
    ];

    /** Planner phases that must not emit command-bearing outputs. */
    private const NON_COMMAND_PHASES = ['discovery'];

    /**
     * User-facing cause for phase-contract violations (N-591a, thread 591 msg 1601).
     *
     * Plain-English LLM material for the synchronizer's [ERROR] observation — deliberately
     * generic: which phase broke which contract is planner vocabulary and travels on
     * repair_hints only, never to the user.
     */
    private const PHASE_CONTRACT_USER_CAUSE = 'An internal planning error prevented this step from '
        . 'being completed. Trying again or rephrasing the request may help.';

    /** @var skill_registry */
    private skill_registry $registry;

    /** @var string Last parse issue code for hard contract gate handling. */
    private string $lastparseissuecode = '';

    /** @var string Truncated raw parse input excerpt for diagnostics. */
    private string $lastparseinputexcerpt = '';

    /**
     * Constructor.
     *
     * @param skill_registry $registry
     */
    public function __construct(skill_registry $registry) {
        $this->registry = $registry;
    }

    /**
     * Parse and validate raw LLM output.
     *
     * @param string $rawresponse
     * @param int    $contextid
     * @param int    $userid
     * @param string $lastusermessage
     * @return array
     */
    public function interpret(string $rawresponse, int $contextid, int $userid, string $lastusermessage = ''): array {
        return $this->mark_model_message(
            $this->interpret_response($rawresponse, $contextid, $userid, $lastusermessage),
            $rawresponse
        );
    }

    /**
     * Interpret a raw planner response (see interpret()).
     *
     * @param string $rawresponse
     * @param int    $contextid
     * @param int    $userid
     * @param string $lastusermessage
     * @return array
     */
    private function interpret_response(string $rawresponse, int $contextid, int $userid, string $lastusermessage): array {
        $this->lastparseissuecode = '';
        $this->lastparseinputexcerpt = '';

        // Stage 1: Parse.
        $parsed = $this->parse($rawresponse);
        if ($parsed === null) {
            $excerpt = $this->lastparseinputexcerpt;
            $message = 'Failed to parse LLM response as JSON.';
            if ($excerpt !== '') {
                $message .= ' Raw excerpt: ' . $excerpt;
            }
            return $this->error_result_with_issue_code(
                $message,
                $this->lastparseissuecode !== '' ? $this->lastparseissuecode : 'CONTRACT_PARSE_ERROR'
            );
        }
        $parsed = $this->canonicalize_planner_shape($parsed);

        // Stage 2: Classify response type.
        $responsetype = $parsed['response_type'] ?? null;
        if (!in_array($responsetype, self::ALLOWED_RESPONSE_TYPES, true)) {
            $normalized = $this->normalize_skill_like_response($parsed, $lastusermessage);
            if ($normalized !== null) {
                $parsed = $normalized;
                $responsetype = $parsed['response_type'];
            } else {
                return $this->error_result_with_issue_code(
                    'LLM returned an unknown or missing response_type: ' . ($responsetype ?? '(none)'),
                    'CONTRACT_UNKNOWN_RESPONSE_TYPE'
                );
            }
        }

        $lang = $this->safe_string($parsed['lang'] ?? '');
        $userlang = $this->safe_string($parsed['user_lang'] ?? $parsed['userlang'] ?? '');
        // The planner's step label travels as written. Whether the work already happened is engine state
        // (the thread's completed commands), never a property of the wording — the previous guard matched
        // seven German and English regexes on LLM text and did nothing at all for French (HARD RULE: no
        // lexical detection). The orchestrator suppresses the step bubble for an already completed skill.
        $nextstepintent = $this->safe_string($parsed['next_step_intent'] ?? '');
        if ($lang === '' && $userlang !== '') {
            $lang = $userlang;
        }
        if ($lang !== '') {
            $lang = strtolower(substr($lang, 0, 2));
        }

        // Passthrough for sufficient: SR/SYN signals that observations are complete.
        if ($responsetype === 'sufficient') {
            return $this->with_optional_next_step_intent([
                'response_type' => 'sufficient',
                'lang'          => $lang,
                'message'       => $this->strip_command_prefix($this->safe_string($parsed['message'] ?? '')),
                'commands'      => [],
                'ambiguities'   => [],
                'ambiguity_options' => [],
                'errors'        => [],
            ], $nextstepintent);
        }

        // Passthrough for clarification, error, and confirm_pending types.
        if ($responsetype === 'clarification') {
            $clearmessage = $this->strip_command_prefix($this->safe_string($parsed['message'] ?? ''));
            if ($clearmessage === '') {
                return $this->error_result_with_issue_code(
                    'CONTRACT_VIOLATION: clarification response has empty message field',
                    'CONTRACT_EMPTY_MESSAGE_CLARIFICATION'
                );
            }
            $clarification = [
                'response_type' => 'clarification',
                'lang'          => $lang,
                'message'       => $clearmessage,
                'commands'      => [],
                'ambiguities'   => [],
                'ambiguity_options' => [],
                'errors'        => [],
            ];
            // Wave 30 (E4): a construction may state structurally that the selected skill cannot perform the
            // request at all ("skill_fits": false) - distinct from a missing value only the user can give. The
            // runtime lets selection choose again once; the message (the reason) travels as a planner-only hint.
            $skillfits = $parsed['skill_fits'] ?? null;
            if ($skillfits === false || (is_string($skillfits) && strtolower(trim($skillfits)) === 'false')) {
                $clarification['issue_codes'] = ['CONSTRUCTION_SKILL_UNFIT'];
                $clarification['repair_hints'] = [$clearmessage];
            }
            return $this->with_optional_next_step_intent($clarification, $nextstepintent);
        }

        if ($responsetype === 'error') {
            $errormessage = $this->strip_command_prefix($this->safe_string($parsed['message'] ?? 'AI returned an error.'));
            if ($errormessage === '') {
                $errormessage = 'AI returned an error (message was empty).';
            }
            return $this->with_optional_next_step_intent([
                'response_type' => 'error',
                'lang'          => $lang,
                'message'       => $errormessage,
                'commands'      => [],
                'ambiguities'   => [],
                'ambiguity_options' => [],
                'errors'        => [$errormessage],
            ], $nextstepintent);
        }

        if ($responsetype === 'confirm_pending') {
            $confirmmessage = $this->strip_command_prefix($this->safe_string($parsed['message'] ?? ''));
            if ($confirmmessage === '') {
                return $this->error_result_with_issue_code(
                    'CONTRACT_VIOLATION: confirm_pending response has empty message field',
                    'CONTRACT_EMPTY_MESSAGE_CONFIRM_PENDING'
                );
            }
            return $this->with_optional_next_step_intent([
                'response_type' => 'confirm_pending',
                'lang'          => $lang,
                'message'       => $confirmmessage,
                'commands'      => [],
                'ambiguities'   => [],
                'ambiguity_options' => [],
                'errors'        => [],
            ], $nextstepintent);
        }

        // Stages 3–6: Full validation for command-bearing responses.
        $commands = $this->normalize_commands_payload($parsed, $lastusermessage);
        if (!is_array($commands) || empty($commands)) {
            // A confirmation_request without commands is semantically a question to the
            // user, not a command envelope: relay it as an honest clarification. The code
            // lets the runtime re-plan the step once through the selector (wave 30); its
            // hint offers the honest way out (ask for the one missing value) so the model
            // is not pushed into inventing keys. Unhealed, this clarification is the answer.
            $downgrademessage = $this->strip_command_prefix($this->safe_string($parsed['message'] ?? ''));
            if ($responsetype === 'confirmation_request' && $downgrademessage !== '') {
                return $this->with_optional_next_step_intent([
                    'response_type' => 'clarification',
                    'lang'          => $lang,
                    'message'       => $downgrademessage,
                    'commands'      => [],
                    'ambiguities'   => [],
                    'ambiguity_options' => [],
                    'errors'        => [],
                    'issue_codes'   => [self::CONFIRMATION_DOWNGRADE_CODE],
                ], $nextstepintent);
            }
            return $this->error_result('Response type requires at least one command but none were provided.');
        }

        [$validatedcommands, $errors, $ambiguities, $ambiguityoptions, $attemptedskills, $issuecodes, $confirmablecommands,
            $repairhints] = $this->validate_commands($commands, $contextid, $userid, $lastusermessage);

        // Stage 5: Any ambiguity from backend validation stops execution and forces clarification.
        // The confirm button must NEVER appear when unresolved questions remain.

        if (!empty($errors)) {
            $validationmessage = $this->user_facing_validation_message($errors, $lang);
            $recoverableinputerror = in_array('RECOVERABLE_INPUT_ERROR', array_map(
                static fn(string $issuecode): string => strtoupper(trim($issuecode)),
                (array)$issuecodes
            ), true);
            if (!empty($confirmablecommands)) {
                return $this->with_optional_next_step_intent([
                    'response_type' => 'confirmation_request',
                    'lang'          => $lang,
                    'message'       => $validationmessage,
                    'commands'      => $confirmablecommands,
                    'ambiguities'   => [],
                    'ambiguity_options' => $ambiguityoptions,
                    'errors'        => $errors,
                    'repair_hints'  => $repairhints,
                    'attempted_skills' => $attemptedskills,
                    'issue_codes'   => $issuecodes,
                ], $nextstepintent);
            }
            return $this->with_optional_next_step_intent([
                'response_type' => $recoverableinputerror ? 'clarification' : 'error',
                'lang'          => $lang,
                'message'       => $validationmessage,
                'commands'      => [],
                'ambiguities'   => [],
                'ambiguity_options' => [],
                'errors'        => $errors,
                'repair_hints'  => $repairhints,
                'attempted_skills' => $attemptedskills,
                'issue_codes'   => $issuecodes,
            ], $nextstepintent);
        }

        if (!empty($ambiguities)) {
            if (empty($errors) && !empty($confirmablecommands)) {
                return $this->with_optional_next_step_intent([
                    'response_type' => 'confirmation_request',
                    'lang'          => $lang,
                    // For backend-driven confirmable issues, prefer skill-validator wording
                    // over generic LLM confirmation text so the user sees the real reason.
                    'message'       => $this->confirmation_message_from_ambiguities($ambiguities),
                    'commands'      => $confirmablecommands,
                    'ambiguities'   => [],
                    'ambiguity_options' => $ambiguityoptions,
                    'errors'        => [],
                    'attempted_skills' => $attemptedskills,
                    'issue_codes'   => $issuecodes,
                ], $nextstepintent);
            }

            return $this->with_optional_next_step_intent([
                'response_type' => 'clarification',
                'lang'          => $lang,
                'message'       => $this->clarification_message($parsed, $ambiguities),
                'commands'      => [],
                'ambiguities'   => $ambiguities,
                'ambiguity_options' => $ambiguityoptions,
                'errors'        => [],
                'attempted_skills' => $attemptedskills,
                'issue_codes'   => $issuecodes,
            ], $nextstepintent);
        }

        return $this->with_optional_next_step_intent([
            'response_type' => $responsetype,
            'lang'          => $lang,
            'message'       => $this->safe_string($parsed['message'] ?? ''),
            'commands'      => $validatedcommands,
            'ambiguities'   => [],
            'ambiguity_options' => [],
            'errors'        => [],
            'attempted_skills' => $attemptedskills,
            'issue_codes'   => $issuecodes,
        ], $nextstepintent);
    }

    /**
     * Interpret phase output with explicit phase context.
     *
     * @param string $rawresponse
     * @param string $phase
     * @param array $context
     * @return array
     */
    public function interpret_phase_output(string $rawresponse, string $phase, array $context = []): array {
        return $this->mark_model_message($this->interpret_phase_response($rawresponse, $phase, $context), $rawresponse);
    }

    /**
     * Interpret phase output (see interpret_phase_output()).
     *
     * @param string $rawresponse
     * @param string $phase
     * @param array $context
     * @return array
     */
    private function interpret_phase_response(string $rawresponse, string $phase, array $context): array {
        $contextid = (int)($context['contextid'] ?? 0);
        $userid = (int)($context['userid'] ?? 0);
        $lastusermessage = (string)($context['lastusermessage'] ?? '');
        $normalizedphase = $this->normalize_phase_name($phase);

        if ($normalizedphase === 'selection') {
            $result = $this->interpret_selection_phase_output($rawresponse, $contextid, $userid, $lastusermessage);
            $result = $this->enforce_phase_contract($result, $normalizedphase, $context);
            $result['phase'] = $normalizedphase;
            return $result;
        }

        $result = $this->interpret($rawresponse, $contextid, $userid, $lastusermessage);
        $result = $this->enforce_phase_contract($result, $normalizedphase, $context);
        $result['phase'] = $normalizedphase;
        return $result;
    }

    /**
     * Interpret the selection phase as a command-bearing selector call without executing the skill.
     *
     * @param string $rawresponse
     * @param int $contextid
     * @param int $userid
     * @param string $lastusermessage
     * @return array
     */
    private function interpret_selection_phase_output(
        string $rawresponse,
        int $contextid,
        int $userid,
        string $lastusermessage = ''
    ): array {
        $this->lastparseissuecode = '';
        $this->lastparseinputexcerpt = '';

        $parsed = $this->parse($rawresponse);
        if ($parsed === null) {
            $excerpt = $this->lastparseinputexcerpt;
            $message = 'Failed to parse LLM response as JSON.';
            if ($excerpt !== '') {
                $message .= ' Raw excerpt: ' . $excerpt;
            }
            return $this->error_result_with_issue_code(
                $message,
                $this->lastparseissuecode !== '' ? $this->lastparseissuecode : 'CONTRACT_PARSE_ERROR'
            );
        }
        $parsed = $this->canonicalize_planner_shape($parsed);

        $responsetype = $this->safe_string($parsed['response_type'] ?? '');
        if (!in_array($responsetype, ['skill_call', 'clarification', 'confirm_pending', 'sufficient', 'error'], true)) {
            $normalized = $this->normalize_skill_like_response($parsed, $lastusermessage);
            if ($normalized !== null) {
                $parsed = $normalized;
                $responsetype = $this->safe_string($parsed['response_type'] ?? '');
            }
        }

        if ($responsetype === '') {
            return $this->error_result_with_issue_code(
                'LLM returned an unknown or missing response_type: (none)',
                'CONTRACT_UNKNOWN_RESPONSE_TYPE'
            );
        }

        $lang = $this->safe_string($parsed['lang'] ?? '');
        $userlang = $this->safe_string($parsed['user_lang'] ?? $parsed['userlang'] ?? '');
        if ($lang === '' && $userlang !== '') {
            $lang = $userlang;
        }
        if ($lang !== '') {
            $lang = strtolower(substr($lang, 0, 2));
        }

        // The planner's human-readable step intent (shown as the progress step bubble). The selection
        // interpreter previously dropped this field entirely; mirror interpret()'s handling. Whether the
        // work already happened is decided by the orchestrator from the thread's completed commands.
        $nextstepintent = $this->safe_string($parsed['next_step_intent'] ?? '');

        if (in_array($responsetype, ['clarification', 'confirm_pending', 'sufficient', 'error'], true)) {
            $message = $this->safe_string($parsed['message'] ?? '');
            if ($responsetype !== 'sufficient' && $message === '') {
                return $this->error_result_with_issue_code(
                    'Selection phase returned an empty message for non-sufficient response_type.',
                    'CONTRACT_EMPTY_SELECTION_MESSAGE'
                );
            }

            return [
                'response_type' => $responsetype,
                'lang' => $lang,
                'user_lang' => $userlang,
                'message' => $this->strip_command_prefix($message),
                'commands' => [],
                'selected_skill' => '',
                'ambiguities' => [],
                'ambiguity_options' => [],
                'errors' => $responsetype === 'error' && $message !== '' ? [$message] : [],
                'issue_codes' => [],
            ];
        }

        $commands = $this->normalize_commands_payload($parsed, $lastusermessage);
        if (count($commands) !== 1) {
            return $this->error_result_with_issue_code(
                'Selection phase must emit exactly one skill command.',
                'CONTRACT_SELECTION_SINGLE_COMMAND_REQUIRED'
            );
        }

        $selectedskill = $this->safe_string($commands[0]['skill'] ?? '');
        if ($selectedskill === '') {
            return $this->error_result_with_issue_code(
                'Selection phase command is missing a skill name.',
                'CONTRACT_SELECTION_SKILL_MISSING'
            );
        }

        $plannedsteps = [];
        if (isset($parsed['planned_steps']) && is_array($parsed['planned_steps'])) {
            foreach ($parsed['planned_steps'] as $step) {
                $intent = is_array($step)
                    ? trim((string)($step['intent'] ?? ''))
                    : trim((string)$step);
                if ($intent !== '') {
                    $plannedsteps[] = ['intent' => $intent];
                }
            }
        }

        return [
            'response_type' => 'skill_call',
            'lang' => $lang,
            'user_lang' => $userlang,
            'message' => $this->strip_command_prefix($this->safe_string($parsed['message'] ?? '')),
            'commands' => $commands,
            'selected_skill' => $selectedskill,
            'planned_steps' => $plannedsteps,
            'next_step_intent' => $nextstepintent,
            'ambiguities' => [],
            'ambiguity_options' => [],
            'errors' => [],
            'issue_codes' => [],
        ];
    }

    /**
     * Enforce explicit response contracts per planner phase.
     *
     * Discovery must not return command-bearing outputs. Selection is a
     * command-bearing selector call that must stay single-skill.
     *
     * @param array $result
     * @param string $phase
     * @param array $context
     * @return array
     */
    private function enforce_phase_contract(array $result, string $phase, array $context = []): array {
        $responsetype = $this->safe_string($result['response_type'] ?? '');
        if ($responsetype === '' || $responsetype === 'error') {
            return $result;
        }

        if (in_array($phase, self::NON_COMMAND_PHASES, true)) {
            if (in_array($responsetype, ['skill_call', 'confirmation_request'], true)) {
                return $this->error_result_with_issue_code(
                    'CONTRACT_VIOLATION: phase "' . $phase . '" must not emit command-bearing response_type.',
                    'CONTRACT_PHASE_RESPONSE_TYPE',
                    self::PHASE_CONTRACT_USER_CAUSE
                );
            }

            $commands = $result['commands'] ?? [];
            if (is_array($commands) && !empty($commands)) {
                return $this->error_result_with_issue_code(
                    'CONTRACT_VIOLATION: phase "' . $phase . '" must not emit commands.',
                    'CONTRACT_PHASE_COMMANDS_NOT_ALLOWED',
                    self::PHASE_CONTRACT_USER_CAUSE
                );
            }
        }

        if ($phase === 'parameter_construction') {
            if (in_array($responsetype, ['skill_call', 'confirmation_request'], true)) {
                $commands = $result['commands'] ?? [];
                if (!is_array($commands)) {
                    $commands = [];
                }

                if (empty($commands)) {
                    return $this->error_result_with_issue_code(
                        'CONTRACT_VIOLATION: phase "' . $phase . '" must emit at least one command.',
                        'CONTRACT_COMMANDS_REQUIRED'
                    );
                }

                $allowedskills = array_values(array_filter(array_map(
                    fn($skill): string => trim((string)$skill),
                    (array)($context['allowed_skills'] ?? [])
                )));

                if (!empty($allowedskills)) {
                    foreach ($commands as $command) {
                        if (!is_array($command)) {
                            return $this->error_result_with_issue_code(
                                'CONTRACT_VIOLATION: phase "' . $phase . '' . '" command payload is invalid.',
                                'CONTRACT_PHASE_SKILL_NOT_ALLOWED',
                                self::PHASE_CONTRACT_USER_CAUSE
                            );
                        }

                        $skill = trim((string)($command['skill'] ?? ''));
                        if ($skill === '' || !in_array($skill, $allowedskills, true)) {
                            return $this->error_result_with_issue_code(
                                'CONTRACT_VIOLATION: phase "' . $phase
                                    . '" command skill "' . $skill . '" is outside discovery-ranked allow-list ('
                                    . implode(', ', $allowedskills) . ').',
                                'CONTRACT_PHASE_SKILL_NOT_ALLOWED',
                                self::PHASE_CONTRACT_USER_CAUSE
                            );
                        }
                    }
                }
            }
        }

        if ($phase === 'selection') {
            if ($responsetype === 'skill_call') {
                $commands = $result['commands'] ?? [];
                if (!is_array($commands)) {
                    $commands = [];
                }

                if (count($commands) !== 1) {
                    return $this->error_result_with_issue_code(
                        'CONTRACT_VIOLATION: phase "' . $phase . '" must emit exactly one selector command.',
                        'CONTRACT_SELECTION_SINGLE_COMMAND_REQUIRED'
                    );
                }

                $selectedskill = trim((string)($result['selected_skill'] ?? ''));
                if ($selectedskill === '') {
                    return $this->error_result_with_issue_code(
                        'CONTRACT_VIOLATION: phase "' . $phase . '" must provide selected_skill for handoff.',
                        'CONTRACT_SELECTION_SKILL_MISSING'
                    );
                }

                $commandskill = trim((string)($commands[0]['skill'] ?? ''));
                if ($commandskill === '' || $commandskill !== $selectedskill) {
                    return $this->error_result_with_issue_code(
                        'CONTRACT_VIOLATION: phase "' . $phase
                            . '" selector command skill must match selected_skill.',
                        'CONTRACT_SELECTION_SKILL_MISMATCH'
                    );
                }
            }
        }

        return $result;
    }

    /**
     * Normalize command payload shapes to a canonical list of command objects.
     *
     * Accepts:
     * - Commands as list: [{skill,version,input|parameters}, ...]
     * - Commands as single object: {skill,version,input|parameters}
     * - Top-level skill/version/input fields when commands is missing
     *
     * @param array $parsed
     * @param string $lastusermessage
     * @return array
     */
    private function normalize_commands_payload(array $parsed, string $lastusermessage = ''): array {
        $commands = $parsed['commands'] ?? null;

        if (is_array($commands) && isset($commands['skill']) && !array_is_list($commands)) {
            $commands = [$commands];
        }

        if (is_array($commands) && !empty($commands)) {
            $normalized = [];
            foreach ($commands as $command) {
                if (!is_array($command)) {
                    continue;
                }

                $skillname = $this->safe_string($command['skill'] ?? '');
                if ($skillname === '') {
                    continue;
                }

                $input = is_array($command['parameters'] ?? null) ? $command['parameters'] : [];
                if (is_array($command['input'] ?? null)) {
                    $input = array_merge($input, $command['input']);
                }
                $input = $this->unwrap_redundant_input_envelope($input);
                $input = input_payload_pruner::prune($input);

                $normalized[] = [
                    'skill' => $skillname,
                    'version' => max(1, (int)($command['version'] ?? 1)),
                    'input' => $input,
                ];
            }

            return $normalized;
        }

        // Fallback: top-level skill/version/input fields.
        $skillname = $this->safe_string($parsed['skill'] ?? '');
        if ($skillname !== '') {
            // Mirror the commands[] path: a top-level command may carry its payload under
            // "parameters" or "input" (the planner is not consistent). Read parameters first,
            // then merge input, so a top-level {"skill":...,"parameters":{...}} command does not
            // silently lose all its arguments (which surfaced as a false GENERATE_QUESTIONS_NO_SOURCE).
            $input = is_array($parsed['parameters'] ?? null) ? $parsed['parameters'] : [];
            if (is_array($parsed['input'] ?? null)) {
                $input = array_merge($input, $parsed['input']);
            }
            $input = $this->unwrap_redundant_input_envelope($input);
            $input = input_payload_pruner::prune($input);
            return [[
                'skill' => $skillname,
                'version' => max(1, (int)($parsed['version'] ?? 1)),
                'input' => $input,
            ]];
        }

        return [];
    }

    /**
     * Collapse a redundant nested parameter envelope.
     *
     * Some planner outputs wrap the real parameters in an extra "input" or "parameters"
     * key (e.g. {"parameters":{"input":{"content":...}}} or {"input":{"input":{...}}}),
     * which would otherwise reach a skill as $input['input'] and hide every real field —
     * the skill then sees no parameters and wrongly reports missing input. When such a
     * wrapper key holds an array, its contents are merged up one level; genuine sibling
     * fields are preserved, and the inner payload wins on key collisions.
     *
     * No skill declares a property literally named "input" or "parameters", so unwrapping
     * these keys cannot clobber a real field.
     *
     * @param array $input
     * @return array
     */
    private function unwrap_redundant_input_envelope(array $input): array {
        foreach (['input', 'parameters'] as $envelopekey) {
            if (!is_array($input[$envelopekey] ?? null)) {
                continue;
            }
            $nested = $input[$envelopekey];
            unset($input[$envelopekey]);
            // Inner payload carries the real fields; keep it on collision, retain siblings otherwise.
            $input = $nested + $input;
        }
        return $input;
    }

    /**
     * Attach optional framework-level next_step_intent to normalized payloads.
     *
     * @param array $payload
     * @param string $nextstepintent
     * @return array
     */
    private function with_optional_next_step_intent(array $payload, string $nextstepintent): array {
        $intent = trim($nextstepintent);
        if ($intent !== '') {
            $payload['next_step_intent'] = $intent;
        }

        return $payload;
    }


    /**
     * Move contract values the planner put under the wrong key to their canonical place.
     *
     * Runs on every parsed answer, before classification, and is a no-op for a canonical one. It reads
     * key STRUCTURE only — the response_type enum and the contract's own field names — never text:
     *  - a key named like a response_type whose value is an object carrying selected_skill|skill is
     *    that response_type (when none is valid) and the single command (when commands[] is empty);
     *    its message fills an empty top-level message;
     *  - a string under the key equal to the response_type fills an empty message;
     *  - top-level selected_skill stands in for skill when commands[] is empty and the response_type
     *    is command-bearing or missing.
     * Sofabooking threads 1311/1312 (2026-10-01, #2529): the constructor wrote the command object under
     * "confirmation_request" (the turn ended as CONTRACT_UNKNOWN_RESPONSE_TYPE, no retry), put
     * selected_skill and parameters next to the response_type, and the selector put its question under
     * "clarification" — each a complete answer that cost the user an error or a 15-second retry round.
     *
     * @param array $parsed
     * @return array
     */
    private function canonicalize_planner_shape(array $parsed): array {
        $responsetype = $this->safe_string($parsed['response_type'] ?? '');
        $hasvalidtype = in_array($responsetype, self::ALLOWED_RESPONSE_TYPES, true);
        $commands = $parsed['commands'] ?? null;
        $hascommands = is_array($commands) && !empty($commands);
        $message = $this->safe_string($parsed['message'] ?? '');

        foreach (self::ALLOWED_RESPONSE_TYPES as $type) {
            $value = $parsed[$type] ?? null;
            if (is_array($value)) {
                $skill = $this->safe_string($value['selected_skill'] ?? $value['skill'] ?? '');
                if ($skill === '') {
                    continue;
                }
                if (!$hasvalidtype) {
                    $parsed['response_type'] = $type;
                    $responsetype = $type;
                    $hasvalidtype = true;
                }
                if (!$hascommands) {
                    $command = $value;
                    unset($command['selected_skill']);
                    $command['skill'] = $skill;
                    $parsed['commands'] = [$command];
                    $hascommands = true;
                }
                $nestedmessage = $this->safe_string($value['message'] ?? '');
                if ($message === '' && $nestedmessage !== '') {
                    $parsed['message'] = $nestedmessage;
                    $message = $nestedmessage;
                }
            } else if (is_string($value) && trim($value) !== '') {
                if (!$hasvalidtype) {
                    $parsed['response_type'] = $type;
                    $responsetype = $type;
                    $hasvalidtype = true;
                }
                if ($message === '' && $responsetype === $type) {
                    $parsed['message'] = trim($value);
                    $message = $parsed['message'];
                }
            }
        }

        $commandbearing = !$hasvalidtype || in_array($responsetype, ['skill_call', 'confirmation_request'], true);
        if (
            $commandbearing
            && !$hascommands
            && $this->safe_string($parsed['skill'] ?? '') === ''
            && $this->safe_string($parsed['selected_skill'] ?? '') !== ''
        ) {
            $parsed['skill'] = $this->safe_string($parsed['selected_skill']);
        }

        return $parsed;
    }

    /**
     * Normalize common skill-like malformed outputs into canonical skill_call payload.
     *
     * @param array  $parsed
     * @param string $lastusermessage  Latest user message text, used as question-field fallback.
     * @return array|null
     */
    private function normalize_skill_like_response(array $parsed, string $lastusermessage = ''): ?array {
        $allowedskills = $this->registry->get_skill_names();
        $nextstepintent = $this->safe_string($parsed['next_step_intent'] ?? '');
        $modeluserlang = $this->safe_string($parsed['user_lang'] ?? $parsed['userlang'] ?? '');

        $responsetype = (string)($parsed['response_type'] ?? '');
        $responsereferencedskill = $this->safe_string($responsetype);
        if ($responsereferencedskill !== '' && in_array($responsereferencedskill, $allowedskills, true)) {
            $input = $this->extract_command_input($parsed);
            return [
                'response_type' => 'skill_call',
                'message' => $this->safe_string($parsed['message'] ?? 'Executing.'),
                'next_step_intent' => $nextstepintent,
                'commands' => [
                    [
                        'skill' => $responsereferencedskill,
                        'version' => (int)($parsed['version'] ?? 1),
                        'input' => $input,
                    ],
                ],
            ];
        }

        $skill = (string)($parsed['skill'] ?? '');
        $resolvedskill = $this->safe_string($skill);
        if ($resolvedskill !== '') {
            $input = $this->extract_command_input($parsed);
            return [
                'response_type' => 'skill_call',
                'message' => $this->safe_string($parsed['message'] ?? 'Executing.'),
                'next_step_intent' => $nextstepintent,
                'commands' => [
                    [
                        'skill' => $resolvedskill,
                        'version' => (int)($parsed['version'] ?? 1),
                        'input' => $input,
                    ],
                ],
            ];
        }

        $commands = $parsed['commands'] ?? null;
        if (is_array($commands) && !empty($commands)) {
            $normalizedcommands = [];
            foreach ($commands as $command) {
                if (!is_array($command)) {
                    continue;
                }
                $commandskill = $this->safe_string($command['skill'] ?? '');
                if ($commandskill === '') {
                    continue;
                }
                $commandinput = $this->extract_command_input($command);
                $normalizedcommands[] = [
                    'skill' => $commandskill,
                    'version' => (int)($command['version'] ?? 1),
                    'input' => $commandinput,
                ];
            }
            if (!empty($normalizedcommands)) {
                return [
                    'response_type' => 'skill_call',
                    'lang' => $this->safe_string($parsed['lang'] ?? ''),
                    'message' => $this->safe_string($parsed['message'] ?? 'Executing.'),
                    'next_step_intent' => $nextstepintent,
                    'commands' => $normalizedcommands,
                ];
            }
        }

        // Fallback: LLM produced a message without any skill-call signal.
        // Heal it to clarification so the synthesis path can proceed rather than
        // triggering an unnecessary recovery loop iteration.
        $fallbackmessage = $this->safe_string($parsed['message'] ?? '');
        if ($responsetype === '' && $fallbackmessage !== '') {
            $modellang = $this->safe_string($parsed['lang'] ?? '');
            if ($modellang === '' && $modeluserlang !== '') {
                $modellang = $modeluserlang;
            }
            return [
                'response_type'     => 'clarification',
                'lang'              => $modellang,
                'user_lang'         => $modeluserlang,
                'message'           => $this->strip_command_prefix($fallbackmessage),
                'commands'          => [],
                'ambiguities'       => [],
                'ambiguity_options' => [],
                'errors'            => [],
                'issue_codes'       => ['CONTRACT_MISSING_RESPONSE_TYPE_HEALED'],
                'next_step_intent'  => $nextstepintent,
            ];
        }

        return null;
    }

    /**
     * Safely coerce an arbitrary value to a trimmed string.
     *
     * @param mixed $value
     * @return string
     */
    private function safe_string($value): string {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return trim((string)$value);
        }

        if (is_scalar($value) && $value !== null) {
            return trim((string)$value);
        }

        return '';
    }

    /**
     * Build a generic error response payload.
     *
     * @param string $message
     * @return array
     */
    private function error_result(string $message): array {
        return $this->error_result_with_issue_code($message, 'CONTRACT_VALIDATION_ERROR');
    }

    /**
     * Build an error response payload with a canonical issue code.
     *
     * Two-channel contract (F3, N-591a): when $usercause is given, it becomes the only
     * user-facing cause text (message + errors — the channel the synchronizer's [ERROR]
     * observation explains to the user), while the technical $message moves to repair_hints,
     * the planner-only retry channel. Thread 591 msg 1601 showed why: the raw
     * "CONTRACT_VIOLATION: … outside discovery-ranked allow-list." string reached the user
     * verbatim. Without $usercause the payload is unchanged (technical message stays the
     * cause — those sites either never reach the user or still await their F3 migration).
     *
     * @param string $message
     * @param string $issuecode
     * @param string $usercause
     * @return array
     */
    private function error_result_with_issue_code(string $message, string $issuecode, string $usercause = ''): array {
        $cleanmessage = $this->safe_string($message);
        $cleanusercause = $this->safe_string($usercause);
        $result = [
            'response_type' => 'error',
            'lang' => '',
            'message' => $cleanusercause !== '' ? $cleanusercause : $cleanmessage,
            'commands' => [],
            'ambiguities' => [],
            'ambiguity_options' => [],
            'errors' => $cleanmessage !== '' ? [$cleanmessage] : [],
            'issue_codes' => [$this->safe_string($issuecode)],
        ];
        if ($cleanusercause !== '') {
            $result['errors'] = [$cleanusercause];
            $result['repair_hints'] = $cleanmessage !== '' ? [$cleanmessage] : [];
        }
        return $result;
    }

    /**
     * Build a fallback clarification message from ambiguity data.
     *
     * @param array $parsed
     * @param array $ambiguities
     * @return string
     */
    private function clarification_message(array $parsed, array $ambiguities): string {
        $message = $this->strip_command_prefix($this->safe_string($parsed['message'] ?? ''));
        if ($message !== '') {
            return $message;
        }

        $summary = [];
        foreach ($ambiguities as $ambiguity) {
            if (!is_array($ambiguity)) {
                continue;
            }

            $label = $this->safe_string($ambiguity['label'] ?? '');
            if ($label !== '') {
                $summary[] = $label;
            }
        }

        if (!empty($summary)) {
            return implode(' ', array_unique($summary));
        }

        return 'Bitte präzisieren Sie die Anfrage.';
    }

    /**
     * Build a fallback confirmation message from backend ambiguities.
     *
     * @param array $ambiguities
     * @return string
     */
    private function confirmation_message_from_ambiguities(array $ambiguities): string {
        $labels = [];
        foreach ($ambiguities as $ambiguity) {
            if (!is_array($ambiguity)) {
                continue;
            }

            $label = $this->safe_string($ambiguity['label'] ?? '');
            if ($label !== '') {
                $labels[] = $label;
            }
        }

        if (!empty($labels)) {
            return implode(' ', array_unique($labels));
        }

        return 'Bitte bestätigen Sie die vorgeschlagene Aktion.';
    }

    /**
     * Extract command input while tolerating common wrapper keys from LLM output.
     *
     * @param array $payload
     * @return array
     */
    private function extract_command_input(array $payload): array {
        // Mirror normalize_commands_payload(): the planner may carry the payload under
        // "parameters" or "input" (or both) — reading only "input" silently dropped every
        // argument of a naked {"skill":…,"parameters":{…}} response, so a perfectly
        // constructed fullname/topic surfaced as a false "<field> is required" error
        // (threads 585/586). Parameters first, input wins on collision.
        $input = is_array($payload['parameters'] ?? null) ? (array)$payload['parameters'] : [];
        if (is_array($payload['input'] ?? null)) {
            $input = array_merge($input, (array)$payload['input']);
        }
        return $input;
    }

    /**
     * Mark the result's message as model-written when it is exactly the message the model returned (George
     * 2026-09-26: model text is never masked again; model_authored_text). Engine texts - parse errors, defaults such
     * as "Executing.", contract messages - differ from the model's message and stay unmarked.
     *
     * @param array $result
     * @param string $rawresponse
     * @return array
     */
    private function mark_model_message(array $result, string $rawresponse): array {
        $message = (string)($result['message'] ?? '');
        if ($message === '') {
            return $result;
        }
        $issuecode = $this->lastparseissuecode;
        $excerpt = $this->lastparseinputexcerpt;
        $parsed = $this->parse($rawresponse);
        $this->lastparseissuecode = $issuecode;
        $this->lastparseinputexcerpt = $excerpt;
        $modelmessage = is_array($parsed) ? $this->safe_string($parsed['message'] ?? '') : '';
        if ($modelmessage === '') {
            return model_authored_text::mark($result, '');
        }
        $candidate = $message === $this->strip_command_prefix($modelmessage) ? $message : $modelmessage;
        return model_authored_text::mark($result, $candidate);
    }

    /**
     * Parse raw LLM output to an array.
     *
     * The LLM is instructed to respond in JSON.  We attempt to extract a
     * JSON object even if surrounded by markdown fences.
     *
     * @param  string     $rawresponse
     * @return array|null Parsed array or null on failure.
     */
    private function parse(string $rawresponse): ?array {
        $candidate = $this->sanitize_json_payload($rawresponse);
        if ($candidate === null) {
            return null;
        }

        $data = json_decode($candidate, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            // Salvage the German-quote idiom „Wort" (typographic opener, ASCII closer): the
            // straight quote terminates the JSON string and kills an otherwise perfect reply.
            // Runs ONLY after a failed parse; valid JSON never reaches this path.
            // The content must not contain a backslash: an already escaped quote (\") is valid JSON and
            // healing it would produce the invalid escape \“ (run 16, thread 5173: one escaped and one
            // broken quote in the same message).
            $healed = preg_replace(
                '/\x{201E}([^"\x{201C}\x{201D}\x{201E}\\\\]{1,120}?)"/u',
                "\u{201E}\$1\u{201C}",
                $candidate
            );
            if (is_string($healed) && $healed !== $candidate) {
                $data = json_decode($healed, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                    return $data;
                }
            }
            $this->lastparseissuecode = 'CONTRACT_PARSE_ERROR';
            $this->lastparseinputexcerpt = $this->truncate_parse_excerpt($candidate);
            return null;
        }

        return $data;
    }

    /**
     * Sanitize raw model output to a single JSON object candidate.
     *
     * @param string $rawresponse
     * @return string|null
     */
    private function sanitize_json_payload(string $rawresponse): ?string {
        $candidate = trim($rawresponse);
        if ($candidate === '') {
            $this->lastparseissuecode = 'CONTRACT_PARSE_ERROR';
            $this->lastparseinputexcerpt = '';
            return null;
        }

        // Remove optional UTF-8 BOM.
        if (strpos($candidate, "\xEF\xBB\xBF") === 0) {
            $candidate = substr($candidate, 3);
            $candidate = trim($candidate);
        }

        if (preg_match('/^\x60\x60\x60(?:json)?\s*([\s\S]*?)\s*\x60\x60\x60$/i', $candidate, $matches) === 1) {
            $candidate = trim((string)($matches[1] ?? ''));
        }

        // Tags are stripped only from a candidate that is not already a JSON object. F76 (baseline run 31,
        // threads 9318/9322): strip_tags() over a complete answer whose message listed placeholders ("<chat>,
        // <due_date>") and operators ("user value <= now + N days") ate everything from the first "<" on and
        // a valid reply died as CONTRACT_PARSE_ERROR. A "<" inside a JSON string is content, not markup.
        if ($candidate === '' || $candidate[0] !== '{' || substr($candidate, -1) !== '}') {
            $candidate = trim(strip_tags($candidate));
        }

        if ($candidate === '' || $candidate[0] !== '{' || substr($candidate, -1) !== '}') {
            $this->lastparseissuecode = 'CONTRACT_PARSE_ERROR';
            $this->lastparseinputexcerpt = $this->truncate_parse_excerpt($candidate);
            return null;
        }

        return $candidate;
    }

    /**
     * Build safe parse excerpt for diagnostics.
     *
     * @param string $value
     * @return string
     */
    private function truncate_parse_excerpt(string $value): string {
        $value = trim(str_replace(["\r", "\n", "\t"], ' ', $value));
        if ($value === '') {
            return '';
        }

        if (strlen($value) > 200) {
            $value = substr($value, 0, 200);
        }

        return $value;
    }

    /**
     * Validate all commands using structural (pure) checks only.
     *
     * This method MUST NOT:
     *  - call skill->check_structure()
     *  - perform any DB lookups
     *  - resolve entity IDs
     *
     * Deep validation (DB lookups, entity resolution, conflict detection) is
     * delegated to agent_decision_service via skill->preflight().
     *
     * Returns [validated, errors, ambiguities, ambiguityoptions, attemptedskills, issuecodes, confirmablecommands].
     *
     * @param array $commands
     * @param int $contextid
     * @param int $userid
     * @param string $lastusermessage
     * @return array
     */
    private function validate_commands(array $commands, int $contextid, int $userid, string $lastusermessage = ''): array {
        $validated = [];
        $errors = [];
        $repairhints = [];
        $ambiguities = [];
        $ambiguityoptions = [];
        $attemptedskills = [];
        $issuecodes = [];
        $confirmablecommands = [];
        $commandnumber = 0;

        $evaluator = new skill_executability_evaluator($this->registry, new authorization_service());
        $allowedskills = $this->registry->get_skill_names_for_context($evaluator, $userid, $contextid);
        $selector = new skill_selector(new lazy_skill_loader($this->registry));
        $constructor = new parameter_constructor($this->registry);
        $validator = new parameter_contract_validator();
        $seencommandsigs = [];

        foreach ($commands as $cmd) {
            $commandnumber++;
            $label = 'Command #' . $commandnumber;

            $rawinput = $this->extract_command_input((array)$cmd);

            // Deduplicate: skip exact duplicate commands (same skill + same input).
            $cmdsig = md5(json_encode(['skill' => $cmd['skill'] ?? '', 'input' => $rawinput]));
            if (isset($seencommandsigs[$cmdsig])) {
                continue;
            }
            $seencommandsigs[$cmdsig] = true;

            $selection = $selector->select((array)$cmd, $allowedskills, $label);
            if ($selection->skillname !== '') {
                $attemptedskills[] = $selection->skillname;
            }
            if (!$selection->valid || $selection->skill === null) {
                if ($selection->skillname !== '') {
                    $evaluation = $evaluator->evaluate_skill($selection->skillname, $userid, $contextid);
                    $denyreason = (string)($evaluation['deny_reason'] ?? skill_contract_validator::DENY_NOT_REGISTERED);
                    $denymessage = skill_contract_validator::get_user_facing_deny_message($denyreason, $selection->skillname);
                    $errors[] = $denymessage !== null
                        ? "$label: " . $denymessage
                        : "$label: skill '" . $selection->skillname . "' denied by governance gate (" . $denyreason . ").";
                    if ($denyreason === skill_contract_validator::DENY_NOT_REGISTERED) {
                        // F62 (Lauf 8, thread 1537): a skill name that is not registered at all is a
                        // planner slip (e.g. a constructor inventing "mod_booking.search_users"), not an
                        // availability denial. Tag it for the one framework re-plan with a repair hint;
                        // agent_runtime restores the neutral SKILL_DENIED framing if it stays unhealed.
                        $issuecodes[] = self::ISSUE_SKILL_NOT_REGISTERED;
                        $repairhints[] = "$label: skill '" . $selection->skillname . "' is not registered; "
                            . 'use only a skill name from the catalog (keep the selected skill).';
                    } else {
                        $issuecodes[] = ($denyreason === skill_contract_validator::DENY_REQUIRES_PRO)
                            ? 'REQUIRES_PRO'
                            : 'SKILL_DENIED';
                    }
                } else {
                    foreach ($selection->errors as $error) {
                        $errors[] = $error;
                    }
                    $issuecodes[] = 'SKILL_DENIED';
                }
                continue;
            }

            if (!is_array($rawinput)) {
                $errors[] = "$label: 'input' must be an object/array.";
                continue;
            }

            $constructed = $constructor->build($selection->skillname, $rawinput, $lastusermessage);
            $structural = $validator->validate($selection->skill, $constructed->input, $label);
            if (!$structural->valid) {
                foreach ($structural->errors as $error) {
                    $errors[] = $error;
                }
                foreach ($structural->repair as $hint) {
                    $repairhints[] = $hint;
                }
                foreach ($structural->issuecodes as $issuecode) {
                    $issuecodes[] = $issuecode;
                }
                // W2 baseline 2026-07-12: in 5 of 7 measured wrong-key constructions NO in-turn
                // retry fired — the structural reject went terminal although the skill's repair
                // text names the canonical key. Tag the retryable class so the loop grants ONE
                // construction retry (LOOP_RETRYABLE_ISSUE_CODES). RECOVERABLE_INPUT_ERROR is
                // the deliberate exception: F3-migrated skills flag genuinely MISSING user input
                // there — that must surface as a clarification turn, never burn a blind retry.
                if (!in_array('RECOVERABLE_INPUT_ERROR', $structural->issuecodes, true)) {
                    $issuecodes[] = 'CONTRACT_STRUCTURAL_MISMATCH';
                }
                continue;
            }

            // Stage 7: Deduplicate identical commands (same skill + input) and emit.
            $commandsig = $selection->skillname . '|' . json_encode($structural->input, JSON_UNESCAPED_UNICODE);
            if (isset($seencommandsigs[$commandsig])) {
                continue;
            }
            $seencommandsigs[$commandsig] = true;

            $validated[] = [
                'skill'   => $selection->skillname,
                'version' => $selection->version,
                'input'   => $structural->input,
                '_structural_validated' => true,
            ];
        }

        return [
            $validated,
            $errors,
            $ambiguities,
            $ambiguityoptions,
            array_values(array_unique($attemptedskills)),
            array_values(array_unique($issuecodes)),
            $confirmablecommands,
            array_values(array_unique($repairhints)),
        ];
    }

    /**
     * Build a user-facing error text from validation errors.
     *
     * @param array $errors
     * @param string $lang
     * @return string
     */
    private function user_facing_validation_message(array $errors, string $lang = ''): string {
        $clean = array_map(fn(string $line): string => $this->strip_command_prefix($line), $errors);
        return implode(' ', $clean);
    }

    /**
     * Remove technical prefixes like "Command #1:" from user-facing texts.
     *
     * @param string $text
     * @return string
     */
    private function strip_command_prefix(string $text): string {
        $clean = preg_replace('/^\s*Command\s*#\d+\s*:\s*/i', '', $text);
        return $this->safe_string($clean ?? $text);
    }

    /**
     * Normalize a phase label for downstream planner composition.
     *
     * @param string $phase
     * @return string
     */
    private function normalize_phase_name(string $phase): string {
        $normalized = strtolower(trim($phase));
        if ($normalized === 'selection') {
            return 'selection';
        }
        if ($normalized === 'parameter_construction') {
            return 'parameter_construction';
        }
        return 'discovery';
    }
}
