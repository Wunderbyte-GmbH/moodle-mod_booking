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

/**
 * Tests for the documentation viewer.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\documentation;

/**
 * Tests for {@see \mod_booking\local\documentation\documentation_viewer}.
 *
 * @covers \mod_booking\local\documentation\documentation_viewer
 */
final class documentation_viewer_test extends \advanced_testcase {
    /**
     * Normalization collapses "." and ".." and rejects paths escaping the root.
     *
     * @dataProvider normalize_path_provider
     * @param string $input
     * @param string|null $expected
     */
    public function test_normalize_path(string $input, ?string $expected): void {
        $this->assertSame($expected, documentation_viewer::normalize_path($input));
    }

    /**
     * Data provider for {@see test_normalize_path}.
     *
     * @return array<string, array{string, string|null}>
     */
    public static function normalize_path_provider(): array {
        return [
            'plain file' => ['README.md', 'README.md'],
            'nested file' => ['user/shortcodes/README.md', 'user/shortcodes/README.md'],
            'current dir segments' => ['./user/./README.md', 'user/README.md'],
            'parent inside root' => ['user/shortcodes/../README.md', 'user/README.md'],
            'multiple slashes' => ['user//README.md', 'user/README.md'],
            'backslashes' => ['user\\README.md', 'user/README.md'],
            'escapes root' => ['../version.php', null],
            'escapes root deeply' => ['user/../../lib.php', null],
            'escapes and returns' => ['../booking/docs/README.md', null],
        ];
    }

    /**
     * Valid files resolve to their type, everything else throws.
     */
    public function test_resolve(): void {
        $resolved = documentation_viewer::resolve('README.md');
        $this->assertSame(documentation_viewer::TYPE_MARKDOWN, $resolved['type']);
        $this->assertSame(realpath(documentation_viewer::docs_root() . '/README.md'), $resolved['path']);

        $resolved = documentation_viewer::resolve('user/examples/import_minimal.csv');
        $this->assertSame(documentation_viewer::TYPE_DOWNLOAD, $resolved['type']);

        foreach (
            [
                'traversal' => '../version.php',
                'deep traversal' => 'user/../../lib.php',
                'absolute path' => '/etc/passwd',
                'wrong extension' => 'user/examples/import_minimal.txt',
                'missing file' => 'user/doesnotexist.md',
                'directory' => 'user',
                'php file' => '../lib.php',
            ] as $label => $badpath
        ) {
            try {
                documentation_viewer::resolve($badpath);
                $this->fail("Path '{$badpath}' ({$label}) must be rejected.");
            } catch (\moodle_exception $e) {
                $this->assertSame('invaliddocumentationfile', $e->errorcode, $label);
            }
        }
    }

    /**
     * Rendering returns the first heading as title and rewrites the links.
     */
    public function test_render_page(): void {
        $page = documentation_viewer::render_page('README.md');
        $this->assertNotEmpty($page['title']);
        $this->assertStringContainsString('documentation.php', $page['html']);
    }

    /**
     * Relative links and images become viewer URLs, external targets and anchors stay.
     */
    public function test_rewrite_html(): void {
        $html = '<p><a href="../README.md">up</a>'
            . '<a href="rule-types.md#anchor">sibling</a>'
            . '<a href="../../developer-guides/">dir</a>'
            . '<a href="../../../../evil.md">escaping</a>'
            . '<a href="https://example.com/page.md">external</a>'
            . '<a href="#local">anchor</a>'
            . '<img src="pix/overview.png" alt="x"></p>';
        $rewritten = documentation_viewer::rewrite_html($html, 'user/booking_rules');

        $this->assertStringContainsString('documentation.php?file=user%2FREADME.md', $rewritten);
        $this->assertStringContainsString('documentation.php?file=user%2Fbooking_rules%2Frule-types.md', $rewritten);
        $this->assertStringContainsString('rule-types.md#anchor', $rewritten);
        $this->assertStringContainsString('documentation.php?file=developer-guides%2FREADME.md', $rewritten);
        // A link escaping the docs folder is clamped to the index.
        $this->assertStringContainsString('documentation.php?file=README.md', $rewritten);
        $this->assertStringContainsString('href="https://example.com/page.md"', $rewritten);
        $this->assertStringContainsString('href="#local"', $rewritten);
        $this->assertStringContainsString('documentation.php?file=user%2Fbooking_rules%2Fpix%2Foverview.png', $rewritten);
        $this->assertStringContainsString('img class="img-fluid"', $rewritten);
    }
}
