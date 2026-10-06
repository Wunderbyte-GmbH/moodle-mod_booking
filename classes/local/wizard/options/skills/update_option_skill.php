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
            // The date-range shape is load-bearing: without it the model falls back to Moodle-idiomatic
            // timestart/timeend and the validator drops it (#2272). Wave 30 (UO-3): the example VALUES were copied
            // too - 18:00-20:00 became the time of a session the user never gave - so the shape is shown with a
            // placeholder in exactly the format the parser takes.
            'optiondates'      => [
                ['coursestarttime' => 'YYYY-MM-DD HH:MM', 'courseendtime' => 'YYYY-MM-DD HH:MM'],
            ],
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
            'description' => 'Change fields of ONE existing booking option (optionquery or optionid): title, dates, seats, price, '
                . 'location, visibility, trainers. Also links a Moodle course to the option (coursequery) or sets its header image '
                . '(headerimage_token).',
            'when' => 'The user wants fields of one existing booking option changed, including its header image.',
            'is' => 'One option.',
            'not' => 'Many options at once (bulk_update_options); the field definitions themselves (create_option_field, '
                . 'update_option_field).',
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
                'activityquery' => [
                    'type' => 'string',
                    'description' => 'Optional: name of the target booking activity when it is not the current one'
                        . ' (e.g. over MCP, which runs at the system context). Names only - never a course.',
                    'required' => false,
                ],
                'shiftdays' => [
                    'type' => 'integer',
                    'description' => 'Move ALL existing sessions of this option by whole days, RELATIVE to where '
                        . 'they are now: 7 = one week later, -7 = one week earlier. Use this whenever the user '
                        . 'describes the move relative ("push the start back a week") instead of naming a date — '
                        . 'you do not need to know the current dates, this skill reads them. Do not combine with '
                        . 'optiondates or coursestarttime.',
                    'required' => false,
                ],
                'optionquery' => [
                    'type' => 'string',
                    'description' => 'Pass the user\'s wording VERBATIM, even when it is vague ("der Wanderkurs"): '
                        . 'this skill resolves it and reports candidates itself. Never ask the user for a name or id '
                        . 'first. Resolves the target option by title/description/location.',
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
            'prompt_meta' => [
                // The prompt_meta block keeps its established shape even where only the group is declared: the
                // contract test asserts both keys on every skill that carries prompt_meta at all, and an
                // empty list is what the readers saw before this block existed.
                'input_fields_for_prompt' => [],
                'anchor_fields' => [],
                // Mirrors check_structure(): the option to change must be named, by id or by query.
                // The other checks there (shiftdays numeric, shiftdays vs. explicit dates) only apply
                // once those fields ARE set, so they are no requirement of an empty input.
                'required_groups' => [
                    ['optionid', 'optionquery'],
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
                'id' => 'mod_booking.set_header_image',
                'description' => 'User wants to add, set or replace a booking option\'s header/cover/title image '
                    . '(e.g. from an uploaded image attachment). This is the skill for attaching images to options.',
            ],
            [
                'id' => 'mod_booking.configure_entry_tickets',
                'description' => 'User wants entry tickets for a booking option: switching tickets on or off, '
                    . 'choosing the ticket design, making tickets personalised or transferable, requiring an '
                    . 'identity check at the door (exams), or printing extra information on the ticket.',
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
                    '- For a RELATIVE move of the existing sessions ("a week later", "push it back three days")',
                    '  set shiftdays (7 / -3). Never ask the user for the current dates - this skill reads them.',
                    '- For dates use optiondates: optiondatesmode=append ADDS sessions, replace SETS the whole list; '
                        . 'without a mode a single date on a single-session option MOVES that session, otherwise dates are added.',
                    '- Use confirmation_request for updates and follow structured validation issues when returned.',
                ],
            ],
            $this->header_image_attachment_prompt_pack(),
            [
                'id' => 'mod_booking.entry_tickets',
                'triggers' => [
                    'ticket', 'tickets', 'entry ticket', 'eintrittskarte', 'eintrittsticket',
                    'einlass', 'scan', 'qr', 'admission',
                ],
                'guidance' => [
                    '- Entry tickets are configured per booking option with ticketdesign, ticketpersonalized,',
                    '  ticketconfirmidentity and ticketextrainfo. They are NOT certificates.',
                    '- ticketdesign takes the NAME of the ticket design (a certificate template), not an id.',
                    '  Pass what the user said; if it matches several designs you get the candidates back, then ask.',
                    '- Setting ticketdesign switches entry tickets ON. Pass an empty ticketdesign to switch them OFF.',
                    '- ticketconfirmidentity=true is for exams and similar: the door scanner then shows name and',
                    '  profile picture and waits for staff to confirm before checking the participant in.',
                    '- ticketpersonalized=false only for tickets that may be passed on or resold.',
                    '- Tickets are created automatically on booking, but they are only DELIVERED by a booking rule.',
                    '  After switching tickets on, offer to create a rule reacting on the ticket_created event',
                    '  with the send_ticket action.',
                    '- Never claim a ticket was sent. Creating a ticket and sending it are two different things.',
                ],
            ],
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
    /**
     * Move existing sessions by whole days.
     *
     * The model states the movement, the skill does the arithmetic on the sessions it read from the database —
     * that is the whole point of the relative field (baseline runs 15/16, UO-1).
     *
     * @param array $sessions Session records with coursestarttime/courseendtime.
     * @param int $days Whole days; positive moves later, negative earlier.
     * @return array[] Session payload for optiondates.
     */
    public static function shifted_sessions(array $sessions, int $days): array {
        $shift = $days * DAYSECS;
        $out = [];
        foreach ($sessions as $session) {
            $start = (int)($session->coursestarttime ?? ($session['coursestarttime'] ?? 0));
            $end = (int)($session->courseendtime ?? ($session['courseendtime'] ?? 0));
            if ($start <= 0) {
                continue;
            }
            $out[] = [
                'coursestarttime' => $start + $shift,
                'courseendtime' => $end > 0 ? $end + $shift : $end,
            ];
        }

        return $out;
    }

    /**
     * Validate the structure of the input before anything is resolved or changed.
     *
     * @param array $input
     * @return array valid/errors result
     */
    public function check_structure(array $input): array {
        if (empty($input['optionid']) && empty($input['optionquery'])) {
            return [
                'valid'  => false,
                'errors' => [get_string('agent_booking_update_option_missing_target', 'booking')],
            ];
        }

        if (isset($input['shiftdays']) && $input['shiftdays'] !== '' && $input['shiftdays'] !== null) {
            if (!is_numeric($input['shiftdays']) || (int)$input['shiftdays'] != $input['shiftdays']) {
                return [
                    'valid' => false,
                    'errors' => ['shiftdays must be a whole number of days (7 = one week later, -7 = earlier).'],
                ];
            }
            if (!empty($input['optiondates']) || !empty($input['coursestarttime'])) {
                return [
                    'valid' => false,
                    'errors' => ['Use either shiftdays (relative) or explicit dates, not both.'],
                ];
            }
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
        $resolved = $this->resolve_option_operating_context($input, $cmid, 'mod/booking:editownoption', $userid, $lang);
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

        // Canonicalize the prices SHAPE before the confirm preview, exactly like create_option: a
        // bare numeric ("prices": 25) means the default price category. Execute normalizes the same
        // way, so without this the price is written but never shown on the card (run 9, P3, #2409).
        if (isset($preparedinput['prices']) && is_numeric($preparedinput['prices'])) {
            $preparedinput['prices'] = ['default' => (float)$preparedinput['prices']];
        }

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
                if ($result['status'] === 'error' || $result['status'] === 'ambiguity') {
                    // L45 UOT-2: choices instead of the resolver's English text naming optionquery/optionid.
                    $issues[] = $this->option_resolution_issue(
                        $result,
                        $cmid,
                        (string)$input['optionquery'],
                        (string)($input['optionwhen'] ?? ''),
                        $lang
                    );
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

        // A relative move is expanded here, AFTER the option is resolved: read the current sessions, move them,
        // and hand the executor the same absolute list it always gets (baseline UO-1). W32: this block ran before
        // the resolution above, so an option named by optionquery had no optionid yet, every shift found "no
        // sessions" and the issue asked which option to update (UO-1, all ten runs L30-L41).
        if (isset($preparedinput['shiftdays']) && $preparedinput['shiftdays'] !== '' && $preparedinput['shiftdays'] !== null) {
            $optionid = (int)($preparedinput['optionid'] ?? 0);
            $sessions = $optionid > 0
                ? $DB->get_records('booking_optiondates', ['optionid' => $optionid], 'coursestarttime ASC')
                : [];
            $shifted = self::shifted_sessions(array_values($sessions), (int)$preparedinput['shiftdays']);
            unset($preparedinput['shiftdays']);
            if (empty($shifted)) {
                $issues[] = [
                    'code' => 'UPDATE_OPTION_NO_SESSIONS_TO_SHIFT',
                    'severity' => 'needs_clarification',
                    'message' => $this->localized_string('agent_booking_update_option_no_sessions_to_shift', null, $lang),
                ];
                // Return here: apply_service_preflight() hands existing issues on only when the service itself reports
                // an error, so this question was dropped and the preflight passed (wave-32 integration test).
                return $this->invalid($issues);
            } else {
                $preparedinput['optiondates'] = $shifted;
                $preparedinput['optiondatesmode'] = 'replace';
            }
        }

        // With local_entities the location is an entity: resolve it here so the card and the save
        // carry the entity link; an unknown name is a clarification with the entities as remedies (#2414).
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
