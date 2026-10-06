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

/**
 * Round-trip integrity tests for the skill-catalog embeddings CSV repository.
 *
 * Regression cover for the legacy fputcsv()/fgetcsv() backslash-escape, which silently dropped
 * rows whose payload columns contained JSON escapes (\/, \", \uXXXX) by desyncing the column count.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\embeddings_csv_repository
 */
final class embeddings_csv_repository_test extends advanced_testcase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }
    /**
     * Build one catalog row whose payloads exercise CSV-hostile characters.
     *
     * @param string $skill
     * @return array
     */
    private function make_row(string $skill): array {
        // Embedding vector serialized as a JSON array (commas) — like the real catalog.
        $vector = json_encode(array_map(static function ($i) {
            return round(0.0001 * $i, 6);
        }, range(1, 8)));

        return [
            'skill' => $skill,
            'anchor_index' => '0',
            'anchor_kind' => 'description',
            // Backslashes, commas, double-quotes and a newline in a single field: exactly the content
            // that the legacy backslash escape desynced. The anchor text is the only free-text column
            // left, so it carries the CSV-hostile payload that proves escaping round-trips losslessly.
            'anchor_text' => 'Sets the header image. Path C:\\images\\logo.png, "quoted", line1' . "\n" . 'line2',
            'embedding_model' => 'text-embedding-3-small',
            'embedding_dimensions' => '8',
            'content_hash' => hash('sha256', $skill),
            'embedding_json' => $vector,
        ];
    }

    /**
     * A catalog with JSON/backslash/quote/comma/newline payloads must round-trip losslessly.
     */
    public function test_write_read_roundtrip_with_csv_hostile_payloads(): void {
        $this->resetAfterTest();
        $path = make_request_directory() . '/skill_catalog_embeddings.csv';
        $repo = new embeddings_csv_repository($path);

        $rows = [];
        foreach (['mod_booking.update_option', 'mod_booking.create_option', 'course.add_quiz'] as $skill) {
            $rows[] = $this->make_row($skill);
        }

        $repo->write_rows($rows);

        $read = $repo->read_rows();
        $this->assertCount(count($rows), $read, 'Every written row must be read back (no silent drops).');
        $this->assertSame(0, $repo->count_unreadable_rows(), 'No row may be unreadable after a clean write.');

        foreach ($rows as $i => $expected) {
            foreach (embeddings_csv_repository::HEADERS as $col) {
                $this->assertSame(
                    $expected[$col],
                    $read[$i][$col],
                    "Column {$col} of row {$i} must round-trip identically."
                );
            }
        }
    }

    /**
     * Each model/dimensions variant maps to its own file; the default variant matches the committed
     * fixture name so non-live tests read deterministic data.
     */
    public function test_for_variant_scopes_the_filename(): void {
        $this->resetAfterTest();

        $default = embeddings_csv_repository::for_variant('text-embedding-3-small', 1536);
        $other = embeddings_csv_repository::for_variant('other-model', 8);

        $this->assertStringEndsWith(
            '/skill_catalog_embeddings__text-embedding-3-small__1536.csv',
            $default->get_csv_path(),
            'The default variant must match the committed fixture file name.'
        );
        $this->assertStringEndsWith('/skill_catalog_embeddings__other-model__8.csv', $other->get_csv_path());
        $this->assertNotSame($default->get_csv_path(), $other->get_csv_path());
    }

    /**
     * A file with rows that do not parse to the schema column count is reported as unreadable
     * (so readiness checks force a rebuild) and the malformed rows are never silently served.
     */
    public function test_corrupt_file_is_detected_not_silently_served(): void {
        $this->resetAfterTest();
        $path = make_request_directory() . '/skill_catalog_embeddings.csv';

        $header = implode(',', embeddings_csv_repository::HEADERS);
        $good = 'mod_booking.update_option,0,description,desc anchor,text-embedding-3-small,8,'
            . hash('sha256', 'x') . ',"[0.1,0.2]"';
        $bad = 'broken.skill,too,few,columns';
        file_put_contents($path, $header . "\n" . $good . "\n" . $bad . "\n");

        $repo = new embeddings_csv_repository($path);

        $this->assertSame(1, $repo->count_unreadable_rows(), 'The malformed row must be counted as unreadable.');

        $read = $repo->read_rows();
        $this->assertCount(1, $read, 'Only the well-formed row is returned.');
        $this->assertSame('mod_booking.update_option', $read[0]['skill']);
        $this->assertDebuggingCalled();
    }
}
