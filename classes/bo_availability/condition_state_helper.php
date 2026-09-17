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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Resolves booking availability condition state with legacy compatibility.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\bo_availability;

/**
 * Resolves booking availability condition state with legacy compatibility.
 *
 * State values:
 * 0 = inactive
 * 1 = freeze
 * 2 = skip and freeze
 */
class condition_state_helper {
    /** Inactive condition. */
    public const STATE_INACTIVE = 0;

    /** Freeze only. */
    public const STATE_FREEZE = 1;

    /** Skip evaluation and freeze the fields. */
    public const STATE_SKIP_AND_FREEZE = 2;

    /**
     * Conditions that are always skipped in enrollink context unless explicitly configured otherwise.
     *
     * These were hardcoded before the enrollink skip flag became configurable on the
     * availability conditions dashboard and remain the fallback defaults.
     *
     * @var array<int>
     */
    public const ENROLLINK_DEFAULT_SKIP = [
        MOD_BOOKING_BO_COND_CAPBOOKINGCHOOSE,
        MOD_BOOKING_BO_COND_JSON_ALLOWEDTOBOOKININSTANCE,
        MOD_BOOKING_BO_COND_JSON_CUSTOMFORM,
        // Enrollink holders have already paid for their place (the bundle answer reserves it),
        // so a fully booked option must not block them by default.
        MOD_BOOKING_BO_COND_FULLYBOOKED,
    ];

    /**
     * Returns the effective state for a condition.
     *
     * The new configuration format is read first when present. If it is not
     * available yet, the legacy skip lists are mapped to skip-and-freeze.
     *
     * @param int $conditionid
     * @param bool $isenrollinkcontext
     * @return int
     */
    public function get_condition_state(int $conditionid, bool $isenrollinkcontext = false): int {
        if ($isenrollinkcontext && $this->is_enrollink_skipped($conditionid)) {
            return self::STATE_SKIP_AND_FREEZE;
        }

        $configuredstates = $this->get_configured_states();
        if (array_key_exists($conditionid, $configuredstates)) {
            return (int)$configuredstates[$conditionid];
        }

        $legacyskipconditions = $this->get_legacy_skipped_conditions();
        if (in_array($conditionid, $legacyskipconditions, true)) {
            return self::STATE_SKIP_AND_FREEZE;
        }

        return self::STATE_INACTIVE;
    }

    /**
     * Returns whether the condition should be skipped during evaluation.
     *
     * @param int $conditionid
     * @param bool $isenrollinkcontext
     * @return bool
     */
    public function should_skip_condition(int $conditionid, bool $isenrollinkcontext = false): bool {
        return $this->get_condition_state($conditionid, $isenrollinkcontext) === self::STATE_SKIP_AND_FREEZE;
    }

    /**
     * Returns whether the condition is skipped when a user books via an enrolment link.
     *
     * A configured entry with an explicit 'enrollinkskip' key always wins. Entries saved
     * before the flag existed (or no entry at all) fall back to the legacy
     * 'enrollinkskipconditions' setting plus the hardcoded defaults, so upgraded sites keep
     * their previous behaviour until the dashboard is saved again.
     *
     * @param int $conditionid
     * @return bool
     */
    public function is_enrollink_skipped(int $conditionid): bool {
        $entries = $this->get_configured_entries();
        if (isset($entries[$conditionid]) && array_key_exists('enrollinkskip', $entries[$conditionid])) {
            return !empty($entries[$conditionid]['enrollinkskip']);
        }

        $legacyexcluded = get_config('booking', 'enrollinkskipconditions');
        $legacyids = !empty($legacyexcluded) ? array_map('intval', explode(',', $legacyexcluded)) : [];
        $legacyids = array_merge($legacyids, self::ENROLLINK_DEFAULT_SKIP);

        return in_array($conditionid, $legacyids, true);
    }

    /**
     * Returns whether the condition should be frozen in the option form.
     *
     * @param int $conditionid
     * @param bool $isenrollinkcontext
     * @return bool
     */
    public function should_freeze_condition(int $conditionid, bool $isenrollinkcontext = false): bool {
        $state = $this->get_condition_state($conditionid, $isenrollinkcontext);
        return in_array($state, [self::STATE_FREEZE, self::STATE_SKIP_AND_FREEZE], true);
    }

    /**
     * Returns condition states from the new config format.
     *
     * @return array<int,int>
     */
    private function get_configured_states(): array {
        $states = [];
        foreach ($this->get_configured_entries() as $conditionid => $entry) {
            if (array_key_exists('skipstate', $entry)) {
                $states[$conditionid] = (int)$entry['skipstate'];
            }
        }

        return $states;
    }

    /**
     * Returns the normalized per-condition entries from the new config format.
     *
     * Each entry is an array that may contain 'skipstate' and 'enrollinkskip' keys.
     * Scalar values from the previous flat map format are normalized to skipstate-only entries.
     *
     * @return array<int,array>
     */
    private function get_configured_entries(): array {
        $configuredstates = get_config('booking', 'availabilityconditionsettings');
        if (empty($configuredstates)) {
            // Backward compatibility for the previous experimental key.
            $configuredstates = get_config('booking', 'availabilityconditionstates');
        }
        if (empty($configuredstates)) {
            return [];
        }

        $decoded = json_decode($configuredstates, true);
        if (!is_array($decoded)) {
            return [];
        }

        $entries = [];
        foreach ($decoded as $conditionid => $entry) {
            if (is_array($entry)) {
                $entries[(int)$conditionid] = $entry;
                continue;
            }

            // Backward compatibility for previous flat map format.
            if (is_scalar($entry)) {
                $entries[(int)$conditionid] = ['skipstate' => (int)$entry];
            }
        }

        return $entries;
    }

    /**
     * Returns the legacy skip list from the 'skipableconditions' setting.
     *
     * @return array<int>
     */
    private function get_legacy_skipped_conditions(): array {
        $skippedconditions = get_config('booking', 'skipableconditions');
        $skippedconditionsarray = !empty($skippedconditions) ? explode(',', $skippedconditions) : [];

        $filteredconditions = array_filter(
            $skippedconditionsarray,
            fn($value) => $value !== '' && $value !== '0'
        );

        return array_map('intval', array_values($filteredconditions));
    }
}
