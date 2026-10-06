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

namespace bookingextension_agent\local\wizard\services;

/**
 * Skill version policy evaluator for layer-1 preflight checks.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class skill_version_policy {
    /** @var string Version is supported and can execute. */
    public const STATUS_SUPPORTED = 'supported';

    /** @var string Version is accepted but should be surfaced as deprecated. */
    public const STATUS_DEPRECATED = 'deprecated';

    /** @var string Version is not supported and must hard-block. */
    public const STATUS_UNSUPPORTED = 'unsupported';

    /** @var string Standard issue code for unsupported skill version. */
    public const ISSUE_UNSUPPORTED = 'SKILL_VERSION_UNSUPPORTED';

    /** @var string Standard issue code for deprecated skill version. */
    public const ISSUE_DEPRECATED = 'SKILL_VERSION_DEPRECATED';

    /**
     * Evaluate a concrete skill version against normalized skill metadata.
     *
     * @param array $skillcontract
     * @param int $requestedversion
     * @return array{status:string,issue_codes:string[],supported_version:int,min_supported_version:int}
     */
    public function evaluate(array $skillcontract, int $requestedversion): array {
        $supportedversion = max(1, (int)($skillcontract['version'] ?? 1));
        $minsupportedversion = max(1, (int)($skillcontract['min_supported_version'] ?? $supportedversion));

        if ($requestedversion < $minsupportedversion || $requestedversion > $supportedversion) {
            return [
                'status' => self::STATUS_UNSUPPORTED,
                'issue_codes' => [self::ISSUE_UNSUPPORTED],
                'supported_version' => $supportedversion,
                'min_supported_version' => $minsupportedversion,
            ];
        }

        if ($this->is_deprecated($skillcontract, $requestedversion)) {
            return [
                'status' => self::STATUS_DEPRECATED,
                'issue_codes' => [self::ISSUE_DEPRECATED],
                'supported_version' => $supportedversion,
                'min_supported_version' => $minsupportedversion,
            ];
        }

        return [
            'status' => self::STATUS_SUPPORTED,
            'issue_codes' => [],
            'supported_version' => $supportedversion,
            'min_supported_version' => $minsupportedversion,
        ];
    }

    /**
     * Determine whether a requested version should be marked deprecated.
     *
     * @param array $skillcontract
     * @param int $requestedversion
     * @return bool
     */
    private function is_deprecated(array $skillcontract, int $requestedversion): bool {
        $deprecatedversions = [];
        if (is_array($skillcontract['deprecated_versions'] ?? null)) {
            foreach ((array)$skillcontract['deprecated_versions'] as $version) {
                $candidate = (int)$version;
                if ($candidate > 0) {
                    $deprecatedversions[] = $candidate;
                }
            }
        }

        if (in_array($requestedversion, $deprecatedversions, true)) {
            return true;
        }

        return trim((string)($skillcontract['deprecated_since'] ?? '')) !== '';
    }
}
