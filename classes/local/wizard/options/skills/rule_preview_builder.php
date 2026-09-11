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

namespace mod_booking\local\wizard\options\skills;

/**
 * Shared, data-only builder for the pre-confirmation preview of booking-rule write tasks.
 *
 * All user-facing text is resolved via get_string() in the conversation language (outputlang).
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rule_preview_builder {
    /**
     * Preview for creating a rule from a template.
     *
     * @param array $input Prepared input.
     * @return array|null
     */
    public static function create_descriptor(array $input): ?array {
        $lang = self::lang($input);
        $rows = option_preview_builder::target_rows($input, $lang);
        self::push($rows, self::str('previewlabel_template', $lang), self::template_value($input));
        self::push($rows, self::str('previewlabel_rulename', $lang), self::text($input['rulename'] ?? null));
        self::push($rows, self::str('previewlabel_active', $lang), self::active_value($input, $lang));
        self::push($rows, self::str('previewlabel_question', $lang), self::text($input['question'] ?? null));

        return [
            'title' => self::str('previewtitle_createrule', $lang),
            'summary' => '',
            'rows' => $rows,
        ];
    }

    /**
     * Preview for updating an existing rule (target + changed fields).
     *
     * @param array $input Prepared input.
     * @return array|null
     */
    public static function update_descriptor(array $input): ?array {
        $lang = self::lang($input);

        $title = self::str('previewtitle_updaterule', $lang);
        $ruleid = self::positive_int_string($input['ruleid'] ?? null);
        $rulename = self::text($input['rule_name_resolved'] ?? null);
        if ($rulename !== null) {
            // Preflight resolved the target rule: show its NAME, with the id as a suffix.
            $title .= ' "' . $rulename . '"';
            if ($ruleid !== null) {
                $title .= ' (#' . $ruleid . ')';
            }
        } else {
            $target = self::text($input['rulequery'] ?? null) ?? $ruleid;
            if ($target !== null) {
                $title .= ' "' . $target . '"';
            }
        }

        $rows = option_preview_builder::target_rows($input, $lang);
        self::push($rows, self::str('previewlabel_template', $lang), self::template_value($input));
        self::push($rows, self::str('previewlabel_rulename', $lang), self::text($input['rulename'] ?? null));
        if (isset($input['isactive']) && $input['isactive'] !== '') {
            self::push($rows, self::str('previewlabel_active', $lang), self::active_value($input, $lang));
        }

        return [
            'title' => $title,
            'summary' => '',
            'rows' => $rows,
        ];
    }

    /**
     * The RESOLVED template name when preflight determined it, else the raw query text or id.
     *
     * @param array $input
     * @return string|null
     */
    private static function template_value(array $input): ?string {
        $resolved = self::text($input['template_name_resolved'] ?? null);
        if ($resolved !== null) {
            return $resolved;
        }
        $query = self::text($input['templatequery'] ?? null);
        if ($query !== null) {
            return $query;
        }
        return self::positive_int_string($input['templateid'] ?? null);
    }

    /**
     * Active/Inactive label for the isactive flag, or null when not set.
     *
     * @param array $input
     * @param string $lang
     * @return string|null
     */
    private static function active_value(array $input, string $lang): ?string {
        if (!isset($input['isactive']) || $input['isactive'] === '') {
            return null;
        }
        return self::truthy($input['isactive'])
            ? self::str('previewvalue_active', $lang)
            : self::str('previewvalue_inactive', $lang);
    }

    /**
     * Append a row when the value is meaningful.
     *
     * @param array[] $rows
     * @param string $label
     * @param string|null $value
     * @return void
     */
    private static function push(array &$rows, string $label, ?string $value): void {
        if ($value !== null && trim($value) !== '') {
            $rows[] = ['label' => $label, 'value' => trim($value)];
        }
    }

    /**
     * Trimmed scalar text, or null when empty.
     *
     * @param mixed $value
     * @return string|null
     */
    private static function text($value): ?string {
        if ($value === null || is_array($value)) {
            return null;
        }
        $text = trim((string)$value);
        return $text === '' ? null : $text;
    }

    /**
     * Positive integer as string, or null.
     *
     * @param mixed $value
     * @return string|null
     */
    private static function positive_int_string($value): ?string {
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
    private static function truthy($value): bool {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int)$value !== 0;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Resolve the conversation output language from the input, or '' for the current language.
     *
     * @param array $input
     * @return string
     */
    private static function lang(array $input): string {
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
    private static function str(string $id, string $lang, $a = null, string $component = 'booking'): string {
        if ($lang === '') {
            return get_string($id, $component, $a);
        }
        return get_string_manager()->get_string($id, $component, $a, $lang);
    }
}
