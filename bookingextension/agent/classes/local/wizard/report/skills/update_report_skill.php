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
use core_reportbuilder\manager;
use core_reportbuilder\permission;

/**
 * Skill report.update_report: change the structure of an existing custom report.
 *
 * Columns (add, remove, replace, heading, aggregation, sorting, position), conditions (add with
 * values, change values, remove), filters (add, remove), name and unique rows. Every operation is
 * validated against the report's own source in preflight and frozen as an operation list; the
 * executor applies the list in one transaction and reports the stored definition afterwards.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_report_skill extends report_skill_base implements skill_trigger_provider_interface {
    /** Skill name. */
    public const SKILL_NAME = 'report.update_report';

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
     * Gate 2 (core's per-report edit rule is checked in preflight on top).
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
            'description' => 'Change an existing custom report of the Moodle Report Builder. Add, remove or replace columns; '
                . 'set headings, aggregation and sorting; add or remove conditions and set their values; add or remove '
                . 'filters; rename the report; toggle unique rows.',
            'is' => 'Edits to a report that already exists.',
            'not' => 'A new report (create_report); who may see it (set_report_audience); when it is sent (schedule_report).',
            'readonly' => false,
            'fallback_skillcall_string_key' => 'ai_status_skillcall_report_update_report',
            'example_utterances' => [
                'add the email column to the users report',
                'remove the city column from that report',
                'sort the completion report by date descending',
                'only show completed entries in the report',
                'rename the report to Quarterly overview',
                'count the rows per course in the enrolment report',
            ],
            'properties' => [
                'reportid' => [
                    'type' => 'integer',
                    'description' => 'Numeric id of the report when already known (e.g. from an earlier lookup or the '
                        . 'previous step). Never guess.',
                    'required' => false,
                ],
                'reportquery' => [
                    'type' => 'string',
                    'description' => 'The report name exactly as the user wrote it, when no id is known.',
                    'required' => false,
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'New report name.',
                    'required' => false,
                ],
                'uniquerows' => [
                    'type' => 'boolean',
                    'description' => 'Show unique rows only.',
                    'required' => false,
                ],
                'add_columns' => [
                    'type' => 'array',
                    'description' => 'Columns to add: objects {identifier, heading?, aggregation?, sort?, sortorder?} with '
                        . 'the exact column identifiers of the report\'s source.',
                    'required' => false,
                ],
                'remove_columns' => [
                    'type' => 'array',
                    'description' => 'Columns to remove, by identifier (entity:name) or by column id.',
                    'required' => false,
                ],
                'set_columns' => [
                    'type' => 'array',
                    'description' => 'Existing columns to change: objects {identifier or columnid, heading?, aggregation?, '
                        . 'sort? (asc, desc or empty to stop sorting), sortorder?, position?}.',
                    'required' => false,
                ],
                'replace_columns' => [
                    'type' => 'boolean',
                    'description' => 'When true, add_columns replaces the whole column list instead of appending.',
                    'required' => false,
                ],
                'add_conditions' => [
                    'type' => 'array',
                    'description' => 'Conditions to add: objects {identifier, operator, value?, value_to?, unit?, values?}; '
                        . 'operator is the operator key of the condition\'s filter (e.g. IS_EQUAL_TO, DATE_LAST).',
                    'required' => false,
                ],
                'set_condition_values' => [
                    'type' => 'array',
                    'description' => 'Values for conditions the report already has: same objects as add_conditions.',
                    'required' => false,
                ],
                'remove_conditions' => [
                    'type' => 'array',
                    'description' => 'Conditions to remove, by identifier or condition id.',
                    'required' => false,
                ],
                'add_filters' => [
                    'type' => 'array',
                    'description' => 'Filters to offer the readers, by identifier.',
                    'required' => false,
                ],
                'remove_filters' => [
                    'type' => 'array',
                    'description' => 'Filters to remove, by identifier or filter id.',
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
        return [
            'reportquery' => 'Active users',
            'add_columns' => [['identifier' => 'user:email']],
            'set_columns' => [['identifier' => 'user:fullname', 'sort' => 'asc']],
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
                'id' => 'report.update_report_request',
                'description' => 'User wants columns, sorting, aggregation, conditions, filters or the name of an existing '
                    . 'custom report changed.',
            ],
        ];
    }

    /**
     * Confirmation card (source B): the report and the operations.
     *
     * @param array $input Prepared input.
     * @return array|null
     */
    public function describe_proposed_action(array $input): ?array {
        $lang = $this->get_output_language($input);
        $resolved = (array)($input['resolved'] ?? []);
        $rows = [[
            'label' => $this->localized_string('agent_report_card_report', null, $lang),
            'value' => (string)($resolved['reportname'] ?? '') . ' (#' . (int)($input['reportid'] ?? 0) . ')',
        ]];
        foreach ((array)($resolved['operations'] ?? []) as $operation) {
            $rows[] = [
                'label' => $this->localized_string('agent_report_card_op_' . (string)$operation['op'], null, $lang),
                'value' => (string)($operation['label'] ?? ''),
            ];
        }
        return [
            'title' => $this->localized_string('agent_report_card_update_title', (string)($resolved['reportname'] ?? ''), $lang),
            'summary' => '',
            'rows' => $rows,
        ];
    }

    /**
     * Queue identity: report plus the hash of the operation list.
     *
     * @param array $input
     * @return array
     */
    public function build_queue_business_identity(array $input): array {
        $keys = ['name', 'uniquerows', 'add_columns', 'remove_columns', 'set_columns', 'replace_columns',
            'add_conditions', 'set_condition_values', 'remove_conditions', 'add_filters', 'remove_filters'];
        $changes = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $input)) {
                $changes[$key] = $input[$key];
            }
        }
        return [
            'task' => self::SKILL_NAME,
            'report' => (int)($input['reportid'] ?? 0) ?: trim((string)($input['reportquery'] ?? '')),
            'changes' => sha1(json_encode($changes)),
        ];
    }

    /**
     * Shape: a target and at least one change.
     *
     * @param array $input
     * @return array{valid:bool,errors:string[],ambiguities:string[]}
     */
    public function check_structure(array $input): array {
        $errors = [];
        if ((int)($input['reportid'] ?? 0) <= 0 && trim((string)($input['reportquery'] ?? '')) === '') {
            $errors[] = get_string('agent_report_report_missing', 'bookingextension_agent');
        }
        if (!$this->has_changes($input)) {
            $errors[] = get_string('agent_report_clarify_changes', 'bookingextension_agent');
        }
        foreach (
            ['add_columns', 'remove_columns', 'set_columns', 'add_conditions', 'set_condition_values',
            'remove_conditions', 'add_filters', 'remove_filters'] as $key
        ) {
            if (array_key_exists($key, $input) && $input[$key] !== null && !is_array($input[$key])) {
                $errors[] = get_string('agent_report_clarify_list_shape', 'bookingextension_agent', $key);
            }
        }
        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => []];
    }

    /**
     * Resolve the report, validate every operation against its source, freeze the list.
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
        $resolved = $this->resolve_report_target($input, $userid);
        if ($resolved['report'] === null) {
            return $this->report_target_clarification($resolved, $userid, $lang);
        }
        /** @var report $persistent */
        $persistent = $resolved['report'];
        if (!permission::can_edit_report($persistent, $userid)) {
            return $this->invalid([[
                'code' => self::CODE_PERMISSION_DENIED,
                'severity' => 'needs_clarification',
                'message' => $this->localized_string('agent_report_no_edit_access', null, $lang),
            ]]);
        }
        if (!$this->has_changes($input)) {
            return $this->invalid([[
                'code' => self::CODE_MISSING_CHANGES,
                'severity' => 'needs_clarification',
                'message' => $this->localized_string('agent_report_clarify_changes', null, $lang),
            ]]);
        }

        $reportid = (int)$persistent->get('id');
        $service = new report_definition_service($this->resolver(), $this->catalog());
        $snapshot = $service->snapshot($persistent, $userid, false);
        manager::reset_caches();
        $instance = manager::get_report_from_persistent($persistent);
        $operations = [];

        $name = trim((string)($input['name'] ?? ''));
        if ($name !== '' && $name !== $snapshot['name']) {
            $operations[] = ['op' => 'set_name', 'name' => $name, 'label' => $name];
        }
        if (array_key_exists('uniquerows', $input) && $input['uniquerows'] !== null) {
            $yesno = get_string_manager()->get_string(
                !empty($input['uniquerows']) ? 'yes' : 'no',
                'core',
                null,
                $lang !== '' ? $lang : current_language()
            );
            $operations[] = ['op' => 'set_uniquerows', 'value' => !empty($input['uniquerows']), 'label' => $yesno];
        }

        foreach ((array)($input['remove_columns'] ?? []) as $ref) {
            $column = $this->find_element($snapshot['columns'], $ref);
            if ($column === null) {
                return $this->definition_problem_clarification([
                    'kind' => report_definition_service::PROBLEM_UNKNOWN_COLUMN,
                    'value' => is_scalar($ref) ? (string)$ref : '',
                    'options' => $this->element_options($snapshot['columns']),
                ], $lang);
            }
            $operations[] = ['op' => 'remove_column', 'columnid' => (int)$column['id'],
                'label' => $column['heading'] !== '' ? $column['heading'] : $column['title']];
        }

        $adds = $service->normalize_columns($instance, (array)($input['add_columns'] ?? []));
        if ($adds['problem'] !== null) {
            return $this->definition_problem_clarification($adds['problem'], $lang);
        }
        if (!empty($input['replace_columns'])) {
            if (empty($adds['items'])) {
                return $this->definition_problem_clarification([
                    'kind' => report_definition_service::PROBLEM_UNKNOWN_COLUMN,
                    'value' => '',
                    'options' => [],
                ], $lang);
            }
            $operations[] = ['op' => 'replace_columns', 'items' => $adds['items'],
                'label' => implode(', ', array_column($adds['items'], 'identifier'))];
        } else {
            foreach ($adds['items'] as $item) {
                $operations[] = ['op' => 'add_column', 'item' => $item,
                    'label' => ($item['heading'] ?? '') !== '' ? $item['heading'] : $item['title']];
            }
        }

        foreach ((array)($input['set_columns'] ?? []) as $spec) {
            if (!is_array($spec)) {
                continue;
            }
            $ref = $spec['columnid'] ?? ($spec['identifier'] ?? '');
            $column = $this->find_element($snapshot['columns'], $ref);
            if ($column === null) {
                return $this->definition_problem_clarification([
                    'kind' => report_definition_service::PROBLEM_UNKNOWN_COLUMN,
                    'value' => is_scalar($ref) ? (string)$ref : '',
                    'options' => $this->element_options($snapshot['columns']),
                ], $lang);
            }
            $check = $service->normalize_columns($instance, [array_merge($spec, ['identifier' => $column['identifier']])]);
            if ($check['problem'] !== null) {
                return $this->definition_problem_clarification($check['problem'], $lang);
            }
            $operation = ['op' => 'set_column', 'columnid' => (int)$column['id']];
            $labelparts = [];
            foreach (['heading', 'aggregation', 'sort', 'sortorder', 'position'] as $key) {
                if (array_key_exists($key, $spec)) {
                    $operation[$key] = $key === 'sort' ? strtolower(trim((string)$spec[$key])) : $spec[$key];
                    $labelparts[] = $key . '=' . (string)$spec[$key];
                }
            }
            $operation['label'] = $column['identifier'] . ': ' . implode(', ', $labelparts);
            $operations[] = $operation;
        }

        foreach ((array)($input['remove_conditions'] ?? []) as $ref) {
            $condition = $this->find_element($snapshot['conditions'], $ref);
            if ($condition === null) {
                return $this->definition_problem_clarification([
                    'kind' => report_definition_service::PROBLEM_UNKNOWN_CONDITION,
                    'value' => is_scalar($ref) ? (string)$ref : '',
                    'options' => $this->element_options($snapshot['conditions']),
                ], $lang);
            }
            $operations[] = ['op' => 'remove_condition', 'conditionid' => (int)$condition['id'], 'label' => $condition['title']];
        }
        $conditions = $service->normalize_conditions($instance, (array)($input['add_conditions'] ?? []), $userid);
        if ($conditions['problem'] !== null) {
            return $this->definition_problem_clarification($conditions['problem'], $lang);
        }
        foreach ($conditions['items'] as $item) {
            $operations[] = ['op' => 'add_condition', 'item' => $item, 'label' => $this->condition_label($item)];
        }
        $values = $service->normalize_conditions($instance, (array)($input['set_condition_values'] ?? []), $userid);
        if ($values['problem'] !== null) {
            return $this->definition_problem_clarification($values['problem'], $lang);
        }
        foreach ($values['items'] as $item) {
            if ($this->find_element($snapshot['conditions'], $item['identifier']) === null) {
                // Setting a value on a condition the report does not have yet adds it.
                $operations[] = ['op' => 'add_condition', 'item' => $item, 'label' => $this->condition_label($item)];
            } else {
                $operations[] = ['op' => 'set_condition_values', 'item' => $item, 'label' => $this->condition_label($item)];
            }
        }

        foreach ((array)($input['remove_filters'] ?? []) as $ref) {
            $filter = $this->find_element($snapshot['filters'], $ref);
            if ($filter === null) {
                return $this->definition_problem_clarification([
                    'kind' => report_definition_service::PROBLEM_UNKNOWN_FILTER,
                    'value' => is_scalar($ref) ? (string)$ref : '',
                    'options' => $this->element_options($snapshot['filters']),
                ], $lang);
            }
            $operations[] = ['op' => 'remove_filter', 'filterid' => (int)$filter['id'], 'label' => $filter['title']];
        }
        $filters = $service->normalize_filters($instance, (array)($input['add_filters'] ?? []));
        if ($filters['problem'] !== null) {
            return $this->definition_problem_clarification($filters['problem'], $lang);
        }
        foreach ($filters['items'] as $item) {
            $operations[] = ['op' => 'add_filter', 'item' => $item, 'label' => $item['title']];
        }

        if (empty($operations)) {
            return $this->invalid([[
                'code' => self::CODE_MISSING_CHANGES,
                'severity' => 'needs_clarification',
                'message' => $this->localized_string('agent_report_clarify_changes', null, $lang),
            ]]);
        }

        return $this->pass([
            'reportid' => $reportid,
            'outputlang' => $lang,
            'resolved' => [
                'reportname' => $snapshot['name'],
                'source' => $snapshot['source'],
                'operations' => $operations,
                'before' => [
                    'columns' => array_column($snapshot['columns'], 'identifier'),
                    'conditions' => array_column($snapshot['conditions'], 'identifier'),
                    'filters' => array_column($snapshot['filters'], 'identifier'),
                ],
            ],
        ]);
    }

    /**
     * Apply the frozen operations.
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
        $reportid = (int)($preparedinput['reportid'] ?? 0);

        $persistent = $reportid > 0 ? report::get_record(['id' => $reportid]) : false;
        if (!$persistent || !permission::can_edit_report($persistent, $userid)) {
            return $this->permission_denied_result($lang, $debugbase . "\nDenied: cannot edit report " . $reportid);
        }

        $service = new report_definition_service($this->resolver(), $this->catalog());
        $applied = $service->apply($persistent, (array)($resolved['operations'] ?? []));
        $persistent = report::get_record(['id' => $reportid]);
        $snapshot = $service->snapshot($persistent, $userid, true);

        $usermessage = $this->localized_string('agent_report_updated', (object)[
            'name' => $snapshot['name'],
            'changes' => count($applied),
            'url' => $snapshot['url'],
        ], $lang);

        $lines = [$usermessage . ' [id ' . $snapshot['id'] . ']'];
        $lines[] = 'applied: ' . implode(', ', $applied);
        $before = (array)($resolved['before'] ?? []);
        $lines[] = 'columns before: ' . implode(', ', (array)($before['columns'] ?? []));
        $lines[] = 'columns after: ' . implode(', ', array_column($snapshot['columns'], 'identifier'));
        foreach (['conditions', 'filters'] as $key) {
            $identifiers = array_column($snapshot[$key], 'identifier');
            $lines[] = $key . ' after: ' . (empty($identifiers) ? '-' : implode(', ', $identifiers));
        }
        $lines[] = 'rows: ' . ($snapshot['rowcount'] ?? '-');

        return [
            'status' => 'executed',
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'resultid' => (int)$snapshot['id'],
            'report' => $snapshot,
            'applied' => $applied,
            'observation_full' => implode("\n", $lines),
            'produced_outputs' => [
                'reportid' => (int)$snapshot['id'],
                'reportname' => (string)$snapshot['name'],
                'reportsource' => (string)$snapshot['source'],
            ],
            'debugmessage' => $debugbase . "\nUpdated report: " . $snapshot['id'] . "\nApplied: " . count($applied),
        ];
    }

    /**
     * Live view of the changed report.
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
     * Whether the input carries any change at all.
     *
     * @param array $input
     * @return bool
     */
    private function has_changes(array $input): bool {
        if (trim((string)($input['name'] ?? '')) !== '') {
            return true;
        }
        if (array_key_exists('uniquerows', $input) && $input['uniquerows'] !== null) {
            return true;
        }
        foreach (
            ['add_columns', 'remove_columns', 'set_columns', 'add_conditions', 'set_condition_values',
            'remove_conditions', 'add_filters', 'remove_filters'] as $key
        ) {
            if (!empty($input[$key])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Find a snapshot element (column, condition, filter) by id or identifier.
     *
     * @param array $elements
     * @param mixed $ref
     * @return array|null
     */
    private function find_element(array $elements, $ref): ?array {
        if (is_array($ref)) {
            $ref = $ref['columnid'] ?? $ref['conditionid'] ?? $ref['filterid'] ?? $ref['id'] ?? $ref['identifier'] ?? '';
        }
        if (!is_scalar($ref)) {
            return null;
        }
        $ref = trim((string)$ref);
        foreach ($elements as $element) {
            if (ctype_digit($ref) && (int)$element['id'] === (int)$ref) {
                return $element;
            }
            if ($element['identifier'] === $ref) {
                return $element;
            }
        }
        return null;
    }

    /**
     * Clarification options for the elements a report currently has.
     *
     * @param array $elements
     * @return array[]
     */
    private function element_options(array $elements): array {
        return array_map(static fn(array $e): array => [
            'id' => $e['identifier'],
            'label' => ($e['heading'] ?? '') !== '' ? $e['heading'] : $e['title'],
        ], $elements);
    }

    /**
     * Card label of a condition item.
     *
     * @param array $item
     * @return string
     */
    private function condition_label(array $item): string {
        $rows = $this->definition_rows([], [$item], [], '');
        return (string)($rows[0]['value'] ?? $item['identifier']);
    }
}
