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
use context_course;
use bookingextension_agent\local\wizard\course\skills\update_quiz_skill;
use bookingextension_agent\local\wizard\dto\skill_risk_class;

/**
 * Tests for the course.update_quiz skill (deterministic parts; generation needs the LLM, not exercised).
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\course\skills\update_quiz_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class update_quiz_skill_test extends advanced_testcase {
    /**
     * Course + teacher + a quiz, acting as the teacher with the course context.
     *
     * @return array{0:\stdClass,1:\stdClass,2:\stdClass,3:int}
     */
    private function quiz_course(): array {
        global $PAGE;
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'name' => 'Chapter 1 quiz']);
        $coursecontext = context_course::instance($course->id);
        $this->setUser($teacher);
        $PAGE->set_context($coursecontext);
        return [$course, $teacher, $quiz, (int)$coursecontext->id];
    }

    /**
     * Metadata.
     */
    public function test_metadata(): void {
        $skill = new update_quiz_skill();
        $this->assertSame('course.update_quiz', $skill->get_name());
        $this->assertFalse($skill->is_read_only());
        $this->assertSame(skill_risk_class::R2, $skill->get_risk_class());
        $this->assertTrue($skill->supports_target_context());
    }

    /**
     * Nothing to change/add asks what to do.
     */
    public function test_nothing_to_do(): void {
        $this->resetAfterTest();
        [$course, $teacher, $quiz, $ctxid] = $this->quiz_course();
        $result = (new update_quiz_skill())->preflight(['cmid' => (int)$quiz->cmid], $ctxid, (int)$teacher->id);
        $this->assertSame('hard_block', $result->status);
        $this->assertContains('UPDATE_QUIZ_NOTHING_TO_DO', $result->issuecodes);
    }

    /**
     * Wanting questions with no source triggers the source clarification.
     */
    public function test_question_source_clarification(): void {
        $this->resetAfterTest();
        [$course, $teacher, $quiz, $ctxid] = $this->quiz_course();
        $result = (new update_quiz_skill())->preflight(
            ['cmid' => (int)$quiz->cmid, 'addquestions' => true],
            $ctxid,
            (int)$teacher->id
        );
        $this->assertSame('hard_block', $result->status);
        $this->assertContains('UPDATE_QUIZ_QUESTION_SOURCE', $result->issuecodes);
    }

    /**
     * Renaming an existing quiz (settings update path).
     */
    public function test_rename_quiz(): void {
        $this->resetAfterTest();
        [$course, $teacher, $quiz, $ctxid] = $this->quiz_course();
        $skill = new update_quiz_skill();
        $pf = $skill->preflight(['cmid' => (int)$quiz->cmid, 'name' => 'Renamed quiz'], $ctxid, (int)$teacher->id);
        $this->assertSame('pass', $pf->status);
        $result = $skill->execute($pf->preparedinput, $ctxid, (int)$teacher->id);
        $this->assertSame('executed', $result['status']);
        $this->assertSame('Renamed quiz', get_fast_modinfo($course)->get_cm((int)$quiz->cmid)->name);
    }

    /**
     * A quiz named from another course is found instead of reported missing.
     *
     * Run-23 finding (UQ-2, UQ-4): the search ran over the ambient course only, so "rename the
     * Abschlusstest" answered "no quiz called that in this course" - about a quiz that existed one
     * course over. The ambient course is still looked at first; this is what happens when it holds
     * no match, and a user who may not see the activity still never finds it.
     */
    public function test_a_quiz_in_another_course_is_found_when_the_ambient_course_has_none(): void {
        $this->resetAfterTest();
        [$course, $teacher, $quiz, $ctxid] = $this->quiz_course();
        unset($course, $quiz);

        // The named quiz lives elsewhere, and the acting user may edit it there.
        $other = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($teacher->id, $other->id, 'editingteacher');
        $elsewhere = $this->getDataGenerator()->create_module(
            'quiz',
            ['course' => $other->id, 'name' => 'Abschlusstest']
        );

        $skill = new update_quiz_skill();
        $pf = $skill->preflight(
            ['activityquery' => 'Abschlusstest', 'name' => 'Zertifikatspruefung'],
            $ctxid,
            (int)$teacher->id
        );
        $this->assertSame('pass', $pf->status);

        $result = $skill->execute($pf->preparedinput, $ctxid, (int)$teacher->id);
        $this->assertSame('executed', $result['status']);
        $this->assertSame(
            'Zertifikatspruefung',
            get_fast_modinfo($other->id)->get_cm((int)$elsewhere->cmid)->name
        );
    }

    /**
     * A quiz the acting user may not see stays invisible, wherever it lives.
     */
    public function test_the_site_wide_search_never_reveals_a_hidden_activity(): void {
        $this->resetAfterTest();
        [$course, $teacher, $quiz, $ctxid] = $this->quiz_course();
        unset($course, $quiz);

        // Another course the acting user is not enrolled in at all.
        $other = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('quiz', ['course' => $other->id, 'name' => 'Geheimtest']);

        $skill = new update_quiz_skill();
        $pf = $skill->preflight(
            ['activityquery' => 'Geheimtest', 'name' => 'Neuer Name'],
            $ctxid,
            (int)$teacher->id
        );
        $this->assertNotSame('pass', $pf->status);
    }

    /**
     * Adding specific existing questions to an existing quiz.
     */
    public function test_add_specific_questions(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $teacher, $quiz, $ctxid] = $this->quiz_course();

        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $qgen->create_question_category(['contextid' => context_course::instance($course->id)->id]);
        $q1 = $qgen->create_question('truefalse', null, ['category' => $cat->id]);

        $skill = new update_quiz_skill();
        $pf = $skill->preflight(
            ['cmid' => (int)$quiz->cmid, 'questionids' => [(int)$q1->id]],
            $ctxid,
            (int)$teacher->id
        );
        $this->assertSame('pass', $pf->status);
        $result = $skill->execute($pf->preparedinput, $ctxid, (int)$teacher->id);
        $this->assertSame('executed', $result['status']);
        $this->assertSame(1, (int)$DB->count_records('quiz_slots', ['quizid' => (int)$quiz->id]));
    }

    /**
     * Editing a non-quiz cm is rejected.
     */
    public function test_not_a_quiz(): void {
        $this->resetAfterTest();
        [$course, $teacher, $quiz, $ctxid] = $this->quiz_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'A page']);
        $result = (new update_quiz_skill())->preflight(
            ['cmid' => (int)$page->cmid, 'name' => 'X'],
            $ctxid,
            (int)$teacher->id
        );
        $this->assertSame('hard_block', $result->status);
        $this->assertContains('UPDATE_QUIZ_NOT_A_QUIZ', $result->issuecodes);
    }

    /**
     * Gate: a student cannot edit a quiz.
     */
    public function test_gate_blocks_student(): void {
        $this->resetAfterTest();
        [$course, $teacher, $quiz, $ctxid] = $this->quiz_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);
        $result = (new update_quiz_skill())->preflight(
            ['cmid' => (int)$quiz->cmid, 'name' => 'X'],
            $ctxid,
            (int)$student->id
        );
        $this->assertSame('hard_block', $result->status);
        $this->assertContains('NO_NATIVE_CAPABILITY', $result->issuecodes);
    }
}
