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

use advanced_testcase;
use bookingextension_agent\local\wizard\services\retrieval\csv_embeddings_store;
use bookingextension_agent\local\wizard\services\retrieval\docs_row_mapper;
use bookingextension_agent\local\wizard\services\retrieval\embedding_hit;
use bookingextension_agent\local\wizard\services\retrieval\embedding_row;
use bookingextension_agent\local\wizard\services\retrieval\embeddings_store_factory;

/**
 * Behaviour tests for the CSV-backed embeddings store (Layer 0, P0).
 *
 * Uses the docs area with a throwaway variant so it writes only to the test temp dir (never the
 * skill-catalog fixture).
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\retrieval\csv_embeddings_store
 * @covers     \bookingextension_agent\local\wizard\services\retrieval\docs_row_mapper
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class embeddings_store_csv_test extends advanced_testcase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }
    /** Test embedding model. */
    private const MODEL = 'test-model';

    /** Test embedding dimensions. */
    private const DIMS = 4;

    /**
     * Build a fresh CSV store over the registered mappers.
     *
     * @return csv_embeddings_store
     */
    private function store(): csv_embeddings_store {
        return new csv_embeddings_store(embeddings_store_factory::mappers());
    }

    /**
     * Build a docs embedding row.
     *
     * @param string $path
     * @param int $start
     * @param float[] $vec
     * @param string $hash
     * @return embedding_row
     */
    private function docrow(string $path, int $start, array $vec, string $hash): embedding_row {
        return new embedding_row(
            docs_row_mapper::AREA,
            'mod_booking',
            $path,
            $start,
            'Title of ' . $path,
            self::MODEL,
            self::DIMS,
            $hash,
            $vec,
            $start + 9
        );
    }

    /**
     * begin → upsert → commit publishes the rows; exists/count/stream read them back.
     */
    public function test_generation_roundtrip(): void {
        $this->resetAfterTest();
        $store = $this->store();

        $this->assertFalse($store->exists(docs_row_mapper::AREA, self::MODEL, self::DIMS));

        $gen = $store->begin_generation(docs_row_mapper::AREA, self::MODEL, self::DIMS);
        $store->upsert(docs_row_mapper::AREA, $gen, $this->docrow('a.md', 1, [1.0, 0.0, 0.0, 0.0], 'h1'));
        $store->upsert(docs_row_mapper::AREA, $gen, $this->docrow('b.md', 1, [0.0, 1.0, 0.0, 0.0], 'h2'));
        $written = $store->commit_generation(docs_row_mapper::AREA, self::MODEL, self::DIMS, $gen);

        $this->assertSame(2, $written);
        $this->assertTrue($store->exists(docs_row_mapper::AREA, self::MODEL, self::DIMS));
        $this->assertSame(2, $store->count_rows(docs_row_mapper::AREA, self::MODEL, self::DIMS));

        $rows = iterator_to_array($store->stream_rows(docs_row_mapper::AREA, self::MODEL, self::DIMS), false);
        $this->assertCount(2, $rows);
        $this->assertInstanceOf(embedding_row::class, $rows[0]);
        $this->assertSame('mod_booking', $rows[0]->owner);
        $this->assertSame('a.md', $rows[0]->refkey);
        $this->assertSame([1.0, 0.0, 0.0, 0.0], $rows[0]->embedding);
        $this->assertSame(10, $rows[0]->endindex);
    }

    /**
     * search_top_k ranks by cosine and returns typed hits (no vector), honouring minscore.
     */
    public function test_search_top_k_ranks_and_filters(): void {
        $this->resetAfterTest();
        $store = $this->store();

        $gen = $store->begin_generation(docs_row_mapper::AREA, self::MODEL, self::DIMS);
        $store->upsert(docs_row_mapper::AREA, $gen, $this->docrow('near.md', 1, [1.0, 0.0, 0.0, 0.0], 'h1'));
        $store->upsert(docs_row_mapper::AREA, $gen, $this->docrow('mid.md', 1, [0.7, 0.7, 0.0, 0.0], 'h2'));
        $store->upsert(docs_row_mapper::AREA, $gen, $this->docrow('far.md', 1, [0.0, 1.0, 0.0, 0.0], 'h3'));
        $store->commit_generation(docs_row_mapper::AREA, self::MODEL, self::DIMS, $gen);

        $hits = $store->search_top_k(docs_row_mapper::AREA, self::MODEL, self::DIMS, [1.0, 0.0, 0.0, 0.0], 3, 0.0);
        $this->assertNotEmpty($hits);
        $this->assertInstanceOf(embedding_hit::class, $hits[0]);
        // The exact-match vector ranks first, the orthogonal one last.
        $this->assertSame('near.md', $hits[0]->refkey);
        $this->assertSame('far.md', $hits[count($hits) - 1]->refkey);
        $this->assertGreaterThan($hits[1]->score, $hits[0]->score + 1e-9);

        // The minscore threshold drops the orthogonal (score 0) row.
        $filtered = $store->search_top_k(docs_row_mapper::AREA, self::MODEL, self::DIMS, [1.0, 0.0, 0.0, 0.0], 3, 0.5);
        $keys = array_map(static fn(embedding_hit $h): string => $h->refkey, $filtered);
        $this->assertContains('near.md', $keys);
        $this->assertNotContains('far.md', $keys);
    }

    /**
     * On rebuild, an unchanged chunk is reusable by its identity key (skips re-embedding).
     */
    public function test_reuse_existing_returns_prior_row(): void {
        $this->resetAfterTest();
        $store = $this->store();

        $gen1 = $store->begin_generation(docs_row_mapper::AREA, self::MODEL, self::DIMS);
        $store->upsert(docs_row_mapper::AREA, $gen1, $this->docrow('keep.md', 5, [0.1, 0.2, 0.3, 0.4], 'hkeep'));
        $store->commit_generation(docs_row_mapper::AREA, self::MODEL, self::DIMS, $gen1);

        // A new generation can look up the committed row by (corpus|path|line_start).
        $gen2 = $store->begin_generation(docs_row_mapper::AREA, self::MODEL, self::DIMS);
        $reused = $store->reuse_existing(docs_row_mapper::AREA, self::MODEL, self::DIMS, 'mod_booking|keep.md|5');
        $this->assertInstanceOf(embedding_row::class, $reused);
        $this->assertSame('hkeep', $reused->contenthash);
        $this->assertSame([0.1, 0.2, 0.3, 0.4], $reused->embedding);
        $this->assertNull($store->reuse_existing(docs_row_mapper::AREA, self::MODEL, self::DIMS, 'mod_booking|missing.md|1'));
        $store->discard_generation(docs_row_mapper::AREA, self::MODEL, self::DIMS, $gen2);

        // Discard did not touch the committed file.
        $this->assertSame(1, $store->count_rows(docs_row_mapper::AREA, self::MODEL, self::DIMS));
    }

    /**
     * The docs mapper round-trips a row through CSV form without loss.
     */
    public function test_docs_mapper_roundtrip(): void {
        $mapper = new docs_row_mapper();
        $row = $this->docrow('round.md', 3, [0.5, -0.5, 0.25, 0.0], 'hround');
        $back = $mapper->to_row($mapper->to_csv($row));
        $this->assertSame($row->owner, $back->owner);
        $this->assertSame($row->refkey, $back->refkey);
        $this->assertSame($row->refindex, $back->refindex);
        $this->assertSame($row->endindex, $back->endindex);
        $this->assertSame($row->title, $back->title);
        $this->assertSame($row->contenthash, $back->contenthash);
        $this->assertSame($row->embedding, $back->embedding);
        $this->assertSame('mod_booking|round.md|3', $mapper->identity_key($mapper->to_csv($row)));
    }

    /**
     * An unknown area is a coding error.
     */
    public function test_unknown_area_throws(): void {
        $this->resetAfterTest();
        $this->expectException(\coding_exception::class);
        $this->store()->exists('nope', self::MODEL, self::DIMS);
    }
}
