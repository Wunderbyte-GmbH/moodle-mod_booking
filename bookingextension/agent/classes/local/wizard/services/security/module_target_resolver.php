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

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\security;

use bookingextension_agent\local\wizard\services\target_query_normalizer;
use bookingextension_agent\local\wizard\dto\agent_context;
use bookingextension_agent\local\wizard\dto\context_target_resolution;
use bookingextension_agent\local\wizard\dto\target_selector;
use context;
use context_module;

/**
 * Resolves a CONTEXT_MODULE {@see target_selector} to a concrete activity instance, generically.
 *
 * Keyed purely by the target's modname (e.g. 'booking', 'quiz') — activity modules are a Moodle
 * core concept, so this carries no domain dependency and serves every mod_ family that opts into
 * module targeting via the {@see \bookingextension_agent\local\wizard\module_targeted_skill} trait.
 * No family ships its own resolution code.
 *
 * Resolution policy (the "scope cascade"):
 *  - explicit cmid           → that instance (when visible to the user), else not_found;
 *  - already inside a matching module and nothing named → that ambient instance;
 *  - otherwise, narrowing by an optional activity-name query:
 *      1) the AMBIENT COURSE first — exactly one visible match → resolved; several → ambiguous
 *         (the candidate list is the course's instances), so the user is asked which one;
 *      2) only when the course yields NONE, fall back SITE-WIDE — one visible match → resolved,
 *         several → ambiguous (site list), none → not_found.
 *
 * This realises "one instance platform-wide → use it wherever I am; one in this course → use it;
 * several in this course → ask". Resolving a context is NOT a permission grant — Gate 2 is enforced
 * by the caller at the resolved operating context.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class module_target_resolver {
    /**
     * Resolve a module target selector to a concrete operating context.
     *
     * @param target_selector    $target  A CONTEXT_MODULE selector carrying a modname.
     * @param agent_context|null $ambient The chat/thread ambient context (drives course-first scoping).
     * @param int                $userid  Acting user id (visibility-aware resolution).
     * @return context_target_resolution
     */
    public function resolve(target_selector $target, ?agent_context $ambient, int $userid): context_target_resolution {
        $modname = (string)$target->modname();
        if ($modname === '' || !$this->is_known_module($modname)) {
            // Without a (valid) module name there is nothing generic to resolve.
            return context_target_resolution::unsupported();
        }

        // An explicit course-module id wins outright.
        $cmid = $target->id();
        if ($cmid !== null) {
            return $this->resolve_explicit_cmid($cmid, $modname, $userid);
        }

        $query = trim((string)($target->query() ?? ''));

        // Already inside the right kind of activity and nothing else named → use it.
        if ($query === '' && $ambient !== null && $ambient->is_module($modname)) {
            return context_target_resolution::resolved($ambient->moodle_context());
        }

        // Scope cascade: the ambient course first, then site-wide as a fallback.
        // The site course (front page, id 1) is a valid scope — it can carry activities — so include it.
        $courseid = $ambient !== null ? $ambient->courseid() : null;
        if ($courseid !== null && $courseid >= 1) {
            $incourse = $this->filter_by_name($this->collect_instances($modname, $userid, (int)$courseid), $query);
            $decided = $this->decide($incourse);
            if ($decided !== null) {
                return $decided;
            }
        }

        $sitewide = $this->filter_by_name($this->collect_instances($modname, $userid, null), $query);
        $decided = $this->decide($sitewide);

        return $decided ?? context_target_resolution::not_found();
    }

    /**
     * Turn a candidate set into a resolution: 0 → null (let the caller try the next scope),
     * 1 → resolved, many → ambiguous (carrying the candidate list for the clarification).
     *
     * @param array[] $candidates
     * @return context_target_resolution|null
     */
    private function decide(array $candidates): ?context_target_resolution {
        $count = count($candidates);
        if ($count === 0) {
            return null;
        }
        if ($count === 1) {
            $context = context_module::instance($candidates[0]['cmid'], IGNORE_MISSING);
            return $context ? context_target_resolution::resolved($context) : context_target_resolution::not_found();
        }

        $payload = [];
        foreach ($candidates as $candidate) {
            $payload[] = [
                'id'              => $candidate['cmid'],
                'name'            => $candidate['name'],
                'courseid'        => $candidate['courseid'],
                'coursename'      => $candidate['coursename'],
                'courseshortname' => $candidate['courseshortname'],
                'url'             => $candidate['url'],
            ];
        }
        return context_target_resolution::ambiguous($payload);
    }

    /**
     * Resolve an explicitly named cmid, honouring the user's visibility.
     *
     * @param int    $cmid
     * @param string $modname
     * @param int    $userid
     * @return context_target_resolution
     */
    private function resolve_explicit_cmid(int $cmid, string $modname, int $userid): context_target_resolution {
        $cm = get_coursemodule_from_id($modname, $cmid, 0, false, IGNORE_MISSING);
        if (!$cm || empty($cm->id)) {
            // Run-23 finding (CBI-1): the runtime block hands the model "context_id" and
            // "module: cmid" one under the other, and it filled this field with the context id.
            // The turn then ended as "no matching activity" about the activity the session was
            // already in. A real cmid always wins - the two number spaces can collide - so this
            // runs only once the value has proven to be no course module of this type.
            $cmid = $this->cmid_behind_module_context($cmid, $modname);
            $cm = $cmid > 0 ? get_coursemodule_from_id($modname, $cmid, 0, false, IGNORE_MISSING) : null;
        }
        if (!$cm || empty($cm->id)) {
            return context_target_resolution::not_found();
        }
        try {
            $modinfo = get_fast_modinfo((int)$cm->course, $userid);
            $info = $modinfo->get_cm((int)$cm->id);
            if (!$info->uservisible) {
                return context_target_resolution::not_found();
            }
        } catch (\Throwable $e) {
            return context_target_resolution::not_found();
        }

        $context = context_module::instance((int)$cm->id, IGNORE_MISSING);
        return $context ? context_target_resolution::resolved($context) : context_target_resolution::not_found();
    }

    /**
     * Narrow a candidate set by an activity-name query (empty query = no filter).
     *
     * Exact (case-insensitive) name matches win when present, so "booking" resolves the activity
     * literally named "booking" rather than going ambiguous against "booking (copy)". Only when no
     * exact match exists do we fall back to a substring match.
     *
     * @param array[] $instances
     * @param string $query
     * @return array[]
     */
    private function filter_by_name(array $instances, string $query): array {
        if ($query === '') {
            return array_values($instances);
        }

        // Names are compared on a key of letters and digits only: "Vorstellungs-Forum" and the forum
        // "Vorstellungsforum" are the same name (baseline run 26, UA-2; #2453, wave 19).
        $querykey = target_query_normalizer::name_key($query);
        if ($querykey === '') {
            return array_values($instances);
        }
        $exact = [];
        $partial = [];
        foreach ($instances as $instance) {
            $namekey = target_query_normalizer::name_key((string)$instance['name']);
            if ($namekey === $querykey) {
                $exact[] = $instance;
            } else if (\core_text::strpos($namekey, $querykey) !== false) {
                $partial[] = $instance;
            }
        }

        return array_values($exact !== [] ? $exact : $partial);
    }

    /**
     * Collect the module instances of a given type the user can actually see.
     *
     * The instance name and course come from the module's own table (the authoritative source),
     * not from a cached modinfo blob; modinfo is consulted only to gate the acting user's
     * visibility per course-module. Caller guarantees $modname is a valid installed module.
     *
     * @param string   $modname
     * @param int      $userid
     * @param int|null $courseid Restrict to one course, or null for the whole site (rare fallback).
     * @return array[]
     */
    private function collect_instances(string $modname, int $userid, ?int $courseid): array {
        global $DB;

        $params = ['modname' => $modname];
        $coursewhere = '';
        if ($courseid !== null) {
            $coursewhere = ' AND c.id = :courseid';
            $params['courseid'] = $courseid;
        }

        $rows = $DB->get_records_sql(
            "SELECT cm.id AS cmid, inst.name AS instname,
                    c.id AS courseid, c.fullname AS coursefullname, c.shortname, c.visible
               FROM {" . $modname . "} inst
               JOIN {course} c ON c.id = inst.course
               JOIN {modules} md ON md.name = :modname
               JOIN {course_modules} cm ON cm.instance = inst.id AND cm.module = md.id AND cm.course = c.id
              WHERE c.id >= 1" . $coursewhere . "
           ORDER BY c.fullname, inst.name",
            $params
        );

        $user = $userid > 0 ? \core_user::get_user($userid) : null;
        $modinfobycourse = [];
        $instances = [];
        foreach ($rows as $row) {
            $cid = (int)$row->courseid;
            try {
                if (!array_key_exists($cid, $modinfobycourse)) {
                    $course = (object)[
                        'id' => $cid,
                        'fullname' => $row->coursefullname,
                        'shortname' => $row->shortname,
                        'visible' => $row->visible,
                    ];
                    $modinfobycourse[$cid] = can_access_course($course, $user)
                        ? get_fast_modinfo($cid, $userid)
                        : null;
                }
                $modinfo = $modinfobycourse[$cid];
                if ($modinfo === null) {
                    continue;
                }
                if (!$modinfo->get_cm((int)$row->cmid)->uservisible) {
                    continue;
                }
            } catch (\Throwable $e) {
                // Never let a single broken course / module break resolution.
                continue;
            }

            $instances[] = [
                'cmid'            => (int)$row->cmid,
                'name'            => format_string($row->instname),
                'courseid'        => $cid,
                'coursename'      => format_string($row->coursefullname),
                'courseshortname' => format_string($row->shortname),
                'url'             => (new \moodle_url('/mod/' . $modname . '/view.php', ['id' => (int)$row->cmid]))->out(false),
            ];
        }

        return $instances;
    }

    /**
     * Whether a module name maps to an installed activity module with its main table.
     *
     * Guards the site-wide SQL (table name interpolation) and rejects typos/non-modules early.
     *
     * @param string $modname
     * @return bool
     */
    private function is_known_module(string $modname): bool {
        global $DB;
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $modname)) {
            return false;
        }
        return $DB->get_manager()->table_exists($modname);
    }

    /**
     * The course module id behind a module CONTEXT id, or 0 when it is not one.
     *
     * @param int    $contextid
     * @param string $modname Only a context of this module type is accepted.
     * @return int
     */
    private function cmid_behind_module_context(int $contextid, string $modname): int {
        if ($contextid <= 0) {
            return 0;
        }
        $context = context::instance_by_id($contextid, IGNORE_MISSING);
        if (!($context instanceof context_module)) {
            return 0;
        }
        $cmid = (int)($context->instanceid ?? 0);
        $cm = $cmid > 0 ? get_coursemodule_from_id($modname, $cmid, 0, false, IGNORE_MISSING) : null;

        return ($cm && !empty($cm->id)) ? $cmid : 0;
    }
}
