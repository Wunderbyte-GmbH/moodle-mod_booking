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
 * Retrieval service for skill-catalog embeddings.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\embeddings;

use bookingextension_agent\local\wizard\skill_registry_factory;

/**
 * Performs vector similarity search and builds planner-ready catalog subsets.
 */
class embeddings_retrieval_service {
    /**
     * Minimum cosine score a skill's BEST anchor must reach to be eligible (multi-vector retrieval).
     * 0.0 disables the gate — the top-k cut is the primary mechanism; this threshold is tuned later
     * against the benchmark / chosen embedding model (SKILL_REWORK.md §5).
     */
    public const SEMANTIC_MIN_SCORE = 0.0;

    /**
     * Multi-vector skill retrieval: score every anchor row, aggregate to the MAX score per skill,
     * and return the top-k DISTINCT skills (SKILL_REWORK.md §5).
     *
     * Each returned row is the winning anchor's row for that skill, tagged with:
     *   - score                : the skill's best (max) cosine score,
     *   - matched_anchor_kind  : 'description' | 'utterance',
     *   - matched_anchor_text  : the exact phrase that matched,
     *   - matched_anchor_index : its anchor index.
     * Distinct-skill aggregation is REQUIRED: without it, several anchors of one strong skill would
     * crowd the top-k and starve other skills. Each returned row is tagged with a 'score' string (one
     * row per result), so build_planner_catalog_subset() consumes it unchanged.
     *
     * @param array $queryvector
     * @param array[] $anchorrows
     * @param int $k Number of distinct skills to return.
     * @return array[]
     */
    public function search_top_k_skills(array $queryvector, array $anchorrows, int $k = 12): array {
        if ($k < 1 || empty($queryvector) || empty($anchorrows)) {
            return [];
        }

        $best = [];
        foreach ($anchorrows as $row) {
            $skill = trim((string)($row['skill'] ?? ''));
            if ($skill === '') {
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
            if (!isset($best[$skill]) || $score > $best[$skill]['score']) {
                $best[$skill] = ['score' => $score, 'row' => $row];
            }
        }

        if (empty($best)) {
            return [];
        }

        // Highest max-score first.
        uasort($best, static function (array $a, array $b): int {
            return $b['score'] <=> $a['score'];
        });

        $out = [];
        foreach ($best as $entry) {
            $score = (float)$entry['score'];
            if (self::SEMANTIC_MIN_SCORE > 0.0 && $score < self::SEMANTIC_MIN_SCORE) {
                continue;
            }
            $row = (array)$entry['row'];
            $row['score'] = (string)$score;
            $row['matched_anchor_kind'] = (string)($row['anchor_kind'] ?? '');
            $row['matched_anchor_text'] = (string)($row['anchor_text'] ?? '');
            $row['matched_anchor_index'] = (string)($row['anchor_index'] ?? '');
            $out[] = $row;
            if (count($out) >= $k) {
                break;
            }
        }

        return $out;
    }

    /**
     * Stream top-k by cosine similarity over an iterable of rows, holding only k candidates in memory.
     *
     * Same ranking/output shape as search_top_k_skills (rows tagged with a 'score' string, descending), but
     * the catalog is consumed one row at a time and the heavy embedding vector is dropped once scored,
     * so peak memory is O(k) instead of O(catalog). Use for large stores (e.g. the docs index).
     *
     * @param array $queryvector
     * @param iterable $rows
     * @param int $k
     * @return array[]
     */
    public function search_top_k_streaming(array $queryvector, iterable $rows, int $k = 5): array {
        if ($k < 1 || empty($queryvector)) {
            return [];
        }

        // Min-heap on score: the lowest-scoring kept candidate sits on top, ready to be evicted.
        $heap = new class extends \SplHeap {
            /**
             * Order candidates so the lowest score sits on top of the heap.
             *
             * @param mixed $value1
             * @param mixed $value2
             * @return int
             */
            protected function compare($value1, $value2): int {
                return $value2['score'] <=> $value1['score'];
            }
        };

        foreach ($rows as $row) {
            // The DB backend supplies a pre-decoded float32 vector under 'embedding'/'_vec'; the CSV
            // backend supplies it JSON-encoded under 'embedding_json'. Accept either, decode only if needed.
            $embedding = $row['_vec'] ?? $row['embedding'] ?? null;
            if (!is_array($embedding) || empty($embedding)) {
                $embedding = json_decode((string)($row['embedding_json'] ?? '[]'), true);
            }
            if (!is_array($embedding) || empty($embedding)) {
                continue;
            }
            $score = vector_math::cosine_similarity($queryvector, $embedding);

            // Drop the vector immediately; only metadata + score are needed downstream.
            unset($row['embedding_json'], $row['_vec'], $row['embedding']);

            if ($heap->count() < $k) {
                $heap->insert(['score' => $score, 'row' => $row]);
            } else if ($score > $heap->top()['score']) {
                $heap->extract();
                $heap->insert(['score' => $score, 'row' => $row]);
            }
        }

        // The heap yields lowest-first; collect then reverse to descending score.
        $ascending = [];
        foreach ($heap as $entry) {
            $ascending[] = $entry;
        }
        $out = [];
        foreach (array_reverse($ascending) as $entry) {
            $row = (array)$entry['row'];
            $row['score'] = (string)($entry['score'] ?? 0.0);
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Build planner-skill contracts from retrieved CSV rows.
     *
     * @param array[] $toprows
     * @param array[] $livecontracts
     * @return array[]
     */
    public function build_planner_catalog_subset(array $toprows, array $livecontracts = []): array {
        $subset = [];
        $contractsbyskill = $this->build_live_contract_lookup($livecontracts);
        $skillregistry = null;
        try {
            $skillregistry = skill_registry_factory::get_default();
        } catch (\Throwable $e) {
            $skillregistry = null;
        }

        foreach ($toprows as $row) {
            $skill = trim((string)($row['skill'] ?? ''));
            if ($skill === '') {
                continue;
            }

            if (isset($contractsbyskill[$skill])) {
                $contract = $contractsbyskill[$skill];
                if (empty($contract['properties']) && $skillregistry !== null) {
                    $liveskill = $skillregistry->get_skill($skill);
                    if ($liveskill !== null) {
                        $schema = (array)$liveskill->get_schema();
                        $contract['properties'] = $this->compact_properties_for_planner((array)($schema['properties'] ?? []));
                    }
                }
                $subset[] = $contract;
                continue;
            }

            $compactproperties = [];
            if ($skillregistry !== null) {
                $liveskill = $skillregistry->get_skill($skill);
                if ($liveskill !== null) {
                    $schema = (array)$liveskill->get_schema();
                    $compactproperties = $this->compact_properties_for_planner((array)($schema['properties'] ?? []));
                }
            }

            // No live contract for this skill (e.g. it is no longer registered). The CSV stores no
            // card metadata, so emit a minimal entry — never fabricate fields from the row.
            $subset[] = [
                'skill' => $skill,
                'intent' => '',
                'readonly' => false,
                'description' => '',
                'minimal_input' => [],
                'example_input' => [],
                'message_triggers' => [],
                'properties' => $compactproperties,
            ];
        }

        return $subset;
    }

    /**
     * Build a skill-name keyed lookup of live prompt contracts.
     *
     * @param array[] $livecontracts
     * @return array
     */
    private function build_live_contract_lookup(array $livecontracts): array {
        $contractsbyskill = [];
        $skillregistry = null;
        try {
            $skillregistry = skill_registry_factory::get_default();
        } catch (\Throwable $e) {
            $skillregistry = null;
        }

        $register = function (array $contract) use (&$contractsbyskill, $skillregistry): void {
            $skillname = trim((string)($contract['skill'] ?? ''));
            if ($skillname === '') {
                return;
            }

            if (!isset($contract['properties']) && $skillregistry !== null) {
                $skill = $skillregistry->get_skill($skillname);
                if ($skill !== null) {
                    $schema = (array)$skill->get_schema();
                    $contract['properties'] = $this->compact_properties_for_planner((array)($schema['properties'] ?? []));
                }
            }

            $contractsbyskill[$skillname] = $contract;
        };

        foreach ($livecontracts as $contract) {
            if (is_array($contract)) {
                $register($contract);
            }
        }

        if (!empty($contractsbyskill)) {
            return $contractsbyskill;
        }

        try {
            $registry = skill_registry_factory::get_default();
            foreach ($registry->get_all_prompt_contracts() as $contract) {
                if (is_array($contract)) {
                    $register($contract);
                }
            }
        } catch (\Throwable $e) {
            return $contractsbyskill;
        }

        return $contractsbyskill;
    }

    /**
     * Build compact schema properties for planner prompts.
     *
     * @param array $properties
     * @return array
     */
    private function compact_properties_for_planner(array $properties): array {
        $compact = [];
        $count = 0;

        foreach ($properties as $name => $spec) {
            if (!is_string($name) || $name === '' || !is_array($spec)) {
                continue;
            }

            $row = [
                'type' => (string)($spec['type'] ?? ''),
                'required' => !empty($spec['required']),
            ];

            $description = trim((string)($spec['description'] ?? ''));
            $description = trim((string)(preg_replace('/\s+/', ' ', $description) ?? $description));
            if ($description !== '') {
                $row['description'] = \core_text::substr($description, 0, 180);
            }

            $compact[$name] = $row;
            $count++;
            if ($count >= 40) {
                break;
            }
        }

        return $compact;
    }
}
