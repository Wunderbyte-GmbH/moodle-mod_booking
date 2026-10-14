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

namespace mod_booking;

use advanced_testcase;
use mod_booking\local\wizard\options\skills\create_option_skill;
use mod_booking\local\wizard\options\skills\create_selflearning_option_skill;

/**
 * The self-learning option carries anchors of its own and the course skill and it fence each other off.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\wizard\options\skills\create_selflearning_option_skill
 */
final class wizard_create_family_anchors_test extends advanced_testcase {
    use \mod_booking\tests\agent_extension_test_trait;

    public function setUp(): void {
        parent::setUp();
        $this->skip_without_agent_extension();
    }

    /**
     * Inherited from the dated option, the example sentences described workshops and lecture series, so a
     * self-paced request ranked the self-learning skill behind the course skill.
     */
    public function test_the_self_learning_option_has_example_sentences_of_its_own(): void {
        $dated = (array)(new create_option_skill())->get_schema()['example_utterances'];
        $selfpaced = (array)(new create_selflearning_option_skill())->get_schema()['example_utterances'];
        $this->assertGreaterThanOrEqual(3, count($selfpaced));
        $this->assertSame([], array_values(array_intersect($selfpaced, $dated)), 'no sentence shared with create_option');
    }

    /**
     * Each of the two neighbours names the other in its NOT line.
     */
    public function test_the_self_learning_option_and_the_course_skill_fence_each_other_off(): void {
        $selfpaced = (string)((new create_selflearning_option_skill())->get_schema()['not'] ?? '');
        $this->assertStringContainsString('course.create_course', $selfpaced);
        $course = new \bookingextension_agent\local\wizard\course\skills\create_course_skill();
        $this->assertStringContainsString('create_selflearning_option', (string)($course->get_schema()['not'] ?? ''));
    }
}
