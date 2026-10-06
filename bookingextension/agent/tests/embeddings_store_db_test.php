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
use bookingextension_agent\local\wizard\services\retrieval\db_embeddings_store;
use bookingextension_agent\local\wizard\services\retrieval\docs_row_mapper;
use bookingextension_agent\local\wizard\services\retrieval\embedding_hit;
use bookingextension_agent\local\wizard\services\retrieval\embedding_row;
use bookingextension_agent\local\wizard\services\retrieval\embeddings_store_factory;
use bookingextension_agent\local\wizard\services\retrieval\retrieval_filter;

/**
 * Behaviour tests for the DB-backed embeddings store (Layer 0, P1).
 *
 * Uses the docs area with a throwaway variant. Vector assertions use values that are exactly
 * representable in float32 so the pack/unpack round-trip is bit-exact.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\retrieval\db_embeddings_store
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class embeddings_store_db_test extends advanced_testcase {
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

    /** Area under test. */
    private const AREA = docs_row_mapper::AREA;

    /**
     * Build a fresh DB store over the registered mappers.
     *
     * @return db_embeddings_store
     */
    private function store(): db_embeddings_store {
        return new db_embeddings_store(embeddings_store_factory::mappers());
    }

    /**
     * Build a docs embedding row.
     *
     * @param string $path
     * @param int $start
     * @param float[] $vec
     * @param string $hash
     * @param int|null $contextid
     * @return embedding_row
     */
    private function docrow(string $path, int $start, array $vec, string $hash, ?int $contextid = null): embedding_row {
        return new embedding_row(
            self::AREA,
            'mod_booking',
            $path,
            $start,
            'Title of ' . $path,
            self::MODEL,
            self::DIMS,
            $hash,
            $vec,
            $start + 9,
            null,
            $contextid
        );
    }

    /**
     * begin → upsert → commit publishes the rows; exists/count/stream read them back, and the float32
     * vector round-trips bit-exact for exactly-representable values.
     */
    public function test_generation_roundtrip(): void {
        $this->resetAfterTest();
        $store = $this->store();

        $this->assertFalse($store->exists(self::AREA, self::MODEL, self::DIMS));
        $this->assertSame(0, $store->count_rows(self::AREA, self::MODEL, self::DIMS));

        $gen = $store->begin_generation(self::AREA, self::MODEL, self::DIMS);
        $this->assertSame(1, $gen);
        $store->upsert(self::AREA, $gen, $this->docrow('a.md', 1, [0.5, -0.25, 0.125, -1.0], 'h1'));
        $store->upsert(self::AREA, $gen, $this->docrow('b.md', 1, [0.0, 1.0, 0.0, 0.0], 'h2'));

        // Not visible until committed.
        $this->assertFalse($store->exists(self::AREA, self::MODEL, self::DIMS));

        $written = $store->commit_generation(self::AREA, self::MODEL, self::DIMS, $gen);
        $this->assertSame(2, $written);
        $this->assertTrue($store->exists(self::AREA, self::MODEL, self::DIMS));
        $this->assertSame(2, $store->count_rows(self::AREA, self::MODEL, self::DIMS));

        $rows = iterator_to_array($store->stream_rows(self::AREA, self::MODEL, self::DIMS), false);
        $this->assertCount(2, $rows);
        $bykey = [];
        foreach ($rows as $row) {
            $this->assertInstanceOf(embedding_row::class, $row);
            $bykey[$row->refkey] = $row;
        }
        $this->assertSame('mod_booking', $bykey['a.md']->owner);
        $this->assertSame([0.5, -0.25, 0.125, -1.0], $bykey['a.md']->embedding);
        $this->assertSame(10, $bykey['a.md']->endindex);
        $this->assertSame('h1', $bykey['a.md']->contenthash);
    }

    /**
     * Per-owner counts return each owner's committed figure — the governance page must never
     * show the store-wide total on every row (#2342).
     */
    public function test_count_rows_by_owner_groups_committed_rows(): void {
        $this->resetAfterTest();
        $store = $this->store();

        $gen = $store->begin_generation(self::AREA, self::MODEL, self::DIMS);
        $store->upsert(self::AREA, $gen, $this->docrow('a.md', 1, [0.5, -0.25, 0.125, -1.0], 'h1'));
        $store->upsert(self::AREA, $gen, $this->docrow('b.md', 1, [0.0, 1.0, 0.0, 0.0], 'h2'));
        $other = new embedding_row(
            self::AREA,
            'other_owner',
            'c.md',
            1,
            'Title of c.md',
            self::MODEL,
            self::DIMS,
            'h3',
            [1.0, 0.0, 0.0, 0.0],
            10,
            null,
            null
        );
        $store->upsert(self::AREA, $gen, $other);
        $store->commit_generation(self::AREA, self::MODEL, self::DIMS, $gen);

        $counts = $store->count_rows_by_owner(self::AREA, self::MODEL, self::DIMS);

        $this->assertSame(['mod_booking' => 2, 'other_owner' => 1], $counts);
    }

    /**
     * A dims change must not leave a dead meta row behind: committing heals same-area+model
     * leftovers with other dims — the get_record()-on-duplicates trap of #2340.
     */
    public function test_commit_heals_stale_other_dims_meta(): void {
        global $DB;
        $this->resetAfterTest();
        $store = $this->store();

        $DB->insert_record('bx_agent_embeddings_meta', (object)[
            'area' => self::AREA,
            'emodel' => self::MODEL,
            'edims' => 1536,
            'committedgeneration' => 0,
            'fingerprint' => 'chunker:v1',
            'timemodified' => time() - DAYSECS,
        ]);

        $gen = $store->begin_generation(self::AREA, self::MODEL, self::DIMS);
        $store->upsert(self::AREA, $gen, $this->docrow('a.md', 1, [0.5, -0.25, 0.125, -1.0], 'h1'));
        $store->commit_generation(self::AREA, self::MODEL, self::DIMS, $gen);

        $metas = $DB->get_records('bx_agent_embeddings_meta', ['area' => self::AREA, 'emodel' => self::MODEL]);
        $this->assertCount(1, $metas, 'committing must heal the stale other-dims meta row');
        $this->assertSame(self::DIMS, (int)reset($metas)->edims);
    }

    /**
     * search_top_k ranks by cosine and returns typed hits (no vector), honouring minscore.
     */
    public function test_search_top_k_ranks_and_filters(): void {
        $this->resetAfterTest();
        $store = $this->store();

        $gen = $store->begin_generation(self::AREA, self::MODEL, self::DIMS);
        $store->upsert(self::AREA, $gen, $this->docrow('near.md', 1, [1.0, 0.0, 0.0, 0.0], 'h1'));
        $store->upsert(self::AREA, $gen, $this->docrow('mid.md', 1, [0.5, 0.5, 0.0, 0.0], 'h2'));
        $store->upsert(self::AREA, $gen, $this->docrow('far.md', 1, [0.0, 1.0, 0.0, 0.0], 'h3'));
        $store->commit_generation(self::AREA, self::MODEL, self::DIMS, $gen);

        $hits = $store->search_top_k(self::AREA, self::MODEL, self::DIMS, [1.0, 0.0, 0.0, 0.0], 3, 0.0);
        $this->assertNotEmpty($hits);
        $this->assertInstanceOf(embedding_hit::class, $hits[0]);
        $this->assertSame('near.md', $hits[0]->refkey);
        $this->assertSame('far.md', $hits[count($hits) - 1]->refkey);
        $this->assertGreaterThan($hits[1]->score, $hits[0]->score + 1e-9);

        // The minscore threshold drops the orthogonal (score 0) row.
        $filtered = $store->search_top_k(self::AREA, self::MODEL, self::DIMS, [1.0, 0.0, 0.0, 0.0], 3, 0.5);
        $keys = array_map(static fn(embedding_hit $h): string => $h->refkey, $filtered);
        $this->assertContains('near.md', $keys);
        $this->assertNotContains('far.md', $keys);
    }

    /**
     * A committed row is reusable by its identity key from a later, uncommitted generation.
     */
    public function test_reuse_existing_across_generations(): void {
        $this->resetAfterTest();
        $store = $this->store();

        $gen1 = $store->begin_generation(self::AREA, self::MODEL, self::DIMS);
        $store->upsert(self::AREA, $gen1, $this->docrow('keep.md', 5, [0.5, 0.25, 0.125, 0.0625], 'hkeep'));
        $store->commit_generation(self::AREA, self::MODEL, self::DIMS, $gen1);

        $gen2 = $store->begin_generation(self::AREA, self::MODEL, self::DIMS);
        $this->assertSame(2, $gen2);
        $reused = $store->reuse_existing(self::AREA, self::MODEL, self::DIMS, 'mod_booking|keep.md|5');
        $this->assertInstanceOf(embedding_row::class, $reused);
        $this->assertSame('hkeep', $reused->contenthash);
        $this->assertSame([0.5, 0.25, 0.125, 0.0625], $reused->embedding);
        $this->assertNull($store->reuse_existing(self::AREA, self::MODEL, self::DIMS, 'mod_booking|missing.md|1'));

        $store->discard_generation(self::AREA, self::MODEL, self::DIMS, $gen2);
        // Discard left the committed generation intact.
        $this->assertSame(1, $store->count_rows(self::AREA, self::MODEL, self::DIMS));
    }

    /**
     * A new generation is invisible until committed; committing swaps atomically and prunes the old one.
     */
    public function test_generation_swap_prunes_old(): void {
        global $DB;
        $this->resetAfterTest();
        $store = $this->store();

        $gen1 = $store->begin_generation(self::AREA, self::MODEL, self::DIMS);
        $store->upsert(self::AREA, $gen1, $this->docrow('old1.md', 1, [1.0, 0.0, 0.0, 0.0], 'o1'));
        $store->upsert(self::AREA, $gen1, $this->docrow('old2.md', 1, [0.0, 1.0, 0.0, 0.0], 'o2'));
        $store->commit_generation(self::AREA, self::MODEL, self::DIMS, $gen1);
        $this->assertSame(2, $store->count_rows(self::AREA, self::MODEL, self::DIMS));

        // Build a second generation with different content; readers still see generation 1.
        $gen2 = $store->begin_generation(self::AREA, self::MODEL, self::DIMS);
        $this->assertSame(2, $gen2);
        $store->upsert(self::AREA, $gen2, $this->docrow('new1.md', 1, [1.0, 0.0, 0.0, 0.0], 'n1'));
        $this->assertSame(2, $store->count_rows(self::AREA, self::MODEL, self::DIMS));
        $stillold = array_map(
            static fn(embedding_row $r): string => $r->refkey,
            iterator_to_array($store->stream_rows(self::AREA, self::MODEL, self::DIMS), false)
        );
        sort($stillold);
        $this->assertSame(['old1.md', 'old2.md'], $stillold);

        // Commit generation 2: reader flips, and generation 1 rows are physically pruned.
        $store->commit_generation(self::AREA, self::MODEL, self::DIMS, $gen2);
        $this->assertSame(1, $store->count_rows(self::AREA, self::MODEL, self::DIMS));
        $this->assertSame(0, $DB->count_records('bx_agent_embeddings', ['generation' => $gen1]));
        $this->assertSame(1, $DB->count_records('bx_agent_embeddings', ['generation' => $gen2]));
    }

    /**
     * delete_by_context removes only the rows carrying that context id.
     */
    public function test_delete_by_context(): void {
        $this->resetAfterTest();
        $store = $this->store();

        $gen = $store->begin_generation(self::AREA, self::MODEL, self::DIMS);
        $store->upsert(self::AREA, $gen, $this->docrow('ctx1.md', 1, [1.0, 0.0, 0.0, 0.0], 'c1', 555));
        $store->upsert(self::AREA, $gen, $this->docrow('ctx2.md', 1, [0.0, 1.0, 0.0, 0.0], 'c2', 555));
        $store->upsert(self::AREA, $gen, $this->docrow('global.md', 1, [0.0, 0.0, 1.0, 0.0], 'c3', null));
        $store->commit_generation(self::AREA, self::MODEL, self::DIMS, $gen);
        $this->assertSame(3, $store->count_rows(self::AREA, self::MODEL, self::DIMS));

        $store->delete_by_context(555);
        $this->assertSame(1, $store->count_rows(self::AREA, self::MODEL, self::DIMS));
        $rows = iterator_to_array($store->stream_rows(self::AREA, self::MODEL, self::DIMS), false);
        $this->assertCount(1, $rows);
        $this->assertSame('global.md', $rows[0]->refkey);
    }

    /**
     * The context filter narrows retrieval to the allowed contexts (and an empty allow-list yields none).
     */
    public function test_search_top_k_context_filter(): void {
        $this->resetAfterTest();
        $store = $this->store();

        $gen = $store->begin_generation(self::AREA, self::MODEL, self::DIMS);
        $store->upsert(self::AREA, $gen, $this->docrow('in.md', 1, [1.0, 0.0, 0.0, 0.0], 'c1', 777));
        $store->upsert(self::AREA, $gen, $this->docrow('out.md', 1, [1.0, 0.0, 0.0, 0.0], 'c2', 888));
        $store->commit_generation(self::AREA, self::MODEL, self::DIMS, $gen);

        $allowed = new retrieval_filter([777]);
        $hits = $store->search_top_k(self::AREA, self::MODEL, self::DIMS, [1.0, 0.0, 0.0, 0.0], 5, 0.0, $allowed);
        $keys = array_map(static fn(embedding_hit $h): string => $h->refkey, $hits);
        $this->assertSame(['in.md'], $keys);
        $this->assertSame(777, $hits[0]->contextid);

        $none = new retrieval_filter([]);
        $this->assertSame([], $store->search_top_k(self::AREA, self::MODEL, self::DIMS, [1.0, 0.0, 0.0, 0.0], 5, 0.0, $none));
    }

    /**
     * The source fingerprint round-trips through the meta table.
     */
    public function test_fingerprint_roundtrip(): void {
        $this->resetAfterTest();
        $store = $this->store();

        $this->assertSame('', $store->fingerprint(self::AREA, self::MODEL, self::DIMS));
        $store->set_fingerprint(self::AREA, self::MODEL, self::DIMS, 'fp-abc');
        $this->assertSame('fp-abc', $store->fingerprint(self::AREA, self::MODEL, self::DIMS));
        $store->set_fingerprint(self::AREA, self::MODEL, self::DIMS, 'fp-def');
        $this->assertSame('fp-def', $store->fingerprint(self::AREA, self::MODEL, self::DIMS));
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
