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
use bookingextension_agent\local\wizard\embeddings_csv_repository;
use bookingextension_agent\local\wizard\services\embeddings\embeddings_catalog_builder_service;
use bookingextension_agent\local\wizard\services\embeddings\family_embeddings_index_service;
use bookingextension_agent\local\wizard\services\retrieval\embedding_row;
use bookingextension_agent\local\wizard\services\retrieval\embeddings_store_factory;
use bookingextension_agent\local\wizard\services\retrieval\skill_row_mapper;
use bookingextension_agent\local\wizard\skill_registry_factory;

/**
 * Skills wiring (§11.24): the catalog rebuild writes through the embeddings store as a generation
 * swap, on BOTH backends selected by the embeddingsstore flag.
 *
 * The committed index is pre-seeded with a hash-matching row for EVERY current anchor, so the
 * rebuild runs entirely through the vector-reuse path and never issues a real embedding call
 * (mirrors docs_embeddings_index_prune_test).
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\embeddings\family_embeddings_index_service
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class skills_embeddings_index_store_test extends advanced_testcase {
    /** @var string Deterministic embedding model used for hashing (never a real fixture variant). */
    private const MODEL = 'unit-skills-model';

    /** @var int Deterministic dimensions used for hashing. */
    private const DIMS = 3;

    /** @var float[] Fake seed vector (exactly representable in float32). */
    private const VECTOR = [1.0, 0.5, -0.5];

    /**
     * Skip without the provider class (the rebuild is gated on it, even on a pure-reuse run).
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        if (!class_exists('\\aiprovider_wunderbyte\\aiactions\\generate_embeddings')) {
            $this->markTestSkipped('embeddings provider not available');
        }
    }

    /**
     * Remove the variant-scoped skills CSV artefacts the CSV backend wrote (under PHPUNIT the skills
     * CSV path points into the fixtures directory, which must stay clean).
     */
    public function tearDown(): void {
        $repo = embeddings_csv_repository::for_variant(self::MODEL, self::DIMS);
        if (file_exists($repo->get_csv_path())) {
            unlink($repo->get_csv_path());
        }
        if (file_exists($repo->get_fingerprint_path())) {
            unlink($repo->get_fingerprint_path());
        }
        parent::tearDown();
    }

    /**
     * Backend flag values the rebuild must work on.
     *
     * @return array
     */
    public static function backend_provider(): array {
        return [
            'csv backend' => ['csv'],
            'db backend' => ['db'],
        ];
    }

    /**
     * A rebuild over a fully seeded committed index reuses every anchor vector through the store
     * generation swap — no embedding call, no deletion, every skill 'untouched'.
     *
     * @dataProvider backend_provider
     * @param string $backend
     */
    public function test_rebuild_reuses_vectors_via_generation_swap(string $backend): void {
        set_config('embeddingsstore', $backend, 'bookingextension_agent');

        $area = skill_row_mapper::AREA;
        $registry = skill_registry_factory::get_default();
        $rows = (new embeddings_catalog_builder_service())
            ->build_full_catalog_rows($registry, self::MODEL, self::DIMS);
        $this->assertNotEmpty($rows);

        // Seed the committed index with a hash-matching row (and fake vector) per current anchor.
        $store = embeddings_store_factory::instance();
        $gen = $store->begin_generation($area, self::MODEL, self::DIMS);
        foreach ($rows as $row) {
            $store->upsert($area, $gen, new embedding_row(
                $area,
                (string)$row['skill'],
                (string)$row['anchor_kind'],
                (int)$row['anchor_index'],
                (string)$row['anchor_text'],
                self::MODEL,
                self::DIMS,
                (string)$row['content_hash'],
                self::VECTOR
            ));
        }
        $store->commit_generation($area, self::MODEL, self::DIMS, $gen);

        $summary = (new family_embeddings_index_service())
            ->rebuild_catalog($registry, self::MODEL, self::DIMS, false);

        $this->assertSame('written', $summary['status']);
        $this->assertSame(count($rows), $summary['written']);
        $this->assertSame(count($rows), $summary['reused'], 'Every anchor must reuse its committed vector.');
        $this->assertSame(0, $summary['embedded'], 'No embedding call may happen on a pure reuse run.');
        $this->assertSame(0, $summary['deleted']);
        $this->assertSame(
            ['untouched'],
            array_values(array_unique((array)$summary['skillstates'])),
            'A fully hash-matched catalog leaves every skill untouched.'
        );

        // The swap republished every anchor with its vector intact.
        $streamed = 0;
        foreach ($store->stream_rows($area, self::MODEL, self::DIMS) as $stored) {
            $this->assertSame(self::VECTOR, $stored->embedding);
            $streamed++;
        }
        $this->assertSame(count($rows), $streamed);
    }
}
