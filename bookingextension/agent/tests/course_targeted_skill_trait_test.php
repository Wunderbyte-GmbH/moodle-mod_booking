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
use bookingextension_agent\local\wizard\course\skills\add_activity_skill;
use bookingextension_agent\local\wizard\course\skills\add_quiz_skill;
use bookingextension_agent\local\wizard\course\skills\analyze_course_structure_skill;
use bookingextension_agent\local\wizard\course\skills\update_activity_skill;
use bookingextension_agent\local\wizard\course\skills\update_quiz_skill;
use bookingextension_agent\local\wizard\question\skills\generate_questions_skill;

/**
 * S6: the course-scoped skills share their cross-context targeting via course_targeted_skill, so all
 * of them expose the identical, correct supports_target_context()/get_target_selector() behaviour.
 *
 * @package    bookingextension_agent
 * @category   test
 * @covers \bookingextension_agent\local\wizard\course_targeted_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class course_targeted_skill_trait_test extends advanced_testcase {
    /**
     * All six course-scoped skills declare cross-context support and resolve a course selector the
     * same way (proving the trait is wired into each).
     */
    public function test_all_course_targeted_skills_use_the_trait(): void {
        $this->resetAfterTest();

        $skills = [
            new add_activity_skill(),
            new update_activity_skill(),
            new add_quiz_skill(),
            new update_quiz_skill(),
            new generate_questions_skill(),
            new analyze_course_structure_skill(),
        ];

        foreach ($skills as $skill) {
            $name = get_class($skill);
            $this->assertTrue($skill->supports_target_context(), "{$name} must support a target context.");

            // No course given -> current context applies (null selector).
            $this->assertNull($skill->get_target_selector([]), "{$name}: empty input must yield no selector.");

            // Courseid wins.
            $byid = $skill->get_target_selector(['courseid' => 7]);
            $this->assertNotNull($byid);
            $this->assertSame(7, $byid->id(), "{$name}: courseid must resolve to the course selector id.");

            // Coursequery is carried through.
            $byquery = $skill->get_target_selector(['coursequery' => 'Algebra 101']);
            $this->assertNotNull($byquery);
            $this->assertSame('Algebra 101', $byquery->query(), "{$name}: coursequery must be carried into the selector.");
        }
    }
}
