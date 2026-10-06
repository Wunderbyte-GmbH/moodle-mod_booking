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

/**
 * Computes the access-diagnosis checklist rows for an already-resolved course and target user.
 *
 * Aggregates the access-relevant facts Moodle already computes — course visibility, enrolment status,
 * roles, per-activity uservisible/availableinfo for the TARGET user, and group-mode membership — into a
 * checklist. It never re-implements the availability engine: $cm->availableinfo carries the human-readable,
 * already-permission-respecting reason (so a "do not show" condition stays hidden). Course/user resolution
 * happens in the orchestrator; this class only enforces the cross-user gate and builds the rows.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class access_aspect_diagnoser {
    /**
     * Build the access-diagnosis checklist rows for the given course/user.
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
        $isself = ($targetuserid === $actinguserid);

        // Cross-user gate: inspecting another person's access/roles needs role:review (held by editing
        // teachers/managers, not by students — viewparticipants is too weak).
        if (!$isself && !has_capability('moodle/role:review', $coursecontext, $actinguserid)) {
            return [
                'rows' => [],
                'error' => [
                    'message' => get_string('nopermissions', 'error', 'moodle/role:review'),
                    'error_class' => 'permission_denied',
                ],
            ];
        }

        $rows = [];

        // Check 1: course visibility.
        if ((int)$course->visible === 1) {
            $rows[] = diagnostic_result_builder::row(
                'ok',
                'Course is visible',
                format_string($course->fullname),
                $links->course($courseid)
            );
        } else {
            $rows[] = diagnostic_result_builder::row(
                'fail',
                'Course is hidden',
                'The course is set to hidden; only users with "view hidden courses" can enter.',
                $links->course($courseid)
            );
        }

        // Check 2: enrolment.
        $activeenrolled = is_enrolled($coursecontext, $targetuserid, '', true);
        $anyenrolled = is_enrolled($coursecontext, $targetuserid, '', false);
        if ($activeenrolled) {
            $rows[] = diagnostic_result_builder::row(
                'ok',
                'Enrolled and active in the course',
                '',
                $links->if_capable($links->enrolled_users($courseid), 'moodle/course:enrolreview', $coursecontext, $actinguserid)
            );
        } else if ($anyenrolled) {
            $rows[] = diagnostic_result_builder::row(
                'fail',
                'Enrolled but not active',
                'The enrolment is suspended or expired — a common "was enrolled once" cause. '
                    . 'Use course.diagnose_enrolment for the enrolment-method details.',
                $links->if_capable($links->enrol_instances($courseid), 'moodle/course:enrolreview', $coursecontext, $actinguserid)
            );
        } else {
            $rows[] = diagnostic_result_builder::row(
                'fail',
                'Not enrolled in the course',
                'No active or inactive enrolment found for this person.',
                $links->if_capable($links->enrol_instances($courseid), 'moodle/course:enrolreview', $coursecontext, $actinguserid)
            );
        }

        // Check 3: role in the course.
        $roles = get_user_roles($coursecontext, $targetuserid, true);
        if (!empty($roles)) {
            $rolenames = array_values(array_unique(array_map(static fn($r): string => (string)$r->shortname, $roles)));
            $rows[] = diagnostic_result_builder::row('ok', 'Has a role in the course', implode(', ', $rolenames));
        } else {
            $rows[] = diagnostic_result_builder::row(
                'warn',
                'No role in the course',
                'The person has no role here; depending on setup this can limit what they can do.'
            );
        }

        // Check 4: activity visibility for the TARGET user (course-wide overview or a named activity).
        $modinfo = get_fast_modinfo($course, $targetuserid);
        $activityquery = trim((string)($input['activityquery'] ?? ''));
        if ($activityquery !== '') {
            $rows[] = $this->activity_visibility_row($modinfo, $activityquery, $links);
        } else {
            $rows[] = $this->activity_overview_row($modinfo);
        }

        // Check 5: group mode + membership.
        $rows[] = $this->group_row($course, $courseid, $targetuserid, $links);

        return ['rows' => $rows, 'error' => null];
    }

    /**
     * Build the row for a specific named activity's visibility.
     *
     * @param \course_modinfo $modinfo
     * @param string $activityquery
     * @param diagnostic_link_builder $links
     * @return array
     */
    private function activity_visibility_row(
        \course_modinfo $modinfo,
        string $activityquery,
        diagnostic_link_builder $links
    ): array {
        $needle = \core_text::strtolower($activityquery);
        $matches = [];
        foreach ($modinfo->get_cms() as $cm) {
            if (str_contains(\core_text::strtolower($cm->name), $needle)) {
                $matches[] = $cm;
            }
        }
        if (empty($matches)) {
            return diagnostic_result_builder::row(
                'warn',
                'Activity "' . $activityquery . '" not found',
                'No activity with that name in this course (for this user).'
            );
        }
        if (count($matches) > 1) {
            $names = array_map(static fn($cm): string => $cm->name, array_slice($matches, 0, 5));
            return diagnostic_result_builder::row(
                'warn',
                'Several activities match "' . $activityquery . '"',
                'Please be more specific: ' . implode('; ', $names)
            );
        }
        $cm = $matches[0];
        if ($cm->uservisible) {
            return diagnostic_result_builder::row(
                'ok',
                'Activity "' . $cm->name . '" is visible to the user',
                '',
                $links->activity($cm->modname, (int)$cm->id)
            );
        }
        $reason = trim(strip_tags((string)$cm->availableinfo));
        return diagnostic_result_builder::row(
            'fail',
            'Activity "' . $cm->name . '" is NOT visible to the user',
            $reason !== '' ? $reason : 'Hidden or restricted (no visible reason is shown for this user).',
            $links->activity($cm->modname, (int)$cm->id)
        );
    }

    /**
     * Build the course-wide activity-visibility overview row.
     *
     * @param \course_modinfo $modinfo
     * @return array
     */
    private function activity_overview_row(\course_modinfo $modinfo): array {
        $total = 0;
        $hidden = 0;
        foreach ($modinfo->get_cms() as $cm) {
            $total++;
            if (!$cm->uservisible) {
                $hidden++;
            }
        }
        if ($total === 0) {
            return diagnostic_result_builder::row('warn', 'No activities in the course', '');
        }
        if ($hidden === 0) {
            return diagnostic_result_builder::row(
                'ok',
                'All activities are visible to the user',
                $total . ' activit(y/ies) checked'
            );
        }
        return diagnostic_result_builder::row(
            'warn',
            $hidden . ' of ' . $total . ' activities not visible to the user',
            'Name the specific activity (activityquery) to see the exact reason.'
        );
    }

    /**
     * Build the group-mode + membership row.
     *
     * @param \stdClass $course
     * @param int $courseid
     * @param int $targetuserid
     * @param diagnostic_link_builder $links
     * @return array
     */
    private function group_row(\stdClass $course, int $courseid, int $targetuserid, diagnostic_link_builder $links): array {
        $groupmode = (int)groups_get_course_groupmode($course);
        if ($groupmode === NOGROUPS) {
            return diagnostic_result_builder::row(
                'ok',
                'No group mode enforced',
                'Group membership does not restrict access here.'
            );
        }
        $usergroups = groups_get_user_groups($courseid, $targetuserid);
        $ingroup = !empty($usergroups[0]);
        $modelabel = $groupmode === SEPARATEGROUPS ? 'separate groups' : 'visible groups';
        if ($ingroup) {
            return diagnostic_result_builder::row('ok', 'Group mode: ' . $modelabel, 'The user is a member of at least one group.');
        }
        $status = $groupmode === SEPARATEGROUPS ? 'warn' : 'ok';
        return diagnostic_result_builder::row(
            $status,
            'Group mode: ' . $modelabel,
            'The user is in no group' . ($groupmode === SEPARATEGROUPS
            ? ' — with separate groups this can hide group-scoped content/people.' : '.')
        );
    }
}
