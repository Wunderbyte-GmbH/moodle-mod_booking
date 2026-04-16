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

use mod_booking\local\wbagent\conversation_store;
use mod_booking\local\wbagent\privacy_anonymizer;
use mod_booking\local\wbagent\booking\booking_task_mutation_execute_service;
use mod_booking\local\wbagent\booking\booking_task_support;
use mod_booking\local\wbagent\interfaces\task_trigger_provider_interface;

/**
 * Task definition for booking.update_option.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_option_task extends base_booking_task implements task_trigger_provider_interface {
    /** Task name constant. */
    public const TASK_NAME = 'booking.update_option';

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
            'description' => 'Update an existing booking option in the current booking instance.',
            'readonly' => $this->is_read_only(),
            'properties' => array_merge([
                'text' => [
                    'type' => 'string',
                    'description' => 'Title of the booking option (not the long description).',
                    'required' => false,
                ],
                'optionid' => [
                    'type' => 'integer',
                    'description' => 'ID of the booking option to update. If omitted, provide optionquery.',
                    'required' => false,
                ],
                'optionquery' => [
                    'type' => 'string',
                    'description' => 'Text query to resolve the target option by title/description/location.',
                    'required' => false,
                ],
                'optionwhen' => [
                    'type' => 'string',
                    'description' => 'Optional temporal hint for disambiguation (e.g. "next monday").',
                    'required' => false,
                ],
            ], option_schema_definition::common_properties()),
        ];
    }

    /**
     * Return task-specific message triggers.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'booking.use_preview_context_for_update',
                'description' => 'User refers to the previously previewed option(s) as the update target.',
            ],
            [
                'id' => 'booking.resolve_option_by_exact_query',
                'description' => 'User asks to target an option by an exact/specific query string.',
            ],
            [
                'id' => 'booking.option_resolution_ambiguous_clarify',
                'description' => 'User provides clarification to resolve an ambiguous option match.',
            ],
            [
                'id' => 'booking.option_resolution_failed_retry',
                'description' => 'User retries target resolution after previous option resolution failure.',
            ],
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
                'id' => 'booking.mutation_flow',
                'triggers' => [
                    'create option', 'update option', 'new option', 'change option',
                    'erstelle', 'anlegen', 'aktualisiere', 'update', 'setze', 'andern', 'ändern',
                ],
                'guidance' => [
                    '- In command input mapping: "text" means option title, "description" means long body text.',
                    '- If user names an existing option, use that text directly as optionquery.',
                    '- If user provides a title fragment (e.g. "contains Hannah Arendt"), still use optionquery directly.',
                    '- For hide/show requests, map to visibility/invisible:',
                    '  invisible|hidden -> invisible=1, visible -> invisible=0, direct-link-only -> invisible=2.',
                    '- Do not ask for optionid first unless optionquery resolution is ambiguous.',
                    '- For mutating requests, combine lookup and action in one command,',
                    '  e.g. booking.update_option with optionquery/teacherquery/coursequery.',
                    '- For mutating requests, do not ask for permission to run internal lookup steps.',
                    '- Do not output standalone search tasks as final action for mutating intent.',
                    '- For date additions on existing options, use optiondates with optiondatesmode=append '
                        . '(or omit optiondatesmode; append is default).',
                    '- Use confirmation_request for updates and follow structured validation issues when returned.',
                ],
            ],
        ];
    }

    /**
     * Validate task input.
     *
     * @param array<string,mixed> $input
     * @param int $cmid
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>,issues?:array<int,array<string,mixed>>}
     */
    public function validate(array $input, int $cmid): array {
        global $USER;
        global $DB;

        if (!empty($USER->id) && (int)$USER->id > 0) {
            $anonymizer = new privacy_anonymizer(new conversation_store());
            $input = $anonymizer->deanonymize_command_input_for_active_user($cmid, (int)$USER->id, $input);
        }

        $errors = [];
        $ambiguities = [];
                $issues = [];

        if (empty($input['optionid'])) {
            if (empty($input['optionquery'])) {
                $ambiguities[] = 'Which booking option should be updated? Provide optionid or optionquery.';
                $issues[] = [
                    'code' => 'MISSING_TARGET_OPTION',
                    'severity' => 'needs_clarification',
                    'user_question' => 'Which booking option should I update?',
                    'remedy_options' => ['PROVIDE_OPTIONQUERY', 'PROVIDE_OPTIONID'],
                ];
            } else if (booking_task_support::is_last_preview_selection_reference((string)$input['optionquery'])) {
                $previewids = booking_task_support::resolve_last_preview_option_ids_for_user_for_execute(
                    $cmid,
                    (int)($USER->id ?? 0)
                );
                if (empty($previewids)) {
                    $errors[] = 'No recently previewed booking options are available for this follow-up request.';
                    $issues[] = [
                        'code' => 'MISSING_PREVIEW_CONTEXT',
                        'severity' => 'needs_clarification',
                        'user_question' => 'I could not find recently shown options. Which option(s) should I update?',
                        'remedy_options' => ['PROVIDE_OPTIONQUERY', 'PROVIDE_OPTIONID'],
                    ];
                }
            } else if (!booking_task_support::is_last_option_reference((string)$input['optionquery'])) {
                $result = booking_task_support::resolve_single_option(
                    $cmid,
                    (string)$input['optionquery'],
                    (string)($input['optionwhen'] ?? '')
                );
                if ($result['status'] === 'error') {
                    $errors[] = (string)$result['message'];
                    $issues[] = [
                        'code' => 'OPTION_RESOLUTION_FAILED',
                        'severity' => 'needs_clarification',
                        'user_question' => 'I could not uniquely find the option to update. Please specify it more precisely.',
                        'remedy_options' => ['PROVIDE_MORE_SPECIFIC_OPTIONQUERY', 'PROVIDE_OPTIONID'],
                    ];
                } else if ($result['status'] === 'ambiguity') {
                    $ambiguities[] = (string)$result['message'];
                    $issues[] = [
                        'code' => 'OPTION_RESOLUTION_AMBIGUOUS',
                        'severity' => 'needs_clarification',
                        'user_question' => 'Multiple options match. Which one should I update?',
                        'remedy_options' => ['SELECT_EXACT_OPTION', 'PROVIDE_OPTIONID'],
                    ];
                }
            }
        } else {
            $cm = get_coursemodule_from_id('booking', $cmid);
            if (
                !$cm
                || !$DB->record_exists('booking_options', [
                    'id' => (int)$input['optionid'],
                    'bookingid' => $cm->instance,
                ])
            ) {
                $errors[] = 'Booking option with id ' . (int)$input['optionid']
                    . ' does not exist in this booking instance.';
                $issues[] = [
                    'code' => 'INVALID_OPTIONID',
                    'severity' => 'needs_clarification',
                    'user_question' => 'The selected option id does not exist here. Please provide a valid option.',
                    'remedy_options' => ['PROVIDE_VALID_OPTIONID', 'PROVIDE_OPTIONQUERY'],
                ];
            }
        }

        $preflight = (new booking_task_mutation_execute_service())->preflight_validate(
            self::TASK_NAME,
            $input,
            $cmid,
            (int)($USER->id ?? 0)
        );
        $errors = array_merge($errors, (array)($preflight['errors'] ?? []));
        $ambiguities = array_merge($ambiguities, (array)($preflight['ambiguities'] ?? []));

        return [
            'valid' => empty($errors) && empty($ambiguities),
            'errors' => $errors,
            'ambiguities' => $ambiguities,
            'issues' => $issues,
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

    /**
     * Execute task.
     *
     * @param array<string,mixed> $input
     * @param int $cmid
     * @param int $userid
     * @return array<string,mixed>
     */
    public function execute(array $input, int $cmid, int $userid): array {
        $service = new booking_task_mutation_execute_service();
        $result = $service->execute(self::TASK_NAME, $input, $cmid, $userid, $this->support);
        if (is_array($result)) {
            return $result;
        }

        return [
            'status' => 'error',
            'detail' => 'Unknown booking task: ' . self::TASK_NAME,
            'resultid' => null,
        ];
    }
}
