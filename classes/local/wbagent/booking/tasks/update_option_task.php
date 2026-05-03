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

use mod_booking\local\wbagent\booking\booking_task_mutation_execute_service;
use mod_booking\local\wbagent\booking\booking_task_support;
use mod_booking\local\wbagent\interfaces\task_trigger_provider_interface;
use mod_booking\local\wbagent\privacy_anonymizer;
use mod_booking\local\wbagent\task_preflight_result;

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
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'Update an existing booking option in the current booking instance.',
            'readonly' => $this->is_read_only(),
            'fallback_confirm_string_key' => 'ai_status_confirm_booking_update_option',
            'fallback_taskcall_string_key' => 'ai_status_taskcall_booking_update_option',
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
                'errors' => [get_string('agent_booking_update_option_missing_target', 'mod_booking')],
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
     * @return task_preflight_result
     */
    public function preflight(array $input, int $cmid, int $userid): task_preflight_result {
        global $DB;

        $lang = $this->get_output_language($input);
        $issues = [];

        // If optionquery is an anonymized token, skip semantic resolution here —
        // the executor will handle real deanonymization.
        if (
            !empty($input['optionquery'])
            && privacy_anonymizer::looks_like_anon_token((string)$input['optionquery'])
        ) {
            return task_preflight_result::ok($input);
        }

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
                return task_preflight_result::invalid($issues);

            } else if (booking_task_support::is_last_preview_selection_reference((string)$input['optionquery'])) {
                $previewids = booking_task_support::resolve_last_preview_option_ids_for_user_for_execute(
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
                    return task_preflight_result::invalid($issues);
                }
                // Resolve preview IDs into prepared_input.
                if (count($previewids) === 1) {
                    $preparedinput['optionid'] = (int)reset($previewids);
                } else {
                    $preparedinput['optionids'] = array_values(array_map('intval', $previewids));
                }

            } else if (!booking_task_support::is_last_option_reference((string)$input['optionquery'])) {
                $result = booking_task_support::resolve_single_option(
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
                    return task_preflight_result::invalid($issues);
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
                    return task_preflight_result::invalid($issues);
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
                return task_preflight_result::invalid($issues);
            }
        }

        // Run service-level preflight (teacher resolution, dates, etc.) and enrich prepared_input.
        $service = new booking_task_mutation_execute_service();
        $servicepreflight = $service->preflight_validate(self::TASK_NAME, $preparedinput, $cmid, $userid);
        if (!empty($servicepreflight['errors']) || !empty($servicepreflight['ambiguities'])) {
            foreach ((array)($servicepreflight['errors'] ?? []) as $err) {
                $issues[] = [
                    'code'     => 'PREFLIGHT_ERROR',
                    'severity' => 'needs_clarification',
                    'message'  => (string)$err,
                ];
            }
            foreach ((array)($servicepreflight['ambiguities'] ?? []) as $amb) {
                $issues[] = [
                    'code'     => 'PREFLIGHT_AMBIGUITY',
                    'severity' => 'needs_clarification',
                    'message'  => (string)$amb,
                ];
            }
            return task_preflight_result::invalid($issues);
        }

        // Use enriched input (e.g. optionid resolved from query by the service).
        if (is_array($servicepreflight['normalized_input'] ?? null)) {
            $preparedinput = (array)$servicepreflight['normalized_input'];
        }

        return task_preflight_result::ok($preparedinput);
    }

    /**
     * Legacy validate — delegates to preflight() for backward-compatibility.
     *
     * Called only by the executor's stale-state guard.  New callers should
     * use preflight() directly.
     *
     * @param  array $input
     * @param  int   $cmid
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>,issues:array}
     * @deprecated since 2026 — use preflight() instead.
     */
    public function validate(array $input, int $cmid): array {
        global $USER;
        $result = $this->preflight($input, $cmid, (int)($USER->id ?? 0));

        $errors = [];
        $ambiguities = [];
        foreach ($result->issues as $issue) {
            $msg = (string)($issue['message'] ?? '');
            if ($msg === '') {
                continue;
            }
            if (($issue['severity'] ?? '') === 'needs_clarification') {
                $errors[] = $msg;
            }
        }

        return [
            'valid'       => $result->is_valid,
            'errors'      => $errors,
            'ambiguities' => $ambiguities,
            'issues'      => $result->issues,
        ];
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
        $service = new booking_task_mutation_execute_service();
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
