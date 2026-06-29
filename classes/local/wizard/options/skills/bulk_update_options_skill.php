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
use bookingextension_agent\local\wizard\interfaces\queue_identity_provider_interface;
use bookingextension_agent\local\wizard\interfaces\skill_trigger_provider_interface;

/**
 * Task definition for booking.bulk_update_options.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bulk_update_options_skill extends booking_skill_base implements
    queue_identity_provider_interface,
    skill_trigger_provider_interface {
    /** Task name constant. */
    public const TASK_NAME = 'mod_booking.bulk_update_options';

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(false, \bookingextension_agent\local\wizard\dto\skill_risk_class::R2, ['mod/booking:addeditownoption']);
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
     * Human-readable preview of the bulk update (tier-3): selection target + changed fields.
     *
     * @param array $input Prepared input.
     * @return array|null
     */
    public function describe_proposed_action(array $input): ?array {
        return option_preview_builder::bulk_descriptor($input);
    }

    /**
     * Build queue business identity for bulk_update_options deduplication.
     *
     * @param array $input
     * @return array<string,mixed>
     */
    public function build_queue_business_identity(array $input): array {
        $normalized = $input;

        $optionids = [];
        foreach ((array)($normalized['optionids'] ?? []) as $optionid) {
            $id = (int)$optionid;
            if ($id > 0) {
                $optionids[] = $id;
            }
        }
        foreach ((array)($normalized['resolvedoptionids'] ?? []) as $optionid) {
            $id = (int)$optionid;
            if ($id > 0) {
                $optionids[] = $id;
            }
        }
        $optionids = array_values(array_unique($optionids));
        sort($optionids);

        $optionquery = $this->normalize_identity_query((string)($normalized['optionquery'] ?? ''));
        $applytoall = !empty($normalized['apply_to_all']);

        foreach (
            [
                'optionids',
                'resolvedoptionids',
                'optionquery',
                'apply_to_all',
                'outputlang',
                'override',
            ] as $key
        ) {
            unset($normalized[$key]);
        }

        return [
            'task_family' => 'mod_booking.bulk_update_options',
            'selection' => [
                'apply_to_all' => $applytoall,
                'optionids' => $optionids,
                'optionquery' => $optionquery,
            ],
            'changes' => $this->normalize_identity_value($normalized),
        ];
    }

    /**
     * Representative FLAT example input for the construction-phase catalog.
     *
     * Mirrors update_option: bulk consumes the SAME flat field shape — a target selector
     * (optionids/optionquery/apply_to_all) plus the common mutation fields (incl. headerimage_token)
     * at the top level. It must NOT advertise a configure_booking_instance-style
     * {changes:[{field,value}]} envelope; bulk does not read that shape (thread-206 regression).
     *
     * @return array<string,mixed>
     */
    public function get_example_input(): array {
        return [
            'optionquery'       => 'Yoga',
            'maxanswers'        => 20,
            'headerimage_token' => 'tok_abc123',
        ];
    }

    /**
     * Verify that requested common fields were persisted (incl. the header image when requested).
     *
     * Identical to update_option's check so the per-option bulk verification reuses the very same
     * field-level logic (via booking_skill_mutation_execute_service::persist_and_verify_single_option)
     * instead of a parallel implementation.
     *
     * @param array $input
     * @param object $settings
     * @return array
     */
    public function verify_persisted_option_state(array $input, object $settings): array {
        return option_input_verification::verify_common_fields($input, $settings);
    }

    /**
     * Return task schema.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'Update multiple booking options at once. All provided fields are applied to every '
                . 'matched option. Requires optionids, optionquery, or apply_to_all=true to select targets.',
            'readonly' => $this->is_read_only(),
            'fallback_confirm_string_key' => 'ai_status_confirm_booking_bulk_update_options',
            'fallback_taskcall_string_key' => 'ai_status_taskcall_booking_bulk_update_options',
            'example_utterances' => [
                'Set the price to 30 euros for all yoga options',
                'Apply the same end date to every option in this activity',
                'Increase the capacity of all workshops to 25 seats',
                'Make all options visible at once',
                'Change the teacher for every Tuesday class in one go',
            ],
            'properties' => array_merge([
                'optionids' => [
                    'type' => 'array',
                    'description' => 'Array of specific option IDs to update.',
                    'required' => false,
                ],
                'optionquery' => [
                    'type' => 'string',
                    'description' => 'Search query to select multiple options to update '
                        . '(e.g. "yoga" selects all yoga options).',
                    'required' => false,
                ],
                'apply_to_all' => [
                    'type' => 'boolean',
                    'description' => 'Set to true to update ALL options in this booking instance. '
                        . 'Must be set when neither optionids nor optionquery is provided.',
                    'required' => false,
                ],
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'Optional language code for task-authored wrapper strings, e.g. de or en.',
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
                'id' => 'mod_booking.bulk_update_apply_to_all_confirmed',
                'description' => 'User explicitly confirms applying a bulk update to all booking options.',
            ],
            [
                'id' => 'mod_booking.bulk_update_selection_by_query',
                'description' => 'User specifies that bulk target selection should be based on optionquery.',
            ],
            [
                'id' => 'mod_booking.bulk_update_by_optionids',
                'description' => 'User specifies explicit option ids for bulk update targets.',
            ],
        ];
    }

    /**
     * Structural validation — pure, no DB access.
     *
     * Checks that at least one target-selection mechanism is present.
     *
     * @param  array $input
     * @return array{valid:bool,errors:array<int,string>}
     */
    public function check_structure(array $input): array {
        $hasids   = !empty($input['optionids']) && is_array($input['optionids'])
            && count($input['optionids']) > 0;
        $hasquery = !empty($input['optionquery']) && trim((string)$input['optionquery']) !== '';
        $applyall = !empty($input['apply_to_all']);

        if (!$hasids && !$hasquery && !$applyall) {
            return [
                'valid'  => false,
                'errors' => [get_string('agent_booking_bulk_update_missing_target', 'bookingextension_agent')],
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
     * Deep preflight validation — DB lookups, bulk option resolution.
     *
     * Returns prepared_input with 'optionids' populated (resolved) so that
     * execute() never has to re-run bulk resolution logic.
     *
     * @param  array $input
     * @param  int   $cmid
     * @param  int   $userid
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
        $preparedinput = $input;

        $hasids   = !empty($input['optionids']) && is_array($input['optionids'])
            && count($input['optionids']) > 0;
        $hasquery = !empty($input['optionquery']) && trim((string)$input['optionquery']) !== '';
        $applyall = !empty($input['apply_to_all']);

        // Resolve preview-fallback IDs.
        $previewfallbackids = booking_skill_support::resolve_last_preview_option_ids_for_user_for_execute($cmid, $userid);

        if (!$hasids && !$hasquery && !$applyall && empty($previewfallbackids)) {
            $issues[] = [
                'code'           => 'MISSING_BULK_TARGET_SELECTION',
                'severity'       => 'needs_clarification',
                'message'        => $this->localized_string('agent_booking_bulk_update_missing_target', null, $lang),
                'user_question'  => $this->localized_string('agent_booking_bulk_update_issue_user_question', null, $lang),
                'remedy_options' => ['SET_APPLY_TO_ALL', 'PROVIDE_OPTIONQUERY', 'PROVIDE_OPTIONIDS'],
            ];
            return $this->invalid($issues);
        }

        // Validate and resolve explicit optionids.
        if ($hasids) {
            $resolvedids = booking_skill_support::resolve_bulk_option_ids_for_execute(
                $cmid,
                ['optionids' => $input['optionids']],
                $userid
            );
            $cm = get_coursemodule_from_id('booking', $cmid);
            if ($cm && empty($resolvedids)) {
                foreach ($input['optionids'] as $optid) {
                    if (!$DB->record_exists('booking_options', ['id' => (int)$optid, 'bookingid' => (int)$cm->instance])) {
                        $issues[] = [
                            'code'     => 'INVALID_OPTION_ID',
                            'severity' => 'needs_clarification',
                            'message'  => $this->localized_string(
                                'agent_booking_bulk_update_option_not_in_instance',
                                (int)$optid,
                                $lang
                            ),
                        ];
                    }
                }
                if (!empty($issues)) {
                    return $this->invalid($issues);
                }
            }
            // Store fully resolved IDs in prepared_input.
            if (!empty($resolvedids)) {
                $preparedinput['optionids'] = array_values(array_map('intval', $resolvedids));
            }
        }

        // Validate preview-based query references.
        if (!$hasids && $hasquery && booking_skill_support::is_last_preview_selection_reference((string)$input['optionquery'])) {
            if (empty($previewfallbackids)) {
                $issues[] = [
                    'code'     => 'MISSING_PREVIEW_CONTEXT',
                    'severity' => 'needs_clarification',
                    'message'  => $this->localized_string('agent_booking_bulk_update_no_preview', null, $lang),
                ];
                return $this->invalid($issues);
            }
            // Swap query reference for resolved IDs.
            unset($preparedinput['optionquery']);
            $preparedinput['optionids'] = array_values(array_map('intval', $previewfallbackids));
        }

        // Bookusersquery is not supported on bulk update.
        if (!empty($input['bookusersquery'])) {
            $issues[] = [
                'code'     => 'BOOKUSERSQUERY_UNSUPPORTED',
                'severity' => 'needs_clarification',
                'message'  => $this->localized_string('agent_booking_bulk_update_bookusersquery_unsupported', null, $lang),
            ];
            return $this->invalid($issues);
        }

        // Run service-level preflight (teacher resolution, dates, etc.) and enrich prepared_input.
        return $this->apply_service_preflight(self::TASK_NAME, $preparedinput, $cmid, $userid, $issues, $lang);
    }

    /**
     * Return contextual guidance packs.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_contextual_prompt_packs(): array {
        return [
            [
                'id' => 'mod_booking.bulk_mutation_flow',
                'triggers' => [
                    'bulk update', 'mass update',
                    'update all', 'set all', 'for all options',
                    'all options', 'all booking options',
                ],
                'guidance' => [
                    '- Use booking.bulk_update_options when the user wants to update multiple options at once.',
                    '- Set apply_to_all=true when the user says "all options" without naming specific ones.',
                    '- Use optionquery to match a subset by title/keyword (e.g. "yoga" selects all yoga options).',
                    '- Use optionids array for an explicit list of known option IDs.',
                    '- All common update fields (maxanswers, maxoverbooking, location, etc.) work the same '
                        . 'as in booking.update_option and are applied to every matched option.',
                    '- Do not use bookusersquery with bulk_update_options.',
                    '- Use confirmation_request for bulk mutations and follow structured validation issues when returned.',
                ],
            ],
        ];
    }

    /**
     * Execute task using prepared_input from preflight().
     *
     * prepared_input already contains resolved 'optionids' so the mutation
     * service needs no further bulk resolution.
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

        $outputlang = $this->get_output_language($preparedinput);
        if (is_array($result)) {
            $usermessage = $this->localized_string(
                'agent_booking_bulk_update_completed',
                ($result['status'] ?? 'unknown'),
                $outputlang
            );
            $result['usermessage'] = $usermessage;
            $result['outputlang'] = $outputlang;
            $result['debugmessage'] = $this->build_task_debug_message(
                self::TASK_NAME,
                $preparedinput,
                ['Status: ' . ($result['status'] ?? 'unknown')]
            );
            return $result;
        }

        return [
            'status' => 'error',
            'detail' => $this->localized_string('agent_booking_unknown_task', self::TASK_NAME, $outputlang),
            'resultid' => null,
            'usermessage' => $this->localized_string('agent_booking_bulk_update_failed', null, $outputlang),
            'outputlang' => $outputlang,
            'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $preparedinput, ['Status: error']),
        ];
    }
}
