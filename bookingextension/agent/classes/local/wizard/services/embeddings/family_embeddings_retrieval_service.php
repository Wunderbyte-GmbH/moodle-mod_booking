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

namespace bookingextension_agent\local\wizard\services\embeddings;

use bookingextension_agent\local\wizard\contracts\skill_family_contract;

/**
 * Family-level ranking helper for skill-catalog embeddings.
 *
 * Aggregates skill-row similarities into deterministic family scores and can
 * boost skill rows by those family scores.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class family_embeddings_retrieval_service {
    /**
     * Compute family semantic scores from skill-catalog rows.
     *
     * @param string[] $families
     * @param array $queryvector
     * @param array[] $catalogrows
     * @return array
     */
    public function score_families(array $families, array $queryvector, array $catalogrows): array {
        $requested = [];
        foreach ($families as $family) {
            $family = skill_family_contract::normalize_family((string)$family);
            if ($family !== skill_family_contract::DEFAULT_FAMILY) {
                $requested[$family] = true;
            }
        }

        if (empty($requested) || empty($queryvector) || empty($catalogrows)) {
            return [];
        }

        $scores = [];
        foreach ($catalogrows as $row) {
            $skill = trim((string)($row['skill'] ?? ''));
            if ($skill === '') {
                continue;
            }

            $family = skill_family_contract::from_skill_name($skill);
            if (!isset($requested[$family])) {
                continue;
            }

            // Store-backed rows carry a pre-decoded float vector under 'embedding'; legacy CSV-shaped
            // rows carry it JSON-encoded under 'embedding_json'. Accept either, decode only if needed.
            $embedding = $row['embedding'] ?? null;
            if (!is_array($embedding) || empty($embedding)) {
                $embedding = json_decode((string)($row['embedding_json'] ?? '[]'), true);
            }
            if (!is_array($embedding) || empty($embedding)) {
                continue;
            }

            $score = vector_math::cosine_similarity($queryvector, $embedding);
            if (!isset($scores[$family]) || $score > $scores[$family]) {
                $scores[$family] = $score;
            }
        }

        foreach (array_keys($requested) as $family) {
            if (!isset($scores[$family])) {
                $scores[$family] = 0.0;
            }
        }

        return $scores;
    }

    /**
     * Boost skill rows with family scores and re-sort them deterministically.
     *
     * @param array[] $toprows
     * @param array $familyscores
     * @param float $skillweight
     * @param float $familyweight
     * @return array[]
     */
    public function boost_skill_rows(
        array $toprows,
        array $familyscores,
        float $skillweight = 0.92,
        float $familyweight = 0.08
    ): array {
        // 0.7/0.3 until 2026-09-20. With a family score spanning 0.2..1.0 that moved the combined score by up
        // to 0.24 — far more than the gap between adjacent candidates, so the family decided the ranking
        // outright. On a booking page the correct answer is regularly a course or core skill (UA-3, SC-2,
        // ACS-2, EU-2), and such a weight would have buried all four. At 0.08 the family breaks a near-tie
        // and nothing else.
        if (empty($toprows)) {
            return [];
        }

        $skillweight = max(0.0, min(1.0, $skillweight));
        $familyweight = max(0.0, min(1.0, $familyweight));
        if (($skillweight + $familyweight) <= 0.0) {
            $skillweight = 1.0;
            $familyweight = 0.0;
        }

        $boosted = [];
        foreach ($toprows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $skill = trim((string)($row['skill'] ?? ''));
            $family = skill_family_contract::from_skill_name($skill);
            $skillscore = (float)($row['score'] ?? 0.0);
            $familyscore = (float)($familyscores[$family] ?? 0.0);
            $combined = ($skillweight * $skillscore) + ($familyweight * $familyscore);

            $row['family'] = $family;
            $row['family_score'] = $familyscore;
            $row['score'] = $combined;
            $boosted[] = $row;
        }

        usort($boosted, static function (array $a, array $b): int {
            $scorecmp = ((float)$b['score'] <=> (float)$a['score']);
            if ($scorecmp !== 0) {
                return $scorecmp;
            }

            $familycmp = strcmp((string)($a['family'] ?? ''), (string)($b['family'] ?? ''));
            if ($familycmp !== 0) {
                return $familycmp;
            }

            return strcmp((string)($a['skill'] ?? ''), (string)($b['skill'] ?? ''));
        });

        return array_values($boosted);
    }
}
