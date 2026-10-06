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
 * Decide staged discovery escalation (A/B/C) using budget and confidence rules.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class discovery_stage_controller {
    /**
     * Minimum semantic score for a family to count as a genuine intent signal.
     *
     * Below this the embedding signal is treated as noise/absent (e.g. embeddings
     * unavailable, all semantic scores ~0), so the intent-coverage guard stays inert
     * and legacy signal-only staging behaviour is preserved.
     *
     * @var float
     */
    private const INTENT_SEMANTIC_MIN = 0.15;

    /** @var discovery_budget_policy */
    private discovery_budget_policy $budgetpolicy;

    /** @var discovery_confidence_policy */
    private discovery_confidence_policy $confidencepolicy;

    /** @var family_ranker */
    private family_ranker $familyranker;

    /**
     * Constructor.
     *
     * @param discovery_budget_policy|null $budgetpolicy
     * @param discovery_confidence_policy|null $confidencepolicy
     * @param family_ranker|null $familyranker
     */
    public function __construct(
        ?discovery_budget_policy $budgetpolicy = null,
        ?discovery_confidence_policy $confidencepolicy = null,
        ?family_ranker $familyranker = null
    ) {
        $this->budgetpolicy = $budgetpolicy ?? new discovery_budget_policy();
        $this->confidencepolicy = $confidencepolicy ?? new discovery_confidence_policy();
        $this->familyranker = $familyranker ?? new family_ranker();
    }

    /**
     * Resolve staged discovery output.
     *
     * @param array[] $rankedfamilies
     * @param string[] $contextfamilies
     * @param string[] $corefamilies
     * @return array
     */
    public function resolve(array $rankedfamilies, array $contextfamilies, array $corefamilies): array {
        if (empty($rankedfamilies)) {
            return [
                'discovery_stage' => 'none',
                'confidence_score' => null,
                'escalation_reason' => 'no_candidates',
                'selected_families' => [],
            ];
        }

        $stageafamilies = array_values(array_unique(array_merge($contextfamilies, $corefamilies)));
        $stagearows = $this->rows_for_families($rankedfamilies, $stageafamilies);
        $stagearows = $this->budgetpolicy->apply_budget($stagearows, 'A');
        $stageascore = $this->top_score($stagearows);

        // Intent-coverage guard (flowchart "context = ranking PRIOR, not hard filter"):
        // the ambient context must not short-circuit at Stage A when the strongest semantic
        // (intent) match lies OUTSIDE the Stage A family set. Intent is carried by the
        // embedding/semantic signal; when it is absent this guard is inert (see
        // stage_a_covers_intent) so legacy signal-only behaviour is preserved.
        $intentcovered = $this->stage_a_covers_intent($rankedfamilies, $stageafamilies);

        if ($intentcovered && $this->confidencepolicy->is_sufficient($stageascore, 'A')) {
            $selectedfamilies = array_values(array_map(
                static fn(array $row): string => (string)$row['family'],
                $stagearows
            ));
            $selectedfamilies = $this->append_low_score_tail($selectedfamilies, $rankedfamilies);
            return [
                'discovery_stage' => 'A',
                'confidence_score' => $this->confidencepolicy->normalize_score($stageascore),
                'escalation_reason' => 'none',
                'selected_families' => $selectedfamilies,
            ];
        }

        $escalationreasonb = $intentcovered ? 'stage_a_low_confidence' : 'stage_a_intent_outside';
        $stagebrows = $this->budgetpolicy->apply_budget($rankedfamilies, 'B');
        $stagebscore = $this->top_score($stagebrows);
        if ($this->confidencepolicy->is_sufficient($stagebscore, 'B')) {
            $selectedfamilies = array_values(array_map(
                static fn(array $row): string => (string)$row['family'],
                $stagebrows
            ));
            $selectedfamilies = $this->append_low_score_tail($selectedfamilies, $rankedfamilies);
            return [
                'discovery_stage' => 'B',
                'confidence_score' => $this->confidencepolicy->normalize_score($stagebscore),
                'escalation_reason' => $escalationreasonb,
                'selected_families' => $selectedfamilies,
            ];
        }

        $stagecrows = $this->budgetpolicy->apply_budget($rankedfamilies, 'C');
        $stagecscore = $this->top_score($stagecrows);

        $selectedfamilies = array_values(array_map(static fn(array $row): string => (string)$row['family'], $stagecrows));
        $selectedfamilies = $this->append_low_score_tail($selectedfamilies, $rankedfamilies);

        return [
            'discovery_stage' => 'C',
            'confidence_score' => $this->confidencepolicy->normalize_score($stagecscore),
            'escalation_reason' => 'stage_b_low_confidence',
            'selected_families' => $selectedfamilies,
        ];
    }

    /**
     * Decide whether the Stage A family set covers the user's semantic intent.
     *
     * Returns true when the highest-scoring SEMANTIC family is already inside Stage A,
     * or when there is no meaningful semantic signal at all (embeddings unavailable →
     * all semantic scores below {@see self::INTENT_SEMANTIC_MIN}). It returns false only
     * when a clear semantic intent points at a family OUTSIDE Stage A, which must force
     * escalation so that cross-namespace skills (e.g. course.* from a booking context)
     * are not silently filtered out.
     *
     * @param array[] $rankedfamilies
     * @param string[] $stageafamilies
     * @return bool
     */
    private function stage_a_covers_intent(array $rankedfamilies, array $stageafamilies): bool {
        $topfamily = '';
        $topsemantic = 0.0;
        foreach ($rankedfamilies as $row) {
            $semantic = (float)($row['semantic_score'] ?? 0.0);
            if ($semantic > $topsemantic) {
                $topsemantic = $semantic;
                $topfamily = (string)($row['family'] ?? '');
            }
        }

        // No meaningful semantic (intent) signal — keep legacy signal-only behaviour.
        if ($topfamily === '' || $topsemantic < self::INTENT_SEMANTIC_MIN) {
            return true;
        }

        return in_array($topfamily, $stageafamilies, true);
    }

    /**
     * Append a controlled low-score tail from the authoritative family ranker.
     *
     * @param string[] $selectedfamilies
     * @param array[] $rankedfamilies
     * @return string[]
     */
    private function append_low_score_tail(array $selectedfamilies, array $rankedfamilies): array {
        $tail = $this->familyranker->select_low_score_tail($rankedfamilies, $selectedfamilies);
        if (empty($tail)) {
            return $selectedfamilies;
        }

        return array_values(array_unique(array_merge($selectedfamilies, $tail)));
    }

    /**
     * Filter ranked rows by candidate family list while preserving rank order.
     *
     * @param array[] $rankedfamilies
     * @param string[] $families
     * @return array[]
     */
    private function rows_for_families(array $rankedfamilies, array $families): array {
        $allowed = array_fill_keys($families, true);
        $rows = [];
        foreach ($rankedfamilies as $row) {
            $family = (string)($row['family'] ?? '');
            if ($family === '' || !isset($allowed[$family])) {
                continue;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Return top confidence score from ranked rows.
     *
     * @param array[] $rows
     * @return float|null
     */
    private function top_score(array $rows): ?float {
        if (empty($rows)) {
            return null;
        }

        return (float)($rows[0]['score'] ?? 0.0);
    }
}
