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

use mod_booking\local\wizard\engine\queue_identity_provider_interface;
use mod_booking\local\wizard\engine\skill_trigger_provider_interface;
use mod_booking\local\wizard\booking\booking_skill_mutation_execute_service;
use mod_booking\local\wizard\booking\booking_skill_support;

/**
 * Task definition for booking.update_option_trainer.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_option_trainer_skill extends booking_skill_base implements
    queue_identity_provider_interface,
    skill_trigger_provider_interface {
    /** Task name constant. */
    public const TASK_NAME = 'mod_booking.update_option_trainer';

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(false, \mod_booking\local\wizard\engine\skill_risk_class::R2, ['mod/booking:addeditownoption']);
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
     * Human-readable preview of the trainer change (tier-3): target option + trainer(s).
     *
     * @param array $input Prepared input.
     * @return array|null
     */
    public function describe_proposed_action(array $input): ?array {
        return option_preview_builder::trainer_descriptor($input);
    }

    /**
     * Build queue business identity for trainer assignment deduplication.
     *
     * @param array $input
     * @return array<string,mixed>
     */
    public function build_queue_business_identity(array $input): array {
        $normalized = $input;

        $resolvedoptionid = (int)($normalized['resolvedoptionid'] ?? 0);
        $optionid = (int)($normalized['optionid'] ?? 0);
        $targetid = $resolvedoptionid > 0 ? $resolvedoptionid : $optionid;
        $targetquery = $this->normalize_identity_query((string)($normalized['optionquery'] ?? ''));
        $targetwhen = $this->normalize_identity_query((string)($normalized['optionwhen'] ?? ''));

        $teacherquery = $this->normalize_identity_query((string)($normalized['teacherquery'] ?? ''));
        $teacheremail = strtolower(trim((string)($normalized['teacheremail'] ?? '')));
        $teacherids = array_values(array_unique(array_filter(
            array_map('intval', (array)($normalized['teacherids'] ?? [])),
            static fn(int $id): bool => $id > 0
        )));

        return [
            'task_family' => self::TASK_NAME,
            'target' => [
                'optionid' => $targetid,
                'optionquery' => $targetquery,
                'optionwhen' => $targetwhen,
            ],
            'trainer_selector' => [
                'teacheremail' => $teacheremail,
                'teacherquery' => $teacherquery,
                'teacherids' => $teacherids,
            ],
        ];
    }

    /**
     * Return task schema.
     *
     * @return array
     */
    public function get_schema(): array {
        $schema = [
            'version' => 1,
            'description' => 'Assign or replace trainer(s) for an existing booking option. '
                . 'This task only updates trainer assignment and does not change other option fields.',
            'readonly' => $this->is_read_only(),
            'fallback_confirm_string_key' => 'ai_status_confirm_booking_update_option',
            'fallback_taskcall_string_key' => 'ai_status_taskcall_booking_update_option',
            'properties' => [
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
                'teacheremail' => [
                    'type' => 'string',
                    'description' => 'Comma-separated e-mail address(es) of trainer(s) to assign.',
                    'required' => false,
                ],
                'teacherquery' => [
                    'type' => 'string',
                    'description' => 'Search query to resolve one trainer by name/e-mail/id.',
                    'required' => false,
                ],
                'teacherids' => [
                    'type' => 'array',
                    'description' => 'Explicit Moodle user IDs of trainer(s) to assign.',
                    'required' => false,
                ],
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'Optional language code override for the user-facing summary, e.g. de or en.',
                    'required' => false,
                ],
            ],
            'prompt_meta' => [
                'input_fields_for_prompt' => ['optionquery', 'teacherquery', 'teacherids'],
                'anchor_fields' => ['option'],
            ],
        ];

        return $schema;
    }

    /**
     * Return task-specific message triggers.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'mod_booking.assign_trainers_to_option_dedicated',
                'description' => 'User asks to add, set, change, assign or replace trainer(s), '
                    . 'or instructor(s) for an existing booking option. '
                    . 'Use this task when the user wants to define who leads, teaches or trains a session. '

                    . 'Keywords: trainer assign, set trainer, assign trainer.',
                'examples' => [
                    'Set ANON_USER_1 as trainer for option First Aid Basics.',
                    'Assign trainer ids 42 and 77 to option 123.',
                    'Set ANON_USER_2 as the trainer for the course Project Management.',
                    'Make ANON_USER_3 the trainer for the next Yoga session.',
                    'Make ANON_USER the trainer of the Tuesday event.',
                    'Assign ANON_USER as the instructor for the workshop.',
                    'ANON_USER should be the trainer of the event on Wednesday.',
                ],
            ],
        ];
    }

    /**
     * Structural validation — pure, no DB access.
     *
     * @param array $input
     * @return array{valid:bool,errors:array<int,string>}
     */
    public function check_structure(array $input): array {
        $errors = [];

        if (empty($input['optionid']) && empty($input['optionquery'])) {
            $errors[] = get_string('agent_booking_update_option_missing_target', 'booking');
        }

        $hastrainerselector = !empty($input['teacheremail']) || !empty($input['teacherquery']) || !empty($input['teacherids']);
        if (!$hastrainerselector) {
            $errors[] = 'Trainer assignment requires one of teacheremail, teacherquery or teacherids.';
        }

        if (!empty($input['teacherids']) && !is_array($input['teacherids'])) {
            $errors[] = 'teacherids must be an array of integer user IDs.';
        }

        $allowedkeys = [
            'optionid',
            'optionquery',
            'optionwhen',
            'teacheremail',
            'teacherquery',
            'teacherids',
            'outputlang',
        ];

        foreach (array_keys($input) as $key) {
            if (!in_array((string)$key, $allowedkeys, true)) {
                $errors[] = 'This task only accepts trainer-assignment fields; unsupported field: ' . (string)$key . '.';
            }
        }

        if (!empty($errors)) {
            return ['valid' => false, 'errors' => array_values(array_unique($errors))];
        }

        return ['valid' => true, 'errors' => []];
    }

    /**
     * Deep preflight validation — DB lookups, option resolution, conflict detection.
     *
     * @param array $input
     * @param int $cmid
     * @param int $userid
     * @return array{status:string,prepared_input:array,issues:array}
     */
    protected function run_preflight(array $input, int $cmid, int $userid): array {
        $cmid = $this->resolve_cmid_from_context_or_cmid($cmid);
        $capdenied = $this->require_native_capability('mod/booking:addeditownoption', $cmid, $userid);
        if ($capdenied !== null) {
            return $capdenied;
        }
        global $DB;

        $lang = $this->get_output_language($input);
        $issues = [];

        // Command input is already deanonymized by the engine before preflight (executor /
        // preflight_pipeline), so optionquery is a real query here — no anon-token short-circuit.

        $preparedinput = $this->filter_prepared_input($input);

        if (empty($preparedinput['optionid'])) {
            if (empty($preparedinput['optionquery'])) {
                $issues[] = [
                    'code' => 'MISSING_TARGET_OPTION',
                    'severity' => 'needs_clarification',
                    'message' => $this->localized_string('agent_booking_update_option_missing_target', null, $lang),
                ];
                return $this->invalid($issues);
            }

            $result = booking_skill_support::resolve_single_option(
                $cmid,
                (string)$preparedinput['optionquery'],
                (string)($preparedinput['optionwhen'] ?? '')
            );

            if (($result['status'] ?? '') !== 'ok') {
                $issues[] = [
                    'code' => 'OPTION_RESOLUTION_FAILED',
                    'severity' => 'needs_clarification',
                    'message' => (string)($result['message'] ?? ''),
                ];
                return $this->invalid($issues);
            }

            $preparedinput['optionid'] = (int)$result['optionid'];
        } else {
            $cm = get_coursemodule_from_id('booking', $cmid);
            if (
                !$cm
                || !$DB->record_exists('booking_options', [
                    'id' => (int)$preparedinput['optionid'],
                    'bookingid' => (int)$cm->instance,
                ])
            ) {
                $issues[] = [
                    'code' => 'INVALID_OPTIONID',
                    'severity' => 'needs_clarification',
                    'message' => $this->localized_string(
                        'agent_booking_update_option_invalid_optionid',
                        (int)$preparedinput['optionid'],
                        $lang
                    ),
                ];
                return $this->invalid($issues);
            }
        }

        return $this->apply_service_preflight(self::TASK_NAME, $preparedinput, $cmid, $userid, $issues, $lang);
    }

    /**
     * Execute task using prepared_input from preflight().
     *
     * @param array $preparedinput
     * @param int $cmid
     * @param int $userid
     * @return array
     */
    public function execute(array $preparedinput, int $cmid, int $userid): array {
        $cmid = $this->resolve_cmid_from_context_or_cmid($cmid);
        $service = new booking_skill_mutation_execute_service($this->attachments());

        $filteredinput = $this->filter_prepared_input($preparedinput);
        $result = $service->execute(self::TASK_NAME, $filteredinput, $cmid, $userid, $this->support);
        if (is_array($result)) {
            $result['debugmessage'] = $this->build_task_debug_message(
                self::TASK_NAME,
                $filteredinput,
                ['Status: ' . ($result['status'] ?? 'unknown')]
            );
            return $result;
        }

        return [
            'status' => 'error',
            'detail' => $this->localized_string('agent_booking_unknown_task', self::TASK_NAME),
            'resultid' => null,
            'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $filteredinput, ['Status: error']),
        ];
    }

    /**
     * Verify that trainer-related fields were persisted as requested.
     *
     * @param array $input
     * @param object $settings
     * @return array
     */
    public function verify_persisted_option_state(array $input, object $settings): array {
        return option_input_verification::verify_common_fields($input, $settings);
    }

    /**
     * Keep only fields relevant for dedicated trainer assignment.
     *
     * @param array $input
     * @return array
     */
    private function filter_prepared_input(array $input): array {
        $allowedkeys = [
            'optionid',
            'optionquery',
            'optionwhen',
            'teacheremail',
            'teacherquery',
            'teacherids',
            'outputlang',
        ];

        $filtered = [];
        foreach ($allowedkeys as $key) {
            if (array_key_exists($key, $input)) {
                $filtered[$key] = $input[$key];
            }
        }

        return $filtered;
    }
}
