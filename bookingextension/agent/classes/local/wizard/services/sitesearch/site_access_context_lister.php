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

/**
 * Engine-free candidate-context prefilter for site-content retrieval.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\sitesearch;

use bookingextension_agent\local\wizard\services\retrieval\retrieval_filter;

/**
 * Builds the set of contexts a user may plausibly see, WITHOUT the search-engine-gated
 * `manager::get_areas_user_accesses()`.
 *
 * This is a **recall/performance prefilter only, never an authorisation decision** — the search
 * service still runs the area's authoritative `check_access()` on every candidate. Its only error
 * direction is *missing* hits (safe), never a leak. Two context levels are contributed (blueprint
 * §7-B extension, §11.27):
 *
 *  - Module contexts: the user's own `get_fast_modinfo(...uservisible)` over their enrolled courses,
 *    limited to the enabled areas' module names.
 *  - Course contexts (only when a course-level area is enabled): the user's enrolled courses, the
 *    VISIBLE courses they are not enrolled in but may see info for
 *    (`core_course_category::can_view_course_info()` — hidden courses are deliberately skipped, the
 *    safe subset), and the front-page course (SITEID).
 *
 * Areas on any other context level contribute NOTHING here — documented fail-closed: their hits are
 * never prefiltered in for regular users (no leak; a site admin still reaches them through the
 * global filter + `check_access`). A user with nothing visible yields an EMPTY array (fail-closed:
 * {@see retrieval_filter} then narrows to zero rows), never null (which would mean global). A site
 * admin gets a null (global) filter — they can see everything and check_access still runs per hit.
 */
class site_access_context_lister {
    /**
     * The retrieval filter narrowing site-content candidates to a user's visible contexts.
     *
     * @param int $userid
     * @param array $descriptor Registry access descriptor
     *              ({@see site_content_area_registry::enabled_access_descriptor()}):
     *              'modnames' (string[]) + 'includecourselevel' (bool).
     * @param int|null $courseid Optional course restriction: only that course's course/module
     *              contexts are collected. Applies to site admins as well — with a course
     *              restriction an admin gets the course-scoped filter instead of the global one,
     *              otherwise course scoping would silently be a no-op for them.
     * @return retrieval_filter Global (null contexts) for an unrestricted site admin; otherwise the
     *              visible context ids.
     */
    public function allowed_context_filter(int $userid, array $descriptor, ?int $courseid = null): retrieval_filter {
        global $DB;

        $isadmin = is_siteadmin($userid);
        if ($isadmin && $courseid === null) {
            return new retrieval_filter(null);
        }

        $modnames = array_values((array)($descriptor['modnames'] ?? []));
        $includecourselevel = !empty($descriptor['includecourselevel']);
        if (empty($modnames) && !$includecourselevel) {
            return new retrieval_filter([]);
        }

        if ($isadmin) {
            // Course-restricted admin: every course/module context of that one course (an admin
            // sees everything, so no per-cm visibility pruning is needed — get_fast_modinfo's
            // uservisible is true for them anyway).
            return new retrieval_filter($this->course_contextids($courseid, $userid, $modnames, $includecourselevel));
        }

        $contextids = [];
        $enrolled = enrol_get_users_courses($userid, true, ['id']);
        foreach ($enrolled as $course) {
            if ($courseid !== null && (int)$course->id !== $courseid) {
                continue;
            }
            if ($includecourselevel) {
                $contextids[] = (int)\context_course::instance((int)$course->id)->id;
            }
            if (empty($modnames)) {
                continue;
            }
            $modinfo = get_fast_modinfo($course->id, $userid);
            foreach ($modnames as $modname) {
                foreach ($modinfo->get_instances_of($modname) as $cm) {
                    if ($cm->uservisible) {
                        $contextids[] = (int)$cm->context->id;
                    }
                }
            }
        }

        if ($includecourselevel && $courseid !== null) {
            // Course-restricted: only the §7-B branches that concern THIS course.
            if ($courseid === SITEID) {
                $contextids[] = (int)\context_course::instance(SITEID)->id;
            } else if (!isset($enrolled[$courseid])) {
                $course = $DB->get_record('course', ['id' => $courseid], 'id, category, visible', IGNORE_MISSING);
                if (
                    $course && !empty($course->visible)
                        && \core_course_category::can_view_course_info($course, $userid)
                ) {
                    $contextids[] = (int)\context_course::instance($courseid)->id;
                }
            }
        } else if ($includecourselevel) {
            // Blueprint §7-B: visible courses the user is NOT enrolled in but may see info for. Iterated
            // with minimal fields (bounded by the site's course count, one capability check per
            // course — the same work Core's get_courses() does). Hidden courses are skipped
            // outright (safe subset: even a viewhiddencourses holder just misses a prefilter hit).
            $recordset = $DB->get_recordset('course', null, '', 'id, category, visible');
            try {
                foreach ($recordset as $course) {
                    if ((int)$course->id === SITEID || isset($enrolled[$course->id])) {
                        continue;
                    }
                    if (empty($course->visible)) {
                        continue;
                    }
                    if (\core_course_category::can_view_course_info($course, $userid)) {
                        $contextids[] = (int)\context_course::instance((int)$course->id)->id;
                    }
                }
            } finally {
                $recordset->close();
            }

            // Blueprint §7-B: front-page content lives in the SITEID course context.
            $contextids[] = (int)\context_course::instance(SITEID)->id;
        }

        // Empty array = fail-closed (no visible contexts → zero rows), NOT null (which is global).
        return new retrieval_filter(array_values(array_unique($contextids)));
    }

    /**
     * All course/module context ids of one course for the enabled areas (admin course restriction).
     *
     * @param int $courseid
     * @param int $userid
     * @param string[] $modnames Enabled module names.
     * @param bool $includecourselevel Whether a course-level area is enabled.
     * @return int[] Context ids; empty (fail-closed) when the course does not exist.
     */
    private function course_contextids(int $courseid, int $userid, array $modnames, bool $includecourselevel): array {
        global $DB;

        if ($courseid <= 0 || !$DB->record_exists('course', ['id' => $courseid])) {
            return [];
        }

        $contextids = [];
        if ($includecourselevel) {
            $contextids[] = (int)\context_course::instance($courseid)->id;
        }
        if (!empty($modnames)) {
            $modinfo = get_fast_modinfo($courseid, $userid);
            foreach ($modnames as $modname) {
                foreach ($modinfo->get_instances_of($modname) as $cm) {
                    if ($cm->uservisible) {
                        $contextids[] = (int)$cm->context->id;
                    }
                }
            }
        }
        return array_values(array_unique($contextids));
    }
}
