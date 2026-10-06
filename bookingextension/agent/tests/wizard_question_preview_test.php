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
use bookingextension_agent\local\wizard\question\skills\generate_questions_skill;
use bookingextension_agent\local\wizard\wizard\skills\forget_skill;
use bookingextension_agent\local\wizard\wizard\skills\recreate_skill_catalog_skill;

/**
 * Tests for the question/wizard pre-confirmation previews (Phase 4).
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\preview_support
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class wizard_question_preview_test extends advanced_testcase {
    /**
     * Map a descriptor's rows into a label => value array.
     *
     * @param array $descriptor
     * @return array<string,string>
     */
    private function rows_map(array $descriptor): array {
        $map = [];
        foreach ($descriptor['rows'] as $row) {
            $map[$row['label']] = $row['value'];
        }
        return $map;
    }

    /**
     * generate_questions previews the plan (not the source content).
     */
    public function test_generate_questions_preview(): void {
        $descriptor = (new generate_questions_skill())->describe_proposed_action([
            'content' => 'Long PDF text that must not appear in the preview.',
            'count' => 10,
            'qtypes' => ['multichoice', 'truefalse'],
            'difficulty' => 'medium',
            'target_category' => 'Mechanics',
        ]);
        $this->assertSame('Generate questions', $descriptor['title']);
        $rows = $this->rows_map($descriptor);
        $this->assertSame('10', $rows['Number of questions']);
        $this->assertStringContainsString('multichoice', $rows['Question types']);
        $this->assertSame('medium', $rows['Difficulty']);
        $this->assertSame('Mechanics', $rows['Category']);
        // Source content is never surfaced.
        $this->assertStringNotContainsString('PDF text', implode(' ', array_values($rows)));
    }

    /**
     * forget previews the deletion target.
     */
    public function test_forget_preview(): void {
        $all = (new forget_skill())->describe_proposed_action(['all' => true]);
        $this->assertSame('Delete memory', $all['title']);
        $this->assertSame('All memories', $this->rows_map($all)['Target']);

        $byquery = (new forget_skill())->describe_proposed_action(['query' => 'my pizza preference']);
        $this->assertSame('my pizza preference', $this->rows_map($byquery)['Target']);
    }

    /**
     * recreate_skill_catalog previews the rebuild with a summary.
     */
    public function test_recreate_catalog_preview(): void {
        $descriptor = (new recreate_skill_catalog_skill())->describe_proposed_action(['force' => true, 'model' => 'text-embed']);
        $this->assertSame('Rebuild skill catalog', $descriptor['title']);
        $this->assertNotSame('', $descriptor['summary']);
        $rows = $this->rows_map($descriptor);
        $this->assertSame(get_string('yes'), $rows['Force full rebuild']);
        $this->assertSame('text-embed', $rows['Model']);
    }
}
