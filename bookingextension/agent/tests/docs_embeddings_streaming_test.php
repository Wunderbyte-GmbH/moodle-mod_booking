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
 * Streaming embeddings repository primitives + bounded-memory docs rebuild.
 *
 * The rebuild used to hold several full copies of the catalog in RAM (read_rows + bykey + scanned +
 * final + write round-trip), OOM-ing the cron process on large corpora. These tests pin the new
 * streaming primitives and prove the rebuild's peak memory is a small fraction of the catalog size.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\embeddings_csv_repository_base
 * @covers     \bookingextension_agent\local\wizard\services\lookup\docs_embeddings_index_service
 */
final class docs_embeddings_streaming_test extends advanced_testcase {
    /** @var string Deterministic embedding model used for hashing. */
    private const MODEL = 'unit-test-model';

    /** @var int Deterministic dimensions used for hashing. */
    private const DIMS = 8;

    /**
     * The streaming writer + streaming/offset readers round-trip losslessly and agree with read_rows().
     */
    public function test_streaming_writer_and_readers_roundtrip(): void {
        $this->resetAfterTest(true);

        $path = make_request_directory() . '/stream.csv';
        $repo = new docs_embeddings_csv_repository($path, '');

        $rows = [];
        for ($i = 0; $i < 25; $i++) {
            $rows[] = [
                'corpus_id' => 'corp',
                // Deliberately include a comma + quote so the RFC-4180 round-trip is exercised.
                'chunk_path' => 'dir/file' . $i . ',"x".md',
                'chunk_title' => 'Title ' . $i,
                'line_start' => (string)($i + 1),
                'line_end' => (string)($i + 5),
                'embedding_model' => self::MODEL,
                'embedding_dimensions' => (string)self::DIMS,
                'content_hash' => sha1('row' . $i),
                'embedding_json' => '[' . $i . '.1,' . $i . '.2,' . $i . '.3]',
            ];
        }

        // Write via the streaming writer.
        $repo->begin_stream_write();
        foreach ($rows as $row) {
            $repo->stream_write_row($row);
        }
        $written = $repo->commit_stream_write();
        $this->assertSame(count($rows), $written);

        // Stream_rows() yields exactly what read_rows() returns.
        $streamed = iterator_to_array($repo->stream_rows(), false);
        $this->assertEquals($repo->read_rows(), $streamed);
        $this->assertCount(count($rows), $streamed);
        $this->assertSame($rows[7]['chunk_path'], $streamed[7]['chunk_path']);
        $this->assertSame($rows[7]['embedding_json'], $streamed[7]['embedding_json']);

        // Offset index + read_row_at fetch the same row content as a full read.
        $built = $repo->build_key_offset_index(static fn(array $r): string => (string)$r['chunk_path']);
        $this->assertSame(count($rows), $built['total']);
        $key = $rows[13]['chunk_path'];
        $this->assertArrayHasKey($key, $built['index']);
        $this->assertSame($rows[13]['content_hash'], $built['index'][$key]['content_hash']);
        $fetched = $repo->read_row_at((int)$built['index'][$key]['offset']);
        $repo->close_random_reader();
        $this->assertSame($rows[13], $fetched);
    }

    /**
     * A discarded streaming write never replaces the published file.
     */
    public function test_discard_stream_write_keeps_previous_file(): void {
        $this->resetAfterTest(true);

        $path = make_request_directory() . '/stream.csv';
        $repo = new docs_embeddings_csv_repository($path, '');
        $repo->write_rows([$this->row('a')]);

        $repo->begin_stream_write();
        $repo->stream_write_row($this->row('b'));
        $repo->discard_stream_write();

        $rows = $repo->read_rows();
        $this->assertCount(1, $rows);
        $this->assertSame('a', $rows[0]['chunk_path']);
        $this->assertFalse(is_file($path . '.tmp'), 'The temp file must be cleaned up on discard.');
    }

