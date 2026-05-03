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
class book_users_task extends base_booking_task implements task_trigger_provider_interface {
    /** Task name constant. */
    public const TASK_NAME = 'booking.book_users';

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
            'description' => 'Book one or more users into an existing booking option. '
                . 'All booking conditions are enforced. Use bookusersquery to name the users.',
            'readonly' => $this->is_read_only(),
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
                'id' => 'booking.book_users_for_option',
                'description' => 'User asks to book one or more people into a booking option.',
                'examples' => [
                    'Book Max Müller into option "Spring Workshop".',
                    'Please register Anna and Bob for the cooking course.',
                    'Buche Lisa für die Lesung mit Georg.',
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
                'id' => 'booking.book_users_guidance',
                'triggers' => [
                    'book user', 'register user', 'enroll user', 'buche', 'einschreiben', 'eintragen',
                ],
                'guidance' => [
                    '- Use booking.book_users to book one or more users into an existing booking option.',
                    '- Pass bookusersquery as a comma-separated list of names, e-mails, or user ids.',
                    '- Pass optionquery with the option title when the option is named in the request.',
                    '- Do NOT use booking.update_option just to book users; use booking.book_users instead.',
                    '- If the option cannot be found, ask the user for clarification before proceeding.',
                    '- If validate() returns a soft-override ambiguity, ask the user for confirmation and '
                        . 'then call again with confirmed=true to proceed.',
                ],
            ],
        ];
    }

    /**
     * Validate task input.
     *
     * Runs a two-level condition pre-check for each resolved user:
     *   1. get_condition_results(..., false) - all blockers (soft + hard)
     *   2. get_condition_results(..., true)  - only true hard blockers
     *
     * When only soft blockers exist (e.g. selectuser), the current admin actor can still
     * book on behalf of the user. In that case a structured confirmation issue is returned
     * for explicit confirmation (confirmed=true) before execute() is called.
     *
     * @param array $input
     * @param int $cmid
     * @return array
     */
    public function validate(array $input, int $cmid): array {
        $errors = [];
        $ambiguities = [];
        $issues = [];
        $lang = $this->get_output_language($input);

        $bookusersquery = trim((string)($input['bookusersquery'] ?? ''));
        if ($bookusersquery === '') {
            $errors[] = $this->localized_string('agent_booking_book_users_required_bookusersquery', null, $lang);
            return ['valid' => false, 'errors' => $errors, 'ambiguities' => $ambiguities, 'issues' => $issues];
        }

        // Attempt to capture the resolved option id so we can run condition pre-checks below.
        $resolvedoptionid = 0;
        $optionid = (int)($input['optionid'] ?? 0);
        $optionquery = trim((string)($input['optionquery'] ?? ''));
        if ($optionid <= 0 && $optionquery === '') {
            $ambiguities[] = $this->localized_string('agent_booking_diagnose_ambiguity_option_required', null, $lang);
        } else if ($optionid <= 0 && $optionquery !== '') {
            if (!booking_task_support::is_last_option_reference($optionquery)) {
                $resolved = booking_task_support::resolve_single_option(
                    $cmid,
                    $optionquery,
                    (string)($input['optionwhen'] ?? '')
                );
                if (($resolved['status'] ?? '') === 'ambiguity') {
                    $ambiguities[] = (string)($resolved['message'] ?? '');
                } else if (($resolved['status'] ?? '') === 'error') {
                    $errors[] = (string)($resolved['message'] ?? '');
                } else {
                    $resolvedoptionid = (int)($resolved['optionid'] ?? 0);
                }
            }
        } else if ($optionid > 0) {
            $resolvedoptionid = $optionid;
        }

        $usersforbooking = booking_task_support::resolve_users_for_booking($bookusersquery);
        if (!empty($usersforbooking['errors'])) {
            $errors = array_merge($errors, $usersforbooking['errors']);
        }
        if (!empty($usersforbooking['ambiguities'])) {
            $ambiguities = array_merge($ambiguities, $usersforbooking['ambiguities']);
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
        if (!$confirmed && $resolvedoptionid > 0 && empty($errors) && empty($ambiguities)) {
            $bookuserids = $usersforbooking['userids'] ?? [];
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
                    $errors[] = get_string('agent_booking_user_cannot_book_hard_block', 'mod_booking', (object)[
                        'userid' => $uid,
                        'descriptions' => $descriptions,
                    ]);
                } else {
                    // Soft blockers only: the target user could not book themselves,
                    // but the current actor (admin) has the right to book on their behalf.
                    $descriptions = $this->summarize_condition_descriptions($allblockers);
                    $softoverridelines[] = get_string('agent_booking_book_users_soft_block', 'mod_booking', (object)[
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
                        . ' ' . get_string('agent_booking_book_users_soft_block_confirm', 'mod_booking'),
                ];
            }
        }

        return [
            'valid' => empty($errors) && empty($ambiguities) && empty($issues),
            'errors' => $errors,
            'ambiguities' => $ambiguities,
            'issues' => $issues,
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
        $outputlang = $this->get_output_language($input);

        // Resolve option.
        $resolvedoption = $this->resolve_option_id($input, $cmid, $userid, $outputlang);
        if (($resolvedoption['status'] ?? '') !== 'ok') {
            return [
                'status' => 'error',
                'detail' => (string)($resolvedoption['message'] ?? get_string('agent_booking_book_users_option_resolve_failed', 'mod_booking')),
                'resultid' => null,
                'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, ['Status: error']),
            ];
        }
        $optionid = (int)$resolvedoption['optionid'];

        // Resolve users.
        $bookusersquery = (string)($input['bookusersquery'] ?? '');
        $usersforbooking = booking_task_support::resolve_users_for_booking($bookusersquery);
        if (!empty($usersforbooking['errors']) || !empty($usersforbooking['ambiguities'])) {
            return [
                'status' => 'error',
                'detail' => trim(implode(' ', array_merge(
                    $usersforbooking['errors'],
                    $usersforbooking['ambiguities']
                ))),
                'resultid' => null,
                'debugmessage' => $this->build_task_debug_message(
                    self::TASK_NAME,
                    $input,
                    ['Status: error (user resolve)']
                ),
            ];
        }

        $bookuserids = $usersforbooking['userids'];
        if (empty($bookuserids)) {
            return [
                'status' => 'error',
                'detail' => $this->localized_string('agent_booking_book_users_required_bookusersquery', null, $outputlang),
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
            $timebooked = booking_task_support::parse_datetime($input['bookuserstimebooked']);
            if ($timebooked !== false) {
                $meta['timebooked'] = $timebooked;
            }
        }

        // Execute booking via the standard bookit flow.
        // book_users_for_option uses get_condition_results(..., true) internally, so
        // admin-overridable conditions (like selectuser) do not block execution.
        $result = booking_task_support::book_users_for_option($optionid, $bookuserids, $meta);

        if (!empty($result['errors'])) {
            return [
                'status' => 'error',
                'detail' => implode(' ', $result['errors']),
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
        $detail = get_string('agent_booking_book_users_booked', 'mod_booking', (object)[
            'count' => count($bookeduserids),
            'optionid' => $optionid,
            'userids' => implode(', ', $bookeduserids),
        ]);

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

        return booking_task_support::resolve_single_option($cmid, $optionquery, (string)($input['optionwhen'] ?? ''));
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
}
