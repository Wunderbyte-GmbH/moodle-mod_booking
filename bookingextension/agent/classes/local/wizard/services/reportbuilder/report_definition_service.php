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

namespace bookingextension_agent\local\wizard\services\reportbuilder;

use core_reportbuilder\datasource;
use core_reportbuilder\local\audiences\base as audience_base;
use core_reportbuilder\local\helpers\aggregation as aggregation_helper;
use core_reportbuilder\local\helpers\report as report_helper;
use core_reportbuilder\local\helpers\schedule as schedule_helper;
use core_reportbuilder\local\models\audience as audience_model;
use core_reportbuilder\local\models\column as column_model;
use core_reportbuilder\local\models\filter as filter_model;
use core_reportbuilder\local\models\report;
use core_reportbuilder\local\models\schedule as schedule_model;
use core_reportbuilder\manager;

/**
 * The definition of one custom report as core stores it: a deterministic snapshot.
 *
 * The snapshot is the truth channel of the report family: the observation after every read and
 * every mutation is what the database holds (columns, conditions with their stored values,
 * filters, audiences, schedules), never what was asked for. Write operations join this class in
 * the authoring work package; reading lives here first so the read skills and the preview share it.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class report_definition_service {
    /** @var report_resolver */
    private report_resolver $resolver;

    /** @var report_source_catalog_service */
    private report_source_catalog_service $catalog;

    /** @var filter_value_codec */
    private filter_value_codec $codec;

    /** Validation problem kinds. */
    public const PROBLEM_UNKNOWN_COLUMN = 'unknown_column';
    /** Validation problem kinds. */
    public const PROBLEM_UNKNOWN_CONDITION = 'unknown_condition';
    /** Validation problem kinds. */
    public const PROBLEM_UNKNOWN_FILTER = 'unknown_filter';
    /** Validation problem kinds. */
    public const PROBLEM_AGGREGATION = 'aggregation';
    /** Validation problem kinds. */
    public const PROBLEM_SORT = 'sort';
    /** Validation problem kinds. */
    public const PROBLEM_CONDITION_VALUE = 'condition_value';

    /**
     * Constructor.
     *
     * @param report_resolver|null $resolver
     * @param report_source_catalog_service|null $catalog
     */
    public function __construct(?report_resolver $resolver = null, ?report_source_catalog_service $catalog = null) {
        $this->resolver = $resolver ?? new report_resolver();
        $this->catalog = $catalog ?? new report_source_catalog_service();
        $this->codec = new filter_value_codec($this->catalog);
    }

    /**
     * Validate and normalise a column list against a datasource.
     *
     * Each item: {identifier, heading?, aggregation?, sort? (asc|desc), sortorder?}. The first
     * problem stops validation and is returned with the structural alternatives (the column
     * identifiers of the source or of the named entity, the compatible aggregations).
     *
     * @param datasource $instance
     * @param array $columns
     * @return array{items: array[], problem: array|null}
     */
    public function normalize_columns(datasource $instance, array $columns): array {
        $items = [];
        foreach ($columns as $spec) {
            if (is_string($spec)) {
                $spec = ['identifier' => $spec];
            }
            if (!is_array($spec)) {
                continue;
            }
            $identifier = trim((string)($spec['identifier'] ?? ''));
            $column = $identifier !== '' ? $instance->get_column($identifier) : null;
            if ($column === null || !$column->get_is_available()) {
                return ['items' => [], 'problem' => [
                    'kind' => self::PROBLEM_UNKNOWN_COLUMN,
                    'value' => $identifier,
                    'options' => $this->column_options($instance, $identifier),
                ]];
            }
            $item = ['identifier' => $identifier, 'title' => $column->get_title()];
            $heading = trim((string)($spec['heading'] ?? ''));
            if ($heading !== '') {
                $item['heading'] = $heading;
            }
            $aggregation = trim((string)($spec['aggregation'] ?? ''));
            if ($aggregation !== '') {
                $aggregation = strtolower($aggregation);
                $compatible = array_keys(aggregation_helper::get_column_aggregations($column->get_type()));
                if (!in_array($aggregation, $compatible, true)) {
                    return ['items' => [], 'problem' => [
                        'kind' => self::PROBLEM_AGGREGATION,
                        'value' => $aggregation,
                        'identifier' => $identifier,
                        'options' => array_map(static fn(string $a): array => ['id' => $a, 'label' => $a], $compatible),
                    ]];
                }
                $item['aggregation'] = $aggregation;
            }
            $sort = strtolower(trim((string)($spec['sort'] ?? '')));
            if ($sort !== '') {
                if (!in_array($sort, ['asc', 'desc'], true) || !$column->get_is_sortable()) {
                    return ['items' => [], 'problem' => [
                        'kind' => self::PROBLEM_SORT,
                        'value' => $sort,
                        'identifier' => $identifier,
                        'options' => $column->get_is_sortable()
                            ? [['id' => 'asc', 'label' => 'asc'], ['id' => 'desc', 'label' => 'desc']]
                            : [],
                    ]];
                }
                $item['sort'] = $sort;
                if (isset($spec['sortorder']) && is_numeric($spec['sortorder'])) {
                    $item['sortorder'] = (int)$spec['sortorder'];
                }
            }
            $items[] = $item;
        }
        return ['items' => $items, 'problem' => null];
    }

    /**
     * Validate and normalise conditions: identifier plus encoded values.
     *
     * Each item: {identifier, operator?, value?, value_to?, unit?, values?, subcategories?}.
     *
     * @param datasource $instance
     * @param array $conditions
     * @param int $userid
     * @return array{items: array[], problem: array|null}
     */
    public function normalize_conditions(datasource $instance, array $conditions, int $userid): array {
        $items = [];
        foreach ($conditions as $spec) {
            if (is_string($spec)) {
                $spec = ['identifier' => $spec];
            }
            if (!is_array($spec)) {
                continue;
            }
            $identifier = trim((string)($spec['identifier'] ?? ''));
            $condition = $identifier !== '' ? $instance->get_condition($identifier) : null;
            if ($condition === null || !$condition->get_is_available()) {
                return ['items' => [], 'problem' => [
                    'kind' => self::PROBLEM_UNKNOWN_CONDITION,
                    'value' => $identifier,
                    'options' => $this->filter_options($instance->get_conditions(), $identifier),
                ]];
            }
            $item = ['identifier' => $identifier, 'title' => $condition->get_header(), 'values' => []];
            $hasspec = array_key_exists('operator', $spec) || array_key_exists('value', $spec)
                || array_key_exists('values', $spec) || array_key_exists('value_to', $spec);
            if ($hasspec) {
                $encoded = $this->codec->encode($condition, $spec, $userid);
                if ($encoded['error'] !== '') {
                    return ['items' => [], 'problem' => [
                        'kind' => self::PROBLEM_CONDITION_VALUE,
                        'value' => (string)($spec['operator'] ?? ''),
                        'identifier' => $identifier,
                        'detail' => $encoded['error'],
                        'options' => array_map(static fn(string $k): array => ['id' => $k, 'label' => $k], $encoded['allowed']),
                    ]];
                }
                $item['values'] = $encoded['values'];
            }
            $items[] = $item;
        }
        return ['items' => $items, 'problem' => null];
    }

    /**
     * Validate filters (identifier only; values belong to the viewer).
     *
     * @param datasource $instance
     * @param array $filters
     * @return array{items: array[], problem: array|null}
     */
    public function normalize_filters(datasource $instance, array $filters): array {
        $items = [];
        foreach ($filters as $spec) {
            $identifier = is_array($spec) ? trim((string)($spec['identifier'] ?? '')) : trim((string)$spec);
            $filter = $identifier !== '' ? $instance->get_filter($identifier) : null;
            if ($filter === null || !$filter->get_is_available()) {
                return ['items' => [], 'problem' => [
                    'kind' => self::PROBLEM_UNKNOWN_FILTER,
                    'value' => $identifier,
                    'options' => $this->filter_options($instance->get_filters(), $identifier),
                ]];
            }
            $items[] = ['identifier' => $identifier, 'title' => $filter->get_header()];
        }
        return ['items' => $items, 'problem' => null];
    }

    /**
     * Create a report from a normalised definition, in one transaction.
     *
     * @param array $definition {name, source, use_defaults, uniquerows?, tags?, columns[], conditions[], filters[]}
     * @param int $userid
     * @return report
     */
    public function create(array $definition, int $userid): report {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        try {
            $data = (object)[
                'name' => (string)$definition['name'],
                'source' => (string)$definition['source'],
                'contextid' => (int)\context_system::instance()->id,
                'component' => '',
                'area' => '',
                'itemid' => 0,
                'uniquerows' => (int)!empty($definition['uniquerows']),
            ];
            if (!empty($definition['tags']) && is_array($definition['tags'])) {
                $data->tags = array_values(array_map('strval', $definition['tags']));
            }
            $persistent = report_helper::create_report($data, !empty($definition['use_defaults']));
            $this->apply_definition($persistent, $definition);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
        manager::reset_caches();
        return report::get_record(['id' => (int)$persistent->get('id')]);
    }

    /**
     * Apply a list of operations to an existing report, in one transaction.
     *
     * Operations (already validated by normalize_*): set_name {name}, set_uniquerows {value},
     * add_column {item}, remove_column {columnid}, set_column {columnid, heading?, aggregation?,
     * sort?, sortorder?, position?}, add_condition {item}, set_condition_values {item},
     * remove_condition {conditionid}, add_filter {item}, remove_filter {filterid},
     * replace_columns {items[]}.
     *
     * @param report $persistent
     * @param array $operations
     * @return array Names of the operations applied, in order.
     */
    public function apply(report $persistent, array $operations): array {
        global $DB;

        $reportid = (int)$persistent->get('id');
        $applied = [];
        $transaction = $DB->start_delegated_transaction();
        try {
            foreach ($operations as $operation) {
                $op = (string)($operation['op'] ?? '');
                switch ($op) {
                    case 'set_name':
                    case 'set_uniquerows':
                        // Core's update_report() always writes name AND uniquerows: carry the current
                        // value of whichever one this operation does not change.
                        $current = report::get_record(['id' => $reportid]);
                        report_helper::update_report((object)[
                            'id' => $reportid,
                            'name' => $op === 'set_name' ? (string)$operation['name'] : (string)$current->get('name'),
                            'uniquerows' => $op === 'set_uniquerows'
                                ? (int)!empty($operation['value'])
                                : (int)$current->get('uniquerows'),
                        ]);
                        break;
                    case 'replace_columns':
                        foreach ($this->instance($reportid)->get_active_columns() as $column) {
                            report_helper::delete_report_column($reportid, (int)$column->get_persistent()->get('id'));
                        }
                        foreach ((array)$operation['items'] as $item) {
                            $this->add_column($reportid, (array)$item);
                        }
                        break;
                    case 'add_column':
                        $this->add_column($reportid, (array)$operation['item']);
                        break;
                    case 'remove_column':
                        report_helper::delete_report_column($reportid, (int)$operation['columnid']);
                        break;
                    case 'set_column':
                        $this->set_column($reportid, (array)$operation);
                        break;
                    case 'add_condition':
                        $this->add_condition($reportid, (array)$operation['item']);
                        break;
                    case 'set_condition_values':
                        $this->merge_condition_values($reportid, (array)($operation['item']['values'] ?? []));
                        break;
                    case 'remove_condition':
                        $this->remove_condition($reportid, (int)$operation['conditionid']);
                        break;
                    case 'add_filter':
                        report_helper::add_report_filter($reportid, (string)$operation['item']['identifier']);
                        break;
                    case 'remove_filter':
                        report_helper::delete_report_filter($reportid, (int)$operation['filterid']);
                        break;
                    default:
                        throw new \coding_exception('Unknown report operation: ' . $op);
                }
                $applied[] = $op;
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
        datasource::report_elements_modified($reportid);
        manager::reset_caches();
        return $applied;
    }

    /**
     * Apply the column/condition/filter part of a create definition.
     *
     * @param report $persistent
     * @param array $definition
     */
    private function apply_definition(report $persistent, array $definition): void {
        $reportid = (int)$persistent->get('id');
        foreach ((array)($definition['columns'] ?? []) as $item) {
            $this->add_column($reportid, (array)$item);
        }
        foreach ((array)($definition['conditions'] ?? []) as $item) {
            $this->add_condition($reportid, (array)$item);
        }
        foreach ((array)($definition['filters'] ?? []) as $item) {
            report_helper::add_report_filter($reportid, (string)$item['identifier']);
        }
        datasource::report_elements_modified($reportid);
    }

    /**
     * Add one column with heading, aggregation and sorting.
     *
     * @param int $reportid
     * @param array $item
     */
    private function add_column(int $reportid, array $item): void {
        $column = report_helper::add_report_column($reportid, (string)$item['identifier']);
        $columnid = (int)$column->get('id');
        $this->set_column($reportid, array_merge($item, ['columnid' => $columnid]));
    }

    /**
     * Update heading, aggregation, sorting and position of one column.
     *
     * @param int $reportid
     * @param array $operation {columnid, heading?, aggregation?, sort?, sortorder?, position?}
     */
    private function set_column(int $reportid, array $operation): void {
        $columnid = (int)$operation['columnid'];
        $model = new column_model($columnid);
        $changed = false;
        if (array_key_exists('heading', $operation)) {
            $model->set('heading', (string)$operation['heading']);
            $changed = true;
        }
        if (array_key_exists('aggregation', $operation)) {
            $model->set('aggregation', (string)$operation['aggregation']);
            $changed = true;
        }
        if ($changed) {
            $model->update();
        }
        if (array_key_exists('sort', $operation)) {
            $sort = (string)$operation['sort'];
            if ($sort === '') {
                report_helper::toggle_report_column_sorting($reportid, $columnid, false);
            } else {
                report_helper::toggle_report_column_sorting(
                    $reportid,
                    $columnid,
                    true,
                    $sort === 'desc' ? SORT_DESC : SORT_ASC
                );
                if (isset($operation['sortorder'])) {
                    report_helper::reorder_report_column_sorting($reportid, $columnid, (int)$operation['sortorder']);
                }
            }
        }
        if (isset($operation['position'])) {
            report_helper::reorder_report_column($reportid, $columnid, (int)$operation['position']);
        }
    }

    /**
     * Add a condition and store its values (if any).
     *
     * @param int $reportid
     * @param array $item {identifier, values}
     */
    private function add_condition(int $reportid, array $item): void {
        $identifier = (string)$item['identifier'];
        $existing = null;
        foreach ($this->instance($reportid)->get_active_conditions(false) as $condition) {
            if ($condition->get_unique_identifier() === $identifier) {
                $existing = $condition;
                break;
            }
        }
        if ($existing === null) {
            report_helper::add_report_condition($reportid, $identifier);
        }
        $this->merge_condition_values($reportid, (array)($item['values'] ?? []));
    }

    /**
     * Merge values into the stored condition data.
     *
     * @param int $reportid
     * @param array $values
     */
    private function merge_condition_values(int $reportid, array $values): void {
        if (empty($values)) {
            return;
        }
        $instance = $this->instance($reportid);
        $instance->set_condition_values(array_merge($instance->get_condition_values(), $values));
    }

    /**
     * Remove a condition and its stored values.
     *
     * @param int $reportid
     * @param int $conditionid
     */
    private function remove_condition(int $reportid, int $conditionid): void {
        $model = new filter_model($conditionid);
        $identifier = (string)$model->get('uniqueidentifier');
        report_helper::delete_report_condition($reportid, $conditionid);
        $instance = $this->instance($reportid);
        $values = $instance->get_condition_values();
        foreach (array_keys($values) as $key) {
            if (strncmp((string)$key, $identifier . '_', strlen($identifier) + 1) === 0) {
                unset($values[$key]);
            }
        }
        $instance->set_condition_values($values);
    }

    /**
     * A fresh datasource instance for a report (caches are reset so element changes are seen).
     *
     * @param int $reportid
     * @return datasource
     */
    private function instance(int $reportid): datasource {
        manager::reset_caches();
        datasource::report_elements_modified($reportid);
        /** @var datasource $instance */
        $instance = manager::get_report_from_id($reportid);
        return $instance;
    }

    /**
     * Clarification options for the columns of a source, narrowed to the entity a failed
     * identifier names when that entity exists.
     *
     * @param datasource $instance
     * @param string $identifier The failed identifier (entity:name).
     * @return array[]
     */
    private function column_options(datasource $instance, string $identifier): array {
        $entity = strpos($identifier, ':') !== false ? (string)substr($identifier, 0, (int)strpos($identifier, ':')) : '';
        $options = [];
        $narrowed = [];
        foreach ($instance->get_columns() as $column) {
            if (!$column->get_is_available()) {
                continue;
            }
            $option = ['id' => $column->get_unique_identifier(), 'label' => $column->get_title()];
            $options[] = $option;
            if ($entity !== '' && $column->get_entity_name() === $entity) {
                $narrowed[] = $option;
            }
        }
        return !empty($narrowed) ? $narrowed : $options;
    }

    /**
     * Clarification options for filters/conditions, narrowed by entity like column_options().
     *
     * @param array $filters
     * @param string $identifier
     * @return array[]
     */
    private function filter_options(array $filters, string $identifier): array {
        $entity = strpos($identifier, ':') !== false ? (string)substr($identifier, 0, (int)strpos($identifier, ':')) : '';
        $options = [];
        $narrowed = [];
        foreach ($filters as $filter) {
            if (!$filter->get_is_available()) {
                continue;
            }
            $option = ['id' => $filter->get_unique_identifier(), 'label' => $filter->get_header()];
            $options[] = $option;
            if ($entity !== '' && $filter->get_entity_name() === $entity) {
                $narrowed[] = $option;
            }
        }
        return !empty($narrowed) ? $narrowed : $options;
    }

    /**
     * Full structure of a report as stored.
     *
     * @param report $persistent
     * @param int $userid Acting user (for the can-edit flag).
     * @param bool $withrowcount Also count the rows (runs the report's count query).
     * @return array
     */
    public function snapshot(report $persistent, int $userid, bool $withrowcount = false): array {
        $snapshot = $this->resolver->summarize($persistent, $userid);
        $reportid = (int)$persistent->get('id');

        /** @var datasource $instance */
        $instance = manager::get_report_from_persistent($persistent);

        $snapshot['columns'] = [];
        foreach ($instance->get_active_columns() as $column) {
            $model = $column->get_persistent();
            $aggregation = $column->get_aggregation();
            $snapshot['columns'][] = [
                'id' => (int)$model->get('id'),
                'identifier' => $column->get_unique_identifier(),
                'title' => $column->get_title(),
                'heading' => (string)$model->get('heading'),
                'entity' => $column->get_entity_name(),
                'aggregation' => $aggregation !== null ? $aggregation::get_class_name() : '',
                'order' => (int)$model->get('columnorder'),
                'sortenabled' => (bool)$model->get('sortenabled'),
                'sortdirection' => (int)$model->get('sortdirection') === SORT_DESC ? 'desc' : 'asc',
                'sortorder' => (int)$model->get('sortorder'),
            ];
        }

        $conditionvalues = $instance->get_condition_values();
        $snapshot['conditions'] = [];
        foreach ($instance->get_active_conditions() as $condition) {
            $model = $condition->get_persistent();
            $identifier = $condition->get_unique_identifier();
            $snapshot['conditions'][] = [
                'id' => $model !== null ? (int)$model->get('id') : 0,
                'identifier' => $identifier,
                'title' => $condition->get_header(),
                'entity' => $condition->get_entity_name(),
                'filterclass' => $this->short_class($condition->get_filter_class()),
                'values' => $this->condition_values_for($identifier, $condition->get_filter_class(), $conditionvalues),
            ];
        }

        $snapshot['filters'] = [];
        foreach ($instance->get_active_filters() as $filter) {
            $model = $filter->get_persistent();
            $snapshot['filters'][] = [
                'id' => $model !== null ? (int)$model->get('id') : 0,
                'identifier' => $filter->get_unique_identifier(),
                'title' => $filter->get_header(),
                'entity' => $filter->get_entity_name(),
                'filterclass' => $this->short_class($filter->get_filter_class()),
            ];
        }

        $snapshot['audiences'] = [];
        foreach (audience_model::get_records(['reportid' => $reportid], 'id') as $audiencemodel) {
            $entry = [
                'id' => (int)$audiencemodel->get('id'),
                'type' => $this->short_class((string)$audiencemodel->get('classname')),
                'classname' => (string)$audiencemodel->get('classname'),
                'heading' => (string)$audiencemodel->get('heading'),
                'name' => '',
                'description' => '',
                'available' => false,
                'usercount' => (new audience_service())->count_users($audiencemodel),
            ];
            $audience = audience_base::instance((int)$audiencemodel->get('id'));
            if ($audience !== null) {
                try {
                    $entry['name'] = (string)$audience->get_name();
                    $entry['description'] = (string)$audience->get_description();
                    $entry['available'] = $audience->is_available();
                } catch (\Throwable $e) {
                    $entry['available'] = false;
                }
            }
            $snapshot['audiences'][] = $entry;
        }

        $recurrences = schedule_helper::get_recurrence_options();
        $viewas = schedule_helper::get_viewas_options();
        $snapshot['schedules'] = [];
        foreach (schedule_model::get_records(['reportid' => $reportid], 'id') as $schedule) {
            $audienceids = json_decode((string)$schedule->get('audiences'), true);
            $snapshot['schedules'][] = [
                'id' => (int)$schedule->get('id'),
                'name' => (string)$schedule->get('name'),
                'enabled' => (bool)$schedule->get('enabled'),
                'format' => (string)$schedule->get('format'),
                'recurrence' => (int)$schedule->get('recurrence'),
                'recurrencename' => (string)($recurrences[(int)$schedule->get('recurrence')] ?? ''),
                'userviewas' => (int)$schedule->get('userviewas'),
                'userviewasname' => (string)($viewas[(int)$schedule->get('userviewas')] ?? ''),
                'timescheduled' => (int)$schedule->get('timescheduled'),
                'timenextsend' => (int)$schedule->get('timenextsend'),
                'timelastsent' => (int)$schedule->get('timelastsent'),
                'audiences' => is_array($audienceids) ? array_map('intval', $audienceids) : [],
                'type' => $this->short_class((string)$schedule->get('classname')),
            ];
        }

        $snapshot['rowcount'] = null;
        if ($withrowcount) {
            try {
                $snapshot['rowcount'] = report_helper::get_report_row_count($reportid);
            } catch (\Throwable $e) {
                $snapshot['rowcount'] = null;
            }
        }

        return $snapshot;
    }

    /**
     * Stored condition values of one condition, decoded from core's flat form keys.
     *
     * Core stores conditions as `<identifier>_operator`, `<identifier>_value`, `_from`, `_to`,
     * `_unit`, … in the report's conditiondata. The operator is reported both as the stored
     * integer and as the filter class' constant name.
     *
     * @param string $identifier
     * @param string $filterclass
     * @param array $conditionvalues
     * @return array
     */
    private function condition_values_for(string $identifier, string $filterclass, array $conditionvalues): array {
        $values = [];
        $prefix = $identifier . '_';
        foreach ($conditionvalues as $key => $value) {
            if (strncmp((string)$key, $prefix, strlen($prefix)) !== 0) {
                continue;
            }
            $suffix = substr((string)$key, strlen($prefix));
            $values[$suffix] = is_scalar($value) || $value === null ? $value : (array)$value;
        }
        if (array_key_exists('operator', $values) && is_numeric($values['operator'])) {
            $values['operator_key'] = $this->catalog->operator_name($filterclass, (int)$values['operator']);
        }
        return $values;
    }

    /**
     * Short class name.
     *
     * @param string $class
     * @return string
     */
    private function short_class(string $class): string {
        $class = ltrim($class, '\\');
        $pos = strrpos($class, '\\');
        return $pos === false ? $class : (string)substr($class, $pos + 1);
    }
}
