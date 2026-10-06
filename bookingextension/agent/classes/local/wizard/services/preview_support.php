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

/**
 * Small shared helpers for skill-authored pre-confirmation previews.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services;

/**
 * Reusable, data-only helpers so a skill's describe_proposed_action() stays a few lines and all
 * user-facing text is resolved via get_string() in the conversation language. No engine references.
 */
class preview_support {
    /**
     * Resolve the conversation output language from the input, or '' for the current language.
     *
     * @param array $input
     * @return string
     */
    public static function lang(array $input): string {
        return trim((string)($input['outputlang'] ?? ''));
    }

    /**
     * Resolve a language string, forced to the conversation language when one is set.
     *
     * @param string $id
     * @param string $lang
     * @param mixed $a
     * @param string $component
     * @return string
     */
    public static function str(string $id, string $lang, $a = null, string $component = 'bookingextension_agent'): string {
        if ($lang === '') {
            return get_string($id, $component, $a);
        }
        return get_string_manager()->get_string($id, $component, $a, $lang);
    }

    /**
     * Append a {label, value} row when the value is meaningful.
     *
     * @param array[] $rows
     * @param string $label
     * @param string|null $value
     * @return void
     */
    public static function push(array &$rows, string $label, ?string $value): void {
        if ($value !== null && trim($value) !== '') {
            $rows[] = ['label' => $label, 'value' => trim($value)];
        }
    }

    /**
     * Trimmed scalar text, or null when empty / non-scalar.
     *
     * @param mixed $value
     * @return string|null
     */
    public static function text($value): ?string {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }
        $text = trim((string)$value);
        return $text === '' ? null : $text;
    }

    /**
     * Positive integer as a string, or null.
     *
     * @param mixed $value
     * @return string|null
     */
    public static function posint($value): ?string {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        $int = (int)$value;
        return $int > 0 ? (string)$int : null;
    }

    /**
     * Interpret a possibly-string value as a boolean flag.
     *
     * @param mixed $value
     * @return bool
     */
    public static function truthy($value): bool {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int)$value !== 0;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Resolve the target course's full name from courseid, or null.
     *
     * @param array $input
     * @return string|null
     */
    public static function course_name(array $input): ?string {
        $courseid = (int)($input['courseid'] ?? 0);
        if ($courseid <= 1) {
            return null;
        }
        try {
            return format_string(get_course($courseid)->fullname);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Comma-join a list of scalar values, or null when empty.
     *
     * @param mixed $value
     * @return string|null
     */
    public static function list_value($value): ?string {
        if (!is_array($value) || empty($value)) {
            return null;
        }
        $parts = array_map(static fn($v) => trim((string)$v), array_filter($value, 'is_scalar'));
        $parts = array_values(array_filter($parts, static fn($v) => $v !== ''));
        return empty($parts) ? null : implode(', ', $parts);
    }
}
