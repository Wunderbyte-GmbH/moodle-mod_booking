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
 * Shared JSON payload extraction utilities.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent\local\wizard\services;

/**
 * Provides deterministic JSON candidate extraction from arbitrary text.
 */
class shared_json_payload_extractor {
    /**
     * Extract likely JSON object candidates from raw model text.
     *
     * Handles plain JSON, markdown-fenced JSON blocks and mixed multi-object output.
     *
     * @param string $text
     * @return string[]
     */
    public static function extract_json_candidates(string $text): array {
        $candidates = [];

        $trimmed = trim($text);
        if ($trimmed !== '') {
            $candidates[] = $trimmed;
        }

        if (preg_match_all('/\x60\x60\x60(?:json)?\s*([\s\S]*?)\s*\x60\x60\x60/i', $text, $matches) > 0) {
            foreach (($matches[1] ?? []) as $block) {
                $block = trim((string)$block);
                if ($block !== '') {
                    $candidates[] = $block;
                }
            }
        }

        foreach (self::extract_balanced_json_objects($text) as $json) {
            $candidates[] = $json;
        }

        return array_values(array_unique(array_filter(array_map('trim', $candidates), static function (string $value): bool {
            return $value !== '';
        })));
    }

    /**
     * Extract balanced top-level JSON object snippets from arbitrary text.
     *
     * @param string $text
     * @return string[]
     */
    public static function extract_balanced_json_objects(string $text): array {
        $objects = [];
        $length = strlen($text);
        $depth = 0;
        $start = -1;
        $instring = false;
        $escaped = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];

            if ($instring) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }

                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }

                if ($char === '"') {
                    $instring = false;
                }
                continue;
            }

            if ($char === '"') {
                $instring = true;
                continue;
            }

            if ($char === '{') {
                if ($depth === 0) {
                    $start = $i;
                }
                $depth++;
                continue;
            }

            if ($char === '}') {
                if ($depth > 0) {
                    $depth--;
                    if ($depth === 0 && $start >= 0) {
                        $objects[] = substr($text, $start, $i - $start + 1);
                        $start = -1;
                    }
                }
            }
        }

        return $objects;
    }
}
