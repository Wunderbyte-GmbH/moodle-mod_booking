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
use mod_booking\local\wizard\engine\skill_trigger_provider_interface;
use mod_booking\local\wizard\engine\observation_time;
use mod_booking\local\wizard\booking\booking_skill_support;
use mod_booking\booking_answers\booking_answers;
use mod_booking\booking_option;
use mod_booking\singleton_service;

/**
 * Read-only skill: diagnose a person's booking(s) (mod_booking.diagnose_user_booking).
 *
 * Produces a verbose, structured status report for one user, in one of two modes:
 *  - option-scoped (an optionid/optionquery resolves): a deep diagnosis of the user's status in
 *    that single booking option, including the full received-message history for the option.
 *  - user-scoped (no option given): an instance-wide overview of every booking the user has in
 *    this booking activity, with aggregate counts and a per-option summary.
 *
 * All booking data is read through {@see booking_answers} (cached) — never via direct DB. The
 * received-message history is read through the standard logstore reader API, bounded to the
 * window [first booking creation … now], capped at the last 12 months, with a hard row limit.
 *
 * The report is returned as a rich `observation_full` payload so the synchronizer can pick the
 * parts that answer the user's actual question.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class diagnose_user_booking_skill extends booking_skill_base implements skill_trigger_provider_interface {
    // Cross-context targeting: a named option (optionid/optionquery) pins the operating booking
    // activity site-wide, so the option-focused mode also works from non-module entry points
    // (dashboard, MCP system context). Without an option reference the skill stays ambient —
    // instance-wide at a booking activity, all instances at the system context. Gate 2
    // (mod/booking:readresponses) is enforced by the engine at the resolved operating context.
    use option_targeted_skill;

    /** Task name constant. */
    public const TASK_NAME = 'mod_booking.diagnose_user_booking';

    /** Hard cap on received-message rows pulled from the logstore. */
    private const MESSAGE_LOG_LIMIT = 50;

    /** Hard cap on the per-option list returned in user-scoped mode. */
    private const MAX_OPTIONS_USERWIDE = 100;

    /** Hard cap on bookingoption_updated events scanned for a certificate-field change. */
    private const CERT_LOG_SCAN_LIMIT = 50;

    /**
     * Report keys whose integer values are Unix timestamps and must be rendered LLM-readable
     * (timezone-adjusted) before the report is handed to the model.
     *
     * @var array<int,string>
     */
    private const OBSERVATION_TIMESTAMP_KEYS = [
        'timebooked', 'timecreated', 'timemodified', 'completion_timemodified',
        'window_start', 'expires', 'last_changed',
    ];

    /**
     * Per-request memo of host-course fullnames, keyed by course id (keeps the per-option loop
     * of the instance-wide report free of repeated course reads).
     *
     * @var array
     */
    private array $coursefullnames = [];

    /**
     * mod_booking message events recorded in the standard logstore (objectid = optionid,
     * relateduserid = recipient). Used to report which messages a user received.
     *
     * @var array<int,string>
     */
    private const MESSAGE_EVENT_NAMES = [
        '\\mod_booking\\event\\message_sent',
        '\\mod_booking\\event\\custom_message_sent',
        '\\mod_booking\\event\\custom_bulk_message_sent',
        '\\mod_booking\\event\\reminder1_sent',
        '\\mod_booking\\event\\reminder2_sent',
        '\\mod_booking\\event\\reminder_teacher_sent',
    ];

    /**
     * Constructor. Read-only diagnosis, lowest risk class.
     */
    public function __construct() {
        parent::__construct(true, skill_risk_class::R0);
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
     * Native capability required to read other users' booking responses (Gate 2).
     *
     * @return string[]
     */
    public function get_required_native_capabilities(): array {
        return ['mod/booking:readresponses'];
    }

    /**
     * Return task schema.
     *
     * @return array
     */
    public function get_schema(): array {
        $schema = [
            'version' => 1,
            'description' => 'Diagnose a person\'s booking status and history and return a detailed status report. '
                . 'Use this when the user asks about ONE specific person: whether/when they booked, whether they '
                . 'completed, their waiting-list/cancelled/previous bookings, their submitted booking form data, and '
                . 'which notification messages they received. If a specific booking option is named, the report focuses '
                . 'on that option (including the full received-message history); if no option is named, it returns an '
                . 'instance-wide overview of all the person\'s bookings (e.g. "how many options has Billy completed"). '
                . 'The report also includes the certificates (tool_certificate) issued to the person, including whether '
                . 'the focused option\'s certificate was actually issued. Every reported option carries the host course '
                . '(id and name) and booking instance the option lives in.',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'what is the booking status of this user',
                'has Billy booked and completed this option',
                'show me this person\'s booking history and waiting-list entries',
                'did this participant receive their certificate',
                'which notification messages did this user get for this booking',
                'how many options has this person completed',
            ],
            'properties' => [
                'userquery' => [
                    'type' => 'string',
                    'description' => 'Name, e-mail or numeric id of the person to diagnose. Pass the user\'s wording '
                        . 'verbatim; "me" resolves to the current user. If only a name is known and it is '
                        . 'ambiguous, provide a more specific name or e-mail address instead.',
                    'required' => false,
                ],
                'userid' => [
                    'type' => 'integer',
                    'description' => 'Numeric user id of the person, when already known. Takes precedence over userquery.',
                    'required' => false,
                ],
                'optionid' => [
                    'type' => 'integer',
                    'description' => 'Booking option id to focus the diagnosis on. Omit for an instance-wide overview.',
                    'required' => false,
                ],
                'optionquery' => [
                    'type' => 'string',
                    'description' => 'Booking option title/query to resolve when optionid is unknown. Omit for an '
                        . 'instance-wide overview of all the person\'s bookings.',
                    'required' => false,
                ],
                'includemessages' => [
                    'type' => 'boolean',
                    'description' => 'Whether to include the received-message history from the logstore (default true).',
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
            'input_fields_for_prompt' => ['userquery (or userid)', 'optionquery (or optionid, optional)'],
            'anchor_fields' => ['userquery', 'userid', 'optionquery', 'optionid'],
        ];

        return $this->enrich_schema_with_prompt_meta($schema);
    }

    /**
     * Return task-specific message triggers.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'mod_booking.diagnose_user_booking_request',
                'description' => 'User asks about ONE person\'s booking status, booking history, completion, cancelled '
                    . 'or previous bookings, submitted form data, which messages that person received, which '
                    . 'certificates that person was issued — or why that person did NOT receive an expected booking '
                    . 'notification/e-mail or certificate: this skill reports the booking messages and certificates '
                    . 'for the person/option.',
                'examples' => [
                    'Has Billy booked and completed the course "First Aid"?',
                    'How many booking options has Maria completed so far?',
                    'Which e-mails did max@example.com get for option 73?',
                    'Did Maria receive the certificate for "First Aid"?',
                    'Which certificates did Billy receive?',
                    'Show me the booking history of user 42.',
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
                'id' => 'mod_booking.diagnose_user_booking',
                'triggers' => [
                    'has booked', 'completed', 'booking status',
                    'how many booked', 'booking history',
                    'which messages', 'which emails', 'form filled in',
                    'cancelled', 'previous bookings',
                    'no mail received', 'no email received', 'no confirmation',
                    'no notification', 'no reminder', 'reminder not received',
                    'no email received', "didn't get notification", 'no confirmation received',
                    'zertifikat', 'zertifikate', 'urkunde', 'certificate', 'certificates',
                    'zertifikat erhalten', 'kein zertifikat', 'no certificate',
                ],
                'guidance' => [
                    '- Use mod_booking.diagnose_user_booking to inspect ONE person\'s booking situation; it is read-only',
                    '  and returns a full status report (status, when booked, completion, previous/cancelled bookings,',
                    '  submitted form data, received messages).',
                    '- Identify the person via userquery (name/e-mail/id) or userid. If only an ambiguous name is known,',
                    '  ask for a more specific name or e-mail address, then pass that (or the resolved userid).',
                    '- Name a specific option (optionquery/optionid) only when the question is about that option. For',
                    '  "how many / which options has X booked or completed", omit the option to get the instance-wide',
                    '  overview.',
                    '- For "person X did not receive a booking mail/confirmation/reminder" questions, this skill is the',
                    '  right tool too: its received-message history shows which booking notifications were actually sent',
                    '  for the person/option (and which are missing).',
                    '- For certificate questions ("did X get the certificate for option Y", "which certificates does X',
                    '  have"), this skill is the right tool: the report\'s certificates section lists the tool_certificate',
                    '  certificates issued to the person, and in option mode whether that option\'s certificate was issued.',
                    '- The report is deliberately verbose; answer the user with only the parts relevant to their question.',
                ],
            ],
        ];
    }

    /**
     * Check task input structure.
     *
     * @param array $input
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>}
     */
    public function check_structure(array $input): array {
        $errors = [];
        $lang = $this->get_output_language($input);

        $hasuserid = !empty((int)($input['userid'] ?? 0));
        $hasuserquery = trim((string)($input['userquery'] ?? '')) !== '';

        if (!$hasuserid && !$hasuserquery) {
            $errors[] = $this->localized_string('agent_booking_diagnose_user_required', null, $lang);
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'ambiguities' => [],
        ];
    }

    /**
     * Explicit preflight for the read-only skill — validate structure and pass input through.
     *
     * @param array $input
     * @param int   $contextid
     * @param int   $userid
     * @return array{status:string,prepared_input:array,issues:array}
     */
    protected function run_preflight(array $input, int $contextid, int $userid): array {
        $structure = $this->check_structure($input);
        if (!($structure['valid'] ?? false)) {
            $issues = [];
            foreach ((array)($structure['errors'] ?? []) as $error) {
                $issues[] = [
                    'code' => 'VALIDATION_ERROR',
                    'severity' => 'needs_clarification',
                    'message' => (string)$error,
                ];
            }
            return $this->invalid($issues);
        }
        return $this->pass($input);
    }

    /**
     * Execute the diagnosis.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        $cmid = $this->resolve_cmid_from_context_or_cmid($contextid);
        $outputlang = $this->get_output_language($input);
        $includemessages = !array_key_exists('includemessages', $input) || !empty($input['includemessages']);

        // 1) Resolve the person to diagnose.
        $targetuserid = $this->resolve_target_userid($input);
        if ($targetuserid <= 0) {
            return $this->error_result(
                $this->localized_string('agent_booking_diagnose_user_notfound', null, $outputlang),
                $input,
                ['Target user could not be resolved']
            );
        }

        // 2) Resolve an optional focus option.
        $optionid = $this->resolve_focus_optionid($input, $cmid, $userid);

        // 3) Branch into option-scoped vs. instance-wide mode.
        if ($optionid > 0) {
            $report = $this->build_option_report($cmid, $optionid, $targetuserid, $includemessages);
            $detail = $this->localized_string('agent_booking_diagnose_user_report_option', (object)[
                'status' => (string)($report['current_status'] ?? 'unknown'),
                'option' => (string)($report['option_title'] ?? ('#' . $optionid)),
            ], $outputlang);
        } else {
            $report = $this->build_userwide_report($cmid, $targetuserid, $includemessages);
            $detail = $this->localized_string('agent_booking_diagnose_user_report_overview', (object)[
                'booked' => (int)($report['totals']['active'] ?? 0),
                'completed' => (int)($report['totals']['completed'] ?? 0),
            ], $outputlang);

            // An option was named but did not resolve to exactly one option — the report degrades
            // to the instance-wide overview; say so instead of silently pretending it was asked for.
            $optionquery = trim((string)($input['optionquery'] ?? ''));
            if ($optionquery !== '') {
                $report['optionquery_unresolved'] = $optionquery;
                $detail = $this->localized_string(
                    'agent_booking_diagnose_user_optionquery_unresolved',
                    $optionquery,
                    $outputlang
                ) . ' ' . $detail;
            }
        }

        $report['target_userid'] = $targetuserid;

        // Entity mentions always carry real moodle_url links for the synchronizer
        // (diagnosed option + target user's profile).
        if ($cmid > 0 && $optionid > 0) {
            $detail .= ' (' . booking_skill_support::build_option_link_for_output($cmid, $optionid) . ')';
        }
        $targetuserlink = booking_skill_support::format_user_links([$targetuserid]);
        if ($targetuserlink !== '') {
            $detail .= ' ' . get_string('agent_booking_diagnosed_user_label', 'booking') . ': '
                . $targetuserlink . '.';
        }

        // Render all Unix timestamps in the report as LLM-readable, timezone-adjusted dates.
        $report = $this->humanize_report_timestamps($report);

        return [
            'status' => 'executed',
            'detail' => $detail,
            'usermessage' => $detail,
            'observation_full' => $this->build_observation_full($detail, $report),
            'resultid' => $optionid > 0 ? $optionid : null,
            // Deduplicated (first occurrence wins): a user can have several answer rows per option
            // (active + previous/cancelled cycles), which would repeat the same optionid here.
            'previewoptionids' => $optionid > 0 ? [$optionid] : array_values(array_unique(array_map(
                static fn(array $o): int => (int)($o['optionid'] ?? 0),
                (array)($report['options'] ?? [])
            ))),
            'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, [
                'Target userid: ' . $targetuserid,
                'Mode: ' . ($optionid > 0 ? ('option#' . $optionid) : 'instance-wide'),
                'Messages included: ' . ($includemessages ? 'yes' : 'no'),
            ]),
        ];
    }

    /**
     * Resolve the target user id from explicit userid or a userquery.
     *
     * @param array $input
     * @return int 0 when it could not be resolved.
     */
    private function resolve_target_userid(array $input): int {
        $explicit = (int)($input['userid'] ?? 0);
        if ($explicit > 0) {
            return $explicit;
        }

        $query = trim((string)($input['userquery'] ?? ''));
        if ($query === '') {
            return 0;
        }

        $resolved = booking_skill_support::resolve_single_user($query);
        if (($resolved['status'] ?? '') === 'ok') {
            return (int)($resolved['userid'] ?? 0);
        }

        return 0;
    }

    /**
     * Resolve an optional focus option id (module-scoped).
     *
     * @param array $input
     * @param int   $cmid
     * @param int   $actinguserid
     * @return int 0 when no option focus is requested or it could not be resolved.
     */
    private function resolve_focus_optionid(array $input, int $cmid, int $actinguserid): int {
        $optionid = (int)($input['optionid'] ?? 0);
        if ($optionid > 0) {
            return $optionid;
        }

        $query = trim((string)($input['optionquery'] ?? ''));
        if ($query === '' || $cmid <= 0) {
            return 0;
        }

        $resolved = booking_skill_support::resolve_single_option($cmid, $query, '');
        if (($resolved['status'] ?? '') === 'ok') {
            return (int)($resolved['optionid'] ?? 0);
        }

        return 0;
    }

    /**
     * Build the option-scoped report for one user via booking_answers (cached, no direct DB).
     *
     * @param int  $cmid
     * @param int  $optionid
     * @param int  $targetuserid
     * @param bool $includemessages
     * @return array<string,mixed>
     */
    private function build_option_report(int $cmid, int $optionid, int $targetuserid, bool $includemessages): array {
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        if (!$settings) {
            return ['mode' => 'option', 'optionid' => $optionid, 'error' => 'option_not_found'];
        }

        $answers = singleton_service::get_instance_of_booking_answers($settings);

        $statusconst = $answers->user_status($targetuserid);
        $report = [
            'mode' => 'option',
            'optionid' => $optionid,
            'option_title' => (string)($settings->text ?? ''),
        ] + $this->option_course_context($settings) + [
            'current_status' => $answers->user_status_as_string($targetuserid),
            'current_status_code' => (int)$statusconst,
            'current_status_label' => $this->status_label((int)$statusconst),
            'is_completed' => (bool)$answers->is_activity_completed($targetuserid),
            'active_answer' => $this->summarize_answer($answers->get_users()[$targetuserid] ?? null),
            'previous_bookings' => $this->summarize_answer_collection(
                $answers->get_userspreviouslybooked()[$targetuserid] ?? null
            ),
            'cancelled_bookings' => $this->summarize_answer_collection(
                $answers->get_usersdeleted()[$targetuserid] ?? null
            ),
            'previous_bookings_count' => $answers->count_previous_bookings($targetuserid),
        ];

        $completion = $answers->return_last_completion($targetuserid);
        $report['completion_timemodified'] = (int)($completion->timemodified ?? 0);

        if ($includemessages) {
            // Message events are logged in the host course of the option's own instance — use the
            // option's cmid, which also works when the skill runs without an ambient module (cmid 0).
            $bookingsettings = $this->booking_settings_or_null((int)($settings->cmid ?? 0));
            $courseid = (int)($bookingsettings->course ?? 0);
            $windowstart = $this->resolve_message_window_start($report);
            $report['received_messages'] = $this->read_received_messages($courseid, $targetuserid, $optionid, $windowstart);
        }

        // Certificates (tool_certificate): whether this option's certificate was issued to the user.
        $configuredtemplate = (int)(booking_option::get_value_of_json_by_key($optionid, 'certificate') ?? 0);
        $report['certificates'] = $this->collect_user_certificates($targetuserid, $configuredtemplate);

        // If the option configures a certificate and the user is completed but no certificate was
        // issued for THIS completion, surface when the option's certificate field was last changed
        // (from the bookingoption_updated log). This lets the answer explain timing — e.g. the
        // certificate was configured only after the user had already completed (certificates are
        // issued at completion time, not retroactively). The log lookup runs only in this case.
        $completiontime = (int)($report['completion_timemodified'] ?? 0);
        if (
            !empty($report['is_completed'])
            && $configuredtemplate > 0
            && !$this->certificate_issued_for_completion($report['certificates'], $configuredtemplate, $completiontime)
        ) {
            $bookingsettings = $this->booking_settings_or_null((int)($settings->cmid ?? 0));
            $lastchanged = $this->certificate_field_last_change($optionid, (int)($bookingsettings->course ?? 0));
            if ($lastchanged !== null) {
                $report['certificate_field'] = [
                    'last_changed' => $lastchanged,
                    'changed_after_user_completion' => $completiontime > 0 && $lastchanged > $completiontime,
                ];
            }
        }

        return $report;
    }

    /**
     * Build the instance-wide report for one user across all options.
     *
     * @param int  $cmid
     * @param int  $targetuserid
     * @param bool $includemessages
     * @return array<string,mixed>
     */
    private function build_userwide_report(int $cmid, int $targetuserid, bool $includemessages): array {
        // A cmid of 0 (no ambient module, e.g. system-context entry points) means: all instances.
        $bookingsettings = $this->booking_settings_or_null($cmid);
        $bookingid = (int)($bookingsettings->id ?? 0);
        $courseid = (int)($bookingsettings->course ?? 0);

        // Single cached query across the whole instance for this user (no direct DB here).
        $rows = (new booking_answers())->get_all_answers_for_user($targetuserid, $bookingid, [
            MOD_BOOKING_STATUSPARAM_BOOKED,
            MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            MOD_BOOKING_STATUSPARAM_RESERVED,
            MOD_BOOKING_STATUSPARAM_PREVIOUSLYBOOKED,
            MOD_BOOKING_STATUSPARAM_DELETED,
        ]);

        $options = [];
        $totals = [
            'active' => 0,
            'completed' => 0,
            'waitinglist' => 0,
            'reserved' => 0,
            'previously_booked' => 0,
            'cancelled' => 0,
        ];
        $earliestcreated = 0;

        foreach ($rows as $row) {
            $statuscode = (int)($row->waitinglist ?? MOD_BOOKING_STATUSPARAM_NOTBOOKED);
            $created = (int)($row->timecreated ?? 0);
            if ($created > 0 && ($earliestcreated === 0 || $created < $earliestcreated)) {
                $earliestcreated = $created;
            }

            switch ($statuscode) {
                case MOD_BOOKING_STATUSPARAM_BOOKED:
                    $totals['active']++;
                    break;
                case MOD_BOOKING_STATUSPARAM_WAITINGLIST:
                    $totals['waitinglist']++;
                    break;
                case MOD_BOOKING_STATUSPARAM_RESERVED:
                    $totals['reserved']++;
                    break;
                case MOD_BOOKING_STATUSPARAM_PREVIOUSLYBOOKED:
                    $totals['previously_booked']++;
                    break;
                case MOD_BOOKING_STATUSPARAM_DELETED:
                    $totals['cancelled']++;
                    break;
            }
            if ((int)($row->completed ?? 0) === 1) {
                $totals['completed']++;
            }

            if (count($options) < self::MAX_OPTIONS_USERWIDE) {
                $rowoptionid = (int)($row->optionid ?? 0);
                $optionsettings = $rowoptionid > 0
                    ? singleton_service::get_instance_of_booking_option_settings($rowoptionid)
                    : null;
                $options[] = [
                    'optionid' => $rowoptionid,
                    'option_title' => (string)($optionsettings->text ?? ''),
                ] + $this->option_course_context($optionsettings) + [
                    'status_code' => $statuscode,
                    'status_label' => $this->status_label($statuscode),
                    'is_completed' => (int)($row->completed ?? 0) === 1,
                    'timebooked' => (int)($row->timebooked ?? 0),
                    'timecreated' => $created,
                    'timemodified' => (int)($row->timemodified ?? 0),
                    'customform' => $this->extract_customform_fields($row),
                ];
            }
        }

        $report = [
            'mode' => 'instance_wide',
            'bookingid' => $bookingid,
            'totals' => $totals,
            'options' => $options,
            'options_truncated' => count($rows) > self::MAX_OPTIONS_USERWIDE,
        ];

        if ($includemessages) {
            $windowstart = $this->resolve_message_window_start(['earliest_created' => $earliestcreated]);
            // Instance-wide: no objectid filter (messages span all of the user's options).
            $report['received_messages'] = $this->read_received_messages($courseid, $targetuserid, 0, $windowstart);
        }

        // All certificates (tool_certificate) the user has been issued.
        $report['certificates'] = $this->collect_user_certificates($targetuserid, 0);

        return $report;
    }

    /**
     * Resolve the logstore message window start: the user's first booking creation, but never
     * earlier than 12 months ago.
     *
     * @param array $report Carries an 'earliest_created' or 'active_answer'/timestamps hint.
     * @return int Unix timestamp.
     */
    private function resolve_message_window_start(array $report): int {
        $floor = strtotime('-12 months');

        $firstbooking = (int)($report['earliest_created'] ?? 0);
        if ($firstbooking === 0) {
            // Option-scoped: derive from the active answer / previous bookings timestamps.
            $candidates = [];
            $active = $report['active_answer'] ?? null;
            if (is_array($active)) {
                $candidates[] = (int)($active['timecreated'] ?? 0);
            }
            foreach ((array)($report['previous_bookings'] ?? []) as $prev) {
                $candidates[] = (int)($prev['timecreated'] ?? 0);
            }
            foreach ((array)($report['cancelled_bookings'] ?? []) as $cancelled) {
                $candidates[] = (int)($cancelled['timecreated'] ?? 0);
            }
            $candidates = array_filter($candidates, static fn(int $t): bool => $t > 0);
            $firstbooking = !empty($candidates) ? min($candidates) : 0;
        }

        if ($firstbooking <= 0) {
            return $floor;
        }

        return max($firstbooking, $floor);
    }

    /**
     * Read the messages a user received about booking option(s) from the standard logstore.
     *
     * Bounded by courseid + relateduserid + the mod_booking message events + a time window, with a
     * hard row limit, via the logstore reader API (no direct query on the log table).
     *
     * @param int $courseid
     * @param int $targetuserid
     * @param int $optionid     0 = all options of the user (instance-wide mode).
     * @param int $windowstart  Unix timestamp lower bound.
     * @return array<string,mixed>
     */
    private function read_received_messages(int $courseid, int $targetuserid, int $optionid, int $windowstart): array {
        global $DB;

        $result = [
            'window_start' => $windowstart,
            'limit' => self::MESSAGE_LOG_LIMIT,
            'count' => 0,
            'truncated' => false,
            'messages' => [],
        ];

        if ($courseid <= 0 || $targetuserid <= 0) {
            return $result;
        }

        $readers = get_log_manager()->get_readers('\core\log\sql_reader');
        $reader = reset($readers);
        if (!$reader) {
            return $result;
        }

        [$insql, $inparams] = $DB->get_in_or_equal(self::MESSAGE_EVENT_NAMES, SQL_PARAMS_NAMED, 'evt');
        $select = "courseid = :courseid AND relateduserid = :relateduserid "
            . "AND eventname $insql AND timecreated >= :windowstart";
        $params = [
            'courseid' => $courseid,
            'relateduserid' => $targetuserid,
            'windowstart' => $windowstart,
        ] + $inparams;

        if ($optionid > 0) {
            $select .= ' AND objectid = :objectid';
            $params['objectid'] = $optionid;
        }

        // Pull one extra row to detect truncation.
        $events = $reader->get_events_select($select, $params, 'timecreated DESC', 0, self::MESSAGE_LOG_LIMIT + 1);
        $events = is_array($events) ? array_values($events) : [];

        if (count($events) > self::MESSAGE_LOG_LIMIT) {
            $result['truncated'] = true;
            $events = array_slice($events, 0, self::MESSAGE_LOG_LIMIT);
        }

        foreach ($events as $event) {
            try {
                $name = method_exists($event, 'get_name') ? (string)$event->get_name() : '';
            } catch (\Throwable $e) {
                $name = '';
            }
            $result['messages'][] = [
                'eventname' => (string)($event->eventname ?? ''),
                'label' => $name,
                'optionid' => (int)($event->objectid ?? 0),
                'timecreated' => (int)($event->timecreated ?? 0),
            ];
        }

        $result['count'] = count($result['messages']);
        return $result;
    }

    /**
     * Build the host-course context of an option: the course (and booking instance) the option lives in.
     *
     * Reads only the already-cached option/instance settings singletons plus the per-request
     * course-name memo, so calling this per option in the instance-wide loop adds no per-row queries.
     *
     * @param object|null $optionsettings the option's booking_option_settings (null-safe)
     * @return array
     */
    private function option_course_context(?object $optionsettings): array {
        $context = [
            'courseid' => 0,
            'coursename' => '',
            'booking_instance' => '',
        ];

        $bookingsettings = $this->booking_settings_or_null((int)($optionsettings->cmid ?? 0));
        if ($bookingsettings === null) {
            return $context;
        }

        $context['courseid'] = (int)($bookingsettings->course ?? 0);
        $context['coursename'] = $this->course_fullname($context['courseid']);
        $context['booking_instance'] = format_string((string)($bookingsettings->name ?? ''), true, ['escape' => false]);

        return $context;
    }

    /**
     * Booking-instance settings for a cmid, null-safe for cmid 0 (instance-less entry points such
     * as the system-context agent, where the settings lookup would raise a debugging notice).
     *
     * @param int $cmid
     * @return object|null
     */
    private function booking_settings_or_null(int $cmid): ?object {
        return $cmid > 0 ? singleton_service::get_instance_of_booking_settings_by_cmid($cmid) : null;
    }

    /**
     * Resolve a course fullname through the per-request memo.
     *
     * @param int $courseid
     * @return string Empty string when the course cannot be read.
     */
    private function course_fullname(int $courseid): string {
        if ($courseid <= 0) {
            return '';
        }
        if (!array_key_exists($courseid, $this->coursefullnames)) {
            try {
                $course = get_course($courseid);
                $this->coursefullnames[$courseid] = format_string((string)$course->fullname, true, ['escape' => false]);
            } catch (\Throwable $e) {
                $this->coursefullnames[$courseid] = '';
            }
        }
        return $this->coursefullnames[$courseid];
    }

    /**
     * Map a status param constant to a stable, machine-readable label.
     *
     * @param int $statuscode
     * @return string
     */
    private function status_label(int $statuscode): string {
        switch ($statuscode) {
            case MOD_BOOKING_STATUSPARAM_BOOKED:
                return 'booked';
            case MOD_BOOKING_STATUSPARAM_WAITINGLIST:
                return 'waitinglist';
            case MOD_BOOKING_STATUSPARAM_RESERVED:
                return 'reserved';
            case MOD_BOOKING_STATUSPARAM_NOTIFYMELIST:
                return 'notifymelist';
            case MOD_BOOKING_STATUSPARAM_DELETED:
                return 'cancelled';
            case MOD_BOOKING_STATUSPARAM_PREVIOUSLYBOOKED:
                return 'previouslybooked';
            default:
                return 'notbooked';
        }
    }

    /**
     * Summarize a single booking answer record into report-safe fields.
     *
     * @param object|null $answer
     * @return array<string,mixed>|null
     */
    private function summarize_answer(?object $answer): ?array {
        if (!is_object($answer)) {
            return null;
        }

        return [
            'status_code' => (int)($answer->waitinglist ?? MOD_BOOKING_STATUSPARAM_NOTBOOKED),
            'status_label' => $this->status_label((int)($answer->waitinglist ?? MOD_BOOKING_STATUSPARAM_NOTBOOKED)),
            'is_completed' => (int)($answer->completed ?? 0) === 1,
            'presence_status' => (int)($answer->status ?? 0),
            'timebooked' => (int)($answer->timebooked ?? 0),
            'timecreated' => (int)($answer->timecreated ?? 0),
            'timemodified' => (int)($answer->timemodified ?? 0),
            'places' => (int)($answer->places ?? 0),
            'customform' => $this->extract_customform_fields($answer),
        ];
    }

    /**
     * Summarize a collection of answer records (one user may have several deleted/previous entries).
     *
     * @param array|\stdClass|null $answers
     * @return array<int,array<string,mixed>>
     */
    private function summarize_answer_collection($answers): array {
        if (is_object($answers)) {
            $answers = [$answers];
        }
        if (!is_array($answers)) {
            return [];
        }

        $summaries = [];
        foreach ($answers as $answer) {
            $summary = $this->summarize_answer(is_object($answer) ? $answer : null);
            if ($summary !== null) {
                $summaries[] = $summary;
            }
        }
        return $summaries;
    }

    /**
     * Extract submitted booking-form (customform) fields already appended to the answer record.
     *
     * The booking_answers loader appends customform elements onto each answer; we surface any
     * scalar customform_* properties so the report carries the user's submitted form data.
     *
     * @param object $answer
     * @return array<string,string>
     */
    private function extract_customform_fields(object $answer): array {
        $fields = [];
        foreach (get_object_vars($answer) as $key => $value) {
            if (strpos((string)$key, 'customform_') !== 0) {
                continue;
            }
            if (is_scalar($value) && (string)$value !== '') {
                $fields[(string)$key] = (string)$value;
            }
        }
        return $fields;
    }

    /**
     * Whether a certificate for the given template was issued to the user for the current completion.
     *
     * An issue counts for the current completion when it was created at or after the completion time
     * (so older issues from previous book-again cycles do not mask a missing current certificate).
     *
     * @param array $certificates the collect_user_certificates() result
     * @param int $templateid the certificate template configured on the option
     * @param int $completiontime the user's completion timestamp (0 if unknown)
     * @return bool
     */
    private function certificate_issued_for_completion(array $certificates, int $templateid, int $completiontime): bool {
        if ($templateid <= 0) {
            return false;
        }
        foreach ((array)($certificates['certificates'] ?? []) as $cert) {
            if ((int)($cert['template_id'] ?? 0) !== $templateid) {
                continue;
            }
            if ($completiontime <= 0 || (int)($cert['timecreated'] ?? 0) >= $completiontime) {
                return true;
            }
        }
        return false;
    }

    /**
     * Timestamp of the most recent change to the option's certificate field, from the standard log.
     *
     * Reads \mod_booking\event\bookingoption_updated events for this option via the logstore reader
     * (bounded, no direct log-table query) and returns the timecreated of the latest event whose
     * other.changes[] touched the 'certificate' field. Returns null when no such change is recorded.
     *
     * @param int $optionid
     * @param int $courseid 0 to skip the course filter
     * @return int|null
     */
    private function certificate_field_last_change(int $optionid, int $courseid): ?int {
        if ($optionid <= 0) {
            return null;
        }

        $readers = get_log_manager()->get_readers('\core\log\sql_reader');
        $reader = reset($readers);
        if (!$reader) {
            return null;
        }

        $select = 'eventname = :eventname AND objectid = :objectid';
        $params = [
            'eventname' => '\\mod_booking\\event\\bookingoption_updated',
            'objectid' => $optionid,
        ];
        if ($courseid > 0) {
            $select .= ' AND courseid = :courseid';
            $params['courseid'] = $courseid;
        }

        // Most recent first; scan a bounded number of update events for a certificate-field change.
        $events = $reader->get_events_select($select, $params, 'timecreated DESC', 0, self::CERT_LOG_SCAN_LIMIT);
        $events = is_array($events) ? array_values($events) : [];

        foreach ($events as $event) {
            $other = $event->other ?? null;
            if (is_string($other)) {
                $decoded = json_decode($other, true);
                $other = is_array($decoded) ? $decoded : null;
            }
            if (!is_array($other)) {
                continue;
            }
            foreach ((array)($other['changes'] ?? []) as $change) {
                if (is_array($change) && (string)($change['fieldname'] ?? '') === 'certificate') {
                    return (int)$event->timecreated;
                }
            }
        }

        return null;
    }

    /**
     * Collect the tool_certificate certificates issued to the user (mod_booking issues via tool_certificate).
     *
     * Read-only and resilient: returns an empty set when the tool_certificate plugin is not installed.
     * In option-scoped mode a configured template id is passed, so the report also states whether the
     * option's own certificate was actually issued to the user (and when / whether it has expired).
     *
     * @param int $targetuserid the diagnosed user
     * @param int $configuredtemplateid the certificate template configured on the focused option (0 = none / instance-wide)
     * @return array<string,mixed>
     */
    private function collect_user_certificates(int $targetuserid, int $configuredtemplateid): array {
        $result = [
            'tool_certificate_available' => false,
            'globally_enabled' => (bool)get_config('booking', 'certificateon'),
            'configured_template_id' => $configuredtemplateid,
            'has_configured_certificate' => $configuredtemplateid > 0,
            'issued_for_configured_template' => null,
            'count' => 0,
            'certificates' => [],
        ];

        if ($targetuserid <= 0 || !class_exists('\\tool_certificate\\certificate')) {
            return $result;
        }
        $result['tool_certificate_available'] = true;

        $now = time();
        $issues = \tool_certificate\certificate::get_issues_for_user($targetuserid, 0, 100);
        foreach ($issues as $issue) {
            $templateid = (int)($issue->templateid ?? 0);
            $expires = (int)($issue->expires ?? 0);
            $entry = [
                'issue_id' => (int)($issue->id ?? 0),
                'template_id' => $templateid,
                'template_name' => format_string((string)($issue->name ?? ''), true, ['escape' => false]),
                'course_name' => (string)($issue->coursename ?? ''),
                'code' => (string)($issue->code ?? ''),
                'timecreated' => (int)($issue->timecreated ?? 0),
                'expires' => $expires,
                'is_expired' => $expires > 0 && $expires < $now,
            ];
            $result['certificates'][] = $entry;

            if (
                $configuredtemplateid > 0
                && $templateid === $configuredtemplateid
                && $result['issued_for_configured_template'] === null
            ) {
                $result['issued_for_configured_template'] = $entry;
            }
        }

        $result['count'] = count($result['certificates']);
        return $result;
    }

    /**
     * Recursively render the report's Unix-timestamp fields as LLM-readable, timezone-adjusted dates.
     *
     * Only the keys in {@see self::OBSERVATION_TIMESTAMP_KEYS} are converted (via the central
     * {@see observation_time::format()}); all internal computations already happened on the raw
     * integers, so this is a pure output pass that does not affect any numeric ids/counts.
     *
     * @param array $report
     * @return array<string,mixed>
     */
    private function humanize_report_timestamps(array $report): array {
        foreach ($report as $key => $value) {
            if (is_array($value)) {
                $report[$key] = $this->humanize_report_timestamps($value);
            } else if (is_int($value) && in_array((string)$key, self::OBSERVATION_TIMESTAMP_KEYS, true)) {
                $report[$key] = observation_time::format($value);
            }
        }
        return $report;
    }

    /**
     * Build the verbose observation payload for synchronizer reasoning.
     *
     * @param string              $detailmessage
     * @param array $report
     * @return string
     */
    private function build_observation_full(string $detailmessage, array $report): string {
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || $json === '') {
            return $detailmessage;
        }
        return $detailmessage . "\n\nBooking diagnosis report (JSON):\n" . $json;
    }

    /**
     * Build a uniform error result payload.
     *
     * @param string             $message
     * @param array              $input
     * @param array  $debugextra
     * @return array<string,mixed>
     */
    private function error_result(string $message, array $input, array $debugextra): array {
        return [
            'status' => 'error',
            'detail' => $message,
            'usermessage' => $message,
            'resultid' => null,
            'observation_full' => $message,
            'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, $debugextra),
        ];
    }
}
