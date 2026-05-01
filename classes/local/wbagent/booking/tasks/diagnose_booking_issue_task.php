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
use mod_booking\local\wbagent\services\answering\diagnose_answering_service;
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
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'Diagnose why the current user (or a specified target user) is not booked, cannot book, ' .
                'or did not receive email '
                .
                'for a booking option or have any other issue regarding a booking option.',
            'readonly' => $this->is_read_only(),
            'properties' => [
                'question' => [
                    'type' => 'string',
                    'description' => 'The user question in natural language, e.g. "Why am I not booked for option X?"',
                    'required' => true,
                ],
                'optionquery' => [
                    'type' => 'string',
                    'description' => 'Booking option title, id-like reference, or words like "last option" '
                        . 'when referring to the last shown option.',
                    'required' => false,
                ],
                'optionid' => [
                    'type' => 'integer',
                    'description' => 'Explicit booking option id when already known.',
                    'required' => false,
                ],
                'userquery' => [
                    'type' => 'string',
                    'description' => 'Optional user reference (name/email/id text) when diagnosing another person.',
                    'required' => false,
                ],
                'targetuserid' => [
                    'type' => 'integer',
                    'description' => 'Optional explicit Moodle user id to diagnose instead of the current user.',
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
     * @return array
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'booking.diagnose_booking_issue_self_help',
                'description' => 'User asks why they or another person are not booked, cannot book, '
                    . 'did not receive mail for a booking option or have any other issue regarding a booking option.',
                'examples' => [
                    'Warum bin ich bei Buchungsoption XY nicht eingetragen?',
                    'Wieso habe ich keine Mail von der Buchungsoption XY bekommen?',
                    'Warum kann ich mich bei Buchungsoption XY nicht eintragen?',
                    'Kann Maxima in "Lesung mit Georg" buchen?',
                ],
            ],
        ];
    }

    /**
     * Return contextual guidance packs.
     *
     * @return array
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
                    '- If the question is about another person, pass userquery or targetuserid explicitly.',
                    '- Pass optionquery when the option title/reference is available; '
                        . 'otherwise the task will ask a follow-up question.',
                ],
            ],
        ];
    }

    /**
     * Validate task input.
     *
     * @param array $input
     * @param int $cmid
     * @return array
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
     * @param array $input
     * @param int $cmid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $cmid, int $userid): array {
        global $DB;
        $outputlang = $this->get_output_language($input);

        $resolveduser = $this->resolve_diagnostic_user($input, $userid, $outputlang);
        if (($resolveduser['status'] ?? '') !== 'ok') {
            return [
                'status' => 'error',
                'detail' => (string)($resolveduser['message']
                    ?? $this->localized_string('agent_booking_resolve_user_query_required', null, $outputlang)),
                'resultid' => null,
                'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, ['Status: error']),
            ];
        }
        $diagnosticuserid = (int)($resolveduser['userid'] ?? $userid);

        if ($diagnosticuserid !== $userid && !$this->can_analyze_other_user($cmid)) {
            return [
                'status' => 'error',
                'detail' => $this->get_other_user_permission_error_message($outputlang),
                'resultid' => null,
                'debugmessage' => $this->build_task_debug_message(
                    self::TASK_NAME,
                    $input,
                    ['Status: error', 'Reason: missing permission for cross-user diagnosis']
                ),
            ];
        }

        $issuetype = $this->resolve_issue_type($input);
        $resolvedoption = $this->resolve_option_id($input, $cmid, $userid, $outputlang);
        if (($resolvedoption['status'] ?? '') !== 'ok') {
            return [
                'status' => 'error',
                'detail' => (string)($resolvedoption['message']
                    ?? $this->localized_string('agent_booking_diagnose_error_option_resolve', null, $outputlang)),
                'resultid' => null,
                'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, ['Status: error']),
            ];
        }

        $optionid = (int)($resolvedoption['optionid'] ?? 0);
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $conditionresults = bo_info::get_condition_results($optionid, $diagnosticuserid);
        $optionname = (string)$DB->get_field('booking_options', 'text', ['id' => $optionid]) ?: ('Option #' . $optionid);
        $ba = singleton_service::get_instance_of_booking_answers($settings);
        $optionstats = $ba->return_all_booking_information($diagnosticuserid);
        $userstatus = (string)$ba->user_status_as_string($diagnosticuserid);
        $optionstats['userstatus'] = $userstatus;
        $optionstats['settings'] = $settings;
        $reasons = $this->build_reason_lines($issuetype, $optionstats, $conditionresults);
        $usermessage = '';
        $answersource = 'none';
        try {
            $answeringresult = $this->create_diagnose_answering_service()->answer_question(
                (string)($input['question'] ?? ''),
                [
                    'issuetype' => $issuetype,
                    'optionname' => $optionname,
                    'userstatus' => $userstatus,
                    'reasons' => $reasons,
                    'stats' => $optionstats,
                ],
                $outputlang,
                $cmid,
                $userid
            );
            $llmanswer = trim((string)($answeringresult['answer'] ?? ''));
            if ($llmanswer !== '') {
                $usermessage = $this->enforce_max_chars($llmanswer, 500);
                $answersource = 'llm';
            }
        } catch (\Throwable $e) {
            $answersource = 'error';
        }

        return [
            'status' => 'executed',
            'detail' => $usermessage,
            'summary' => $usermessage,
            'usermessage' => $usermessage,
            'resultid' => $optionid,
            'previewoptionids' => [$optionid],
            'diagnosis' => [
                'issue' => $issuetype,
                'userid' => $diagnosticuserid,
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
                    'Diagnostic user id: ' . $diagnosticuserid,
                    'Issue: ' . $issuetype,
                    'User status: ' . $userstatus,
                    'Reasons: ' . count($reasons),
                    'Answer source: ' . $answersource,
                ]
            ),
        ];
    }

    /**
     * Resolve issue type from explicit input or the question text.
     *
     * @param array $input
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
     * Resolve diagnostic target user from explicit input or question fallback.
     *
     * @param array $input
     * @param int $currentuserid
     * @param string $lang
     * @return array
     */
    private function resolve_diagnostic_user(array $input, int $currentuserid, string $lang = ''): array {
        global $DB;

        $targetuserid = (int)($input['targetuserid'] ?? 0);
        if ($targetuserid > 0) {
            if (!$DB->record_exists('user', ['id' => $targetuserid, 'deleted' => 0])) {
                return [
                    'status' => 'error',
                    'message' => $this->localized_string('agent_booking_resolve_user_no_match', $targetuserid, $lang),
                ];
            }
            return ['status' => 'ok', 'userid' => $targetuserid];
        }

        $question = trim((string)($input['question'] ?? ''));
        $userquery = trim((string)($input['userquery'] ?? ''));

        $candidatequeries = [];
        if ($userquery !== '') {
            $candidatequeries[] = $userquery;
        }

        $inferredquery = $this->infer_user_query_from_question($question);
        if ($inferredquery !== '' && !in_array($inferredquery, $candidatequeries, true)) {
            $candidatequeries[] = $inferredquery;
        }

        if (empty($candidatequeries)) {
            return ['status' => 'ok', 'userid' => $currentuserid];
        }

        $lastresolved = [
            'status' => 'error',
            'message' => $this->localized_string('agent_booking_resolve_user_query_required', null, $lang),
        ];

        foreach ($candidatequeries as $candidatequery) {
            $resolved = booking_task_support::resolve_single_user($candidatequery);
            if (($resolved['status'] ?? '') === 'ok') {
                return ['status' => 'ok', 'userid' => (int)($resolved['userid'] ?? $currentuserid)];
            }

            $lastresolved = $resolved;
        }

        return $lastresolved;
    }

    /**
     * Best-effort extraction of a person reference from question text.
     *
     * @param string $question
     * @return string
     */
    private function infer_user_query_from_question(string $question): string {
        $question = trim($question);
        if ($question === '') {
            return '';
        }

        $patterns = [
            '/\b([\p{L}\p{M}\-]+\s+[\p{L}\p{M}\-]+)\s+is\s+booked\b/ui',
            '/\bfor\s+([\p{L}\p{M}\-]+\s+[\p{L}\p{M}\-]+)\b/ui',
            '/\bkann\s+([\p{L}\p{M}\-]+(?:\s+[\p{L}\p{M}\-]+)?)\s+in\b/ui',
            '/\bcan\s+([\p{L}\p{M}\-]+(?:\s+[\p{L}\p{M}\-]+)?)\s+book\b/ui',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $question, $matches) && !empty($matches[1])) {
                return trim((string)$matches[1]);
            }
        }

        return '';
    }

    /**
     * Check if current user may diagnose another user in this booking context.
     *
     * @param int $cmid
     * @return bool
     */
    private function can_analyze_other_user(int $cmid): bool {
        $context = \context_module::instance($cmid);
        return has_capability('mod/booking:bookforothers', $context);
    }

    /**
     * Permission denied message for cross-user diagnostics.
     *
     * @param string $lang
     * @return string
     */
    private function get_other_user_permission_error_message(string $lang = ''): string {
        return $this->localized_string('agent_booking_diagnose_other_user_permission_denied', null, $lang);
    }

    /**
     * Validate whether the task has enough option information.
     *
     * @param array $input
     * @param int $cmid
     * @param string $lang
     * @return array
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
     * @param array $input
     * @param int $cmid
     * @param int $userid
     * @param string $lang
     * @return array
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
     * Create the diagnose answering service.
     *
     * @return diagnose_answering_service
     */
    protected function create_diagnose_answering_service(): diagnose_answering_service {
        return new diagnose_answering_service();
    }

    /**
     * Build concrete reason lines for the diagnosis (used as input for the LLM prompt).
     *
     * @param string $issuetype
     * @param array $optionstats
     * @param array $conditionresults
     * @return array
     */
    private function build_reason_lines(string $issuetype, array $optionstats, array $conditionresults): array {
        $lang = '';
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
                    $reasons[] = $this->localized_string(
                        'agent_booking_diagnose_reason_cannot_book_waitinglist_available',
                        null,
                        $lang
                    );
                }
            }

            foreach ($conditionresults as $condition) {
                try {
                    $class = $condition['classname']::instance();
                } catch (\Throwable $e) {
                    $class = new $condition['classname']();
                }

                if (method_exists($class, 'get_description_string')) {
                    $description = $class->get_description_string(false, true, $optionstats['settings']);
                } else {
                    $description = $condition["description"] ?? '';
                }
                $description = trim(strip_tags((string)($description)));
                if ($description !== '' && strtolower($description) !== 'book now') {
                    $reasons[] = $description . ' Blocking class: ' .  $condition["classname"];
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
}
