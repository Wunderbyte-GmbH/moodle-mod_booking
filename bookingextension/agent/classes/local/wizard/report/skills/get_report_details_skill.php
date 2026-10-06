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

/**
 * Skill report.get_report_details: one existing custom report as it is configured.
 *
 * The observation is the stored definition (columns with aggregation and sorting, conditions
 * with their stored values, filters, audiences, schedules, optional row count) — the truth channel
 * the authoring skills verify against. The side panel shows the live report view.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_report_details_skill extends report_skill_base implements skill_trigger_provider_interface {
    /** Skill name. */
    public const SKILL_NAME = 'report.get_report_details';

    /**
     * Constructor: read-only (R0).
     */
    public function __construct() {
        parent::__construct(true, skill_risk_class::R0);
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
     * Schema.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'Show one existing custom report of the Moodle Report Builder as it is configured. Covers the '
                . 'source, columns with aggregation and sorting, conditions with values, filters, audiences, schedules, the '
                . 'row count and an optional delivery diagnosis for a named person. Read-only.',
            'is' => 'The configuration and visibility of one existing report.',
            'not' => 'A report by name (search_reports); a source (describe_report_source); who sees it '
                . '(set_report_audience); sending (schedule_report); row counts (query_report).',
            'readonly' => true,
            'fallback_skillcall_string_key' => 'ai_status_skillcall_report_get_report_details',
            'example_utterances' => [
                'what does the completion report contain',
                'which columns and conditions does report 12 have',
                'who can see the booking answers report',
                'when is the weekly report sent and to whom',
                'how many rows does the certificates report currently have',
                'show me the structure of that report',
            ],
            'properties' => [
                'reportid' => [
                    'type' => 'integer',
                    'description' => 'Numeric id of the report when already known (e.g. from an earlier lookup). Never guess.',
                    'required' => false,
                ],
                'reportquery' => [
                    'type' => 'string',
                    'description' => 'The report name exactly as the user wrote it, when no id is known. The system resolves '
                        . 'it and asks when several reports match.',
                    'required' => false,
                ],
                'include_row_count' => [
                    'type' => 'boolean',
                    'description' => 'Whether to count the rows the report currently returns (default true).',
                    'required' => false,
                ],
                'diagnose_userquery' => [
                    'type' => 'string',
                    'description' => 'Optional: a person (name, e-mail or id exactly as the user wrote it) whose delivery of '
                        . 'the scheduled report should be diagnosed: audience membership, schedule state, account state.',
                    'required' => false,
                ],
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'Optional language code override for the user-facing summary, e.g. de or en.',
                    'required' => false,
                ],
            ],
            'prompt_meta' => [
                'input_fields_for_prompt' => ['reportquery', 'reportid'],
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
        return ['reportquery' => 'Course completions', 'include_row_count' => true];
    }

    /**
     * WHEN line of the card.
     *
     * @return array[]
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'report.get_report_details_request',
                'description' => 'User asks what an existing report contains, who can see it, when and to whom it is sent, '
                    . 'or how many rows it has.',
            ],
        ];
    }

    /**
     * The delivery diagnosis names a person.
     *
     * @return string[]
     */
    public function get_person_reference_fields(): array {
        return ['diagnose_userquery'];
    }

    /**
     * Left out, no delivery is diagnosed, so the requester is named through a yes/no companion (#2569).
     *
     * @return array<string,string> field => description of its yes/no companion
     */
    public function get_requester_flag_fields(): array {
        return ['diagnose_userquery' => 'true when the delivery is diagnosed for the requester.'];
    }

    /**
     * Nothing to ground: the target is the user's own wording or a known id.
     *
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function get_dynamic_construction_hints(int $contextid, int $userid): array {
        return [];
    }

    /**
     * Shape: an id or a name must be present.
     *
     * @param array $input
     * @return array{valid:bool,errors:string[],ambiguities:string[]}
     */
    public function check_structure(array $input): array {
        $errors = [];
        if ((int)($input['reportid'] ?? 0) <= 0 && trim((string)($input['reportquery'] ?? '')) === '') {
            $errors[] = get_string('agent_report_report_missing', 'bookingextension_agent');
        }
        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => []];
    }

    /**
     * Preflight (MCP channel): access, then report resolution with candidates on a miss.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    protected function run_preflight(array $input, int $contextid, int $userid): array {
        $lang = $this->get_output_language($input);
        if (!$this->can_use_report_builder($userid)) {
            return $this->permission_denied_preflight($lang);
        }
        $resolved = $this->resolve_report_target($input, $userid);
        if ($resolved['report'] === null) {
            return $this->report_target_clarification($resolved, $userid, $lang);
        }
        $prepared = $input;
        $prepared['reportid'] = (int)$resolved['report']->get('id');
        return $this->pass($prepared);
    }

    /**
     * Execute.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        $lang = $this->get_output_language($input);
        $debugbase = $this->build_skill_debug_message(self::SKILL_NAME, $input);

        if (!$this->can_use_report_builder($userid)) {
            return $this->permission_denied_result($lang, $debugbase . "\nDenied: no report builder access");
        }

        $resolved = $this->resolve_report_target($input, $userid);
        if ($resolved['report'] === null) {
            return $this->report_target_error($resolved, $userid, $lang, $debugbase . "\nUnresolved: " . $resolved['reason']);
        }
        /** @var report $persistent */
        $persistent = $resolved['report'];

        $withrows = !array_key_exists('include_row_count', $input) || !empty($input['include_row_count']);
        $snapshot = (new report_definition_service($this->resolver(), $this->catalog()))->snapshot($persistent, $userid, $withrows);

        // Delivery diagnosis for a named person: deterministic facts, no interpretation.
        $diagnosis = null;
        $diagnosequery = trim((string)($input['diagnose_userquery'] ?? ''));
        if ($diagnosequery !== '') {
            $persons = (new audience_service())->resolve_users([$diagnosequery], $userid);
            if ($persons['problem'] !== null) {
                $kind = (string)$persons['problem']['kind'];
                $message = $this->localized_string($kind === audience_service::PROBLEM_USER_AMBIGUOUS
                    ? 'agent_report_clarify_user_ambiguous' : 'agent_report_clarify_user_not_found', $diagnosequery, $lang);
                $observation = $message;
                foreach ((array)($persons['problem']['options'] ?? []) as $option) {
                    $observation .= "\n- " . $option['label'] . ' (id ' . $option['id'] . ')';
                }
                return [
                    'status' => 'error',
                    'detail' => $message,
                    'usermessage' => $message,
                    'resultid' => null,
                    'observation_full' => $observation,
                    'debugmessage' => $debugbase . "\nUnresolved person for diagnosis",
                ];
            }
            $personid = (int)$persons['ids'][0];
            $service = new schedule_service();
            $diagnosis = ['userid' => $personid, 'schedules' => []];
            foreach (schedule_model::get_records(['reportid' => (int)$persistent->get('id')], 'id') as $schedule) {
                $diagnosis['schedules'][] = $service->diagnose($schedule, $personid);
            }
            $diagnosis['user_in_report_audiences'] = (new audience_service())->covers_user(
                \core_reportbuilder\local\models\audience::get_records(['reportid' => (int)$persistent->get('id')]),
                $personid
            );
            $diagnosis['user_can_view_report'] = \core_reportbuilder\permission::can_view_report($persistent, $personid);
        }

        $usermessage = $this->localized_string('agent_report_details_summary', (object)[
            'name' => $snapshot['name'],
            'columns' => count($snapshot['columns']),
            'conditions' => count($snapshot['conditions']),
            'filters' => count($snapshot['filters']),
            'audiences' => count($snapshot['audiences']),
            'schedules' => count($snapshot['schedules']),
        ], $lang);

        return [
            'status' => 'executed',
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'resultid' => (int)$snapshot['id'],
            'report' => $snapshot,
            'diagnosis' => $diagnosis,
            'observation_full' => $this->build_observation($snapshot, $usermessage)
                . ($diagnosis !== null ? "\n" . $this->build_diagnosis_observation($diagnosis) : ''),
            'debugmessage' => $debugbase . "\nReport: " . $snapshot['id'],
        ];
    }

    /**
     * Live report view with a structure header.
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
     * Delivery facts for one person as observation lines (the person appears as an id only).
     *
     * @param array $diagnosis
     * @return string
     */
    private function build_diagnosis_observation(array $diagnosis): string {
        $lines = ['DELIVERY DIAGNOSIS for user id ' . (int)$diagnosis['userid'] . ':'];
        $lines[] = '- in a report audience: ' . ($diagnosis['user_in_report_audiences'] ? 'yes' : 'no')
            . ' | may view the report: ' . ($diagnosis['user_can_view_report'] ? 'yes' : 'no');
        if (empty($diagnosis['schedules'])) {
            $lines[] = '- the report has no schedule: nothing is sent automatically';
        }
        foreach ($diagnosis['schedules'] as $facts) {
            $lines[] = '- schedule id ' . $facts['scheduleid']
                . ' | enabled: ' . ($facts['schedule_enabled'] ? 'yes' : 'no')
                . ' | recurrence: ' . $facts['recurrence']
                . ' | start: ' . ($facts['timescheduled'] > 0 ? userdate($facts['timescheduled']) : '-')
                . ' | next: ' . ($facts['timenextsend'] > 0 ? userdate($facts['timenextsend']) : '-')
                . ' | last: ' . ($facts['timelastsent'] > 0 ? userdate($facts['timelastsent']) : '-')
                . ' | due now: ' . ($facts['should_send_now'] ? 'yes' : 'no')
                . ' | person in schedule audiences: ' . ($facts['user_in_schedule_audiences'] ? 'yes' : 'no')
                . ' | account: ' . ($facts['user_exists'] ? 'exists' : 'missing')
                . ($facts['user_suspended'] ? ', suspended' : '')
                . ($facts['user_confirmed'] ? '' : ', unconfirmed')
                . ($facts['user_has_email'] ? '' : ', no e-mail')
                . ($facts['user_emailstop'] ? ', e-mail disabled' : '')
                . ' | view as: ' . $facts['viewas']
                . ' | if empty: ' . ($facts['if_empty'] !== '' ? $facts['if_empty'] : 'send_empty')
                . ' | rows: ' . ($facts['report_rowcount'] ?? '-')
                . ($facts['would_skip_empty'] ? ' | would be skipped (empty report)' : '')
                . (!empty($facts['schedule_audiences_missing'])
                    ? ' | schedule refers to deleted audiences: ' . implode(',', $facts['schedule_audiences_missing']) : '');
        }
        return implode("\n", $lines);
    }

    /**
     * Deterministic observation lines from the snapshot.
     *
     * @param array $snapshot
     * @param string $usermessage
     * @return string
     */
    private function build_observation(array $snapshot, string $usermessage): string {
        $lines = [$usermessage . ' [id ' . $snapshot['id'] . ']'];
        $lines[] = 'source: ' . $snapshot['sourcename'] . ' [' . $snapshot['source'] . '] | plugin: ' . $snapshot['component']
            . ' | unique rows: ' . ($snapshot['uniquerows'] ? 'yes' : 'no')
            . ' | can edit: ' . ($snapshot['canedit'] ? 'yes' : 'no')
            . ' | created by me: ' . ($snapshot['created_by_me'] ? 'yes' : 'no')
            . ' | ' . $snapshot['url'];
        if ($snapshot['rowcount'] !== null) {
            $lines[] = 'rows: ' . (int)$snapshot['rowcount'];
        }

        $lines[] = 'COLUMNS (' . count($snapshot['columns']) . '):';
        foreach ($snapshot['columns'] as $column) {
            $line = '- ' . $column['identifier'] . ' | ' . ($column['heading'] !== '' ? $column['heading'] : $column['title']);
            if ($column['aggregation'] !== '') {
                $line .= ' | aggregation: ' . $column['aggregation'];
            }
            if ($column['sortenabled']) {
                $line .= ' | sort: ' . $column['sortdirection'] . ' (' . $column['sortorder'] . ')';
            }
            $lines[] = $line;
        }

        $lines[] = 'CONDITIONS (' . count($snapshot['conditions']) . '):';
        foreach ($snapshot['conditions'] as $condition) {
            $values = [];
            foreach ((array)$condition['values'] as $key => $value) {
                $values[] = $key . '=' . (is_array($value) ? json_encode($value) : (string)$value);
            }
            $lines[] = '- ' . $condition['identifier'] . ' | ' . $condition['title'] . ' | ' . $condition['filterclass']
                . (empty($values) ? ' | no value set' : ' | ' . implode(', ', $values));
        }

        $lines[] = 'FILTERS (' . count($snapshot['filters']) . '):';
        foreach ($snapshot['filters'] as $filter) {
            $lines[] = '- ' . $filter['identifier'] . ' | ' . $filter['title'] . ' | ' . $filter['filterclass'];
        }

        $lines[] = 'AUDIENCES (' . count($snapshot['audiences']) . '):';
        foreach ($snapshot['audiences'] as $audience) {
            // The audience description may name persons; the observation carries the count only.
            $lines[] = '- id ' . $audience['id'] . ' | ' . $audience['type'] . ' | ' . $audience['name']
                . ' | users covered: ' . (int)($audience['usercount'] ?? 0)
                . ($audience['available'] ? '' : ' | unavailable');
        }

        $lines[] = 'SCHEDULES (' . count($snapshot['schedules']) . '):';
        foreach ($snapshot['schedules'] as $schedule) {
            $lines[] = '- id ' . $schedule['id'] . ' | ' . $schedule['name']
                . ' | ' . ($schedule['enabled'] ? 'enabled' : 'disabled')
                . ' | format: ' . $schedule['format']
                . ' | recurrence: ' . $schedule['recurrencename']
                . ' | view as: ' . $schedule['userviewasname']
                . ' | next: ' . ($schedule['timenextsend'] > 0 ? userdate($schedule['timenextsend']) : '-')
                . ' | last: ' . ($schedule['timelastsent'] > 0 ? userdate($schedule['timelastsent']) : '-')
                . ' | audiences: ' . (empty($schedule['audiences']) ? '-' : implode(',', $schedule['audiences']));
        }

        return implode("\n", $lines);
    }
}
