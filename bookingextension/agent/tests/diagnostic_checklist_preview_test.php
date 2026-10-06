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
use moodle_url;
use bookingextension_agent\local\wizard\diagnostics\diagnostic_checklist_preview;

/**
 * Tests for the shared diagnostic checklist preview builder.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\diagnostics\diagnostic_checklist_preview
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class diagnostic_checklist_preview_test extends advanced_testcase {
    /**
     * Rows render into a self-contained preview data block.
     */
    public function test_render_rows(): void {
        $rows = [
            ['status' => 'ok', 'check' => 'Enrolled in course', 'finding' => 'since 01.09.'],
            [
                'status' => 'fail',
                'check' => 'Activity visible',
                'finding' => 'hidden until Quiz 2',
                'url' => new moodle_url('/mod/quiz/view.php', ['id' => 3]),
            ],
            ['status' => 'warn', 'check' => 'Group membership', 'finding' => 'no group'],
        ];
        $preview = (new diagnostic_checklist_preview())->render($rows, 'Access check', ['userid' => 5]);

        $this->assertIsArray($preview);
        $this->assertSame('diagnostic_checklist', $preview['type']);
        $this->assertSame(3, $preview['payload']['rowcount']);
        $this->assertSame(5, $preview['payload']['userid']);
        $this->assertStringContainsString('Enrolled in course', $preview['html']);
        $this->assertStringContainsString('Access check', $preview['html']);
        $this->assertStringContainsString('/mod/quiz/view.php', $preview['html']);
        // Status glyphs present.
        $this->assertStringContainsString('✓', $preview['html']);
        $this->assertStringContainsString('✗', $preview['html']);
        $this->assertStringContainsString('⚠', $preview['html']);
    }

    /**
     * No renderable rows yields null (nothing to preview).
     */
    public function test_empty_returns_null(): void {
        $builder = new diagnostic_checklist_preview();
        $this->assertNull($builder->render([]));
        $this->assertNull($builder->render([['status' => 'ok', 'finding' => 'no check label']]));
    }

    /**
     * An unknown status degrades to a warning rather than breaking.
     */
    public function test_unknown_status_degrades(): void {
        $preview = (new diagnostic_checklist_preview())->render([
            ['status' => 'bogus', 'check' => 'Some check'],
        ]);
        $this->assertIsArray($preview);
        $this->assertStringContainsString('⚠', $preview['html']);
    }
}
