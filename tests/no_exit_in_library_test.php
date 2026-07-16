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
 * Guards against exit()/die() in library code.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use basic_testcase;

/**
 * Guards against exit()/die() in library code.
 *
 * exit()/die() terminates PHP with exit code 0, so a PHPUnit run that hits one
 * aborts silently and looks green in CI. Library code must throw exceptions
 * instead; only page scripts (view.php etc.) may legitimately exit.
 *
 * @package mod_booking
 * @coversNothing
 */
final class no_exit_in_library_test extends basic_testcase {
    /**
     * Known exit() calls in library code that are proven unreachable under PHPUnit
     * (relative path => allowed count). Do not add entries without such a proof.
     */
    private const ALLOWED = [
        // Browser-only signin sheet download: streams the file and exits, standard Moodle download pattern.
        'classes/signinsheet/signinsheet_generator.php' => 1,
    ];

    /**
     * No exit()/die() in code that can run during a PHPUnit run.
     */
    public function test_library_code_contains_no_exit_or_die(): void {
        $root = dirname(__DIR__);

        $files = [$root . '/lib.php'];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/classes', \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        $violations = [];
        foreach ($files as $path) {
            $relpath = str_replace('\\', '/', substr($path, strlen($root) + 1));
            $lines = file($path);
            $count = 0;
            foreach (token_get_all(file_get_contents($path)) as $token) {
                if (!is_array($token) || $token[0] !== T_EXIT) {
                    continue;
                }
                // The standard "defined('MOODLE_INTERNAL') || die();" file guard only
                // fires when the file is loaded outside of Moodle - that one is fine.
                $context = ($lines[$token[2] - 2] ?? '') . ($lines[$token[2] - 1] ?? '');
                if (strpos($context, 'MOODLE_INTERNAL') !== false) {
                    continue;
                }
                $count++;
                if ($count > (self::ALLOWED[$relpath] ?? 0)) {
                    $violations[] = $relpath . ':' . $token[2];
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            'exit()/die() found in library code. It terminates PHP with exit code 0, '
            . 'so an aborted PHPUnit run passes CI unnoticed. Throw a moodle_exception instead.'
        );
    }
}
