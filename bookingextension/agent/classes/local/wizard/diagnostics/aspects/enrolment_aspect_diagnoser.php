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
 * Enrolment aspect diagnoser: builds the enrolment checklist rows for a course (and optional person).
 *
 * Inspects the course's enrolment methods (incl. disabled ones) — self, cohort, manual in detail, others by
 * name — their plugin/instance state, the relevant constraints (window, key, max participants, cohort
 * restriction/membership), and, when a person is named, that person's existing enrolment records
 * (active/suspended/expired). Site admins additionally see the health of enrolment-related scheduled tasks.
 *
 * Enrolment configuration is privileged knowledge, gated on moodle/course:enrolreview.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class enrolment_aspect_diagnoser {
    /**
     * Diagnose enrolment for a (resolved) course and optional target user, returning checklist rows.
     *
     * @param \stdClass $course
     * @param int $courseid
     * @param \context $coursecontext
     * @param int $targetuserid 0 = no specific person / overview.
     * @param int $actinguserid
     * @param array $input
     * @param diagnostic_link_builder $links
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
        require_once($CFG->libdir . '/enrollib.php');

        // Gate: enrolment configuration is privileged (teacher/manager).
        if (!has_capability('moodle/course:enrolreview', $coursecontext, $actinguserid)) {
            return [
                'rows' => [],
                'error' => [
                    'message' => get_string('nopermissions', 'error', 'moodle/course:enrolreview'),
                    'error_class' => 'permission_denied',
                ],
            ];
        }

        $rows = [];

        // 4) Enrolment methods.
        $instances = enrol_get_instances($courseid, false);
        if (empty($instances)) {
            $rows[] = diagnostic_result_builder::row(
                'fail',
                'No enrolment methods on the course',
                'The course has no enrolment method configured at all.',
                $links->enrol_instances($courseid)
            );
        }
        foreach ($instances as $instance) {
            $rows[] = $this->analyse_instance($instance, $coursecontext, $targetuserid, $links, $courseid);
        }

        // 5) The person's existing enrolment records (when a person is named).
        if ($targetuserid > 0) {
            $rows[] = $this->existing_enrolment_row($courseid, $targetuserid, $coursecontext, $links);
        }

        // 6) Scheduled-task health for enrolment plugins (site admins only).
        if (is_siteadmin($actinguserid)) {
            foreach ($this->enrolment_task_rows($links, $actinguserid) as $taskrow) {
                $rows[] = $taskrow;
            }
        }

        return ['rows' => $rows, 'error' => null];
    }

    /**
     * Analyse a single enrolment instance into one checklist row.
     *
     * @param \stdClass $instance
     * @param \context $coursecontext
     * @param int $targetuserid 0 = no specific person.
     * @param diagnostic_link_builder $links
     * @param int $courseid
     * @return array
     */
    private function analyse_instance(
        \stdClass $instance,
        \context $coursecontext,
        int $targetuserid,
        diagnostic_link_builder $links,
        int $courseid
    ): array {
        $method = (string)$instance->enrol;
        $label = get_string('pluginname', 'enrol_' . $method);
        $url = $links->enrol_instances($courseid);

        // Disabled instance or site-disabled plugin are themselves the blocker.
        if ((int)$instance->status !== ENROL_INSTANCE_ENABLED) {
            return diagnostic_result_builder::row(
                'fail',
                'Method "' . $label . '" is disabled',
                'This enrolment method instance is turned off.',
                $url
            );
        }
        if (!enrol_is_enabled($method)) {
            return diagnostic_result_builder::row(
                'fail',
                'Method "' . $label . '" plugin disabled site-wide',
                'The ' . $label . ' enrolment plugin is disabled for the whole site.',
                $url
            );
        }

        if ($method === 'self') {
            return $this->analyse_self($instance, $label, $targetuserid, $url);
        }
        if ($method === 'cohort') {
            return $this->analyse_cohort($instance, $label, $targetuserid, $url, $links);
        }
        if ($method === 'manual') {
            return diagnostic_result_builder::row(
                'ok',
                'Method "' . $label . '" is active',
                'Manual enrolment is available; teachers/managers add users by hand.',
                $url
            );
        }
        // Other methods: name + active only (v1).
        return diagnostic_result_builder::row(
            'ok',
            'Method "' . $label . '" is active',
            'Not inspected in detail in this version.',
            $url
        );
    }

    /**
     * Analyse a self-enrolment instance.
     *
     * @param \stdClass $instance
     * @param string $label
     * @param int $targetuserid
     * @param \moodle_url $url
     * @return array
     */
    private function analyse_self(\stdClass $instance, string $label, int $targetuserid, \moodle_url $url): array {
        global $DB;
        $notes = [];
        $status = 'ok';
        $now = time();

        if ((int)$instance->customint6 === 0) {
            $status = 'fail';
            $notes[] = 'new self-enrolments are not allowed';
        }
        if ((int)$instance->enrolstartdate > 0 && (int)$instance->enrolstartdate > $now) {
            $status = 'fail';
            $notes[] = 'enrolment window has not started yet';
        }
        if ((int)$instance->enrolenddate > 0 && (int)$instance->enrolenddate < $now) {
            $status = 'fail';
            $notes[] = 'enrolment window has ended';
        }
        if ((int)$instance->customint3 > 0) {
            $active = (int)$DB->count_records('user_enrolments', ['enrolid' => $instance->id, 'status' => 0]);
            if ($active >= (int)$instance->customint3) {
                $status = 'fail';
                $notes[] = 'max participants reached (' . $active . '/' . (int)$instance->customint3 . ')';
            }
        }
        if (!empty($instance->password)) {
            $notes[] = 'an enrolment key is required';
        }
        if ((int)$instance->customint5 > 0) {
            $cohort = $DB->get_record('cohort', ['id' => (int)$instance->customint5], 'id, name, contextid', IGNORE_MISSING);
            $cohortname = $cohort
                ? format_string($cohort->name, true, ['context' => \context::instance_by_id($cohort->contextid)])
                : ('#' . (int)$instance->customint5);
            if ($targetuserid > 0 && !cohort_is_member((int)$instance->customint5, $targetuserid)) {
                $status = 'fail';
                $notes[] = 'restricted to members of cohort "' . $cohortname . '" — the person is NOT a member';
            } else {
                $notes[] = 'restricted to members of cohort "' . $cohortname . '"';
            }
        }

        $finding = empty($notes) ? 'Self enrolment is open.' : ucfirst(implode('; ', $notes)) . '.';
        return diagnostic_result_builder::row($status, 'Self enrolment "' . $label . '"', $finding, $url);
    }

    /**
     * Analyse a cohort-sync instance.
     *
     * @param \stdClass $instance
     * @param string $label
     * @param int $targetuserid
     * @param \moodle_url $url
     * @param diagnostic_link_builder $links
     * @return array
     */
    private function analyse_cohort(
        \stdClass $instance,
        string $label,
        int $targetuserid,
        \moodle_url $url,
        diagnostic_link_builder $links
    ): array {
        global $DB;
        $cohortid = (int)$instance->customint1;
        $cohort = $cohortid > 0
            ? $DB->get_record('cohort', ['id' => $cohortid], 'id, name, contextid', IGNORE_MISSING)
            : null;
        $cohortname = $cohort
            ? format_string($cohort->name, true, ['context' => \context::instance_by_id($cohort->contextid)])
            : ('#' . $cohortid);

        if ($targetuserid > 0) {
            if ($cohortid > 0 && cohort_is_member($cohortid, $targetuserid)) {
                return diagnostic_result_builder::row(
                    'ok',
                    'Cohort sync via "' . $cohortname . '"',
                    'The person IS a member of this cohort, so cohort sync should enrol them.',
                    $url
                );
            }
            return diagnostic_result_builder::row(
                'fail',
                'Cohort sync via "' . $cohortname . '"',
                'The person is NOT a member of this cohort — cohort sync will not enrol them.',
                $url
            );
        }
        return diagnostic_result_builder::row(
            'ok',
            'Cohort sync via "' . $cohortname . '"',
            'Members of this cohort are auto-enrolled.',
            $url
        );
    }

    /**
     * Build the row describing a person's existing enrolment records in the course.
     *
     * @param int $courseid
     * @param int $targetuserid
     * @param \context $coursecontext
     * @param diagnostic_link_builder $links
     * @return array
     */
    private function existing_enrolment_row(
        int $courseid,
        int $targetuserid,
        \context $coursecontext,
        diagnostic_link_builder $links
    ): array {
        global $DB;
        $now = time();
        $sql = "SELECT ue.id, ue.status, ue.timestart, ue.timeend, e.enrol
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE e.courseid = :courseid AND ue.userid = :userid";
        $records = $DB->get_records_sql($sql, ['courseid' => $courseid, 'userid' => $targetuserid]);

        if (empty($records)) {
            return diagnostic_result_builder::row(
                'fail',
                'No enrolment record for this person',
                'The person has no enrolment in this course (active, suspended or expired).',
                $links->user_profile($targetuserid, $courseid)
            );
        }

        $hasactive = false;
        $details = [];
        foreach ($records as $ue) {
            $method = (string)$ue->enrol;
            if ((int)$ue->status === 1) {
                $details[] = $method . ': suspended';
            } else if ((int)$ue->timeend > 0 && (int)$ue->timeend < $now) {
                $details[] = $method . ': expired';
            } else if ((int)$ue->timestart > $now) {
                $details[] = $method . ': not started yet';
            } else {
                $hasactive = true;
                $details[] = $method . ': active';
            }
        }

        $status = $hasactive ? 'ok' : 'fail';
        $check = $hasactive ? 'Person is currently enrolled (active)' : 'Person has only inactive enrolments';
        return diagnostic_result_builder::row(
            $status,
            $check,
            implode('; ', $details),
            $links->user_profile($targetuserid, $courseid)
        );
    }

    /**
     * Build rows for the health of enrolment-related scheduled tasks (admin-only).
     *
     * @param diagnostic_link_builder $links
     * @param int $userid
     * @return array[]
     */
    private function enrolment_task_rows(diagnostic_link_builder $links, int $userid): array {
        global $DB;
        $rows = [];
        $tasks = $DB->get_records('task_scheduled');
        $unhealthy = [];
        foreach ($tasks as $task) {
            if (strpos((string)$task->classname, 'enrol_') === false) {
                continue;
            }
            if ((int)$task->disabled === 1) {
                $unhealthy[] = $task->classname . ' (disabled)';
            } else if ((int)$task->faildelay > 0) {
                $unhealthy[] = $task->classname . ' (failing, faildelay=' . (int)$task->faildelay . ')';
            }
        }
        $tasklink = $links->if_admin($links->scheduled_tasks(), $userid);
        if (empty($unhealthy)) {
            $rows[] = diagnostic_result_builder::row(
                'ok',
                'Enrolment scheduled tasks healthy',
                'No disabled or failing enrolment tasks (e.g. cohort sync).',
                $tasklink
            );
        } else {
            $rows[] = diagnostic_result_builder::row(
                'fail',
                'Enrolment scheduled task problem',
                implode('; ', $unhealthy),
                $tasklink
            );
        }
        return $rows;
    }
}
