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

namespace bookingextension_agent\local\wizard\services\reportbuilder;

use core_reportbuilder\local\models\audience as audience_model;
use core_reportbuilder\local\models\report;
use core_reportbuilder\local\models\schedule as schedule_model;
use core_reportbuilder\local\report\base;
use core_reportbuilder\permission;
use core_text;
use moodle_url;

/**
 * Finds existing custom reports the acting user may view, and resolves a planner reference to one.
 *
 * Visibility is core's own rule per report (core_reportbuilder\permission::can_view_report for the
 * acting user), never a role guess. Resolution is by id, by exact name (case-insensitive) or by a
 * word-wise LIKE on the name — the established search-service pattern — and a query that matches
 * several reports yields candidates, never a silent pick.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class report_resolver {
    /** Hard cap on listed reports. */
    public const MAX_LIMIT = 50;

    /**
     * Custom reports visible to the user, with compact metadata.
     *
     * @param int $userid Acting user.
     * @param string $query Substring / word-wise match on the report name ('' = all).
     * @param string $source Restrict to one datasource FQCN ('' = all).
     * @param bool $editableonly Only reports the user may edit.
     * @param bool $mineonly Only reports the user created.
     * @return array{reports: array[], total_visible: int, hidden: int}
     */
    public function find(
        int $userid,
        string $query = '',
        string $source = '',
        bool $editableonly = false,
        bool $mineonly = false
    ): array {
        global $DB;

        $select = 'type = :type';
        $params = ['type' => base::TYPE_CUSTOM_REPORT];
        $source = ltrim(trim($source), '\\');
        if ($source !== '') {
            $select .= ' AND source = :source';
            $params['source'] = $source;
        }
        if ($mineonly) {
            $select .= ' AND usercreated = :usercreated';
            $params['usercreated'] = $userid;
        }
        $words = $this->words($query);
        foreach ($words as $i => $word) {
            $select .= ' AND ' . $DB->sql_like('name', ':w' . $i, false, false);
            $params['w' . $i] = '%' . $DB->sql_like_escape($word) . '%';
        }

        $records = $DB->get_records_select('reportbuilder_report', $select, $params, 'name ASC, id ASC');
        $hidden = 0;
        $reports = [];
        foreach ($records as $record) {
            $persistent = new report(0, $record);
            if (!permission::can_view_report($persistent, $userid)) {
                $hidden++;
                continue;
            }
            $canedit = permission::can_edit_report($persistent, $userid);
            if ($editableonly && !$canedit) {
                $hidden++;
                continue;
            }
            $reports[] = $this->summarize($persistent, $userid, $canedit);
        }

        return ['reports' => $reports, 'total_visible' => count($reports), 'hidden' => $hidden];
    }

    /**
     * Resolve a planner reference (id and/or name) to exactly one visible report.
     *
     * @param int $reportid Explicit id (0 = none).
     * @param string $query Name as the user wrote it ('' = none).
     * @param int $userid Acting user.
     * @return array{report: report|null, candidates: array[], reason: string}
     *         reason: '' | 'missing' | 'not_found' | 'ambiguous' | 'no_access'
     */
    public function resolve(int $reportid, string $query, int $userid): array {
        if ($reportid > 0) {
            $persistent = report::get_record(['id' => $reportid, 'type' => base::TYPE_CUSTOM_REPORT]);
            if (!$persistent) {
                return ['report' => null, 'candidates' => [], 'reason' => 'not_found'];
            }
            if (!permission::can_view_report($persistent, $userid)) {
                return ['report' => null, 'candidates' => [], 'reason' => 'no_access'];
            }
            return ['report' => $persistent, 'candidates' => [], 'reason' => ''];
        }

        $query = trim($query);
        if ($query === '') {
            return ['report' => null, 'candidates' => [], 'reason' => 'missing'];
        }

        $found = $this->find($userid, $query);
        $reports = $found['reports'];
        if (empty($reports)) {
            return ['report' => null, 'candidates' => [], 'reason' => 'not_found'];
        }

        $exact = array_values(array_filter(
            $reports,
            static fn(array $r): bool => core_text::strtolower((string)$r['name']) === core_text::strtolower($query)
        ));
        if (count($exact) === 1) {
            return ['report' => report::get_record(['id' => $exact[0]['id']]), 'candidates' => [], 'reason' => ''];
        }
        if (count($reports) === 1) {
            return ['report' => report::get_record(['id' => $reports[0]['id']]), 'candidates' => [], 'reason' => ''];
        }

        return ['report' => null, 'candidates' => $reports, 'reason' => 'ambiguous'];
    }

    /**
     * Clarification options for a candidate list: id = report id, label = "name (source)".
     *
     * @param array[] $reports
     * @return array[]
     */
    public function options(array $reports): array {
        return array_map(static fn(array $r): array => [
            'id' => (int)$r['id'],
            'label' => $r['name'] . ' (' . $r['sourcename'] . ')',
        ], $reports);
    }

    /**
     * Compact metadata of one report.
     *
     * @param report $persistent
     * @param int $userid
     * @param bool|null $canedit Pass when already computed.
     * @return array
     */
    public function summarize(report $persistent, int $userid, ?bool $canedit = null): array {
        $source = ltrim((string)$persistent->get('source'), '\\');
        $sourcename = $source;
        $component = (string)(explode('\\', $source)[0] ?? '');
        if (class_exists($source) && method_exists($source, 'get_name')) {
            try {
                $sourcename = (string)$source::get_name();
            } catch (\Throwable $e) {
                $sourcename = $source;
            }
        }
        $id = (int)$persistent->get('id');
        return [
            'id' => $id,
            'name' => (string)$persistent->get('name'),
            'source' => $source,
            'sourcename' => $sourcename,
            'component' => $component,
            'contextid' => (int)$persistent->get('contextid'),
            'created_by_me' => ((int)$persistent->get('usercreated') === $userid),
            'timemodified' => (int)$persistent->get('timemodified'),
            'uniquerows' => (bool)$persistent->get('uniquerows'),
            'audiences' => audience_model::count_records(['reportid' => $id]),
            'schedules' => schedule_model::count_records(['reportid' => $id]),
            'canedit' => $canedit ?? permission::can_edit_report($persistent, $userid),
            'url' => (new moodle_url('/reportbuilder/view.php', ['id' => $id]))->out(false),
            'editurl' => (new moodle_url('/reportbuilder/edit.php', ['id' => $id]))->out(false),
        ];
    }

    /**
     * Split a query into words (letters and digits of any script); '' yields [].
     *
     * @param string $query
     * @return string[]
     */
    private function words(string $query): array {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY);
        return is_array($parts) ? array_values(array_unique($parts)) : [];
    }
}
