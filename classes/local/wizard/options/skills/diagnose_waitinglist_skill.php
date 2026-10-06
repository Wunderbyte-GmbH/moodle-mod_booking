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

use mod_booking\local\wizard\booking\booking_skill_support;
use mod_booking\local\wizard\engine\skill_risk_class;
use mod_booking\local\waitinglist\waitinglist_sync_status;
use mod_booking\local\wizard\engine\skill_trigger_provider_interface;

/**
 * Task definition for booking.diagnose_waitinglist.
 *
 * Answers "why did the waiting list not move?" for one option - promotion after a
 * cancellation, or (most often) why reducing the seats moved nobody. The verdict is
 * derived deterministically from the sync gates in waitinglist_sync_status; no phrase
 * matching on the user text.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class diagnose_waitinglist_skill extends booking_skill_base implements skill_trigger_provider_interface {
    /** Task name constant. */
    public const TASK_NAME = 'mod_booking.diagnose_waitinglist';

    /** Map from an explain() issue code to its localized reason string identifier. */
    private const ISSUE_STRING_MAP = [
        waitinglist_sync_status::GATE_TURNOFF_GLOBAL => 'agent_booking_waitinglist_reason_turnoffglobal',
        waitinglist_sync_status::GATE_TURNOFF_AFTERSTART => 'agent_booking_waitinglist_reason_aftercoursestart',
        waitinglist_sync_status::GATE_OPTION_STARTED => 'agent_booking_waitinglist_reason_optionstarted',
        'nowaitinglist' => 'agent_booking_waitinglist_reason_nowaitinglist',
        'paidoption' => 'agent_booking_waitinglist_reason_paidoption',
        'keepusersbooked' => 'agent_booking_waitinglist_reason_keepusersbooked',
        'waitforconfirmation' => 'agent_booking_waitinglist_reason_waitforconfirmation',
        'missingdeleteresponses' => 'agent_booking_waitinglist_reason_missingdeleteresponses',
    ];

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(true, skill_risk_class::R0);
    }

    /**
     * Free-text queries here may arrive masked as anonymized person tokens.
     *
     * @return bool
     */
    public function is_person_centric_readonly(): bool {
        return true;
    }

    /**
     * Return the task name.
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
        $schema = [
            'version' => 1,
            'description' => 'Diagnose why the waiting list of a booking option did or did not move: why nobody was promoted after '
                . 'a cancellation, or - most commonly - why reducing the number of seats (maxanswers) did not move any booked user '
                . 'to the waiting list. Reports the exact blocking gate and settings. PATTERN: extract the option reference into '
                . 'optionquery whenever the option is identifiable in the user message.',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'I reduced the seats from 16 to 9 but all 16 are still booked, why?',
                'Why was nobody moved up from the waiting list?',
                'I lowered the capacity but the waiting list did not change',
                'Why does reducing the places not remove anyone from this option?',
                'Nobody got promoted after a cancellation on the waiting list',
            ],
            'properties' => [
                // The option reference comes first and its whole instruction fits the constructor's field line.
                'optionquery' => [
                    'type' => 'string',
                    'description' => 'Never ask which option: pass the user\'s words for it verbatim, even vague ones ("the '
                        . 'photo workshop"); this skill resolves them or lists the candidates itself.',
                    'required' => false,
                ],
                'optionid' => [
                    'type' => 'integer',
                    'description' => 'Explicit booking option id when already known.',
                    'required' => false,
                ],
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'Optional language code for wrapper strings, e.g. de or en.',
                    'required' => false,
                ],
            ],
        ];

        $schema['prompt_meta'] = [
            'intent' => 'Explain from stored facts why the waiting list of one option moved or did not move.',
            'input_fields_for_prompt' => ['optionquery'],
            'anchor_fields' => ['optionquery', 'optionid'],
            // Mirrors check_structure(): the option whose waiting list is examined must be named,
            // either by id or by query — one of the two, never both mandatory.
            'required_groups' => [
                ['optionid', 'optionquery'],
            ],
        ];

        return $this->enrich_schema_with_prompt_meta($schema);
    }

    /**
     * Return contextual guidance packs.
     *
     * @return array
     */
    public function get_contextual_prompt_packs(): array {
        return [
            [
                'id' => 'mod_booking.diagnose_waitinglist',
                'triggers' => [
                    'waiting list', 'waitinglist', 'reduce seats', 'reduced seats', 'lower capacity',
                    'move up', 'moved up', 'promote', 'promotion',
                ],
                'guidance' => [
                    '- Use mod_booking.diagnose_waitinglist when the user asks why the waiting list did not change,',
                    '  why nobody was promoted, or why reducing the seats did not move anyone.',
                    '- Put the user\'s words for the option into optionquery, vague ones too; the skill resolves them.',
                    '- The task reports the blocking gate deterministically; relay the exact setting names.',
                ],
            ],
        ];
    }

    /**
     * Check task input structure.
     *
     * @param array $input
     * @return array
     */
    public function check_structure(array $input): array {
        $errors = [];
        $lang = $this->get_output_language($input);

        $hasoptionid = !empty((int)($input['optionid'] ?? 0));
        $hasquery = trim((string)($input['optionquery'] ?? '')) !== '';
        if (!$hasoptionid && !$hasquery) {
            $errors[] = $this->localized_string('agent_booking_diagnose_ambiguity_option_title_or_id', null, $lang);
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'ambiguities' => [],
        ];
    }

    /**
     * Resolve the option before anything runs, so a miss offers the existing options as choices.
     *
     * Before W32 the option was resolved only in execute(): a query that matched nothing (W32 DWL-2, "die
     * Wanderung") ran the skill into an error that ended the turn. Options with a waiting list come first -
     * only they can have one that does not move.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array{status:string,prepared_input:array,issues:array}
     */
    protected function run_preflight(array $input, int $contextid, int $userid): array {
        $structure = parent::run_preflight($input, $contextid, $userid);
        if (($structure['status'] ?? 'pass') === 'invalid') {
            return $structure;
        }
        $cmid = $this->resolve_cmid_from_context_or_cmid($contextid);
        if ($cmid <= 0) {
            // The execute() method answers the missing activity scope as before.
            return $structure;
        }

        $lang = $this->get_output_language($input);
        $resolved = $this->resolve_option_id($input, $cmid, $userid, $lang);
        if (($resolved['status'] ?? '') === 'ok') {
            $prepared = (array)($structure['prepared_input'] ?? $input);
            $prepared['optionid'] = (int)$resolved['optionid'];
            return $this->pass($prepared);
        }
        if (($resolved['issue_code'] ?? '') === 'OPTION_NOT_FOUND') {
            return $this->invalid([$this->option_miss_choices_issue(
                'DIAGNOSE_WAITINGLIST_OPTION_NOT_FOUND',
                $cmid,
                $this->waitinglist_option_ids($cmid),
                $lang
            )]);
        }
        // Ambiguity and every other outcome keep the established execute() path and wording.
        return $structure;
    }

    /**
     * Options of this activity that keep a waiting list (a waiting-list size above zero).
     *
     * @param int $cmid
     * @return int[]
     */
    private function waitinglist_option_ids(int $cmid): array {
        global $DB;

        $cm = get_coursemodule_from_id('booking', $cmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return [];
        }
        return array_map('intval', $DB->get_fieldset_select(
            'booking_options',
            'id',
            'bookingid = :bookingid AND maxoverbooking > 0',
            ['bookingid' => (int)$cm->instance]
        ));
    }

    /**
     * Execute task.
     *
     * @param array $input
     * @param int $contextid Moodle contextid (module or system context).
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        global $DB;

        $cmid = $this->resolve_cmid_from_context_or_cmid($contextid);
        if ($scoperesult = $this->build_no_instance_scope_result($cmid, $input)) {
            return $scoperesult;
        }

        $outputlang = $this->get_output_language($input);

        $resolved = $this->resolve_option_id($input, $cmid, $userid, $outputlang);
        if (($resolved['status'] ?? '') !== 'ok') {
            return [
                'status' => 'error',
                'detail' => (string)($resolved['message']
                    ?? $this->localized_string('agent_booking_diagnose_error_option_resolve', null, $outputlang)),
                'resultid' => null,
                'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, ['Status: error']),
            ];
        }
        $optionid = (int)$resolved['optionid'];

        $report = waitinglist_sync_status::explain($optionid, $userid);

        // Translate the deterministic issue codes into localized, actionable reasons.
        $reasons = [];
        foreach ((array)($report['issues'] ?? []) as $code) {
            if (isset(self::ISSUE_STRING_MAP[$code])) {
                $reasons[] = $this->localized_string(self::ISSUE_STRING_MAP[$code], null, $outputlang);
            }
        }
        if (empty($reasons)) {
            $reasons[] = $this->localized_string('agent_booking_waitinglist_nothing_blocking', null, $outputlang);
        }

        $counts = (array)($report['counts'] ?? []);
        $optionname = (string)$DB->get_field('booking_options', 'text', ['id' => $optionid]) ?: ('Option #' . $optionid);

        $usermessage = $this->localized_string(
            'agent_booking_diagnose_intro_checked_option',
            $optionname,
            $outputlang
        );
        if ($cmid > 0 && $optionid > 0) {
            $usermessage .= ' (' . booking_skill_support::build_option_link_for_output($cmid, $optionid) . ')';
        }

        $haswaitinglist = (bool)($report['haswaitinglist'] ?? false);
        $overbooked = (bool)($report['overbooked'] ?? false);
        $blockinggate = $report['blockinggate'] ?? null;

        return [
            'status' => 'executed',
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'resultid' => $optionid,
            'previewoptionids' => [$optionid],
            'diagnosis' => [
                'issue' => 'waitinglist_sync',
                'optionid' => $optionid,
                'optionname' => $optionname,
                'blockinggate' => $blockinggate,
                'haswaitinglist' => $haswaitinglist,
                'overbooked' => $overbooked,
                'counts' => $counts,
                'reasons' => $reasons,
            ],
            // Without this the summarizer renders only the reasons, cut at 220 characters, and the model
            // never sees the counts it is asked to reason about.
            'observation_full' => $this->build_observation_full(
                $optionname,
                $counts,
                $haswaitinglist,
                $overbooked,
                $blockinggate,
                $reasons
            ),
            'debugmessage' => $this->build_task_debug_message(
                self::TASK_NAME,
                $input,
                [
                    'Resolved option: ' . $optionname . ' (id=' . $optionid . ')',
                    'Blocking gate: ' . (string)($report['blockinggate'] ?? 'none'),
                    'Issues: ' . implode(',', (array)($report['issues'] ?? [])),
                ]
            ),
        ];
    }

    /**
     * The text the model reads: the seat and waiting list numbers first, then the findings.
     *
     * @param string $optionname
     * @param array $counts
     * @param bool $haswaitinglist
     * @param bool $overbooked
     * @param string|null $blockinggate
     * @param string[] $reasons
     * @return string
     */
    private function build_observation_full(
        string $optionname,
        array $counts,
        bool $haswaitinglist,
        bool $overbooked,
        ?string $blockinggate,
        array $reasons
    ): string {
        $booked = (int)($counts['booked'] ?? 0);
        $reserved = (int)($counts['reserved'] ?? 0);
        $waiting = (int)($counts['waiting'] ?? 0);
        $maxanswers = (int)($counts['maxanswers'] ?? 0);
        $maxoverbooking = (int)($counts['maxoverbooking'] ?? 0);

        $lines = ['Diagnosis for option "' . $optionname . '" (waiting list).'];
        if ($haswaitinglist) {
            $lines[] = "Seats: {$booked} booked of {$maxanswers}, {$waiting} on the waiting list ({$maxoverbooking} places),"
                . " {$reserved} reserved.";
            $lines[] = $overbooked
                ? 'Overbooked: yes, ' . ($booked + $reserved - $maxanswers) . ' booked above the limit.'
                : 'Overbooked: no, the booked seats are within the limit.';
        } else {
            $lines[] = "Seats: {$booked} booked, no seat limit, no waiting list.";
        }
        $lines[] = 'Blocking gate: ' . ($blockinggate ?? 'none') . '.';
        $lines[] = 'Findings:';
        foreach ($reasons as $reason) {
            $lines[] = '- ' . trim((string)$reason);
        }

        return implode("\n", $lines);
    }

    /**
     * Resolve the booking option to diagnose.
     *
     * @param array $input
     * @param int $cmid
     * @param int $userid
     * @param string $lang
     * @return array
     */
    private function resolve_option_id(array $input, int $cmid, int $userid, string $lang = ''): array {
        return $this->resolve_diagnose_option($input, $cmid, $userid, $lang);
    }

    /**
     * The situation in which the selector routes here; rendered as the card's WHEN line.
     *
     * @return array[]
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'mod_booking.diagnose_waitinglist_request',
                'description' => 'The user asks why nobody moved up from the waiting list of one booking option, or why someone'
                    . ' did.',
            ],
        ];
    }
}
