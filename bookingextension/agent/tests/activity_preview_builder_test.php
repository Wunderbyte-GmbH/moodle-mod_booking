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
use bookingextension_agent\local\wizard\services\activity_preview_builder;
use bookingextension_agent\local\wizard\course\skills\add_activity_skill;
use bookingextension_agent\local\wizard\course\skills\update_activity_skill;
use bookingextension_agent\local\wizard\course\skills\add_quiz_skill;
use bookingextension_agent\local\wizard\course\skills\update_quiz_skill;

/**
 * Tests for the course activity/quiz pre-confirmation preview (Phase 3).
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\activity_preview_builder
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class activity_preview_builder_test extends advanced_testcase {
    /**
     * Map a descriptor's rows into a label => value array.
     *
     * @param array $descriptor
     * @return array
     */
    private function rows_map(array $descriptor): array {
        $map = [];
        foreach ($descriptor['rows'] as $row) {
            $map[$row['label']] = $row['value'];
        }
        return $map;
    }

    /**
     * add_activity_descriptor shows module type, resolved course, section and name.
     */
    public function test_add_activity_descriptor(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Physics 101']);

        $descriptor = activity_preview_builder::add_activity_descriptor([
            'modname' => 'page',
            'name' => 'Lecture notes',
            'courseid' => (int)$course->id,
            'sectionnum' => 2,
            'intro' => 'Reading material',
        ]);

        $this->assertSame('Add activity "Lecture notes"', $descriptor['title']);
        $rows = $this->rows_map($descriptor);
        $this->assertSame(get_string('pluginname', 'mod_page'), $rows['Type']);
        $this->assertSame('Physics 101', $rows['Course']);
        $this->assertSame('Section 2', $rows['Section']);
        $this->assertSame('Reading material', $rows['Description']);
    }

    /**
     * update_activity_descriptor resolves the target and shows only changed fields.
     */
    public function test_update_activity_descriptor(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Old name']);

        $descriptor = activity_preview_builder::update_activity_descriptor([
            'cmid' => (int)$page->cmid,
            'name' => 'New name',
            'visible' => 0,
        ]);

        $this->assertStringContainsString('Old name', $descriptor['title']);
        $this->assertStringContainsString('#' . (int)$page->cmid, $descriptor['title']);
        $rows = $this->rows_map($descriptor);
        $this->assertSame('New name', $rows['Name']);
        $this->assertSame(get_string('previewvalue_hidden', 'bookingextension_agent'), $rows['Visibility']);
    }

    /**
     * The confirmation preview receives the PREPARED input (preflight pass payload) where the field
     * changes are nested under 'changes' and a move under 'section_move': the rename row must not be
     * dropped when a section move is also requested, and the target course row is shown.
     */
    public function test_update_activity_descriptor_prepared_input(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Chemistry 1']);
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Old name']);

        $descriptor = activity_preview_builder::update_activity_descriptor([
            'courseid' => (int)$course->id,
            'cmid' => (int)$page->cmid,
            'modname' => 'page',
            'changes' => ['name' => 'New name'],
            'section_move' => 1,
            'before' => ['name' => 'Old name', 'visible' => 1, 'section' => 0],
        ]);

        $rows = $this->rows_map($descriptor);
        $this->assertSame('New name', $rows['Name']);
        $this->assertSame('1', $rows['Section']);
        $this->assertSame('Chemistry 1', $rows['Course']);
    }

    /**
     * update_quiz preview from PREPARED input: name change (nested in 'changes'), a questions row
     * derived from the question 'plan' (count + source type), and the target course row.
     */
    public function test_update_quiz_descriptor_prepared_input(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Biology 2']);
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'name' => 'Quiz 3']);

        // Rename + generate questions.
        $rows = $this->rows_map(activity_preview_builder::update_quiz_descriptor([
            'courseid' => (int)$course->id,
            'cmid' => (int)$quiz->cmid,
            'instance' => (int)$quiz->id,
            'changes' => ['name' => 'Final exam'],
            'plan' => ['mode' => 'generate', 'content' => 'Photosynthesis', 'count' => 5],
            'ambientcontextid' => 1,
        ]));
        $this->assertSame('Final exam', $rows['Name']);
        $this->assertStringContainsString('5', $rows['Questions']);
        $this->assertSame('Biology 2', $rows['Course']);

        // Random questions from a category, no settings changes: rows must not come back empty.
        $rows = $this->rows_map(activity_preview_builder::update_quiz_descriptor([
            'courseid' => (int)$course->id,
            'cmid' => (int)$quiz->cmid,
            'instance' => (int)$quiz->id,
            'changes' => [],
            'plan' => ['mode' => 'category', 'category' => 'Algebra', 'count' => 3],
        ]));
        $this->assertStringContainsString('3', $rows['Questions']);
        $this->assertStringContainsString('Algebra', $rows['Questions']);
        $this->assertSame('Biology 2', $rows['Course']);
    }

    /**
     * The quiz question summary combines generate / random / selected / category clauses.
     */
    public function test_quiz_questions_summary(): void {
        $rows = $this->rows_map(activity_preview_builder::add_quiz_descriptor([
            'name' => 'Midterm',
            'addquestions' => true,
            'count' => 5,
            'randomcount' => 3,
            'questionids' => [10, 11],
            'category' => 'Mechanics',
        ]));

        $questions = $rows['Questions'];
        $this->assertStringContainsString('5', $questions);
        $this->assertStringContainsString('3 random', $questions);
        $this->assertStringContainsString('2 selected', $questions);
        $this->assertStringContainsString('Mechanics', $questions);
    }

    /**
     * The Phase 3 skill overrides delegate to the shared builder.
     */
    public function test_skill_overrides_delegate(): void {
        $add = (new add_activity_skill())->describe_proposed_action(['modname' => 'page', 'name' => 'X']);
        $this->assertSame(get_string('pluginname', 'mod_page'), $this->rows_map($add)['Type']);

        $upd = (new update_activity_skill())->describe_proposed_action(['cmid' => 0, 'name' => 'Y']);
        $this->assertSame('Y', $this->rows_map($upd)['Name']);

        $quiz = (new add_quiz_skill())->describe_proposed_action(['name' => 'Q', 'addquestions' => true, 'count' => 4]);
        $this->assertStringContainsString('4', $this->rows_map($quiz)['Questions']);

        $uquiz = (new update_quiz_skill())->describe_proposed_action(['cmid' => 0, 'name' => 'Z']);
        $this->assertSame('Z', $this->rows_map($uquiz)['Name']);
    }
}
