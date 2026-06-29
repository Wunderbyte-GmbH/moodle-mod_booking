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
use mod_booking\local\wizard\engine\queue_identity_provider_interface;
use mod_booking\local\wizard\engine\skill_trigger_provider_interface;
use mod_booking\bo_availability\bo_info;

/**
 * Task definition for booking.book_users.
 *
 * Books one or more users into a booking option by running them through the
 * standard bookit flow. All existing booking conditions are enforced.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class book_users_skill extends booking_skill_base implements
    queue_identity_provider_interface,
    skill_trigger_provider_interface {
    /** Task name constant. */
    public const TASK_NAME = 'mod_booking.book_users';

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(false, \mod_booking\local\wizard\engine\skill_risk_class::R3, ['mod/booking:bookforothers']);
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
     * Human-readable preview of the booking (tier-3): target option + resolved participants.
     *
     * @param array $input Prepared input (carries resolved option + user ids).
     * @return array|null
     */
    public function describe_proposed_action(array $input): ?array {
        return option_preview_builder::book_users_descriptor($input);
    }

    /**
     * Build queue business identity for book_users deduplication.
     *
     * @param array $input
     * @return array<string,mixed>
     */
    public function build_queue_business_identity(array $input): array {
        $normalized = $input;

        $targetid = (int)($normalized['resolvedoptionid'] ?? $normalized['optionid'] ?? 0);
        $targetquery = $this->normalize_identity_query((string)($normalized['optionquery'] ?? ''));
        $targetwhen = $this->normalize_identity_query((string)($normalized['optionwhen'] ?? ''));
        $bookedat = booking_skill_support::normalize_identity_datetime((string)($normalized['bookuserstimebooked'] ?? ''));
        $bookusers = $this->extract_identity_users($normalized);

        return [
            'task_family' => 'mod_booking.book_users',
            'target' => [
                'optionid' => $targetid,
                'optionquery' => $targetquery,
                'optionwhen' => $targetwhen,
            ],
            'bookusers' => $bookusers,
            'bookuserstimebooked' => $bookedat,
            'bookuserscompleted' => !empty($normalized['bookuserscompleted']),
            'bookusersupdateexisting' => !empty($normalized['bookusersupdateexisting']),
        ];
    }

    /**
     * Extract normalized users for identity hashing.
     *
     * @param array $input
     * @return array<int,string>
     */
    private function extract_identity_users(array $input): array {
        $users = [];

        foreach ((array)($input['resolvedbookuserids'] ?? []) as $userid) {
            $id = (int)$userid;
            if ($id > 0) {
                $users[] = 'id:' . $id;
            }
        }

        if (empty($users)) {
            $query = trim((string)($input['bookusersquery'] ?? ''));
            if ($query !== '') {
                $parts = preg_split('/\s*,\s*/', $query) ?: [];
                foreach ($parts as $part) {
                    $token = $this->normalize_identity_query((string)$part);
                    if ($token !== '') {
                        $users[] = 'q:' . $token;
                    }
                }
            }
        }

        $users = array_values(array_unique($users));
        sort($users);
        return $users;
    }

    /**
     * Return task schema.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'Book one or more users into an existing booking option. '
                . 'All booking conditions are enforced. Use bookusersquery to name the users and '
                . 'optionquery to name the target option directly (a title or fragment, e.g. the '
                . 'course/event name) — this skill resolves the option itself, so do NOT run a '
                . 'separate search/find step first; call it directly even when only the option name '
                . 'is known.',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'Enrol Maria into the Tuesday cooking course',
                'Book John and Sarah into the Spring Workshop',
                'Add three participants to this option',
                'Register the student with email anna@example.com for the First Aid Course',
                'Sign me up for the yoga class',
            ],
            'properties' => [
                'optionquery' => [
                    'type' => 'string',
                    'description' => 'Booking option title or text fragment to identify the target option.',
                    'required' => false,
                ],
                'optionid' => [
                    'type' => 'integer',
                    'description' => 'Explicit booking option id when already known.',
                    'required' => false,
                ],
                'optionwhen' => [
                    'type' => 'string',
                    'description' => 'Optional temporal hint for disambiguation (e.g. "next monday").',
                    'required' => false,
                ],
                'bookusersquery' => [
                    'type' => 'string',
                    'description' => 'Comma-separated list of user names, e-mails or ids to book.',
                    'required' => true,
                ],
                'bookuserstimebooked' => [
                    'type' => 'string',
                    'description' => 'Optional booking timestamp for imported bookings (ISO 8601 or Unix timestamp).',
                    'required' => false,
                ],
                'bookuserscompleted' => [
                    'type' => 'boolean',
                    'description' => 'If true, mark newly booked users as completed.',
                    'required' => false,
                ],
                'bookusersupdateexisting' => [
                    'type' => 'boolean',
                    'description' => 'If true, update existing booking answers when user is already booked.',
                    'required' => false,
                ],
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'Optional language code for the user-facing summary, e.g. de or en.',
                    'required' => false,
                ],
                'confirmed' => [
                    'type' => 'boolean',
                    'description' => 'Set to true to confirm booking when the target user has soft-only booking '
                        . 'restrictions (e.g. selectuser) that the current actor (admin) can override. '
                        . 'Only set this after the user has explicitly confirmed they want to proceed.',
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
                'id' => 'mod_booking.book_users_for_option',
                'description' => 'User asks to book one or more people into a booking option.',
                'examples' => [
                    'Book a user into an option.',
                    'Book ANON_USER into option "Spring Workshop".',
                    'Please register ANON_USER1 and ANON_USER2 for the cooking course.',
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
                'id' => 'mod_booking.book_users_guidance',
                'triggers' => [
                    'book user', 'register user', 'enroll user',
                ],
                'guidance' => [
                    '- Use booking.book_users to book one or more users into an existing booking option.',
                    '- If you know only the user\'s name (not their id), call booking.search_users FIRST,',
                    '  wait for the observation with the resolved userid, then call booking.book_users.',
                    '- Pass bookusersquery as a comma-separated list of names, e-mails, or user ids.',
                    '- Pass optionquery with the option title when the option is named in the request.',
                    '- If the user already named a concrete option title (e.g. "My event"),',
                    '  pass it directly as optionquery and do not ask for optionid first.',
                    '- Prefer one direct booking call with optionquery + bookusersquery when both are grounded.',
                    '- Do NOT use booking.update_option just to book users; use booking.book_users instead.',
                    '- If the option cannot be found, ask the user for clarification before proceeding.',
                    '- If preflight returns a soft-override confirmation issue, ask the user for confirmation and '
                        . 'then call again with confirmed=true to proceed.',
                ],
            ],
        ];
    }

    /**
     * Check task input structure.
     *
     * @param array $input
     * @return array{valid:bool,errors:array<int,string>}
     */
    public function check_structure(array $input): array {
        $errors = [];
        $lang = $this->get_output_language($input);

        $bookusersquery = $this->normalize_query_text($input['bookusersquery'] ?? '');
        $explicituserids = $this->extract_explicit_user_ids($input);
        if ($bookusersquery === '' && empty($explicituserids)) {
            $errors[] = $this->localized_string('agent_booking_book_users_required_bookusersquery', null, $lang);
        }

        $optionid = (int)($input['optionid'] ?? 0);
        $optionquery = $this->normalize_query_text($input['optionquery'] ?? '');
        if ($optionid <= 0 && $optionquery === '') {
            $errors[] = $this->localized_string('agent_booking_diagnose_ambiguity_option_required', null, $lang);
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Deep preflight validation and entity preparation.
     *
     * Runs a two-level condition pre-check for each resolved user:
     *   1. get_condition_results(..., false) - all blockers (soft + hard)
     *   2. get_condition_results(..., true)  - only true hard blockers
     *
     * @param array $input
     * @param int $cmid
     * @param int $userid
     * @return array{status:string,prepared_input:array,issues:array}
     */
    protected function run_preflight(array $input, int $cmid, int $userid): array {
        $cmid = $this->resolve_cmid_from_context_or_cmid($cmid);
        $capdenied = $this->require_native_capability('mod/booking:bookforothers', $cmid, $userid);
        if ($capdenied !== null) {
            return $capdenied;
        }
        $structure = $this->check_structure($input);
        if (!($structure['valid'] ?? false)) {
            return $this->invalid($this->build_preflight_issues((array)($structure['errors'] ?? [])));
        }

        $issues = [];
        $lang = $this->get_output_language($input);
        $preparedinput = $input;

        $resolvedoption = $this->resolve_option_id($input, $cmid, $userid, $lang);
        $resolvedoptionid = 0;
        if (($resolvedoption['status'] ?? '') === 'ok') {
            $resolvedoptionid = (int)($resolvedoption['optionid'] ?? 0);
            $preparedinput['resolvedoptionid'] = $resolvedoptionid;
            $preparedinput['optionid'] = $resolvedoptionid;
        } else if (($resolvedoption['status'] ?? '') === 'ambiguity') {
            $issues = array_merge($issues, $this->build_preflight_issues(
                [(string)($resolvedoption['message'] ?? '')],
                (string)($resolvedoption['issue_code'] ?? 'BOOK_USERS_OPTION_AMBIGUOUS')
            ));
        } else {
            $issues = array_merge($issues, $this->build_preflight_issues([
                (string)($resolvedoption['message'] ?? get_string(
                    'agent_booking_book_users_option_resolve_failed',
                    'booking'
                )),
            ], (string)($resolvedoption['issue_code'] ?? 'BOOK_USERS_OPTION_NOT_FOUND')));
        }

        $bookuserids = $this->extract_explicit_user_ids($input);
        if (empty($bookuserids)) {
            $bookusersquery = $this->normalize_query_text($input['bookusersquery'] ?? '');
            $usersforbooking = booking_skill_support::resolve_users_for_booking($bookusersquery);
            if (!empty($usersforbooking['issues']) && is_array($usersforbooking['issues'])) {
                foreach ($usersforbooking['issues'] as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $msg = trim((string)($entry['message'] ?? ''));
                    if ($msg === '') {
                        continue;
                    }
                    $issues[] = [
                        'code' => trim((string)($entry['code'] ?? 'BOOK_USERS_USER_RESOLVE_ERROR')),
                        'severity' => trim((string)($entry['severity'] ?? 'needs_clarification')),
                        'message' => $msg,
                    ];
                }
            } else {
                if (!empty($usersforbooking['errors'])) {
                    $issues = array_merge($issues, $this->build_preflight_issues(
                        (array)$usersforbooking['errors'],
                        'BOOK_USERS_USER_RESOLVE_ERROR'
                    ));
                }
                if (!empty($usersforbooking['ambiguities'])) {
                    $issues = array_merge($issues, $this->build_preflight_issues(
                        (array)$usersforbooking['ambiguities'],
                        'BOOK_USERS_USER_AMBIGUOUS'
                    ));
                }
            }
            $bookuserids = array_values(array_filter(array_map('intval', (array)($usersforbooking['userids'] ?? []))));
        }
        if (!empty($bookuserids)) {
            $preparedinput['resolvedbookuserids'] = $bookuserids;
        }

        // Condition pre-check: detect soft-only blockers that an admin can override.
        //
        // Logic:
        // - get_condition_results(..., false) returns ALL blocking conditions.
        // - get_condition_results(..., true)  rechecks each blocker via hard_block():
        // conditions like selectuser that are admin-overridable become isavailable=true.
        // - If allblockers is non-empty but hardblockers is empty -> soft override scenario.
        // The target user cannot book themselves but the current actor can book for them.
        // We return a structured confirmation issue so the existing confirm-button flow is used.
        // - If hardblockers is non-empty -> real hard block, nobody can book -> error.
        //
        // Skip the check when the caller already confirmed (confirmed=true).
        $confirmed = !empty($input['confirmed']);
        if (!$confirmed && $resolvedoptionid > 0 && empty($issues)) {
            $softoverridelines = [];
            foreach ($bookuserids as $uid) {
                $uid = (int)$uid;
                if ($uid <= 0) {
                    continue;
                }

                // All conditions (soft + hard) for this user.
                $allresults = bo_info::get_condition_results($resolvedoptionid, $uid, false);
                $allblockers = array_filter(
                    $allresults,
                    static fn(array $r): bool => !(bool)($r['isavailable'] ?? true) && (int)($r['id'] ?? 0) > 1
                );

                if (empty($allblockers)) {
                    // No restrictions at all - proceed normally.
                    continue;
                }

                // Hard-block-only check: conditions that truly block even for privileged actors.
                $hardresults = bo_info::get_condition_results($resolvedoptionid, $uid, true);
                $hardblockers = array_filter(
                    $hardresults,
                    static fn(array $r): bool => !(bool)($r['isavailable'] ?? true) && (int)($r['id'] ?? 0) > 1
                );

                if (!empty($hardblockers)) {
                    // Real hard blockers - nobody can book this user into this option.
                    $descriptions = $this->summarize_condition_descriptions($hardblockers);
                    $issues = array_merge($issues, $this->build_preflight_issues([
                        get_string('agent_booking_user_cannot_book_hard_block', 'booking', (object)[
                            'userid' => $uid,
                            'descriptions' => $descriptions,
                        ]),
                    ], 'BOOK_USERS_NOT_ALLOWED'));
                } else {
                    // Soft blockers only: the target user could not book themselves,
                    // but the current actor (admin) has the right to book on their behalf.
                    $descriptions = $this->summarize_condition_descriptions($allblockers);
                    $softoverridelines[] = get_string('agent_booking_book_users_soft_block', 'booking', (object)[
                        'userid' => $uid,
                        'descriptions' => $descriptions,
                    ]);
                }
            }

            if (!empty($softoverridelines)) {
                $issues[] = [
                    'code' => 'SOFT_BOOKING_OVERRIDE_CONFIRM_REQUIRED',
                    'severity' => 'needs_confirmation',
                    'user_question' => implode(' ', $softoverridelines)
                        . ' ' . get_string('agent_booking_book_users_soft_block_confirm', 'booking'),
                ];
            }
        }

        if (!empty($issues) && !$this->has_confirmation_issue($issues)) {
            return $this->invalid($issues);
        }

        if ($this->has_confirmation_issue($issues)) {
            return $this->confirmable($preparedinput, $issues);
        }

        return $this->pass($preparedinput);
    }

    /**
     * Convert messages to preflight issues.
     *
     * @param array $messages
     * @param string $code
     * @return array<int,array<string,string>>
     */
    private function build_preflight_issues(array $messages, string $code = 'BOOK_USERS_PREFLIGHT_BLOCKED'): array {
        $issues = [];
        foreach ($messages as $message) {
            $message = trim((string)$message);
            if ($message === '') {
                continue;
            }
            $issues[] = [
                'code' => $code,
                'severity' => 'needs_clarification',
                'message' => $message,
            ];
        }
        return $issues;
    }

    /**
     * Whether issue list contains at least one confirmation issue.
     *
     * @param array $issues
     * @return bool
     */
    private function has_confirmation_issue(array $issues): bool {
        foreach ($issues as $issue) {
            if (!is_array($issue)) {
                continue;
            }
            if (trim((string)($issue['severity'] ?? '')) === 'needs_confirmation') {
                return true;
            }
        }

        return false;
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
        $cmid = $this->resolve_cmid_from_context_or_cmid($cmid);
        $outputlang = $this->get_output_language($input);

        $optionid = (int)($input['resolvedoptionid'] ?? 0);
        if ($optionid <= 0) {
            $resolvedoption = $this->resolve_option_id($input, $cmid, $userid, $outputlang);
            if (($resolvedoption['status'] ?? '') !== 'ok') {
                return [
                    'status' => 'error',
                    'detail' => (string)($resolvedoption['message'] ?? get_string(
                        'agent_booking_book_users_option_resolve_failed',
                        'booking'
                    )),
                    'issue_codes' => [(string)($resolvedoption['issue_code'] ?? 'BOOK_USERS_OPTION_NOT_FOUND')],
                    'resultid' => null,
                    'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, ['Status: error']),
                ];
            }
            $optionid = (int)$resolvedoption['optionid'];
        }

        $bookuserids = array_values(array_filter(array_map('intval', (array)($input['resolvedbookuserids'] ?? []))));
        if (empty($bookuserids)) {
            $bookuserids = $this->extract_explicit_user_ids($input);
        }
        if (empty($bookuserids)) {
            $bookusersquery = $this->normalize_query_text($input['bookusersquery'] ?? '');
            $usersforbooking = booking_skill_support::resolve_users_for_booking($bookusersquery);
            if (!empty($usersforbooking['errors']) || !empty($usersforbooking['ambiguities'])) {
                return [
                    'status' => 'error',
                    'detail' => trim(implode(' ', array_merge(
                        $usersforbooking['errors'],
                        $usersforbooking['ambiguities']
                    ))),
                    'issue_codes' => array_values(array_unique(array_map(
                        'strval',
                        (array)($usersforbooking['issue_codes'] ?? [])
                    ))),
                    'resultid' => null,
                    'debugmessage' => $this->build_task_debug_message(
                        self::TASK_NAME,
                        $input,
                        ['Status: error (user resolve)']
                    ),
                ];
            }
            $bookuserids = array_values(array_filter(array_map('intval', (array)($usersforbooking['userids'] ?? []))));
        }
        if (empty($bookuserids)) {
            return [
                'status' => 'error',
                'detail' => $this->localized_string('agent_booking_book_users_required_bookusersquery', null, $outputlang),
                'issue_codes' => ['BOOK_USERS_USER_NOT_FOUND'],
                'resultid' => null,
                'debugmessage' => $this->build_task_debug_message(
                    self::TASK_NAME,
                    $input,
                    ['Status: error (no users resolved)']
                ),
            ];
        }

        // Build meta.
        $meta = [
            'completed' => !empty($input['bookuserscompleted']),
            'updateexisting' => !empty($input['bookusersupdateexisting']),
            'timebooked' => null,
        ];
        if (isset($input['bookuserstimebooked'])) {
            $timebooked = booking_skill_support::parse_datetime($input['bookuserstimebooked']);
            if ($timebooked !== false) {
                $meta['timebooked'] = $timebooked;
            }
        }

        // Execute booking via the standard bookit flow.
        // book_users_for_option uses get_condition_results(..., true) internally, so
        // admin-overridable conditions (like selectuser) do not block execution.
        $result = booking_skill_support::book_users_for_option($optionid, $bookuserids, $meta);

        if (!empty($result['errors'])) {
            return [
                'status' => 'error',
                'detail' => implode(' ', $result['errors']),
                'issue_codes' => array_values(array_unique(array_map('strval', (array)($result['issue_codes'] ?? [])))),
                'resultid' => $optionid,
                'debugmessage' => $this->build_task_debug_message(
                    self::TASK_NAME,
                    $input,
                    [
                        'Status: error',
                        'Booked: ' . implode(', ', $result['bookeduserids']),
                        'Errors: ' . implode(' ', $result['errors']),
                    ]
                ),
            ];
        }

        $bookeduserids = $result['bookeduserids'];
        $detail = get_string('agent_booking_book_users_booked', 'booking', (object)[
            'count' => count($bookeduserids),
            'optionid' => $optionid,
            'userids' => implode(', ', $bookeduserids),
        ]);
        // Entity mentions always travel with real moodle_url links (option + user
        // profiles) so the synchronizer presents them clickable without inventing URLs.
        if ($cmid > 0) {
            $detail .= ' Option: ' . booking_skill_support::build_option_link_for_output($cmid, $optionid) . '.';
        }
        $userlinks = booking_skill_support::format_user_links($bookeduserids);
        if ($userlinks !== '') {
            $detail .= ' ' . get_string('agent_booking_booked_users_label', 'booking') . ': '
                . $userlinks . '.';
        }

        return [
            'status' => 'executed',
            'detail' => $detail,
            'summary' => $detail,
            'usermessage' => $detail,
            'resultid' => $optionid,
            'previewoptionids' => [$optionid],
            'debugmessage' => $this->build_task_debug_message(
                self::TASK_NAME,
                $input,
                [
                    'Status: executed',
                    'Option id: ' . $optionid,
                    'Booked user ids: ' . implode(', ', $bookeduserids),
                ]
            ),
        ];
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

        $optionquery = $this->normalize_query_text($input['optionquery'] ?? '');
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
                    'message' => $this->localized_string(
                        'agent_booking_diagnose_ambiguity_last_preview_multiple',
                        null,
                        $lang
                    ),
                ];
            }
            return [
                'status' => 'error',
                'message' => $this->localized_string('agent_booking_diagnose_error_last_preview_none', null, $lang),
            ];
        }

        return booking_skill_support::resolve_single_option($cmid, $optionquery, (string)($input['optionwhen'] ?? ''));
    }

    /**
     * Build a short human-readable summary from a set of blocking condition results.
     *
     * @param array $blockers  Filtered subset from bo_info::get_condition_results()
     * @return string
     */
    private function summarize_condition_descriptions(array $blockers): string {
        $parts = [];
        foreach ($blockers as $result) {
            $classname = (string)($result['classname'] ?? '');
            $classparts = explode('\\', $classname);
            $shortname = strtolower((string)end($classparts));
            $description = trim(strip_tags((string)($result['description'] ?? '')));
            if ($description !== '') {
                $parts[] = $shortname . ': ' . $description;
            } else {
                $parts[] = $shortname;
            }
        }
        $parts = array_values(array_unique($parts));
        return implode('; ', $parts);
    }

    /**
     * Normalize free-text task inputs that may arrive as string OR array values.
     *
     * @param mixed $value
     * @return string
     */
    private function normalize_query_text($value): string {
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                if (is_array($item)) {
                    continue;
                }
                $text = trim((string)$item);
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
            return trim(implode(', ', $parts));
        }

        return trim((string)$value);
    }

    /**
     * Extract explicit user ids from input.userids when present.
     *
     * @param array $input
     * @return array<int,int>
     */
    private function extract_explicit_user_ids(array $input): array {
        $raw = $input['userids'] ?? [];
        if (!is_array($raw)) {
            $raw = [$raw];
        }

        return array_values(array_filter(array_map('intval', $raw)));
    }
}
