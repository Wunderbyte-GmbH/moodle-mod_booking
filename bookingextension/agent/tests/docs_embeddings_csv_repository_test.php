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
use bookingextension_agent\local\wizard\services\lookup\docs_embeddings_csv_repository;

/**
 * Round-trip integrity tests for the documentation embeddings CSV repository.
 *
 * Regression cover for the legacy fputcsv()/fgetcsv() backslash-escape, which silently dropped
 * rows whose payload columns contained JSON escapes (\/, \", \uXXXX) by desyncing the column count.
 * The docs repository now inherits the RFC-4180/atomic base, so it must round-trip losslessly.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\embeddings_csv_repository_base
 * @covers     \bookingextension_agent\local\wizard\services\lookup\docs_embeddings_csv_repository
 */
final class docs_embeddings_csv_repository_test extends advanced_testcase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }
    /**
     * Build one chunk row whose payloads exercise CSV-hostile characters.
     *
     * @param string $corpusid
     * @param string $chunkpath
     * @return array
     */
    private function make_row(string $corpusid, string $chunkpath): array {
        $vector = json_encode(array_map(static function ($i) {
            return round(0.0001 * $i, 6);
        }, range(1, 8)));

        return [
            'corpus_id' => $corpusid,
            'chunk_path' => $chunkpath,
            // Backslashes, commas, double-quotes and a newline in a single field.
            'chunk_title' => 'Path C:\\docs\\readme.md, "quoted", line1' . "\n" . 'line2',
            'line_start' => '1',
            'line_end' => '40',
            'embedding_model' => 'text-embedding-3-small',
            'embedding_dimensions' => '8',
            'content_hash' => hash('sha256', $corpusid . $chunkpath),
            'embedding_json' => (string)$vector,
        ];
    }

    /**
     * A docs index with JSON/backslash/quote/comma/newline payloads must round-trip losslessly.
     */
    public function test_write_read_roundtrip_with_csv_hostile_payloads(): void {
        $this->resetAfterTest();
        $path = make_request_directory() . '/docs_embeddings.csv';
        $repo = new docs_embeddings_csv_repository($path);

        $rows = [
            $this->make_row('mod_booking', 'README.md'),
            $this->make_row('mod_booking', 'guides/options.md'),
            $this->make_row('bookingextension_agent', 'README.md'),
        ];

        $repo->write_rows($rows);

        $read = $repo->read_rows();
        $this->assertCount(count($rows), $read, 'Every written row must be read back (no silent drops).');
        $this->assertSame(0, $repo->count_unreadable_rows(), 'No row may be unreadable after a clean write.');

        foreach ($rows as $i => $expected) {
            foreach (docs_embeddings_csv_repository::HEADERS as $col) {
                $this->assertSame(
                    $expected[$col],
                    $read[$i][$col],
                    "Column {$col} of row {$i} must round-trip identically."
                );
            }
        }
    }

    /**
     * read_rows_for_corpus() returns only the requested corpus, untouched by the hardening.
     */
    public function test_read_rows_for_corpus_filters_by_corpus(): void {
        $this->resetAfterTest();
        $path = make_request_directory() . '/docs_embeddings.csv';
        $repo = new docs_embeddings_csv_repository($path);

        $repo->write_rows([
            $this->make_row('mod_booking', 'README.md'),
            $this->make_row('mod_booking', 'guides/options.md'),
            $this->make_row('bookingextension_agent', 'README.md'),
        ]);

        $booking = $repo->read_rows_for_corpus('mod_booking');
        $this->assertCount(2, $booking);
        foreach ($booking as $row) {
            $this->assertSame('mod_booking', $row['corpus_id']);
        }
    }

    /**
     * A malformed row is counted as unreadable and never silently served.
     */
    public function test_corrupt_file_is_detected_not_silently_served(): void {
        $this->resetAfterTest();
        $path = make_request_directory() . '/docs_embeddings.csv';

        $header = implode(',', docs_embeddings_csv_repository::HEADERS);
        $good = 'mod_booking,README.md,Title,1,40,text-embedding-3-small,8,' . hash('sha256', 'x') . ',"[0.1,0.2]"';
        $bad = 'mod_booking,too,few,columns';
        file_put_contents($path, $header . "\n" . $good . "\n" . $bad . "\n");

        $repo = new docs_embeddings_csv_repository($path);

        $this->assertSame(1, $repo->count_unreadable_rows(), 'The malformed row must be counted as unreadable.');

        $read = $repo->read_rows();
        $this->assertCount(1, $read, 'Only the well-formed row is returned.');
        $this->assertSame('README.md', $read[0]['chunk_path']);
        $this->assertDebuggingCalled();
    }

    /**
     * A non-empty variant key isolates the file name, leaving the legacy file untouched.
     */
    public function test_variant_key_suffixes_filename(): void {
        $this->resetAfterTest();
        $base = make_request_directory() . '/docs_embeddings.csv';

        $legacy = new docs_embeddings_csv_repository($base);
        $variant = new docs_embeddings_csv_repository($base, 'text-embedding-3-large__1536');

        $this->assertSame($base, $legacy->get_csv_path());
        $this->assertSame(
            substr($base, 0, -4) . '__text-embedding-3-large__1536.csv',
            $variant->get_csv_path()
        );
        $this->assertNotSame($legacy->get_csv_path(), $variant->get_csv_path());
    }
}