    /**
     * The rebuild's peak memory stays a small fraction of the on-disk catalog size, even for a large
     * index processed entirely through the reuse path (no real embedding calls).
     */
    public function test_rebuild_memory_is_bounded_for_large_index(): void {
        $this->resetAfterTest(true);

        if (!class_exists('\\aiprovider_wunderbyte\\aiactions\\generate_embeddings')) {
            $this->markTestSkipped('embeddings provider not available');
        }
        if (!function_exists('memory_reset_peak_usage')) {
            $this->markTestSkipped('memory_reset_peak_usage() unavailable (needs PHP 8.2+)');
        }

        set_config('aiskillenableall', 1, 'bookingextension_agent');

        $root = make_request_directory() . '/corpus';
        mkdir($root, 0777, true);

        // A large catalog: many single-chunk files, each with a sizeable embedding vector so the
        // on-disk file is several MB. Every file has a hash-matching seed row → pure reuse on rebuild.
        $count = 400;
        // The declared dimensions must match the vector length (embeddings_dimension_guard, #2225).
        $bigdims = 1536;
        $bigvector = '[' . implode(',', array_fill(0, $bigdims, '0.123456789')) . ']';
        $seedrows = [];
        for ($i = 0; $i < $count; $i++) {
            $relpath = 'doc' . $i . '.md';
            $content = "# Doc {$i}\n\nBody of document number {$i}.\n";
            file_put_contents($root . '/' . $relpath, $content);
            $seedrows[] = [
                'corpus_id' => 'big',
                'chunk_path' => $relpath,
                'chunk_title' => '',
                'line_start' => '1',
                'line_end' => (string)(substr_count($content, "\n") + 1),
                'embedding_model' => self::MODEL,
                'embedding_dimensions' => (string)$bigdims,
                'content_hash' => sha1($content . '|m=' . self::MODEL . '|d=' . $bigdims),
                'embedding_json' => $bigvector,
            ];
        }

        docs_corpus_registry::set_corpora_for_testing(['big' => $root]);
        $repo = $this->repo($bigdims);
        $repo->write_rows($seedrows);

        $filesize = (int)filesize($repo->get_csv_path());
        $this->assertGreaterThan(3 * 1024 * 1024, $filesize, 'Fixture should be a multi-MB catalog.');

        // Measure peak memory used by the rebuild alone.
        gc_collect_cycles();
        $before = memory_get_usage();
        memory_reset_peak_usage();
        $summary = (new docs_embeddings_index_service())->rebuild('big', self::MODEL, $bigdims, false);
        $peakdelta = memory_get_peak_usage() - $before;

        // Pure reuse: every file copied from the old index, nothing embedded, nothing dropped.
        $this->assertSame('ok', $summary['status']);
        $this->assertSame($count, $summary['reused']);
        $this->assertSame(0, $summary['embedded']);
        $this->assertSame(0, $summary['deleted']);
        $this->assertSame($count, $summary['written']);

        // The old full-array rebuild held ~4-5× the catalog in RAM; the streaming rebuild must stay
        // well under a single copy of the on-disk file.
        $this->assertLessThan(
            $filesize,
            $peakdelta,
            sprintf('Rebuild peak (%d bytes) must stay below the catalog size (%d bytes).', $peakdelta, $filesize)
        );

        docs_corpus_registry::set_corpora_for_testing(null);
    }

    /**
     * Repository bound to the same variant the rebuild writes (MODEL plus the given dimensions).
     *
     * @param int $dims Declared dimensions of the variant.
     * @return docs_embeddings_csv_repository
     */
    private function repo(int $dims = self::DIMS): docs_embeddings_csv_repository {
        return new docs_embeddings_csv_repository(
            null,
            embeddings_csv_repository_base::normalize_variant_key(self::MODEL . '__' . $dims)
        );
    }

    /**
     * A minimal valid docs row with the given chunk_path.
     *
     * @param string $path
     * @return array
     */
    private function row(string $path): array {
        return [
            'corpus_id' => 'corp',
            'chunk_path' => $path,
            'chunk_title' => '',
            'line_start' => '1',
            'line_end' => '1',
            'embedding_model' => self::MODEL,
            'embedding_dimensions' => (string)self::DIMS,
            'content_hash' => sha1($path),
            'embedding_json' => '[0.1]',
        ];
    }
}
