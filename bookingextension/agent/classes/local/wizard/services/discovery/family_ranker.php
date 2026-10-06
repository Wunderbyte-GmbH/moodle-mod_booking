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
 * Merge ranking signals into one deterministic family ranking.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class family_ranker {
    /** @var float Default weight for symbolic/context signals. */
    private const SIGNAL_WEIGHT = 0.7;

    /** @var float Default weight for embedding semantic scores. */
    private const SEMANTIC_WEIGHT = 0.3;

    /** @var int Maximum number of low-score tail families forwarded to selection. */
    private const LOW_SCORE_TAIL_MAX = 2;

    /** @var float Minimum score required for low-score tail forwarding. */
    private const LOW_SCORE_TAIL_MIN_SCORE = 0.15;

    /**
     * Rank families by combined signal and semantic scores.
     *
     * @param string[] $families
     * @param array $signalscores
     * @param array $semanticscores
     * @return array[]
     */
    public function rank(array $families, array $signalscores, array $semanticscores = []): array {
        $rows = [];
        foreach ($families as $family) {
            $signal = (float)($signalscores[$family] ?? 0.0);
            $semantic = (float)($semanticscores[$family] ?? 0.0);
            if (empty($semanticscores)) {
                $score = $signal;
            } else {
                $score = (self::SIGNAL_WEIGHT * $signal) + (self::SEMANTIC_WEIGHT * $semantic);
            }

            $rows[] = [
                'family' => $family,
                'score' => min(1.0, max(0.0, $score)),
                'signal_score' => min(1.0, max(0.0, $signal)),
                'semantic_score' => min(1.0, max(0.0, $semantic)),
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $scorecmp = ((float)$b['score'] <=> (float)$a['score']);
            if ($scorecmp !== 0) {
                return $scorecmp;
            }

            return strcmp((string)$a['family'], (string)$b['family']);
        });

        return $rows;
    }

    /**
     * Select a small deterministic low-score tail outside already selected families.
     *
     * @param array[] $rankedfamilies
     * @param string[] $selectedfamilies
     * @param int|null $maxitems
     * @param float|null $minscore
     * @return string[]
     */
    public function select_low_score_tail(
        array $rankedfamilies,
        array $selectedfamilies,
        ?int $maxitems = null,
        ?float $minscore = null
    ): array {
        $limit = max(0, (int)($maxitems ?? self::LOW_SCORE_TAIL_MAX));
        if ($limit === 0) {
            return [];
        }

        $threshold = min(1.0, max(0.0, (float)($minscore ?? self::LOW_SCORE_TAIL_MIN_SCORE)));
        $selectedset = array_fill_keys($selectedfamilies, true);

        $selectedfloorscore = 1.0;
        $hasscoredselected = false;
        foreach ($rankedfamilies as $row) {
            $family = trim((string)($row['family'] ?? ''));
            if ($family === '' || !isset($selectedset[$family])) {
                continue;
            }

            $hasscoredselected = true;
            $selectedfloorscore = min($selectedfloorscore, (float)($row['score'] ?? 0.0));
        }

        if (!$hasscoredselected) {
            $selectedfloorscore = 1.0;
        }

        $tail = [];
        foreach ($rankedfamilies as $row) {
            if (count($tail) >= $limit) {
                break;
            }

            $family = trim((string)($row['family'] ?? ''));
            if ($family === '' || isset($selectedset[$family])) {
                continue;
            }

            $score = (float)($row['score'] ?? 0.0);
            if ($score < $threshold || $score > $selectedfloorscore) {
                continue;
            }

            $tail[] = $family;
        }

        return $tail;
    }
}
