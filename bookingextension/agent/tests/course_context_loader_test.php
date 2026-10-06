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

namespace bookingextension_agent;

use advanced_testcase;
use bookingextension_agent\local\wizard\services\course\course_context_loader;

/**
 * Tests for the shared course_context_loader (inventory + enumerate-then-reason resolution).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \bookingextension_agent\local\wizard\services\course\course_context_loader
 */
final class course_context_loader_test extends advanced_testcase {
    /**
     * Build a course with a page and two quizzes, return [course, teacherid].
     *
     * @return array{0:\stdClass,1:int}
     */
    private function build_course(): array {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['numsections' => 2]);
        $teacher = $gen->create_user();
        $gen->enrol_user($teacher->id, $course->id, 'editingteacher');
        $gen->create_module('page', ['course' => $course->id, 'section' => 0, 'name' => 'Welcome Page']);
        $gen->create_module('quiz', ['course' => $course->id, 'section' => 1, 'name' => 'Quiz A']);
        $gen->create_module('quiz', ['course' => $course->id, 'section' => 1, 'name' => 'Quiz B']);
        rebuild_course_cache($course->id, true);
        return [$course, (int)$teacher->id];
    }

    /**
     * build_inventory returns one rich row per activity.
     */
    public function test_build_inventory_lists_all_activities_with_fields(): void {
        $this->resetAfterTest();
        [$course, $teacherid] = $this->build_course();

        $rows = (new course_context_loader())->build_inventory($course, $teacherid);

        $this->assertCount(3, $rows);
        $names = array_column($rows, 'name');
        $this->assertContains('Welcome Page', $names);
        $this->assertContains('Quiz A', $names);
        $this->assertContains('Quiz B', $names);
        foreach ($rows as $r) {
            $this->assertArrayHasKey('cmid', $r);
            $this->assertArrayHasKey('modname', $r);
            $this->assertArrayHasKey('sectionnum', $r);
            $this->assertArrayHasKey('position', $r);
            $this->assertArrayHasKey('uservisible', $r);
        }
    }

    /**
     * Exact unique name and explicit cmid both resolve.
     */
    public function test_resolve_activity_unique_name_and_id(): void {
        $this->resetAfterTest();
        [$course, $teacherid] = $this->build_course();
        $loader = new course_context_loader();
        $inv = $loader->build_inventory($course, $teacherid);

        $byname = $loader->resolve_activity($inv, 'Welcome Page');
        $this->assertSame('resolved', $byname['status']);
        $this->assertSame('Welcome Page', $byname['row']['name']);

        $cmid = (int)$byname['row']['cmid'];
        $byid = $loader->resolve_activity($inv, 'irrelevant', $cmid);
        $this->assertSame('resolved', $byid['status']);
        $this->assertSame($cmid, (int)$byid['row']['cmid']);
    }

    /**
     * An ordinal/ambiguous reference does NOT guess — it returns the candidates for the LLM.
     */
    public function test_resolve_activity_ambiguous_returns_candidates_not_a_guess(): void {
        $this->resetAfterTest();
        [$course, $teacherid] = $this->build_course();
        $loader = new course_context_loader();
        $inv = $loader->build_inventory($course, $teacherid);

        // Query "second quiz" matches no name; with a quiz filter the pool is the two quizzes -> unresolved.
        $res = $loader->resolve_activity($inv, 'second quiz', 0, 'quiz');
        $this->assertSame('unresolved', $res['status']);
        $this->assertCount(2, $res['candidates']);

        // Query "quiz" contains both quiz names -> still ambiguous, never a silent pick.
        $res2 = $loader->resolve_activity($inv, 'quiz', 0, 'quiz');
        $this->assertSame('unresolved', $res2['status']);
    }

    /**
     * No activity of the requested type -> 'none'.
     */
    public function test_resolve_activity_none_when_type_absent(): void {
        $this->resetAfterTest();
        [$course, $teacherid] = $this->build_course();
        $loader = new course_context_loader();
        $inv = $loader->build_inventory($course, $teacherid);

        $res = $loader->resolve_activity($inv, 'the assignment', 0, 'assign');
        $this->assertSame('none', $res['status']);
    }

    /**
     * The resolution observation lists candidates by activityid and instructs an unambiguous re-call.
     */
    public function test_resolution_observation_instructs_activityid_recall(): void {
        $this->resetAfterTest();
        [$course, $teacherid] = $this->build_course();
        $loader = new course_context_loader();
        $inv = $loader->build_inventory($course, $teacherid);

        $obs = $loader->build_resolution_observation('the second quiz', $inv, 'course.diagnose_user_in_course');
        $this->assertStringContainsString('Quiz A', $obs);
        $this->assertStringContainsString('Quiz B', $obs);
        // Ids are labelled with the real parameter so the LLM re-calls with activityid (not cmid).
        $this->assertStringContainsString('activityid=', $obs);
        // Unambiguous, self-correcting instruction — no user-clarification, no query-rephrase loop.
        $this->assertStringContainsString('EXACTLY ONE', $obs);
        $this->assertStringContainsString('do NOT repeat the same activityquery', $obs);
    }
}
