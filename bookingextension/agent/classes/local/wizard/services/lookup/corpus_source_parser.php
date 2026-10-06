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
 * Parser for the documentation corpus textarea setting.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\lookup;

/**
 * Turns the admin "documentation corpora" textarea into corpus definitions.
 *
 * One line per corpus. The source is a local file path OR a Moodle component (optionally with a
 * sub-directory); no remote URLs. Line syntax:
 *
 *   # comment / blank line — ignored
 *   bookingextension_agent          → component → …/bookingextension_agent/docs (corpus_id = component)
 *   mod_booking                     → component → …/mod/booking/docs
 *   quizdocs = mod/quiz/docs        → explicit corpus_id + dirroot-relative path
 *   intern  = /var/www/.../docs     → explicit corpus_id + absolute path (must be under dirroot)
 *
 * The result separates two sets (the §3 "declared ≠ resolvable" invariant):
 *  - declared:   every syntactically valid corpus_id, independent of whether its root exists right
 *                now. Prune decisions (B1) measure "superfluous" against THIS set, so a declared but
 *                momentarily unreadable corpus is never deleted.
 *  - resolvable: corpus_id => absolute root, only where the root exists AND lies under $CFG->dirroot.
 *
 * Security (E2): no path may escape $CFG->dirroot. A line whose target is outside the platform is
 * a hard reject (collected in `warnings`) and appears in neither set. A line inside dirroot whose
 * directory is merely missing is a soft `notice` and stays declared.
 */
class corpus_source_parser {
    /**
     * Parse the textarea content into corpus definitions.
     *
     * @param string $textarea Raw setting value (one corpus per line).
     * @return array{declared: string[], resolvable: array<string,string>, warnings: string[], notices: string[]}
     */
    public static function parse(string $textarea): array {
        $declared = [];
        $resolvable = [];
        $warnings = [];
        $notices = [];
        // Tracks which raw token first claimed a corpus_id, for collision diagnostics.
        $claimedby = [];

        foreach (preg_split('/\R/', $textarea) ?: [] as $rawline) {
            $line = self::strip_comment($rawline);
            if ($line === '') {
                continue;
            }

            [$corpusid, $source] = self::split_line($line);
            if ($source === '') {
                $warnings[] = get_string('aidocsroot_warn_emptysource', 'bookingextension_agent', $line);
                continue;
            }

            $candidate = self::candidate_path($source);
            if ($candidate === null) {
                $warnings[] = get_string('aidocsroot_warn_unresolvable', 'bookingextension_agent', $line);
                continue;
            }

            // E2 hard confinement: the intended target must lie within $CFG->dirroot, even when it
            // does not exist yet (checked lexically so a missing dir is not silently allowed out).
            if (!self::is_within_dirroot($candidate)) {
                $warnings[] = get_string('aidocsroot_warn_outside', 'bookingextension_agent', $line);
                continue;
            }

            if ($corpusid === '') {
                $corpusid = self::derive_corpus_id($source, $candidate);
            }
            $corpusid = self::normalize_corpus_id($corpusid);
            if ($corpusid === '') {
                $warnings[] = get_string('aidocsroot_warn_emptycorpusid', 'bookingextension_agent', $line);
                continue;
            }

            // First declaration of a corpus_id wins; later collisions are reported and dropped.
            if (isset($claimedby[$corpusid])) {
                $warnings[] = get_string('aidocsroot_warn_collision', 'bookingextension_agent', (object)[
                    'corpusid' => $corpusid,
                    'line' => $line,
                ]);
                continue;
            }
            $claimedby[$corpusid] = $line;

            $declared[] = $corpusid;

            // Resolvable only when the directory actually exists and realpath stays under dirroot.
            $real = is_dir($candidate) ? realpath($candidate) : false;
            if ($real !== false && self::is_within_dirroot($real)) {
                $resolvable[$corpusid] = rtrim($real, '/\\');
            } else {
                $notices[] = get_string('aidocsroot_notice_missing', 'bookingextension_agent', (object)[
                    'corpusid' => $corpusid,
                    'path' => $candidate,
                ]);
            }
        }

        return [
            'declared' => array_values(array_unique($declared)),
            'resolvable' => $resolvable,
            'warnings' => $warnings,
            'notices' => $notices,
        ];
    }

