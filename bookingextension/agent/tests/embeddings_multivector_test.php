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

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\services\embeddings\embeddings_retrieval_service;
use bookingextension_agent\local\wizard\services\embeddings\embeddings_catalog_builder_service;
use bookingextension_agent\local\wizard\skill_registry_factory;

/**
 * Unit tests for multi-vector semantic discovery (anchors + max-aggregation retrieval).
 *
 * Uses hand-built fake vectors only — no embeddings provider call. See SKILL_REWORK.md §5.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\services\embeddings\embeddings_retrieval_service::search_top_k_skills
 * @covers     \bookingextension_agent\local\wizard\services\embeddings\embeddings_catalog_builder_service::build_full_catalog_rows
 */
final class embeddings_multivector_test extends \advanced_testcase {
    /**
     * Build one fake anchor row.
     *
     * @param string $skill
     * @param int $index
     * @param string $kind
     * @param string $text
     * @param float[] $vector
     * @return array
     */
    private function anchor_row(string $skill, int $index, string $kind, string $text, array $vector): array {
        return [
            'skill' => $skill,
            'anchor_index' => (string)$index,
            'anchor_kind' => $kind,
            'anchor_text' => $text,
            'embedding_json' => json_encode($vector),
        ];
    }

    /**
     * Retrieval aggregates anchor scores to the MAX per skill and returns top-k DISTINCT skills,
     * surfacing which anchor won.
     */
    public function test_retrieval_max_aggregation_distinct_and_topk(): void {
        $rows = [
            // Skill A: description far from the query, but one utterance very close → A wins via utterance.
            $this->anchor_row('ns.a', 0, 'description', 'a description', [0.0, 1.0, 0.0]),
            $this->anchor_row('ns.a', 1, 'utterance', 'the winning utterance', [0.9, 0.1, 0.0]),
            // Skill B: single moderate anchor.
            $this->anchor_row('ns.b', 0, 'description', 'b description', [0.6, 0.6, 0.0]),
            // Skill C: orthogonal to the query.
            $this->anchor_row('ns.c', 0, 'description', 'c description', [0.0, 0.0, 1.0]),
        ];
        $query = [1.0, 0.0, 0.0];

        $service = new embeddings_retrieval_service();
        $top = $service->search_top_k_skills($query, $rows, 3);

        // Three distinct skills, A first (its best anchor beats B and C).
        $this->assertSame(['ns.a', 'ns.b', 'ns.c'], array_column($top, 'skill'));

        // A is represented exactly once despite having two anchors (distinct-skill aggregation).
        $this->assertCount(1, array_filter($top, static fn($r): bool => $r['skill'] === 'ns.a'));

        // A's winning anchor is the utterance, not the description.
        $this->assertSame('utterance', $top[0]['matched_anchor_kind']);
        $this->assertSame('the winning utterance', $top[0]['matched_anchor_text']);
        $this->assertGreaterThan((float)$top[1]['score'], (float)$top[0]['score']);

        // Top-k cut returns exactly k distinct skills.
        $two = $service->search_top_k_skills($query, $rows, 2);
        $this->assertSame(['ns.a', 'ns.b'], array_column($two, 'skill'));
    }

    /**
     * The builder emits one row PER ANCHOR (description #0 + each example_utterance), each with the
     * anchor text as its embedding input and a per-anchor content hash.
     */
    public function test_builder_emits_per_anchor_rows(): void {
        $this->resetAfterTest();
        $registry = skill_registry_factory::get_default();
        $rows = (new embeddings_catalog_builder_service())->build_full_catalog_rows($registry, 'text-embedding-3-small', 1536);
        $this->assertNotEmpty($rows);

        // Pick a skill known to declare example_utterances (authored in increment 2a).
        $target = 'wizard.list_skills';
        $anchors = array_values(array_filter($rows, static fn($r): bool => ($r['skill'] ?? '') === $target));
        $this->assertNotEmpty($anchors, "Expected anchor rows for {$target}.");

        $kinds = array_column($anchors, 'anchor_kind');
        $this->assertContains('description', $kinds, 'There must be a description anchor (#0).');
        $this->assertContains('utterance', $kinds, 'There must be at least one utterance anchor.');

        // Anchor #0 is the description; embedding input equals the anchor text; hashes are per-anchor.
        $hashes = [];
        foreach ($anchors as $row) {
            $this->assertSame(
                (string)$row['anchor_text'],
                (string)$row['_embedding_input'],
                'Embedding input must be the anchor text alone.'
            );
            if ((string)$row['anchor_index'] === '0') {
                $this->assertSame('description', (string)$row['anchor_kind']);
            }
            $hashes[] = (string)$row['content_hash'];
        }
        $this->assertSame(count($hashes), count(array_unique($hashes)), 'Each anchor needs its own content hash.');
    }
}
