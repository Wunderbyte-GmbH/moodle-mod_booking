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
 * Per-variant (model + dimensions) isolation of the docs embeddings store (Phase F2).
 *
 * A rebuild for one model must write its own file and leave another model's file untouched, so a
 * model switch never invalidates the others and switching back is free.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\services\lookup\docs_embeddings_index_service
 * @covers     \bookingextension_agent\local\wizard\embeddings_csv_repository_base
 */
final class docs_embeddings_variant_test extends advanced_testcase {
    /**
     * Build a repository for a specific model/dims variant.
     *
     * @param string $model
     * @param int    $dims
     * @return docs_embeddings_csv_repository
     */
    private function repo(string $model, int $dims): docs_embeddings_csv_repository {
        return new docs_embeddings_csv_repository(
            null,
            embeddings_csv_repository_base::normalize_variant_key($model . '__' . $dims)
        );
    }

    /**
     * Build a reusable (hash-matching) row for a model/dims variant.
     *
     * @param string $root
     * @param string $relpath
     * @param string $model
     * @param int    $dims
     * @return array
     */
    private function seed_row(string $root, string $relpath, string $model, int $dims): array {
        $content = (string)file_get_contents($root . '/' . $relpath);
        return [
            'corpus_id' => 'corpa', 'chunk_path' => $relpath, 'chunk_title' => '',
            'line_start' => '1', 'line_end' => (string)(substr_count($content, "\n") + 1),
            'embedding_model' => $model, 'embedding_dimensions' => (string)$dims,
            'content_hash' => sha1($content . '|m=' . $model . '|d=' . $dims),
            // Exactly $dims values: the store refuses any other length (embeddings_dimension_guard, #2225).
            'embedding_json' => '[' . implode(',', array_fill(0, $dims, '0.1')) . ']',
        ];
    }

    /**
     * Rebuilding model B does not touch model A's file; switching back to A reuses it.
     */
    public function test_model_switch_keeps_other_variant_file(): void {
        $this->resetAfterTest(true);
        if (!class_exists('\\aiprovider_wunderbyte\\aiactions\\generate_embeddings')) {
            $this->markTestSkipped('embeddings provider not available');
        }
        set_config('aiskillenableall', 1, 'bookingextension_agent');

        $base = make_request_directory();
        mkdir($base . '/a', 0777, true);
        file_put_contents($base . '/a/README.md', "# A\n\nAlpha.\n");
        docs_corpus_registry::set_corpora_for_testing(['corpa' => $base . '/a']);

        // Seed both variant files so each rebuild runs purely through reuse (no embedding call).
        $this->repo('model-a', 8)->write_rows([$this->seed_row($base . '/a', 'README.md', 'model-a', 8)]);
        $this->repo('model-b', 16)->write_rows([$this->seed_row($base . '/a', 'README.md', 'model-b', 16)]);

        // Rebuild under model B.
        $summary = (new docs_embeddings_index_service())->rebuild('corpa', 'model-b', 16, false);
        $this->assertSame('ok', $summary['status']);
        $this->assertSame(1, $summary['reused']);
        $this->assertSame(0, $summary['embedded']);

        // Model A's file is untouched and still reusable.
        $this->assertTrue($this->repo('model-a', 8)->exists(), 'Model A variant file must survive a model-B rebuild.');
        $rowsa = $this->repo('model-a', 8)->read_rows();
        $this->assertCount(1, $rowsa);
        $this->assertSame('model-a', $rowsa[0]['embedding_model']);

        // Switching back to A reuses (no re-embed).
        $back = (new docs_embeddings_index_service())->rebuild('corpa', 'model-a', 8, false);
        $this->assertSame(1, $back['reused']);
        $this->assertSame(0, $back['embedded']);

        docs_corpus_registry::set_corpora_for_testing(null);
    }
}
