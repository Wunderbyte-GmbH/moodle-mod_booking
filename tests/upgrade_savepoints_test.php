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
 * Consistency checks for the version numbers of the steps in db/upgrade.php.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

/**
 * Consistency checks for the version numbers of the steps in db/upgrade.php.
 *
 * Moodle runs a step "if ($oldversion < N)" only on sites whose installed version is below N.
 * A step with a number at or below a version that was already released without it is
 * therefore skipped on every site running that release - silently, without any error.
 *
 * @coversNothing
 */
final class upgrade_savepoints_test extends \basic_testcase {
    /**
     * Every step ends with the savepoint of its own number and the numbers only go up.
     */
    public function test_every_step_has_its_savepoint_in_ascending_order(): void {
        [$steps, $errors] = self::parse_steps(file_get_contents(self::plugin_dir() . '/db/upgrade.php'));

        $this->assertNotEmpty($steps, 'No upgrade steps found in db/upgrade.php - did the code style of the steps change?');
        $this->assertSame([], $errors);
    }

    /**
     * The highest step must not be above the plugin version.
     *
     * A savepoint above version.php leaves the site with a higher version in the database than in
     * the code; and as long as version.php is not raised, Moodle does not run the new step at all.
     * So a new step and the version bump belong into the same commit.
     */
    public function test_highest_step_is_not_above_the_plugin_version(): void {
        [$steps] = self::parse_steps(file_get_contents(self::plugin_dir() . '/db/upgrade.php'));
        $pluginversion = self::plugin_version(file_get_contents(self::plugin_dir() . '/version.php'));

        $this->assertLessThanOrEqual(
            $pluginversion,
            max($steps),
            'The highest upgrade step is above $plugin->version. Raise $plugin->version in version.php'
                . ' to at least the number of the step, in the same commit.'
        );
    }

    /**
     * Steps that are not part of the last release must have a number above the version of that release.
     *
     * The last release is the git tag with the highest $plugin->version, on whatever branch it was
     * made. Needs the git history with its tags, the test is skipped without it.
     */
    public function test_new_steps_are_above_the_last_release(): void {
        $dir = self::plugin_dir();

        // The plugin has to be a git repository of its own, not a directory inside the Moodle repository.
        $toplevel = self::git($dir, 'rev-parse --show-toplevel');
        $tags = self::git($dir, 'tag --list');
        if (empty($toplevel) || realpath($toplevel[0]) !== realpath($dir) || empty($tags)) {
            $this->markTestSkipped('mod_booking is not a git checkout with release tags.');
        }

        // One git call for all tags. Each line reads "<tag>:version.php:$plugin->version = 2026110500;".
        $lines = self::git(
            $dir,
            'grep -E ' . escapeshellarg('^\$plugin->version') . ' '
                . implode(' ', array_map('escapeshellarg', $tags)) . ' -- version.php'
        );
        $releasetag = '';
        $releaseversion = 0;
        foreach ($lines as $line) {
            if (preg_match('/^(.+):version\.php:(.*)$/', $line, $matches)) {
                $version = self::plugin_version($matches[2]);
                if ($version > $releaseversion) {
                    [$releasetag, $releaseversion] = [$matches[1], $version];
                }
            }
        }
        $releasedupgrade = self::git($dir, 'show ' . escapeshellarg($releasetag . ':db/upgrade.php'));
        if ($releasetag === '' || empty($releasedupgrade)) {
            $this->markTestSkipped('Could not read the last release from the git tags.');
        }

        [$steps] = self::parse_steps(file_get_contents($dir . '/db/upgrade.php'));
        [$releasedsteps] = self::parse_steps(implode("\n", $releasedupgrade));
        $newsteps = array_diff($steps, $releasedsteps);
        $toolow = array_filter($newsteps, fn(int $step): bool => $step <= $releaseversion);

        $this->assertSame(
            [],
            array_values($toolow),
            "These upgrade steps are not part of the last release $releasetag ($releaseversion), but their number is"
                . ' not above its version. Sites running that release would skip them. Give each of them a number'
                . " above $releaseversion and raise \$plugin->version in version.php accordingly."
        );
    }

    /**
     * The directory of mod_booking.
     *
     * @return string
     */
    private static function plugin_dir(): string {
        return dirname(__DIR__);
    }

    /**
     * Reads the steps of an upgrade.php.
     *
     * @param string $code content of db/upgrade.php
     * @return array{int[], string[]} the numbers of the steps in file order and the inconsistencies found
     */
    private static function parse_steps(string $code): array {
        preg_match_all(
            '/if \(\$oldversion < (\d+)\)|upgrade_mod_savepoint\(true, (\d+), \'booking\'\)/',
            $code,
            $matches,
            PREG_SET_ORDER
        );

        $steps = [];
        $errors = [];
        $open = null;
        foreach ($matches as $match) {
            if (!isset($match[2])) {
                // Start of a step.
                $step = (int)$match[1];
                if ($open !== null) {
                    $errors[] = "Step $open has no savepoint.";
                }
                if (!empty($steps) && $step <= end($steps)) {
                    $errors[] = "Step $step is not above the step before it (" . end($steps) . ').';
                }
                $steps[] = $step;
                $open = $step;
                continue;
            }
            // Savepoint.
            $savepoint = (int)$match[2];
            if ($open === null) {
                $errors[] = "Savepoint $savepoint does not belong to a step.";
            } else if ($savepoint !== $open) {
                $errors[] = "Step $open ends with the savepoint $savepoint.";
            }
            $open = null;
        }
        if ($open !== null) {
            $errors[] = "Step $open has no savepoint.";
        }

        return [$steps, $errors];
    }

    /**
     * Reads $plugin->version from the code of a version.php.
     *
     * @param string $code content of version.php, or just the line with the version
     * @return int 0 if there is none
     */
    private static function plugin_version(string $code): int {
        return preg_match('/\$plugin->version\s*=\s*(\d+)/', $code, $matches) ? (int)$matches[1] : 0;
    }

    /**
     * Runs a read-only git command in a directory.
     *
     * @param string $dir
     * @param string $arguments already escaped arguments
     * @return string[] the lines of the output, empty if git is not available or the command failed
     */
    private static function git(string $dir, string $arguments): array {
        if (!function_exists('exec')) {
            return [];
        }
        $output = [];
        $exitcode = 1;
        exec('git -C ' . escapeshellarg($dir) . ' ' . $arguments . ' 2>&1', $output, $exitcode);

        return $exitcode === 0 ? $output : [];
    }
}
