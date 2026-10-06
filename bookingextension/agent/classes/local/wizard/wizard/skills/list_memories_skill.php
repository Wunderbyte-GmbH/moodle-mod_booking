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
 * Skill definition for wizard.list_memories — list stored user-stated facts.
 *
 * Distinct from wizard.recall_memory: lists facts the user explicitly asked the
 * agent to remember, NOT previous conversation turns.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class list_memories_skill extends core_skill_base implements skill_trigger_provider_interface {
    /** Skill name constant. */
    public const SKILL_NAME = 'wizard.list_memories';

    /**
     * Constructor — read-only.
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
        $schema = [
            'version' => 1,
            'description' => 'List the stored facts/preferences the user previously asked the agent to remember (e.g. "what do you '
                . 'know about me?"). User isolation is strict; userid is never taken from input.',
            'is' => 'Stored user-stated facts.',
            'not' => 'Previous conversation (recall_memory).',
            'readonly' => $this->is_read_only(),
            'fallback_skillcall_string_key' => 'agent_memory_list_skillcall',
            'example_utterances' => [
                'what do you know about me',
                'what have you remembered about me',
                'list everything you have stored about me',
                'show me my saved preferences',
                'what facts have I asked you to keep',
            ],
            'properties' => [],
            'prompt_meta' => [
                'intent' => 'List the facts/preferences the user previously asked the agent to remember.',
                'input_fields_for_prompt' => [],
                'anchor_fields' => [],
                'capabilities' => ['user_memory_list'],
                // Affected scope is the USER's global memory store, not the hosting context.
                'context_scopes' => ['user'],
            ],
        ];

        return $schema;
    }

    /**
     * Return skill-specific message triggers.
     *
     * @return array[]
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'wizard.list_memories_request',
                'description' => 'User asks what stored facts/preferences the agent has about them.',
                'examples' => [
                    'what do you know about me?',
                    'show my saved preferences',
                    'what have you remembered about me?',
                ],
            ],
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
        $records = $service->get_all($userid);

        if (empty($records)) {
            return [
                'status' => 'executed',
                'detail' => get_string('agent_memory_list_empty', 'bookingextension_agent'),
                'memories' => [],
                'observation_full' => '[USER_MEMORY] none stored.',
            ];
        }

        $memories = [];
        $lines = ['[USER_MEMORY] ' . count($records) . ' stored fact(s):'];
        $index = 1;
        foreach ($records as $record) {
            $id = (int)$record->id;
            $text = (string)$record->memory;
            $scopes = user_memory_service::parse_scopes($record->scopes ?? null);
            $scopelabel = empty($scopes) ? 'all' : implode(',', $scopes);
            $memories[] = ['id' => $id, 'memory' => $text, 'relevant_for' => $scopes];
            $lines[] = $index . '. (id=' . $id . ', relevant_for=' . $scopelabel . ') ' . $text;
            $index++;
        }

        return [
            'status' => 'executed',
            'detail' => get_string('agent_memory_list_summary', 'bookingextension_agent', count($records)),
            'memories' => $memories,
            'observation_full' => implode("\n", $lines),
        ];
    }
}
