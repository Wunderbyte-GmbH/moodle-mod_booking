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

namespace bookingextension_agent\local\wizard\config;

use core_text;

/**
 * Central runtime feature flags for incremental architecture migration.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class runtime_feature_flags {
    /** @var string Plugin config component key. */
    private const COMPONENT = 'bookingextension_agent';

    /** @var string Enables family-level discovery path integration. */
    public const FAMILY_DISCOVERY_ENABLED = 'family_discovery_enabled';

    /** @var string Enables staged discovery routing (A/B/C). */
    public const STAGED_DISCOVERY_ENABLED = 'staged_discovery_enabled';

    /** @var string Enables family-level embeddings boost in planner ranking. */
    public const FAMILY_EMBEDDINGS_ENABLED = 'family_embeddings_enabled';

    /** @var string Enables stricter synchronizer output contract behavior. */
    public const SYNCHRONIZER_STRICT_CONTRACT = 'synchronizer_strict_contract';

    /**
     * Consistency gate enforcement mode.
     * Values: 'observe' (log only), 'warn' (log + soft message), 'enforce' (block + issue_code).
     * Default: 'enforce' (gate is always active for now; flag allows staged rollback).
     */
    public const CONSISTENCY_GATE_MODE = 'consistency_gate_mode';

    /**
     * Postcondition enforcement mode.
     * Values: 'observe' (log only), 'warn' (log + soft message), 'enforce' (block success).
     * Default: 'enforce'.
     */
    public const POSTCONDITION_ENFORCEMENT_MODE = 'postcondition_enforcement_mode';

    /** @var string[] Known and supported runtime feature flags. */
    private const KNOWN_FLAGS = [
        self::FAMILY_DISCOVERY_ENABLED,
        self::FAMILY_EMBEDDINGS_ENABLED,
        self::STAGED_DISCOVERY_ENABLED,
        self::SYNCHRONIZER_STRICT_CONTRACT,
        self::CONSISTENCY_GATE_MODE,
        self::POSTCONDITION_ENFORCEMENT_MODE,
    ];

    /**
     * Observe mode (log only).
     */
    public const ENFORCEMENT_MODE_OBSERVE  = 'observe';

    /**
     * Warn mode (log + soft message).
     */
    public const ENFORCEMENT_MODE_WARN     = 'warn';

    /**
     * Enforce mode (block success).
     */
    public const ENFORCEMENT_MODE_ENFORCE  = 'enforce';

    /**
     * Resolve enforcement mode flag, returning one of the ENFORCEMENT_MODE_* constants.
     * Defaults to 'enforce' when flag is unset or invalid.
     *
     * @param string $flag One of CONSISTENCY_GATE_MODE or POSTCONDITION_ENFORCEMENT_MODE.
     * @return string
     */
    public static function enforcement_mode(string $flag): string {
        if (!in_array($flag, self::KNOWN_FLAGS, true)) {
            return self::ENFORCEMENT_MODE_ENFORCE;
        }
        $raw = trim((string)(get_config(self::COMPONENT, $flag) ?? ''));
        if (in_array($raw, [self::ENFORCEMENT_MODE_OBSERVE, self::ENFORCEMENT_MODE_WARN], true)) {
            return $raw;
        }
        return self::ENFORCEMENT_MODE_ENFORCE;
    }

    /**
     * Resolve whether a known runtime feature flag is enabled.
     *
     * Unknown flag names are treated as disabled for safety.
     *
     * @param string $flag
     * @return bool
     */
    public static function is_enabled(string $flag): bool {
        if (!in_array($flag, self::KNOWN_FLAGS, true)) {
            return false;
        }

        $raw = get_config(self::COMPONENT, $flag);
        return self::normalize_bool($raw);
    }

    /**
     * Return all known runtime flags as a normalized boolean snapshot.
     *
     * @return array
     */
    public static function snapshot(): array {
        $snapshot = [];
        foreach (self::KNOWN_FLAGS as $flag) {
            $snapshot[$flag] = self::is_enabled($flag);
        }
        return $snapshot;
    }

    /**
     * Normalize raw config values to strict booleans.
     *
     * @param mixed $raw
     * @return bool
     */
    private static function normalize_bool($raw): bool {
        if ($raw === null || $raw === false || $raw === '') {
            return false;
        }

        if (is_bool($raw)) {
            return $raw;
        }

        if (is_int($raw) || is_float($raw)) {
            return (int)$raw > 0;
        }

        if (!is_string($raw)) {
            return false;
        }

        $value = trim(core_text::strtolower($raw));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }
}
