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

use core_reportbuilder\local\report\filter;

/**
 * Encodes a structured condition/filter value into the flat form core's filter classes read.
 *
 * The planner sends `{operator, value, value_to, unit, values}` with the operator as the filter
 * class' constant name (IS_EQUAL_TO, DATE_RANGE, …) and dates as ISO 8601 or Unix time; core wants
 * `<identifier>_operator`, `_value`, `_value1`, `_value2`, `_from`, `_to`, `_unit`, `_values`,
 * `_subcategories` depending on the class. The mapping is per filter class and strictly
 * structural: an unknown operator, a value of the wrong shape or an unsupported class is an error
 * with the allowed alternatives, never a guess. Nothing here interprets natural language.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class filter_value_codec {
    /** Error: the operator is not one of the class' operators. */
    public const ERROR_OPERATOR = 'operator';
    /** Error: a required value is missing or has the wrong shape. */
    public const ERROR_VALUE = 'value';
    /** Error: the filter class is not supported by the codec. */
    public const ERROR_UNSUPPORTED = 'unsupported';

    /** @var report_source_catalog_service */
    private report_source_catalog_service $catalog;

    /**
     * Constructor.
     *
     * @param report_source_catalog_service|null $catalog
     */
    public function __construct(?report_source_catalog_service $catalog = null) {
        $this->catalog = $catalog ?? new report_source_catalog_service();
    }

    /**
     * Encode one specification for one filter/condition.
     *
     * @param filter $filter The report filter (resolved from the datasource).
     * @param array $spec {operator?: string|int, value?, value_to?, unit?: string|int, values?: array, subcategories?: bool}
     * @param int $userid Acting user (time zone for dates).
     * @return array{values: array, error: string, allowed: array}
     *         values: flat form values keyed `<identifier>_<suffix>`; error: '' or one of ERROR_*;
     *         allowed: on an operator error, the operator keys the class accepts.
     */
    public function encode(filter $filter, array $spec, int $userid): array {
        $name = $filter->get_unique_identifier();
        $class = ltrim($filter->get_filter_class(), '\\');
        $short = (string)substr($class, (int)strrpos($class, '\\') + 1);
        $operators = $this->operator_map($class);

        $operatorkey = $spec['operator'] ?? null;
        $operator = null;
        if ($operatorkey !== null && $operatorkey !== '') {
            if (is_int($operatorkey) || (is_string($operatorkey) && ctype_digit($operatorkey))) {
                $operator = in_array((int)$operatorkey, $operators, true) ? (int)$operatorkey : null;
            } else if (is_string($operatorkey)) {
                $operator = $operators[strtoupper(trim($operatorkey))] ?? null;
            }
            if ($operator === null) {
                return ['values' => [], 'error' => self::ERROR_OPERATOR, 'allowed' => array_keys($operators)];
            }
        }

        $values = [];
        switch ($short) {
            case 'text':
            case 'select':
                if ($operator === null) {
                    return ['values' => [], 'error' => self::ERROR_OPERATOR, 'allowed' => array_keys($operators)];
                }
                $values[$name . '_operator'] = $operator;
                if (array_key_exists('value', $spec) && is_scalar($spec['value'])) {
                    $values[$name . '_value'] = (string)$spec['value'];
                }
                break;

            case 'boolean_select':
            case 'user':
                if ($operator === null) {
                    return ['values' => [], 'error' => self::ERROR_OPERATOR, 'allowed' => array_keys($operators)];
                }
                $values[$name . '_operator'] = $operator;
                if ($short === 'user' && !empty($spec['values'])) {
                    $values[$name . '_value'] = $this->int_list((array)$spec['values']);
                }
                break;

            case 'number':
            case 'filesize':
                if ($operator === null) {
                    return ['values' => [], 'error' => self::ERROR_OPERATOR, 'allowed' => array_keys($operators)];
                }
                $values[$name . '_operator'] = $operator;
                if (array_key_exists('value', $spec)) {
                    if (!is_numeric($spec['value'])) {
                        return ['values' => [], 'error' => self::ERROR_VALUE, 'allowed' => []];
                    }
                    $values[$name . '_value1'] = (float)$spec['value'];
                }
                if ($short === 'number' && array_key_exists('value_to', $spec)) {
                    if (!is_numeric($spec['value_to'])) {
                        return ['values' => [], 'error' => self::ERROR_VALUE, 'allowed' => []];
                    }
                    $values[$name . '_value2'] = (float)$spec['value_to'];
                }
                if ($short === 'filesize') {
                    $unit = $this->unit($class, $spec['unit'] ?? null, 'SIZE_UNIT_');
                    if ($unit === false) {
                        return ['values' => [], 'error' => self::ERROR_VALUE, 'allowed' => $this->unit_keys($class, 'SIZE_UNIT_')];
                    }
                    if ($unit !== null) {
                        $values[$name . '_unit'] = $unit;
                    }
                }
                break;

            case 'date':
                if ($operator === null) {
                    return ['values' => [], 'error' => self::ERROR_OPERATOR, 'allowed' => array_keys($operators)];
                }
                $values[$name . '_operator'] = $operator;
                if (array_key_exists('value', $spec) && $spec['value'] !== null && $spec['value'] !== '') {
                    $from = $this->timestamp($spec['value'], $userid);
                    // A relative operator takes a count of units; an absolute range takes dates.
                    if (array_key_exists('value_to', $spec) || $from !== null && !is_numeric($spec['value'])) {
                        if ($from === null) {
                            return ['values' => [], 'error' => self::ERROR_VALUE, 'allowed' => []];
                        }
                        $values[$name . '_from'] = $from;
                    } else if (is_numeric($spec['value'])) {
                        $values[$name . '_value'] = (int)$spec['value'];
                    } else {
                        return ['values' => [], 'error' => self::ERROR_VALUE, 'allowed' => []];
                    }
                }
                if (array_key_exists('value_to', $spec) && $spec['value_to'] !== null && $spec['value_to'] !== '') {
                    $to = $this->timestamp($spec['value_to'], $userid);
                    if ($to === null) {
                        return ['values' => [], 'error' => self::ERROR_VALUE, 'allowed' => []];
                    }
                    $values[$name . '_to'] = $to;
                }
                $unit = $this->unit($class, $spec['unit'] ?? null, 'DATE_UNIT_');
                if ($unit === false) {
                    return ['values' => [], 'error' => self::ERROR_VALUE, 'allowed' => $this->unit_keys($class, 'DATE_UNIT_')];
                }
                if ($unit !== null) {
                    $values[$name . '_unit'] = $unit;
                }
                break;

            case 'duration':
                if ($operator === null) {
                    return ['values' => [], 'error' => self::ERROR_OPERATOR, 'allowed' => array_keys($operators)];
                }
                $values[$name . '_operator'] = $operator;
                if (array_key_exists('value', $spec)) {
                    if (!is_numeric($spec['value'])) {
                        return ['values' => [], 'error' => self::ERROR_VALUE, 'allowed' => []];
                    }
                    $values[$name . '_value'] = (float)$spec['value'];
                }
                if (array_key_exists('unit', $spec) && $spec['unit'] !== null && $spec['unit'] !== '') {
                    if (!is_numeric($spec['unit'])) {
                        return ['values' => [], 'error' => self::ERROR_VALUE, 'allowed' => []];
                    }
                    $values[$name . '_unit'] = (int)$spec['unit'];
                }
                break;

            case 'category':
                if ($operator === null) {
                    return ['values' => [], 'error' => self::ERROR_OPERATOR, 'allowed' => array_keys($operators)];
                }
                $values[$name . '_operator'] = $operator;
                if (array_key_exists('value', $spec)) {
                    if (!is_numeric($spec['value'])) {
                        return ['values' => [], 'error' => self::ERROR_VALUE, 'allowed' => []];
                    }
                    $values[$name . '_value'] = (int)$spec['value'];
                }
                if (!empty($spec['subcategories'])) {
                    $values[$name . '_subcategories'] = 1;
                }
                break;

            case 'tags':
                if ($operator === null) {
                    return ['values' => [], 'error' => self::ERROR_OPERATOR, 'allowed' => array_keys($operators)];
                }
                $values[$name . '_operator'] = $operator;
                if (!empty($spec['values'])) {
                    $values[$name . '_value'] = $this->int_list((array)$spec['values']);
                }
                break;

            case 'cohort':
            case 'course_selector':
            case 'autocomplete':
                if (empty($spec['values']) || !is_array($spec['values'])) {
                    return ['values' => [], 'error' => self::ERROR_VALUE, 'allowed' => []];
                }
                $values[$name . '_values'] = $short === 'autocomplete'
                    ? array_values(array_map('strval', $spec['values']))
                    : $this->int_list($spec['values']);
                break;

            default:
                return ['values' => [], 'error' => self::ERROR_UNSUPPORTED, 'allowed' => []];
        }

        return ['values' => $values, 'error' => '', 'allowed' => []];
    }

    /**
     * Operator constants of a filter class: name => value (units excluded, aliases collapsed).
     *
     * @param string $class
     * @return array<string, int>
     */
    public function operator_map(string $class): array {
        $map = [];
        if (!class_exists($class)) {
            return $map;
        }
        $seen = [];
        foreach ((new \ReflectionClass($class))->getConstants() as $name => $value) {
            if (!is_int($value) || isset($seen[$value])) {
                continue;
            }
            if (str_starts_with((string)$name, 'DATE_UNIT_') || str_starts_with((string)$name, 'SIZE_UNIT_')) {
                continue;
            }
            $seen[$value] = true;
            $map[(string)$name] = $value;
        }
        return $map;
    }

    /**
     * Resolve a unit given as constant name (DATE_UNIT_DAY), short key (day) or integer.
     *
     * @param string $class
     * @param mixed $unit
     * @param string $prefix
     * @return int|null|false null when none given, false when invalid.
     */
    private function unit(string $class, $unit, string $prefix) {
        if ($unit === null || $unit === '') {
            return null;
        }
        $constants = $this->unit_map($class, $prefix);
        if (is_int($unit) || (is_string($unit) && ctype_digit($unit))) {
            return in_array((int)$unit, $constants, true) ? (int)$unit : false;
        }
        if (!is_string($unit)) {
            return false;
        }
        $key = strtoupper(trim($unit));
        if (isset($constants[$key])) {
            return $constants[$key];
        }
        if (isset($constants[$prefix . $key])) {
            return $constants[$prefix . $key];
        }
        return false;
    }

    /**
     * Unit constants of a class: name => value.
     *
     * @param string $class
     * @param string $prefix
     * @return array<string, int>
     */
    private function unit_map(string $class, string $prefix): array {
        $map = [];
        if (!class_exists($class)) {
            return $map;
        }
        foreach ((new \ReflectionClass($class))->getConstants() as $name => $value) {
            if (is_int($value) && str_starts_with((string)$name, $prefix)) {
                $map[(string)$name] = $value;
            }
        }
        return $map;
    }

    /**
     * Unit constant names of a class.
     *
     * @param string $class
     * @param string $prefix
     * @return string[]
     */
    private function unit_keys(string $class, string $prefix): array {
        return array_keys($this->unit_map($class, $prefix));
    }

    /**
     * A Unix timestamp from an integer or an ISO 8601 date/date-time in the user's time zone.
     *
     * Shared by the condition codec and the schedule service; nothing else is accepted (no
     * natural-language dates).
     *
     * @param mixed $value
     * @param int $userid
     * @return int|null
     */
    public static function timestamp($value, int $userid): ?int {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return (int)$value;
        }
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?)?$/', trim($value))) {
            return null;
        }
        try {
            $tz = \core_date::get_user_timezone_object(\core_user::get_user($userid) ?: null);
            $date = new \DateTimeImmutable(trim($value), $tz);
            return $date->getTimestamp();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Positive integers from a list; non-numeric entries are dropped.
     *
     * @param array $values
     * @return int[]
     */
    private function int_list(array $values): array {
        $out = [];
        foreach ($values as $value) {
            if (is_numeric($value) && (int)$value > 0) {
                $out[] = (int)$value;
            }
        }
        return array_values(array_unique($out));
    }
}
