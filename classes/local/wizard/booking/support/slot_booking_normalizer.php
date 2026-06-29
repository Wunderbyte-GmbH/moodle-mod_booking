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
 * Slot-booking input normalizer — domain helper for create/update option tasks.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\wizard\booking\support;

/**
 * Normalizes LLM-produced command input for booking.create_option and
 * booking.update_option tasks before the interpreter passes the input to
 * task-level validation.
 *
 * This class encapsulates all slot-booking and self-learning domain knowledge
 * so that the interpreter itself remains domain-agnostic (parse → validate →
 * emit structured command).
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class slot_booking_normalizer {
    /**
     * Canonicalize task input before validation.
     *
     * Only applies to booking.create_option and booking.update_option.
     * All other task names are returned unchanged.
     *
     * @param  string $taskname
     * @param  array  $input
     * @return array
     */
    public function normalize(string $taskname, array $input): array {
        // Domain-field normalization that applies to EVERY booking task — moved out of the engine
        // parameter_constructor to keep the engine framework-agnostic (audit 05-F01): self-reference
        // query fields and the option start/end timestamps. Runs for all booking skills, not just
        // create/update_option, mirroring the engine's previous unconditional behaviour.
        $input = $this->normalize_common_booking_fields($input);

        if (!in_array($taskname, ['mod_booking.create_option', 'mod_booking.update_option'], true)) {
            return $input;
        }

        // Self-learning: interpret "no limit" phrases as a high practical capacity.
        if ($this->is_selflearning_input($input) && !array_key_exists('maxanswers', $input)) {
            $nolimittext = $this->collect_text_fields($input);
            if (preg_match('/\b(kein\s+limit|unbegrenzt|ohne\s+limit|no\s+limit|unlimited)\b/u', $nolimittext)) {
                $input['maxanswers'] = 999999;
            }
        }

        if (!$this->is_slotbooking_input($input)) {
            return $input;
        }

        $input['slot_enabled'] = true;

        $combinedtext = $this->collect_text_fields($input);
        $hascustomindicator = preg_match(
            '/\b(custom|userdefined|user-defined|max|up\s+to|at\s+most)\b/u',
            $combinedtext
        ) === 1;

        if (empty($input['slot_type']) || !is_string($input['slot_type'])) {
            $input['slot_type'] = $hascustomindicator ? 'userdefined' : 'fixed';
        } else {
            $slottype = strtolower(trim((string)$input['slot_type']));
            if (in_array($slottype, ['custom', 'user-defined', 'user defined'], true)) {
                $input['slot_type'] = 'userdefined';
            }
        }

        if ($hascustomindicator && ($input['slot_type'] ?? '') === 'fixed') {
            $input['slot_type'] = 'userdefined';
        }

        if (empty($input['slot_booking_view_mode']) || !is_string($input['slot_booking_view_mode'])) {
            $input['slot_booking_view_mode'] = 'calendar';
        }

        if (isset($input['slot_duration_minutes'])) {
            $input['slot_duration_minutes'] = max(1, (int)$input['slot_duration_minutes']);
        }

        if (isset($input['slot_interval_minutes'])) {
            $input['slot_interval_minutes'] = max(1, (int)$input['slot_interval_minutes']);
        } else if (isset($input['slot_duration_minutes'])) {
            $input['slot_interval_minutes'] = max(1, (int)$input['slot_duration_minutes']);
        }

        if (($input['slot_type'] ?? '') === 'userdefined') {
            if (!isset($input['slot_custom_max_duration']) || (int)$input['slot_custom_max_duration'] <= 0) {
                $maxseconds = $this->extract_max_duration_seconds($combinedtext);
                if ($maxseconds === null && isset($input['slot_duration_minutes'])) {
                    $maxseconds = max(60, (int)$input['slot_duration_minutes'] * 60);
                }
                if ($maxseconds === null) {
                    $maxseconds = 60 * 60;
                }
                $input['slot_custom_max_duration'] = (int)$maxseconds;
            }

            if (!isset($input['slot_custom_min_duration']) || (int)$input['slot_custom_min_duration'] <= 0) {
                $input['slot_custom_min_duration'] = 15 * 60;
            }

            if (!isset($input['slot_custom_max_days']) || (int)$input['slot_custom_max_days'] <= 0) {
                $input['slot_custom_max_days'] = DAYSECS;
            }

            if (!isset($input['slot_custom_start_interval_minutes']) || (int)$input['slot_custom_start_interval_minutes'] <= 0) {
                $input['slot_custom_start_interval_minutes'] = 1;
            }
        }

        foreach (['slot_valid_from', 'slot_valid_until'] as $datefield) {
            if (isset($input[$datefield])) {
                $ts = $this->to_unix_timestamp($input[$datefield]);
                if ($ts !== null) {
                    $input[$datefield] = $ts;
                }
            }
        }

        for ($day = 1; $day <= 7; $day++) {
            $key = 'slot_day_' . $day;
            $input[$key] = !empty($input[$key]) ? 1 : 0;
        }

        if (isset($input['slot_max_participants_per_slot'])) {
            $input['slot_max_participants_per_slot'] = max(1, (int)$input['slot_max_participants_per_slot']);
        }

        if (isset($input['slot_max_slots_per_user'])) {
            $input['slot_max_slots_per_user'] = max(1, (int)$input['slot_max_slots_per_user']);
        } else {
            $input['slot_max_slots_per_user'] = 1;
        }

        if (!array_key_exists('slot_type_change_has_answers', $input)) {
            $input['slot_type_change_has_answers'] = 0;
        }
        if (!array_key_exists('slot_type_change_confirm', $input)) {
            $input['slot_type_change_confirm'] = 0;
        }

        return $input;
    }

    /**
     * Normalize fields shared across all booking tasks: self-reference query strings (comma-trim)
     * and the option start/end timestamps. Moved here from the engine parameter_constructor so the
     * engine carries no booking field names (audit 05-F01).
     *
     * @param  array $input
     * @return array
     */
    private function normalize_common_booking_fields(array $input): array {
        foreach (['teacherquery', 'selectusersquery', 'bookusersquery'] as $field) {
            if (!isset($input[$field]) || !is_string($input[$field])) {
                continue;
            }
            $raw = trim($input[$field]);
            if ($raw === '') {
                continue;
            }
            $parts = [];
            foreach (array_map('trim', explode(',', $raw)) as $part) {
                if ($part !== '') {
                    $parts[] = $part;
                }
            }
            if (!empty($parts)) {
                $input[$field] = implode(', ', $parts);
            }
        }

        foreach (['coursestarttime', 'courseendtime'] as $field) {
            if (array_key_exists($field, $input)) {
                $ts = $this->coerce_timestamp($input[$field]);
                if ($ts !== null) {
                    $input[$field] = $ts;
                }
            }
        }

        return $input;
    }

    /**
     * Coerce a timestamp-like value (int, digit string, human date string, or {timestamp|value}
     * array) to a unix timestamp, using the booking datetime parser for human strings.
     *
     * @param  mixed $value
     * @return int|null
     */
    private function coerce_timestamp($value): ?int {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_array($value)) {
            if (isset($value['timestamp'])) {
                return $this->coerce_timestamp($value['timestamp']);
            }
            if (isset($value['value'])) {
                return $this->coerce_timestamp($value['value']);
            }
            return null;
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }
            if (ctype_digit($trimmed)) {
                $parsed = (int)$trimmed;
                return $parsed > 0 ? $parsed : null;
            }
            return $this->to_unix_timestamp($trimmed);
        }

        return null;
    }

    /**
     * Detect whether command input targets slotbooking.
     *
     * @param  array $input
     * @return bool
     */
    private function is_slotbooking_input(array $input): bool {
        if (!empty($input['slot_enabled'])) {
            return true;
        }

        $optiontype = strtolower(trim((string)($input['optiontype'] ?? '')));
        if (in_array($optiontype, ['2', 'slot', 'slotbooking', 'slot-booking'], true)) {
            return true;
        }

        foreach (array_keys($input) as $key) {
            if (is_string($key) && str_starts_with($key, 'slot_')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect whether command input targets self-learning option type.
     *
     * @param  array $input
     * @return bool
     */
    private function is_selflearning_input(array $input): bool {
        if (!empty($input['selflearningcourse'])) {
            return true;
        }

        $optiontype = strtolower(trim((string)($input['optiontype'] ?? '')));
        return in_array($optiontype, ['1', 'selflearning', 'self-learning', 'selflearningcourse'], true);
    }

    /**
     * Collect free-text fields into one lowercased string for intent cues.
     *
     * @param  array $input
     * @return string
     */
    private function collect_text_fields(array $input): string {
        $chunks = [];
        foreach (['text', 'description', 'teacherquery', 'coursequery'] as $field) {
            if (isset($input[$field]) && is_string($input[$field])) {
                $chunks[] = $input[$field];
            }
        }
        return strtolower(trim(implode(' ', $chunks)));
    }

    /**
     * Extract a "maximum slot duration" hint from text and return seconds.
     *
     * @param  string $text
     * @return int|null
     */
    private function extract_max_duration_seconds(string $text): ?int {
        if ($text === '') {
            return null;
        }

        if (preg_match('/\b(eine\s+stunde|1\s*stunde|1h|60\s*min(?:uten)?)\b/u', $text)) {
            return 60 * 60;
        }

        if (preg_match('/\b(\d{1,3})\s*(?:min|minute|minuten)\b/u', $text, $m)) {
            return max(1, (int)$m[1]) * 60;
        }

        if (preg_match('/\b(\d{1,2})\s*(?:h|std|stunde|stunden)\b/u', $text, $m)) {
            return max(1, (int)$m[1]) * 3600;
        }

        return null;
    }

    /**
     * Convert flexible date input to unix timestamp.
     *
     * @param  mixed $value
     * @return int|null
     */
    private function to_unix_timestamp($value): ?int {
        $parsed = \mod_booking\local\wizard\booking\booking_skill_support::parse_datetime($value);
        return $parsed !== false ? $parsed : null;
    }
}
