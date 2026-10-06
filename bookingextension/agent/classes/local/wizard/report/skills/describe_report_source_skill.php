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
use bookingextension_agent\local\wizard\services\reportbuilder\report_source_catalog_service;

/**
 * Skill report.describe_report_source: the building blocks of one Report Builder source.
 *
 * Returns every column, filter and condition of a datasource with its exact unique identifier
 * (entity:name), entity, type, compatible aggregations and — for filters and conditions — the
 * operator enum of its filter class. These identifiers and enum keys are what the report
 * authoring skills accept, so a planner reads this first when the user names specific columns.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class describe_report_source_skill extends report_skill_base implements skill_trigger_provider_interface {
    /** Skill name. */
    public const SKILL_NAME = 'report.describe_report_source';

    /** Preview type of the detail card. */
    public const PREVIEW_TYPE_DETAIL = 'report_source_detail';

    /** Cap on lines per section in the observation (a source can have well over 100 columns). */
    private const SECTION_LINE_CAP = 120;

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
            'description' => 'Describe one report source of the Moodle Report Builder: every column, filter and condition with its '
                . 'exact identifier, entity, type, allowed aggregations and filter operators, plus the defaults a new report '
                . 'starts with. Read-only.',
            'is' => 'The columns, filters and conditions one source offers.',
            'not' => 'All sources (list_report_sources); an existing report (get_report_details); building one '
                . '(create_report).',
            'readonly' => true,
            'fallback_skillcall_string_key' => 'ai_status_skillcall_report_describe_report_source',
            'example_utterances' => [
                'which columns does the users report source have',
                'what can I filter on in the booking answers source',
                'show me the fields available for a course completion report',
                'list the conditions and operators of the report source',
                'what aggregations are possible for that report column',
                'describe the report builder source for badges',
            ],
            'properties' => [
                'source' => [
                    'type' => 'string',
                    'description' => 'The report source: its exact identifier as listed on this site, or its exact localised name. '
                        . 'The system does not guess; an unknown value is answered with the list of available sources.',
                    'required' => true,
                ],
                'section' => [
                    'type' => 'string',
                    'description' => 'Which part to return: all (default), columns, filters or conditions.',
                    'required' => false,
                ],
                'entity' => [
                    'type' => 'string',
                    'description' => 'Optional entity name (as listed for the source) to restrict the result to one entity.',
                    'required' => false,
                ],
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'Optional language code override for the user-facing summary, e.g. de or en.',
                    'required' => false,
                ],
            ],
            'prompt_meta' => [
                'input_fields_for_prompt' => ['source', 'section'],
                'anchor_fields' => ['source'],
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
        return ['source' => 'core_user\\reportbuilder\\datasource\\users', 'section' => 'all'];
    }

    /**
     * WHEN line of the card.
     *
     * @return array[]
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'report.describe_report_source_request',
                'description' => 'User asks which columns, filters, conditions or operators one report source offers, or names '
                    . 'specific columns for a report that is about to be built.',
            ],
        ];
    }

    /**
     * Shape only: a source must be named, the section must be a known selector.
     *
     * @param array $input
     * @return array{valid:bool,errors:string[],ambiguities:string[]}
     */
    public function check_structure(array $input): array {
        $errors = [];
        if (trim((string)($input['source'] ?? '')) === '') {
            $errors[] = get_string('agent_report_source_missing', 'bookingextension_agent');
        }
        $section = trim((string)($input['section'] ?? ''));
        if ($section !== '' && !in_array($section, report_source_catalog_service::SECTIONS, true)) {
            $errors[] = get_string(
                'agent_report_section_invalid',
                'bookingextension_agent',
                implode(', ', report_source_catalog_service::SECTIONS)
            );
        }
        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => []];
    }

    /**
     * Preflight (MCP channel): access, then source resolution with a candidate list on a miss.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    protected function run_preflight(array $input, int $contextid, int $userid): array {
        $lang = $this->get_output_language($input);
        if (!$this->can_author_reports($userid)) {
            return $this->permission_denied_preflight($lang);
        }
        $structure = $this->check_structure($input);
        $reference = trim((string)($input['source'] ?? ''));
        if (!$structure['valid']) {
            if ($reference === '') {
                return $this->source_clarification($lang, '');
            }
            return parent::run_preflight($input, $contextid, $userid);
        }
        $resolved = $this->catalog()->resolve_source($reference);
        if ($resolved['source'] === null) {
            return $this->source_clarification($lang, $reference);
        }
        $prepared = $input;
        $prepared['source'] = $resolved['source'];
        return $this->pass($prepared);
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

        $reference = trim((string)($input['source'] ?? ''));
        $resolved = $reference === '' ? ['source' => null, 'candidates' => []] : $this->catalog()->resolve_source($reference);
        if ($resolved['source'] === null) {
            // Chat read-only path has no preflight: an unresolved source is an honest error that names
            // the available sources (no codes, no field lists), never a silent fallback.
            $names = array_map(static fn(array $s): string => $s['name'], $this->catalog()->list_sources(false));
            $message = $reference === ''
                ? $this->localized_string('agent_report_source_missing', null, $lang)
                : $this->localized_string('agent_report_source_not_found', $reference, $lang);
            $observation = $message . "\n" . implode("\n", array_map(static fn(string $n): string => '- ' . $n, $names));
            return [
                'status' => 'error',
                'detail' => $message,
                'usermessage' => $message,
                'resultid' => null,
                'observation_full' => $observation,
                'debugmessage' => $debugbase . "\nUnresolved source: " . $reference,
            ];
        }

        $section = trim((string)($input['section'] ?? ''));
        if (!in_array($section, report_source_catalog_service::SECTIONS, true)) {
            $section = report_source_catalog_service::SECTION_ALL;
        }
        $entity = trim((string)($input['entity'] ?? ''));

        $described = $this->catalog()->describe_source($resolved['source'], $section, $entity);

        $usermessage = $this->localized_string('agent_report_source_described', (object)[
            'name' => $described['name'],
            'columns' => count($described['columns']),
            'filters' => count($described['filters']),
            'conditions' => count($described['conditions']),
        ], $lang);

        $lines = [$usermessage . ' [source: ' . $described['source'] . ']'];
        $entities = array_map(static fn(array $e): string => $e['name'] . ' (' . $e['title'] . ')', $described['entities']);
        $lines[] = 'entities: ' . implode(', ', $entities);
        foreach (['columns', 'filters', 'conditions'] as $key) {
            $defaults = (array)$described['defaults'][$key];
            $lines[] = 'default ' . $key . ': ' . (empty($defaults) ? '-' : implode(', ', $defaults));
        }

        foreach (['columns', 'filters', 'conditions'] as $key) {
            if (empty($described[$key])) {
                continue;
            }
            $lines[] = strtoupper($key) . ' (' . count($described[$key]) . '):';
            $shown = 0;
            foreach ($described[$key] as $item) {
                if ($shown >= self::SECTION_LINE_CAP) {
                    $lines[] = $this->localized_string(
                        'agent_report_list_partial',
                        (object)['shown' => $shown, 'total' => count($described[$key])],
                        $lang
                    );
                    break;
                }
                $lines[] = $key === 'columns' ? $this->column_line($item) : $this->filter_line($item);
                $shown++;
            }
        }

        return [
            'status' => 'executed',
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'resultid' => null,
            'source' => $described['source'],
            'sourcename' => $described['name'],
            'component' => $described['component'],
            'entities' => $described['entities'],
            'defaults' => $described['defaults'],
            'columns' => $described['columns'],
            'filters' => $described['filters'],
            'conditions' => $described['conditions'],
            'observation_full' => implode("\n", $lines),
            'debugmessage' => $debugbase . "\nSource: " . $described['source']
                . "\nColumns: " . count($described['columns'])
                . "\nFilters: " . count($described['filters'])
                . "\nConditions: " . count($described['conditions']),
        ];
    }

    /**
     * Side-panel detail of the described source.
     *
     * @param array $resultentry
     * @param int $contextid
     * @param int $userid
     * @return array|null
     */
    public function get_result_preview(array $resultentry, int $contextid, int $userid): ?array {
        if (trim((string)($resultentry['source'] ?? '')) === '') {
            return null;
        }
        $html = (new report_cards_renderer())->render_source_detail([
            'source' => (string)$resultentry['source'],
            'name' => (string)($resultentry['sourcename'] ?? ''),
            'columns' => (array)($resultentry['columns'] ?? []),
            'filters' => (array)($resultentry['filters'] ?? []),
            'conditions' => (array)($resultentry['conditions'] ?? []),
        ]);
        return [
            'type' => self::PREVIEW_TYPE_DETAIL,
            'html' => $html,
            'replace' => true,
            'payload' => ['source' => (string)$resultentry['source']],
        ];
    }

    /**
     * One observation line for a column.
     *
     * @param array $item
     * @return string
     */
    private function column_line(array $item): string {
        $line = '- ' . $item['identifier'] . ' | ' . $item['title'] . ' | entity: ' . $item['entity'] . ' | type: ' . $item['type'];
        if (!empty($item['aggregations'])) {
            $line .= ' | aggregations: ' . implode(', ', $item['aggregations']);
        }
        if (!empty($item['sortable'])) {
            $line .= ' | sortable';
        }
        if (!empty($item['default'])) {
            $line .= ' | default';
        }
        return $line;
    }

    /**
     * One observation line for a filter or condition.
     *
     * @param array $item
     * @return string
     */
    private function filter_line(array $item): string {
        $operators = [];
        foreach ((array)($item['operators'] ?? []) as $operator) {
            $operators[] = $operator['label'] !== '' ? $operator['key'] . ' (' . $operator['label'] . ')' : $operator['key'];
        }
        $line = '- ' . $item['identifier'] . ' | ' . $item['title'] . ' | entity: ' . $item['entity']
            . ' | filter: ' . $item['filterclass'];
        if (!empty($operators)) {
            $line .= ' | operators: ' . implode(', ', $operators);
        }
        if (!empty($item['units'])) {
            $line .= ' | units: ' . implode(', ', array_column((array)$item['units'], 'key'));
        }
        if (!empty($item['default'])) {
            $line .= ' | default';
        }
        return $line;
    }
}
