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

namespace bookingextension_agent\local\wizard\report\skills;

use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\interfaces\skill_trigger_provider_interface;
use bookingextension_agent\local\wizard\services\reportbuilder\report_cards_renderer;

/**
 * Skill report.list_report_sources: which data sets the Report Builder can report on here.
 *
 * Everything comes from core_reportbuilder's component discovery at run time: a plugin's
 * datasources appear when the plugin is installed and vanish with it. The observation carries the
 * exact source identifier of every source, which is what the create/describe skills take as input.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class list_report_sources_skill extends report_skill_base implements skill_trigger_provider_interface {
    /** Skill name. */
    public const SKILL_NAME = 'report.list_report_sources';

    /** Default cap on listed sources. */
    private const DEFAULT_LIMIT = 50;

    /** Hard cap. */
    private const MAX_LIMIT = 100;

    /**
     * Constructor: read-only (R0).
     */
    public function __construct() {
        parent::__construct(true, skill_risk_class::R0);
    }

    /**
     * Skill name.
     *
     * @return string
     */
    public function get_name(): string {
        return self::SKILL_NAME;
    }

    /**
     * Schema.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'List the report sources the Moodle Report Builder offers on this site: for each source its exact '
                . 'identifier, localised name, the plugin that ships it, the entities it joins and how many columns, filters '
                . 'and conditions it has. Read-only; nothing is created.',
            'is' => 'Which data sets exist for reporting, and from which plugin.',
            'not' => 'The columns of one source (describe_report_source); existing reports (search_reports); building one '
                . '(create_report).',
            'readonly' => true,
            'fallback_skillcall_string_key' => 'ai_status_skillcall_report_list_report_sources',
            'example_utterances' => [
                'which report sources are available in the report builder',
                'what can I build reports on here',
                'list the data sources for custom reports',
                'which plugins provide report builder data sources',
                'show me the report sources of the booking plugin',
                'what report datasources does this site have',
            ],
            'properties' => [
                'component' => [
                    'type' => 'string',
                    'description' => 'Optional plugin filter as a Moodle component name (e.g. mod_booking, core_user). '
                        . 'Leave empty to list the sources of every plugin.',
                    'required' => false,
                ],
                'include_entities' => [
                    'type' => 'boolean',
                    'description' => 'Whether to include the entities and element counts of each source (default false; '
                        . 'slower, best combined with a component filter).',
                    'required' => false,
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of sources to return (default 50, max 100).',
                    'required' => false,
                ],
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'Optional language code override for the user-facing summary, e.g. de or en.',
                    'required' => false,
                ],
            ],
            'prompt_meta' => [
                'input_fields_for_prompt' => ['component'],
                'anchor_fields' => [],
                'context_scopes' => ['system'],
            ],
        ];
    }

    /**
     * Example input.
     *
     * @return array
     */
    public function get_example_input(): array {
        return ['component' => '', 'include_entities' => false];
    }

    /**
     * WHEN line of the card: the situation, in one sentence.
     *
     * @return array[]
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'report.list_report_sources_request',
                'description' => 'User asks which report sources, data sets or plugins the Report Builder can report on, '
                    . 'typically before building a report.',
            ],
        ];
    }

    /**
     * No source field here: the list itself is the grounding.
     *
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function get_dynamic_construction_hints(int $contextid, int $userid): array {
        return [];
    }

    /**
     * Empty input is fine: it lists everything.
     *
     * @param array $input
     * @return array{valid:bool,errors:string[],ambiguities:string[]}
     */
    public function check_structure(array $input): array {
        return ['valid' => true, 'errors' => [], 'ambiguities' => []];
    }

    /**
     * Preflight (MCP channel): access only.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    protected function run_preflight(array $input, int $contextid, int $userid): array {
        if (!$this->can_author_reports($userid)) {
            return $this->permission_denied_preflight($this->get_output_language($input));
        }
        return $this->pass($input);
    }

    /**
     * Execute.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        $lang = $this->get_output_language($input);
        $debugbase = $this->build_skill_debug_message(self::SKILL_NAME, $input);

        if (!$this->can_author_reports($userid)) {
            return $this->permission_denied_result($lang, $debugbase . "\nDenied: no report builder access");
        }

        $component = trim((string)($input['component'] ?? ''));
        $withentities = !empty($input['include_entities']);
        $limit = isset($input['limit']) ? max(1, min(self::MAX_LIMIT, (int)$input['limit'])) : self::DEFAULT_LIMIT;

        $sources = $this->catalog()->list_sources($withentities, $component);
        $total = count($sources);
        $sources = array_slice($sources, 0, $limit);

        if ($total === 0) {
            $usermessage = $this->localized_string('agent_report_sources_none', null, $lang);
            return [
                'status' => 'executed',
                'detail' => $usermessage,
                'usermessage' => $usermessage,
                'resultid' => null,
                'sources' => [],
                'observation_full' => $usermessage,
                'debugmessage' => $debugbase . "\nResults: 0",
            ];
        }

        $usermessage = $this->localized_string('agent_report_sources_listed', $total, $lang);
        $lines = [$usermessage];
        foreach ($sources as $source) {
            $line = '- ' . $source['name'] . ' [source: ' . $source['source'] . ']'
                . ' | plugin: ' . $source['componentname'] . ' (' . $source['component'] . ')';
            if ($withentities) {
                $entities = array_map(static fn(array $e): string => $e['name'], (array)($source['entities'] ?? []));
                $line .= ' | entities: ' . (empty($entities) ? '-' : implode(', ', $entities));
                $counts = (array)($source['counts'] ?? []);
                $line .= ' | columns: ' . (int)($counts['columns'] ?? 0)
                    . ', filters: ' . (int)($counts['filters'] ?? 0)
                    . ', conditions: ' . (int)($counts['conditions'] ?? 0);
                if (array_key_exists('available', $source) && !$source['available']) {
                    $line .= ' | unavailable';
                }
            }
            $lines[] = $line;
        }
        if ($total > count($sources)) {
            $lines[] = $this->localized_string(
                'agent_report_list_partial',
                (object)['shown' => count($sources), 'total' => $total],
                $lang
            );
        }

        return [
            'status' => 'executed',
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'resultid' => null,
            'sources' => $sources,
            'observation_full' => implode("\n", $lines),
            'debugmessage' => $debugbase . "\nResults: " . count($sources) . "\nTotal: " . $total,
        ];
    }

    /**
     * Side-panel cards of the listed sources.
     *
     * @param array $resultentry
     * @param int $contextid
     * @param int $userid
     * @return array|null
     */
    public function get_result_preview(array $resultentry, int $contextid, int $userid): ?array {
        $sources = (array)($resultentry['sources'] ?? []);
        if (empty($sources)) {
            return null;
        }
        $html = (new report_cards_renderer())->render_sources($sources);
        if ($html === '') {
            return null;
        }
        return [
            'type' => self::PREVIEW_TYPE_SOURCES,
            'html' => $html,
            'replace' => true,
            'payload' => ['sources' => array_column($sources, 'source')],
        ];
    }
}
