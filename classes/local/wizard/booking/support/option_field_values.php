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

namespace mod_booking\local\wizard\booking\support;

use mod_booking\local\wizard\options\skills\option_field_support;

/**
 * Values of booking option fields (custom fields) in the wizard skills (Wunderbyte-GmbH/Wunderbyte-GmbH#2680).
 *
 * The skills take `optionfields` as field key => displayed value. A select field stores the position of its value
 * (1-based), so the displayed value is resolved here; an unknown field or value is a clarification with the possible
 * values as remedies. The resolved map travels in the prepared input as `optionfieldvalues` and is applied to the
 * option payload in import style (`$data->{key}`), which booking_handler maps to the form element on save.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class option_field_values {
    /** @var string Issue code: no booking option field with this key. */
    public const ISSUE_UNKNOWN = 'OPTION_FIELD_UNKNOWN';
    /** @var string Issue code: the field cannot take the requested value. */
    public const ISSUE_VALUE_INVALID = 'OPTION_FIELD_VALUE_INVALID';
    /** @var int Maximum number of remedies offered. */
    private const REMEDY_LIMIT = 20;

    /**
     * Resolve field key => displayed value into field key => stored value.
     *
     * @param mixed $optionfields The `optionfields` input (object, or its JSON text).
     * @param callable $string Resolves a booking language string: fn(string $id, $a): string.
     * @return array{values:array<string,mixed>,issue:?array}
     */
    public static function resolve($optionfields, callable $string): array {
        if (is_string($optionfields)) {
            $optionfields = json_decode($optionfields, true);
        }
        $values = [];
        foreach ((array)$optionfields as $key => $requested) {
            $key = trim((string)$key);
            if ($key === '') {
                continue;
            }
            $record = option_field_support::get_field_by_shortname($key);
            if ($record === null) {
                return ['values' => [], 'issue' => self::unknown_field_issue($key, $string)];
            }
            $resolved = self::stored_value($record, $requested);
            if ($resolved === null) {
                $allowed = implode(', ', self::select_options($record));
                $a = (object)['field' => (string)$record->name, 'value' => self::text($requested), 'allowed' => $allowed];
                return ['values' => [], 'issue' => [
                    'code' => self::ISSUE_VALUE_INVALID,
                    'severity' => 'needs_clarification',
                    'field' => 'optionfields',
                    'message' => $string('agent_booking_optionfield_value_invalid', $a),
                    'user_question' => $string('agent_booking_optionfield_value_question', $a),
                    'remedy_options' => array_slice(self::select_options($record), 0, self::REMEDY_LIMIT),
                ]];
            }
            $values[$key] = $resolved;
        }
        return ['values' => $values, 'issue' => null];
    }

    /**
     * Preflight step for the option skills: resolve `optionfields` into `optionfieldvalues` of the prepared input.
     *
     * @param array $prepared Prepared input (by reference; receives `optionfieldvalues`).
     * @param callable $string Resolves a booking language string: fn(string $id, $a): string.
     * @return array|null Issue or null.
     */
    public static function preflight_issue(array &$prepared, callable $string): ?array {
        if (empty($prepared['optionfields'])) {
            return null;
        }
        $resolved = self::resolve($prepared['optionfields'], $string);
        if ($resolved['issue'] !== null) {
            return $resolved['issue'];
        }
        $prepared['optionfieldvalues'] = $resolved['values'];
        return null;
    }

    /**
     * The clarification for a field key that exists on no booking option field.
     *
     * @param string $key
     * @param callable $string
     * @return array
     */
    public static function unknown_field_issue(string $key, callable $string): array {
        $remedies = [];
        foreach (array_slice(option_field_support::get_field_records(), 0, self::REMEDY_LIMIT) as $record) {
            $remedies[] = ['id' => (string)$record->shortname, 'label' => (string)$record->name];
        }
        return [
            'code' => self::ISSUE_UNKNOWN,
            'severity' => 'needs_clarification',
            'field' => 'optionfields',
            'message' => $string('agent_booking_optionfield_not_found', $key),
            'user_question' => $string('agent_booking_optionfield_not_found_question', $key),
            'remedy_options' => $remedies,
        ];
    }

    /**
     * Put the resolved values on the option payload the way an import does; booking_handler maps them to the form.
     *
     * @param \stdClass $data Payload for booking_option::update() (importing mode).
     * @param array $values field key => stored value
     * @return void
     */
    public static function apply_to_data(\stdClass $data, array $values): void {
        foreach ($values as $key => $value) {
            $data->{(string)$key} = $value;
        }
    }

    /**
     * Of the given options, those without a value for the field.
     *
     * @param int[] $optionids
     * @param int $fieldid
     * @return int[]
     */
    public static function option_ids_without_value(array $optionids, int $fieldid): array {
        global $DB;
        $optionids = array_values(array_unique(array_map('intval', $optionids)));
        if (empty($optionids)) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($optionids, SQL_PARAMS_NAMED);
        $params['fieldid'] = $fieldid;
        $params['emptyvalue'] = '';
        $sql = "SELECT DISTINCT cfd.instanceid
                  FROM {customfield_data} cfd
                 WHERE cfd.fieldid = :fieldid
                   AND cfd.instanceid $insql
                   AND (cfd.intvalue > 0 OR (cfd.value IS NOT NULL AND " . $DB->sql_compare_text('cfd.value') . " <> :emptyvalue))";
        $filled = array_map('intval', $DB->get_fieldset_sql($sql, $params));
        return array_values(array_diff($optionids, $filled));
    }

    /**
     * What a field stores for the requested value, or null when the field cannot take it.
     *
     * @param \stdClass $record Field record from option_field_support.
     * @param mixed $requested
     * @return mixed
     */
    private static function stored_value(\stdClass $record, $requested) {
        $type = (string)$record->type;
        if ($type === 'select') {
            $options = self::select_options($record);
            $wanted = \core_text::strtolower(self::text($requested));
            foreach ($options as $index => $option) {
                if (\core_text::strtolower(trim($option)) === $wanted) {
                    return $index + 1;
                }
            }
            if (ctype_digit($wanted) && (int)$wanted >= 1 && (int)$wanted <= count($options)) {
                return (int)$wanted;
            }
            return null;
        }
        if ($type === 'checkbox') {
            $wanted = \core_text::strtolower(self::text($requested));
            if (in_array($wanted, ['1', 'true', 'yes', 'ja', 'oui', 'on'], true)) {
                return 1;
            }
            if (in_array($wanted, ['0', 'false', 'no', 'nein', 'non', 'off', ''], true)) {
                return 0;
            }
            return null;
        }
        if ($type === 'textarea') {
            return ['text' => self::text($requested), 'format' => FORMAT_HTML];
        }
        return self::text($requested);
    }

    /**
     * The displayed values of a select field, in stored order.
     *
     * @param \stdClass $record
     * @return string[]
     */
    private static function select_options(\stdClass $record): array {
        $configdata = json_decode((string)($record->configdata ?? ''), true);
        return option_field_support::normalize_options($configdata['options'] ?? []);
    }

    /**
     * A requested value as text.
     *
     * @param mixed $value
     * @return string
     */
    private static function text($value): string {
        if (is_array($value)) {
            $value = $value['text'] ?? implode(', ', array_map('strval', $value));
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        return trim((string)$value);
    }
}
