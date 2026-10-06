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
 * Confidence policy for stage escalation decisions.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class discovery_confidence_policy {
    /** @var float Confidence threshold for stage A sufficiency. */
    private const THRESHOLD_STAGE_A = 0.60;

    /** @var float Confidence threshold for stage B sufficiency. */
    private const THRESHOLD_STAGE_B = 0.45;

    /**
     * Normalize confidence value to [0.0, 1.0].
     *
     * @param float|null $score
     * @return float
     */
    public function normalize_score(?float $score): float {
        if ($score === null) {
            return 0.0;
        }

        if ($score < 0.0) {
            return 0.0;
        }
        if ($score > 1.0) {
            return 1.0;
        }
        return $score;
    }

    /**
     * Decide whether a confidence score is sufficient for a stage.
     *
     * @param float|null $score
     * @param string $stage
     * @return bool
     */
    public function is_sufficient(?float $score, string $stage): bool {
        $normalized = strtoupper(trim($stage));
        $value = $this->normalize_score($score);

        if ($normalized === 'A') {
            return $value >= self::THRESHOLD_STAGE_A;
        }
        if ($normalized === 'B') {
            return $value >= self::THRESHOLD_STAGE_B;
        }

        return $value > 0.0;
    }
}
