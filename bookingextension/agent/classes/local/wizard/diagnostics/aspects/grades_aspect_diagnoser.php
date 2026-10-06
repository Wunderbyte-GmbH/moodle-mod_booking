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

namespace bookingextension_agent\local\wizard\diagnostics\aspects;

use bookingextension_agent\local\wizard\diagnostics\diagnostic_result_builder;
use bookingextension_agent\local\wizard\diagnostics\diagnostic_link_builder;
use grade_grade;
use grade_item;

/**
 * Computes the grade-diagnosis checklist rows for an already-resolved course and target user.
 *
 * Deliberately a FACTS COLLECTOR, not a recalculation engine: re-implementing gradebook aggregation
 * correctly is a large, error-prone effort and a wrong explanation is worse than none. The class gathers
 * the grade structure and the user's stored grades with their flags (hidden/locked/overridden/excluded,
 * needsupdate, showgrades) so the model can explain ONLY from those facts, not recompute.
 *
 * Most sensitive data of the family: cross-user needs moodle/grade:viewall; self-diagnosis never reveals
 * a grade hidden from the user. Course/user resolution happens in the orchestrator; this class only
 * enforces the capability gate and builds the rows.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class grades_aspect_diagnoser {
    /** Hard cap on grade items reported (observation-size discipline). */
    private const MAX_ITEMS = 25;

    /**
     * Build the grade-diagnosis checklist rows for the given course/user.
     *
     * @param \stdClass $course
     * @param int $courseid
     * @param \context $coursecontext
     * @param int $targetuserid
     * @param int $actinguserid
     * @param array $input
     * @param diagnostic_link_builder $links
     * @return array{rows:array[],error:?array{message:string,error_class:string}}
     */
    public function diagnose(
        \stdClass $course,
        int $courseid,
        \context $coursecontext,
        int $targetuserid,
        int $actinguserid,
        array $input,
        diagnostic_link_builder $links
    ): array {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $isself = ($targetuserid === $actinguserid);

        // Gate (sensitive): cross-user needs grade:viewall; self needs to be able to see grades at all.
        $canviewall = has_capability('moodle/grade:viewall', $coursecontext, $actinguserid);
        if (!$isself && !$canviewall) {
            return [
                'rows' => [],
                'error' => [
                    'message' => get_string('nopermissions', 'error', 'moodle/grade:viewall'),
                    'error_class' => 'permission_denied',
                ],
            ];
        }
        if ($isself && !$canviewall && !has_capability('moodle/grade:view', $coursecontext, $actinguserid)) {
            return [
                'rows' => [],
                'error' => [
                    'message' => get_string('nopermissions', 'error', 'moodle/grade:view'),
                    'error_class' => 'permission_denied',
                ],
            ];
        }

        $rows = [];

        // Course-level gradebook facts.
        if ((int)($course->showgrades ?? 1) === 0) {
            $rows[] = diagnostic_result_builder::row(
                'warn',
                'Gradebook hidden from students',
                'The course setting "show gradebook to students" is off.',
                $links->grade_setup($courseid)
            );
        } else {
            $rows[] = diagnostic_result_builder::row('ok', 'Gradebook shown to students', '', $links->grade_setup($courseid));
        }

        // Grade items + the user's grade per item.
        $items = grade_item::fetch_all(['courseid' => $courseid]) ?: [];
        $items = $this->filter_items($items, trim((string)($input['itemquery'] ?? '')));
        if (empty($items)) {
            $rows[] = diagnostic_result_builder::row(
                'warn',
                'No matching grade item',
                'No grade item matched the request in this course.'
            );
        }

        $shown = 0;
        $needsupdate = false;
        foreach ($items as $item) {
            if ($shown >= self::MAX_ITEMS) {
                $rows[] = diagnostic_result_builder::row('warn', 'More grade items exist', 'Only the first ' . self::MAX_ITEMS
                    . ' are shown; name a specific item (itemquery) to narrow down.');
                break;
            }
            if (!empty($item->needsupdate)) {
                $needsupdate = true;
            }
            $rows[] = $this->item_row($item, (int)$targetuserid, $isself, $canviewall, $courseid, $links);
            $shown++;
        }

        if ($needsupdate) {
            $rows[] = diagnostic_result_builder::row(
                'warn',
                'Gradebook recalculation pending',
                'At least one item is flagged needsupdate — totals may be stale until recalculated.',
                $links->grade_setup($courseid)
            );
        }

        return ['rows' => $rows, 'error' => null];
    }

    /**
     * Filter grade items by an optional name query (fuzzy).
     *
     * @param grade_item[] $items
     * @param string $itemquery
     * @return grade_item[]
     */
    private function filter_items(array $items, string $itemquery): array {
        if ($itemquery === '') {
            return $items;
        }
        $needle = \core_text::strtolower($itemquery);
        $matches = [];
        foreach ($items as $item) {
            $name = \core_text::strtolower((string)$item->get_name());
            if ($name !== '' && str_contains($name, $needle)) {
                $matches[] = $item;
            }
        }
        return $matches;
    }

    /**
     * Build one checklist row for a grade item + the user's grade.
     *
     * @param grade_item $item
     * @param int $targetuserid
     * @param bool $isself
     * @param bool $canviewall
     * @param int $courseid
     * @param diagnostic_link_builder $links
     * @return array
     */
    private function item_row(
        grade_item $item,
        int $targetuserid,
        bool $isself,
        bool $canviewall,
        int $courseid,
        diagnostic_link_builder $links
    ): array {
        $name = (string)$item->get_name();
        $url = $links->user_grade_report($courseid, $targetuserid);

        $grade = grade_grade::fetch(['itemid' => $item->id, 'userid' => $targetuserid]);
        if (!$grade || ($grade->finalgrade === null && $grade->rawgrade === null)) {
            return diagnostic_result_builder::row(
                'warn',
                'Item "' . $name . '": no grade recorded',
                'No grade stored for this person yet.',
                $url
            );
        }

        // Respect hidden grades for a self-request without viewall.
        if ($grade->is_hidden() && !$canviewall) {
            return diagnostic_result_builder::row(
                'warn',
                'Item "' . $name . '": grade hidden from the user',
                'A grade exists but is hidden (not yet released or hidden by the teacher).',
                $url
            );
        }

        $flags = [];
        if ($grade->is_hidden()) {
            $flags[] = 'hidden';
        }
        if ($grade->is_locked()) {
            $flags[] = 'locked';
        }
        if ($grade->is_overridden()) {
            $flags[] = 'overridden';
        }
        if ($grade->is_excluded()) {
            $flags[] = 'excluded from aggregation';
        }

        $display = $this->format_grade($grade->finalgrade, $item);
        $finding = 'Grade: ' . $display;
        if (
            $grade->finalgrade !== null && $grade->rawgrade !== null
            && (float)$grade->finalgrade !== (float)$grade->rawgrade
        ) {
            $finding .= ' (raw ' . $this->format_grade($grade->rawgrade, $item) . ')';
        }
        if (!empty($flags)) {
            $finding .= ' [' . implode(', ', $flags) . ']';
        }

        $status = $grade->is_excluded() ? 'warn' : 'ok';
        return diagnostic_result_builder::row($status, 'Item "' . $name . '"', $finding, $url);
    }

    /**
     * Format a grade value for display (value/scale/text aware).
     *
     * @param float|null $value
     * @param grade_item $item
     * @return string
     */
    private function format_grade($value, grade_item $item): string {
        if ($value === null) {
            return '-';
        }
        try {
            return (string)grade_format_gradevalue((float)$value, $item, true);
        } catch (\Throwable $e) {
            return (string)round((float)$value, 2) . '/' . (string)round((float)$item->grademax, 2);
        }
    }
}
