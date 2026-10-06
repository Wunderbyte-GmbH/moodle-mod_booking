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
use bookingextension_agent\local\wizard\embeddings_csv_repository_base;
use bookingextension_agent\local\wizard\services\lookup\docs_corpus_registry;
use bookingextension_agent\local\wizard\services\lookup\docs_embeddings_csv_repository;
use bookingextension_agent\local\wizard\services\lookup\docs_embeddings_index_service;

/**
 * Non-destructive prune / per-corpus scoping for the docs embeddings rebuild (Phase B1/B2).
 *
 * Drives the rebuild entirely through the hash-reuse path (every scanned file has a matching seed
 * row) so it never issues a real embedding call, and asserts the merge rules:
 *  - rows of corpora not scanned this run are kept,
 *  - rows of corpora no longer declared are pruned,
 *  - a vanished file in a scanned corpus drops out.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\services\lookup\docs_embeddings_index_service
 */
final class docs_embeddings_index_prune_test extends advanced_testcase {
    /** @var string Deterministic embedding model used for hashing. */
    private const MODEL = 'unit-test-model';

    /** @var int Deterministic dimensions used for hashing. */
    private const DIMS = 8;

    /**
     * A vector of exactly DIMS values: the store refuses any other length (embeddings_dimension_guard, #2225).
     *
     * @return string JSON array.
     */
    private static function vector_json(): string {
        return '[' . implode(',', array_fill(0, self::DIMS, '0.1')) . ']';
    }

    /** @var string */
    private string $roota;

    /** @var string */
    private string $rootb;

    /**
     * Create two in-process corpora and a clean index file.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);

        if (!class_exists('\\aiprovider_wunderbyte\\aiactions\\generate_embeddings')) {
            $this->markTestSkipped('embeddings provider not available');
        }

        // The rebuild is gated on the docs skill being active (Phase E3).
        set_config('aiskillenableall', 1, 'bookingextension_agent');

        $base = make_request_directory();
        $this->roota = $base . '/corpa';
        $this->rootb = $base . '/corpb';
        mkdir($this->roota . '/sub', 0777, true);
        mkdir($this->rootb, 0777, true);

        file_put_contents($this->roota . '/README.md', "# A Home\n\nAlpha.\n");
        file_put_contents($this->roota . '/sub/a.md', "# A Sub\n\nAlpha sub.\n");
        file_put_contents($this->rootb . '/README.md', "# B Home\n\nBravo.\n");

        docs_corpus_registry::set_corpora_for_testing([
            'corpa' => $this->roota,
            'corpb' => $this->rootb,
        ]);

        // Start every test from an empty index file.
        ($this->repo())->write_rows([]);
    }

    /**
     * Restore corpus parsing.
     */
    public function tearDown(): void {
        docs_corpus_registry::set_corpora_for_testing(null);
        ($this->repo())->write_rows([]);
        parent::tearDown();
    }

    /**
     * Repository bound to the same variant the rebuild writes (MODEL/DIMS).
     *
     * @return docs_embeddings_csv_repository
     */
    private function repo(): docs_embeddings_csv_repository {
        return new docs_embeddings_csv_repository(
            null,
            embeddings_csv_repository_base::normalize_variant_key(self::MODEL . '__' . self::DIMS)
        );
    }

    /**
     * Build a reusable (hash-matching) index row for a file.
     *
     * @param string $corpusid
     * @param string $root
     * @param string $relpath
     * @return array
     */
    private function seed_row(string $corpusid, string $root, string $relpath): array {
        $content = (string)file_get_contents($root . '/' . $relpath);
        $hash = sha1($content . '|m=' . self::MODEL . '|d=' . self::DIMS);
        return [
            'corpus_id' => $corpusid,
            'chunk_path' => $relpath,
            'chunk_title' => '',
            'line_start' => '1',
            'line_end' => (string)(substr_count($content, "\n") + 1),
            'embedding_model' => self::MODEL,
            'embedding_dimensions' => (string)self::DIMS,
            'content_hash' => $hash,
            'embedding_json' => self::vector_json(),
        ];
    }

    /**
     * Return the corpus_ids present in the on-disk index.
     *
     * @return string[]
     */
    private function corpus_ids_in_index(): array {
        $ids = [];
        foreach (($this->repo())->read_rows() as $row) {
            $ids[trim((string)$row['corpus_id'])] = true;
        }
        return array_keys($ids);
    }

    /**
     * A scoped rebuild keeps other corpora untouched and prunes an undeclared corpus.
     */
    public function test_scoped_rebuild_keeps_others_and_prunes_undeclared(): void {
        $repo = $this->repo();
        $repo->write_rows([
            $this->seed_row('corpa', $this->roota, 'README.md'),
            $this->seed_row('corpa', $this->roota, 'sub/a.md'),
            $this->seed_row('corpb', $this->rootb, 'README.md'),
            // An orphan row whose corpus is no longer declared → must be pruned.
            [
                'corpus_id' => 'gone', 'chunk_path' => 'x.md', 'chunk_title' => '',
                'line_start' => '1', 'line_end' => '1', 'embedding_model' => self::MODEL,
                'embedding_dimensions' => (string)self::DIMS, 'content_hash' => 'deadbeef',
                'embedding_json' => self::vector_json(),
            ],
        ]);

        $summary = (new docs_embeddings_index_service())->rebuild('corpa', self::MODEL, self::DIMS, false);

        $this->assertSame('ok', $summary['status']);
        $this->assertSame(2, $summary['reused'], 'Both corpa files reuse their embeddings (no new calls).');
        $this->assertSame(0, $summary['embedded'], 'No new embedding call may happen on a pure reuse run.');

        $ids = $this->corpus_ids_in_index();
        $this->assertContains('corpa', $ids);
        $this->assertContains('corpb', $ids, 'corpb was not scoped this run and must be kept.');
        $this->assertNotContains('gone', $ids, 'An undeclared corpus must be pruned.');
    }

    /**
     * A file that vanished from a scanned corpus drops out; a still-present file is kept.
     */
    public function test_vanished_file_in_scanned_corpus_drops_out(): void {
        $repo = $this->repo();
        $repo->write_rows([
            $this->seed_row('corpa', $this->roota, 'README.md'),
            $this->seed_row('corpa', $this->roota, 'sub/a.md'),
            $this->seed_row('corpb', $this->rootb, 'README.md'),
        ]);

        // Remove one corpa file before rebuilding.
        unlink($this->roota . '/sub/a.md');

        $summary = (new docs_embeddings_index_service())->rebuild('corpa', self::MODEL, self::DIMS, false);

        $this->assertSame('ok', $summary['status']);
        $this->assertSame(1, $summary['reused']);

        $rows = ($this->repo())->read_rows();
        $paths = array_map(static fn($r) => $r['corpus_id'] . '/' . $r['chunk_path'], $rows);
        $this->assertContains('corpa/README.md', $paths);
        $this->assertNotContains('corpa/sub/a.md', $paths, 'A vanished file must drop out.');
        $this->assertContains('corpb/README.md', $paths, 'Unscanned corpb stays.');
    }
}
