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

/**
 * A quiz name that matches nothing offers the course's quizzes as a choice.
 *
 * UQ-3 (L49 thread 17795): "Complète le quiz existant avec des questions de la catégorie « algèbre »" - the constructor
 * wrote "quiz existant" as the name, no quiz is called that, and the turn asked for the exact name although the
 * course holds the quizzes to choose from. As for options (wave 32): the quizzes are listed with their ids, the model
 * picks one, and nothing is guessed here.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\course\skills\update_quiz_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class update_quiz_miss_offers_quizzes_test extends advanced_testcase {
    /** @var \stdClass */
    private $teacher;
    /** @var \stdClass */
    private $course;
    /** @var int */
    private int $ctxid = 0;

    /**
     * A course with two quizzes; the teacher acts from the course context.
     */
    protected function setUp(): void {
        global $PAGE;
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');
        $coursecontext = context_course::instance($this->course->id);
        $this->ctxid = (int)$coursecontext->id;
        $this->setUser($this->teacher);
        $PAGE->set_context($coursecontext);
    }

    /**
     * The issue with one code from a preflight result.
     *
     * @param \bookingextension_agent\local\wizard\dto\preflight_result $pf
     * @param string $code
     * @return array
     */
    private function issue($pf, string $code): array {
        foreach (json_decode(json_encode($pf->issues), true) as $issue) {
            if ((string)($issue['code'] ?? '') === $code) {
                return $issue;
            }
        }
        return [];
    }

    /**
     * A name nobody carries lists the quizzes (field cmid, their ids), the chosen id then passes.
     */
    public function test_a_miss_lists_the_quizzes_and_the_id_passes(): void {
        $first = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id, 'name' => 'Chapter 1 test']);
        $second = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id, 'name' => 'Final exam']);
        $skill = new update_quiz_skill();

        $input = ['activityquery' => 'quiz existant', 'category' => 'algèbre'];
        $pf = $skill->preflight($input, $this->ctxid, (int)$this->teacher->id);
        $this->assertNotSame('pass', (string)$pf->status);
        $issue = $this->issue($pf, 'UPDATE_QUIZ_NOT_FOUND');
        $this->assertSame('needs_clarification', (string)($issue['severity'] ?? ''), json_encode($pf->issues));
        $this->assertSame('cmid', (string)($issue['field'] ?? ''));
        $this->assertEqualsCanonicalizing(
            [(int)$first->cmid, (int)$second->cmid],
            array_map('intval', array_column((array)($issue['candidates'] ?? []), 'id'))
        );
        foreach (['activityquery', 'cmid', 'UPDATE_QUIZ_NOT_FOUND'] as $internal) {
            $this->assertStringNotContainsString($internal, (string)($issue['message'] ?? ''), 'no schema field in the text');
        }

        $retry = $skill->preflight(['cmid' => (int)$first->cmid, 'category' => 'algèbre'], $this->ctxid, (int)$this->teacher->id);
        $this->assertNotSame('UPDATE_QUIZ_NOT_FOUND', (string)(($this->issue($retry, 'UPDATE_QUIZ_NOT_FOUND')['code'] ?? '')));
    }

    /**
     * Non-success path: a course without any quiz has nothing to offer - the miss stays a plain clarification.
     */
    public function test_a_course_without_quizzes_offers_nothing(): void {
        $pf = (new update_quiz_skill())->preflight(
            ['activityquery' => 'quiz existant', 'category' => 'algèbre'],
            $this->ctxid,
            (int)$this->teacher->id
        );
        $issue = $this->issue($pf, 'UPDATE_QUIZ_NOT_FOUND');
        $this->assertSame('needs_clarification', (string)($issue['severity'] ?? ''), json_encode($pf->issues));
        $this->assertArrayNotHasKey('candidates', $issue);
    }
}
