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
 * Shared empty-placeholder pruner for normalized command input payloads.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent\local\wizard\services;

/**
 * Recursively drops empty placeholders from a normalized input payload.
 *
 * Previously this exact recursion was a private method in both `interpreter` and
 * `parameter_constructor`, which run it on the same payload in the same turn (audit 05-F02).
 * Behaviour (kept verbatim): drop empty/whitespace-only strings and nulls, drop arrays that
 * prune away to empty, and KEEP everything else — numeric `0` and boolean `false` included.
 * Note: non-empty strings are kept unchanged (no trimming) and array keys are preserved.
 */
class input_payload_pruner {
    /**
     * Prune empty placeholders recursively.
     *
     * @param array $input
     * @return array
     */
    public static function prune(array $input): array {
        $cleaned = [];
        foreach ($input as $key => $value) {
            if (is_array($value)) {
                $nested = self::prune($value);
                if (!empty($nested)) {
                    $cleaned[$key] = $nested;
                }
                continue;
            }

            if (is_string($value) && trim($value) === '') {
                continue;
            }
            if ($value === null) {
                continue;
            }
            $cleaned[$key] = $value;
        }

        return $cleaned;
    }
}
