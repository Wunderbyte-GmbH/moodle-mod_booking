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
use bookingextension_agent\local\wizard\embeddings_action_config_resolver;
use bookingextension_agent\local\wizard\embeddings_csv_repository;
use bookingextension_agent\local\wizard\services\retrieval\csv_embeddings_store;
use bookingextension_agent\local\wizard\services\retrieval\db_embeddings_store;
use bookingextension_agent\local\wizard\services\retrieval\docs_row_mapper;
use bookingextension_agent\local\wizard\services\retrieval\embedding_row;
use bookingextension_agent\local\wizard\services\retrieval\embeddings_store_factory;
use bookingextension_agent\local\wizard\services\retrieval\embeddings_store_migration_service;
use bookingextension_agent\local\wizard\services\retrieval\skill_row_mapper;

/**
 * P3: CSV → DB migration copies vectors without re-embedding (docs AND skills areas).
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\retrieval\embeddings_store_migration_service
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class embeddings_store_migration_test extends advanced_testcase {
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
     * A CSV store over the registered mappers.
     *
     * @return csv_embeddings_store
     */
    private function csv_store(): csv_embeddings_store {
        return new csv_embeddings_store(embeddings_store_factory::mappers());
    }

    /**
     * A DB store over the registered mappers.
     *
     * @return db_embeddings_store
     */
    private function db_store(): db_embeddings_store {
        return new db_embeddings_store(embeddings_store_factory::mappers());
    }

    /**
     * Seed a two-row committed CSV index (exactly-representable vectors) plus a fingerprint.
     *
     * @return void
     */
    private function seed_csv(): void {
        $csv = $this->csv_store();
        $gen = $csv->begin_generation(self::AREA, self::MODEL, self::DIMS);
        $csv->upsert(self::AREA, $gen, new embedding_row(
            self::AREA,
            'mod_booking',
            'a.md',
            1,
            'A',
            self::MODEL,
            self::DIMS,
            'h1',
            [0.5, -0.25, 0.125, -1.0],
            10
        ));
        $csv->upsert(self::AREA, $gen, new embedding_row(
            self::AREA,
            'mod_booking',
            'b.md',
            1,
            'B',
            self::MODEL,
            self::DIMS,
            'h2',
            [0.0, 1.0, 0.0, 0.0],
            10
        ));
        $csv->commit_generation(self::AREA, self::MODEL, self::DIMS, $gen);
        $csv->set_fingerprint(self::AREA, self::MODEL, self::DIMS, 'fp-csv');
    }

    /**
     * Migration copies every CSV row (vectors + hashes + fingerprint) into the DB backend.
     */
    public function test_migrate_copies_csv_to_db(): void {
        $this->resetAfterTest();
        $this->seed_csv();

        $result = (new embeddings_store_migration_service())->migrate_csv_to_db(self::AREA, self::MODEL, self::DIMS);
        $this->assertSame('ok', $result['status']);
        $this->assertSame(2, $result['migrated']);

        $db = $this->db_store();
        $this->assertTrue($db->exists(self::AREA, self::MODEL, self::DIMS));
        $this->assertSame(2, $db->count_rows(self::AREA, self::MODEL, self::DIMS));
        $this->assertSame('fp-csv', $db->fingerprint(self::AREA, self::MODEL, self::DIMS));

        $bykey = [];
        foreach ($db->stream_rows(self::AREA, self::MODEL, self::DIMS) as $row) {
            $bykey[$row->refkey] = $row;
        }
        $this->assertSame([0.5, -0.25, 0.125, -1.0], $bykey['a.md']->embedding);
        $this->assertSame('h1', $bykey['a.md']->contenthash);
        $this->assertSame(10, $bykey['a.md']->endindex);

        // The migrated identity is queryable for reuse (proves identityhash was written on upsert).
        $reused = $db->reuse_existing(self::AREA, self::MODEL, self::DIMS, 'mod_booking|a.md|1');
        $this->assertNotNull($reused);
        $this->assertSame('h1', $reused->contenthash);
    }

    /**
     * The if-needed variant does not clobber an already-populated DB index.
     */
    public function test_migrate_if_needed_skips_when_db_populated(): void {
        $this->resetAfterTest();
        $this->seed_csv();

        $svc = new embeddings_store_migration_service();
        $svc->migrate_csv_to_db(self::AREA, self::MODEL, self::DIMS);

        $again = $svc->migrate_csv_to_db_if_needed(self::AREA, self::MODEL, self::DIMS);
        $this->assertSame('skipped', $again['status']);
        $this->assertSame('db_already_populated', $again['reason']);
    }

    /**
     * With no CSV index present, migration is a no-op (the rebuild fallback handles population).
     */
    public function test_migrate_skips_when_no_csv(): void {
        $this->resetAfterTest();

        $result = (new embeddings_store_migration_service())->migrate_csv_to_db(self::AREA, self::MODEL, self::DIMS);
        $this->assertSame('skipped', $result['status']);
        $this->assertSame('no_csv_index', $result['reason']);
    }

    /**
     * Migration copies the SKILLS area too: vectors, anchor identity, content hashes and the
     * fingerprint reach the DB backend, and the migrated identity is reusable (§11.24).
     */
    public function test_migrate_copies_skills_csv_to_db(): void {
        $this->resetAfterTest();

        $model = 'mig-skills-model';
        $dims = 4;
        $area = skill_row_mapper::AREA;

        try {
            // Seed a committed two-anchor skills CSV (exactly-representable float32 vectors).
            $csv = $this->csv_store();
            $gen = $csv->begin_generation($area, $model, $dims);
            $csv->upsert($area, $gen, new embedding_row(
                $area,
                'mod_booking.create_option',
                'description',
                0,
                'create a booking option',
                $model,
                $dims,
                'sh1',
                [0.5, -0.25, 0.125, -1.0]
            ));
            $csv->upsert($area, $gen, new embedding_row(
                $area,
                'mod_booking.create_option',
                'utterance',
                1,
                'add a course date',
                $model,
                $dims,
                'sh2',
                [0.0, 1.0, 0.0, 0.0]
            ));
            $csv->commit_generation($area, $model, $dims, $gen);
            $csv->set_fingerprint($area, $model, $dims, 'fp-skills');

            $svc = new embeddings_store_migration_service();
            $result = $svc->migrate_csv_to_db($area, $model, $dims);
            $this->assertSame('ok', $result['status']);
            $this->assertSame(2, $result['migrated']);

            $db = $this->db_store();
            $this->assertTrue($db->exists($area, $model, $dims));
            $this->assertSame(2, $db->count_rows($area, $model, $dims));
            $this->assertSame('fp-skills', $db->fingerprint($area, $model, $dims));

            $byanchor = [];
            foreach ($db->stream_rows($area, $model, $dims) as $row) {
                $byanchor[$row->owner . '|' . $row->refindex] = $row;
            }
            $this->assertSame([0.5, -0.25, 0.125, -1.0], $byanchor['mod_booking.create_option|0']->embedding);
            $this->assertSame('description', $byanchor['mod_booking.create_option|0']->refkey);
            $this->assertSame('sh1', $byanchor['mod_booking.create_option|0']->contenthash);

            // The migrated identity is queryable for rebuild reuse (skills identity = skill|anchor_index).
            $reused = $db->reuse_existing($area, $model, $dims, 'mod_booking.create_option|1');
            $this->assertNotNull($reused);
            $this->assertSame('sh2', $reused->contenthash);

            // Idempotent: a second run never clobbers the populated DB index.
            $again = $svc->migrate_csv_to_db_if_needed($area, $model, $dims);
            $this->assertSame('skipped', $again['status']);
            $this->assertSame('db_already_populated', $again['reason']);
        } finally {
            $this->cleanup_skills_csv($model, $dims);
        }
    }

    /**
     * The embeddingsstore settings callback migrates the skills area of the ACTIVE variant when the
     * backend is switched to db (fail-open per area; the docs area simply skips without a CSV).
     */
    public function test_settings_callback_migrates_skills_area(): void {
        $this->resetAfterTest();

        $resolved = (new embeddings_action_config_resolver())->resolve();
        $model = (string)$resolved['model'];
        $dims = (int)$resolved['dimensions'];
        if (!$this->csv_store()->exists(skill_row_mapper::AREA, $model, $dims)) {
            $this->markTestSkipped('no skills CSV fixture for the active embeddings variant');
        }

        set_config('embeddingsstore', 'db', 'bookingextension_agent');
        embeddings_store_migration_service::on_embeddingsstore_setting_updated(
            'bookingextension_agent/embeddingsstore'
        );

        $db = $this->db_store();
        $this->assertTrue($db->exists(skill_row_mapper::AREA, $model, $dims));
        $this->assertSame(
            $this->csv_store()->count_rows(skill_row_mapper::AREA, $model, $dims),
            $db->count_rows(skill_row_mapper::AREA, $model, $dims)
        );
    }

    /**
     * Remove the variant-scoped skills CSV artefacts a test created (under PHPUNIT the skills CSV
     * path points into the fixtures directory, which must stay clean).
     *
     * @param string $model
     * @param int $dims
     * @return void
     */
    private function cleanup_skills_csv(string $model, int $dims): void {
        $repo = embeddings_csv_repository::for_variant($model, $dims);
        if (file_exists($repo->get_csv_path())) {
            unlink($repo->get_csv_path());
        }
        if (file_exists($repo->get_fingerprint_path())) {
            unlink($repo->get_fingerprint_path());
        }
    }
}
