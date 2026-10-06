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

namespace bookingextension_agent\local\wizard\benchmark;

/**
 * Calculates aggregated model and agent metrics from scenario results.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class benchmark_metrics_calculator {
    /**
     * Critical metric thresholds. Regression = actual < threshold.
     */
    private const CRITICAL_THRESHOLDS = [
        'skill_hit_rate'         => 90.0,
        'json_validity_rate'    => 99.0,
        'contract_compliance_rate' => 98.0,
        'response_type_accuracy' => 95.0,
        'planned_steps_coverage' => 95.0,
        'e2e_success_rate'      => 85.0,
    ];

    /**
     * Calculate all metrics from a set of scenario result arrays.
     *
     * @param array[] $scenarios
     * @return array[] Metric records ready for benchmark_db_writer.
     */
    public function calculate(array $scenarios): array {
        if (empty($scenarios)) {
            return [];
        }

        $total       = count($scenarios);
        $passed      = 0;
        $jsonvalid   = 0;
        $compliant   = 0;
        $rtaccurate  = 0;
        $rtstrict    = 0;
        $forbiddenbase     = 0;
        $forbiddenavoided  = 0;
        $skillhit     = 0;
        $plannedhit  = 0;
        $multistep   = 0;
        $multistepok = 0;
        $clarif      = 0;
        $totalms     = 0;
        $totaltokens = 0;
        $totalsteps  = 0;
        $durations   = [];

        foreach ($scenarios as $s) {
            if ($s['passed']) {
                $passed++;
            }
            if ($s['json_valid']) {
                $jsonvalid++;
            }
            if ($s['contract_compliant']) {
                $compliant++;
            }
            $exprt = trim((string)($s['response_type_expected'] ?? ''));
            $actrt = trim((string)($s['response_type_actual'] ?? ''));
            // Acceptance-aware: a scenario may declare a SET of valid response_types (catalog-gap =
            // error OR search_skills; accept-both mutations = skill_call OR confirmation_request). The
            // per-scenario PASS logic already honors that set, so a scenario that passed had, by
            // definition, an acceptable response_type. Counting strict expected==actual here would mark
            // such a passed scenario as a miss and raise a false "regression" at 100% pass (run 40).
            // Stay in sync with the verdict: accurate if it passed, or the strict expected matches.
            if ($exprt !== '' && ($exprt === $actrt || !empty($s['passed']))) {
                $rtaccurate++;
            }
            // Strict variant: exact expected==actual only (no "|| passed" escape). The gap to the lenient
            // response_type_accuracy above is the "acceptable-but-not-intended response_type" signal.
            if ($exprt !== '' && $exprt === $actrt) {
                $rtstrict++;
            }
            // Routing scenarios that name confusable siblings: did the model AVOID the forbidden skill?
            if (!empty($s['forbidden_siblings_present'])) {
                $forbiddenbase++;
                if (empty($s['forbidden_sibling_hit'])) {
                    $forbiddenavoided++;
                }
            }
            $expskill = trim((string)($s['skill_expected'] ?? ''));
            $actskill = trim((string)($s['skill_selected'] ?? ''));
            if ($expskill !== '' && $expskill === $actskill) {
                $skillhit++;
            }
            if (($s['scenario_class'] ?? '') === 'multistep') {
                $multistep++;
                if ($s['planned_steps_present']) {
                    $plannedhit++;
                }
                if ($s['passed']) {
                    $multistepok++;
                }
            }
            if ($actrt === 'clarification') {
                $clarif++;
            }
            $totalms     += (int)($s['duration_ms'] ?? 0);
            $totaltokens += (int)($s['tokens_prompt'] ?? 0) + (int)($s['tokens_completion'] ?? 0);
            $totalsteps  += (int)($s['step_count'] ?? 0);
            $durations[]  = (int)($s['duration_ms'] ?? 0);
        }

        sort($durations);
        $p50ms = $this->percentile($durations, 50);
        $p95ms = $this->percentile($durations, 95);

        $skillbase    = array_sum(array_map(fn($s) => (string)($s['skill_expected'] ?? '') !== '' ? 1 : 0, $scenarios));
        $rtbase      = array_sum(array_map(fn($s) => (string)($s['response_type_expected'] ?? '') !== '' ? 1 : 0, $scenarios));

        $metrics = [
            [
                'metric_key' => 'e2e_success_rate',
                'metric_value' => $this->pct($passed, $total),
                'metric_unit' => 'percent',
            ],
            [
                'metric_key' => 'json_validity_rate',
                'metric_value' => $this->pct($jsonvalid, $total),
                'metric_unit' => 'percent',
            ],
            [
                'metric_key' => 'contract_compliance_rate',
                'metric_value' => $this->pct($compliant, $total),
                'metric_unit' => 'percent',
            ],
            [
                'metric_key' => 'response_type_accuracy',
                'metric_value' => $this->pct($rtaccurate, max(1, $rtbase)),
                'metric_unit' => 'percent',
            ],
            [
                'metric_key' => 'response_type_strict_accuracy',
                'metric_value' => $this->pct($rtstrict, max(1, $rtbase)),
                'metric_unit' => 'percent',
            ],
            [
                'metric_key' => 'forbidden_sibling_avoidance_rate',
                // N/A (100) when no scenario in the set declares confusable siblings.
                'metric_value' => $forbiddenbase > 0 ? $this->pct($forbiddenavoided, $forbiddenbase) : 100.0,
                'metric_unit' => 'percent',
            ],
            [
                'metric_key' => 'skill_hit_rate',
                // N/A when no scenario in the set is skill-scoped (e.g. a contract-only run): a missing
                // denominator is "not applicable", not a 0% regression.
                'metric_value' => $skillbase > 0 ? $this->pct($skillhit, $skillbase) : 100.0,
                'metric_unit' => 'percent',
            ],
            [
                'metric_key' => 'planned_steps_coverage',
                // N/A when the set contains no multistep scenarios.
                'metric_value' => $multistep > 0 ? $this->pct($plannedhit, $multistep) : 100.0,
                'metric_unit' => 'percent',
            ],
            [
                'metric_key' => 'multistep_completion_rate',
                'metric_value' => $this->pct($multistepok, max(1, $multistep)),
                'metric_unit' => 'percent',
            ],
            [
                'metric_key' => 'clarification_rate',
                'metric_value' => $this->pct($clarif, $total),
                'metric_unit' => 'percent',
            ],
            [
                'metric_key' => 'avg_tokens_per_scenario',
                'metric_value' => $total > 0 ? round($totaltokens / $total, 1) : 0,
                'metric_unit' => 'tokens',
            ],
            [
                'metric_key' => 'avg_step_count',
                'metric_value' => $total > 0 ? round($totalsteps / $total, 2) : 0,
                'metric_unit' => 'count',
            ],
            [
                'metric_key' => 'p50_duration_ms',
                'metric_value' => $p50ms,
                'metric_unit' => 'ms',
            ],
            [
                'metric_key' => 'p95_duration_ms',
                'metric_value' => $p95ms,
                'metric_unit' => 'ms',
            ],
        ];

        foreach ($metrics as &$m) {
            $m['scenario_class'] = null;
        }
        unset($m);

        return $metrics;
    }

    /**
     * Compare two sets of metric records and return regression flags.
     *
     * @param array $current  Current run metrics (metric_key => metric_value).
     * @param array $baseline Baseline metrics (metric_key => metric_value).
     * @return array Keyed by metric_key, with delta, regressed, threshold.
     */
    public function compare(array $current, array $baseline): array {
        $result = [];
        foreach (self::CRITICAL_THRESHOLDS as $key => $threshold) {
            $cur = (float)($current[$key] ?? 0.0);
            $base = (float)($baseline[$key] ?? $threshold);
            $delta = $cur - $base;
            $result[$key] = [
                'current'   => $cur,
                'baseline'  => $base,
                'delta'     => round($delta, 2),
                'threshold' => $threshold,
                'regressed' => $cur < $threshold && $delta < 0,
                'status'    => $cur >= $threshold ? 'green' : ($cur >= $threshold * 0.95 ? 'yellow' : 'red'),
            ];
        }
        return $result;
    }

    /**
     * Return true if any critical metric has regressed below threshold.
     *
     * @param array $metricsmap  metric_key => metric_value
     */
    public function has_critical_regression(array $metricsmap): bool {
        foreach (self::CRITICAL_THRESHOLDS as $key => $threshold) {
            if (isset($metricsmap[$key]) && (float)$metricsmap[$key] < $threshold) {
                return true;
            }
        }
        return false;
    }

    /**
     * Return critical thresholds, overridden by Moodle admin config when available.
     *
     * @return array
     */
    public function get_thresholds(): array {
        $thresholds = self::CRITICAL_THRESHOLDS;
        $configmap = [
            'skill_hit_rate'          => 'benchmark_threshold_skill_hit_rate',
            'json_validity_rate'     => 'benchmark_threshold_json_validity',
            'e2e_success_rate'       => 'benchmark_threshold_e2e_success',
        ];
        if (function_exists('get_config')) {
            foreach ($configmap as $key => $configkey) {
                $val = get_config('bookingextension_agent', $configkey);
                if ($val !== false && $val !== null && is_numeric($val)) {
                    $thresholds[$key] = (float)$val;
                }
            }
        }
        return $thresholds;
    }

    /**
     * Calculate percentage.
     *
     * @param int $num   Numerator.
     * @param int $denom Denominator.
     * @return float
     */
    private function pct(int $num, int $denom): float {
        return $denom > 0 ? round($num / $denom * 100, 2) : 0.0;
    }

    /**
     * Calculate percentile.
     *
     * @param array $sorted Sorted numeric values.
     * @param int   $pct    Percentile value (e.g. 95).
     * @return float
     */
    private function percentile(array $sorted, int $pct): float {
        if (empty($sorted)) {
            return 0.0;
        }
        $idx = (int) ceil(count($sorted) * $pct / 100) - 1;
        return (float)$sorted[max(0, min($idx, count($sorted) - 1))];
    }
}
