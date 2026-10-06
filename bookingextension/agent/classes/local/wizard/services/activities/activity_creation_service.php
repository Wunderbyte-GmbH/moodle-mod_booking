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

namespace bookingextension_agent\local\wizard\services\activities;

use context_module;
use moodle_url;
use stdClass;

/**
 * Module-neutral creation core: persists a prepared $moduleinfo via add_moduleinfo() in a transaction.
 *
 * Knows nothing about specific module types or the agent's skills — it only turns a validated
 * $moduleinfo into a real course module, with rollback on failure. A dedicated skill (e.g. course.add_quiz)
 * can reuse this exact service for the "create the hull" step (blueprint §8).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity_creation_service {
    /**
     * Create the activity from a prepared $moduleinfo.
     *
     * @param stdClass $moduleinfo Prepared, validated module info (see module_form_contract).
     * @param stdClass $course
     * @return array{cmid:int,instance:int,modname:string,name:string,url:string,coursecontextid:int}
     * @throws \Throwable On creation failure (the underlying add_moduleinfo() rolls its DB changes back).
     */
    public function create(stdClass $moduleinfo, stdClass $course): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/modlib.php');

        $this->assert_creation_allowed($moduleinfo, $course);

        $transaction = $DB->start_delegated_transaction();
        try {
            $result = add_moduleinfo($moduleinfo, $course);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            throw $e;
        }

        $cmid = (int)($result->coursemodule ?? 0);
        $instance = (int)($result->instance ?? 0);
        $modname = (string)($result->modulename ?? $moduleinfo->modulename ?? '');
        $name = (string)($result->name ?? $moduleinfo->name ?? $modname);

        return [
            'cmid' => $cmid,
            'instance' => $instance,
            'modname' => $modname,
            'name' => $name,
            'url' => $this->resolve_activity_url($course, $cmid, $modname),
            'coursecontextid' => (int)\context_course::instance($course->id)->id,
        ];
    }

    /**
     * Raise a TRUTHFUL cause before add_moduleinfo() does (C6, thread-audit).
     *
     * core's add_moduleinfo() -> course_allowed_module() throws a single 'moduledisable' —
     * whose {$a} is frequently unresolved — for TWO different causes: a genuinely disabled
     * module type AND an acting user merely missing mod/<name>:addinstance. A reader cannot
     * tell which. Pre-check the capability case here (the module is enabled) so the surfaced
     * message names the missing capability instead of falsely claiming the module is disabled.
     * A truly disabled module falls through to core's own error unchanged.
     *
     * @param stdClass $moduleinfo
     * @param stdClass $course
     * @return void
     */
    private function assert_creation_allowed(stdClass $moduleinfo, stdClass $course): void {
        $modname = trim((string)($moduleinfo->modulename ?? $moduleinfo->modname ?? ''));
        if ($this->addinstance_denial_reason($course, $modname) !== null) {
            throw new \moodle_exception(
                'agent_activity_missing_addinstance',
                'bookingextension_agent',
                '',
                "mod/$modname:addinstance"
            );
        }
    }

    /**
     * Truthful, localized reason a module cannot be added for a MISSING addinstance capability,
     * or null when that is not the blocker (C6). Callers that build the mform BEFORE reaching
     * create() (add_activity's form-first path) must consult this first, because the mform
     * construction itself raises the misleading "module disabled ({$a})" for a mere capability
     * gap. A genuinely disabled/uninstalled module returns null → core's own accurate error stands.
     *
     * @param stdClass $course
     * @param string $modname
     * @return string|null
     */
    public function addinstance_denial_reason(stdClass $course, string $modname): ?string {
        global $DB;
        $modname = trim($modname);
        if ($modname === '') {
            return null;
        }
        $module = $DB->get_record('modules', ['name' => $modname]);
        if (!$module || (int)$module->visible !== 1) {
            return null;
        }
        if (!has_capability("mod/$modname:addinstance", \context_course::instance((int)$course->id))) {
            return get_string('agent_activity_missing_addinstance', 'bookingextension_agent', "mod/$modname:addinstance");
        }
        return null;
    }

    /**
     * Apply a prepared $moduleinfo to an existing course module (edit), transactionally.
     *
     * @param stdClass $cm Course module record the moduleinfo was built from.
     * @param stdClass $moduleinfo Prepared, validated module info (see module_form_contract update mode).
     * @param stdClass $course
     * @return array{cmid:int,instance:int,modname:string,name:string,url:string,coursecontextid:int}
     * @throws \Throwable On update failure (the underlying update_moduleinfo() rolls its DB changes back).
     */
    public function update(stdClass $cm, stdClass $moduleinfo, stdClass $course): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/modlib.php');

        $transaction = $DB->start_delegated_transaction();
        try {
            [$updatedcm, $updatedinfo] = update_moduleinfo($cm, $moduleinfo, $course);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            throw $e;
        }
        unset($updatedcm);

        $cmid = (int)($moduleinfo->coursemodule ?? $cm->id ?? 0);
        $modname = (string)($moduleinfo->modulename ?? '');
        $name = (string)($updatedinfo->name ?? $moduleinfo->name ?? $modname);

        return [
            'cmid' => $cmid,
            'instance' => (int)($moduleinfo->instance ?? $cm->instance ?? 0),
            'modname' => $modname,
            'name' => $name,
            'url' => $this->resolve_activity_url($course, $cmid, $modname),
            'coursecontextid' => (int)\context_course::instance($course->id)->id,
        ];
    }

    /**
     * Move an existing course module to a different section, transactionally.
     *
     * Section placement is a course-structure operation, not a mod_form field — this uses the core
     * moveto_module() so the section sequences, module visibility and course cache stay consistent.
     *
     * @param stdClass $cm Course module record (from get_coursemodule_from_id) carrying the CURRENT section id.
     * @param int $sectionnum Target section number (0-based topic index) — must already exist in the course.
     * @param stdClass $course
     * @return int The section number the module now lives in.
     * @throws \Throwable On move failure (the transaction is rolled back).
     */
    public function move_to_section(stdClass $cm, int $sectionnum, stdClass $course): int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');

        $section = $DB->get_record(
            'course_sections',
            ['course' => (int)$course->id, 'section' => $sectionnum],
            '*',
            MUST_EXIST
        );

        $transaction = $DB->start_delegated_transaction();
        try {
            moveto_module($cm, $section);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            throw $e;
        }

        return $sectionnum;
    }

    /**
     * Resolve a user-facing URL for the freshly created activity.
     *
     * Activities without their own view page (e.g. a label) link back to the course page.
     *
     * @param stdClass $course
     * @param int $cmid
     * @param string $modname
     * @return string
     */
    private function resolve_activity_url(stdClass $course, int $cmid, string $modname): string {
        if ($cmid > 0) {
            try {
                $cm = get_fast_modinfo($course)->get_cm($cmid);
                if ($cm->url instanceof moodle_url) {
                    return $cm->url->out(false);
                }
            } catch (\Throwable $e) {
                // Fall through to the course page.
                unset($e);
            }
        }
        return (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false);
    }
}
