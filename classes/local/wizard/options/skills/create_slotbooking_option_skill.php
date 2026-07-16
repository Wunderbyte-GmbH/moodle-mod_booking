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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_booking\local\wizard\options\skills;

use mod_booking\local\wizard\booking\booking_skill_support;
use mod_booking\utils\wb_payment;

/**
 * Task definition for slot-based appointment options.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_slotbooking_option_skill extends create_option_skill {
    /** Task name constant. */
    public const TASK_NAME = 'mod_booking.create_slotbooking_option';

    /**
     * Return task name.
     *
     * @return string
     */
    public function get_name(): string {
        return self::TASK_NAME;
    }

    /**
     * Human-readable preview of the slot booking option to be created (tier-3 confirmation preview).
     *
     * Collapses the weekday flags and availability window into a compact summary + rows.
     *
     * @param array $input Prepared input.
     * @return array|null
     */
    public function describe_proposed_action(array $input): ?array {
        return option_preview_builder::slotbooking_descriptor($input);
    }

    /**
     * Build queue business identity for slotbooking create deduplication.
     *
     * Keeps identity focused on user-facing slot semantics so equivalent
     * requests hash to the same queue item even when payload formatting differs.
     *
     * @param array $input
     * @return array<string,mixed>
     */
    public function build_queue_business_identity(array $input): array {
        $title = $this->normalize_identity_string((string)($input['text'] ?? ''));
        $opening = $this->normalize_time_value((string)($input['slot_opening_time'] ?? ''));
        $closing = $this->normalize_time_value((string)($input['slot_closing_time'] ?? ''));
        $validfrom = booking_skill_support::normalize_identity_datetime((string)($input['slot_valid_from'] ?? ''));
        $validuntil = booking_skill_support::normalize_identity_datetime((string)($input['slot_valid_until'] ?? ''));
        $slotduration = max(0, (int)($input['slot_duration_minutes'] ?? 0));
        $slotcapacity = max(0, (int)($input['slot_max_participants_per_slot'] ?? 0));
        $slottype = strtolower(trim((string)($input['slot_type'] ?? 'fixed')));
        $customduration = max(0, (int)($input['slot_custom_max_duration'] ?? 0));
        $days = $this->extract_active_slot_days($input);

        return [
            'task_family' => 'mod_booking.create_slotbooking_option',
            'text' => $title,
            'slot_opening_time' => $opening,
            'slot_closing_time' => $closing,
            'slot_duration_minutes' => $slotduration,
            'slot_max_participants_per_slot' => $slotcapacity,
            'slot_valid_from' => $validfrom,
            'slot_valid_until' => $validuntil,
            'slot_type' => $slottype,
            'slot_custom_max_duration' => $customduration,
            'slot_days' => $days,
        ];
    }

    /**
     * Return task schema.
     *
     * @return array
     */
    public function get_schema(): array {
        $schema = parent::get_schema();
        $properties = is_array($schema['properties'] ?? null) ? (array)$schema['properties'] : [];

        // Expose ONLY slot-relevant + common booking fields so parameter construction faces a
        // small, unambiguous schema (the inherited create_option schema carries ~70 availability/
        // condition fields that overwhelm the constructor). optiontype/slot_enabled are set by this
        // skill in preflight/execute and stripped by the shared check_structure slotbooking branch,
        // so they are intentionally NOT prompt-facing.
        $allowed = array_flip([
            'text',
            'maxanswers', 'teacherquery', 'teacheremail', 'prices',
            'bookingopeningtime', 'bookingclosingtime', 'maxoverbooking',
            'override', 'outputlang', 'activityquery',
        ]);
        // Keep the core fields plus ALL slot_* properties (opening/closing/duration/interval/
        // validity/capacity AND the slot_day_1..7 weekday toggles) — they are all slot-relevant.
        $properties = array_filter(
            $properties,
            static fn($key): bool => isset($allowed[$key]) || str_starts_with((string)$key, 'slot_'),
            ARRAY_FILTER_USE_KEY
        );

        $schema['description'] = 'Create a slot-based booking option for appointment scheduling with reusable '
            . 'availability windows, slot duration, validity range and per-slot capacity. '
            . 'Use this canonical task for requests like consultation slots, court appointments, '
            . 'office-hour availability, or any recurring bookable time window. '
            . 'Do not use it for fixed dated event series with trainer/capacity and numbered titles '
            . '(for example Lecture 1..n on specific weekdays); those are normal dated options. '
            . 'Do not use it for single dated events or normal course sessions; those belong to the '
            . 'general create_option task.';
        $schema['properties'] = $properties;

        $schema['example_utterances'] = [
            'Create bookable consultation slots every Monday from 10:00 to 14:00',
            'Set up 30-minute appointment slots for office hours',
            'Make a slot-based option for court appointments next month',
            'Offer reusable availability windows people can book individual time slots in',
            'Create 25-minute meeting slots on Mondays and Wednesdays in July',
        ];

        // The inherited create_option schema does not declare governance; nothing to override here.
        unset($schema['governance']);

        return $schema;
    }

    /**
     * Return explicit planner prompt contract.
     *
     * @return array<string,mixed>
     */
    protected function prompt_contract_payload(): array {
        return [
            'intent' => 'create_slotbooking',
            'anchors' => ['option'],
            'minimal_input' => [
                'text',
                'slot_opening_time',
                'slot_closing_time',
                'slot_duration_minutes',
                'slot_valid_from',
                'slot_valid_until',
                'slot_max_participants_per_slot',
                'activityquery',
            ],
            'example_input' => [
                'text' => 'Georgs Zeit 1',
                'slot_opening_time' => '10:00',
                'slot_closing_time' => '14:00',
                'slot_duration_minutes' => 25,
                'slot_max_participants_per_slot' => 1,
                'slot_valid_from' => '2026-07-01',
                'slot_valid_until' => '2026-07-31',
                'slot_day_1' => true,
                'slot_day_3' => true,
            ],
            'namespace' => 'mod_booking',
            'version' => 1,
            'context_scopes' => ['module'],
        ];
    }

    /**
     * Return task-specific message triggers.
     *
     * @return array
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'mod_booking.create_slotbooking_request',
                'description' => 'User asks for slot/appointment booking with reusable availability windows and '
                    . 'slot duration. Route here when the user wants bookable appointment windows rather than '
                    . 'a single dated event. Convert weekday phrases to slot_day_1..slot_day_7 '
                    . '(Monday=1 ... Sunday=7) and set slot_max_participants_per_slot explicitly. '
                    . 'Do not route fixed weekday lecture/session series with numbered titles '
                    . '(Lecture x) to slotbooking.',
                'examples' => [
                    'Create my office hours every Monday and Wednesday from 10:00 to 14:00, '
                        . '25 minutes per slot, for the whole of July.',
                    'Mein Tennisplatz soll jeden Wochentag von 10 bis 18 Uhr buchbar sein, in 1h-Slots.',
                    'Create appointment slots where each participant picks any available 30-minute slot '
                        . 'inside a daily window next month.',
                    'Create appointment slots Monday to Friday from 09:00 to 17:00 for August.',
                    'Set up consultation slots every Wednesday afternoon for next month.',
                    'Build recurring office-hour availability in 30-minute windows for the next month.',
                ],
            ],
        ];
    }

    /**
     * Deep preflight validation for slotbooking-specific create flow.
     *
     * @param array $input
     * @param int $contextid Operating context resolved by the engine (parent resolves the cmid).
     * @param int $userid
     * @return array{status:string,prepared_input:array,issues:array}
     */
    protected function run_preflight(array $input, int $contextid, int $userid): array {
        if (!wb_payment::pro_version_is_activated()) {
            return $this->invalid([[
                'code' => 'SLOTBOOKING_REQUIRES_PRO',
                'severity' => 'needs_clarification',
                'message' => get_string('proversiononly', 'mod_booking'),
            ]]);
        }
        unset($input['selflearningcourse'], $input['duration'], $input['disablecancel']);
        $input['optiontype'] = 'slotbooking';
        $input['slot_enabled'] = true;
        // Forward the operating context unchanged; parent::preflight resolves it to the cmid.
        return parent::run_preflight($input, $contextid, $userid);
    }

    /**
     * Execute task using prepared input from preflight.
     *
     * @param array $preparedinput
     * @param int $contextid Operating context resolved by the engine (parent resolves the cmid).
     * @param int $userid
     * @return array
     */
    public function execute(array $preparedinput, int $contextid, int $userid): array {
        unset($preparedinput['selflearningcourse'], $preparedinput['duration'], $preparedinput['disablecancel']);
        $preparedinput['optiontype'] = 'slotbooking';
        $preparedinput['slot_enabled'] = true;
        // Forward the operating context unchanged; parent::execute resolves it to the cmid.
        return parent::execute($preparedinput, $contextid, $userid);
    }

    /**
     * Normalize title-like identity string.
     *
     * @param string $value
     * @return string
     */
    private function normalize_identity_string(string $value): string {
        $value = strtolower(trim($value));
        $value = preg_replace('/\s+/', ' ', $value);
        return trim((string)$value);
    }



    /**
     * Normalize HH:MM-like time values for signature identity.
     *
     * @param string $value
     * @return string
     */
    private function normalize_time_value(string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (preg_match('/^(\d{1,2}):(\d{2})$/', $value, $matches)) {
            $hours = max(0, min(23, (int)$matches[1]));
            $minutes = max(0, min(59, (int)$matches[2]));
            return sprintf('%02d:%02d', $hours, $minutes);
        }

        return strtolower($value);
    }

    /**
     * Extract active slot weekdays as sorted day numbers (1=Mon ... 7=Sun).
     *
     * @param array $input
     * @return array<int,int>
     */
    private function extract_active_slot_days(array $input): array {
        $days = [];

        for ($day = 1; $day <= 7; $day++) {
            $key = 'slot_day_' . $day;
            if ($this->is_truthy_day_value($input[$key] ?? null, $day)) {
                $days[] = $day;
            }
        }

        $weekdaytokens = $input['weekdays'] ?? null;
        if (is_string($weekdaytokens) && trim($weekdaytokens) !== '') {
            $weekdaytokens = preg_split('/\s*,\s*|\s+and\s+/i', trim($weekdaytokens)) ?: [];
        }
        if (is_array($weekdaytokens)) {
            foreach ($weekdaytokens as $token) {
                $mapped = $this->map_weekday_token_to_number((string)$token);
                if ($mapped > 0) {
                    $days[] = $mapped;
                }
            }
        }

        $days = array_values(array_unique(array_filter($days, static fn(int $value): bool => $value >= 1 && $value <= 7)));
        sort($days);
        return $days;
    }

    /**
     * Determine whether a slot day value should count as active.
     *
     * @param mixed $value
     * @param int $day
     * @return bool
     */
    private function is_truthy_day_value($value, int $day): bool {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int)$value > 0;
        }
        if (!is_string($value)) {
            return false;
        }

        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return false;
        }
        if (in_array($normalized, ['1', 'true', 'yes', 'on', 'active'], true)) {
            return true;
        }

        return $this->map_weekday_token_to_number($normalized) === $day;
    }

    /**
     * Map weekday token to slot day number (1=Mon ... 7=Sun).
     *
     * @param string $token
     * @return int
     */
    private function map_weekday_token_to_number(string $token): int {
        $token = strtolower(trim($token));
        if ($token === '') {
            return 0;
        }

        $map = [
            'monday' => 1,
            'mon' => 1,
            'montag' => 1,
            'dienstag' => 2,
            'tuesday' => 2,
            'tue' => 2,
            'di' => 2,
            'mittwoch' => 3,
            'wednesday' => 3,
            'wed' => 3,
            'mi' => 3,
            'donnerstag' => 4,
            'thursday' => 4,
            'thu' => 4,
            'do' => 4,
            'freitag' => 5,
            'friday' => 5,
            'fri' => 5,
            'fr' => 5,
            'samstag' => 6,
            'saturday' => 6,
            'sat' => 6,
            'sa' => 6,
            'sonntag' => 7,
            'sunday' => 7,
            'sun' => 7,
            'so' => 7,
        ];

        return (int)($map[$token] ?? 0);
    }
}
