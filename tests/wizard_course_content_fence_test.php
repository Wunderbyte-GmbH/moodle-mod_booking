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
use core_text;
use mod_booking\local\wizard\options\skills\get_option_details_skill;
use mod_booking\local\wizard\options\skills\search_options_skill;

/**
 * The two option skills that read like a course question on a booking page name the course analysis.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\wizard\options\skills\get_option_details_skill
 * @covers \mod_booking\local\wizard\options\skills\search_options_skill
 */
final class wizard_course_content_fence_test extends advanced_testcase {
    use \mod_booking\tests\agent_extension_test_trait;

    /** @var int Characters the selector card shows of a NOT line. */
    private const NOT_CAP = 160;

    public function setUp(): void {
        parent::setUp();
        $this->skip_without_agent_extension();
    }

    /**
     * "What is in course X" on a booking page ranks the option skills first; their cards hand the course
     * contents to the course analysis so the fence is mutual.
     */
    public function test_the_option_skills_fence_off_the_course_contents(): void {
        foreach ([new get_option_details_skill(), new search_options_skill()] as $skill) {
            $not = (string)($skill->get_schema()['not'] ?? '');

            $this->assertStringContainsString('course.analyze_course_structure', $not, $skill->get_name());
            $this->assertLessThanOrEqual(self::NOT_CAP, core_text::strlen($not), $not);
        }
    }
}
