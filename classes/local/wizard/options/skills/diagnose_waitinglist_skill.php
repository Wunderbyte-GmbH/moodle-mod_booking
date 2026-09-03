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
class diagnose_waitinglist_skill extends booking_skill_base {
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
            'description' => 'Diagnose why the waiting list of a booking option did or did not move: why nobody '
                . 'was promoted after a cancellation, or - most commonly - why reducing the number of seats '
                . '(maxanswers) did not move any booked user to the waiting list. Reports the exact blocking '
                . 'gate and settings. PATTERN: extract the option reference into optionquery; do not ask for '
                . 'clarification if the option is identifiable in the user message.',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'I reduced the seats from 16 to 9 but all 16 are still booked, why?',
                'Why was nobody moved up from the waiting list?',
                'I lowered the capacity but the waiting list did not change',
                'Why does reducing the places not remove anyone from this option?',
                'Nobody got promoted after a cancellation on the waiting list',
            ],
            'properties' => [
                'optionid' => [
                    'type' => 'integer',
                    'description' => 'Explicit booking option id when already known.',
                    'required' => false,
                ],
                'optionquery' => [
                    'type' => 'string',
                    'description' => 'Booking option title, id-like reference, or "last option" when referring to '
                        . 'the last shown option.',
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
            'input_fields_for_prompt' => ['optionquery (or optionid)'],
            'anchor_fields' => ['optionquery', 'optionid'],
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
                    '- Use booking.diagnose_waitinglist when the user asks why the waiting list did not change,',
                    '  why nobody was promoted, or why reducing the seats did not move anyone.',
                    '- Resolve the option via optionquery (or optionid) first.',
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
        if ($scoperesult = $this->build_no_instance_scope_result($cmid)) {
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
                'blockinggate' => $report['blockinggate'] ?? null,
                'haswaitinglist' => (bool)($report['haswaitinglist'] ?? false),
                'overbooked' => (bool)($report['overbooked'] ?? false),
                'counts' => $counts,
                'reasons' => $reasons,
                'reply_requirements' => 'Name the exact setting keys and concrete admin changes. '
                    . 'If a blockinggate is set, that is the primary reason the whole sync stopped.',
            ],
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
     * Resolve the booking option to diagnose.
     *
     * @param array $input
     * @param int $cmid
     * @param int $userid
     * @param string $lang
     * @return array
     */
    private function resolve_option_id(array $input, int $cmid, int $userid, string $lang = ''): array {
        global $DB;

        $optionid = (int)($input['optionid'] ?? 0);
        $optionquery = trim((string)($input['optionquery'] ?? ''));
        if ($optionid > 0) {
            $cm = get_coursemodule_from_id('booking', $cmid, 0, false, MUST_EXIST);
            if ($DB->record_exists('booking_options', ['id' => $optionid, 'bookingid' => (int)$cm->instance])) {
                return ['status' => 'ok', 'optionid' => $optionid];
            }
            if ($optionquery !== '') {
                return booking_skill_support::resolve_single_option($cmid, $optionquery, '');
            }
            return [
                'status' => 'error',
                'message' => $this->localized_string('agent_booking_diagnose_error_option_not_in_instance', null, $lang),
            ];
        }

        if ($optionquery === '') {
            return [
                'status' => 'ambiguity',
                'message' => $this->localized_string('agent_booking_diagnose_ambiguity_option_title_or_id', null, $lang),
            ];
        }

        if (booking_skill_support::is_last_option_reference($optionquery)) {
            $lastids = booking_skill_support::resolve_last_preview_option_ids_for_user_for_execute($cmid, $userid);
            if (count($lastids) === 1) {
                return ['status' => 'ok', 'optionid' => (int)$lastids[0]];
            }
            if (count($lastids) > 1) {
                return [
                    'status' => 'ambiguity',
                    'message' => $this->localized_string('agent_booking_diagnose_ambiguity_last_preview_multiple', null, $lang),
                ];
            }
            return [
                'status' => 'error',
                'message' => $this->localized_string('agent_booking_diagnose_error_last_preview_none', null, $lang),
            ];
        }

        return booking_skill_support::resolve_single_option($cmid, $optionquery, '');
    }
}
