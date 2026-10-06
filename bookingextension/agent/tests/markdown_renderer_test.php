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
use bookingextension_agent\local\wizard\services\lookup\markdown_renderer;

/**
 * Characterization tests for the markdown renderer extracted from ai_get_doc_content (S7).
 *
 * Locks the rendered-HTML behaviour so the move stays byte-faithful and future edits are guarded.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\services\lookup\markdown_renderer
 */
final class markdown_renderer_test extends advanced_testcase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }
    /**
     * Render a fragment with no module context (contextid 0).
     *
     * @param string $md
     * @return string
     */
    private function render(string $md): string {
        return markdown_renderer::render($md, 'guide/intro.md', 0, 'mod_booking');
    }

    /**
     * Headings become id'd, class'd h1–h4 tags.
     */
    public function test_headings(): void {
        $html = $this->render("# Title One\n\n## Section Two\n");
        $this->assertStringContainsString('<h1 id="doc-title-one" class="booking-doc-h1">Title One</h1>', $html);
        $this->assertStringContainsString('<h2 id="doc-section-two" class="booking-doc-h2">Section Two</h2>', $html);
    }

    /**
     * Bold, italic and inline code spans.
     */
    public function test_inline_formatting(): void {
        // phpcs:ignore moodle.Strings.ForbiddenStrings.Found
        $html = $this->render("Some **bold** and *italic* and `code` here.\n");
        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('<em>italic</em>', $html);
        $this->assertStringContainsString('<code>code</code>', $html);
        $this->assertStringContainsString('class="booking-doc-p"', $html);
    }

    /**
     * Fenced code blocks are escaped and wrapped in pre/code with a language class.
     */
    public function test_fenced_code_block(): void {
        $md = "```php\n<?php echo 'hi';\n```\n"; // phpcs:ignore moodle.Strings.ForbiddenStrings.Found
        $html = $this->render($md);
        $this->assertStringContainsString('<pre class="booking-doc-code"><code class="language-php">', $html);
        // Content is HTML-escaped (no raw tags can be injected).
        $this->assertStringContainsString('&lt;?php', $html);
        $this->assertStringNotContainsString("<?php echo 'hi';", $html);
    }

    /**
     * Unordered and ordered lists.
     */
    public function test_lists(): void {
        $ul = $this->render("- one\n- two\n");
        $this->assertStringContainsString('<ul class="booking-doc-list">', $ul);
        $this->assertStringContainsString('<li>one</li>', $ul);

        $ol = $this->render("1. first\n2. second\n");
        $this->assertStringContainsString('<ol class="booking-doc-list">', $ol);
        $this->assertStringContainsString('<li>first</li>', $ol);
    }

    /**
     * GFM pipe tables render header + body rows; the separator row is dropped.
     */
    public function test_table_and_hr(): void {
        $md = "| A | B |\n| - | - |\n| 1 | 2 |\n\n---\n";
        $html = $this->render($md);
        $this->assertStringContainsString('<table class="table table-sm table-bordered booking-doc-table">', $html);
        $this->assertStringContainsString('<th>A</th>', $html);
        $this->assertStringContainsString('<td>1</td>', $html);
        $this->assertStringContainsString('<hr>', $html);
    }

    /**
     * External links open in a new tab; relative .md links become internal preview anchors.
     */
    public function test_links(): void {
        $external = $this->render("See [docs](https://example.com/x).\n");
        $this->assertStringContainsString(
            '<a href="https://example.com/x" target="_blank" rel="noopener noreferrer">docs</a>',
            $external
        );

        $internal = $this->render("See [rules](rules.md#section).\n");
        $this->assertStringContainsString('class="booking-doc-link"', $internal);
        $this->assertStringContainsString('data-docpath="guide/rules.md"', $internal);
        $this->assertStringContainsString('data-docfragment="section"', $internal);
        $this->assertStringContainsString('data-corpusid="mod_booking"', $internal);
    }

    /**
     * Raw HTML in plain text is escaped — no script injection is possible.
     */
    public function test_escapes_raw_html(): void {
        $html = $this->render("A <script>alert(1)</script> line.\n");
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
