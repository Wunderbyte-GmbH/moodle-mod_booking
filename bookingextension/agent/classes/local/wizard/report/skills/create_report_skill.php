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
use bookingextension_agent\local\wizard\services\reportbuilder\report_definition_service;
use bookingextension_agent\local\wizard\services\reportbuilder\report_preview_renderer;
use core_reportbuilder\local\models\report;
use core_reportbuilder\local\report\base;
use core_reportbuilder\manager;
use core_reportbuilder\permission;
use moodle_url;

/**
 * Skill report.create_report: create a custom report in the Report Builder.
 *
 * Name and source are required; columns (with heading, aggregation, sorting), conditions with
 * values, filters, unique rows and tags are optional. Without columns the source's defaults are
 * taken. Everything is validated against the datasource in preflight (identifiers, aggregations,
 * operators — with the alternatives as options on a miss), frozen into the prepared input, and
 * written in one transaction; the observation is the stored definition.
 *
 * The report is created without an audience, so until one is set only its creator and users with
 * moodle/reportbuilder:editall or :viewall can see it. That is what lets the follow-up steps of a
 * series (columns, audiences, schedules) preview the real report before anybody else sees it.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_report_skill extends report_skill_base implements skill_trigger_provider_interface {
    /** Skill name. */
    public const SKILL_NAME = 'report.create_report';

    /**
     * Constructor: broad write (R2).
     */
    public function __construct() {
        parent::__construct(false, skill_risk_class::R2);
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
     * Gate 2: creating custom reports (core additionally accepts editall; checked in preflight).
     *
     * @return string[]
     */
    public function get_required_native_capabilities(): array {
        return ['moodle/reportbuilder:edit'];
    }

    /**
     * Schema.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'Create a custom report in the Moodle Report Builder from a report source: name, source and '
                . 'optionally columns (heading, aggregation, sorting), conditions with values, filters, unique rows and '
                . 'tags. Without columns the source defaults are used.',
            'is' => 'A new report.',
            'not' => 'Changing an existing report (update_report); listing sources (list_report_sources) or their '
                . 'columns (describe_report_source).',
            'readonly' => false,
            'fallback_skillcall_string_key' => 'ai_status_skillcall_report_create_report',
            'example_utterances' => [
                'create a report of all users with their email addresses',
                'build a custom report on course completions',
                'make a new report in the report builder for booking answers',
                'I need a report listing every course with its category',
                'set up a report of badges awarded this year',
                'create a report of enrolments grouped by course',
            ],
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'The name of the new report.',
                    'required' => true,
                ],
                'source' => [
                    'type' => 'string',
                    'description' => 'The report source: its exact identifier as available on this site, or its exact '
                        . 'localised name. Never invent one; an unknown value is answered with the available sources.',
                    'required' => true,
                ],
                'use_defaults' => [
                    'type' => 'boolean',
                    'description' => 'Start with the default columns, filters and conditions of the source. Default: true '
                        . 'when no columns are given, false otherwise.',
                    'required' => false,
                ],
                'columns' => [
                    'type' => 'array',
                    'description' => 'Columns as objects {identifier, heading?, aggregation?, sort?, sortorder?}. identifier '
                        . 'is the exact column identifier of the source (entity:name); aggregation one of the column\'s '
                        . 'allowed aggregations (e.g. count, sum, groupconcat); sort asc or desc.',
                    'required' => false,
                ],
                'conditions' => [
                    'type' => 'array',
                    'description' => 'Conditions as objects {identifier, operator, value?, value_to?, unit?, values?}. '
                        . 'identifier is the exact condition identifier of the source; operator is the operator key of '
                        . 'its filter (e.g. IS_EQUAL_TO, CONTAINS, DATE_RANGE, DATE_LAST); dates as ISO 8601 (YYYY-MM-DD).',
                    'required' => false,
                ],
                'filters' => [
                    'type' => 'array',
                    'description' => 'Filters offered to the report readers, as objects {identifier} or plain identifiers.',
                    'required' => false,
                ],
                'uniquerows' => [
                    'type' => 'boolean',
                    'description' => 'Show unique rows only (default false).',
                    'required' => false,
                ],
                'tags' => [
                    'type' => 'array',
                    'description' => 'Optional tag names for the report.',
                    'required' => false,
                ],
                'override' => [
                    'type' => 'array',
                    'description' => 'Confirmation tokens for accepted warnings, e.g. duplicate_name when a report with the '
                        . 'same name and source already exists and the user wants a second one.',
                    'required' => false,
                ],
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'Optional language code override for the user-facing summary, e.g. de or en.',
                    'required' => false,
                ],
            ],
            'prompt_meta' => [
                'input_fields_for_prompt' => ['name', 'source'],
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
        return [
            'name' => 'Active users',
            'source' => 'core_user\\reportbuilder\\datasource\\users',
            'columns' => [
                ['identifier' => 'user:fullname'],
                ['identifier' => 'user:email'],
            ],
            'conditions' => [
                ['identifier' => 'user:suspended', 'operator' => 'NOT_CHECKED'],
            ],
        ];
    }

    /**
     * WHEN line of the card.
     *
     * @return array[]
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'report.create_report_request',
                'description' => 'User wants a new custom report built in the Report Builder on some data set, with or '
                    . 'without naming columns, conditions or filters.',
            ],
        ];
    }

    /**
     * Confirmation card (source B) from the prepared input.
     *
     * @param array $input Prepared input.
     * @return array|null
     */
    public function describe_proposed_action(array $input): ?array {
        $lang = $this->get_output_language($input);
        $resolved = (array)($input['resolved'] ?? []);
        $rows = [
            [
                'label' => $this->localized_string('agent_report_card_name', null, $lang),
                'value' => (string)($input['name'] ?? ''),
            ],
            [
                'label' => $this->localized_string('agent_report_preview_source', null, $lang),
                'value' => (string)($resolved['sourcename'] ?? $input['source'] ?? ''),
            ],
        ];
        if (!empty($resolved['use_defaults'])) {
            $rows[] = [
                'label' => $this->localized_string('agent_report_preview_columns', null, $lang),
                'value' => $this->localized_string('agent_report_card_defaults', null, $lang),
            ];
        }
        $rows = array_merge($rows, $this->definition_rows(
            (array)($resolved['columns'] ?? []),
            (array)($resolved['conditions'] ?? []),
            (array)($resolved['filters'] ?? []),
            $lang
        ));
        if (!empty($input['uniquerows'])) {
            $rows[] = [
                'label' => $this->localized_string('agent_report_card_uniquerows', null, $lang),
                'value' => get_string_manager()->get_string('yes', 'core', null, $lang !== '' ? $lang : current_language()),
            ];
        }
        return [
            'title' => $this->localized_string('agent_report_card_create_title', (string)($input['name'] ?? ''), $lang),
            'summary' => $this->localized_string('agent_report_card_visibility_hint', null, $lang),
            'rows' => $rows,
        ];
    }

    /**
     * Queue identity: one report per name and source per plan.
     *
     * @param array $input
     * @return array
     */
    public function build_queue_business_identity(array $input): array {
        return [
            'task' => self::SKILL_NAME,
            'name' => trim((string)($input['name'] ?? '')),
            'source' => trim((string)($input['source'] ?? '')),
        ];
    }

    /**
     * Shape only.
     *
     * @param array $input
     * @return array{valid:bool,errors:string[],ambiguities:string[]}
     */
    public function check_structure(array $input): array {
        $errors = [];
        if (trim((string)($input['name'] ?? '')) === '') {
            $errors[] = get_string('agent_report_clarify_name', 'bookingextension_agent');
        }
        if (trim((string)($input['source'] ?? '')) === '') {
            $errors[] = get_string('agent_report_source_missing', 'bookingextension_agent');
        }
        foreach (['columns', 'conditions', 'filters', 'tags', 'override'] as $key) {
            if (array_key_exists($key, $input) && $input[$key] !== null && !is_array($input[$key])) {
                $errors[] = get_string('agent_report_clarify_list_shape', 'bookingextension_agent', $key);
            }
        }
        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => []];
    }

    /**
     * Resolve, validate and freeze the definition.
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
        if (!permission::can_create_report($userid) && manager::report_limit_reached()) {
            return $this->invalid([[
                'code' => self::CODE_LIMIT_REACHED,
                'severity' => 'needs_clarification',
                'message' => $this->localized_string('agent_report_limit_reached', null, $lang),
            ]]);
        }

        $structure = $this->check_structure($input);
        if (!$structure['valid']) {
            if (trim((string)($input['name'] ?? '')) === '') {
                return $this->invalid([[
                    'code' => self::CODE_MISSING_NAME,
                    'severity' => 'needs_clarification',
                    'message' => $this->localized_string('agent_report_clarify_name', null, $lang),
                ]]);
            }
            if (trim((string)($input['source'] ?? '')) === '') {
                return $this->source_clarification($lang, '');
            }
            return parent::run_preflight($input, $contextid, $userid);
        }

        $name = trim((string)$input['name']);
        $resolvedsource = $this->catalog()->resolve_source((string)$input['source']);
        if ($resolvedsource['source'] === null) {
            return $this->source_clarification($lang, (string)$input['source']);
        }
        $source = $resolvedsource['source'];
        $instance = $this->catalog()->instantiate($source);
        $service = new report_definition_service($this->resolver(), $this->catalog());

        $columns = $service->normalize_columns($instance, (array)($input['columns'] ?? []));
        if ($columns['problem'] !== null) {
            return $this->definition_problem_clarification($columns['problem'], $lang);
        }
        $conditions = $service->normalize_conditions($instance, (array)($input['conditions'] ?? []), $userid);
        if ($conditions['problem'] !== null) {
            return $this->definition_problem_clarification($conditions['problem'], $lang);
        }
        $filters = $service->normalize_filters($instance, (array)($input['filters'] ?? []));
        if ($filters['problem'] !== null) {
            return $this->definition_problem_clarification($filters['problem'], $lang);
        }

        $usedefaults = array_key_exists('use_defaults', $input)
            ? !empty($input['use_defaults'])
            : empty($columns['items']);

        $prepared = [
            'name' => $name,
            'source' => $source,
            'uniquerows' => !empty($input['uniquerows']),
            'tags' => array_values(array_filter(array_map('strval', (array)($input['tags'] ?? [])))),
            'override' => array_values(array_map('strval', (array)($input['override'] ?? []))),
            'outputlang' => $lang,
            'resolved' => [
                'sourcename' => $this->source_name($source),
                'use_defaults' => $usedefaults,
                'columns' => $columns['items'],
                'conditions' => $conditions['items'],
                'filters' => $filters['items'],
            ],
        ];

        // A second report with the same name on the same source is legal but usually an accident.
        $duplicate = report::get_records(['name' => $name, 'source' => $source, 'type' => base::TYPE_CUSTOM_REPORT]);
        if (!empty($duplicate) && !in_array(self::OVERRIDE_DUPLICATE_NAME, $prepared['override'], true)) {
            return $this->confirmable($prepared, [[
                'code' => self::CODE_NAME_CONFLICT,
                'severity' => 'needs_confirmation',
                'message' => $this->localized_string('agent_report_name_conflict', $name, $lang),
            ]]);
        }

        return $this->pass($prepared);
    }

    /**
     * Create the report from the prepared input.
     *
     * @param array $preparedinput
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $preparedinput, int $contextid, int $userid): array {
        $lang = $this->get_output_language($preparedinput);
        $debugbase = $this->build_skill_debug_message(self::SKILL_NAME, $preparedinput);
        $resolved = (array)($preparedinput['resolved'] ?? []);

        if (!$this->can_author_reports($userid)) {
            return $this->permission_denied_result($lang, $debugbase . "\nDenied: no report builder access");
        }

        $service = new report_definition_service($this->resolver(), $this->catalog());
        $persistent = $service->create([
            'name' => (string)$preparedinput['name'],
            'source' => (string)$preparedinput['source'],
            'use_defaults' => !empty($resolved['use_defaults']),
            'uniquerows' => !empty($preparedinput['uniquerows']),
            'tags' => (array)($preparedinput['tags'] ?? []),
            'columns' => (array)($resolved['columns'] ?? []),
            'conditions' => (array)($resolved['conditions'] ?? []),
            'filters' => (array)($resolved['filters'] ?? []),
        ], $userid);

        $snapshot = $service->snapshot($persistent, $userid, true);
        $url = (new moodle_url('/reportbuilder/view.php', ['id' => (int)$snapshot['id']]))->out(false);
        $usermessage = $this->localized_string('agent_report_created', (object)[
            'name' => $snapshot['name'],
            'source' => $snapshot['sourcename'],
            'columns' => count($snapshot['columns']),
            'url' => $url,
        ], $lang);

        $lines = [$usermessage . ' [id ' . $snapshot['id'] . ']'];
        $lines[] = 'columns: ' . implode(', ', array_column($snapshot['columns'], 'identifier'));
        foreach (['conditions', 'filters'] as $key) {
            $identifiers = array_column($snapshot[$key], 'identifier');
            $lines[] = $key . ': ' . (empty($identifiers) ? '-' : implode(', ', $identifiers));
        }
        $lines[] = 'rows: ' . ($snapshot['rowcount'] ?? '-');
        $lines[] = 'audiences: 0 (visible only to you until an audience is set) | schedules: 0';

        return [
            'status' => 'executed',
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'resultid' => (int)$snapshot['id'],
            'report' => $snapshot,
            'observation_full' => implode("\n", $lines),
            'produced_outputs' => [
                'reportid' => (int)$snapshot['id'],
                'reportname' => (string)$snapshot['name'],
                'reportsource' => (string)$snapshot['source'],
            ],
            'debugmessage' => $debugbase . "\nCreated report: " . $snapshot['id'],
        ];
    }

    /**
     * Live view of the created report.
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
     * Localised name of a source.
     *
     * @param string $source FQCN
     * @return string
     */
    private function source_name(string $source): string {
        foreach ($this->catalog()->list_sources(false) as $entry) {
            if ($entry['source'] === $source) {
                return (string)$entry['name'];
            }
        }
        return $source;
    }
}
