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

use bookingextension_agent\local\wizard\wizard\skills\search_skills_skill;

/**
 * An empty search query is refused with a language-pack text (wave 32, A3).
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\wizard\skills\search_skills_skill
 */
final class search_skills_check_structure_test extends \advanced_testcase {
    /**
     * The refusal of an empty query is the get_string text, not a hard-coded literal; a real query passes.
     */
    public function test_empty_query_is_refused_with_the_language_pack_text(): void {
        $this->resetAfterTest();
        $skill = new search_skills_skill();

        $empty = $skill->check_structure(['query' => '   ']);
        $this->assertFalse((bool)($empty['valid'] ?? true));
        $this->assertSame(
            [get_string('agent_search_skills_query_required', 'bookingextension_agent')],
            (array)($empty['errors'] ?? [])
        );

        $filled = $skill->check_structure(['query' => 'export']);
        $this->assertTrue((bool)($filled['valid'] ?? false), json_encode($filled));
    }
}
