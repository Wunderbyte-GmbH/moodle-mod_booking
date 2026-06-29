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

use mod_booking\singleton_service;

/**
 * Shared, data-only builder for the human-readable pre-confirmation preview of option write tasks.
 *
 * Produces {title, summary, rows:[{label,value}]} descriptors for create / slotbooking-create /
 * update of booking options, so the option skills' describe_proposed_action() overrides stay tiny
 * and consistent. Contains NO engine references; only mod_booking domain knowledge.
 *
 * All user-facing text is resolved via get_string() in the conversation language (the input's
 * `outputlang`, falling back to the current language); only punctuation/format separators and
 * resolved data values (titles, names, prices) are literals.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class option_preview_builder {
    /** @var string[] Keys that identify the target option (never shown as a "change"). */
    private const TARGETING_KEYS = ['optionid', 'optionids', 'optionquery', 'resolvedoptionid'];

    /** @var string[] Internal / derived keys that carry no user meaning in a preview. */
    private const HIDDEN_KEYS = [
        'outputlang', 'optiontype', 'slot_enabled', 'headerimage_token',
        'activityquery', 'cmid', 'id', 'override', 'description',
    ];

    /**
     * Build the preview descriptor for creating a normal (dated) booking option.
     *
     * @param array $input Prepared input.
     * @return array|null
     */
    public static function create_descriptor(array $input): ?array {
        $lang = self::lang($input);
        return [
            'title' => self::title('previewtitle_createoption', (string)($input['text'] ?? ''), $lang),
            'summary' => '',
            'rows' => self::curated_option_rows($input, $lang),
        ];
    }

    /**
     * Build the preview descriptor for creating a slot booking (appointment) option.
     *
     * @param array $input Prepared input.
     * @return array|null
     */
    public static function slotbooking_descriptor(array $input): ?array {
        $lang = self::lang($input);
        $window = self::time_range((string)($input['slot_opening_time'] ?? ''), (string)($input['slot_closing_time'] ?? ''));
        $weekdays = self::active_weekdays($input, $lang);
        $duration = self::positive_int($input['slot_duration_minutes'] ?? null);
        $seats = self::positive_int($input['slot_max_participants_per_slot'] ?? null);
        $validity = self::date_range((string)($input['slot_valid_from'] ?? ''), (string)($input['slot_valid_until'] ?? ''), $lang);

        $rows = [];
        self::push($rows, self::str('previewlabel_availabilitywindow', $lang), $window);
        self::push($rows, self::str('previewlabel_weekdays', $lang), $weekdays);
        self::push(
            $rows,
            self::str('previewlabel_slotlength', $lang),
            $duration !== null ? self::str('previewvalue_minutes', $lang, $duration) : null
        );
        self::push($rows, self::str('previewlabel_seatsperslot', $lang), $seats !== null ? (string)$seats : null);
        self::push($rows, self::str('previewlabel_valid', $lang), $validity);
        self::push($rows, self::str('previewlabel_prices', $lang), self::format_prices($input['prices'] ?? null));

        return [
            'title' => self::title('previewtitle_createslotbooking', (string)($input['text'] ?? ''), $lang),
            'summary' => self::slotbooking_summary($window, $weekdays, $duration, $seats, $validity, $lang),
            'rows' => $rows,
        ];
    }

    /**
     * Build the preview descriptor for updating an existing booking option.
     *
     * Shows the target option first, then ONLY the fields being changed.
     *
     * @param array $input Prepared input (carries the resolved optionid).
     * @return array|null
     */
    public static function update_descriptor(array $input): ?array {
        $lang = self::lang($input);
        $optionid = (int)($input['optionid'] ?? $input['resolvedoptionid'] ?? 0);
        return [
            'title' => self::option_target_title('previewtitle_updateoption', $optionid, $lang),
            'summary' => '',
            'rows' => self::changed_field_rows($input, $lang),
        ];
    }

    /**
     * Build the preview descriptor for changing an option's trainer(s).
     *
     * @param array $input Prepared input.
     * @return array|null
     */
    public static function trainer_descriptor(array $input): ?array {
        $lang = self::lang($input);
        $optionid = (int)($input['optionid'] ?? $input['resolvedoptionid'] ?? 0);

        $rows = [];
        $trainer = self::text_value($input['teacherquery'] ?? ($input['teacheremail'] ?? null));
        if ($trainer === null && !empty($input['teacherids']) && is_array($input['teacherids'])) {
            $trainer = self::resolve_user_names($input['teacherids']);
        }
        self::push_str($rows, 'previewlabel_teacher', $lang, $trainer);

        return [
            'title' => self::option_target_title('previewtitle_updatetrainer', $optionid, $lang),
            'summary' => '',
            'rows' => $rows,
        ];
    }

    /**
     * Build the preview descriptor for a bulk option update.
     *
     * @param array $input Prepared input.
     * @return array|null
     */
    public static function bulk_descriptor(array $input): ?array {
        $lang = self::lang($input);

        if (!empty($input['apply_to_all'])) {
            $target = self::str('previewvalue_alloptions', $lang);
        } else if (!empty($input['optionids']) && is_array($input['optionids'])) {
            $target = self::str('previewvalue_noptions', $lang, count($input['optionids']));
        } else {
            $target = self::text_value($input['optionquery'] ?? null);
        }

        $rows = [];
        self::push_str($rows, 'previewlabel_appliesto', $lang, $target);
        foreach (self::changed_field_rows($input, $lang) as $row) {
            $rows[] = $row;
        }

        return [
            'title' => self::str('previewtitle_bulkupdate', $lang),
            'summary' => '',
            'rows' => $rows,
        ];
    }

    /**
     * Build the preview descriptor for booking users into an option.
     *
     * @param array $input Prepared input (carries resolved option + user ids).
     * @return array|null
     */
    public static function book_users_descriptor(array $input): ?array {
        $lang = self::lang($input);
        $optionid = (int)($input['resolvedoptionid'] ?? $input['optionid'] ?? 0);

        $participants = null;
        if (!empty($input['resolvedbookuserids']) && is_array($input['resolvedbookuserids'])) {
            $participants = self::resolve_user_names($input['resolvedbookuserids']);
        } else {
            $participants = self::text_value($input['bookusersquery'] ?? null);
        }

        $rows = [];
        self::push_str($rows, 'previewlabel_participants', $lang, $participants);
        if (self::is_truthy($input['bookuserscompleted'] ?? null)) {
            self::push_str($rows, 'previewlabel_markcompleted', $lang, self::str('yes', $lang, null, 'core'));
        }
        if (self::is_truthy($input['bookusersupdateexisting'] ?? null)) {
            self::push_str($rows, 'previewlabel_updateexisting', $lang, self::str('yes', $lang, null, 'core'));
        }

        return [
            'title' => self::option_target_title('previewtitle_bookusers', $optionid, $lang),
            'summary' => '',
            'rows' => $rows,
        ];
    }

    /**
     * Build the preview descriptor for adding a price category.
     *
     * @param array $input Prepared input.
     * @return array|null
     */
    public static function add_price_category_descriptor(array $input): ?array {
        $lang = self::lang($input);

        $rows = [];
        self::push_str($rows, 'previewlabel_identifier', $lang, self::text_value($input['identifier'] ?? null));
        self::push_str($rows, 'previewlabel_name', $lang, self::text_value($input['name'] ?? null));
        if (isset($input['defaultvalue']) && $input['defaultvalue'] !== '') {
            self::push_str($rows, 'previewlabel_defaultprice', $lang, (string)(float)$input['defaultvalue']);
        }
        self::push_str($rows, 'previewlabel_sortorder', $lang, self::positive_int_string($input['pricecatsortorder'] ?? null));

        return [
            'title' => self::str('previewtitle_addpricecategory', $lang),
            'summary' => '',
            'rows' => $rows,
        ];
    }

    /**
     * Build the preview descriptor for configuring booking-instance settings.
     *
     * @param array $input Prepared input ({action, changes:[{field,value}]}).
     * @param array $fieldspec Configurable field metadata (key => [...]).
     * @return array|null
     */
    public static function configure_instance_descriptor(array $input, array $fieldspec): ?array {
        $lang = self::lang($input);
        $changes = is_array($input['changes'] ?? null) ? (array)$input['changes'] : [];

        $rows = [];
        foreach ($changes as $change) {
            if (!is_array($change)) {
                continue;
            }
            $field = trim((string)($change['field'] ?? ''));
            if ($field === '' || !isset($fieldspec[$field])) {
                continue;
            }
            $type = (string)($fieldspec[$field]['type'] ?? 'string');
            $value = $type === 'boolean'
                ? ($change['value'] ? self::str('yes', $lang, null, 'core') : self::str('no', $lang, null, 'core'))
                : self::generic_value($change['value'] ?? null, $lang);
            if ($value === null) {
                continue;
            }
            $rows[] = ['label' => self::str('previewcfg_' . $field, $lang), 'value' => $value];
        }

        return [
            'title' => self::str('previewtitle_configureinstance', $lang),
            'summary' => '',
            'rows' => $rows,
        ];
    }

    /**
     * Build a `Verb "option name" (#id)` heading for an existing option.
     *
     * @param string $stringid
     * @param int $optionid
     * @param string $lang
     * @return string
     */
    private static function option_target_title(string $stringid, int $optionid, string $lang): string {
        $title = self::str($stringid, $lang);
        $name = self::resolve_option_title($optionid);
        if ($name !== '') {
            $title .= ' "' . $name . '"';
        }
        if ($optionid > 0) {
            $title .= ' (#' . $optionid . ')';
        }
        return $title;
    }

    /**
     * Resolve up to a cap of user ids to a comma-separated full-name list ("+N more"), or null.
     *
     * @param mixed[] $userids
     * @param int $cap
     * @return string|null
     */
    private static function resolve_user_names(array $userids, int $cap = 10): ?string {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userids), static fn($id) => $id > 0)));
        if (empty($ids)) {
            return null;
        }
        $names = [];
        foreach (array_slice($ids, 0, $cap) as $id) {
            $user = \core_user::get_user($id);
            if ($user) {
                $names[] = fullname($user);
            }
        }
        if (empty($names)) {
            return null;
        }
        $remaining = count($ids) - count($names);
        $list = implode(', ', $names);
        return $remaining > 0 ? $list . ', ' . get_string('previewvalue_andnmore', 'bookingextension_agent', $remaining) : $list;
    }

    /**
     * Curated subset of common option fields, in display order (used for create).
     *
     * @param array $input
     * @param string $lang
     * @return array[]
     */
    private static function curated_option_rows(array $input, string $lang): array {
        $teacher = self::text_value($input['teacherquery'] ?? ($input['teacheremail'] ?? null));
        $rows = [];
        self::push_str($rows, 'previewlabel_seats', $lang, self::positive_int_string($input['maxanswers'] ?? null));
        self::push_str($rows, 'previewlabel_waitinglist', $lang, self::positive_int_string($input['maxoverbooking'] ?? null));
        self::push_str($rows, 'previewlabel_start', $lang, self::format_datetime($input['coursestarttime'] ?? null, $lang));
        self::push_str($rows, 'previewlabel_end', $lang, self::format_datetime($input['courseendtime'] ?? null, $lang));
        self::push_str($rows, 'previewlabel_sessions', $lang, self::format_sessions($input['optiondates'] ?? null, $lang));
        self::push_str($rows, 'previewlabel_location', $lang, self::text_value($input['location'] ?? null));
        self::push_str($rows, 'previewlabel_address', $lang, self::text_value($input['address'] ?? null));
        self::push_str($rows, 'previewlabel_teacher', $lang, $teacher);
        self::push_str($rows, 'previewlabel_prices', $lang, self::format_prices($input['prices'] ?? null));
        $opens = self::format_datetime($input['bookingopeningtime'] ?? null, $lang);
        $closes = self::format_datetime($input['bookingclosingtime'] ?? null, $lang);
        self::push_str($rows, 'previewlabel_bookingopens', $lang, $opens);
        self::push_str($rows, 'previewlabel_bookingcloses', $lang, $closes);
        self::push_str($rows, 'previewlabel_visibility', $lang, self::format_visibility($input, $lang));
        return $rows;
    }

    /**
     * Every changed field in an update, mapped to a friendly label where known and humanized
     * otherwise — so the diff is complete, not curated.
     *
     * @param array $input
     * @param string $lang
     * @return array[]
     */
    private static function changed_field_rows(array $input, string $lang): array {
        // Known fields keep their nice label + formatter.
        $known = self::curated_option_rows($input, $lang);
        $shownkeys = [
            'maxanswers', 'maxoverbooking', 'coursestarttime', 'courseendtime', 'optiondates',
            'location', 'address', 'teacherquery', 'teacheremail', 'prices',
            'bookingopeningtime', 'bookingclosingtime', 'visibility', 'invisible',
        ];

        // Any other changed key falls back to a humanized label + generic value.
        $skip = array_merge(self::TARGETING_KEYS, self::HIDDEN_KEYS, $shownkeys);
        foreach ($input as $key => $value) {
            $key = (string)$key;
            if (in_array($key, $skip, true)) {
                continue;
            }
            $formatted = self::generic_value($value, $lang);
            if ($formatted !== null) {
                $known[] = ['label' => self::humanize($key), 'value' => $formatted];
            }
        }
        return $known;
    }

    /**
     * Compose a one-line summary for a slot booking option (rendered only when the core parts exist).
     *
     * @param string|null $window
     * @param string|null $weekdays
     * @param int|null $duration
     * @param int|null $seats
     * @param string|null $validity
     * @param string $lang
     * @return string
     */
    private static function slotbooking_summary(
        ?string $window,
        ?string $weekdays,
        ?int $duration,
        ?int $seats,
        ?string $validity,
        string $lang
    ): string {
        if ($window === null || $weekdays === null || $duration === null || $seats === null || $validity === null) {
            return '';
        }
        return self::str('previewsummary_slotbooking', $lang, (object)[
            'window' => $window,
            'weekdays' => $weekdays,
            'duration' => $duration,
            'seats' => $seats,
            'validity' => $validity,
        ]);
    }

    /**
     * Build a `Verb noun "title"` heading from a title string id plus the option text.
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
     * Append a row whose label is a language string id, resolved in the target language.
     *
     * @param array[] $rows
     * @param string $labelid Language string id (bookingextension_agent).
     * @param string $lang
     * @param string|null $value
     * @return void
     */
    private static function push_str(array &$rows, string $labelid, string $lang, ?string $value): void {
        self::push($rows, self::str($labelid, $lang), $value);
    }

    /**
     * Active weekdays as a localized, comma-separated name list (slot_day_1..7, Monday=1).
     *
     * @param array $input
     * @param string $lang
     * @return string|null
     */
    private static function active_weekdays(array $input, string $lang): ?string {
        $daykeys = [
            1 => 'monday', 2 => 'tuesday', 3 => 'wednesday', 4 => 'thursday',
            5 => 'friday', 6 => 'saturday', 7 => 'sunday',
        ];
        $active = [];
        foreach ($daykeys as $day => $daykey) {
            if (self::is_truthy($input['slot_day_' . $day] ?? null)) {
                $active[] = self::str($daykey, $lang, null, 'calendar');
            }
        }
        return empty($active) ? null : implode(', ', $active);
    }

    /**
     * Format "from – to" for HH:MM clock values.
     *
     * @param string $from
     * @param string $to
     * @return string|null
     */
    private static function time_range(string $from, string $to): ?string {
        $from = trim($from);
        $to = trim($to);
        if ($from === '' && $to === '') {
            return null;
        }
        return trim($from . ' – ' . $to, ' –');
    }

    /**
     * Format "from – to" for date values.
     *
     * @param string $from
     * @param string $to
     * @param string $lang
     * @return string|null
     */
    private static function date_range(string $from, string $to, string $lang): ?string {
        $from = self::format_date($from, $lang);
        $to = self::format_date($to, $lang);
        if ($from === null && $to === null) {
            return null;
        }
        return trim((string)$from . ' – ' . (string)$to, ' –');
    }

    /**
     * Format a date-only value (ISO 8601 / unix) in the site locale, or null when empty.
     *
     * @param mixed $value
     * @param string $lang
     * @return string|null
     */
    private static function format_date($value, string $lang): ?string {
        $ts = self::to_timestamp($value);
        return $ts === null ? null : userdate($ts, self::str('strftimedate', $lang, null, 'langconfig'));
    }

    /**
     * Format a datetime value (ISO 8601 / unix) in the site locale, or null when empty.
     *
     * @param mixed $value
     * @param string $lang
     * @return string|null
     */
    private static function format_datetime($value, string $lang): ?string {
        $ts = self::to_timestamp($value);
        return $ts === null ? null : userdate($ts, self::str('strftimedatetime', $lang, null, 'langconfig'));
    }

    /**
     * Coerce an ISO 8601 string or unix timestamp to a positive unix timestamp, or null.
     *
     * @param mixed $value
     * @return int|null
     */
    private static function to_timestamp($value): ?int {
        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }
        if (is_numeric($value)) {
            $ts = (int)$value;
            return $ts > 0 ? $ts : null;
        }
        $ts = strtotime((string)$value);
        return ($ts !== false && $ts > 0) ? $ts : null;
    }

    /**
     * Format a price map ({category: price}) as "category: price, …", or null when empty.
     *
     * @param mixed $value
     * @return string|null
     */
    private static function format_prices($value): ?string {
        if (!is_array($value) || empty($value)) {
            return null;
        }
        $parts = [];
        foreach ($value as $category => $price) {
            if (is_array($price)) {
                continue;
            }
            $parts[] = trim((string)$category) . ': ' . trim((string)$price);
        }
        return empty($parts) ? null : implode(', ', $parts);
    }

    /**
     * Format option visibility from either the `visibility` alias or the `invisible` integer.
     *
     * @param array $input
     * @param string $lang
     * @return string|null
     */
    private static function format_visibility(array $input, string $lang): ?string {
        if (isset($input['visibility']) && trim((string)$input['visibility']) !== '') {
            return ucfirst(trim((string)$input['visibility']));
        }
        if (!isset($input['invisible']) || $input['invisible'] === '') {
            return null;
        }
        return match ((int)$input['invisible']) {
            0 => self::str('previewvalue_visible', $lang),
            1 => self::str('previewvalue_hidden', $lang),
            2 => self::str('previewvalue_visiblelink', $lang),
            default => null,
        };
    }

    /**
     * Localized session count for an optiondates array, or null.
     *
     * @param mixed $value
     * @param string $lang
     * @return string|null
     */
    private static function format_sessions($value, string $lang): ?string {
        if (!is_array($value) || empty($value)) {
            return null;
        }
        return self::str('previewvalue_sessions', $lang, count($value));
    }

    /**
     * Resolve the title of an existing option (best-effort), or '' when unavailable.
     *
     * @param int $optionid
     * @return string
     */
    private static function resolve_option_title(int $optionid): string {
        if ($optionid <= 0) {
            return '';
        }
        try {
            $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
            return trim((string)($settings->text ?? ''));
        } catch (\Throwable $e) {
            return '';
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
     * Positive integer, or null when absent / non-positive.
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
     * Positive integer as string, or null.
     *
     * @param mixed $value
     * @return string|null
     */
    private static function positive_int_string($value): ?string {
        $int = self::positive_int($value);
        return $int === null ? null : (string)$int;
    }

    /**
     * Generic scalar/array value formatter for unmapped changed fields.
     *
     * @param mixed $value
     * @param string $lang
     * @return string|null
     */
    private static function generic_value($value, string $lang): ?string {
        if (is_bool($value)) {
            return $value ? self::str('yes', $lang, null, 'core') : null;
        }
        if (is_array($value)) {
            if (empty($value)) {
                return null;
            }
            $scalars = array_map(static fn($v) => trim((string)$v), array_filter($value, 'is_scalar'));
            return empty($scalars) ? null : implode(', ', $scalars);
        }
        if ($value === null) {
            return null;
        }
        $text = trim((string)$value);
        return $text === '' ? null : $text;
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
     * Humanize a snake_case key into a label (fallback for unmapped changed fields only).
     *
     * @param string $key
     * @return string
     */
    private static function humanize(string $key): string {
        $text = trim((string)preg_replace('/\s+/', ' ', str_replace('_', ' ', $key)));
        return $text === '' ? '' : \core_text::strtoupper(\core_text::substr($text, 0, 1)) . \core_text::substr($text, 1);
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
     * @param string $id String identifier.
     * @param string $lang Target language ('' = current).
     * @param mixed $a Placeholder data.
     * @param string $component Language component.
     * @return string
     */
    private static function str(string $id, string $lang, $a = null, string $component = 'bookingextension_agent'): string {
        if ($lang === '') {
            return get_string($id, $component, $a);
        }
        return get_string_manager()->get_string($id, $component, $a, $lang);
    }
}
