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
use bookingextension_agent\local\wizard\services\reportbuilder\report_cards_renderer;
use bookingextension_agent\local\wizard\services\reportbuilder\report_resolver;

/**
 * Skill report.search_reports: existing custom reports the acting user may view or edit.
 *
 * Visibility is decided per report by core (audiences, viewall, edit rights). A name the user
 * wrote is matched word-wise against the report names (the search-service pattern); reports the
 * user may not view are never listed, but their count is reported so an empty answer is honest.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_reports_skill extends report_skill_base implements skill_trigger_provider_interface {
    /** Skill name. */
    public const SKILL_NAME = 'report.search_reports';

    /** Default cap. */
    private const DEFAULT_LIMIT = 25;

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
            'description' => 'Find existing custom reports of the Moodle Report Builder that the user may view or edit: id, '
                . 'name, source, plugin, number of audiences and schedules, links. Optionally by name, by source, only '
                . 'editable or only own reports. Read-only.',
            'is' => 'Reports that already exist, by name or source.',
            'not' => 'The data sets a report can be built on (list_report_sources); the contents of one report '
                . '(get_report_details); its row count (query_report).',
            'readonly' => true,
            'fallback_skillcall_string_key' => 'ai_status_skillcall_report_search_reports',
            'example_utterances' => [
                'which custom reports exist on this site',
                'find the report about course completions',
                'show me my reports in the report builder',
                'is there already a report for booking answers',
                'which reports can I edit',
                'list the reports built on the users source',
            ],
            'properties' => [
                'reportquery' => [
                    'type' => 'string',
                    'description' => 'The report name, or the part of it, exactly as the user wrote it. Leave empty to list '
                        . 'all reports the user may see.',
                    'required' => false,
                ],
                'source' => [
                    'type' => 'string',
                    'description' => 'Optional: only reports built on this source (exact source identifier or exact localised '
                        . 'source name).',
                    'required' => false,
                ],
                'editable_only' => [
                    'type' => 'boolean',
                    'description' => 'Only reports the user may edit (default false).',
                    'required' => false,
                ],
                'mine_only' => [
                    'type' => 'boolean',
                    'description' => 'Only reports the user created (default false).',
                    'required' => false,
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of reports to return (default 25, max 50).',
                    'required' => false,
                ],
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'Optional language code override for the user-facing summary, e.g. de or en.',
                    'required' => false,
                ],
            ],
            'prompt_meta' => [
                'input_fields_for_prompt' => ['reportquery'],
                'anchor_fields' => ['reportquery'],
                'context_scopes' => ['system'],
            ],
        ];
    }

    /**
     * Example input.
     *
     * @return array
     */
    public function get_example_input(): array {
        return ['reportquery' => '', 'editable_only' => false];
    }

    /**
     * WHEN line of the card.
     *
     * @return array[]
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'report.search_reports_request',
                'description' => 'User asks which custom reports exist, looks for a report by name or source, or needs a '
                    . 'report id before describing, changing or sending one.',
            ],
        ];
    }

    /**
     * Source grounding only when a source filter is plausible; the name comes from the user.
     *
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function get_dynamic_construction_hints(int $contextid, int $userid): array {
        return [];
    }

    /**
     * Empty input lists everything the user may see.
     *
     * @param array $input
     * @return array{valid:bool,errors:string[],ambiguities:string[]}
     */
    public function check_structure(array $input): array {
        return ['valid' => true, 'errors' => [], 'ambiguities' => []];
    }

    /**
     * Preflight (MCP channel): access and source resolution.
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
        $source = trim((string)($input['source'] ?? ''));
        if ($source !== '') {
            $resolved = $this->catalog()->resolve_source($source);
            if ($resolved['source'] === null) {
                return $this->source_clarification($lang, $source);
            }
            $input['source'] = $resolved['source'];
        }
        return $this->pass($input);
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

        $query = trim((string)($input['reportquery'] ?? ''));
        $source = trim((string)($input['source'] ?? ''));
        if ($source !== '') {
            $resolvedsource = $this->catalog()->resolve_source($source);
            if ($resolvedsource['source'] === null) {
                $message = $this->localized_string('agent_report_source_not_found', $source, $lang);
                return [
                    'status' => 'error',
                    'detail' => $message,
                    'usermessage' => $message,
                    'resultid' => null,
                    'observation_full' => $message,
                    'debugmessage' => $debugbase . "\nUnresolved source: " . $source,
                ];
            }
            $source = $resolvedsource['source'];
        }
        $limit = isset($input['limit'])
            ? max(1, min(report_resolver::MAX_LIMIT, (int)$input['limit']))
            : self::DEFAULT_LIMIT;

        $found = $this->resolver()->find(
            $userid,
            $query,
            $source,
            !empty($input['editable_only']),
            !empty($input['mine_only'])
        );
        $total = (int)$found['total_visible'];
        $reports = array_slice($found['reports'], 0, $limit);

        // The "Found 0 report(s)." line stays byte-identical for the genuinely empty case; a hidden count is
        // appended as its own line so the two cases remain distinguishable (F59 rule).
        $usermessage = $this->localized_string('agent_report_reports_found', $total, $lang);
        $lines = [$usermessage];
        foreach ($reports as $report) {
            $lines[] = '- ' . $report['name'] . ' (id ' . $report['id'] . ')'
                . ' | source: ' . $report['sourcename'] . ' [' . $report['source'] . ']'
                . ' | audiences: ' . (int)$report['audiences']
                . ' | schedules: ' . (int)$report['schedules']
                . ' | can edit: ' . ($report['canedit'] ? 'yes' : 'no')
                . ' | ' . $report['url'];
        }
        if ($total > count($reports)) {
            $lines[] = $this->localized_string(
                'agent_report_list_partial',
                (object)['shown' => count($reports), 'total' => $total],
                $lang
            );
        }
        if ((int)$found['hidden'] > 0) {
            $lines[] = $this->localized_string('agent_report_reports_hidden', (int)$found['hidden'], $lang);
        }

        return [
            'status' => 'executed',
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'resultid' => !empty($reports) ? (int)$reports[0]['id'] : null,
            'reports' => $reports,
            'total_visible' => $total,
            'hidden' => (int)$found['hidden'],
            'observation_full' => implode("\n", $lines),
            'debugmessage' => $debugbase . "\nResults: " . count($reports) . "\nVisible: " . $total
                . "\nHidden: " . (int)$found['hidden'],
        ];
    }

    /**
     * Side-panel cards of the found reports.
     *
     * @param array $resultentry
     * @param int $contextid
     * @param int $userid
     * @return array|null
     */
    public function get_result_preview(array $resultentry, int $contextid, int $userid): ?array {
        $reports = (array)($resultentry['reports'] ?? []);
        if (empty($reports)) {
            return null;
        }
        $html = (new report_cards_renderer())->render_reports($reports);
        if ($html === '') {
            return null;
        }
        return [
            'type' => self::PREVIEW_TYPE_REPORTS,
            'html' => $html,
            'replace' => true,
            'payload' => ['reportids' => array_map('intval', array_column($reports, 'id'))],
        ];
    }
}
