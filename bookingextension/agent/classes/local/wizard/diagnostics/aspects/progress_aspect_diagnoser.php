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

use bookingextension_agent\local\wizard\diagnostics\diagnostic_link_builder;
use bookingextension_agent\local\wizard\diagnostics\diagnostic_result_builder;
use completion_info;
use context;
use core_completion\cm_completion_details;

/**
 * Progress/completion aspect diagnoser: builds the per-activity and course-completion checklist rows
 * behind a "why has this user not completed the course / these activities?" question. A FACTS
 * COLLECTOR (it reports stored completion state and the unmet completion RULES), never a completion
 * engine — it does not re-evaluate completion itself.
 *
 * Course/user resolution is done by the orchestrator; this class only applies the cross-user
 * capability gate and assembles the checklist rows.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class progress_aspect_diagnoser {
    /** Hard cap on activities reported (observation-size discipline). */
    private const MAX_ITEMS = 30;

    /**
     * Build the progress/completion checklist rows for the resolved target user in the resolved course.
     *
     * @param \stdClass $course           The resolved course record.
     * @param int $courseid               The resolved course id.
     * @param \context $coursecontext     The course context.
     * @param int $targetuserid           The resolved target user id.
     * @param int $actinguserid           The acting (current) user id.
     * @param array $input                Skill input (uses $input['activityquery']).
     * @param diagnostic_link_builder $links Link builder for action URLs.
     * @return array{rows:array[],error:array{message:string,error_class:string}|null}
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
        require_once($CFG->libdir . '/completionlib.php');

        $isself = ($targetuserid === $actinguserid);

        // Gate: viewing another user's progress needs the activity-completion report capability; a
        // self-request only reports what the user already sees in their own progress.
        if (!$isself && !has_capability('report/progress:view', $coursecontext, $actinguserid)) {
            return [
                'rows' => [],
                'error' => [
                    'message' => get_string('nopermissions', 'error', 'report/progress:view'),
                    'error_class' => 'permission_denied',
                ],
            ];
        }

        $rows = [];

        // Completion enabled for the course?
        $completion = new completion_info($course);
        if (!$completion->is_enabled()) {
            $rows[] = diagnostic_result_builder::row(
                'warn',
                'Completion tracking disabled',
                'This course does not track completion, so there is no per-activity or course completion to report.',
                $links->completion_settings($courseid)
            );
            return ['rows' => $rows, 'error' => null];
        }

        // The target's role may not be tracked for completion (e.g. teachers/managers).
        if (!$completion->is_tracked_user($targetuserid)) {
            $rows[] = diagnostic_result_builder::row(
                'warn',
                'Completion not tracked for this user',
                'Completion is only tracked for roles configured as tracked (usually students); the figures below '
                    . 'may therefore be empty for this person.',
                $links->course_completion_report($courseid)
            );
        }

        // Per-activity completion (visibility = Moodle engine, computed for the target user).
        $activityquery = \core_text::strtolower(trim((string)($input['activityquery'] ?? '')));
        $modinfo = get_fast_modinfo($course, $targetuserid);
        $tracked = 0;
        $done = 0;
        $shown = 0;
        $trackedtotal = 0;   // Completion-tracked activities in the course, BEFORE the activityquery filter.
        $matched = 0;        // Completion-tracked activities matching the activityquery (or all, if none given).
        $trackednames = [];  // Names of completion-tracked activities, for a "no match" hint.
        foreach ($modinfo->get_cms() as $cm) {
            if ((int)$cm->completion === COMPLETION_TRACKING_NONE) {
                continue; // No completion tracking on this activity.
            }
            $name = (string)$cm->get_formatted_name();
            $trackedtotal++;
            if (count($trackednames) < self::MAX_ITEMS) {
                $trackednames[] = $name;
            }
            // A (possibly spurious) activityquery must never turn a real course progress into "no tracking":
            // it only narrows which rows are shown. The trackedtotal/no-match branch below reports the truth.
            if ($activityquery !== '' && !str_contains(\core_text::strtolower($name), $activityquery)) {
                continue;
            }
            $matched++;

            // An activity the target cannot reach because of an access restriction is a completion
            // blocker too — surface it and point at the access diagnosis (no recompute of restrictions).
            if (!$cm->uservisible) {
                if ($cm->visible && !empty($cm->availableinfo)) {
                    $rows[] = diagnostic_result_builder::row(
                        'warn',
                        'Activity "' . $name . '": blocked by an access restriction',
                        'The user cannot access this activity yet (access conditions not met), so it cannot be '
                            . 'completed. Use course.diagnose_access for the restriction details.',
                        $links->activity($cm->modname, $cm->id)
                    );
                }
                continue;
            }

            $tracked++;
            if ($shown >= self::MAX_ITEMS) {
                continue; // Keep counting totals, but stop emitting rows (observation discipline).
            }
            $shown++;
            [$row, $iscomplete] = $this->activity_row($completion, $cm, $targetuserid, $name, $links);
            if ($iscomplete) {
                $done++;
            }
            $rows[] = $row;
        }

        if ($tracked > self::MAX_ITEMS) {
            $rows[] = diagnostic_result_builder::row(
                'warn',
                'More activities exist',
                'Only the first ' . self::MAX_ITEMS . ' completion-tracked activities are shown; name a specific '
                    . 'activity (activityquery) to narrow down.'
            );
        }
        if ($trackedtotal === 0) {
            $rows[] = diagnostic_result_builder::row(
                'warn',
                'No completion-tracked activities',
                'No activity in this course has completion tracking enabled.'
            );
        } else if ($activityquery !== '' && $matched === 0) {
            // The course DOES track completion, the named/assumed activity just did not match — never
            // report this as "no progress measurable" (that was the thread-559 false negative).
            $rows[] = diagnostic_result_builder::row(
                'warn',
                'No completion-tracked activity matches "' . $activityquery . '"',
                'This course HAS ' . $trackedtotal . ' completion-tracked activit'
                    . ($trackedtotal === 1 ? 'y' : 'ies') . ': ' . implode(', ', $trackednames)
                    . '. For overall progress omit the activity filter; otherwise name one of these activities.'
            );
        }

        // Course-completion criteria + overall course completion.
        $this->append_course_completion_rows($completion, $targetuserid, $courseid, $links, $rows);

        return ['rows' => $rows, 'error' => null];
    }

    /**
     * Build one checklist row for an activity's completion state (+ the unmet rules behind it).
     *
     * @param completion_info $completion
     * @param \cm_info $cm
     * @param int $targetuserid
     * @param string $name
     * @param diagnostic_link_builder $links
     * @return array{0:array,1:bool}  [row, iscomplete]
     */
    private function activity_row(
        completion_info $completion,
        \cm_info $cm,
        int $targetuserid,
        string $name,
        diagnostic_link_builder $links
    ): array {
        $url = $links->activity($cm->modname, $cm->id);
        $details = new cm_completion_details($completion, $cm, $targetuserid);
        $state = $details->get_overall_completion();

        $iscomplete = in_array($state, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS], true);
        if ($iscomplete) {
            $finding = $state === COMPLETION_COMPLETE_PASS ? 'Completed (passing grade achieved).' : 'Completed.';
            return [diagnostic_result_builder::row('ok', 'Activity "' . $name . '"', $finding, $url), true];
        }

        if ($state === COMPLETION_COMPLETE_FAIL) {
            $finding = 'Marked complete but the required passing grade was NOT achieved — '
                . 'use course.diagnose_grades for the grade details.';
            return [diagnostic_result_builder::row('fail', 'Activity "' . $name . '"', $finding, $url), false];
        }

        // Incomplete: explain WHY from the configured rules.
        if ($details->is_manual()) {
            $finding = 'Not completed — this activity is marked complete manually by the user (self-marking).';
            return [diagnostic_result_builder::row('fail', 'Activity "' . $name . '"', $finding, $url), false];
        }

        $unmet = [];
        foreach ($details->get_details() as $detail) {
            $rstatus = (int)($detail->status ?? COMPLETION_INCOMPLETE);
            if (!in_array($rstatus, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS], true)) {
                $unmet[] = trim((string)($detail->description ?? ''));
            }
        }
        $unmet = array_values(array_filter($unmet));
        $finding = empty($unmet)
            ? 'Not completed.'
            : 'Not completed — unmet: ' . implode('; ', $unmet) . '.';

        return [diagnostic_result_builder::row('fail', 'Activity "' . $name . '"', $finding, $url), false];
    }

    /**
     * Append the course-completion criteria rows and the overall course-completion row.
     *
     * @param completion_info $completion
     * @param int $targetuserid
     * @param int $courseid
     * @param diagnostic_link_builder $links
     * @param array[] $rows  (by reference)
     * @return void
     */
    private function append_course_completion_rows(
        completion_info $completion,
        int $targetuserid,
        int $courseid,
        diagnostic_link_builder $links,
        array &$rows
    ): void {
        $criteria = $completion->get_criteria();
        if (empty($criteria)) {
            return;
        }

        $url = $links->course_completion_report($courseid);
        $completions = $completion->get_completions($targetuserid);
        foreach ($completions as $cc) {
            try {
                $title = (string)$cc->get_criteria()->get_title();
                $met = (bool)$cc->is_complete();
            } catch (\Throwable $e) {
                continue;
            }
            $rows[] = diagnostic_result_builder::row(
                $met ? 'ok' : 'fail',
                'Course criterion: ' . $title,
                $met ? 'Met.' : 'Not met yet.',
                $url
            );
        }

        $coursecomplete = $completion->is_course_complete($targetuserid);
        $rows[] = diagnostic_result_builder::row(
            $coursecomplete ? 'ok' : 'fail',
            'Overall course completion',
            $coursecomplete ? 'The course is marked complete for this user.' : 'The course is not yet complete.',
            $url
        );
    }
}
