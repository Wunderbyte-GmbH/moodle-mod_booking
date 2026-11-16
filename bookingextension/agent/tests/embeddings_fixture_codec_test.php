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
use bookingextension_agent\local\wizard\services\embeddings\embeddings_fixture_codec;

/**
 * The compact form of an embeddings fixture gives back the CSV file it was made from.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\services\embeddings\embeddings_fixture_codec
 */
final class embeddings_fixture_codec_test extends advanced_testcase {
    /**
     * One row of the skill catalog.
     *
     * @param string $skill
     * @param string $vector JSON text of the vector.
     * @return array
     */
    private function row(string $skill, string $vector): array {
        return [
            'skill' => $skill,
            'anchor_index' => '0',
            'anchor_kind' => 'description',
            'anchor_text' => 'Path C:\\images\\logo.png, "quoted", line1' . "\n" . 'line2 – ä',
            'embedding_model' => 'unit-model',
            'embedding_dimensions' => '4',
            'content_hash' => hash('sha256', $skill),
            'embedding_json' => $vector,
        ];
    }

    /**
     * Packing and unpacking gives the same bytes, also for a row without a vector.
     */
    public function test_pack_and_unpack_give_back_the_same_file(): void {
        $this->resetAfterTest();
        $path = make_request_directory() . '/skill_catalog_embeddings__unit-model__4.csv';
        (new embeddings_csv_repository($path))->write_rows([
            $this->row('a.one', json_encode([-0.0012006759643554688, 0.5, -1.0, 0.0])),
            $this->row('a.two', ''),
            $this->row('a.three', json_encode([65504.0, 2 ** -24, -0.25, 0.333251953125])),
        ]);
        $before = file_get_contents($path);

        $this->assertSame(3, embeddings_fixture_codec::pack($path));
        $this->assertSame(2 * 4 * 2, filesize(embeddings_fixture_codec::vector_path($path)));
        unlink($path);

        $this->assertTrue(embeddings_fixture_codec::unpack($path));
        $this->assertSame($before, file_get_contents($path));
    }

    /**
     * A CSV file that is newer than its compact form is left alone, an older one is rebuilt.
     */
    public function test_unpack_rebuilds_only_an_outdated_file(): void {
        $this->resetAfterTest();
        $path = make_request_directory() . '/skill_catalog_embeddings__unit-model__4.csv';
        (new embeddings_csv_repository($path))->write_rows([$this->row('a.one', json_encode([0.5, 0.25, 0.125, 1.0]))]);
        embeddings_fixture_codec::pack($path);
        $packed = file_get_contents($path);

        file_put_contents($path, 'edited');
        touch($path, time() + 10);
        embeddings_fixture_codec::unpack($path);
        $this->assertSame('edited', file_get_contents($path));

        touch($path, time() - 100);
        embeddings_fixture_codec::unpack_directory(dirname($path));
        $this->assertSame($packed, file_get_contents($path));
    }

    /**
     * Without a compact form nothing is built.
     */
    public function test_unpack_without_a_compact_form(): void {
        $this->resetAfterTest();
        $path = make_request_directory() . '/skill_catalog_embeddings__unit-model__4.csv';
        $this->assertFalse(embeddings_fixture_codec::unpack($path));
        $this->assertFileDoesNotExist($path);
    }

    /**
     * A value that is not exactly a half-float is refused instead of rounded.
     */
    public function test_a_value_that_is_no_half_float_is_refused(): void {
        $this->resetAfterTest();
        $path = make_request_directory() . '/skill_catalog_embeddings__unit-model__4.csv';
        (new embeddings_csv_repository($path))->write_rows([$this->row('a.one', '[0.1,0.5,0.5,0.5]')]);

        $this->expectException(\coding_exception::class);
        embeddings_fixture_codec::pack($path);
    }
}
