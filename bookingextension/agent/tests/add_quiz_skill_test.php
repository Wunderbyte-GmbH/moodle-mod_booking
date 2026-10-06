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
use bookingextension_agent\local\wizard\course\skills\add_quiz_skill;
use bookingextension_agent\local\wizard\dto\skill_risk_class;

/**
 * Tests for the course.add_quiz skill (deterministic parts; generation needs the LLM and is not exercised).
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\course\skills\add_quiz_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class add_quiz_skill_test extends advanced_testcase {
    /**
     * Course + editing teacher, acting as teacher with the course context + page set.
     *
     * @return array{0:\stdClass,1:\stdClass,2:int}
     */
    private function teacher_course(): array {
        global $PAGE;
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $coursecontext = context_course::instance($course->id);
        $this->setUser($teacher);
        $PAGE->set_context($coursecontext);
        return [$course, $teacher, (int)$coursecontext->id];
    }

    /**
     * Metadata: mutating, R2, manageactivities, cross-context.
     */
    public function test_metadata(): void {
        $skill = new add_quiz_skill();
        $this->assertSame('course.add_quiz', $skill->get_name());
        $this->assertFalse($skill->is_read_only());
        $this->assertSame(skill_risk_class::R2, $skill->get_risk_class());
        $this->assertTrue($skill->supports_target_context());
    }

    /**
     * A name is required.
     */
    public function test_name_required(): void {
        $this->resetAfterTest();
        [$course, $teacher, $ctxid] = $this->teacher_course();
        $result = (new add_quiz_skill())->preflight(['courseid' => (int)$course->id], $ctxid, (int)$teacher->id);
        $this->assertSame('hard_block', $result->status);
        $this->assertContains('ADD_QUIZ_NAME_REQUIRED', $result->issuecodes);
    }

    /**
     * George 2026-09-26 (wave 32, AQ-2 and asset AQ-3: constructions invented "Test Shell" and other names): the
     * name is a declared required value, so the construction reminder names it and the constructor asks (frozen
     * rule 2). Should a command still arrive without a name - or with blanks only - the preflight asks as well: a
     * question, never an error. The structure gate does not reject a nameless empty quiz on its own.
     */
    public function test_missing_name_is_asked_for_by_contract_and_preflight(): void {
        $this->resetAfterTest();
        [$course, $teacher, $ctxid] = $this->teacher_course();
        $skill = new add_quiz_skill();

        $method = new \ReflectionMethod($skill, 'prompt_contract_payload');
        $method->setAccessible(true);
        $contract = (array)$method->invoke($skill);
        $this->assertSame(['name'], (array)$contract['required_input']);
        $this->assertTrue($skill->check_structure([])['valid']);

        foreach ([[], ['name' => '   ']] as $input) {
            $result = $skill->preflight(['courseid' => (int)$course->id] + $input, $ctxid, (int)$teacher->id);
            $this->assertSame('hard_block', $result->status);
            $this->assertSame(['ADD_QUIZ_NAME_REQUIRED'], array_values($result->issuecodes));
            $this->assertSame(
                ['needs_clarification'],
                array_values(array_unique(array_column($result->issues, 'severity')))
            );
        }
    }

    /**
     * Wanting questions without naming a source triggers the source clarification.
     */
    public function test_question_source_clarification(): void {
        $this->resetAfterTest();
        [$course, $teacher, $ctxid] = $this->teacher_course();
        $result = (new add_quiz_skill())->preflight(
            ['courseid' => (int)$course->id, 'name' => 'My quiz', 'addquestions' => true],
            $ctxid,
            (int)$teacher->id
        );
        $this->assertSame('hard_block', $result->status);
        $this->assertContains('ADD_QUIZ_QUESTION_SOURCE', $result->issuecodes);
    }

    /**
     * An empty quiz is allowed: preflight passes (mode none) and execute creates a quiz with no questions.
     */
    public function test_create_empty_quiz(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $teacher, $ctxid] = $this->teacher_course();
        $skill = new add_quiz_skill();

        $pf = $skill->preflight(['courseid' => (int)$course->id, 'name' => 'Empty quiz'], $ctxid, (int)$teacher->id);
        $this->assertSame('pass', $pf->status);
        $this->assertSame('none', $pf->preparedinput['plan']['mode']);

        $result = $skill->execute($pf->preparedinput, $ctxid, (int)$teacher->id);
        $this->assertSame('executed', $result['status']);
        $this->assertGreaterThan(0, (int)$result['created_cmid']);
        $cm = get_fast_modinfo($course)->get_cm((int)$result['created_cmid']);
        $this->assertSame('quiz', $cm->modname);
        $this->assertSame('Empty quiz', $cm->name);
        // No slots in an empty quiz.
        $instance = $DB->get_field('course_modules', 'instance', ['id' => (int)$result['created_cmid']]);
        $this->assertSame(0, (int)$DB->count_records('quiz_slots', ['quizid' => (int)$instance]));
    }

    /**
     * Creating a quiz and populating it with specific existing questions.
     */
    public function test_create_quiz_with_specific_questions(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $teacher, $ctxid] = $this->teacher_course();

        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $qgen->create_question_category(['contextid' => context_course::instance($course->id)->id]);
        $q1 = $qgen->create_question('truefalse', null, ['category' => $cat->id]);
        $q2 = $qgen->create_question('truefalse', null, ['category' => $cat->id]);

        $skill = new add_quiz_skill();
        $pf = $skill->preflight(
            ['courseid' => (int)$course->id, 'name' => 'Q quiz', 'questionids' => [(int)$q1->id, (int)$q2->id]],
            $ctxid,
            (int)$teacher->id
        );
        $this->assertSame('pass', $pf->status);
        $this->assertSame('ids', $pf->preparedinput['plan']['mode']);

        $result = $skill->execute($pf->preparedinput, $ctxid, (int)$teacher->id);
        $this->assertSame('executed', $result['status']);
        $this->assertSame(2, (int)$result['question_count']);
        $instance = $DB->get_field('course_modules', 'instance', ['id' => (int)$result['created_cmid']]);
        $this->assertSame(2, (int)$DB->count_records('quiz_slots', ['quizid' => (int)$instance]));
    }

    /**
     * Gate 2: a student cannot create a quiz.
     */
    public function test_gate_blocks_student(): void {
        $this->resetAfterTest();
        [$course, $teacher, $ctxid] = $this->teacher_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);

        $result = (new add_quiz_skill())->preflight(
            ['courseid' => (int)$course->id, 'name' => 'Nope'],
            $ctxid,
            (int)$student->id
        );
        $this->assertSame('hard_block', $result->status);
        $this->assertContains('NO_NATIVE_CAPABILITY', $result->issuecodes);
    }
}
