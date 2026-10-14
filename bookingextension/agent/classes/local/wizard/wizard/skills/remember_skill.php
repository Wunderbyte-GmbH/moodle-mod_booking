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

namespace bookingextension_agent\local\wizard\wizard\skills;

use bookingextension_agent\local\wizard\core\skills\core_skill_base;
use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\interfaces\skill_trigger_provider_interface;
use bookingextension_agent\local\wizard\services\user_memory_service;

/**
 * Skill definition for wizard.remember — store a user-stated fact/preference.
 *
 * Distinct from wizard.recall_memory: this stores facts the user explicitly asks
 * the agent to remember, NOT previous conversation turns.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class remember_skill extends core_skill_base implements skill_trigger_provider_interface {
    /** Skill name constant. */
    public const SKILL_NAME = 'wizard.remember';

    /**
     * Constructor — treated like read-only (R0), no confirmation step.
     *
     * Decision (Georg, 2026-06-11): storing a note the user EXPLICITLY asked the
     * agent to remember is a write to the user's own preference store only —
     * a confirmation round-trip ("remember X" → "shall I?" → "yes") is pure
     * friction. The destructive counterpart wizard.forget stays R2 with explicit
     * confirmation, so nothing is lost without a guarded path.
     */
    public function __construct() {
        parent::__construct(true, skill_risk_class::R0);
    }

    /**
     * Return skill name.
     *
     * @return string
     */
    public function get_name(): string {
        return self::SKILL_NAME;
    }

    /**
     * Return skill schema.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            // First 240 characters = selector window (#2423, REM-2 "remember permanently that …").
            'description' => 'Store permanently a fact, preference or standing instruction (memory) the user asks the agent to '
                . 'remember, e.g. "remember that I prefer morning bookings". User isolation is strict; userid is never taken from '
                . 'input.',
            'is' => 'Storing a user-stated fact.',
            'not' => 'Recalling previous conversation (recall_memory); a capability search (search_skills).',
            'readonly' => $this->is_read_only(),
            'fallback_confirm_string_key' => 'agent_memory_remember_confirm',
            'fallback_skillcall_string_key' => 'agent_memory_remember_skillcall',
            'example_utterances' => [
                'remember that I prefer morning bookings',
                'keep in mind that my employee id is 12345',
                'always address me as Dr. Smith',
                'note that I always need room B',
                'save this preference for next time',
                // Wave 32 (A3): a standing default for the things the agent CREATES. Such a request names a
                // creatable object and a setting, so the object skills (create/update course, activity, option)
                // outscored every anchor above and remember fell to rank 10-27 (catalogue = semantic top 12 plus
                // the search fallback; it missed the catalogue in 9 of 12 threads, L30-L43 incl. the Sol control).
                // These anchors carry the intent class "remember my default for what you create", never the
                // wording of a test prompt; none of them names a concrete setting. (Review w32s-a3: a third
                // anchor "... my default for all future requests" was dropped - it echoed the framing of an
                // existing memory prompt and lay outside this intent class.)
                'remember my default setting for everything you create for me from now on',
                'remember my personal defaults for creating courses, activities and booking options',
            ],
            'properties' => [
                'memory' => [
                    'type' => 'string',
                    'description' => 'The fact/preference/instruction to remember as a COMPLETE, self-contained '
                        . 'statement that makes sense without the conversation (e.g. "Address the user as '
                        . '\'Dr. Smith\'", NOT just "Dr. Smith"). Keep it brief and factual '
                        . '(max ' . user_memory_service::MAX_CHARS_PER_MEMORY . ' characters).',
                    'required' => true,
                ],
                'relevant_for' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => [
                        user_memory_service::SCOPE_SELECTION,
                        user_memory_service::SCOPE_CONSTRUCTION,
                        user_memory_service::SCOPE_SYNCHRONIZATION,
                    ]],
                    // The constructor sees 160 characters of this text; the longer decision rule behind them never
                    // reached it, and A/B on Gemma preferred the cut-free first sentence (22/32 -> 27/32, #2582).
                    'description' => 'Which planning stages this memory should influence. Decision rule: does it '
                        . 'change HOW THE AGENT TALKS to the user (form of address, tone, language, formatting)?',
                    'required' => false,
                ],
            ],
            'prompt_meta' => [
                'intent' => 'Persist a user-stated fact/preference/instruction for future planning, tagged by the '
                    . 'stage(s) it is relevant for (selection/construction/synchronization). '
                    . 'Use only when the user explicitly asks the agent to remember something about themselves.',
                'input_fields_for_prompt' => ['memory', 'relevant_for'],
                'anchor_fields' => ['memory'],
                'capabilities' => ['user_memory_store'],
                // Affected scope is the USER's global memory store — not the hosting
                // Moodle context (the skill runs anywhere, incl. the dashboard).
                'context_scopes' => ['user'],
            ],
        ];
    }

    /**
     * Return example input for planner contract rendering.
     *
     * @return array
     */
    public function get_example_input(): array {
        return [
            'memory' => "Always address the user as 'Dr. Smith'.",
            'relevant_for' => [user_memory_service::SCOPE_SYNCHRONIZATION],
        ];
    }

    /**
     * Return skill-specific message triggers.
     *
     * @return array[]
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'wizard.remember_request',
                'description' => 'User asks the agent to remember a fact, preference or standing instruction '
                    . 'about themselves (stored facts, not previous conversation).',
                'examples' => [
                    'remember this: I prefer morning bookings',
                    'remember that my employee id is 12345',
                    'please remember that I always need room B',
                ],
            ],
        ];
    }

    /**
     * Check skill input structure.
     *
     * @param array $input
     * @return array{valid:bool,errors:string[],ambiguities:string[]}
     */
    public function check_structure(array $input): array {
        $errors = [];
        $memory = trim((string)($input['memory'] ?? ''));
        if ($memory === '') {
            $errors[] = get_string('agent_memory_add_empty', 'bookingextension_agent');
        } else if (\core_text::strlen($memory) > user_memory_service::MAX_CHARS_PER_MEMORY) {
            $errors[] = get_string(
                'agent_memory_add_too_long',
                'bookingextension_agent',
                user_memory_service::MAX_CHARS_PER_MEMORY
            );
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'ambiguities' => [],
            'issue_codes' => empty($errors) ? [] : ['RECOVERABLE_INPUT_ERROR'],
        ];
    }

    /**
     * Execute skill.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        $service = new user_memory_service();
        $scopes = [];
        foreach ((array)($input['relevant_for'] ?? []) as $scope) {
            $scopes[] = (string)$scope;
        }
        $result = $service->add($userid, (string)($input['memory'] ?? ''), $scopes);

        return [
            'status' => 'executed',
            'detail' => (string)$result['message'],
            'memory_status' => (string)$result['status'],
            'resultid' => $result['id'],
            'observation_full' => '[USER_MEMORY] remember status=' . (string)$result['status']
                . ' :: ' . (string)$result['message'],
        ];
    }
}
