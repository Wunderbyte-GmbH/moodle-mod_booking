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
 * Shared normalization of finalization issue codes.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services;

/**
 * Canonicalizes issue codes (uppercase, trimmed, de-duplicated) so the finalization classifier and
 * synchronizer compare them consistently. Previously copied across four services.
 */
class issue_code_normalizer {
    /**
     * Normalize a list of issue codes: uppercase, trim, drop empties, de-duplicate (order kept).
     *
     * @param mixed[] $codes
     * @return string[]
     */
    public static function normalize(array $codes): array {
        $normalized = [];
        foreach ($codes as $code) {
            $value = strtoupper(trim((string)$code));
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Normalize the issue codes carried on a result/sync payload under the `issue_codes` key.
     *
     * A non-array `issue_codes` value yields an empty list (it is not coerced) — matching the
     * historical finalization behavior.
     *
     * @param array $result
     * @return string[]
     */
    public static function from_result(array $result): array {
        $raw = $result['issue_codes'] ?? [];
        return is_array($raw) ? self::normalize($raw) : [];
    }
}
