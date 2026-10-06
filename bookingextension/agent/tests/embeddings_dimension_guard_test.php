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
use bookingextension_agent\local\wizard\services\retrieval\db_embeddings_store;
use bookingextension_agent\local\wizard\services\retrieval\docs_row_mapper;
use bookingextension_agent\local\wizard\services\retrieval\embedding_row;
use bookingextension_agent\local\wizard\services\retrieval\embeddings_store_factory;

/**
 * Write-time dimension guard: a vector whose length contradicts the declared edims must never be stored.
 *
 * Pins Wunderbyte-GmbH/Wunderbyte-GmbH#2225: the provider config declared 1536 dimensions while the
 * endpoint returned 3584-dim vectors; both stores persisted the rows unchecked and the read path then
 * discarded every vector, leaving the skill catalog silently unretrievable while rebuilds "succeeded".
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\retrieval\embeddings_dimension_guard
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class embeddings_dimension_guard_test extends advanced_testcase {
    /** Test embedding model. */
    private const MODEL = 'test-model';

    /** Declared test dimensions. */
    private const DIMS = 4;

    /** Area under test. */
    private const AREA = docs_row_mapper::AREA;

    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }

    /**
     * Build a row whose vector carries the given number of values under the declared DIMS.
     *
     * @param float[] $vector
     * @return embedding_row
     */
    private function row(array $vector): embedding_row {
        return new embedding_row(
            self::AREA,
            'mod_booking',
            'a.md',
            1,
            'A',
            self::MODEL,
            self::DIMS,
            'h1',
            $vector,
            10
        );
    }

    /**
     * DB store: a mismatching vector (the 3584-under-1536 shape of #2225) is rejected at write time.
     */
    public function test_db_store_rejects_mismatching_vector(): void {
        $this->resetAfterTest();
        $store = new db_embeddings_store(embeddings_store_factory::mappers());
        $gen = $store->begin_generation(self::AREA, self::MODEL, self::DIMS);

        $this->expectException(\coding_exception::class);
        $this->expectExceptionMessage('does not match the declared dimensions');
        $store->upsert(self::AREA, $gen, $this->row([0.5, -0.25, 0.125, -1.0, 0.75]));
    }

    /**
     * DB store: a matching vector is stored and read back intact (guard does not over-block).
     */
    public function test_db_store_accepts_matching_vector(): void {
        $this->resetAfterTest();
        $store = new db_embeddings_store(embeddings_store_factory::mappers());
        $gen = $store->begin_generation(self::AREA, self::MODEL, self::DIMS);
        $store->upsert(self::AREA, $gen, $this->row([0.5, -0.25, 0.125, -1.0]));
        $store->commit_generation(self::AREA, self::MODEL, self::DIMS, $gen);

        $rows = iterator_to_array($store->stream_rows(self::AREA, self::MODEL, self::DIMS), false);
        $this->assertCount(1, $rows);
        $this->assertSame([0.5, -0.25, 0.125, -1.0], $rows[0]->embedding);
    }

    /**
     * CSV store: the same mismatch is rejected before anything is written to the temp file.
     */
    public function test_csv_store_rejects_mismatching_vector(): void {
        $this->resetAfterTest();
        $store = new csv_embeddings_store(embeddings_store_factory::mappers());
        $gen = $store->begin_generation(self::AREA, self::MODEL, self::DIMS);

        $this->expectException(\coding_exception::class);
        $this->expectExceptionMessage('does not match the declared dimensions');
        $store->upsert(self::AREA, $gen, $this->row([0.5, -0.25, 0.125]));
    }

    /**
     * Vectorless rows stay allowed: the read path normalizes unusable blobs to an empty vector and
     * some flows legitimately stage rows without a vector — the guard must not reject those.
     */
    public function test_empty_vector_is_allowed(): void {
        $this->resetAfterTest();
        $store = new db_embeddings_store(embeddings_store_factory::mappers());
        $gen = $store->begin_generation(self::AREA, self::MODEL, self::DIMS);
        $store->upsert(self::AREA, $gen, $this->row([]));
        $store->commit_generation(self::AREA, self::MODEL, self::DIMS, $gen);

        $rows = iterator_to_array($store->stream_rows(self::AREA, self::MODEL, self::DIMS), false);
        $this->assertCount(1, $rows);
        $this->assertSame([], $rows[0]->embedding);
    }
}
