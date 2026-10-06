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
 * Single source of truth for resolving and normalizing skill risk classes.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\risk;

use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\skill_registry;

/**
 * Centralizes the risk-class resolution that was previously copied across the decision, preflight
 * and queue layers (LG_RISK is the documented centralization point).
 *
 * Fail-safe by design: anything that cannot be resolved to a valid class falls back to R3
 * (irreversible/external), so an unknown command is always treated with maximum caution.
 */
class risk_class_resolver {
    /**
     * Normalize a raw risk-class string to a valid class, defaulting to R3.
     *
     * @param string $riskclass
     * @return string One of skill_risk_class::R0..R3.
     */
    public static function normalize(string $riskclass): string {
        $riskclass = trim($riskclass);
        return skill_risk_class::is_valid($riskclass) ? $riskclass : skill_risk_class::R3;
    }

    /**
     * Resolve the effective risk class for one command.
     *
     * Prefers the command's own declared risk_class; otherwise looks up the skill's declared class
     * from the registry; falls back to R3 when neither yields a valid class.
     *
     * @param array $command
     * @param skill_registry      $registry
     * @return string One of skill_risk_class::R0..R3.
     */
    public static function resolve_for_command(array $command, skill_registry $registry): string {
        $riskclass = trim((string)($command['risk_class'] ?? ''));
        if (skill_risk_class::is_valid($riskclass)) {
            return $riskclass;
        }

        $skillname = trim((string)($command['skill'] ?? ''));
        if ($skillname !== '') {
            $skill = $registry->get_skill($skillname);
            if ($skill !== null) {
                $skillriskclass = trim($skill->get_risk_class());
                if (skill_risk_class::is_valid($skillriskclass)) {
                    return $skillriskclass;
                }
            }
        }

        return skill_risk_class::R3;
    }

    /**
     * Rank risk classes from least (0) to most (3) restrictive — for taking the max over a batch.
     *
     * @param string $riskclass
     * @return int
     */
    public static function rank(string $riskclass): int {
        return match ($riskclass) {
            skill_risk_class::R0 => 0,
            skill_risk_class::R1 => 1,
            skill_risk_class::R2 => 2,
            default => 3,
        };
    }
}
