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
 * Link integrity of the user documentation.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;

/**
 * Every relative link between the pages of docs/user stays inside docs/user and reaches a page.
 *
 * docs/user is the documentation corpus the booking assistant reads and previews. The preview
 * follows a relative .md link only while its target lies inside the corpus; a link that leaves it
 * is opened relative to the Moodle page instead (e.g. "../../../README.md" became the site's
 * /README.md). Pages outside the corpus are linked with an absolute URL.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class docs_user_links_test extends advanced_testcase {
    /**
     * Each relative .md link of a docs/user page resolves to an existing page inside docs/user.
     */
    public function test_relative_doc_links_stay_inside_the_corpus(): void {
        global $CFG;

        $root = realpath($CFG->dirroot . '/mod/booking/docs/user');
        $this->assertNotFalse($root, 'docs/user is missing.');

        $problems = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (strtolower($file->getExtension()) !== 'md') {
                continue;
            }
            $page = $file->getPathname();
            // Fenced code blocks show markdown verbatim; their links are no links.
            $fence = str_repeat(chr(96), 3);
            $content = preg_replace('/^' . $fence . '.*?^' . $fence . '/ms', '', (string) file_get_contents($page));
            preg_match_all('/\]\(([^)\s]+)\)/', $content, $matches);
            foreach ($matches[1] as $href) {
                if (preg_match('/^([a-z][a-z0-9+.-]*:|#|\/)/i', $href)) {
                    continue;
                }
                $target = explode('#', $href)[0];
                if (strtolower(pathinfo($target, PATHINFO_EXTENSION)) !== 'md') {
                    continue;
                }
                $resolved = realpath(dirname($page) . '/' . $target);
                $relpage = substr($page, strlen($root) + 1);
                if ($resolved === false) {
                    $problems[] = "{$relpage}: {$href} does not exist";
                } else if (strpos($resolved, $root . DIRECTORY_SEPARATOR) !== 0) {
                    $problems[] = "{$relpage}: {$href} leaves docs/user";
                }
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }
}
