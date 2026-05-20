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
        $configuredstates = $this->get_configured_states();
        if (array_key_exists($conditionid, $configuredstates)) {
            return (int)$configuredstates[$conditionid];
        }

        $legacyskipconditions = $this->get_legacy_skipped_conditions($isenrollinkcontext);
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
        $configuredstates = get_config('booking', 'availabilityconditionstates');
        if (empty($configuredstates)) {
            return [];
        }

        $decoded = json_decode($configuredstates, true);
        if (!is_array($decoded)) {
            return [];
        }

        $states = [];
        foreach ($decoded as $conditionid => $state) {
            $states[(int)$conditionid] = (int)$state;
        }

        return $states;
    }

    /**
     * Returns the legacy skip list, keeping enrollink-specific defaults intact.
     *
     * @param bool $isenrollinkcontext
     * @return array<int>
     */
    private function get_legacy_skipped_conditions(bool $isenrollinkcontext = false): array {
        $skippedconditions = get_config('booking', 'skipableconditions');
        $skippedconditionsarray = !empty($skippedconditions) ? explode(',', $skippedconditions) : [];

        if ($isenrollinkcontext) {
            $enrollinkexcluded = get_config('booking', 'enrollinkskipconditions');
            if (!empty($enrollinkexcluded)) {
                $skippedconditionsarray = array_merge($skippedconditionsarray, explode(',', $enrollinkexcluded));
            }

            $skippedconditionsarray = array_merge($skippedconditionsarray, [
                MOD_BOOKING_BO_COND_CAPBOOKINGCHOOSE,
                MOD_BOOKING_BO_COND_JSON_ALLOWEDTOBOOKININSTANCE,
                MOD_BOOKING_BO_COND_JSON_CUSTOMFORM,
            ]);
        }

        $filteredconditions = array_filter(
            $skippedconditionsarray,
            fn($value) => $value !== '' && $value !== '0'
        );

        return array_map('intval', array_values($filteredconditions));
    }
}
