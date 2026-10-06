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
use mod_booking\local\wizard\booking\support\entity_location;
use mod_booking\local\wizard\engine\queue_identity_provider_interface;
use mod_booking\local\wizard\engine\skill_trigger_provider_interface;

/**
 * Task definition for booking.bulk_update_options.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bulk_update_options_skill extends booking_skill_base implements
    queue_identity_provider_interface,
    skill_trigger_provider_interface {
    use option_targeted_skill;

    /** Task name constant. */
    public const TASK_NAME = 'mod_booking.bulk_update_options';

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(false, \mod_booking\local\wizard\engine\skill_risk_class::R2, ['mod/booking:editownoption']);
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
            // First 240 characters = selector window (#2423, BU-1 "all cooking classes get 18 seats").
            'description' => 'CHANGE fields (maxanswers seats, prices, visibility, location, dates) on MANY booking options at '
                . 'once, selected by optionquery (e.g. all cooking classes), optionids or apply_to_all. All provided fields are '
                . 'applied to every matched option.',
            'when' => 'The user wants the same fields changed on many booking options at once.',
            'is' => 'Many options in one call.',
            'not' => 'One single option (update_option).',
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
                'activityquery' => [
                    'type' => 'string',
                    'description' => 'Optional: name of the target booking activity when it is not the current one'
                        . ' (e.g. over MCP, which runs at the system context). Names only - never a course.',
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
            'prompt_meta' => [
                // The prompt_meta block keeps its established shape even where only the group is declared: the
                // contract test asserts both keys on every skill that carries prompt_meta at all, and an
                // empty list is what the readers saw before this block existed.
                'input_fields_for_prompt' => [],
                'anchor_fields' => [],
                // Mirrors the first gate of check_structure(): without optionids, optionquery or
                // apply_to_all there is no selection to work on. The second gate there (at least one
                // known change field) is not declared here: its alternatives are the whole set of
                // mutation properties, derived from the schema at runtime, and a static copy would drift.
                'required_groups' => [
                    ['optionids', 'optionquery', 'apply_to_all'],
                ],
            ],
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
     * Input keys that steer the bulk run without changing any option field. Everything else
     * must be a schema-known mutation field for the command to have an effect.
     *
     * @var array<int,string>
     */
    private const BULK_CONTROL_KEYS = [
        'optionids', 'resolvedoptionids', 'optionquery', 'activityquery', 'optionwhen', 'apply_to_all',
        'outputlang', 'override',
    ];

    /**
     * Structural validation — pure, no DB access.
     *
     * Checks that at least one target-selection mechanism is present AND that at least one
     * schema-known change field is requested.
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
                'errors' => [get_string('agent_booking_bulk_update_missing_target', 'booking')],
            ];
        }

        // At least one KNOWN change field must be present, or the run would mutate nothing and
        // still report success (observed live: the model invented "available: 1" for a
        // visibility request; the option stayed hidden while the reply claimed the update
        // happened). Known = the schema's mutation properties plus the documented legacy
        // "visible" alias (normalize_visibility_input); the selection/control keys never count.
        $knownchangekeys = array_flip(array_merge(
            array_diff(array_keys((array)($this->get_schema()['properties'] ?? [])), self::BULK_CONTROL_KEYS),
            ['visible']
        ));
        $unknownkeys = [];
        $haschange = false;
        foreach (array_keys($input) as $key) {
            if (!is_string($key) || $key === '' || in_array($key, self::BULK_CONTROL_KEYS, true)) {
                continue;
            }
            if (isset($knownchangekeys[$key])) {
                $haschange = true;
            } else {
                $unknownkeys[] = $key;
            }
        }
        if (!$haschange) {
            // F3 two-channel cause contract: 'errors' carries ONLY the plain-English user cause
            // (LLM material, formulated by the synchronizer in the user's language); 'repair'
            // carries the planner-only retry instructions incl. the canonical field names.
            return [
                'valid' => false,
                'errors' => ['Which property of the selected booking options should be changed, '
                    . 'and to what value?'],
                'repair' => [
                    'No supported change field was provided'
                        . (empty($unknownkeys) ? '' : ' (unsupported keys: ' . implode(', ', $unknownkeys) . ')')
                        . '. Retry ' . self::TASK_NAME . ' once with canonical fields, e.g. invisible '
                        . '(0 = visible, 1 = invisible, 2 = direct link only), maxanswers, location, '
                        . 'coursestarttime/courseendtime, prices.',
                ],
                'issue_codes' => ['RECOVERABLE_INPUT_ERROR'],
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
        $lang = $this->get_output_language($input);

        // The option_targeted_skill trait resolves the operating context from the named option ids /
        // query, so a bulk update works from an activity page, the dashboard, or MCP alike.
        $resolved = $this->resolve_option_operating_context($input, $cmid, 'mod/booking:editownoption', $userid, $lang);
        if (isset($resolved['clarification'])) {
            return $resolved['clarification'];
        }
        $cmid = $resolved['cmid'];

        global $DB;

        $issues = [];
        $preparedinput = $input;

        // Bulk mirrors update_option: a bare numeric price becomes the default category before the
        // confirm preview, so the card shows what execute will write (run 9, P3, #2409).
        if (isset($preparedinput['prices']) && is_numeric($preparedinput['prices'])) {
            $preparedinput['prices'] = ['default' => (float)$preparedinput['prices']];
        }

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

        // Resolve the query / apply-to-all match set now: the confirm card must state the
        // real scope, and an empty set is a clarification, never a confirmable command.
        if (empty($preparedinput['optionids'])) {
            $matchedids = booking_skill_support::resolve_bulk_option_ids_for_execute($cmid, $input, $userid);
            if (empty($matchedids)) {
                $query = trim((string)($input['optionquery'] ?? ''));
                $nomatch = $query === ''
                    ? $this->localized_string('agent_booking_bulk_update_no_options', null, $lang)
                    : $this->localized_string('agent_booking_bulk_update_no_matches', $query, $lang);
                // The engine shows user_question, not message: the "nothing matched" fact must be
                // part of the question, or the user only sees the generic scope question again.
                $issue = [
                    'code'           => 'EMPTY_BULK_TARGET_SELECTION',
                    'severity'       => 'needs_clarification',
                    'message'        => $nomatch,
                    'user_question'  => $nomatch . ' '
                        . $this->localized_string('agent_booking_bulk_update_issue_user_question', null, $lang),
                    'remedy_options' => ['PROVIDE_OPTIONQUERY', 'PROVIDE_OPTIONIDS', 'SET_APPLY_TO_ALL'],
                ];
                // Wave 32 (BU-1 0/10, threads 9106-12890): "Kochkurse" is in no option name, "Kochkurs Italienisch"
                // and "Kochkurs Vegetarisch" are. Choices, not an error: the options of the activity are offered
                // (visible first) and the model picks the ids meant - no stemming, no word list in the code.
                $choices = $query === '' ? [] : booking_skill_support::option_choices($cmid);
                if (!empty($choices)) {
                    $issue['message'] = $nomatch . ' '
                        . $this->localized_string('agent_booking_bulk_update_choose_from_candidates', null, $lang);
                    $issue['field'] = 'optionids';
                    $issue['candidates'] = $choices;
                }
                $issues[] = $issue;
                return $this->invalid($issues);
            }
            $preparedinput['optionids'] = array_values(array_map('intval', $matchedids));
        }

        // Bulk mirrors update_option: an entity-managed location is resolved before the card (#2414).
        $entityissue = entity_location::preflight_issue(
            $preparedinput,
            fn(string $id, $a): string => $this->localized_string($id, $a, $lang)
        );
        if ($entityissue !== null) {
            $issues[] = $entityissue;
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
                    '- For hide/show requests, map to visibility/invisible:',
                    '  invisible|hidden -> invisible=1, visible -> invisible=0, direct-link-only -> invisible=2.',
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
            // Never clobber the service's failure information with the bare generic string: on
            // anything but a clean all-green run, append the service detail (which option failed
            // on which field, verifier messages incl. real option links) so it survives to the
            // user. The all-green case keeps the plain generic message as before.
            $detail = trim((string)($result['detail'] ?? ''));
            if (($result['status'] ?? '') !== 'executed' && $detail !== '') {
                $usermessage .= "\n" . $detail;
            }
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
