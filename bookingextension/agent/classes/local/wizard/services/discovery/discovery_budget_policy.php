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

namespace bookingextension_agent\local\wizard\services\discovery;

/**
 * Budget policy for staged family discovery.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class discovery_budget_policy {
    /** @var int Hard cap for stage A. */
    private const STAGE_A_BUDGET = 12;

    /** @var int Hard cap for stage B. */
    private const STAGE_B_BUDGET = 24;

    /** @var int Hard cap for stage C. */
    private const STAGE_C_BUDGET = 36;

    /**
     * Return family budget for one stage.
     *
     * @param string $stage
     * @return int
     */
    public function get_stage_budget(string $stage): int {
        $normalized = strtoupper(trim($stage));
        if ($normalized === 'A') {
            return self::STAGE_A_BUDGET;
        }
        if ($normalized === 'B') {
            return self::STAGE_B_BUDGET;
        }
        if ($normalized === 'C') {
            return self::STAGE_C_BUDGET;
        }

        return self::STAGE_A_BUDGET;
    }

    /**
     * Apply stage-specific hard budget to ranked family rows.
     *
     * @param array[] $rankedfamilies
     * @param string $stage
     * @return array[]
     */
    public function apply_budget(array $rankedfamilies, string $stage): array {
        return array_slice($rankedfamilies, 0, $this->get_stage_budget($stage));
    }
}
