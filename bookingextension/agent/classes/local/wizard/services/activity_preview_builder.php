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
 * Shared, data-only builder for the pre-confirmation preview of course activity/quiz write tasks.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services;

/**
 * Builds {title, summary, rows} descriptors for add/update activity and add/update quiz.
 *
 * All user-facing text is resolved via get_string() in the conversation language (outputlang);
 * only resolved data values remain literals. Contains NO engine references.
 */
class activity_preview_builder {
    /**
     * Preview for creating a course activity.
     *
     * @param array $input Prepared input.
     * @return array{title:string,summary:string,rows:array[]}|null
     */
    public static function add_activity_descriptor(array $input): ?array {
        $lang = self::lang($input);
        $rows = [];
        self::push($rows, self::str('previewlabel_type', $lang), self::module_type((string)($input['modname'] ?? ''), $lang));
        self::push($rows, self::str('previewlabel_course', $lang), self::course_name($input));
        self::push($rows, self::str('previewlabel_section', $lang), self::section_label($input, $lang));
        self::push($rows, self::str('previewlabel_description', $lang), self::text_value($input['intro'] ?? null));

        return [
            'title' => self::title('previewtitle_addactivity', (string)($input['name'] ?? ''), $lang),
            'summary' => '',
            'rows' => $rows,
        ];
    }

    /**
     * Preview for updating a course activity (target + changed fields).
     *
     * @param array $input Prepared input.
     * @return array{title:string,summary:string,rows:array[]}|null
     */
    public static function update_activity_descriptor(array $input): ?array {
        $lang = self::lang($input);
        $input = self::flatten_prepared_changes($input);
        $rows = [];
        self::push($rows, self::str('previewlabel_course', $lang), self::course_name($input));
        $rows = array_merge($rows, self::changed_basic_rows($input, $lang));

        return [
            'title' => self::target_title('previewtitle_updateactivity', $input, $lang),
            'summary' => '',
            'rows' => $rows,
        ];
    }

    /**
     * Preview for creating a quiz.
     *
     * @param array $input Prepared input.
     * @return array{title:string,summary:string,rows:array[]}|null
     */
    public static function add_quiz_descriptor(array $input): ?array {
        $lang = self::lang($input);
        $rows = [];
        self::push($rows, self::str('previewlabel_course', $lang), self::course_name($input));
        self::push($rows, self::str('previewlabel_section', $lang), self::section_label($input, $lang));
        self::push($rows, self::str('previewlabel_description', $lang), self::text_value($input['intro'] ?? null));
        self::push($rows, self::str('previewlabel_questions', $lang), self::questions_summary($input, $lang));

        return [
            'title' => self::title('previewtitle_addquiz', (string)($input['name'] ?? ''), $lang),
            'summary' => '',
            'rows' => $rows,
        ];
    }

    /**
     * Preview for updating a quiz (target + changed fields + question changes).
     *
     * @param array $input Prepared input.
     * @return array{title:string,summary:string,rows:array[]}|null
     */
    public static function update_quiz_descriptor(array $input): ?array {
        $lang = self::lang($input);
        $input = self::flatten_prepared_changes($input);
        $input = self::flatten_prepared_plan($input);
        $rows = [];
        self::push($rows, self::str('previewlabel_course', $lang), self::course_name($input));
        $rows = array_merge($rows, self::changed_basic_rows($input, $lang));
        self::push($rows, self::str('previewlabel_questions', $lang), self::questions_summary($input, $lang));

        return [
            'title' => self::target_title('previewtitle_updatequiz', $input, $lang),
            'summary' => '',
            'rows' => $rows,
        ];
    }

    /**
     * Lift a prepared update input's nested field changes to the flat keys the row builders read.
     *
     * The confirmation preview receives the PREPARED input (the preflight pass payload), which nests
     * the requested field changes under 'changes'; the raw LLM input carries them flat. Flat keys win.
     *
     * @param array $input
     * @return array
     */
    private static function flatten_prepared_changes(array $input): array {
        $changes = $input['changes'] ?? null;
        if (!is_array($changes)) {
            return $input;
        }
        foreach (['name', 'intro', 'visible', 'settings'] as $key) {
            if (!isset($input[$key]) && array_key_exists($key, $changes)) {
                $input[$key] = $changes[$key];
            }
        }
        return $input;
    }

    /**
     * Lift a prepared quiz update's question 'plan' to the flat question keys the summary reads.
     *
     * @param array $input
     * @return array
     */
    private static function flatten_prepared_plan(array $input): array {
        $plan = $input['plan'] ?? null;
        if (!is_array($plan)) {
            return $input;
        }
        switch ((string)($plan['mode'] ?? '')) {
            case 'generate':
                $input['addquestions'] = true;
                if (!isset($input['count'])) {
                    $input['count'] = $plan['count'] ?? null;
                }
                break;
            case 'ids':
                if (empty($input['questionids'])) {
                    $input['questionids'] = (array)($plan['questionids'] ?? []);
                }
                break;
            case 'category':
                if (trim((string)($input['category'] ?? '')) === '') {
                    $input['category'] = $plan['category'] ?? null;
                }
                if (!isset($input['randomcount'])) {
                    $input['randomcount'] = $plan['count'] ?? null;
                }
                break;
        }
        return $input;
    }

