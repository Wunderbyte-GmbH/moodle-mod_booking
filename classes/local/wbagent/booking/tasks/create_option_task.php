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

namespace mod_booking\local\wbagent\booking\tasks;

use mod_booking\local\wbagent\booking\booking_task_support;
use mod_booking\local\wbagent\booking\support\booking_mutation_validation;

/**
 * Task definition for booking.create_option.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_option_task extends base_booking_task {
    /** Task name constant. */
    public const TASK_NAME = 'booking.create_option';

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(false);
    }

    /**
     * Return task name.
     *
     * @return string
     */
    public function get_name(): string {
        return self::TASK_NAME;
    }

    /**
     * Return task schema.
     *
     * @return array<string,mixed>
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'Create a new booking option inside the current booking instance.',
            'readonly' => $this->is_read_only(),
            'properties' => array_merge([
                'text' => [
                    'type' => 'string',
                    'description' => 'Title of the new booking option.',
                    'required' => true,
                ],
            ], option_schema_definition::common_properties()),
        ];
    }

    /**
     * Validate task input.
     *
     * @param array<string,mixed> $input
     * @param int $cmid
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>}
     */
    public function validate(array $input, int $cmid): array {
        $errors = [];
        $ambiguities = [];

        if (empty($input['text'])) {
            $errors[] = 'Field "text" (option title) is required for create_option.';
        } else {
            $duplicatecheck = booking_task_support::find_existing_options_by_exact_title($cmid, (string)$input['text']);
            if (($duplicatecheck['status'] ?? '') === 'single') {
                $ambiguities[] = get_string(
                    'agent_booking_create_option_exists_single',
                    'booking',
                    (int)$duplicatecheck['optionid']
                );
            } else if (($duplicatecheck['status'] ?? '') === 'multiple') {
                $ambiguities[] = get_string(
                    'agent_booking_create_option_exists_multiple',
                    'booking',
                    (string)($duplicatecheck['candidates'] ?? '')
                );
            }
        }

        $common = booking_mutation_validation::validate_common($input, $cmid, self::TASK_NAME);
        $errors = array_merge($errors, $common['errors']);
        $ambiguities = array_merge($ambiguities, $common['ambiguities']);

        return [
            'valid' => empty($errors) && empty($ambiguities),
            'errors' => $errors,
            'ambiguities' => $ambiguities,
        ];
    }

    /**
     * Return contextual guidance packs.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_contextual_prompt_packs(): array {
        return [
            [
                'id' => 'booking.course_teacher',
                'triggers' => ['course', 'kurs', 'teacher', 'dozent', 'trainer'],
                'guidance' => [
                    '- Use coursequery to connect an option to a Moodle course.',
                    '- Use teacherquery or teacheremail to assign responsible teacher.',
                ],
            ],
            [
                'id' => 'booking.availability_conditions',
                'triggers' => [
                    'availability', 'verfugbarkeit', 'verfügbarkeit', 'enrolled', 'cohort', 'competency',
                    'previously booked', 'overlapping', 'profile field', 'condition', 'einschrankung', 'einschränkung',
                ],
                'guidance' => [
                    '- Use enrolledincoursequery (+ optional enrolledincourseoperator) for enrolled-in-course condition.',
                    '- Use enrolledincohortquery (+ optional enrolledincohortoperator) for cohort condition.',
                    '- Use hascompetencyquery (+ optional hascompetencyoperator) for competency condition.',
                    '- Use previouslybookedquery (+ optional previouslybookedrequirecompletion) for prerequisites.',
                    '- Use selectusersquery for explicit allowlist condition.',
                    '- Use nooverlappingmode with "block" or "warn".',
                    '- Use allowedtobookininstance (+ optional allowedtobookininstancecapabilitynotneeded).',
                    '- Use userprofilestandard* and userprofilecustom* fields for profile-based conditions.',
                ],
            ],
            [
                'id' => 'booking.selflearning_cancel',
                'triggers' => ['self-learning', 'selflearning', 'duration', 'hours', 'cancel', 'storno', 'stornieren'],
                'guidance' => [
                    '- For self-learning options use selflearningcourse=true with duration in seconds (e.g. 4h = 14400).',
                    '- To allow self-cancellation, keep disablecancel absent or false.',
                    '- Set disablecancel=true to prevent participants from cancelling themselves.',
                ],
            ],
            [
                'id' => 'booking.bookusers',
                'triggers' => ['book user', 'book users', 'buche', 'teilnehmer buchen', 'assign user', 'enrol user'],
                'guidance' => [
                    '- To book users directly to an option, use bookusersquery in booking.create_option'
                        . ' or booking.update_option.',
                    '- If the user already provided a person name (e.g. "Billy Teachy"), pass it directly as bookusersquery.',
                    '- For utterances like "buche <person> in die option <option>", map <person> -> bookusersquery and'
                        . ' <option> -> optionquery.',
                    '- Do not ask for user id or e-mail when a name query is already present.',
                    '- Ask for a more specific user identifier only after a real ambiguity (multiple matched users).',
                    '- Optional fields: bookuserstimebooked, bookuserscompleted, bookusersupdateexisting.',
                    '- For pure booking in booking.update_option, do not include'
                        . ' additional option-update fields in the same command.',
                ],
            ],
            [
                'id' => 'booking.datetime',
                'triggers' => ['date', 'time', 'datum', 'uhrzeit', 'tomorrow', 'today', 'next', 'morgen', 'heute'],
                'guidance' => [
                    '- Resolve relative dates against current Moodle timezone and current datetime from system prompt.',
                    '- Prefer ISO 8601 for date/time fields in command input.',
                    '- Never use old hardcoded timestamps from examples.',
                    '- If a resolved date appears in the past and user did not ask for past dates, ask clarification.',
                ],
            ],
            [
                'id' => 'booking.customform',
                'triggers' => [
                    'custom form', 'customform', 'formular', 'form element', 'formelement', 'bo_cond',
                    'checkbox', 'dropdown', 'select element',
                ],
                'guidance' => [
                    '- For custom form conditions, use "customformelements" with one item per row.',
                    '- Supported formtype values: advcheckbox, static, shorttext, select, url, mail,'
                        . ' deleteinfoscheckboxuser, enrolusersaction.',
                    '- Each customformelements item can include: label, value, required, enroluserstowaitinglist.',
                    '- For formtype "select", value must be a multiline string with one option per line.',
                    '- Select line formats: key => Display name; key => Display name => Max bookings => Price => Allowed user IDs.',
                    '- Key must not contain spaces or special characters.',
                ],
            ],
        ];
    }

    /**
     * Verify that relevant fields were persisted as requested.
     *
     * @param array<string,mixed> $input
     * @param object $settings
     * @return array<int,string>
     */
    public function verify_persisted_option_state(array $input, object $settings): array {
        return option_input_verification::verify_common_fields($input, $settings);
    }
}
