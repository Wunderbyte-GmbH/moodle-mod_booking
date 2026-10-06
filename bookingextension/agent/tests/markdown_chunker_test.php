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
use bookingextension_agent\local\wizard\services\lookup\markdown_chunker;

/**
 * Tests for the markdown chunker (Phase C1).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\services\lookup\markdown_chunker
 */
final class markdown_chunker_test extends advanced_testcase {
    /**
     * A short single-section document yields exactly one chunk spanning the whole file.
     */
    public function test_single_section(): void {
        $md = "# Title\n\nSome body text.\n";
        $chunks = markdown_chunker::chunk($md);

        $this->assertCount(1, $chunks);
        $this->assertSame('Title', $chunks[0]['title']);
        $this->assertSame(1, $chunks[0]['line_start']);
        $this->assertSame(4, $chunks[0]['line_end']);
        $this->assertSame($md, $chunks[0]['text']);
    }

    /**
     * Level-2 headings start new chunks with their own titles and contiguous, non-overlapping ranges.
     */
    public function test_splits_on_headings(): void {
        $md = "# Doc\n\nIntro.\n\n## Alpha\n\nAlpha body.\n\n## Bravo\n\nBravo body.\n";
        $chunks = markdown_chunker::chunk($md);

        $this->assertCount(3, $chunks);
        $this->assertSame('Doc', $chunks[0]['title'], 'Preamble keeps the H1 title.');
        $this->assertSame('Alpha', $chunks[1]['title']);
        $this->assertSame('Bravo', $chunks[2]['title']);

        // Ranges are contiguous and cover the whole document.
        $this->assertSame(1, $chunks[0]['line_start']);
        $this->assertSame($chunks[0]['line_end'] + 1, $chunks[1]['line_start']);
        $this->assertSame($chunks[1]['line_end'] + 1, $chunks[2]['line_start']);

        // Every section's body is captured.
        $this->assertStringContainsString('Alpha body.', $chunks[1]['text']);
        $this->assertStringContainsString('Bravo body.', $chunks[2]['text']);
    }

    /**
     * An oversized section is split on the size budget; the whole content is still covered, beyond
     * the legacy 6000-char truncation.
     */
    public function test_oversized_section_split_by_budget(): void {
        $body = [];
        for ($i = 1; $i <= 400; $i++) {
            $body[] = "Line number {$i} with some filler words to add length.";
        }
        $md = "# Big\n\n" . implode("\n", $body) . "\n";
        $this->assertGreaterThan(6000, strlen($md));

        $chunks = markdown_chunker::chunk($md, 1000);

        $this->assertGreaterThan(1, count($chunks), 'A large section must be split into multiple chunks.');

        // Continuation chunks inherit the section title.
        foreach ($chunks as $chunk) {
            $this->assertSame('Big', $chunk['title']);
        }

        // The last line of the document is present in the final chunk (no truncation).
        $this->assertStringContainsString('Line number 400', $chunks[count($chunks) - 1]['text']);

        // Ranges are contiguous and cover line 1 through the last line.
        $expectednext = 1;
        foreach ($chunks as $chunk) {
            $this->assertSame($expectednext, $chunk['line_start']);
            $expectednext = $chunk['line_end'] + 1;
        }
    }

    /**
     * Whitespace-only content produces no chunks.
     */
    public function test_whitespace_only_yields_no_chunks(): void {
        $this->assertSame([], markdown_chunker::chunk("\n\n   \n"));
    }
}