    /**
     * Strip a trailing/whole-line comment and surrounding whitespace.
     *
     * @param string $rawline
     * @return string
     */
    private static function strip_comment(string $rawline): string {
        $hash = strpos($rawline, '#');
        if ($hash !== false) {
            $rawline = substr($rawline, 0, $hash);
        }
        return trim($rawline);
    }

    /**
     * Split a line into [corpus_id, source]; corpus_id is empty when not given via "=".
     *
     * @param string $line
     * @return array{0:string,1:string}
     */
    private static function split_line(string $line): array {
        $eq = strpos($line, '=');
        if ($eq === false) {
            return ['', trim($line)];
        }
        return [trim(substr($line, 0, $eq)), trim(substr($line, $eq + 1))];
    }

    /**
     * Resolve a source token to an intended absolute path (not yet existence-checked).
     *
     * Resolution order:
     *  1. absolute path (starts with "/") → used as-is,
     *  2. bare frankenstyle component (no "/") → its directory + "/docs",
     *  3. otherwise → a path relative to $CFG->dirroot.
     *
     * @param string $source
     * @return string|null Absolute candidate path, or null when it cannot be formed.
     */
    private static function candidate_path(string $source): ?string {
        global $CFG;

        $source = str_replace('\\', '/', trim($source));
        if ($source === '') {
            return null;
        }

        if ($source[0] === '/') {
            return self::normalize_absolute($source);
        }

        if (strpos($source, '/') === false) {
            $dir = \core_component::get_component_directory($source);
            if ($dir !== null) {
                return self::normalize_absolute(rtrim($dir, '/\\') . '/docs');
            }
        }

        return self::normalize_absolute(rtrim($CFG->dirroot, '/\\') . '/' . ltrim($source, '/'));
    }

    /**
     * Derive a corpus_id when the line did not specify one.
     *
     * @param string $source    Raw source token.
     * @param string $candidate Resolved absolute candidate path.
     * @return string
     */
    private static function derive_corpus_id(string $source, string $candidate): string {
        $source = str_replace('\\', '/', trim($source));
        // A bare component name maps directly onto its own id.
        if (strpos($source, '/') === false && \core_component::get_component_directory($source) !== null) {
            return $source;
        }
        return basename(rtrim($candidate, '/\\'));
    }

    /**
     * Normalize a corpus_id to [a-z0-9_].
     *
     * @param string $corpusid
     * @return string
     */
    private static function normalize_corpus_id(string $corpusid): string {
        $corpusid = strtolower(trim($corpusid));
        $corpusid = (string)preg_replace('/[^a-z0-9_]+/', '_', $corpusid);
        return trim($corpusid, '_');
    }

    /**
     * Lexically normalize an absolute path, collapsing "." and ".." without touching the filesystem.
     *
     * @param string $path
     * @return string
     */
    private static function normalize_absolute(string $path): string {
        $path = str_replace('\\', '/', $path);
        $isabsolute = isset($path[0]) && $path[0] === '/';
        $segments = explode('/', $path);
        $out = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $segment;
        }
        return ($isabsolute ? '/' : '') . implode('/', $out);
    }

    /**
     * Whether a (lexically normalized) absolute path lies within $CFG->dirroot.
     *
     * @param string $path
     * @return bool
     */
    private static function is_within_dirroot(string $path): bool {
        global $CFG;

        $root = self::normalize_absolute(str_replace('\\', '/', $CFG->dirroot));
        $path = self::normalize_absolute(str_replace('\\', '/', $path));

        return $path === $root || str_starts_with($path, $root . '/');
    }
}
