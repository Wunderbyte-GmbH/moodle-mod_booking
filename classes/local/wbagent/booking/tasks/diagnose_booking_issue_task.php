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

use mod_booking\bo_availability\bo_info;
use mod_booking\local\wbagent\booking\booking_task_support;
use mod_booking\local\wbagent\interfaces\task_trigger_provider_interface;
use mod_booking\singleton_service;

/**
 * Task definition for booking.diagnose_booking_issue.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class diagnose_booking_issue_task extends base_booking_task implements task_trigger_provider_interface {
    /** Task name constant. */
    public const TASK_NAME = 'booking.diagnose_booking_issue';

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(true);
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
            'description' => 'Diagnose why the current user is not booked, cannot book, or did not receive email for a booking option or have any other issue regarding a booking option.',
            'readonly' => $this->is_read_only(),
            'properties' => [
                'question' => [
                    'type' => 'string',
                    'description' => 'The user question in natural language, e.g. "Why am I not booked for option X?"',
                    'required' => true,
                ],
                'optionquery' => [
                    'type' => 'string',
                    'description' => 'Booking option title, id-like reference, or words like "last option" when referring to the last shown option.',
                    'required' => false,
                ],
                'optionid' => [
                    'type' => 'integer',
                    'description' => 'Explicit booking option id when already known.',
                    'required' => false,
                ],
                'issue' => [
                    'type' => 'string',
                    'description' => 'Optional issue type: booking_status, missing_email, cannot_book.',
                    'required' => false,
                ],
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'Optional language code for localized task strings, e.g. de or en.',
                    'required' => false,
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
                'id' => 'booking.diagnose_booking_issue_self_help',
                'description' => 'User asks why they are not booked, cannot book, did not receive mail for a booking option or have any other issue regarding a booking option.',
                'examples' => [
                    'Warum bin ich bei Buchungsoption XY nicht eingetragen?',
                    'Wieso habe ich keine Mail von der Buchungsoption XY bekommen?',
                    'Warum kann ich mich bei Buchungsoption XY nicht eintragen?',
                ],
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
                'id' => 'booking.self_help_diagnostics',
                'triggers' => [
                    'why am i not booked', 'why can i not book', 'why no email',
                    'warum bin ich nicht eingetragen', 'warum kann ich mich nicht eintragen',
                    'wieso habe ich keine mail bekommen',
                ],
                'guidance' => [
                    '- Use booking.diagnose_booking_issue for self-help questions about one booking option.',
                    '- Pass the original user wording as question so the task can classify the issue type.',
                    '- Pass optionquery when the option title/reference is available; otherwise the task will ask a follow-up question.',
                ],
            ],
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
        $lang = $this->get_output_language($input);

        $question = trim((string)($input['question'] ?? ''));
        if ($question === '') {
            $errors[] = $this->localized_string('agent_booking_diagnose_required_question', null, $lang);
        }

        $optionvalidation = $this->validate_option_reference($input, $cmid, $lang);
        $errors = array_merge($errors, $optionvalidation['errors']);
        $ambiguities = array_merge($ambiguities, $optionvalidation['ambiguities']);

        return [
            'valid' => empty($errors) && empty($ambiguities),
            'errors' => $errors,
            'ambiguities' => $ambiguities,
        ];
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
        global $DB;
        $lang = $this->get_output_language($input);

        $issuetype = $this->resolve_issue_type($input);
        $resolvedoption = $this->resolve_option_id($input, $cmid, $userid, $lang);
        if (($resolvedoption['status'] ?? '') !== 'ok') {
            return [
                'status' => 'error',
                'detail' => (string)($resolvedoption['message']
                    ?? $this->localized_string('agent_booking_diagnose_error_option_resolve', null, $lang)),
                'resultid' => null,
                'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, ['Status: error']),
            ];
        }

        $optionid = (int)($resolvedoption['optionid'] ?? 0);
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $conditionresults = bo_info::get_condition_results($optionid, $userid);
        $optionname = (string)$DB->get_field('booking_options', 'text', ['id' => $optionid]) ?: ('Option #' . $optionid);
        $bookingid = (int)$DB->get_field('booking_options', 'bookingid', ['id' => $optionid]);
        $optionstats = $this->collect_option_stats($bookingid, $optionid, $userid, $settings);

        $userstatus = (string)$optionstats['userstatus'];
        $reasons = $this->build_reason_lines($issuetype, $optionstats, $conditionresults, $lang);
        $usermessage = $this->build_user_message($issuetype, $optionname, $userstatus, $reasons, $lang);

        return [
            'status' => 'executed',
            'detail' => $usermessage,
            'summary' => $usermessage,
            'usermessage' => $usermessage,
            'resultid' => $optionid,
            'previewoptionids' => [$optionid],
            'diagnosis' => [
                'issue' => $issuetype,
                'optionid' => $optionid,
                'optionname' => $optionname,
                'userstatus' => $userstatus,
                'stats' => $optionstats,
                'reasons' => $reasons,
            ],
            'debugmessage' => $this->build_task_debug_message(
                self::TASK_NAME,
                $input,
                [
                    'Resolved option: ' . $optionname . ' (id=' . $optionid . ')',
                    'Issue: ' . $issuetype,
                    'User status: ' . $userstatus,
                    'Reasons: ' . count($reasons),
                ]
            ),
        ];
    }

    /**
     * Resolve issue type from explicit input or the question text.
     *
     * @param array<string,mixed> $input
     * @return string
     */
    private function resolve_issue_type(array $input): string {
        $rawissue = strtolower(trim((string)($input['issue'] ?? '')));
        if (in_array($rawissue, ['booking_status', 'missing_email', 'cannot_book'], true)) {
            return $rawissue;
        }

        $question = strtolower(trim((string)($input['question'] ?? '')));
        if ($question === '') {
            return '';
        }

        $emailtokens = ['mail', 'email', 'e-mail', 'nachricht', 'confirmation mail'];
        foreach ($emailtokens as $token) {
            if (strpos($question, $token) !== false) {
                return 'missing_email';
            }
        }

        $cannotbooktokens = [
            'cannot book',
            'can not book',
            'can\'t book',
            'cannot enroll',
            'cannot sign up',
            'can\'t sign up',
            'nicht eintragen',
            'nicht buchen',
            'nicht anmelden',
            'nicht mehr anmelden',
            'kann ich mich nicht anmelden',
        ];
        foreach ($cannotbooktokens as $token) {
            if (strpos($question, $token) !== false) {
                return 'cannot_book';
            }
        }

        $statustokens = ['not booked', 'nicht eingetragen', 'nicht gebucht', 'why am i not', 'warum bin ich'];
        foreach ($statustokens as $token) {
            if (strpos($question, $token) !== false) {
                return 'booking_status';
            }
        }

        return 'booking_status';
    }

    /**
     * Validate whether the task has enough option information.
     *
     * @param array<string,mixed> $input
     * @param int $cmid
     * @return array{errors:array<int,string>,ambiguities:array<int,string>}
     */
    private function validate_option_reference(array $input, int $cmid, string $lang = ''): array {
        $errors = [];
        $ambiguities = [];

        $optionid = (int)($input['optionid'] ?? 0);
        $optionquery = trim((string)($input['optionquery'] ?? ''));

        if ($optionid <= 0 && $optionquery === '') {
            $ambiguities[] = $this->localized_string('agent_booking_diagnose_ambiguity_option_required', null, $lang);
            return ['errors' => $errors, 'ambiguities' => $ambiguities];
        }

        if ($optionid > 0) {
            return ['errors' => $errors, 'ambiguities' => $ambiguities];
        }

        if (booking_task_support::is_last_option_reference($optionquery)) {
            return ['errors' => $errors, 'ambiguities' => $ambiguities];
        }

        $resolved = booking_task_support::resolve_single_option($cmid, $optionquery, '');
        if (($resolved['status'] ?? '') === 'ambiguity') {
            $ambiguities[] = (string)($resolved['message']
                ?? $this->localized_string('agent_booking_diagnose_ambiguity_option_specify', null, $lang));
        } else if (($resolved['status'] ?? '') === 'error') {
            $errors[] = (string)($resolved['message']
                ?? $this->localized_string('agent_booking_diagnose_error_option_resolve', null, $lang));
        }

        return ['errors' => $errors, 'ambiguities' => $ambiguities];
    }

    /**
     * Resolve the target option id from explicit id, query or last preview selection.
     *
     * @param array<string,mixed> $input
     * @param int $cmid
     * @param int $userid
     * @return array<string,mixed>
     */
    private function resolve_option_id(array $input, int $cmid, int $userid, string $lang = ''): array {
        global $DB;

        $optionid = (int)($input['optionid'] ?? 0);
        if ($optionid > 0) {
            $cm = get_coursemodule_from_id('booking', $cmid, 0, false, MUST_EXIST);
            if ($DB->record_exists('booking_options', ['id' => $optionid, 'bookingid' => (int)$cm->instance])) {
                return ['status' => 'ok', 'optionid' => $optionid];
            }
            return [
                'status' => 'error',
                'message' => $this->localized_string('agent_booking_diagnose_error_option_not_in_instance', null, $lang),
            ];
        }

        $optionquery = trim((string)($input['optionquery'] ?? ''));
        if ($optionquery === '') {
            return [
                'status' => 'ambiguity',
                'message' => $this->localized_string('agent_booking_diagnose_ambiguity_option_title_or_id', null, $lang),
            ];
        }

        if (booking_task_support::is_last_option_reference($optionquery)) {
            $lastids = booking_task_support::resolve_last_preview_option_ids_for_user_for_execute($cmid, $userid);
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

        return booking_task_support::resolve_single_option($cmid, $optionquery, '');
    }

    /**
     * Extract normalized user status key from booking information.
     *
     * @param array<string,mixed> $bookinginformation
     * @return string
     */
    private function collect_option_stats(int $bookingid, int $optionid, int $userid, object $settings): array {
        global $DB;

        $records = $DB->get_records('booking_answers', [
            'bookingid' => $bookingid,
            'optionid' => $optionid,
        ], 'timemodified DESC, id DESC', 'id, userid, waitinglist, places');

        $userstatus = 'notbooked';
        $bookedplaces = 0;
        $waitingplaces = 0;
        $notifylist = false;

        foreach ($records as $record) {
            $places = max(1, (int)($record->places ?? 1));
            $waitinglist = (int)($record->waitinglist ?? MOD_BOOKING_STATUSPARAM_NOTBOOKED);

            if ($waitinglist === MOD_BOOKING_STATUSPARAM_BOOKED) {
                $bookedplaces += $places;
            } else if ($waitinglist === MOD_BOOKING_STATUSPARAM_WAITINGLIST) {
                $waitingplaces += $places;
            }

            if ((int)$record->userid !== $userid) {
                continue;
            }

            if ($waitinglist === MOD_BOOKING_STATUSPARAM_BOOKED) {
                $userstatus = 'booked';
                continue;
            }
            if ($waitinglist === MOD_BOOKING_STATUSPARAM_WAITINGLIST && $userstatus !== 'booked') {
                $userstatus = 'waitinglist';
                continue;
            }
            if ($waitinglist === MOD_BOOKING_STATUSPARAM_RESERVED && !in_array($userstatus, ['booked', 'waitinglist'], true)) {
                $userstatus = 'reserved';
                continue;
            }
            if ($waitinglist === MOD_BOOKING_STATUSPARAM_NOTIFYMELIST && $userstatus === 'notbooked') {
                $userstatus = 'notifylist';
                $notifylist = true;
            }
        }

        $maxanswers = (int)($settings->maxanswers ?? 0);
        $maxoverbooking = (int)($settings->maxoverbooking ?? 0);

        return [
            'userstatus' => $userstatus,
            'bookedplaces' => $bookedplaces,
            'waitingplaces' => $waitingplaces,
            'notifylist' => $notifylist,
            'maxanswers' => $maxanswers,
            'maxoverbooking' => $maxoverbooking,
            'fullybooked' => $maxanswers > 0 ? $bookedplaces >= $maxanswers : false,
            'waitinglistfull' => $maxoverbooking > 0 ? $waitingplaces >= $maxoverbooking : false,
        ];
    }

    /**
     * Build concrete reason lines for the diagnosis.
     *
     * @param string $issuetype
     * @param array<string,mixed> $optionstats
     * @param array<int,array<string,mixed>> $conditionresults
     * @return array<int,string>
     */
    private function build_reason_lines(string $issuetype, array $optionstats, array $conditionresults, string $lang = ''): array {
        $reasons = [];
        $userstatus = (string)($optionstats['userstatus'] ?? 'notbooked');

        if ($issuetype === 'booking_status') {
            if ($userstatus === 'booked') {
                $reasons[] = $this->localized_string('agent_booking_diagnose_reason_status_booked', null, $lang);
            } else if ($userstatus === 'waitinglist') {
                $reasons[] = $this->localized_string('agent_booking_diagnose_reason_status_waitinglist', null, $lang);
            } else if ($userstatus === 'reserved') {
                $reasons[] = $this->localized_string('agent_booking_diagnose_reason_status_reserved', null, $lang);
            } else if ($userstatus === 'notifylist') {
                $reasons[] = $this->localized_string('agent_booking_diagnose_reason_status_notifylist', null, $lang);
            } else {
                $reasons[] = $this->localized_string('agent_booking_diagnose_reason_status_notbooked', null, $lang);
            }
        }

        if ($issuetype === 'cannot_book' || $issuetype === 'booking_status') {
            if ($userstatus === 'booked') {
                $reasons[] = $this->localized_string('agent_booking_diagnose_reason_cannot_book_already_booked', null, $lang);
            }

            if (!empty($optionstats['fullybooked'])) {
                $reasons[] = $this->localized_string('agent_booking_diagnose_reason_cannot_book_fully_booked', null, $lang);
            }

            if ((int)($optionstats['maxoverbooking'] ?? 0) === 0 && !empty($optionstats['fullybooked'])) {
                $reasons[] = $this->localized_string('agent_booking_diagnose_reason_cannot_book_no_waitinglist', null, $lang);
            } else if ((int)($optionstats['maxoverbooking'] ?? 0) > 0) {
                if (!empty($optionstats['waitinglistfull'])) {
                    $reasons[] = $this->localized_string('agent_booking_diagnose_reason_cannot_book_waitinglist_full', null, $lang);
                } else if (!empty($optionstats['fullybooked'])) {
                    $reasons[] = $this->localized_string('agent_booking_diagnose_reason_cannot_book_waitinglist_available', null, $lang);
                }
            }

            foreach ($conditionresults as $condition) {
                $description = trim(strip_tags((string)($condition['description'] ?? '')));
                if ($description !== '' && strtolower($description) !== 'book now') {
                    $reasons[] = $description;
                }
            }
        }

        if ($issuetype === 'missing_email') {
            if ($userstatus === 'booked') {
                $reasons[] = $this->localized_string('agent_booking_diagnose_reason_missing_email_booked', null, $lang);
            } else if ($userstatus === 'waitinglist') {
                $reasons[] = $this->localized_string('agent_booking_diagnose_reason_missing_email_waitinglist', null, $lang);
            } else {
                $reasons[] = $this->localized_string('agent_booking_diagnose_reason_missing_email_not_booked', null, $lang);
            }
            $reasons[] = $this->localized_string('agent_booking_diagnose_reason_missing_email_limitations', null, $lang);
            $reasons[] = $this->localized_string('agent_booking_diagnose_reason_missing_email_manager_check', null, $lang);
        }

        $reasons = array_values(array_unique(array_filter(array_map('trim', $reasons))));
        if (empty($reasons)) {
            $reasons[] = $this->localized_string('agent_booking_diagnose_reason_none', null, $lang);
        }

        return $reasons;
    }

    /**
     * Build a concise user-facing diagnosis text.
     *
     * @param string $issuetype
     * @param string $optionname
     * @param string $userstatus
     * @param array<int,string> $reasons
     * @return string
     */
    private function build_user_message(
        string $issuetype,
        string $optionname,
        string $userstatus,
        array $reasons,
        string $lang = ''
    ): string {
        $intro = $this->localized_string('agent_booking_diagnose_intro_checked_option', $optionname, $lang);

        if ($issuetype === 'missing_email') {
            $intro .= ' ' . $this->localized_string('agent_booking_diagnose_intro_missing_email', null, $lang);
        } else if ($issuetype === 'cannot_book') {
            $intro .= ' ' . $this->localized_string('agent_booking_diagnose_intro_cannot_book', null, $lang);
        } else {
            $statuslabel = $this->localized_string('agent_booking_diagnose_status_' . $userstatus, null, $lang);
            $intro .= ' ' . $this->localized_string('agent_booking_diagnose_intro_status', $statuslabel, $lang);
        }

        $lines = array_map(static fn(string $reason): string => '- ' . $reason, $reasons);
        return $intro . "\n" . implode("\n", $lines);
    }
}