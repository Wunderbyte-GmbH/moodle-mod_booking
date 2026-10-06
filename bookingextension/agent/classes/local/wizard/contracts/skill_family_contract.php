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

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\contracts;

use core_text;
use bookingextension_agent\local\wizard\skill_contract_validator;

/**
 * Family-level contract helper for skill and prompt metadata.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class skill_family_contract {
    /** @var string Fallback family name when no valid family can be derived. */
    public const DEFAULT_FAMILY = 'wizard.general';

    /**
     * Derive a deterministic family from a skill name namespace.
     *
     * @param string $skillname
     * @return string
     */
    public static function from_skill_name(string $skillname): string {
        $namespace = skill_contract_validator::extract_skill_namespace($skillname);
        if ($namespace === '') {
            return self::DEFAULT_FAMILY;
        }

        return $namespace . '.general';
    }

    /**
     * Resolve and normalize family from prompt contract payload.
     *
     * @param array $promptcontract
     * @param string $skillname
     * @return string
     */
    public static function resolve_from_prompt_contract(array $promptcontract, string $skillname): string {
        $candidate = trim((string)($promptcontract['family'] ?? ''));
        if ($candidate !== '' && self::is_valid_family($candidate)) {
            return self::normalize_family($candidate);
        }

        return self::normalize_family(self::from_skill_name($skillname));
    }

    /**
     * Validate family format: <namespace>.<family>.
     *
     * @param string $family
     * @return bool
     */
    public static function is_valid_family(string $family): bool {
        $family = trim(core_text::strtolower($family));
        return preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $family) === 1;
    }

    /**
     * Normalize family string and fail closed to default family when invalid.
     *
     * @param string $family
     * @return string
     */
    public static function normalize_family(string $family): string {
        $family = trim(core_text::strtolower($family));
        if (!self::is_valid_family($family)) {
            return self::DEFAULT_FAMILY;
        }

        return $family;
    }
}
