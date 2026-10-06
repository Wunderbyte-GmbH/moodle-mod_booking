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


/**
 * Task definition for self-learning booking options.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_selflearning_option_skill extends create_option_skill {
    /** Task name constant. */
    public const TASK_NAME = 'mod_booking.create_selflearning_option';

    /**
     * Return task name.
     *
     * @return string
     */
    public function get_name(): string {
        return self::TASK_NAME;
    }

    /**
     * Build queue business identity for selflearning create deduplication.
     *
     * @param array $input
     * @return array<string,mixed>
     */
    public function build_queue_business_identity(array $input): array {
        $title = $this->normalize_identity_string((string)($input['text'] ?? ''));
        $duration = max(0, (int)($input['duration'] ?? 0));
        $maxanswers = max(0, (int)($input['maxanswers'] ?? 0));
        $teacherquery = $this->normalize_identity_query((string)($input['teacherquery'] ?? ''));
        $teacheremail = strtolower(trim((string)($input['teacheremail'] ?? '')));

        return [
            'task_family' => 'mod_booking.create_selflearning_option',
            'text' => $title,
            'duration' => $duration,
            'maxanswers' => $maxanswers,
            'teacherquery' => $teacherquery,
            'teacheremail' => $teacheremail,
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

        // Self-learning options are duration-based, not slot/date based. Expose ONLY the fields a
        // self-learning offer actually needs, so parameter construction faces a small, unambiguous
        // schema. The inherited create_option schema carries ~70 availability/condition fields that
        // overwhelm the constructor (cause of intermittent "no commands"/extra-key failures).
        // optiontype/selflearningcourse are set by this skill itself in preflight/execute and are
        // deliberately NOT prompt-facing.
        $allowed = array_flip([
            'text', 'description', 'duration', 'maxanswers', 'teacherquery', 'teacheremail', 'prices',
            'bookingopeningtime', 'bookingclosingtime', 'maxoverbooking', 'disablecancel',
            // Skill-internal type flags: this skill sets them in preflight/execute, so they
            // must stay valid schema keys (the shared validator rejects unknown keys, and the
            // planner naturally emits them for a self-learning request).
            'optiontype', 'selflearningcourse',
            'override', 'outputlang', 'activityquery', 'cmid', 'linkedcoursequery',
        ]);
        $properties = array_intersect_key($properties, $allowed);

        // Two sentences that together stay inside the 240-character card window, so the card carries the
        // purpose AND the use case; a third sentence would be dropped whole.
        $schema['description'] = 'Create a SELF-LEARNING booking option (duration in seconds, no dates, no slots) '
            . 'for duration-based participation. '
            . 'Use it when the user wants a self-paced or e-learning offer with a duration '
            . '(for example 2h, 4h or 14400 seconds).';
        $schema['is'] = 'Duration-based, self-paced offers without fixed dates.';
        $schema['not'] = 'Dated events or session series (create_option); appointment slots (create_slotbooking_option).';
        $schema['properties'] = self::describe_scoped_creation_fields($properties);

        return $schema;
    }

    /**
     * Return task-specific message triggers.
     *
     * @return array
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'mod_booking.create_selflearning_request',
                'description' => 'The user wants a self-learning or e-learning option with a participation duration and no fixed'
                    . ' appointment dates.',
                'examples' => [
                    'Create a self-learning course with a learning duration of 4 hours.',
                    'Create a self-learning booking option for 2 hours duration.',
                    'I need a self-paced learning option people can complete within 3 hours.',
                    'Please create a course-like booking option that lasts one afternoon and is not tied to time slots.',
                ],
            ],
        ];
    }

    /**
     * Deep preflight validation for self-learning-specific create flow.
     *
     * @param array $input
     * @param int $contextid Operating context resolved by the engine (parent resolves the cmid).
     * @param int $userid
     * @return array{status:string,prepared_input:array,issues:array}
     */
    protected function run_preflight(array $input, int $contextid, int $userid): array {
        unset($input['slot_enabled']);
        foreach (array_keys($input) as $key) {
            if (is_string($key) && str_starts_with($key, 'slot_')) {
                unset($input[$key]);
            }
        }

        $input['optiontype'] = 'selflearning';
        $input['selflearningcourse'] = true;
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
        unset($preparedinput['slot_enabled']);
        foreach (array_keys($preparedinput) as $key) {
            if (is_string($key) && str_starts_with($key, 'slot_')) {
                unset($preparedinput[$key]);
            }
        }

        $preparedinput['optiontype'] = 'selflearning';
        $preparedinput['selflearningcourse'] = true;
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
}
