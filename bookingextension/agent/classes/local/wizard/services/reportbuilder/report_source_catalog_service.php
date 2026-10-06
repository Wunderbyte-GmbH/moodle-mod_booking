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

use context_system;
use core_reportbuilder\datasource;
use core_reportbuilder\local\helpers\aggregation;
use core_reportbuilder\local\models\report;
use core_reportbuilder\local\report\base;
use core_reportbuilder\local\report\column;
use core_reportbuilder\local\report\filter;
use core_reportbuilder\manager;
use core_text;

/**
 * Read-only catalog of the Report Builder datasources installed on this site.
 *
 * Everything here is derived at run time from core_reportbuilder's own component discovery
 * (manager::get_report_datasources()) and from the datasource instances themselves: which plugin
 * ships a source, which entities it joins, and which columns, filters and conditions it offers
 * with their exact unique identifiers, filter operators and compatible aggregations. No plugin,
 * source or column name is known to this class.
 *
 * Operators are read from the filter classes by reflection (their public integer constants and,
 * where the class has one, its private get_operators() label map), so the enum the planner sees
 * is the constant name (IS_EQUAL_TO, DATE_RANGE, …) and never a phrase to be matched.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class report_source_catalog_service {
    /** Section selector: everything. */
    public const SECTION_ALL = 'all';
    /** Section selector: columns only. */
    public const SECTION_COLUMNS = 'columns';
    /** Section selector: filters only. */
    public const SECTION_FILTERS = 'filters';
    /** Section selector: conditions only. */
    public const SECTION_CONDITIONS = 'conditions';

    /** @var string[] All section selectors. */
    public const SECTIONS = [self::SECTION_ALL, self::SECTION_COLUMNS, self::SECTION_FILTERS, self::SECTION_CONDITIONS];

    /** @var array<string, datasource> Per-request cache of instantiated sources. */
    private static array $instances = [];

    /** @var array<string, array>|null Per-request cache of the flat source list (without entities). */
    private static ?array $flatsources = null;

    /**
     * Forget the per-request caches (tests).
     */
    public static function reset_caches(): void {
        self::$instances = [];
        self::$flatsources = null;
    }

    /**
     * Flat list of the installed and available datasources.
     *
     * Each entry: source (FQCN without leading backslash), name (localised), component (frankenstyle),
     * componentname (display name of the plugin, or the site name for core), and — when requested —
     * entities [{name, title}], counts {columns, filters, conditions} and available (false when the
     * source cannot be instantiated; the reason travels in `error`).
     *
     * Instantiation is on demand only: a datasource whose plugin ships a broken entity class dies
     * at compile time (PHP fatal, not an exception — seen with a third-party plugin whose entities
     * still override a method core removed), and a fatal cannot be caught. The plain list therefore
     * never instantiates, and callers narrow to a component before asking for entities.
     *
     * @param bool $withentities Instantiate the (narrowed) sources to list entities and counts.
     * @param string $component Frankenstyle component filter ('' = all), applied before instantiation.
     * @return array[]
     */
    public function list_sources(bool $withentities = false, string $component = ''): array {
        $sources = $this->flat_sources();
        $component = trim($component);
        if ($component !== '') {
            $sources = array_filter(
                $sources,
                static fn(array $s): bool => strcasecmp((string)$s['component'], $component) === 0
            );
        }
        if (!$withentities) {
            return array_values($sources);
        }

        foreach ($sources as $fqcn => $entry) {
            try {
                $instance = $this->instantiate($fqcn);
                $entry['entities'] = $this->describe_entities($instance);
                $entry['counts'] = [
                    'columns' => count($instance->get_columns()),
                    'filters' => count($instance->get_filters()),
                    'conditions' => count($instance->get_conditions()),
                ];
                $entry['available'] = true;
            } catch (\Throwable $e) {
                $entry['entities'] = [];
                $entry['counts'] = ['columns' => 0, 'filters' => 0, 'conditions' => 0];
                $entry['available'] = false;
                $entry['error'] = get_class($e);
            }
            $sources[$fqcn] = $entry;
        }

        return array_values($sources);
    }

    /**
     * Resolve a planner-supplied source reference to an installed datasource.
     *
     * Accepted, in this order: the exact FQCN (with or without leading backslash), the exact short
     * class name when it identifies exactly one source, the exact localised name (case-insensitive).
     * Anything else is unresolved and comes back with the full candidate list; there is no fuzzy
     * or partial matching by design.
     *
     * @param string $reference
     * @return array{source: string|null, candidates: array[]}
     */
    public function resolve_source(string $reference): array {
        $sources = $this->flat_sources();
        $needle = trim($reference);
        $needle = ltrim($needle, '\\');

        if ($needle !== '' && isset($sources[$needle])) {
            return ['source' => $needle, 'candidates' => []];
        }

        if ($needle !== '') {
            $byshort = [];
            $byname = [];
            $lowered = core_text::strtolower($needle);
            foreach ($sources as $fqcn => $entry) {
                $short = (string)substr($fqcn, (int)strrpos($fqcn, '\\') + 1);
                if (core_text::strtolower($short) === $lowered) {
                    $byshort[] = $fqcn;
                }
                if (core_text::strtolower((string)$entry['name']) === $lowered) {
                    $byname[] = $fqcn;
                }
            }
            if (count($byshort) === 1) {
                return ['source' => $byshort[0], 'candidates' => []];
            }
            if (count($byname) === 1) {
                return ['source' => $byname[0], 'candidates' => []];
            }
        }

        return ['source' => null, 'candidates' => array_values($sources)];
    }

    /**
     * Clarification options for the source list: id = FQCN, label = "name (plugin)".
     *
     * @return array[]
     */
    public function source_options(): array {
        $options = [];
        foreach ($this->flat_sources() as $fqcn => $entry) {
            $options[] = [
                'id' => $fqcn,
                'label' => $entry['name'] . ' (' . $entry['componentname'] . ')',
            ];
        }
        return $options;
    }

    /**
     * Describe one datasource: its columns, filters and conditions with identifiers and operators.
     *
     * @param string $source FQCN of the datasource (already resolved).
     * @param string $section One of SECTIONS.
     * @param string $entity Restrict to one entity name (empty = all).
     * @return array
     */
    public function describe_source(string $source, string $section = self::SECTION_ALL, string $entity = ''): array {
        $source = ltrim(trim($source), '\\');
        $flat = $this->flat_sources();
        $meta = $flat[$source] ?? ['source' => $source, 'name' => $source, 'component' => '', 'componentname' => ''];
        $instance = $this->instantiate($source);
        $entity = trim($entity);

        $described = $meta;
        $described['entities'] = $this->describe_entities($instance);
        $described['defaults'] = [
            'columns' => array_values($instance->get_default_columns()),
            'filters' => array_values($instance->get_default_filters()),
            'conditions' => array_values($instance->get_default_conditions()),
            'sorting' => $instance->get_default_column_sorting(),
        ];
        $described['columns'] = [];
        $described['filters'] = [];
        $described['conditions'] = [];

        $wantall = ($section === self::SECTION_ALL);
        if ($wantall || $section === self::SECTION_COLUMNS) {
            $defaults = array_flip($described['defaults']['columns']);
            foreach ($instance->get_columns() as $column) {
                if ($entity !== '' && $column->get_entity_name() !== $entity) {
                    continue;
                }
                $isdefault = isset($defaults[$column->get_unique_identifier()]);
                $described['columns'][] = $this->describe_column($instance, $column, $isdefault);
            }
        }
        if ($wantall || $section === self::SECTION_FILTERS) {
            $defaults = array_flip($described['defaults']['filters']);
            foreach ($instance->get_filters() as $filter) {
                if ($entity !== '' && $filter->get_entity_name() !== $entity) {
                    continue;
                }
                $isdefault = isset($defaults[$filter->get_unique_identifier()]);
                $described['filters'][] = $this->describe_filter($instance, $filter, $isdefault);
            }
        }
        if ($wantall || $section === self::SECTION_CONDITIONS) {
            $defaults = array_flip($described['defaults']['conditions']);
            foreach ($instance->get_conditions() as $condition) {
                if ($entity !== '' && $condition->get_entity_name() !== $entity) {
                    continue;
                }
                $described['conditions'][] = $this->describe_filter(
                    $instance,
                    $condition,
                    isset($defaults[$condition->get_unique_identifier()])
                );
            }
        }

        return $described;
    }

    /**
     * Instantiate a datasource without a persisted report.
     *
     * The datasource constructor only needs a report persistent to read source/context from; an
     * unsaved persistent with id 0 is enough to build the entities, columns and filters (this is
     * also how the column-cards exporter works before a report has any columns).
     *
     * @param string $source FQCN
     * @return datasource
     */
    public function instantiate(string $source): datasource {
        $source = ltrim(trim($source), '\\');
        if (isset(self::$instances[$source])) {
            return self::$instances[$source];
        }
        if (!manager::report_source_exists($source, datasource::class)) {
            throw new \coding_exception('Unknown report source: ' . $source);
        }

        $persistent = new report(0, (object)[
            'name' => '',
            'source' => $source,
            'type' => base::TYPE_CUSTOM_REPORT,
            'uniquerows' => 0,
            'contextid' => (int)context_system::instance()->id,
            'component' => '',
            'area' => '',
            'itemid' => 0,
        ]);

        /** @var datasource $instance */
        $instance = new $source($persistent);
        self::$instances[$source] = $instance;
        return $instance;
    }

    /**
     * Constant name of a filter operator value, e.g. text::IS_EQUAL_TO (3) => 'IS_EQUAL_TO'.
     *
     * Aliased constants collapse onto the first declared name; unit constants are ignored. Returns
     * '' when the class is unknown or has no such operator.
     *
     * @param string $filterclass FQCN of a core_reportbuilder filter class.
     * @param int $value
     * @return string
     */
    public function operator_name(string $filterclass, int $value): string {
        $filterclass = ltrim($filterclass, '\\');
        if (!class_exists($filterclass)) {
            return '';
        }
        foreach ((new \ReflectionClass($filterclass))->getConstants() as $name => $constant) {
            if (!is_int($constant) || $constant !== $value) {
                continue;
            }
            if (str_starts_with((string)$name, 'DATE_UNIT_') || str_starts_with((string)$name, 'SIZE_UNIT_')) {
                continue;
            }
            return (string)$name;
        }
        return '';
    }

    /**
     * Human-readable name of a column type constant.
     *
     * @param int $type
     * @return string
     */
    public static function column_type_name(int $type): string {
        $map = [
            column::TYPE_INTEGER => 'integer',
            column::TYPE_TEXT => 'text',
            column::TYPE_TIMESTAMP => 'timestamp',
            column::TYPE_BOOLEAN => 'boolean',
            column::TYPE_FLOAT => 'float',
            column::TYPE_LONGTEXT => 'longtext',
        ];
        return $map[$type] ?? (string)$type;
    }

    /**
     * Flat map FQCN => {source, name, component, componentname} from core's grouped list.
     *
     * @return array<string, array>
     */
    private function flat_sources(): array {
        if (self::$flatsources !== null) {
            return self::$flatsources;
        }
        $flat = [];
        foreach (manager::get_report_datasources() as $componentname => $sources) {
            foreach ($sources as $fqcn => $name) {
                $fqcn = ltrim((string)$fqcn, '\\');
                [$component] = explode('\\', $fqcn);
                $flat[$fqcn] = [
                    'source' => $fqcn,
                    'name' => (string)$name,
                    'component' => (string)$component,
                    'componentname' => (string)$componentname,
                ];
            }
        }
        ksort($flat);
        self::$flatsources = $flat;
        return $flat;
    }

    /**
     * Entities of a source: name and localised title, in the order the source annotated them.
     *
     * The entity list is private on the report base class; the columns and filters carry the
     * entity names, and get_entity_title() resolves each to its annotation.
     *
     * @param datasource $instance
     * @return array[]
     */
    private function describe_entities(datasource $instance): array {
        $names = [];
        foreach ($instance->get_columns() as $column) {
            $names[$column->get_entity_name()] = true;
        }
        foreach ($instance->get_filters() as $filter) {
            $names[$filter->get_entity_name()] = true;
        }
        foreach ($instance->get_conditions() as $condition) {
            $names[$condition->get_entity_name()] = true;
        }

        $entities = [];
        foreach (array_keys($names) as $name) {
            $entities[] = ['name' => (string)$name, 'title' => $this->entity_title($instance, (string)$name)];
        }
        return $entities;
    }

    /**
     * Localised entity title, falling back to the entity name.
     *
     * @param datasource $instance
     * @param string $entityname
     * @return string
     */
    private function entity_title(datasource $instance, string $entityname): string {
        try {
            return (string)$instance->get_entity_title($entityname);
        } catch (\Throwable $e) {
            return $entityname;
        }
    }

    /**
     * Describe one column.
     *
     * @param datasource $instance
     * @param column $column
     * @param bool $isdefault
     * @return array
     */
    private function describe_column(datasource $instance, column $column, bool $isdefault): array {
        $type = (int)$column->get_type();
        $aggregations = [];
        try {
            $aggregations = array_keys(aggregation::get_column_aggregations($type));
        } catch (\Throwable $e) {
            $aggregations = [];
        }
        return [
            'identifier' => $column->get_unique_identifier(),
            'title' => $column->get_title(),
            'entity' => $column->get_entity_name(),
            'entitytitle' => $this->entity_title($instance, $column->get_entity_name()),
            'type' => self::column_type_name($type),
            'sortable' => (bool)$column->get_is_sortable(),
            'aggregations' => $aggregations,
            'default' => $isdefault,
        ];
    }

    /**
     * Describe one filter or condition, including its operator enum.
     *
     * @param datasource $instance
     * @param filter $filter
     * @param bool $isdefault
     * @return array
     */
    private function describe_filter(datasource $instance, filter $filter, bool $isdefault): array {
        $class = $filter->get_filter_class();
        ['operators' => $operators, 'units' => $units] = $this->describe_operators($filter);
        return [
            'identifier' => $filter->get_unique_identifier(),
            'title' => $filter->get_header(),
            'entity' => $filter->get_entity_name(),
            'entitytitle' => $this->entity_title($instance, $filter->get_entity_name()),
            'filterclass' => (string)substr($class, (int)strrpos($class, '\\') + 1),
            'operators' => $operators,
            'units' => $units,
            'default' => $isdefault,
        ];
    }

    /**
     * Operator enum of a filter class, by reflection.
     *
     * Every public integer constant of the filter class is an operator, except the unit constants
     * (DATE_UNIT_*, SIZE_UNIT_*), which are reported separately. When the class implements the
     * conventional private get_operators() label map, the labels are attached and the enum is
     * narrowed to the operators that map allows (restrict_limited_operators). Aliased constants
     * (DATE_PREVIOUS = DATE_LAST) collapse onto the first declared name.
     *
     * @param filter $filter
     * @return array{operators: array[], units: array[]}
     */
    private function describe_operators(filter $filter): array {
        $class = $filter->get_filter_class();
        $operators = [];
        $units = [];
        if (!class_exists($class)) {
            return ['operators' => $operators, 'units' => $units];
        }

        $labels = null;
        try {
            $instance = $class::create($filter);
            if (method_exists($instance, 'get_operators')) {
                $method = new \ReflectionMethod($instance, 'get_operators');
                $method->setAccessible(true);
                $raw = $method->invoke($instance);
                if (is_array($raw)) {
                    $labels = [];
                    foreach ($raw as $value => $label) {
                        $labels[(int)$value] = (string)$label;
                    }
                }
            }
        } catch (\Throwable $e) {
            $labels = null;
        }

        $seen = [];
        foreach ((new \ReflectionClass($class))->getConstants() as $name => $value) {
            if (!is_int($value)) {
                continue;
            }
            if (str_starts_with((string)$name, 'DATE_UNIT_') || str_starts_with((string)$name, 'SIZE_UNIT_')) {
                $units[] = ['key' => (string)$name, 'value' => $value];
                continue;
            }
            if (isset($seen[$value])) {
                continue;
            }
            if ($labels !== null && !array_key_exists($value, $labels)) {
                continue;
            }
            $seen[$value] = true;
            $operators[] = [
                'key' => (string)$name,
                'value' => $value,
                'label' => $labels[$value] ?? '',
            ];
        }

        return ['operators' => $operators, 'units' => $units];
    }
}
