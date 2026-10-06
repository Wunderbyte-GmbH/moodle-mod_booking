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

defined('MOODLE_INTERNAL') || die();

global $CFG;
// Global MOD_BOOKING_* constants — not loaded on non-booking pages (e.g. the
// dashboard agent entry point), so pull them in explicitly.
require_once($CFG->dirroot . '/mod/booking/lib.php');

use mod_booking\local\wizard\engine\skill_risk_class;
use core_text;
use mod_booking\local\wizard\booking\booking_skill_support;
use mod_booking\local\wizard\engine\skill_trigger_provider_interface;
use mod_booking\bo_availability\bo_info;
use mod_booking\singleton_service;

/**
 * Task definition for booking.diagnose_booking_issue.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class diagnose_booking_issue_skill extends booking_skill_base implements skill_trigger_provider_interface {
    // Cross-context targeting: the named option (optionid/optionquery) pins the operating booking
    // activity BEFORE the no-instance guard fires, so the skill also works from non-module entry
    // points (dashboard, MCP system context). The cross-user gate (mod/booking:bookforothers) is
    // checked inline at the resolved operating cmid, per the trait's opt-in safety rule.
    use option_targeted_skill;

    /** Task name constant. */
    public const TASK_NAME = 'mod_booking.diagnose_booking_issue';

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
            // First 240 characters = selector window (#2423, DBI-1 "cannot get into the pottery class").
            'description' => 'Diagnose WHY a person (userquery) cannot BOOK or get into one booking option (optionquery). Covers '
                . 'not booked, no mail, blocking conditions and settings. PATTERN: If user asks "why can [Name] not book '
                . '[Option]", extract Name→userquery, Option→optionquery and supply them directly whenever both are identifiable '
                . 'in the user message.',
            // Wave 38 (N50 DUC-2: "cohort sync into Winter School 2027" came here instead of the course diagnosis). The
            // IS line carries it: the NOT line is at the registry cap and would lose a sibling name.
            'is' => 'One person against one booking option; not course enrolment or cohort sync.',
            'not' => 'History (diagnose_user_booking); cancelling (diagnose_cancellation_issue); '
                . 'course.diagnose_user_in_course; local_taskflow.diagnose_user_assignments.',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'Why is this person stuck on the waiting list?',
                'The booking didn\'t confirm for this user',
                'Why can\'t Maria book this course?',
                'A participant says they never got the confirmation email',
                'Why am I not enrolled even though I booked?',
                'This user booked but isn\'t showing as participant',
            ],
            'properties' => [
                'optionquery' => [
                    'type' => 'string',
                    'description' => 'Never ask which option when the request names one: pass the user\'s words verbatim, '
                        . 'however short; the skill resolves them or lists the candidates.',
                    'required' => false,
                ],
                'optionid' => [
                    'type' => 'integer',
                    'description' => 'Explicit booking option id when already known.',
                    'required' => false,
                ],
                'question' => [
                    'type' => 'string',
                    'description' => 'The user question in natural language, e.g. "Why am I not booked for option X?". '
                        . 'Pass the original user wording so the task can classify the issue type automatically. '
                        . 'Omit only when the issue type is passed explicitly via the issue field.',
                    'required' => false,
                    'from_user_message' => true,
                ],
                'userquery' => [
                    'type' => 'string',
                    'description' => 'User reference (name, email, id-like text) when diagnosing for another person. '
                        . 'Omit (do NOT send empty string) to diagnose for the current user. '
                        . 'CRITICAL: Omit this field entirely for self-diagnosis. '
                        . 'Do not send fuzzy phrases like "you", "me", "myself", etc. '
                        . 'Only send concrete user identifiers (names, emails, user IDs).',
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
                'activityquery' => [
                    'type' => 'string',
                    'description' => 'Leave it out unless the user names a booking activity other than the current one '
                        . '(e.g. over MCP, which runs at the system context); never a course.',
                    'required' => false,
                ],
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'Optional language code for localized task strings, e.g. de or en.',
                    'required' => false,
                ],
            ],
            'prompt_meta' => [
                // The prompt_meta block keeps its established shape even where only the group is declared: the
                // contract test asserts both keys on every skill that carries prompt_meta at all, and an
                // empty list is what the readers saw before this block existed.
                'input_fields_for_prompt' => [],
                'anchor_fields' => [],
                // Mirrors check_structure(): the skill only fails hard when neither the question text nor
                // any option reference nor an explicit issue is present. Each of these alone is enough,
                // so the requirement is a group of alternatives, not a required schema field.
                'required_groups' => [
                    ['question', 'optionid', 'optionquery', 'issue'],
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
                'id' => 'mod_booking.diagnose_booking_issue_self_help',
                // Wave 38 (L52sol DBI-1/DBI-4): users call the option a course or class; GPT-6 Sol 20/20 with this line.
                'description' => 'User asks why they or another person are not booked, cannot book, got no mail or have '
                    . 'another issue with a booking option, also when they call the option a course or class.',
                'examples' => [
                    'Why am I not registered for booking option XY?',
                    'Why did I not get any e-mail from booking option XY?',
                    'Why can I not register for booking option XY?',
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
                'id' => 'mod_booking.self_help_diagnostics',
                'triggers' => [
                    'why am i not booked', 'why can i not book', 'why no email',
                    'cannot book', 'can not book', 'can\'t book',
                    'why am i not registered', 'why can i not register',
                    'why did i not get any mail',
                    'cannot book', 'cannot register', 'cannot sign up',
                    'unable to book', 'why can',
                    'diagnose', 'diagnos',
                ],
                'guidance' => [
                    '- Use booking.diagnose_booking_issue as response_type "task_call" IMMEDIATELY
                        — no clarification, no confirmation_request.',
                    '- booking.diagnose_booking_issue is READ-ONLY. Execute it directly without asking the user for permission.',
                    '- Extract ALL information from the user message in one pass: the user\'s words for the option
                        → optionquery, short ones too, also when they call it a course or class; person name → userquery.',
                    '- CRITICAL: For self-diagnosis (current user), OMIT the userquery field entirely.
                        Do NOT send fuzzy self-reference phrases like "you", "me", "myself", etc.',
                    '- Pass the full original user question as the "question" field so the task can classify the issue type.',
                    '- Do NOT ask for clarification when the option name or person name appears in the user message
                        — extract directly.',
                    '- If genuinely nothing about the option is mentioned (no name, no id, no context), only then ask once.',
                ],
            ],
        ];
    }

    /**
     * Structural validation — pure, no DB access.
     *
     * Checks that the 'question' field is present.
     *
     * @param  array $input
     * @return array{valid:bool,errors:array<int,string>}
     */
    public function check_structure(array $input): array {
        // Question is optional when issue is passed explicitly; otherwise the task derives it from question text.
        // We only hard-fail when neither question nor any identifying field is present at all.
        $hasquestion   = trim((string)($input['question'] ?? '')) !== '';
        $hasoptionref  = trim((string)($input['optionquery'] ?? '')) !== '' || !empty($input['optionid']);
        $hasissue      = trim((string)($input['issue'] ?? '')) !== '';

        if (!$hasquestion && !$hasoptionref && !$hasissue) {
            return [
                'valid'  => false,
                'errors' => [get_string('agent_booking_diagnose_required_question', 'booking')],
            ];
        }
        return ['valid' => true, 'errors' => []];
    }

    /**
     * Deep preflight validation — option resolution and user resolution.
     *
     * Returns prepared_input with 'optionid' resolved so execute() never has
     * to perform another DB lookup.
     *
     * @param  array $input
     * @param  int   $cmid
     * @param  int   $userid
     * @return array{status:string,prepared_input:array,issues:array}
     */
    protected function run_preflight(array $input, int $cmid, int $userid): array {
        $cmid = $this->resolve_cmid_from_context_or_cmid($cmid);
        if ($guard = $this->require_booking_instance_scope($cmid, $input)) {
            return $guard;
        }
        $lang = $this->get_output_language($input);
        $issues = [];

        // Question is required.
        $question = trim((string)($input['question'] ?? ''));
        if ($question === '') {
            $issues[] = [
                'code'     => 'MISSING_QUESTION',
                'severity' => 'needs_clarification',
                'message'  => $this->localized_string('agent_booking_diagnose_required_question', null, $lang),
            ];
            return $this->invalid($issues);
        }

        $preparedinput = $input;

        // Resolve option reference.
        $optionid = (int)($input['optionid'] ?? 0);
        $optionquery = trim((string)($input['optionquery'] ?? ''));

        if ($optionid <= 0 && $optionquery === '') {
            $issues[] = [
                'code'     => 'OPTION_REFERENCE_REQUIRED',
                'severity' => 'needs_clarification',
                'message'  => $this->localized_string('agent_booking_diagnose_ambiguity_option_required', null, $lang),
            ];
            return $this->invalid($issues);
        }

        if ($optionid <= 0 && $optionquery !== '') {
            if (!booking_skill_support::is_last_option_reference($optionquery)) {
                $resolved = booking_skill_support::resolve_single_option($cmid, $optionquery, '');
                // Wave 32 (DBI-4 1/10, threads 10493-12947): "Excel-Kurs" is in no option name; the turn ended with
                // "no option matched" although the activity holds exactly one visible Excel option. Choices, not an
                // error: the name hits (if any) first, then the options of the activity, visible first - the model
                // picks the optionid, the code never stems or guesses a name.
                if (($resolved['status'] ?? '') === 'ambiguity') {
                    $hitrows = booking_skill_support::search_option_candidates_for_preview(
                        $cmid,
                        $optionquery,
                        50
                    );
                    $hits = array_map(static fn(array $row): int => (int)($row['optionid'] ?? 0), $hitrows);
                    $issues[] = [
                        'code'     => 'OPTION_RESOLUTION_AMBIGUOUS',
                        'severity' => 'needs_clarification',
                        'message'  => (string)($resolved['message']
                            ?? $this->localized_string('agent_booking_diagnose_ambiguity_option_specify', null, $lang)),
                        'field'    => 'optionid',
                        'candidates' => booking_skill_support::option_choices($cmid, $hits),
                    ];
                    return $this->invalid($issues);
                } else if (($resolved['status'] ?? '') === 'error') {
                    // L43 (thread 13182, sync observation Z. 424): the shared resolver's text "No option matched
                    // optionquery ..." is English and names a schema field; it reached the synchronizer as the
                    // question's fact. The skill's own localized string carries the same fact.
                    $issues[] = [
                        'code'     => 'OPTION_RESOLUTION_FAILED',
                        'severity' => 'needs_clarification',
                        'message'  => $this->localized_string('agent_booking_diagnose_error_option_resolve', null, $lang)
                            . ' ' . $this->localized_string('agent_booking_option_choose_from_candidates', null, $lang),
                        'field'    => 'optionid',
                        'candidates' => booking_skill_support::option_choices($cmid),
                    ];
                    return $this->invalid($issues);
                } else if (($resolved['status'] ?? '') === 'ok') {
                    $preparedinput['optionid'] = (int)($resolved['optionid'] ?? 0);
                }
            }
            // Is_last_option_reference is handled at execute() time using user-session data.
        }

        return $this->pass($preparedinput);
    }

    /**
     * Execute task using prepared_input from preflight().
     *
     * prepared_input already contains a resolved 'optionid' when the option was
     * identified during preflight().  Only 'last option' references need runtime
     * resolution here (they depend on live session state, not static DB data).
     *
     * @param  array $preparedinput  Resolved input from preflight().
     * @param  int   $cmid
     * @param  int   $userid
     * @return array
     */
    public function execute(array $preparedinput, int $cmid, int $userid): array {
        $cmid = $this->resolve_cmid_from_context_or_cmid($cmid);
        if ($scoperesult = $this->build_no_instance_scope_result($cmid, $preparedinput)) {
            return $scoperesult;
        }
        global $DB;
        $outputlang = $this->get_output_language($preparedinput);

        // Step 1: Resolve which user to diagnose.
        // Priority: explicit targetuserid > userquery (resolved via DB) > current user.
        $resolveduser = $this->resolve_diagnostic_user($preparedinput, $userid, $outputlang);
        if (($resolveduser['status'] ?? '') !== 'ok') {
            return [
                'status' => 'error',
                'detail' => (string)($resolveduser['message']
                    ?? $this->localized_string('agent_booking_resolve_user_query_required', null, $outputlang)),
                'resultid' => null,
                'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $preparedinput, ['Status: error']),
            ];
        }
        $diagnosticuserid = (int)($resolveduser['userid'] ?? $userid);

        // Step 2: Permission check — diagnosing another user requires mod/booking:bookforothers.
        if ($diagnosticuserid !== $userid && !$this->can_analyze_other_user($cmid)) {
            return [
                'status' => 'error',
                'detail' => $this->get_other_user_permission_error_message($outputlang),
                'resultid' => null,
                'debugmessage' => $this->build_task_debug_message(
                    self::TASK_NAME,
                    $preparedinput,
                    ['Status: error', 'Reason: missing permission for cross-user diagnosis']
                ),
            ];
        }

        // Step 3: Detect which issue type to diagnose.
        // Derived from explicit 'issue' field or by keyword-matching the 'question' text.
        // Possible values: booking_status | cannot_book | missing_email.
        $issuetype = $this->resolve_issue_type($preparedinput);

        // Step 4: Resolve the booking option.
        // Priority: explicit optionid > "last option" session reference > optionquery (DB search).
        $resolvedoption = $this->resolve_option_id($preparedinput, $cmid, $userid, $outputlang);
        if (($resolvedoption['status'] ?? '') !== 'ok') {
            return [
                'status' => 'error',
                'detail' => (string)($resolvedoption['message']
                    ?? $this->localized_string('agent_booking_diagnose_error_option_resolve', null, $outputlang)),
                'resultid' => null,
                'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $preparedinput, ['Status: error']),
            ];
        }

        // Step 5: Load option settings, availability condition results and booking answers.
        $optionid = (int)($resolvedoption['optionid'] ?? 0);
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $conditionresults = bo_info::get_condition_results($optionid, $diagnosticuserid);
        $optionname = (string)$DB->get_field('booking_options', 'text', ['id' => $optionid]) ?: ('Option #' . $optionid);
        $ba = singleton_service::get_instance_of_booking_answers($settings);
        $optionstats = $ba->return_all_booking_information($diagnosticuserid);
        $userstatus = (string)$ba->user_status_as_string($diagnosticuserid);
        $optionstats['userstatus'] = $userstatus;
        $isselfdiagnosis = ($diagnosticuserid === $userid);

        // Step 5b: Load booking instance settings (needed for instance-level restriction checks).
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);

        // Step 5b2: Which booking rules does this option actually apply? An opt-in restriction with
        // an empty or incomplete list silently switches off the mail rules of the surrounding
        // contexts, which is a frequent cause of "booked, but no confirmation mail". An applied
        // rule can still be out of its time window after the option end; the confirmation went
        // out when the answer was written, so that is the moment the window has to cover.
        $useranswer = $ba->get_users()[$diagnosticuserid] ?? null;
        $confirmationtime = (in_array($userstatus, ['booked', 'waitinglist'], true) && !empty($useranswer->timemodified))
            ? (int)$useranswer->timemodified
            : 0;
        $notificationrules = option_rules_diagnostics::describe($optionid, $confirmationtime);

        // Step 5c: Check course enrollment.
        // Only enrolled users can book; non-enrollment is a fundamental blocker.
        $cm = get_coursemodule_from_id('booking', $cmid, 0, false, MUST_EXIST);
        $coursecontext = \context_course::instance((int)$cm->course);
        $isenrolled = is_enrolled($coursecontext, $diagnosticuserid, '', true);

        // Step 5d: Check option visibility.
        // invisible = 0 → visible; 1 → hidden (non-privileged users cannot see/book);
        // invisible = 2 → not shown in list but accessible via direct link (does not block booking).
        $invisiblevalue = (int)($settings->invisible ?? 0);

        // Step 5e: Check instance-level booking restrictions from booking_settings.
        // disablebooking: booking disabled for the whole instance.
        // maxperuser: max active bookings per user (0 = unlimited).
        // banusernames: comma-separated list of usernames blocked from booking.
        $instancedisablebooking = !empty($bookingsettings->disablebooking);
        $maxperuser = (int)($bookingsettings->maxperuser ?? 0);
        $userbookingcount = 0;
        if ($maxperuser > 0) {
            // Count confirmed bookings (waitinglist = 0) for this user in this booking instance.
            $userbookingcount = (int)$DB->count_records('booking_answers', [
                'bookingid' => (int)$bookingsettings->id,
                'userid'    => $diagnosticuserid,
                'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
            ]);
        }
        $userisbannedfrominstance = false;
        $banusernames = trim((string)($bookingsettings->banusernames ?? ''));
        if ($banusernames !== '') {
            $diagnosticuser = $DB->get_record('user', ['id' => $diagnosticuserid], 'username', IGNORE_MISSING);
            $bannedlist = array_map('trim', explode(',', $banusernames));
            if ($diagnosticuser && in_array((string)$diagnosticuser->username, $bannedlist, true)) {
                $userisbannedfrominstance = true;
            }
        }

        // Step 5f: Can the diagnostic user even SEE the booking activity itself? Covers the
        // "the booking module is hidden / not yet available for this user" case. Read through core
        // modinfo for the TARGET user so it respects availability — and respects a "do not show the
        // reason" setting (availableinfo is empty then), instead of reading conditions raw from the DB.
        $activityuservisible = true;
        $activityavailableinfo = '';
        try {
            $targetcm = get_fast_modinfo((int)$cm->course, $diagnosticuserid)->get_cm($cmid);
            $activityuservisible = (bool)$targetcm->uservisible;
            $activityavailableinfo = trim(strip_tags((string)$targetcm->availableinfo));
        } catch (\Throwable $e) {
            // Defensive: a modinfo hiccup must never break the diagnosis.
            $activityuservisible = true;
            $activityavailableinfo = '';
        }

        $instancecontext = [
            'isenrolled' => $isenrolled, // Whether user is enrolled in the course.
            'invisiblevalue' => $invisiblevalue, // Visibility: 0=visible, 1=hidden, 2=not in list.
            'activityuservisible' => $activityuservisible, // Whether the booking activity itself is visible to the user.
            'activityavailableinfo' => $activityavailableinfo, // Human-readable "not available until …" reason, if shown.
            'instancedisablebooking' => $instancedisablebooking, // Disablebooking set on the instance.
            'maxperuser' => $maxperuser, // Max bookings per user (0=unlimited).
            'userbookingcount' => $userbookingcount, // Current confirmed booking count.
            'userisbannedfrominstance' => $userisbannedfrominstance, // Username in banusernames.
            'courseid' => (int)$cm->course,
        ];

        // Step 6: Build consistency payload — detects mismatches between LLM-supplied and resolved IDs/names.
        $consistency = $this->build_consistency_payload($preparedinput, $diagnosticuserid, $optionid, $optionname);

        // Step 7: Build human-readable reason lines tailored to the issue type.
        // - booking_status: reports current enrollment status.
        // - cannot_book:    reports capacity limits and blocking availability conditions.
        // - missing_email:  reports enrollment status and e-mail configuration hints.
        $reasons = $this->build_reason_lines(
            $issuetype,
            $optionstats,
            $conditionresults,
            $settings,
            $isselfdiagnosis,
            $outputlang,
            $instancecontext,
            $notificationrules
        );
        $supplementary = $this->build_supplementary_context_lines(
            $isselfdiagnosis,
            $outputlang,
            $instancecontext,
            $notificationrules
        );

        // Step 7b: The same facts as a checklist, for the preview pane. One row per thing that can
        // stand between a person and a booking — including whether the option's rule setting lets
        // the booking-confirmation rules run at all.
        $checklistrows = diagnose_checklist_builder::build(
            [
                'cmid' => $cmid,
                'optionid' => $optionid,
                'userstatus' => $userstatus,
                'invisiblevalue' => $invisiblevalue,
                'instancechecks' => $instancecontext,
                'stats' => $optionstats,
                'conditions' => self::collect_blocking_condition_descriptions($conditionresults, $settings),
                'notificationrules' => $notificationrules,
            ],
            $outputlang
        );

        $introk = $isselfdiagnosis
            ? 'agent_booking_diagnose_intro_checked_option'
            : 'agent_booking_diagnose_intro_checked_option_other';
        $usermessage = $this->localized_string($introk, $optionname, $outputlang);
        // Entity mentions always carry a real moodle_url link for the synchronizer.
        if ($cmid > 0 && $optionid > 0) {
            $usermessage .= ' (' . booking_skill_support::build_option_link_for_output($cmid, $optionid) . ')';
        }

        return [
            'status' => 'executed',
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'resultid' => $optionid,
            'previewoptionids' => [$optionid],
            // The generic observation summarizer caps a contributed summary at 220 characters, which
            // cuts the findings off mid-sentence before they reach the synchronizer. observation_full
            // is the engine's documented channel for verbatim list content.
            'observation_full' => $this->build_observation_full(
                $optionname,
                $issuetype,
                $userstatus,
                $reasons,
                $supplementary
            ),
            'checklist_rows' => $checklistrows,
            'checklist_title' => $usermessage,
            'requested_userid' => (int)($preparedinput['targetuserid'] ?? 0),
            'requested_optionid' => (int)($preparedinput['optionid'] ?? 0),
            'requested_userquery' => trim((string)($preparedinput['userquery'] ?? '')),
            'requested_optionquery' => trim((string)($preparedinput['optionquery'] ?? '')),
            'diagnosis' => [
                'issue' => $issuetype,
                'outputlang' => $outputlang,
                'userid' => $diagnosticuserid,
                'isselfdiagnosis' => $isselfdiagnosis,
                'optionid' => $optionid,
                'optionname' => $optionname,
                'userstatus' => $userstatus,
                'stats' => $optionstats,
                'instance_checks' => $instancecontext,
                'notification_rules' => $notificationrules,
                'reasons' => $reasons,
                'supplementary_context' => $supplementary,
                'consistency' => $consistency,
            ],
            'debugmessage' => $this->build_task_debug_message(
                self::TASK_NAME,
                $preparedinput,
                [
                    'Resolved option: ' . $optionname . ' (id=' . $optionid . ')',
                    'Diagnostic user id: ' . $diagnosticuserid,
                    'Issue: ' . $issuetype,
                    'User status: ' . $userstatus,
                    'Reasons: ' . count($reasons),
                    'Rule restriction active: ' . (!empty($notificationrules['restrictionactive']) ? 'yes' : 'no'),
                    'Skipped mail rules: ' . count((array)($notificationrules['skippedmailrules'] ?? [])),
                    'Expired confirmation rules: '
                        . count((array)($notificationrules['expiredconfirmationrules'] ?? [])),
                    'Expired other mail rules: ' . count((array)($notificationrules['expiredothermailrules'] ?? [])),
                    'Supplementary context lines: ' . count($supplementary),
                    'Consistency user mismatch: ' . (!empty($consistency['user_mismatch']) ? 'yes' : 'no'),
                    'Consistency option mismatch: ' . (!empty($consistency['option_mismatch']) ? 'yes' : 'no'),
                ]
            ),
        ];
    }

    /**
     * Build consistency payload comparing requested vs. resolved target entities.
     *
     * @param array $input
     * @param int $resolveduserid
     * @param int $resolvedoptionid
     * @param string $resolvedoptionname
     * @return array
     */
    private function build_consistency_payload(
        array $input,
        int $resolveduserid,
        int $resolvedoptionid,
        string $resolvedoptionname
    ): array {
        $requesteduserid = (int)($input['targetuserid'] ?? 0);
        $requestedoptionid = (int)($input['optionid'] ?? 0);
        $requesteduserquery = trim((string)($input['userquery'] ?? ''));
        $requestedoptionquery = trim((string)($input['optionquery'] ?? ''));

        $resolveduserlabel = $this->resolve_user_label($resolveduserid);
        $usermismatch = false;
        $optionmismatch = false;
        $warnings = [];

        if ($requesteduserid > 0 && $requesteduserid !== $resolveduserid) {
            $usermismatch = true;
            $warnings[] = 'targetuserid differs from resolved userid';
        }

        if ($requestedoptionid > 0 && $requestedoptionid !== $resolvedoptionid) {
            $optionmismatch = true;
            $warnings[] = 'optionid differs from resolved optionid';
        }

        if (
            $requesteduserquery !== ''
            && $this->looks_like_anonymized_identifier($requesteduserquery)
            && $resolveduserlabel !== ''
            && core_text::strtolower($resolveduserlabel) !== core_text::strtolower($requesteduserquery)
        ) {
            $usermismatch = true;
            $warnings[] = 'userquery differs from resolved user label';
        }

        if (
            $requestedoptionquery !== ''
            && $this->looks_like_anonymized_identifier($requestedoptionquery)
            && core_text::strtolower(trim($resolvedoptionname)) !== core_text::strtolower($requestedoptionquery)
        ) {
            $optionmismatch = true;
            $warnings[] = 'optionquery differs from resolved option title';
        }

        return [
            'requested_userid' => $requesteduserid,
            'requested_optionid' => $requestedoptionid,
            'requested_userquery' => $requesteduserquery,
            'requested_optionquery' => $requestedoptionquery,
            'resolved_userid' => $resolveduserid,
            'resolved_userlabel' => $resolveduserlabel,
            'resolved_optionid' => $resolvedoptionid,
            'resolved_optionname' => $resolvedoptionname,
            'user_mismatch' => $usermismatch,
            'option_mismatch' => $optionmismatch,
            'warnings' => $warnings,
        ];
    }

    /**
     * Resolve user label for consistency diagnostics.
     *
     * @param int $userid
     * @return string
     */
    private function resolve_user_label(int $userid): string {
        global $DB;

        if ($userid <= 0) {
            return '';
        }

        $user = $DB->get_record(
            'user',
            ['id' => $userid, 'deleted' => 0],
            'id,username,firstname,lastname,email,firstnamephonetic,lastnamephonetic,middlename,alternatename',
            IGNORE_MISSING
        );
        if (!$user) {
            return '';
        }

        $fullname = trim(fullname($user));
        if ($fullname !== '') {
            return $fullname;
        }

        return trim((string)($user->username ?? $user->email ?? ''));
    }

    /**
     * Check whether a query looks like an anonymized identifier token.
     *
     * @param string $query
     * @return bool
     */
    private function looks_like_anonymized_identifier(string $query): bool {
        $normalized = trim(core_text::strtolower($query));
        if ($normalized === '') {
            return false;
        }

        if (preg_match('/\banon_user_\d+\b/u', $normalized) === 1) {
            return true;
        }

        return false;
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
        ];
        foreach ($cannotbooktokens as $token) {
            if (strpos($question, $token) !== false) {
                return 'cannot_book';
            }
        }

        $statustokens = ['not booked', 'why am i not'];
        foreach ($statustokens as $token) {
            if (strpos($question, $token) !== false) {
                return 'booking_status';
            }
        }

        return 'booking_status';
    }

    /**
     * Resolve diagnostic target user from explicit LLM input.
     *
     * The LLM must provide either targetuserid or userquery explicitly.
     * If neither is provided, defaults to the current user.
     *
     * @param array $input
     * @param int $currentuserid
     * @param string $lang
     * @return array
     */
    private function resolve_diagnostic_user(array $input, int $currentuserid, string $lang = ''): array {
        global $DB;

        // Explicit user ID takes precedence.
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

        // LLM must provide userquery if diagnosing another user.
        $userquery = trim((string)($input['userquery'] ?? ''));
        if ($userquery === '') {
            // No user reference provided → diagnose for current user.
            return ['status' => 'ok', 'userid' => $currentuserid];
        }

        // Attempt to resolve the user query.
        $resolved = booking_skill_support::resolve_single_user($userquery);
        if (($resolved['status'] ?? '') === 'ok') {
            return ['status' => 'ok', 'userid' => (int)($resolved['userid'] ?? $currentuserid)];
        }

        // User resolution failed (not found or ambiguous).
        // Ensure we return a clear, user-facing error message.
        if (($resolved['status'] ?? '') === 'error') {
            // Specific error — use the message from support layer or provide a clear fallback.
            return [
                'status' => 'error',
                'message' => (string)($resolved['message'] ??
                    $this->localized_string('agent_booking_diagnose_error_user_not_found', $userquery, $lang)),
            ];
        }

        // Ambiguity or other status.
        return [
            'status' => 'error',
            'message' => (string)($resolved['message'] ??
                $this->localized_string('agent_booking_diagnose_error_user_resolution_failed', $userquery, $lang)),
        ];
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

        if (booking_skill_support::is_last_option_reference($optionquery)) {
            return ['errors' => $errors, 'ambiguities' => $ambiguities];
        }

        $resolved = booking_skill_support::resolve_single_option($cmid, $optionquery, '');
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
        return $this->resolve_diagnose_option($input, $cmid, $userid, $lang);
    }

    /**
     * Build concrete reason lines for the diagnosis (returned as structured data for agent-layer narration).
     *
     * @param string $issuetype
     * @param array $optionstats
     * @param array $conditionresults
     * @param mixed $settings booking_option_settings instance from singleton_service.
     * @param bool $isselfdiagnosis
     * @param string $lang
     * @param array $instancecontext Optional instance-level and enrollment context from execute().
     * @param array $notificationrules Per-option booking-rule restriction from option_rules_diagnostics.
     * @return array
     */
    private function build_reason_lines(
        string $issuetype,
        array $optionstats,
        array $conditionresults,
        $settings,
        bool $isselfdiagnosis,
        string $lang = '',
        array $instancecontext = [],
        array $notificationrules = []
    ): array {
        $reasons = [];
        $userstatus = (string)($optionstats['userstatus'] ?? 'notbooked');

        // Fundamental access checks (evaluated for all issue types).

        // Check 2: Option visibility.
        // invisible=1 → hidden for non-privileged users (hard blocker).
        // invisible=2 → not shown in list, but still accessible via direct link (soft, informational).
        $invisiblevalue = (int)($instancecontext['invisiblevalue'] ?? 0);
        if ($invisiblevalue === 1) {
            $reasons[] = $this->localized_string(
                $isselfdiagnosis
                    ? 'agent_booking_diagnose_reason_option_invisible'
                    : 'agent_booking_diagnose_reason_option_invisible_other',
                null,
                $lang
            );
            $reasons[] = $this->localized_string(
                'agent_booking_diagnose_reason_option_invisible_concrete',
                null,
                $lang
            );
        } else if ($invisiblevalue === 2) {
            $reasons[] = $this->localized_string('agent_booking_diagnose_reason_option_hidden_from_list', null, $lang);
            $reasons[] = $this->localized_string(
                'agent_booking_diagnose_reason_option_hidden_from_list_concrete',
                null,
                $lang
            );
        }

        // Check 3: Instance-level booking disabled.
        if (!empty($instancecontext['instancedisablebooking'])) {
            $reasons[] = $this->localized_string(
                'agent_booking_diagnose_reason_instance_disablebooking',
                null,
                $lang
            );
            $reasons[] = $this->localized_string(
                'agent_booking_diagnose_reason_instance_disablebooking_concrete',
                null,
                $lang
            );
        }

        // Check 4: Max bookings per user exceeded.
        $maxperuser = (int)($instancecontext['maxperuser'] ?? 0);
        $userbookingcount = (int)($instancecontext['userbookingcount'] ?? 0);
        if ($maxperuser > 0 && $userbookingcount >= $maxperuser) {
            $reasons[] = $this->localized_string(
                $isselfdiagnosis
                    ? 'agent_booking_diagnose_reason_maxperuser_exceeded'
                    : 'agent_booking_diagnose_reason_maxperuser_exceeded_other',
                $maxperuser,
                $lang
            );
            $reasons[] = $this->localized_string(
                'agent_booking_diagnose_reason_maxperuser_exceeded_concrete',
                (object)['max' => $maxperuser, 'current' => $userbookingcount],
                $lang
            );
        }

        // Check 5: Banned username.
        if (!empty($instancecontext['userisbannedfrominstance'])) {
            $reasons[] = $this->localized_string(
                $isselfdiagnosis
                    ? 'agent_booking_diagnose_reason_banned_username'
                    : 'agent_booking_diagnose_reason_banned_username_other',
                null,
                $lang
            );
            $reasons[] = $this->localized_string(
                'agent_booking_diagnose_reason_banned_username_concrete',
                null,
                $lang
            );
        }

        // Issue-type specific checks.

        $statusstats = [];
        foreach (['notbooked', 'iambooked', 'iamreserved', 'onwaitinglist', 'onnotifylist'] as $statuskey) {
            if (!empty($optionstats[$statuskey]) && is_array($optionstats[$statuskey])) {
                $statusstats = (array)$optionstats[$statuskey];
                break;
            }
        }
        $effectivestats = array_merge($statusstats, $optionstats);

        if ($issuetype === 'booking_status') {
            if ($userstatus === 'booked') {
                $reasons[] = $this->localized_string(
                    $isselfdiagnosis
                        ? 'agent_booking_diagnose_reason_status_booked'
                        : 'agent_booking_diagnose_reason_status_booked_other',
                    null,
                    $lang
                );
            } else if ($userstatus === 'waitinglist') {
                $reasons[] = $this->localized_string(
                    $isselfdiagnosis
                        ? 'agent_booking_diagnose_reason_status_waitinglist'
                        : 'agent_booking_diagnose_reason_status_waitinglist_other',
                    null,
                    $lang
                );
            } else if ($userstatus === 'reserved') {
                $reasons[] = $this->localized_string(
                    $isselfdiagnosis
                        ? 'agent_booking_diagnose_reason_status_reserved'
                        : 'agent_booking_diagnose_reason_status_reserved_other',
                    null,
                    $lang
                );
            } else if ($userstatus === 'notifylist') {
                $reasons[] = $this->localized_string(
                    $isselfdiagnosis
                        ? 'agent_booking_diagnose_reason_status_notifylist'
                        : 'agent_booking_diagnose_reason_status_notifylist_other',
                    null,
                    $lang
                );
            } else {
                $reasons[] = $this->localized_string(
                    $isselfdiagnosis
                        ? 'agent_booking_diagnose_reason_status_notbooked'
                        : 'agent_booking_diagnose_reason_status_notbooked_other',
                    null,
                    $lang
                );
            }
        }

        if ($issuetype === 'cannot_book' || $issuetype === 'booking_status') {
            if ($userstatus === 'booked') {
                $reasons[] = $this->localized_string(
                    $isselfdiagnosis
                        ? 'agent_booking_diagnose_reason_cannot_book_already_booked'
                        : 'agent_booking_diagnose_reason_cannot_book_already_booked_other',
                    null,
                    $lang
                );
            }

            if (!empty($effectivestats['fullybooked'])) {
                $reasons[] = $this->localized_string('agent_booking_diagnose_reason_cannot_book_fully_booked', null, $lang);
            }

            if ((int)($effectivestats['maxoverbooking'] ?? 0) === 0 && !empty($effectivestats['fullybooked'])) {
                $reasons[] = $this->localized_string('agent_booking_diagnose_reason_cannot_book_no_waitinglist', null, $lang);
            } else if ((int)($effectivestats['maxoverbooking'] ?? 0) > 0) {
                if (!empty($effectivestats['waitinglistfull'])) {
                    $reasons[] = $this->localized_string('agent_booking_diagnose_reason_cannot_book_waitinglist_full', null, $lang);
                } else if (!empty($effectivestats['fullybooked'])) {
                    $reasons[] = $this->localized_string(
                        'agent_booking_diagnose_reason_cannot_book_waitinglist_available',
                        null,
                        $lang
                    );
                }
            }

            // Add the human-readable descriptions only — no class names in user-facing strings.
            foreach (self::collect_blocking_condition_descriptions($conditionresults, $settings) as $description) {
                $reasons[] = $description;
            }
        }

        if ($issuetype === 'missing_email') {
            // Only a rule that reacts to the booking itself would have produced the confirmation
            // mail. Reminders, cancellation notices and evaluation mails are switched off by the
            // same setting, but naming them as the cause would be untrue, so they stay a count.
            $skippedconfirmationrules = (array)($notificationrules['skippedconfirmationrules'] ?? []);
            $otherskippedmailrules = count((array)($notificationrules['skippedmailrules'] ?? []))
                - count($skippedconfirmationrules);

            if (!empty($skippedconfirmationrules)) {
                $reasons[] = $this->localized_string(
                    $isselfdiagnosis
                        ? 'agent_booking_diagnose_reason_rules_mail_skipped'
                        : 'agent_booking_diagnose_reason_rules_mail_skipped_other',
                    option_rules_diagnostics::rule_names($skippedconfirmationrules, $lang),
                    $lang
                );
                $reasons[] = $this->localized_string(
                    'agent_booking_diagnose_reason_rules_mail_skipped_concrete',
                    (object)[
                        'setting' => $this->localized_string('skipbookingrulesmode', null, $lang),
                        'mode' => $this->localized_string(
                            (int)($notificationrules['mode'] ?? 0) === 1
                                ? 'skipbookingrulesoptin'
                                : 'skipbookingrulesoptout',
                            null,
                            $lang
                        ),
                    ],
                    $lang
                );
            }

            if ($otherskippedmailrules > 0) {
                $reasons[] = $this->localized_string(
                    'agent_booking_diagnose_reason_rules_othermail_skipped',
                    $otherskippedmailrules,
                    $lang
                );
            }

            // A rule the option does apply can still be past its time window after the option end;
            // the mail task then skips it silently. Same split as above: only a confirmation rule is
            // named as the cause, every other mail rule stays a count.
            $expiredconfirmationrules = (array)($notificationrules['expiredconfirmationrules'] ?? []);
            if (!empty($expiredconfirmationrules)) {
                $a = (object)[
                    'names' => option_rules_diagnostics::rule_names($expiredconfirmationrules, $lang),
                    'date' => option_rules_diagnostics::format_time(
                        option_rules_diagnostics::earliest_window_end($expiredconfirmationrules),
                        $lang
                    ),
                ];
                if (empty($notificationrules['confirmationcheckedatbooking'])) {
                    $key = 'agent_booking_diagnose_reason_rules_mail_expired_now';
                } else {
                    $key = $isselfdiagnosis
                        ? 'agent_booking_diagnose_reason_rules_mail_expired'
                        : 'agent_booking_diagnose_reason_rules_mail_expired_other';
                }
                $reasons[] = $this->localized_string($key, $a, $lang);
                $reasons[] = $this->localized_string(
                    'agent_booking_diagnose_reason_rules_mail_expired_concrete',
                    (object)[
                        'setting' => $this->localized_string('rulereactoneventaftercompletion', null, $lang),
                        'days' => (int)($expiredconfirmationrules[0]['aftercompletion'] ?? 0),
                        'end' => option_rules_diagnostics::format_time((int)($settings->courseendtime ?? 0), $lang),
                    ],
                    $lang
                );
            }

            $expiredothermailrules = count((array)($notificationrules['expiredothermailrules'] ?? []));
            if ($expiredothermailrules > 0) {
                $reasons[] = $this->localized_string(
                    'agent_booking_diagnose_reason_rules_othermail_expired',
                    $expiredothermailrules,
                    $lang
                );
            }
            if ($userstatus === 'booked') {
                $reasons[] = $this->localized_string(
                    $isselfdiagnosis
                        ? 'agent_booking_diagnose_reason_missing_email_booked'
                        : 'agent_booking_diagnose_reason_missing_email_booked_other',
                    null,
                    $lang
                );
            } else if ($userstatus === 'waitinglist') {
                $reasons[] = $this->localized_string(
                    $isselfdiagnosis
                        ? 'agent_booking_diagnose_reason_missing_email_waitinglist'
                        : 'agent_booking_diagnose_reason_missing_email_waitinglist_other',
                    null,
                    $lang
                );
            } else {
                $reasons[] = $this->localized_string(
                    $isselfdiagnosis
                        ? 'agent_booking_diagnose_reason_missing_email_not_booked'
                        : 'agent_booking_diagnose_reason_missing_email_not_booked_other',
                    null,
                    $lang
                );
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
     * Build supplementary context lines that must remain secondary in feedback.
     *
     * @param bool $isselfdiagnosis
     * @param string $lang
     * @param array $instancecontext
     * @param array $notificationrules Per-option booking-rule restriction from option_rules_diagnostics.
     * @return array<int,string>
     */
    private function build_supplementary_context_lines(
        bool $isselfdiagnosis,
        string $lang = '',
        array $instancecontext = [],
        array $notificationrules = []
    ): array {
        $lines = [];

        // A rule restriction is worth knowing for every issue type, but it only becomes a decisive
        // reason when it actually switched a mail rule off (handled in build_reason_lines()).
        if (!empty($notificationrules['restrictionactive'])) {
            $applied = count((array)($notificationrules['applied'] ?? []));
            $lines[] = $this->localized_string(
                'agent_booking_diagnose_context_rules_restricted',
                (object)[
                    'applied' => $applied,
                    'total' => $applied + count((array)($notificationrules['skipped'] ?? [])),
                ],
                $lang
            );
        }

        // Course enrollment is useful context but intentionally non-decisive.
        if (isset($instancecontext['isenrolled']) && !$instancecontext['isenrolled']) {
            $lines[] = $this->localized_string(
                $isselfdiagnosis
                    ? 'agent_booking_diagnose_reason_not_enrolled'
                    : 'agent_booking_diagnose_reason_not_enrolled_other',
                null,
                $lang
            );
            $lines[] = $this->localized_string(
                'agent_booking_diagnose_reason_not_enrolled_concrete',
                (int)($instancecontext['courseid'] ?? 0),
                $lang
            );
            $lines[] = $this->localized_string(
                'agent_booking_diagnose_reason_not_enrolled_supplementary',
                null,
                $lang
            );
        }

        return array_values(array_unique(array_filter(array_map('trim', $lines))));
    }

    /**
     * Human-readable descriptions of the availability conditions that currently block this option.
     *
     * Shared by the reason lines and the diagnosis checklist so both count the same conditions.
     * UI-only conditions (is_shown_in_mform() === false, e.g. the "Book it" button control) are
     * not configurable restrictions and never appear.
     *
     * @param array $conditionresults Result of bo_info::get_condition_results().
     * @param mixed $settings booking_option_settings instance, or null.
     * @return array<int,string>
     */
    private static function collect_blocking_condition_descriptions(array $conditionresults, $settings): array {
        $descriptions = [];

        foreach ($conditionresults as $condition) {
            $classname = $condition['classname'] ?? '';

            // Instantiate the condition class to inspect its properties.
            try {
                $class = $classname::instance();
            } catch (\Throwable $e) {
                try {
                    $class = new $classname();
                } catch (\Throwable $e2) {
                    $class = null;
                }
            }

            if ($class !== null && method_exists($class, 'is_shown_in_mform') && !$class->is_shown_in_mform()) {
                continue;
            }

            if ($class !== null && method_exists($class, 'get_description_string') && $settings !== null) {
                $description = $class->get_description_string(false, true, $settings);
            } else {
                $description = $condition['description'] ?? '';
            }
            $description = trim(strip_tags((string)($description)));

            if ($description === '') {
                continue;
            }

            $descriptions[] = $description;
        }

        return array_values(array_unique($descriptions));
    }

    /**
     * Build the verbatim observation the synchronizer receives.
     *
     * Mirrors the shape of the generic diagnosis summary, but is exempt from its 220-character cap,
     * so no finding is cut off before it reaches the final answer.
     *
     * @param string $optionname
     * @param string $issuetype
     * @param string $userstatus
     * @param array $reasons
     * @param array $supplementary
     * @return string
     */
    private function build_observation_full(
        string $optionname,
        string $issuetype,
        string $userstatus,
        array $reasons,
        array $supplementary
    ): string {
        $header = 'Diagnosis';
        if (trim($optionname) !== '') {
            $header .= ' for option "' . trim($optionname) . '"';
        }
        if (trim($issuetype) !== '') {
            $header .= ' (issue: ' . trim($issuetype) . ')';
        }
        $lines = [$header . '.'];

        if (trim($userstatus) !== '') {
            $lines[] = 'User booking status: ' . trim($userstatus) . '.';
        }

        if (!empty($reasons)) {
            $lines[] = 'Findings:';
            foreach ($reasons as $reason) {
                $lines[] = '- ' . trim((string)$reason);
            }
        }

        if (!empty($supplementary)) {
            $lines[] = 'Secondary context:';
            foreach ($supplementary as $line) {
                $lines[] = '- ' . trim((string)$line);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Option card preview, followed by the diagnosis checklist.
     *
     * The checklist is the visual counterpart of the findings: one row per check, with the rule
     * row answering "which confirmation rule would have sent that mail, and why did it not run"
     * without a trip to the option settings form.
     *
     * @param array $resultentry
     * @param int $contextid
     * @param int $userid
     * @return array|null
     */
    public function get_result_preview(array $resultentry, int $contextid, int $userid): ?array {
        $preview = parent::get_result_preview($resultentry, $contextid, $userid);

        $block = $this->build_checklist_block(
            (array)($resultentry['checklist_rows'] ?? []),
            trim((string)($resultentry['checklist_title'] ?? ''))
        );
        if ($block === '') {
            return $preview;
        }

        if ($preview === null) {
            return [
                'type' => 'booking_option',
                'html' => $block,
                'js' => '',
                'payload' => ['optionids' => []],
            ];
        }

        $preview['html'] = (string)($preview['html'] ?? '') . $block;

        return $preview;
    }

    /**
     * Markup for the diagnosis checklist, rendered by the engine's shared checklist preview.
     *
     * Reached through the alias layer's engine_resolver, so the same skill class works under either
     * engine plugin without vendoring the renderer. If the engine does not offer it, the preview
     * degrades to the option card alone rather than to a second, diverging checklist of our own.
     *
     * @param array $rows Rows from diagnose_checklist_builder::build().
     * @param string $title Heading above the list.
     * @return string Empty string when there is nothing to render.
     */
    private function build_checklist_block(array $rows, string $title): string {
        if (empty($rows)) {
            return '';
        }

        $rendererclass = \mod_booking\local\wizard\engine\engine_resolver::fqcn(
            'diagnostics\\diagnostic_checklist_preview'
        );
        if (!class_exists($rendererclass)) {
            return '';
        }

        $rendered = (new $rendererclass())->render($rows, $title);

        return \html_writer::div((string)($rendered['html'] ?? ''), 'booking-ai-preview-item mb-3');
    }
}
