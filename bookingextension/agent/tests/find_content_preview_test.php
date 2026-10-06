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
use bookingextension_agent\local\wizard\core\skills\find_content_skill;

/**
 * find_content must deliver a structured self-contained preview (#2350).
 *
 * Hits render as an escaped list (title-link, course, snippet); file hits (mod/resource)
 * additionally carry a click-to-embed button so the document can be inspected in the pane
 * without leaving the chat. No preview at all was delivered before.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\core\skills\find_content_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class find_content_preview_test extends advanced_testcase {
    /**
     * Hits become an escaped structured preview; resource hits get the embed button.
     */
    public function test_result_preview_renders_hits_and_embed_button(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $entry = [
            'results' => [
                [
                    'title' => 'Otter Handout (PDF)',
                    'url' => 'https://example.com/mod/resource/view.php?id=135',
                    'snippet' => 'FILE: otter-handout.pdf Fischotter jagen <b>Forellen</b>.',
                    'courseid' => 39,
                    'area' => 'mod_resource-activity',
                ],
                [
                    'title' => 'Waschbären sind toll',
                    'url' => 'https://example.com/mod/label/view.php?id=99',
                    'snippet' => 'Waschbären sind toll',
                    'courseid' => 13,
                    'area' => 'mod_label-activity',
                ],
            ],
        ];

        $skill = new find_content_skill();
        $this->assertTrue(
            method_exists($skill, 'get_result_preview'),
            'find_content must participate in the skill preview channel'
        );
        $preview = $skill->get_result_preview($entry, (int)\context_system::instance()->id, 2);

        $this->assertIsArray($preview);
        $this->assertNotSame('', trim((string)($preview['type'] ?? '')));
        $html = (string)($preview['html'] ?? '');
        $this->assertStringContainsString('Otter Handout (PDF)', $html);
        $this->assertStringContainsString('mod/resource/view.php?id=135', $html);
        $this->assertStringContainsString('data-embed-url', $html, 'resource hits need the click-to-embed button');
        $this->assertStringContainsString('&lt;b&gt;', $html, 'snippets must be escaped, never raw HTML');
        $this->assertSame(1, substr_count($html, 'data-embed-url'), 'only file-backed hits get the button');
        $this->assertSame(1, substr_count($html, 'mod/label/view.php?id=99'), 'one entry per document');
    }

    /**
     * Polish pins: chunk hits of one document collapse, the snippet never echoes the title,
     * and a course line identical to the title is suppressed.
     */
    public function test_preview_dedupes_and_strips_title_echo(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $entry = ['results' => [
            ['title' => 'Otter Handout (PDF)', 'url' => 'https://example.com/mod/resource/view.php?id=135',
             'snippet' => 'Otter Handout (PDF) Handout zum Fischotter.', 'courseid' => 0, 'area' => 'mod_resource-activity'],
            ['title' => 'Otter Handout (PDF)', 'url' => 'https://example.com/mod/resource/view.php?id=135',
             'snippet' => 'FILE: otter-handout.pdf Fischotter jagen Forellen.', 'courseid' => 0, 'area' => 'mod_resource-activity'],
        ]];
        $html = (string)((new find_content_skill())->get_result_preview($entry, 1, 2)['html'] ?? '');

        $this->assertSame(1, substr_count($html, 'data-embed-url'), 'same document must collapse to one entry');
        $this->assertStringNotContainsString('Otter Handout (PDF) Handout', $html, 'no title echo in the snippet');
        $this->assertStringContainsString('Handout zum Fischotter.', $html);
    }

    /**
     * No hits — no preview block.
     */
    public function test_empty_results_yield_no_preview(): void {
        $this->resetAfterTest();
        $skill = new find_content_skill();
        if (!method_exists($skill, 'get_result_preview')) {
            $this->markTestSkipped('method landed with the fix');
        }
        $this->assertNull($skill->get_result_preview(['results' => []], 1, 2));
    }
}
