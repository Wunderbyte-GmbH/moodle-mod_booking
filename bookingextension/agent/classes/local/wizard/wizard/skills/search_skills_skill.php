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
use bookingextension_agent\local\wizard\interfaces\skill_discovery_provider_interface;
use bookingextension_agent\local\wizard\interfaces\skill_trigger_provider_interface;
use bookingextension_agent\local\wizard\services\discovery\skill_discovery_service;

/**
 * Skill definition for wizard.search_skills (Dynamic Skill Discovery).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_skills_skill extends core_skill_base implements skill_trigger_provider_interface {
    /** Skill name constant. */
    public const SKILL_NAME = 'wizard.search_skills';

    /** @var skill_discovery_provider_interface|null Engine-injected discovery provider. */
    private ?skill_discovery_provider_interface $discovery = null;

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(true, skill_risk_class::R0);
    }

    /**
     * Inject the engine discovery provider (duck-typed; called by the executor before execute).
     *
     * @param skill_discovery_provider_interface $provider
     * @return void
     */
    public function set_discovery_provider(skill_discovery_provider_interface $provider): void {
        $this->discovery = $provider;
    }

    /**
     * Map a discovery status code to the skill's user-facing failure message.
     *
     * @param string $status
     * @return string
     */
    private function discovery_failure_message(string $status): string {
        return match ($status) {
            skill_discovery_provider_interface::STATUS_EMBEDDINGS_UNAVAILABLE =>
                'Skill discovery is unavailable because embeddings are disabled.',
            skill_discovery_provider_interface::STATUS_CATALOG_NOT_READY =>
                'Skill catalog embeddings are not ready.',
            skill_discovery_provider_interface::STATUS_EMBEDDING_FAILED =>
                'Failed to generate embedding for the query.',
            default => 'Skill discovery failed.',
        };
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
            // The "last resort" restriction must stay inside the selector's 240-character window: outside it the
            // selector used this skill for "what can you do" (LS-3, wave 7 Nachlauf 2026-09-17).
            'description' => 'LAST RESORT only: find one hidden capability by a concrete query (e.g. certificates, export) when no '
                . 'listed skill fits. Use it ONLY when none of the other listed skills can fulfil the request — the user wants an '
                . 'action or capability that no available skill represents (downloading or issuing a certificate, exporting or '
                . 'importing data, a feature with no matching skill in the list). Pass a descriptive query of the wanted '
                . 'capability; it searches the full tool registry for skills not currently shown. Always prefer a concrete '
                . 'matching skill when one exists.',
            'is' => 'One hidden capability, by query.',
            'not' => '"What can you do" — the full menu (list_skills); documentation (explain_docs); storing a fact '
                . '(remember).',
            'readonly' => true,
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'A short, descriptive search term or user intent to find the ' .
                        'right tool (e.g. "download certificate" or "delete user").',
                    'required' => true,
                ],
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
            'query' => 'download certificate',
        ];
    }

    /**
     * Opt into deterministic construction passthrough (skill-agnostic engine contract).
     *
     * search_skills is the engine fallback: it needs only a free-text query (the wanted capability),
     * never DB-grounded parameters. Declaring the passthrough field tells the engine to build that one
     * field from the user intent and SKIP the construction LLM — which otherwise occasionally rejects
     * this meta-skill and dead-ends in a clarification, defeating the fallback (thread 531).
     *
     * @return string the input field that receives the query
     */
    public function get_passthrough_construction_field(): string {
        return 'query';
    }

    /**
     * Return skill-specific message triggers.
     *
     * @return array[]
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'wizard.search_skills_request',
                'description' => 'User asks to perform an action but the necessary tool is not immediately visible.',
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
        $query = trim((string)($input['query'] ?? ''));
        if ($query === '') {
            // Wave 32 (A3): user-facing text via get_string (was a hard-coded English literal).
            $errors[] = get_string('agent_search_skills_query_required', 'bookingextension_agent');
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
        $query = trim((string)($input['query'] ?? ''));
        if ($query === '') {
            return [
                'status' => 'failed',
                'message' => 'Empty search query.',
                'discovered_skills' => [],
                'observation_full' => 'No search query was provided to wizard.search_skills. Re-run this skill '
                    . 'with a concrete "query" describing the capability the user needs (use the user\'s own '
                    . 'request). Do NOT conclude from this that the capability is unavailable.',
            ];
        }

        // Discovery (embeddings + LLM retrieval) is engine machinery — it is injected by the executor
        // as a contract; this skill only maps its status to a user-facing message and formats output.
        // Cast a WIDER net than the planner's own discovery top-k (which already missed the skill):
        // this is the fallback, so it searches deep into the registry on the re-formulated query.
        $discovery = ($this->discovery ?? new skill_discovery_service())->discover($query, $contextid, $userid, 25);
        if ($discovery['status'] !== skill_discovery_provider_interface::STATUS_OK) {
            return [
                'status' => 'failed',
                'message' => $this->discovery_failure_message((string)$discovery['status']),
                'discovered_skills' => [],
            ];
        }
        $discovered = $discovery['discovered_skills'];

        // Surface the discovered skills as an authoritative observation so the next planner turn can
        // select one of them — they are registered/allowed skills even if they were not in the slim
        // catalog shown initially.
        $lines = [];
        foreach ($discovered as $entry) {
            $name = trim((string)($entry['skill'] ?? ''));
            if ($name === '') {
                continue;
            }
            $desc = trim((string)($entry['schema']['description'] ?? ''));
            $lines[] = '- ' . $name . ($desc !== '' ? ': ' . $desc : '');
        }
        $observationfull = empty($lines)
            ? 'Skill search for "' . $query . '" found no matching skills. '
                . 'Tell the user this capability is not available, or ask for clarification.'
            : 'Skill search for "' . $query . '" found these capabilities. You MUST select ONE of them as a '
                . 'skill_call in your next step — they are valid, registered, executable skills. Do NOT tell the '
                . 'user the capability is unavailable, and do NOT ask the user to perform it manually:'
                . "\n" . implode("\n", $lines);

        return [
            'status' => 'executed',
            'message' => 'Successfully discovered relevant skills.',
            'query' => $query,
            'discovered_skills' => $discovered,
            'observation_full' => $observationfull,
            // Not engine-static: the observation repeats the de-anonymized query, so it stays masked. The flag
            // never reached a masking layer before L43 (execution_feedback_service dropped it), so this is the
            // behaviour the skill always had.
        ];
    }
}