    /**
     * Changed basic fields shared by activity/quiz updates (name, visibility, description).
     *
     * @param array $input
     * @param string $lang
     * @return array[]
     */
    private static function changed_basic_rows(array $input, string $lang): array {
        $rows = [];
        self::push($rows, self::str('previewlabel_name', $lang), self::text_value($input['name'] ?? null));
        if (isset($input['visible']) && $input['visible'] !== '') {
            $visible = self::is_truthy($input['visible'])
                ? self::str('previewvalue_visible', $lang)
                : self::str('previewvalue_hidden', $lang);
            self::push($rows, self::str('previewlabel_visibility', $lang), $visible);
        }
        self::push($rows, self::str('previewlabel_description', $lang), self::text_value($input['intro'] ?? null));
        $section = $input['section'] ?? ($input['section_move'] ?? null);
        if ($section !== null && $section !== '' && is_numeric($section)) {
            self::push($rows, self::str('previewlabel_section', $lang), (string)(int)$section);
        }
        return $rows;
    }

    /**
     * Summarize the question changes of a quiz create/update, or null when none.
     *
     * @param array $input
     * @param string $lang
     * @return string|null
     */
    private static function questions_summary(array $input, string $lang): ?string {
        $clauses = [];
        $count = self::positive_int($input['count'] ?? null);
        if ($count !== null && self::is_truthy($input['addquestions'] ?? null)) {
            $clauses[] = self::str('previewquiz_generate', $lang, $count);
        }
        $random = self::positive_int($input['randomcount'] ?? null);
        if ($random !== null) {
            $clauses[] = self::str('previewquiz_random', $lang, $random);
        }
        if (!empty($input['questionids']) && is_array($input['questionids'])) {
            $clauses[] = self::str('previewquiz_selected', $lang, count($input['questionids']));
        }
        $category = self::text_value($input['category'] ?? null);
        if ($category !== null) {
            $clauses[] = self::str('previewquiz_fromcategory', $lang, $category);
        }
        return empty($clauses) ? null : implode(', ', $clauses);
    }

    /**
     * Resolve the module type's localized display name (e.g. "Page", "URL"), or null.
     *
     * @param string $modname
     * @param string $lang
     * @return string|null
     */
    private static function module_type(string $modname, string $lang): ?string {
        $modname = trim($modname);
        if ($modname === '') {
            return null;
        }
        try {
            return self::str('pluginname', $lang, null, 'mod_' . $modname);
        } catch (\Throwable $e) {
            return $modname;
        }
    }

    /**
     * Resolve the target course's full name from courseid, or null.
     *
     * @param array $input
     * @return string|null
     */
    private static function course_name(array $input): ?string {
        $courseid = (int)($input['courseid'] ?? 0);
        if ($courseid <= 1) {
            return null;
        }
        try {
            $course = get_course($courseid);
            return format_string($course->fullname);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Localized "Section N" label from sectionnum/section, or null.
     *
     * @param array $input
     * @param string $lang
     * @return string|null
     */
    private static function section_label(array $input, string $lang): ?string {
        $section = $input['sectionnum'] ?? ($input['section'] ?? null);
        if ($section === null || $section === '' || !is_numeric($section)) {
            return null;
        }
        return self::str('previewvalue_section', $lang, (int)$section);
    }

    /**
     * Build a `Verb "target activity name" (cmid #id)` title for an update.
     *
     * @param string $stringid
     * @param array $input
     * @param string $lang
     * @return string
     */
    private static function target_title(string $stringid, array $input, string $lang): string {
        $title = self::str($stringid, $lang);
        $cmid = (int)($input['cmid'] ?? 0);
        $name = self::activity_name($cmid);
        if ($name !== '') {
            $title .= ' "' . $name . '"';
        }
        if ($cmid > 0) {
            $title .= ' (#' . $cmid . ')';
        }
        return $title;
    }

    /**
     * Resolve a course module's name from its id (best-effort, from DB), or '' .
     *
     * @param int $cmid
     * @return string
     */
    private static function activity_name(int $cmid): string {
        if ($cmid <= 0) {
            return '';
        }
        try {
            $cm = get_coursemodule_from_id('', $cmid, 0, false, IGNORE_MISSING);
            return $cm ? format_string($cm->name) : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Build a `Verb "name"` title.
     *
     * @param string $stringid
     * @param string $text
     * @param string $lang
     * @return string
     */
    private static function title(string $stringid, string $text, string $lang): string {
        $prefix = self::str($stringid, $lang);
        $text = trim($text);
        return $text === '' ? $prefix : $prefix . ' "' . $text . '"';
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
     * Trimmed text value, or null when empty.
     *
     * @param mixed $value
     * @return string|null
     */
    private static function text_value($value): ?string {
        if ($value === null || is_array($value)) {
            return null;
        }
        $text = trim((string)$value);
        return $text === '' ? null : $text;
    }

    /**
     * Positive integer, or null.
     *
     * @param mixed $value
     * @return int|null
     */
    private static function positive_int($value): ?int {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        $int = (int)$value;
        return $int > 0 ? $int : null;
    }

    /**
     * Interpret a possibly-string value as a boolean flag.
     *
     * @param mixed $value
     * @return bool
     */
    private static function is_truthy($value): bool {
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
    private static function str(string $id, string $lang, $a = null, string $component = 'bookingextension_agent'): string {
        if ($lang === '') {
            return get_string($id, $component, $a);
        }
        return get_string_manager()->get_string($id, $component, $a, $lang);
    }
}
