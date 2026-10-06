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
use context_module;
use bookingextension_agent\local\wizard\question\skills\generate_questions_skill;
use bookingextension_agent\local\wizard\dto\agent_context;
use bookingextension_agent\local\wizard\services\security\skill_operating_context_resolver;

/**
 * Cross-context behaviour of question.generate_questions (blueprint Phase 3 adopter).
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\question\skills\generate_questions_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class generate_questions_cross_context_test extends advanced_testcase {
    /**
     * get_target_selector maps courseid / coursequery, and yields null for the current course.
     */
    public function test_target_selector_mapping(): void {
        $this->resetAfterTest();
        $skill = new generate_questions_skill();

        $this->assertNull($skill->get_target_selector([]));
        $this->assertNull($skill->get_target_selector(['courseid' => 0, 'coursequery' => '']));

        $byid = $skill->get_target_selector(['courseid' => 42]);
        $this->assertNotNull($byid);
        $this->assertSame(42, $byid->id());
        $this->assertSame(CONTEXT_COURSE, $byid->level());

        $byquery = $skill->get_target_selector(['coursequery' => 'Biology 101']);
        $this->assertNotNull($byquery);
        $this->assertSame('Biology 101', $byquery->query());
    }

    /**
     * With a target course, the operating context resolves to that course (not the ambient one).
     */
    public function test_operating_context_resolves_to_target_course(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $ambientcourse = $this->getDataGenerator()->create_course();
        $targetcourse = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $ambientcourse->id]);
        $ambient = agent_context::from_context(context_module::instance($page->cmid));

        $skill = new generate_questions_skill();
        $operating = (new skill_operating_context_resolver())->resolve(
            $skill,
            ['courseid' => (int)$targetcourse->id],
            $ambient,
            0
        );

        $this->assertSame((int)context_course::instance($targetcourse->id)->id, $operating->id());
    }

    /**
     * Gate 2 is enforced at the TARGET course: a user without moodle/question:add there is blocked,
     * even though the chat lives in another course.
     */
    public function test_gate2_checked_at_target_course(): void {
        global $DB;
        $this->resetAfterTest();

        $targetcourse = $this->getDataGenerator()->create_course();
        $targetcontextid = (int)context_course::instance($targetcourse->id)->id;
        $input = ['content' => 'Some source material about photosynthesis.', 'courseid' => (int)$targetcourse->id];

        // A user with no role in the target course lacks moodle/question:add there.
        $student = $this->getDataGenerator()->create_user();
        $result = (new generate_questions_skill())->preflight($input, $targetcontextid, (int)$student->id)->to_array();
        $this->assertNotSame('pass', $result['status']);
        $this->assertContains('NO_NATIVE_CAPABILITY', $result['issue_codes']);

        // An editing teacher in the target course may add questions there → Gate 2 passes.
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $targetcourse->id, 'editingteacher');
        $passresult = (new generate_questions_skill())->preflight($input, $targetcontextid, (int)$teacher->id)->to_array();
        $this->assertSame('pass', $passresult['status']);
    }
}
