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

namespace bookingextension_agent;

use advanced_testcase;
use bookingextension_agent\local\wizard\services\debug\skill_selection_debug_service;

/**
 * The skill-selection simulator must rank 1:1 with the live planner, which is SEMANTIC-ONLY: when
 * embeddings are present the ranking is embedding-only (lexical is reference-only and must NOT drive
 * it), restricted to the planner pool. A previous hybrid (embedding*0.75 + lexical*0.25) mis-ranked
 * skills the live path never surfaces (thread 5).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \bookingextension_agent\local\wizard\services\debug\skill_selection_debug_service::rank_simulation_candidates
 */
final class skill_selection_simulator_ranking_test extends advanced_testcase {
    /**
     * With embeddings present, ranking is embedding-only: a lexical-only skill (highest lexically)
     * never enters, and a low-embedding/high-lexical skill never outranks a high-embedding one.
     */
    public function test_embedding_only_ranking_ignores_lexical(): void {
        $service = new skill_selection_debug_service();

        $embedding = [
            ['skill' => 'a.high_embed', 'score' => 0.90, 'readonly' => '1'],
            ['skill' => 'b.low_embed', 'score' => 0.40, 'readonly' => '1'],
        ];
        // The c.lexonly skill is the strongest LEXICAL match but has NO embedding row; b has a tiny
        // embedding but a near-perfect lexical score — under the old hybrid it would have jumped ahead.
        $lexicalbyskill = [
            'a.high_embed' => ['lexical_score' => 0.05, 'match_terms' => [], 'readonly' => true, 'intent' => ''],
            'b.low_embed'  => ['lexical_score' => 0.99, 'match_terms' => ['x'], 'readonly' => true, 'intent' => ''],
            'c.lexonly'    => ['lexical_score' => 1.00, 'match_terms' => ['y'], 'readonly' => true, 'intent' => ''],
        ];
        $pool = ['a.high_embed' => true, 'b.low_embed' => true, 'c.lexonly' => true];

        $candidates = $service->rank_simulation_candidates($embedding, $lexicalbyskill, $pool, 10);
        $names = array_map(static fn(array $c): string => $c['skill'], $candidates);

        // Embedding order, lexical ignored; the lexical-only skill is absent (live would not list it).
        $this->assertSame(['a.high_embed', 'b.low_embed'], $names);
        $this->assertNotContains('c.lexonly', $names);
        // The combined_score mirrors the embedding score (no hybrid weighting); lexical rides along as info.
        $this->assertSame(0.90, $candidates[0]['combined_score']);
        $this->assertSame(0.90, $candidates[0]['embedding_score']);
        $this->assertSame(0.99, $candidates[1]['lexical_score']);
        $this->assertSame('embedding', $candidates[0]['source']);
    }

    /**
     * A high-embedding skill outside the planner pool is not shown (mirrors the live executable pool).
     */
    public function test_pool_filter_excludes_out_of_scope_skill(): void {
        $service = new skill_selection_debug_service();

        $embedding = [
            ['skill' => 'in.scope', 'score' => 0.80, 'readonly' => '1'],
            ['skill' => 'out.scope', 'score' => 0.95, 'readonly' => '1'],
        ];
        $candidates = $service->rank_simulation_candidates($embedding, [], ['in.scope' => true], 10);
        $names = array_map(static fn(array $c): string => $c['skill'], $candidates);

        $this->assertSame(['in.scope'], $names);
    }

    /**
     * Without embeddings the lexical ordering is returned only as a flagged debugging aid (not live).
     */
    public function test_lexical_fallback_when_no_embeddings(): void {
        $service = new skill_selection_debug_service();

        $lexicalbyskill = [
            'low.lex'  => ['lexical_score' => 0.2, 'match_terms' => [], 'readonly' => true, 'intent' => ''],
            'high.lex' => ['lexical_score' => 0.8, 'match_terms' => [], 'readonly' => true, 'intent' => ''],
        ];
        $candidates = $service->rank_simulation_candidates([], $lexicalbyskill, [], 10);

        $this->assertSame('high.lex', $candidates[0]['skill']);
        $this->assertNull($candidates[0]['embedding_score']);
        $this->assertSame('lexical', $candidates[0]['source']);
    }
}
