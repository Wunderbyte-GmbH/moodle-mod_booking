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

use bookingextension_agent\local\wizard\core\skills\core_skill_base;
use bookingextension_agent\local\wizard\services\reportbuilder\report_cards_renderer;
use bookingextension_agent\local\wizard\services\reportbuilder\report_definition_service;
use bookingextension_agent\local\wizard\services\reportbuilder\report_resolver;
use bookingextension_agent\local\wizard\services\reportbuilder\report_source_catalog_service;
use context_system;
use core_reportbuilder\permission;

/**
 * Shared base of the report.* family (Moodle Report Builder skills).
 *
 * Custom reports live in the system context, so every skill of the family operates there. Access
 * is decided exactly like core does it (core_reportbuilder\permission: custom reports enabled and
 * any of the reportbuilder capabilities), for the acting user, never as admin. Read-only skills
 * receive the raw planner input in the chat channel (no preflight there), so the same checks run
 * in run_preflight() and in execute().
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class report_skill_base extends core_skill_base {
    /** Issue code: the acting user may not use the Report Builder at all. */
    public const CODE_PERMISSION_DENIED = 'REPORT_PERMISSION_DENIED';

    /** Issue code: the named report source does not exist on this site. */
    public const CODE_SOURCE_VALIDATION_ERROR = 'REPORT_SOURCE_VALIDATION_ERROR';

    /** Issue code: no source named although one is needed. */
    public const CODE_MISSING_SOURCE = 'MISSING_REPORT_SOURCE';

    /** Issue code: no report named although one is needed. */
    public const CODE_MISSING_REPORT = 'MISSING_REPORT_TARGET';

    /** Issue code: the named report does not exist or is not visible. */
    public const CODE_REPORT_NOT_FOUND = 'REPORT_NOT_FOUND';

    /** Issue code: several visible reports match the name. */
    public const CODE_REPORT_AMBIGUOUS = 'REPORT_TARGET_AMBIGUOUS';

    /** Issue code: a column identifier is not offered by the source. */
    public const CODE_COLUMN_VALIDATION_ERROR = 'REPORT_COLUMN_VALIDATION_ERROR';

    /** Issue code: a condition identifier is not offered by the source. */
    public const CODE_CONDITION_VALIDATION_ERROR = 'REPORT_CONDITION_VALIDATION_ERROR';

    /** Issue code: a filter identifier is not offered by the source. */
    public const CODE_FILTER_VALIDATION_ERROR = 'REPORT_FILTER_VALIDATION_ERROR';

    /** Issue code: a condition value or operator does not fit the filter class. */
    public const CODE_CONDITION_VALUE_VALIDATION_ERROR = 'REPORT_CONDITION_VALUE_VALIDATION_ERROR';

    /** Issue code: an aggregation or sort setting does not fit the column. */
    public const CODE_COLUMN_SETTING_VALIDATION_ERROR = 'REPORT_COLUMN_SETTING_VALIDATION_ERROR';

    /** Issue code (confirmable): a report with this name and source already exists. */
    public const CODE_NAME_CONFLICT = 'REPORT_NAME_CONFLICT_CONFIRM_REQUIRED';

    /** Issue code: the site's custom report limit is reached. */
    public const CODE_LIMIT_REACHED = 'REPORT_LIMIT_REACHED';

    /** Issue code: no report name given. */
    public const CODE_MISSING_NAME = 'MISSING_REPORT_NAME';

    /** Issue code: an update without any change. */
    public const CODE_MISSING_CHANGES = 'MISSING_REPORT_CHANGES';

    /** Override token that confirms a duplicate report name. */
    public const OVERRIDE_DUPLICATE_NAME = 'duplicate_name';

    /** Preview type of the source list (sources A and C). */
    public const PREVIEW_TYPE_SOURCES = 'report_sources';

    /** Preview type of a report candidate list (sources A and C). */
    public const PREVIEW_TYPE_REPORTS = 'report_list';

    /** @var report_resolver|null Lazily created resolver. */
    private ?report_resolver $resolver = null;

    /** @var report_source_catalog_service|null Lazily created catalog. */
    private ?report_source_catalog_service $catalog = null;

    /**
     * Custom reports are a system-context feature.
     *
     * @return int
     */
    public function get_required_context_level(): int {
        return CONTEXT_SYSTEM;
    }

    /**
     * The catalog service (one per skill instance; the service caches per request).
     *
     * @return report_source_catalog_service
     */
    protected function catalog(): report_source_catalog_service {
        if ($this->catalog === null) {
            $this->catalog = new report_source_catalog_service();
        }
        return $this->catalog;
    }

    /**
     * The report resolver (one per skill instance).
     *
     * @return report_resolver
     */
    protected function resolver(): report_resolver {
        if ($this->resolver === null) {
            $this->resolver = new report_resolver();
        }
        return $this->resolver;
    }

    /**
     * Resolve the report a skill input points at (`reportid` and/or `reportquery`).
     *
     * Returns the persistent, or the reason it could not be resolved together with the candidate
     * list. Callers turn a miss into a clarification (preflight) or an honest error (chat path).
     *
     * @param array $input
     * @param int $userid
     * @return array{report: \core_reportbuilder\local\models\report|null, reason: string, candidates: array[], reference: string}
     */
    protected function resolve_report_target(array $input, int $userid): array {
        $reportid = (int)($input['reportid'] ?? 0);
        $reference = trim((string)($input['reportquery'] ?? ''));
        $resolved = $this->resolver()->resolve($reportid, $reference, $userid);
        $resolved['reference'] = $reportid > 0 ? (string)$reportid : $reference;
        return $resolved;
    }

    /**
     * Localised user text for an unresolved report target (no codes, no field names).
     *
     * @param string $reason resolver reason
     * @param string $reference what the user named
     * @param string $lang
     * @return string
     */
    protected function report_target_message(string $reason, string $reference, string $lang): string {
        switch ($reason) {
            case 'missing':
                return $this->localized_string('agent_report_report_missing', null, $lang);
            case 'ambiguous':
                return $this->localized_string('agent_report_report_ambiguous', $reference, $lang);
            case 'no_access':
                return $this->localized_string('agent_report_no_view_access', null, $lang);
            default:
                return $this->localized_string('agent_report_report_not_found', $reference, $lang);
        }
    }

    /**
     * Issue code for an unresolved report target.
     *
     * @param string $reason
     * @return string
     */
    protected function report_target_code(string $reason): string {
        switch ($reason) {
            case 'missing':
                return self::CODE_MISSING_REPORT;
            case 'ambiguous':
                return self::CODE_REPORT_AMBIGUOUS;
            case 'no_access':
                return self::CODE_PERMISSION_DENIED;
            default:
                return self::CODE_REPORT_NOT_FOUND;
        }
    }

    /**
     * Preflight clarification for an unresolved report: candidates as options and as side-panel cards.
     *
     * When the name matched nothing, the candidates are all reports the user may view, so the user
     * can pick instead of retyping.
     *
     * @param array $resolved result of resolve_report_target()
     * @param int $userid
     * @param string $lang
     * @return array
     */
    protected function report_target_clarification(array $resolved, int $userid, string $lang): array {
        $candidates = (array)$resolved['candidates'];
        if (empty($candidates) && $resolved['reason'] !== 'no_access') {
            $candidates = $this->resolver()->find($userid)['reports'];
        }
        $issue = [
            'code' => $this->report_target_code((string)$resolved['reason']),
            'severity' => 'needs_clarification',
            'message' => $this->report_target_message((string)$resolved['reason'], (string)$resolved['reference'], $lang),
        ];
        if (!empty($candidates)) {
            $issue['options'] = $this->resolver()->options($candidates);
            $html = (new report_cards_renderer($lang))->render_reports($candidates);
            if ($html !== '') {
                $issue['preview'] = ['type' => self::PREVIEW_TYPE_REPORTS, 'html' => $html];
            }
        }
        return $this->invalid([$issue]);
    }

    /**
     * Honest error result (chat read-only path) for an unresolved report, naming the candidates.
     *
     * @param array $resolved result of resolve_report_target()
     * @param int $userid
     * @param string $lang
     * @param string $debugmessage
     * @return array
     */
    protected function report_target_error(array $resolved, int $userid, string $lang, string $debugmessage): array {
        $message = $this->report_target_message((string)$resolved['reason'], (string)$resolved['reference'], $lang);
        $candidates = (array)$resolved['candidates'];
        if (empty($candidates) && $resolved['reason'] !== 'no_access') {
            $candidates = $this->resolver()->find($userid)['reports'];
        }
        $lines = [$message];
        foreach ($candidates as $candidate) {
            $lines[] = '- ' . $candidate['name'] . ' (id ' . $candidate['id'] . ') | source: ' . $candidate['sourcename'];
        }
        return [
            'status' => 'error',
            'detail' => $message,
            'usermessage' => $message,
            'resultid' => null,
            'observation_full' => implode("\n", $lines),
            'debugmessage' => $debugmessage,
        ];
    }

    /**
     * Turn a definition validation problem (report_definition_service::normalize_*) into a clarification.
     *
     * The message names what was not accepted in the user's language; the alternatives travel as
     * structured options (identifiers, aggregations, operators), never as a field list in the text.
     *
     * @param array $problem {kind, value, identifier?, detail?, options}
     * @param string $lang
     * @return array
     */
    protected function definition_problem_clarification(array $problem, string $lang): array {
        $kind = (string)($problem['kind'] ?? '');
        $value = (string)($problem['value'] ?? '');
        $identifier = (string)($problem['identifier'] ?? '');
        switch ($kind) {
            case report_definition_service::PROBLEM_UNKNOWN_COLUMN:
                $code = self::CODE_COLUMN_VALIDATION_ERROR;
                $message = $this->localized_string('agent_report_clarify_column', $value, $lang);
                break;
            case report_definition_service::PROBLEM_UNKNOWN_CONDITION:
                $code = self::CODE_CONDITION_VALIDATION_ERROR;
                $message = $this->localized_string('agent_report_clarify_condition', $value, $lang);
                break;
            case report_definition_service::PROBLEM_UNKNOWN_FILTER:
                $code = self::CODE_FILTER_VALIDATION_ERROR;
                $message = $this->localized_string('agent_report_clarify_filter', $value, $lang);
                break;
            case report_definition_service::PROBLEM_CONDITION_VALUE:
                $code = self::CODE_CONDITION_VALUE_VALIDATION_ERROR;
                $message = $this->localized_string('agent_report_clarify_condition_value', $identifier, $lang);
                break;
            default:
                $code = self::CODE_COLUMN_SETTING_VALIDATION_ERROR;
                $message = $this->localized_string(
                    'agent_report_clarify_column_setting',
                    (object)['identifier' => $identifier, 'value' => $value],
                    $lang
                );
                break;
        }
        $issue = ['code' => $code, 'severity' => 'needs_clarification', 'message' => $message];
        if (!empty($problem['options'])) {
            $issue['options'] = array_values((array)$problem['options']);
        }
        return $this->invalid([$issue]);
    }

    /**
     * Human-readable rows for a normalised definition (confirmation card, source B).
     *
     * @param array $columns normalised column items
     * @param array $conditions normalised condition items
     * @param array $filters normalised filter items
     * @param string $lang
     * @return array[] rows {label, value}
     */
    protected function definition_rows(array $columns, array $conditions, array $filters, string $lang): array {
        $rows = [];
        if (!empty($columns)) {
            $parts = [];
            foreach ($columns as $column) {
                $part = (string)($column['heading'] ?? '') !== ''
                    ? (string)$column['heading']
                    : (string)($column['title'] ?? $column['identifier']);
                if (!empty($column['aggregation'])) {
                    $part .= ' (' . $column['aggregation'] . ')';
                }
                if (!empty($column['sort'])) {
                    $part .= ' ' . ($column['sort'] === 'desc' ? '↓' : '↑');
                }
                $parts[] = $part;
            }
            $rows[] = [
                'label' => $this->localized_string('agent_report_preview_columns', null, $lang),
                'value' => implode(', ', $parts),
            ];
        }
        if (!empty($conditions)) {
            $parts = [];
            foreach ($conditions as $condition) {
                $part = (string)($condition['title'] ?? $condition['identifier']);
                $values = (array)($condition['values'] ?? []);
                $summary = [];
                foreach ($values as $key => $value) {
                    $suffix = (string)substr((string)$key, strlen((string)$condition['identifier']) + 1);
                    $summary[] = $suffix . '=' . (is_array($value) ? implode('|', $value) : (string)$value);
                }
                if (!empty($summary)) {
                    $part .= ': ' . implode(', ', $summary);
                }
                $parts[] = $part;
            }
            $rows[] = [
                'label' => $this->localized_string('agent_report_preview_conditions', null, $lang),
                'value' => implode('; ', $parts),
            ];
        }
        if (!empty($filters)) {
            $rows[] = [
                'label' => $this->localized_string('agent_report_preview_filters', null, $lang),
                'value' => implode(', ', array_map(
                    static fn(array $f): string => (string)($f['title'] ?? $f['identifier']),
                    $filters
                )),
            ];
        }
        return $rows;
    }

    /**
     * Whether the acting user may author custom reports: the Report Builder is enabled and the user
     * holds an editing capability in the system context.
     *
     * Deliberately the authoring rule, not core's viewing rule: moodle/reportbuilder:view is granted
     * to every authenticated user (reports shared via audiences), while the sources, columns and
     * operators a datasource offers only matter to someone who may build or change a report. Unlike
     * permission::can_create_report() this ignores the site's report limit, which must not hide the
     * catalog.
     *
     * @param int $userid
     * @return bool
     */
    protected function can_author_reports(int $userid): bool {
        global $CFG;
        return !empty($CFG->enablecustomreports) && has_any_capability(
            ['moodle/reportbuilder:edit', 'moodle/reportbuilder:editall'],
            context_system::instance(),
            $userid
        );
    }

    /**
     * Whether the acting user may view custom reports at all (core's own rule).
     *
     * @param int $userid
     * @return bool
     */
    protected function can_use_report_builder(int $userid): bool {
        return permission::can_view_reports_list($userid, context_system::instance());
    }

    /**
     * Preflight issue for a user who may not use the Report Builder.
     *
     * Not user-fixable, but not a technical error either: it ends the turn as a clarification in
     * the user's language (an admin has to grant the permission), never as an `error`.
     *
     * @param string $lang
     * @return array
     */
    protected function permission_denied_preflight(string $lang): array {
        return $this->invalid([[
            'code' => self::CODE_PERMISSION_DENIED,
            'severity' => 'needs_clarification',
            'message' => $this->localized_string('agent_report_no_access', null, $lang),
        ]]);
    }

    /**
     * Honest error result for the chat read-only path (no preflight) when the user lacks access.
     *
     * @param string $lang
     * @param string $debugmessage
     * @return array
     */
    protected function permission_denied_result(string $lang, string $debugmessage): array {
        $message = $this->localized_string('agent_report_no_access', null, $lang);
        return [
            'status' => 'error',
            'detail' => $message,
            'usermessage' => $message,
            'resultid' => null,
            'observation_full' => $message,
            'debugmessage' => $debugmessage,
        ];
    }

    /**
     * Clarification asking which report source is meant, with the candidate list as options and
     * as a side-panel card list (preview source C).
     *
     * @param string $lang
     * @param string $reference What the planner sent ('' when nothing).
     * @return array
     */
    protected function source_clarification(string $lang, string $reference): array {
        $options = $this->catalog()->source_options();
        $message = $reference === ''
            ? $this->localized_string('agent_report_source_missing', null, $lang)
            : $this->localized_string('agent_report_source_not_found', $reference, $lang);
        $issue = [
            'code' => $reference === '' ? self::CODE_MISSING_SOURCE : self::CODE_SOURCE_VALIDATION_ERROR,
            'severity' => 'needs_clarification',
            'message' => $message,
            'options' => $options,
        ];
        $html = (new report_cards_renderer($lang))->render_sources($this->catalog()->list_sources(false));
        if ($html !== '') {
            $issue['preview'] = ['type' => self::PREVIEW_TYPE_SOURCES, 'html' => $html];
        }
        return $this->invalid([$issue]);
    }

    /**
     * Construction grounding shared by the skills that take a `source`: the sources that exist here.
     *
     * Runs after the skill is chosen, so the constructor can copy an exact source value instead of
     * guessing one. Bounded so the prompt stays affordable on sites with many plugins.
     *
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function get_dynamic_construction_hints(int $contextid, int $userid): array {
        try {
            $sources = $this->catalog()->list_sources(false);
        } catch (\Throwable $e) {
            return [];
        }
        if (empty($sources)) {
            return [];
        }
        $pairs = [];
        foreach (array_slice($sources, 0, 40) as $source) {
            $pairs[] = $source['name'] . ' → ' . $source['source'];
        }
        return [
            'guidance' => [
                '- The source field takes one of these EXACT source identifiers (shown as "name → identifier"): '
                    . implode('; ', $pairs) . '. Copy the identifier verbatim; never invent one.',
            ],
            'example_parameters' => ['source' => $sources[0]['source']],
        ];
    }
}
