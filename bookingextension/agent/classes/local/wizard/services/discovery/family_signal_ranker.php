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
 * Compute language-agnostic signal scores for family candidates.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class family_signal_ranker {
    /** @var float Base score for each candidate family. */
    private float $baseweight;

    /** @var float Bonus for core.* families. */
    private float $coreweight;

    /** @var float Bonus when namespace_hint matches family namespace. */
    private float $namespacehintweight;

    /** @var float Bonus when recent skill namespaces match. */
    private float $recencynamespaceweight;

    /** @var float Weight for the namespace of the plugin owning the current page. */
    private float $contextnamespaceweight;

    /**
     * Constructor.
     *
     * @param array $weights
     */
    public function __construct(array $weights = []) {
        $this->baseweight = $this->normalize_weight($weights['base'] ?? 0.20);
        $this->coreweight = $this->normalize_weight($weights['core'] ?? 0.10);
        // Down from 0.35 on 2026-09-20: namespace_hint is catalogue POPULARITY (the plugin with the most
        // registered skills), constant across every request and blind to where the user is. It was the
        // strongest term, which would have preferred local_taskflow on every page of the site.
        $this->namespacehintweight = $this->normalize_weight($weights['namespace_hint'] ?? 0.10);
        // The plugin that owns the current page — the only term here that actually knows the context.
        $this->contextnamespaceweight = $this->normalize_weight($weights['context_namespace'] ?? 0.40);
        $this->recencynamespaceweight = $this->normalize_weight($weights['recent_namespace'] ?? 0.20);
    }

    /**
     * Score families from context priors and recency signals.
     *
     * @param string[] $families
     * @param array $contextprior
     * @param string[] $recentskillnames
     * @return array
     */
    public function score_families(array $families, array $contextprior, array $recentskillnames = []): array {
        $scores = [];
        $namespacehint = trim((string)($contextprior['namespace_hint'] ?? ''));
        $contextnamespace = trim((string)($contextprior['context_namespace'] ?? ''));

        $recentnamespaces = [];
        foreach ($recentskillnames as $skillname) {
            $dot = strpos((string)$skillname, '.');
            if ($dot === false || $dot <= 0) {
                continue;
            }
            $recentnamespaces[] = substr((string)$skillname, 0, $dot);
        }

        foreach ($families as $family) {
            $score = $this->baseweight;

            if (strpos($family, 'wizard.') === 0) {
                $score += $this->coreweight;
            }

            if ($namespacehint !== '' && strpos($family, $namespacehint . '.') === 0) {
                $score += $this->namespacehintweight;
            }

            if ($contextnamespace !== '' && strpos($family, $contextnamespace . '.') === 0) {
                $score += $this->contextnamespaceweight;
            }

            foreach ($recentnamespaces as $namespace) {
                if (strpos($family, $namespace . '.') === 0) {
                    $score += $this->recencynamespaceweight;
                    break;
                }
            }

            $scores[$family] = min(1.0, max(0.0, $score));
        }

        return $scores;
    }

    /**
     * Clamp ranking weights into a safe range.
     *
     * @param mixed $weight
     * @return float
     */
    private function normalize_weight($weight): float {
        $value = (float)$weight;
        if ($value < 0.0) {
            return 0.0;
        }

        return min(1.0, $value);
    }
}
