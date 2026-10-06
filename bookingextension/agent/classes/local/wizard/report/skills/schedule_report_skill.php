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

namespace bookingextension_agent\local\wizard\report\skills;

use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\interfaces\skill_trigger_provider_interface;
use bookingextension_agent\local\wizard\services\reportbuilder\audience_service;
use bookingextension_agent\local\wizard\services\reportbuilder\report_definition_service;
use bookingextension_agent\local\wizard\services\reportbuilder\report_preview_renderer;
use bookingextension_agent\local\wizard\services\reportbuilder\schedule_service;
use core_reportbuilder\local\models\report;
use core_reportbuilder\local\models\schedule as schedule_model;
use core_reportbuilder\permission;

/**
 * Skill report.schedule_report: send a custom report on a schedule.
 *
 * Create, change, enable, disable or send now. Recipients are the report's audiences (all of
 * them, or named by id); format, recurrence, start time, view-as, empty-report policy, subject
 * and message are structured enums and ISO dates, validated against what the site offers. The
 * schedule type is the registered one (pluggable since 5.1; the single message type is taken
 * without asking).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class schedule_report_skill extends report_skill_base implements skill_trigger_provider_interface {
    /** Skill name. */
    public const SKILL_NAME = 'report.schedule_report';

    /** Issue code: the report has no audience to send to. */
    public const CODE_AUDIENCE_MISSING = 'SCHEDULE_AUDIENCE_MISSING';

    /** Issue code: a schedule setting is not one of the offered values. */
    public const CODE_SETTING = 'SCHEDULE_SETTING_VALIDATION_ERROR';

    /** Issue code: the start time is not a valid future date. */
    public const CODE_START = 'SCHEDULE_START_VALIDATION_ERROR';

    /** Issue code: view-as needs a capability the user lacks. */
    public const CODE_VIEWAS_DENIED = 'SCHEDULE_VIEWAS_PERMISSION_DENIED';

    /** Issue code: the schedule to change is not on this report. */
    public const CODE_SCHEDULE_NOT_FOUND = 'SCHEDULE_NOT_FOUND';

    /** Issue code: missing or unknown action. */
    public const CODE_MISSING_ACTION = 'MISSING_SCHEDULE_ACTION';

    /** @var string[] Actions. */
    private const ACTIONS = ['create', 'update', 'enable', 'disable', 'send_now'];

    /**
     * Constructor: broad write (R2).
     */
    public function __construct() {
        parent::__construct(false, skill_risk_class::R2);
    }

    /**
     * Skill name.
     *
     * @return string
     */
    public function get_name(): string {
        return self::SKILL_NAME;
    }

    /**
     * Gate 2 (core's per-report edit rule and scheduleviewas on top).
     *
     * @return string[]
     */
    public function get_required_native_capabilities(): array {
        return ['moodle/reportbuilder:edit'];
    }

    /**
     * The view-as person is a person reference.
     *
     * @return string[]
     */
    public function get_person_reference_fields(): array {
        return ['viewas_userquery'];
    }

    /**
     * Left out, the schedule has no view-as person, so the requester is named through a yes/no companion (#2569).
     *
     * @return array<string,string> field => description of its yes/no companion
     */
    public function get_requester_flag_fields(): array {
        return ['viewas_userquery' => 'true when the report is viewed as the requester.'];
    }

    /**
     * Schema.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'Send a custom report of the Moodle Report Builder automatically. Create a schedule (recipient '
                . 'audiences, format, recurrence, start time, view-as, empty-report policy, subject, message), change it, '
                . 'enable, disable or send it now.',
            'is' => 'When and to whom a report is sent.',
            'not' => 'Who may open it (set_report_audience); its columns (update_report); its current schedules '
                . '(get_report_details).',
            'readonly' => false,
            'fallback_skillcall_string_key' => 'ai_status_skillcall_report_schedule_report',
            'example_utterances' => [
                'send the completion report every Monday as an Excel file',
                'email that report to its audience once a month',
                'schedule the users report daily starting next week',
                'send the report now',
                'pause the weekly report schedule',
                'do not send the report when it is empty',
            ],
            'properties' => [
                'reportid' => [
                    'type' => 'integer',
                    'description' => 'Numeric id of the report when already known. Never guess.',
                    'required' => false,
                ],
                'reportquery' => [
                    'type' => 'string',
                    'description' => 'The report name exactly as the user wrote it, when no id is known.',
                    'required' => false,
                ],
                'action' => [
                    'type' => 'string',
                    'description' => 'create (default), update, enable, disable or send_now.',
                    'required' => false,
                ],
                'scheduleid' => [
                    'type' => 'integer',
                    'description' => 'For update, enable, disable and send_now: the schedule id (from the report details). '
                        . 'Optional when the report has exactly one schedule.',
                    'required' => false,
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'Schedule name (default: the report name).',
                    'required' => false,
                ],
                'audiences' => [
                    'type' => 'array',
                    'description' => 'Recipient audiences: ids of the report\'s audiences, or the word all (default all).',
                    'required' => false,
                ],
                'format' => [
                    'type' => 'string',
                    'description' => 'Export format: one of the site\'s enabled data formats (e.g. csv, xlsx, ods, pdf, json).',
                    'required' => false,
                ],
                'recurrence' => [
                    'type' => 'string',
                    'description' => 'none, hourly, daily, weekdays, weekly, monthly or annually (default none).',
                    'required' => false,
                ],
                'starttime' => [
                    'type' => 'string',
                    'description' => 'First send time as ISO 8601 (YYYY-MM-DD or YYYY-MM-DD HH:MM) in the user\'s time zone; '
                        . 'default tomorrow at midnight. Must not be in the past.',
                    'required' => false,
                ],
                'viewas' => [
                    'type' => 'string',
                    'description' => 'Whose view of the data is sent: creator (default), recipient, or user (then '
                        . 'viewas_userquery names that user).',
                    'required' => false,
                ],
                'viewas_userquery' => [
                    'type' => 'string',
                    'description' => 'For viewas=user: the person exactly as the user named them (name, e-mail or id).',
                    'required' => false,
                ],
                'subject' => [
                    'type' => 'string',
                    'description' => 'Message subject (default: the report name).',
                    'required' => false,
                ],
                'message' => [
                    'type' => 'string',
                    'description' => 'Message body (default: the report name).',
                    'required' => false,
                ],
                'if_empty' => [
                    'type' => 'string',
                    'description' => 'What to do when the report has no rows: send_empty (default), send_without or dont_send.',
                    'required' => false,
                ],
                'enabled' => [
                    'type' => 'boolean',
                    'description' => 'Whether the schedule is active (default true).',
                    'required' => false,
                ],
                'schedule_type' => [
                    'type' => 'string',
                    'description' => 'Only when the site offers several schedule types: which one.',
                    'required' => false,
                ],
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'Optional language code override for the user-facing summary, e.g. de or en.',
                    'required' => false,
                ],
            ],
            'prompt_meta' => [
                'input_fields_for_prompt' => ['reportquery', 'recurrence', 'format'],
                'anchor_fields' => ['reportquery'],
                'context_scopes' => ['system'],
                'required_groups' => [['reportid', 'reportquery']],
            ],
        ];
    }

    /**
     * Example input.
     *
     * @return array
     */
    public function get_example_input(): array {
        return [
            'reportquery' => 'Active users',
            'action' => 'create',
            'recurrence' => 'weekly',
            'format' => 'xlsx',
            // A placeholder, not a time: an example value is copied when the user gave none (wave 30, UO-3).
            'starttime' => 'YYYY-MM-DD HH:MM',
        ];
    }

    /**
     * WHEN line of the card.
     *
     * @return array[]
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'report.schedule_report_request',
                'description' => 'User wants a custom report sent by e-mail on a schedule or right now, or a schedule '
                    . 'changed, paused or resumed.',
            ],
        ];
    }

    /**
     * Grounding: the formats and recurrences this site offers.
     *
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function get_dynamic_construction_hints(int $contextid, int $userid): array {
        try {
            $service = new schedule_service();
            $formats = array_keys($service->formats());
            $recurrences = array_keys($service->recurrences());
        } catch (\Throwable $e) {
            return [];
        }
        return [
            'guidance' => [
                '- format takes one of: ' . implode(', ', $formats) . '. recurrence takes one of: '
                    . implode(', ', $recurrences) . '. starttime is ISO 8601; never invent a date the user did not give.',
            ],
        ];
    }

    /**
     * Confirmation card (source B).
     *
     * @param array $input Prepared input.
     * @return array|null
     */
    public function describe_proposed_action(array $input): ?array {
        $lang = $this->get_output_language($input);
        $resolved = (array)($input['resolved'] ?? []);
        $summary = (array)($resolved['summary'] ?? []);
        $action = (string)($input['action'] ?? 'create');
        $rows = [[
            'label' => $this->localized_string('agent_report_card_report', null, $lang),
            'value' => (string)($resolved['reportname'] ?? '') . ' (#' . (int)($input['reportid'] ?? 0) . ')',
        ]];
        if (!empty($resolved['schedulename'])) {
            $rows[] = [
                'label' => $this->localized_string('agent_report_card_schedule', null, $lang),
                'value' => (string)$resolved['schedulename'],
            ];
        }
        foreach (['audiences', 'format', 'recurrence', 'starttime', 'viewas', 'if_empty'] as $key) {
            if (isset($summary[$key]) && $summary[$key] !== '') {
                $rows[] = [
                    'label' => $this->localized_string('agent_report_card_schedule_' . $key, null, $lang),
                    'value' => (string)$summary[$key],
                ];
            }
        }
        return [
            'title' => $this->localized_string(
                'agent_report_card_schedule_' . $action . '_title',
                (string)($resolved['reportname'] ?? ''),
                $lang
            ),
            'summary' => '',
            'rows' => $rows,
        ];
    }

    /**
     * Queue identity: report, action, schedule and settings.
     *
     * @param array $input
     * @return array
     */
    public function build_queue_business_identity(array $input): array {
        $settings = array_intersect_key($input, array_flip(['name', 'audiences', 'format', 'recurrence', 'starttime',
            'viewas', 'viewas_userquery', 'subject', 'message', 'if_empty', 'enabled', 'schedule_type']));
        return [
            'task' => self::SKILL_NAME,
            'report' => (int)($input['reportid'] ?? 0) ?: trim((string)($input['reportquery'] ?? '')),
            'action' => (string)($input['action'] ?? 'create'),
            'schedule' => (int)($input['scheduleid'] ?? 0),
            'settings' => sha1(json_encode($settings)),
        ];
    }

    /**
     * Shape: a target and a known action.
     *
     * @param array $input
     * @return array{valid:bool,errors:string[],ambiguities:string[]}
     */
    public function check_structure(array $input): array {
        $errors = [];
        if ((int)($input['reportid'] ?? 0) <= 0 && trim((string)($input['reportquery'] ?? '')) === '') {
            $errors[] = get_string('agent_report_report_missing', 'bookingextension_agent');
        }
        $action = strtolower(trim((string)($input['action'] ?? 'create')));
        if (!in_array($action, self::ACTIONS, true)) {
            $errors[] = get_string('agent_report_clarify_schedule_action', 'bookingextension_agent');
        }
        if (
            array_key_exists('audiences', $input) && $input['audiences'] !== null
            && !is_array($input['audiences']) && !is_string($input['audiences'])
        ) {
            $errors[] = get_string('agent_report_clarify_list_shape', 'bookingextension_agent', 'audiences');
        }
        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => []];
    }

    /**
     * Resolve report and schedule, build and validate the record, freeze it.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    protected function run_preflight(array $input, int $contextid, int $userid): array {
        $lang = $this->get_output_language($input);

        if (!$this->can_author_reports($userid)) {
            return $this->permission_denied_preflight($lang);
        }
        $resolved = $this->resolve_report_target($input, $userid);
        if ($resolved['report'] === null) {
            return $this->report_target_clarification($resolved, $userid, $lang);
        }
        /** @var report $persistent */
        $persistent = $resolved['report'];
        if (!permission::can_edit_report($persistent, $userid)) {
            return $this->invalid([[
                'code' => self::CODE_PERMISSION_DENIED,
                'severity' => 'needs_clarification',
                'message' => $this->localized_string('agent_report_no_edit_access', null, $lang),
            ]]);
        }
        $reportid = (int)$persistent->get('id');
        $action = strtolower(trim((string)($input['action'] ?? 'create')));
        if (!in_array($action, self::ACTIONS, true)) {
            return $this->invalid([[
                'code' => self::CODE_MISSING_ACTION,
                'severity' => 'needs_clarification',
                'message' => $this->localized_string('agent_report_clarify_schedule_action', null, $lang),
                'options' => array_map(static fn(string $a): array => ['id' => $a, 'label' => $a], self::ACTIONS),
            ]]);
        }
        $service = new schedule_service();

        // View-as person: resolve the name to an id before building.
        $viewasquery = trim((string)($input['viewas_userquery'] ?? ''));
        if ($viewasquery !== '') {
            $persons = (new audience_service())->resolve_users([$viewasquery], $userid);
            if ($persons['problem'] !== null) {
                $kind = (string)$persons['problem']['kind'];
                $issue = [
                    'code' => $kind === audience_service::PROBLEM_USER_AMBIGUOUS
                        ? set_report_audience_skill::CODE_AUDIENCE_MEMBER_AMBIGUOUS
                        : set_report_audience_skill::CODE_AUDIENCE_MEMBER,
                    'severity' => 'needs_clarification',
                    'message' => $this->localized_string($kind === audience_service::PROBLEM_USER_AMBIGUOUS
                        ? 'agent_report_clarify_user_ambiguous' : 'agent_report_clarify_user_not_found', $viewasquery, $lang),
                ];
                if (!empty($persons['problem']['options'])) {
                    $issue['options'] = $persons['problem']['options'];
                }
                return $this->invalid([$issue]);
            }
            $input['viewas_userid'] = (int)$persons['ids'][0];
            $input['viewas'] = 'user';
        }

        $existing = null;
        if ($action !== 'create') {
            $schedules = schedule_model::get_records(['reportid' => $reportid], 'id');
            $scheduleid = (int)($input['scheduleid'] ?? 0);
            if ($scheduleid <= 0 && count($schedules) === 1) {
                $existing = reset($schedules);
            } else {
                foreach ($schedules as $schedule) {
                    if ((int)$schedule->get('id') === $scheduleid) {
                        $existing = $schedule;
                    }
                }
            }
            if ($existing === null) {
                return $this->invalid([[
                    'code' => self::CODE_SCHEDULE_NOT_FOUND,
                    'severity' => 'needs_clarification',
                    'message' => $this->localized_string('agent_report_clarify_schedule_id', null, $lang),
                    'options' => array_map(static fn(schedule_model $s): array => [
                        'id' => (string)(int)$s->get('id'),
                        'label' => (string)$s->get('name'),
                    ], array_values($schedules)),
                ]]);
            }
        }

        $record = [];
        $summary = [];
        if ($action === 'create' || $action === 'update') {
            $built = $service->build($persistent, $input, $userid, $existing);
            if ($built['problem'] !== null) {
                return $this->schedule_problem_clarification($built['problem'], $lang);
            }
            $record = $built['record'];
            $summary = $built['summary'];
            if (
                $action === 'update' && empty(array_diff_key($record, ['configdata' => 1, 'classname' => 1]))
                && trim((string)($input['subject'] ?? '')) === '' && trim((string)($input['message'] ?? '')) === ''
                && trim((string)($input['if_empty'] ?? '')) === ''
            ) {
                return $this->invalid([[
                    'code' => self::CODE_MISSING_CHANGES,
                    'severity' => 'needs_clarification',
                    'message' => $this->localized_string('agent_report_clarify_schedule_changes', null, $lang),
                ]]);
            }
        } else if ($action === 'send_now' && !$existing->get('enabled')) {
            return $this->invalid([[
                'code' => self::CODE_SETTING,
                'severity' => 'needs_clarification',
                'message' => $this->localized_string('agent_report_clarify_schedule_disabled', null, $lang),
            ]]);
        }

        return $this->pass([
            'reportid' => $reportid,
            'action' => $action,
            'scheduleid' => $existing !== null ? (int)$existing->get('id') : 0,
            'record' => $record,
            'outputlang' => $lang,
            'resolved' => [
                'reportname' => (string)$persistent->get('name'),
                'schedulename' => $existing !== null ? (string)$existing->get('name') : (string)($record['name'] ?? ''),
                'summary' => $summary,
            ],
        ]);
    }

    /**
     * Apply the frozen schedule change.
     *
     * @param array $preparedinput
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $preparedinput, int $contextid, int $userid): array {
        $lang = $this->get_output_language($preparedinput);
        $debugbase = $this->build_skill_debug_message(self::SKILL_NAME, $preparedinput);
        $reportid = (int)($preparedinput['reportid'] ?? 0);
        $action = (string)($preparedinput['action'] ?? 'create');
        $scheduleid = (int)($preparedinput['scheduleid'] ?? 0);

        $persistent = $reportid > 0 ? report::get_record(['id' => $reportid]) : false;
        if (!$persistent || !permission::can_edit_report($persistent, $userid)) {
            return $this->permission_denied_result($lang, $debugbase . "\nDenied: cannot edit report " . $reportid);
        }

        $service = new schedule_service();
        $record = (array)($preparedinput['record'] ?? []);
        switch ($action) {
            case 'create':
                $schedule = $service->create($reportid, $record);
                $scheduleid = (int)$schedule->get('id');
                break;
            case 'update':
                $service->update($reportid, $scheduleid, $record);
                break;
            case 'enable':
            case 'disable':
                $service->toggle($reportid, $scheduleid, $action === 'enable');
                break;
            case 'send_now':
                $service->send_now($reportid, $scheduleid);
                break;
        }

        $definition = new report_definition_service($this->resolver(), $this->catalog());
        $snapshot = $definition->snapshot(report::get_record(['id' => $reportid]), $userid, false);
        $current = null;
        foreach ($snapshot['schedules'] as $entry) {
            if ((int)$entry['id'] === $scheduleid) {
                $current = $entry;
            }
        }
        $usermessage = $this->localized_string('agent_report_schedule_' . $action, (object)[
            'name' => $snapshot['name'],
            'schedule' => (string)($current['name'] ?? ''),
            'next' => !empty($current['timenextsend']) ? userdate((int)$current['timenextsend']) : '-',
            'url' => $snapshot['url'],
        ], $lang);

        $lines = [$usermessage . ' [id ' . $snapshot['id'] . ']'];
        $lines[] = 'SCHEDULES (' . count($snapshot['schedules']) . '):';
        foreach ($snapshot['schedules'] as $entry) {
            $lines[] = '- id ' . $entry['id'] . ' | ' . $entry['name']
                . ' | ' . ($entry['enabled'] ? 'enabled' : 'disabled')
                . ' | format: ' . $entry['format']
                . ' | recurrence: ' . $entry['recurrencename']
                . ' | view as: ' . $entry['userviewasname']
                . ' | next: ' . ($entry['timenextsend'] > 0 ? userdate($entry['timenextsend']) : '-')
                . ' | audiences: ' . (empty($entry['audiences']) ? '-' : implode(',', $entry['audiences']));
        }
        if ($action === 'send_now') {
            $lines[] = 'send_now: queued as an adhoc task; delivery happens on the next cron run';
        }

        return [
            'status' => 'executed',
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'resultid' => (int)$snapshot['id'],
            'report' => $snapshot,
            'scheduleid' => $scheduleid,
            'observation_full' => implode("\n", $lines),
            'produced_outputs' => [
                'reportid' => (int)$snapshot['id'],
                'reportname' => (string)$snapshot['name'],
                'scheduleid' => $scheduleid,
            ],
            'debugmessage' => $debugbase . "\nReport: " . $reportid . "\nAction: " . $action . "\nSchedule: " . $scheduleid,
        ];
    }

    /**
     * Live view of the report after the change.
     *
     * @param array $resultentry
     * @param int $contextid
     * @param int $userid
     * @return array|null
     */
    public function get_result_preview(array $resultentry, int $contextid, int $userid): ?array {
        $snapshot = (array)($resultentry['report'] ?? []);
        $reportid = (int)($snapshot['id'] ?? 0);
        if ($reportid <= 0) {
            return null;
        }
        $persistent = report::get_record(['id' => $reportid]);
        if (!$persistent) {
            return null;
        }
        return (new report_preview_renderer())->build($persistent, $userid, $snapshot);
    }

    /**
     * Clarification for a schedule build problem.
     *
     * @param array $problem {kind, value, options, detail}
     * @param string $lang
     * @return array
     */
    private function schedule_problem_clarification(array $problem, string $lang): array {
        $kind = (string)($problem['kind'] ?? '');
        $value = (string)($problem['value'] ?? '');
        $detail = (string)($problem['detail'] ?? '');
        switch ($kind) {
            case schedule_service::PROBLEM_NO_AUDIENCE:
                $code = self::CODE_AUDIENCE_MISSING;
                $message = $this->localized_string('agent_report_clarify_schedule_no_audience', null, $lang);
                break;
            case schedule_service::PROBLEM_AUDIENCE:
                $code = self::CODE_SETTING;
                $message = $this->localized_string('agent_report_clarify_schedule_audience', $value, $lang);
                break;
            case schedule_service::PROBLEM_START:
                $code = self::CODE_START;
                $message = $this->localized_string($detail === 'past'
                    ? 'agent_report_clarify_schedule_start_past' : 'agent_report_clarify_schedule_start', $value, $lang);
                break;
            case schedule_service::PROBLEM_VIEWAS:
                $code = $detail === 'capability' ? self::CODE_VIEWAS_DENIED : self::CODE_SETTING;
                $message = $this->localized_string($detail === 'capability'
                    ? 'agent_report_clarify_schedule_viewas_denied' : 'agent_report_clarify_schedule_viewas', $value, $lang);
                break;
            case schedule_service::PROBLEM_FORMAT:
                $code = self::CODE_SETTING;
                $message = $this->localized_string('agent_report_clarify_schedule_format', $value, $lang);
                break;
            case schedule_service::PROBLEM_RECURRENCE:
                $code = self::CODE_SETTING;
                $message = $this->localized_string('agent_report_clarify_schedule_recurrence', $value, $lang);
                break;
            case schedule_service::PROBLEM_IF_EMPTY:
                $code = self::CODE_SETTING;
                $message = $this->localized_string('agent_report_clarify_schedule_if_empty', $value, $lang);
                break;
            default:
                $code = self::CODE_SETTING;
                $message = $this->localized_string('agent_report_clarify_schedule_type', null, $lang);
                break;
        }
        $issue = ['code' => $code, 'severity' => 'needs_clarification', 'message' => $message];
        if (!empty($problem['options'])) {
            $issue['options'] = array_values((array)$problem['options']);
        }
        return $this->invalid([$issue]);
    }
}
