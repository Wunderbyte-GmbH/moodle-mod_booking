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
use bookingextension_agent\local\wizard\services\reportbuilder\filter_value_codec;
use bookingextension_agent\local\wizard\services\reportbuilder\report_definition_service;
use bookingextension_agent\local\wizard\services\reportbuilder\report_preview_renderer;
use core_reportbuilder\local\helpers\report as report_helper;
use core_reportbuilder\local\helpers\user_filter_manager;
use core_reportbuilder\local\models\report;
use core_reportbuilder\manager;

/**
 * Skill report.query_report: how many rows a custom report returns, optionally with filter values.
 *
 * The observation carries the row count, the column headers and the filters applied - never the
 * rows. The rows are shown in the side panel only (the live report view), so personal data in a
 * report never crosses the LLM boundary (decision D9, George 2026-09-23). Filter values are set
 * the way the report view sets them: as the acting user's own filter values of that report, so the
 * panel and the count agree; a filters list that is given replaces the user's current values, an
 * absent list leaves them as they are.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class query_report_skill extends report_skill_base implements skill_trigger_provider_interface {
    /** Skill name. */
    public const SKILL_NAME = 'report.query_report';

    /** Issue code: a filter identifier the report does not offer. */
    public const CODE_FILTER = 'REPORT_QUERY_FILTER_VALIDATION_ERROR';

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
            'description' => 'Count the rows an existing custom report of the Moodle Report Builder currently returns, '
                . 'optionally with filter values applied, and show the report itself in the side panel. Returns the '
                . 'count, the column headers and the filters used; the rows stay in the panel. Read-only.',
            'is' => 'The number of rows a report yields, and the report shown live.',
            'not' => 'Its configuration (get_report_details); finding it (search_reports).',
            'readonly' => true,
            'fallback_skillcall_string_key' => 'ai_status_skillcall_report_query_report',
            'example_utterances' => [
                'how many rows does the completion report have',
                'how many people are in the radiation protection report',
                'run the users report filtered to suspended accounts',
                'show me the enrolment report for this month',
                'how many entries does report 12 return right now',
                'open the booking answers report in the panel',
            ],
            'properties' => [
                'reportid' => [
                    'type' => 'integer',
                    'description' => 'Numeric id of the report when already known. Never guess.',
                    'required' => false,
                ],
                'reportquery' => [
                    'type' => 'string',
                    'description' => 'The report name exactly as the user wrote it, when no id is known.',
                    'required' => false,
                ],
                'filters' => [
                    'type' => 'array',
                    'description' => 'Filter values as objects {identifier, operator, value?, value_to?, unit?, values?} for '
                        . 'the filters the report offers (see its details); operator is the operator key of the filter '
                        . '(e.g. IS_EQUAL_TO, DATE_LAST). When given, replaces the current filter values; an empty list '
                        . 'clears them. Leave out to count with the current values.',
                    'required' => false,
                ],
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'Optional language code override for the user-facing summary, e.g. de or en.',
                    'required' => false,
                ],
            ],
            'prompt_meta' => [
                'input_fields_for_prompt' => ['reportquery', 'reportid'],
                'anchor_fields' => ['reportquery'],
                'context_scopes' => ['system'],
                'required_groups' => [['reportid', 'reportquery']],
            ],
        ];
    }

    /**
     * Example input.
     *
     * @return array
     */
    public function get_example_input(): array {
        return ['reportquery' => 'Course completions'];
    }

    /**
     * WHEN line of the card.
     *
     * @return array[]
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'report.query_report_request',
                'description' => 'User asks how many rows or people an existing report yields, wants it run with certain '
                    . 'filter values, or wants to look at its data.',
            ],
        ];
    }

    /**
     * Nothing to ground beyond the user's own words.
     *
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function get_dynamic_construction_hints(int $contextid, int $userid): array {
        return [];
    }

    /**
     * Shape: an id or a name; filters a list.
     *
     * @param array $input
     * @return array{valid:bool,errors:string[],ambiguities:string[]}
     */
    public function check_structure(array $input): array {
        $errors = [];
        if ((int)($input['reportid'] ?? 0) <= 0 && trim((string)($input['reportquery'] ?? '')) === '') {
            $errors[] = get_string('agent_report_report_missing', 'bookingextension_agent');
        }
        if (array_key_exists('filters', $input) && $input['filters'] !== null && !is_array($input['filters'])) {
            $errors[] = get_string('agent_report_clarify_list_shape', 'bookingextension_agent', 'filters');
        }
        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => []];
    }

    /**
     * Preflight (MCP channel): access, report, filter validation.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    protected function run_preflight(array $input, int $contextid, int $userid): array {
        $lang = $this->get_output_language($input);
        if (!$this->can_use_report_builder($userid)) {
            return $this->permission_denied_preflight($lang);
        }
        $resolved = $this->resolve_report_target($input, $userid);
        if ($resolved['report'] === null) {
            return $this->report_target_clarification($resolved, $userid, $lang);
        }
        $prepared = $input;
        $prepared['reportid'] = (int)$resolved['report']->get('id');
        if (array_key_exists('filters', $input) && is_array($input['filters'])) {
            $encoded = $this->encode_filters($resolved['report'], (array)$input['filters'], $userid);
            if ($encoded['problem'] !== null) {
                return $this->definition_problem_clarification($encoded['problem'], $lang);
            }
            $prepared['resolved_filters'] = $encoded['values'];
            $prepared['applied'] = $encoded['applied'];
        }
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

        if (!$this->can_use_report_builder($userid)) {
            return $this->permission_denied_result($lang, $debugbase . "\nDenied: no report builder access");
        }
        $resolved = $this->resolve_report_target($input, $userid);
        if ($resolved['report'] === null) {
            return $this->report_target_error($resolved, $userid, $lang, $debugbase . "\nUnresolved: " . $resolved['reason']);
        }
        /** @var report $persistent */
        $persistent = $resolved['report'];
        $reportid = (int)$persistent->get('id');

        $applied = null;
        if (array_key_exists('filters', $input) && is_array($input['filters'])) {
            if (array_key_exists('resolved_filters', $input) && is_array($input['resolved_filters'])) {
                $values = (array)$input['resolved_filters'];
                $applied = (array)($input['applied'] ?? []);
            } else {
                // Chat read-only path: no preflight, so the same validation runs here.
                $encoded = $this->encode_filters($persistent, (array)$input['filters'], $userid);
                if ($encoded['problem'] !== null) {
                    $problem = $encoded['problem'];
                    $message = $this->localized_string('agent_report_clarify_filter', (string)($problem['value'] ?? ''), $lang);
                    $lines = [$message];
                    foreach ((array)($problem['options'] ?? []) as $option) {
                        $lines[] = '- ' . $option['label'] . ' [' . $option['id'] . ']';
                    }
                    return [
                        'status' => 'error',
                        'detail' => $message,
                        'usermessage' => $message,
                        'resultid' => null,
                        'observation_full' => implode("\n", $lines),
                        'debugmessage' => $debugbase . "\nInvalid filter: " . json_encode($problem),
                    ];
                }
                $values = $encoded['values'];
                $applied = $encoded['applied'];
            }
            // Set exactly these values for the acting user, as the report view would.
            user_filter_manager::set($reportid, $values, $userid);
        }

        manager::reset_caches();
        $rowcount = report_helper::get_report_row_count($reportid);
        $snapshot = (new report_definition_service($this->resolver(), $this->catalog()))->snapshot($persistent, $userid, false);
        $snapshot['rowcount'] = $rowcount;
        $currentfilters = $applied ?? $this->describe_current_filters($persistent, $userid);

        $usermessage = $this->localized_string('agent_report_query_result', (object)[
            'name' => $snapshot['name'],
            'rows' => $rowcount,
            'url' => $snapshot['url'],
        ], $lang);

        $headers = array_map(
            static fn(array $c): string => $c['heading'] !== '' ? $c['heading'] : $c['title'],
            $snapshot['columns']
        );
        $lines = [$usermessage . ' [id ' . $reportid . ']'];
        $lines[] = 'rows: ' . $rowcount;
        $lines[] = 'columns: ' . implode(' | ', $headers);
        $lines[] = 'filters applied: ' . (empty($currentfilters) ? 'none' : implode('; ', array_map(
            static fn(array $f): string => $f['identifier'] . ' ' . ($f['operator_key'] ?? '') . ' ' . ($f['value'] ?? ''),
            $currentfilters
        )));
        $lines[] = 'The rows themselves are shown in the side panel, not in this observation.';

        return [
            'status' => 'executed',
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'resultid' => $reportid,
            'report' => $snapshot,
            'rowcount' => $rowcount,
            'headers' => $headers,
            'filters_applied' => $currentfilters,
            'observation_full' => implode("\n", $lines),
            'debugmessage' => $debugbase . "\nReport: " . $reportid . "\nRows: " . $rowcount,
        ];
    }

    /**
     * Live view of the report with the acting user's filter values (the rows live here only).
     *
     * @param array $resultentry
     * @param int $contextid
     * @param int $userid
     * @return array|null
     */
    public function get_result_preview(array $resultentry, int $contextid, int $userid): ?array {
        $snapshot = (array)($resultentry['report'] ?? []);
        $reportid = (int)($snapshot['id'] ?? 0);
        if ($reportid <= 0) {
            return null;
        }
        $persistent = report::get_record(['id' => $reportid]);
        if (!$persistent) {
            return null;
        }
        return (new report_preview_renderer())->build($persistent, $userid, $snapshot);
    }

    /**
     * Validate and encode filter specs against the filters the report offers.
     *
     * @param report $persistent
     * @param array $specs
     * @param int $userid
     * @return array{values: array, applied: array[], problem: array|null}
     */
    private function encode_filters(report $persistent, array $specs, int $userid): array {
        manager::reset_caches();
        $instance = manager::get_report_from_persistent($persistent);
        $offered = [];
        foreach ($instance->get_active_filters() as $filter) {
            $offered[$filter->get_unique_identifier()] = $filter;
        }
        $codec = new filter_value_codec($this->catalog());
        $values = [];
        $applied = [];
        foreach ($specs as $spec) {
            if (is_string($spec)) {
                $spec = ['identifier' => $spec];
            }
            if (!is_array($spec)) {
                continue;
            }
            $identifier = trim((string)($spec['identifier'] ?? ''));
            if ($identifier === '' || !isset($offered[$identifier])) {
                return ['values' => [], 'applied' => [], 'problem' => [
                    'kind' => report_definition_service::PROBLEM_UNKNOWN_FILTER,
                    'value' => $identifier,
                    'options' => array_map(static fn($f): array => [
                        'id' => $f->get_unique_identifier(), 'label' => $f->get_header(),
                    ], array_values($offered)),
                ]];
            }
            $encoded = $codec->encode($offered[$identifier], $spec, $userid);
            if ($encoded['error'] !== '') {
                return ['values' => [], 'applied' => [], 'problem' => [
                    'kind' => report_definition_service::PROBLEM_CONDITION_VALUE,
                    'value' => (string)($spec['operator'] ?? ''),
                    'identifier' => $identifier,
                    'detail' => $encoded['error'],
                    'options' => array_map(static fn(string $k): array => ['id' => $k, 'label' => $k], $encoded['allowed']),
                ]];
            }
            $values = array_merge($values, $encoded['values']);
            $applied[] = [
                'identifier' => $identifier,
                'operator_key' => strtoupper((string)($spec['operator'] ?? '')),
                'value' => is_scalar($spec['value'] ?? null) ? (string)$spec['value'] : '',
            ];
        }
        return ['values' => $values, 'applied' => $applied, 'problem' => null];
    }

    /**
     * The acting user's current filter values of a report, decoded to identifier/operator/value.
     *
     * @param report $persistent
     * @param int $userid
     * @return array[]
     */
    private function describe_current_filters(report $persistent, int $userid): array {
        $values = user_filter_manager::get((int)$persistent->get('id'), $userid);
        if (empty($values)) {
            return [];
        }
        manager::reset_caches();
        $instance = manager::get_report_from_persistent($persistent);
        $described = [];
        foreach ($instance->get_active_filters() as $filter) {
            $identifier = $filter->get_unique_identifier();
            $operator = $values[$identifier . '_operator'] ?? null;
            if ($operator === null) {
                continue;
            }
            $value = $values[$identifier . '_value'] ?? ($values[$identifier . '_value1'] ?? '');
            $described[] = [
                'identifier' => $identifier,
                'operator_key' => $this->catalog()->operator_name($filter->get_filter_class(), (int)$operator),
                'value' => is_scalar($value) ? (string)$value : json_encode($value),
            ];
        }
        return $described;
    }
}
