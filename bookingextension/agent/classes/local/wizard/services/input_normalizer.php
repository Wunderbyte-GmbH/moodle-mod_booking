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
 * Shared, option-driven input normalizer.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent\local\wizard\services;

use core_text;

/**
 * Drops noise keys from a command input and recursively normalizes its values.
 *
 * Previously two services each carried a near-identical `normalize_input`/`normalize_value`
 * pair that had begun to drift (audit 03-F03): the **compact** variant (for the
 * SYSTEM_RUNTIME.completed_commands prompt blob) caps strings/lists and drops empties; the
 * **signature** variant (for the deterministic dedupe signature) `ksort`s for order-stability
 * and keeps everything. Those are genuinely different transforms, so this helper keeps BOTH
 * behaviours exactly and exposes the difference as explicit options — no behaviour changes.
 *
 * Options (all optional):
 *  - `dropkeys`  string[]  top-level keys to drop entirely (e.g. sesskey/lang).
 *  - `ksort`     bool      sort map keys (top level and nested maps) for a stable signature.
 *  - `capstring` int|null  trim strings, drop now-empty ones, and cap length to N chars.
 *  - `caplist`   int|null  keep at most N entries per array.
 *  - `dropempty` bool      drop values that normalize away (null / empty string / empty array);
 *                          with this on, dropped list entries are re-indexed so no gaps remain.
 */
class input_normalizer {
    /**
     * Normalize a top-level input map.
     *
     * @param array $input
     * @param array $opts See the class docblock.
     * @return array
     */
    public static function normalize(array $input, array $opts): array {
        $dropkeys = (array)($opts['dropkeys'] ?? []);
        $ksort = !empty($opts['ksort']);
        $dropempty = !empty($opts['dropempty']);

        $normalized = [];
        foreach ($input as $key => $value) {
            if (!is_string($key) || $key === '' || in_array($key, $dropkeys, true)) {
                continue;
            }
            $clean = self::normalize_value($value, $opts);
            if ($dropempty && $clean === null) {
                continue;
            }
            $normalized[$key] = $clean;
        }

        if ($ksort) {
            ksort($normalized);
        }

        return $normalized;
    }

    /**
     * Normalize one value recursively.
     *
     * Returns null to signal "drop" only when `dropempty` is on (empty string / empty array);
     * a literal null value is preserved unless `dropempty` drops it at the parent.
     *
     * @param mixed $value
     * @param array $opts
     * @return mixed|null
     */
    private static function normalize_value($value, array $opts) {
        $ksort = !empty($opts['ksort']);
        $dropempty = !empty($opts['dropempty']);
        $capstring = $opts['capstring'] ?? null;
        $caplist = $opts['caplist'] ?? null;

        if (is_string($value)) {
            if ($capstring !== null) {
                $trimmed = trim($value);
                if ($trimmed === '') {
                    return null;
                }
                return core_text::substr($trimmed, 0, (int)$capstring);
            }
            return $value;
        }

        if (is_null($value) || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_array($value)) {
            if ($ksort && !array_is_list($value)) {
                ksort($value);
            }

            $out = [];
            $count = 0;
            foreach ($value as $k => $v) {
                if ($caplist !== null && $count >= (int)$caplist) {
                    break;
                }

                $nv = self::normalize_value($v, $opts);
                if ($dropempty && $nv === null) {
                    continue;
                }

                if (is_string($k)) {
                    $out[$k] = $nv;
                } else if ($dropempty) {
                    // Re-index numeric keys so dropped entries leave no gaps.
                    $out[] = $nv;
                } else {
                    $out[$k] = $nv;
                }
                $count++;
            }

            if ($dropempty && empty($out)) {
                return null;
            }

            return $out;
        }

        return $dropempty ? null : $value;
    }
}
