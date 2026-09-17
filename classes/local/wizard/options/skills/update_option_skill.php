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

use mod_booking\local\wizard\booking\booking_skill_mutation_execute_service;
use mod_booking\local\wizard\booking\booking_skill_support;
use mod_booking\local\wizard\engine\queue_identity_provider_interface;
use mod_booking\local\wizard\engine\skill_trigger_provider_interface;

/**
 * Task definition for booking.update_option.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_option_skill extends booking_skill_base implements
    queue_identity_provider_interface,
    skill_trigger_provider_interface {
    use option_targeted_skill;

    /** Task name constant. */
    public const TASK_NAME = 'mod_booking.update_option';

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
     * Human-readable preview of the option update (tier-3): target option + changed fields only.
     *
     * @param array $input Prepared input (carries the resolved optionid).
     * @return array|null
     */
    public function describe_proposed_action(array $input): ?array {
        return option_preview_builder::update_descriptor($input);
    }

    /**
     * Build queue business identity for update_option deduplication.
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

        foreach (
            [
                'resolvedoptionid',
                'optionid',
                'optionquery',
                'optionwhen',
                'outputlang',
                'override',
            ] as $key
        ) {
            unset($normalized[$key]);
        }

        return [
            'task_family' => 'mod_booking.update_option',
            'target' => [
                'optionid' => $targetid,
                'optionquery' => $targetquery,
                'optionwhen' => $targetwhen,
            ],
            'changes' => $this->normalize_identity_value($normalized),
        ];
    }

    /**
     * Return representative example input for the construction-phase catalog.
     *
     * Overrides base_skill default so the LLM sees all commonly used parameters
     * (including headerimage_token) in the construction-phase skill catalog.
     *
     * @return array<string,mixed>
     */
    public function get_example_input(): array {
        return [
            'optionquery'      => 'Code Swap',
            'text'             => 'New option title',
            'headerimage_token' => 'tok_abc123',
        ];
    }

    /**
     * Return task schema.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'Update an existing booking option in the current booking instance. '
                . 'Also links a Moodle course to the option (coursequery) or sets its header image '
                . '(headerimage_token).',
            'readonly' => $this->is_read_only(),
            'fallback_confirm_string_key' => 'ai_status_confirm_booking_update_option',
            'fallback_taskcall_string_key' => 'ai_status_taskcall_booking_update_option',
            'example_utterances' => [
                'Change the price of the yoga course to 50 euros',
                'Rename the "Spring Workshop" option to "Summer Workshop"',
                'Move the start date of the cooking class to next Friday',
                'Set this uploaded picture as the header image of the option',
                'Update the description of the First Aid Course',
                'Hide the Tuesday option from the list',
                'Connect the booking option to a Moodle course',
                'Is the course linked to this booking option? Link it if not',
            ],
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
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'Optional language code override for the user-facing summary, e.g. de or en.',
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
                'id' => 'mod_booking.set_header_image',
                'description' => 'User wants to add, set or replace a booking option\'s header/cover/title image '
                    . '(e.g. from an uploaded image attachment). This is the skill for attaching images to options.',
            ],
            [
                'id' => 'mod_booking.use_preview_context_for_update',
                'description' => 'User refers to the previously previewed option(s) as the update target.',
            ],
            [
                'id' => 'mod_booking.resolve_option_by_exact_query',
                'description' => 'User asks to target an option by an exact/specific query string.',
            ],
            [
                'id' => 'mod_booking.option_resolution_ambiguous_clarify',
                'description' => 'User provides clarification to resolve an ambiguous option match.',
            ],
            [
                'id' => 'mod_booking.option_resolution_failed_retry',
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
                'id' => 'mod_booking.mutation_flow',
                'triggers' => [
                    'create option', 'update option', 'new option', 'change option',
                    'create', 'update', 'set', 'modify', 'edit',
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
                    '- Do not ask for slot details when the user asks to book participants into a normal option.',
                    '- For mutating requests, do not ask for permission to run internal lookup steps.',
                    '- Do not output standalone search tasks as final action for mutating intent.',
                    '- For date additions on existing options, use optiondates with optiondatesmode=append '
                        . '(or omit optiondatesmode; append is default).',
                    '- Use confirmation_request for updates and follow structured validation issues when returned.',
                ],
            ],
            $this->header_image_attachment_prompt_pack(),
            [
                'id' => 'mod_booking.multi_option_disambiguation',
                'triggers' => [
                    'first', 'second', 'third', 'both', 'each', 'respective',
                    'same name', 'multiple', 'several',
                ],
                'guidance' => [
                    '- When several booking options share the same name, FIRST call the search_options skill'
                        . ' to obtain their concrete option IDs, then issue one update command per option using'
                        . " 'optionid'. Do not guess IDs and do not rely on optionquery for same-named options.",
                    '- When issuing multiple update commands for options that share the same name,'
                        . " you MUST use 'optionid' (not 'optionquery') in each command to differentiate them.",
                    '- Never repeat the same \'optionquery\' value across two commands in the same response'
                        . ' — the option resolver treats each command independently and will return AMBIGUOUS for both.',
                    '- If the conversation history or observations already contain option IDs'
                        . ' (e.g. from get_option_details or an "OPTION_RESOLUTION_AMBIGUOUS" error listing matching IDs),'
                        . " use those 'optionid' values directly in the command parameters.",
                    '- Map items (images, dates, etc.) to options in the order the user implied:'
                        . ' first item → first option (lowest id or as stated in context),'
                        . ' second item → second option, and so on.',
                    '- Example for two same-named options with IDs 1422 and 1423:'
                        . ' Command 1: {"optionid": 1422, "headerimage_token": "token_A"},'
                        . ' Command 2: {"optionid": 1423, "headerimage_token": "token_B"}.',
                ],
            ],
        ];
    }

    /**
     * Structural validation — pure, no DB access.
     *
     * Checks that at least one option-target field is present.
     *
     * @param  array $input
     * @return array{valid:bool,errors:array<int,string>}
     */
    public function check_structure(array $input): array {
        if (empty($input['optionid']) && empty($input['optionquery'])) {
            return [
                'valid'  => false,
                'errors' => [get_string('agent_booking_update_option_missing_target', 'booking')],
            ];
        }

        $commonerrors = $this->validate_common_mutation_structure($input, false);
        if (!empty($commonerrors)) {
            return [
                'valid' => false,
                'errors' => $commonerrors,
            ];
        }

        return ['valid' => true, 'errors' => []];
    }

    /**
     * Deep preflight validation — DB lookups, option resolution, conflict detection.
     *
     * Returns prepared_input with 'optionid' resolved so execute() needs no
     * further lookup.  Does NOT perform writes.
     *
     * @param  array $input
     * @param  int   $cmid
     * @param  int   $userid
     * @return array{status:string,prepared_input:array,issues:array}
     */
    protected function run_preflight(array $input, int $cmid, int $userid): array {
        $lang = $this->get_output_language($input);

        // The option_targeted_skill trait resolves the operating context from the named option, so
        // this works from an activity page, the dashboard, or MCP (system context) alike.
        $resolved = $this->resolve_option_operating_context($input, $cmid, 'mod/booking:addeditownoption', $userid, $lang);
        if (isset($resolved['clarification'])) {
            return $resolved['clarification'];
        }
        $cmid = $resolved['cmid'];

        global $DB;

        $issues = [];

        // Note: command input is already deanonymized by the engine before preflight runs
        // (executor::deanonymize_command_input / preflight_pipeline), so optionquery is always a
        // real query here — no anonymized-token short-circuit needed at the skill level.

        $preparedinput = $input;

        if (empty($input['optionid'])) {
            if (empty($input['optionquery'])) {
                $issues[] = [
                    'code'          => 'MISSING_TARGET_OPTION',
                    'severity'      => 'needs_clarification',
                    'message'       => $this->localized_string('agent_booking_update_option_missing_target', null, $lang),
                    'user_question' => $this->localized_string(
                        'agent_booking_update_option_which_option_question',
                        null,
                        $lang
                    ),
                    'remedy_options' => ['PROVIDE_OPTIONQUERY', 'PROVIDE_OPTIONID'],
                ];
                return $this->invalid($issues);
            } else if (booking_skill_support::is_last_preview_selection_reference((string)$input['optionquery'])) {
                $previewids = booking_skill_support::resolve_last_preview_option_ids_for_user_for_execute(
                    $cmid,
                    $userid
                );
                if (empty($previewids)) {
                    $issues[] = [
                        'code'          => 'MISSING_PREVIEW_CONTEXT',
                        'severity'      => 'needs_clarification',
                        'message'       => $this->localized_string(
                            'agent_booking_update_option_missing_preview_context',
                            null,
                            $lang
                        ),
                        'user_question' => $this->localized_string(
                            'agent_booking_update_option_missing_preview_question',
                            null,
                            $lang
                        ),
                        'remedy_options' => ['PROVIDE_OPTIONQUERY', 'PROVIDE_OPTIONID'],
                    ];
                    return $this->invalid($issues);
                }
                // Resolve preview IDs into prepared_input.
                if (count($previewids) === 1) {
                    $preparedinput['optionid'] = (int)reset($previewids);
                } else {
                    $preparedinput['optionids'] = array_values(array_map('intval', $previewids));
                }
            } else if (!booking_skill_support::is_last_option_reference((string)$input['optionquery'])) {
                $result = booking_skill_support::resolve_single_option(
                    $cmid,
                    (string)$input['optionquery'],
                    (string)($input['optionwhen'] ?? '')
                );
                if ($result['status'] === 'error') {
                    $issues[] = [
                        'code'          => 'OPTION_RESOLUTION_FAILED',
                        'severity'      => 'needs_clarification',
                        'message'       => (string)$result['message'],
                        'user_question' => $this->localized_string(
                            'agent_booking_update_option_resolution_failed_question',
                            null,
                            $lang
                        ),
                        'remedy_options' => ['PROVIDE_MORE_SPECIFIC_OPTIONQUERY', 'PROVIDE_OPTIONID'],
                    ];
                    return $this->invalid($issues);
                } else if ($result['status'] === 'ambiguity') {
                    $issues[] = [
                        'code'          => 'OPTION_RESOLUTION_AMBIGUOUS',
                        'severity'      => 'needs_clarification',
                        'message'       => (string)$result['message'],
                        'user_question' => $this->localized_string(
                            'agent_booking_update_option_resolution_ambiguous_question',
                            null,
                            $lang
                        ),
                        'remedy_options' => ['SELECT_EXACT_OPTION', 'PROVIDE_OPTIONID'],
                    ];
                    return $this->invalid($issues);
                } else if ($result['status'] === 'ok') {
                    // Store resolved ID in prepared_input.
                    $preparedinput['optionid'] = (int)$result['optionid'];
                }
            }
        } else {
            // Verify explicit optionid belongs to this booking instance.
            $cm = get_coursemodule_from_id('booking', $cmid);
            if (
                !$cm
                || !$DB->record_exists('booking_options', [
                    'id'        => (int)$input['optionid'],
                    'bookingid' => $cm->instance,
                ])
            ) {
                $issues[] = [
                    'code'          => 'INVALID_OPTIONID',
                    'severity'      => 'needs_clarification',
                    'message'       => $this->localized_string(
                        'agent_booking_update_option_invalid_optionid',
                        (int)$input['optionid'],
                        $lang
                    ),
                    'user_question' => $this->localized_string(
                        'agent_booking_update_option_invalid_optionid_question',
                        (int)$input['optionid'],
                        $lang
                    ),
                    'remedy_options' => ['PROVIDE_VALID_OPTIONID', 'PROVIDE_OPTIONQUERY'],
                ];
                return $this->invalid($issues);
            }
        }

        // Run service-level preflight (teacher resolution, dates, etc.) and enrich prepared_input.
        return $this->apply_service_preflight(self::TASK_NAME, $preparedinput, $cmid, $userid, $issues, $lang);
    }

    /**
     * Verify that relevant fields were persisted as requested.
     *
     * @param array $input
     * @param object $settings
     * @return array
     */
    public function verify_persisted_option_state(array $input, object $settings): array {
        return option_input_verification::verify_common_fields($input, $settings);
    }

    /**
     * Execute task using prepared_input from preflight().
     *
     * prepared_input already contains a resolved 'optionid' (or 'optionids' for
     * multi-preview cases) so the mutation service needs no further resolution.
     *
     * @param  array $preparedinput  Resolved input from preflight().
     * @param  int   $cmid
     * @param  int   $userid
     * @return array
     */
    public function execute(array $preparedinput, int $cmid, int $userid): array {
        $cmid = $this->resolve_cmid_from_context_or_cmid($cmid);
        $service = new booking_skill_mutation_execute_service($this->attachments());
        $result = $service->execute(self::TASK_NAME, $preparedinput, $cmid, $userid, $this->support);
        if (is_array($result)) {
            $result['debugmessage'] = $this->build_task_debug_message(
                self::TASK_NAME,
                $preparedinput,
                ['Status: ' . ($result['status'] ?? 'unknown')]
            );
            return $result;
        }

        return [
            'status' => 'error',
            'detail' => $this->localized_string('agent_booking_unknown_task', self::TASK_NAME),
            'resultid' => null,
            'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $preparedinput, ['Status: error']),
        ];
    }
}
