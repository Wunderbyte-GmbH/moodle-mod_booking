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

namespace bookingextension_agent\local\wizard\services\questions;

use cm_info;
use context;
use context_module;
use core_question\local\bank\question_bank_helper;
use moodle_exception;
use stdClass;

/**
 * Resolves the target module question bank for generated questions.
 *
 * In Moodle 5.x question banks are module activities (mod_qbank). Given the ambient context
 * the agent runs in (e.g. a booking module), this resolves the enclosing course and returns
 * that course's default question bank activity, creating it if it does not exist yet
 * (idempotent get-or-create via core's question_bank_helper).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_bank_target_resolver {
    /**
     * Resolve the course question bank module context for an ambient context.
     *
     * On Moodle 4.5 (no mod_qbank, question_bank_helper missing) the target is the course
     * context itself — the native home of question categories there — and cm is null.
     *
     * @param context $ambient The context the agent is running in.
     * @return array{context:context,course:stdClass,cm:?cm_info}
     * @throws moodle_exception When the context is not within a course or no bank can be resolved.
     */
    public function resolve_for_context(context $ambient): array {
        global $CFG;

        $coursecontext = $ambient->get_course_context(false);
        if (!$coursecontext) {
            throw new moodle_exception(
                'error',
                'moodle',
                '',
                null,
                'Questions can only be generated within a course context.'
            );
        }

        $course = get_course((int)$coursecontext->instanceid);

        if (!class_exists(question_bank_helper::class)) {
            require_once($CFG->libdir . '/questionlib.php');
            question_make_default_categories([$coursecontext]);
            return [
                'context' => $coursecontext,
                'course' => $course,
                'cm' => null,
            ];
        }

        $cm = question_bank_helper::get_default_open_instance_system_type($course, true);
        if (!$cm) {
            throw new moodle_exception(
                'error',
                'moodle',
                '',
                null,
                'Could not resolve or create a question bank for this course.'
            );
        }

        return [
            'context' => context_module::instance($cm->id),
            'course' => $course,
            'cm' => $cm,
        ];
    }

    /**
     * List the question categories in the enclosing course the user may add questions to.
     *
     * Enumerates every question-bank (mod_qbank) activity in the course, keeps the ones the user can
     * write to (moodle/question:add at the bank's module context) and returns their usable (non-top)
     * categories. Returns an empty list when no bank exists yet — the default bank is created lazily
     * on first use, so "no banks" is treated as "no ambiguity" by the caller, not as an error.
     *
     * This is read-only: it never creates a bank or category.
     *
     * @param context $ambient The context the agent is running in.
     * @param int     $userid  The acting user.
     * @return array[]
     */
    public function list_writable_targets(context $ambient, int $userid): array {
        global $DB;

        $coursecontext = $ambient->get_course_context(false);
        if (!$coursecontext) {
            return [];
        }
        $course = get_course((int)$coursecontext->instanceid);

        // Moodle 4.5: no qbank activities — the writable targets are the course context's own
        // categories (capability checked at the course context, bankcmid 0 marks "no module").
        if (!class_exists(question_bank_helper::class)) {
            if (!has_capability('moodle/question:add', $coursecontext, $userid)) {
                return [];
            }
            $targets = [];
            $categories = $DB->get_records_select(
                'question_categories',
                'contextid = :ctx AND parent <> 0',
                ['ctx' => $coursecontext->id],
                'sortorder, name',
                'id, name'
            );
            $default = question_get_default_category($coursecontext->id);
            foreach ($categories as $cat) {
                $targets[] = [
                    'categoryid' => (int)$cat->id,
                    'categoryname' => format_string($cat->name, true, ['context' => $coursecontext]),
                    'questioncount' => $this->count_category_questions((int)$cat->id),
                    'isdefault' => $default !== false && (int)$default->id === (int)$cat->id,
                    'bankcmid' => 0,
                    'bankname' => format_string($course->fullname, true, ['context' => $coursecontext]),
                    'bankcontextid' => (int)$coursecontext->id,
                ];
            }
            return $targets;
        }

        $modinfo = get_fast_modinfo($course, $userid);

        $targets = [];
        foreach ($modinfo->get_instances_of('qbank') as $cm) {
            if (!$cm->uservisible) {
                continue;
            }
            $bankcontext = context_module::instance($cm->id);
            if (!has_capability('moodle/question:add', $bankcontext, $userid)) {
                continue;
            }

            // Real, selectable categories only: the per-context "top" container has parent = 0.
            $categories = $DB->get_records_select(
                'question_categories',
                'contextid = :ctx AND parent <> 0',
                ['ctx' => $bankcontext->id],
                'sortorder, name',
                'id, name'
            );
            // Moodle's own default category of this bank (question_get_default_category, no creation): the
            // one place the questions go when the user names nothing and no other default competes.
            $default = question_get_default_category($bankcontext->id);
            foreach ($categories as $cat) {
                $targets[] = [
                    'categoryid' => (int)$cat->id,
                    'categoryname' => format_string($cat->name, true, ['context' => $bankcontext]),
                    'questioncount' => $this->count_category_questions((int)$cat->id),
                    'isdefault' => $default !== false && (int)$default->id === (int)$cat->id,
                    'bankcmid' => (int)$cm->id,
                    'bankname' => format_string($cm->get_name(), true, ['context' => $bankcontext]),
                    'bankcontextid' => (int)$bankcontext->id,
                ];
            }
        }

        return $targets;
    }

    /**
     * Resolve a specific, user-chosen category into an import target.
     *
     * Validates that the category is one of the user's writable targets in this course, so a stale or
     * forged category id can never redirect the import outside what the user is allowed to write to.
     *
     * @param context $ambient    The context the agent is running in.
     * @param int     $categoryid The chosen question category id.
     * @param int     $userid     The acting user.
     * @return array{context:context_module,course:stdClass,cm:cm_info,categoryid:int}
     * @throws moodle_exception When the category is not a writable target in this course.
     */
    public function resolve_selected_target(context $ambient, int $categoryid, int $userid): array {
        $coursecontext = $ambient->get_course_context(false);
        if (!$coursecontext) {
            throw new moodle_exception(
                'error',
                'moodle',
                '',
                null,
                'Questions can only be generated within a course context.'
            );
        }

        foreach ($this->list_writable_targets($ambient, $userid) as $target) {
            if ($target['categoryid'] === $categoryid) {
                $course = get_course((int)$coursecontext->instanceid);
                if (empty($target['bankcmid'])) {
                    // Moodle 4.5 course-context target (no qbank module).
                    return [
                        'context' => $coursecontext,
                        'course' => $course,
                        'cm' => null,
                        'categoryid' => $categoryid,
                    ];
                }
                $cm = get_fast_modinfo($course, $userid)->get_cm($target['bankcmid']);
                return [
                    'context' => context_module::instance($target['bankcmid']),
                    'course' => $course,
                    'cm' => $cm,
                    'categoryid' => $categoryid,
                ];
            }
        }

        throw new moodle_exception(
            'error',
            'moodle',
            '',
            null,
            'The selected question category is not a writable target in this course.'
        );
    }

    /**
     * Best-effort count of the question bank entries in a category (for display in the chooser).
     *
     * @param int $categoryid
     * @return int
     */
    private function count_category_questions(int $categoryid): int {
        global $DB;
        try {
            return (int)$DB->count_records('question_bank_entries', ['questioncategoryid' => $categoryid]);
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
