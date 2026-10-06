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

use bookingextension_agent\local\wizard\orchestrator;
use bookingextension_agent\local\wizard\skill_registry_factory;
use bookingextension_agent\local\wizard\services\embeddings\embeddings_catalog_builder_service;
use bookingextension_agent\local\wizard\services\embeddings\embeddings_retrieval_service;
use bookingextension_agent\local\wizard\services\planner_catalog_service;

/**
 * Routing guard: every recorded request keeps its skill inside the selector's window.
 *
 * The selector only sees the skills whose best anchor is closest to the request (top-k, orchestrator::
 * EMBEDDINGS_DEFAULT_TOP_K). A card change can push a neighbour out of that window without touching it: in L44
 * (2026-09-26) two new anchors of other skills moved a settings skill from the window to rank 14 for a request it had
 * answered in every run before, and the request lost its skill in 4 of 4 runs. No test saw it, because nothing ranked
 * the catalog before a live run.
 *
 * The fixture (tests/agent/fixtures/routing_guard/) holds the catalog index as the provider embedded it and, for each
 * recorded request, its English-normalised query vector and the skill that answers it. Vectors are IEEE half floats,
 * little endian: the provider's vectors are exact halves, so this is lossless. The ranking is the engine's own
 * (embeddings_retrieval_service::search_top_k_skills). The fixture must describe the current cards - the first test
 * fails as soon as a card's anchor text changes without a new export - so a card change is always ranked before it
 * ships.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\services\embeddings\embeddings_retrieval_service
 * @covers \bookingextension_agent\local\wizard\services\embeddings\embeddings_catalog_builder_service
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class routing_guard_test extends \advanced_testcase {
    /** @var string Fixture directory. */
    private const DIR = __DIR__ . '/fixtures/routing_guard';

    /**
     * The fixture's catalog is the catalog the current cards produce: same anchors, same anchor texts.
     *
     * Without this the guard would rank a stale catalog and pass while the live one has already moved.
     */
    public function test_catalog_fixture_matches_the_current_cards(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $meta = $this->read_json('catalog.json');
        $current = (new embeddings_catalog_builder_service())->build_full_catalog_rows(
            skill_registry_factory::get_default(),
            (string)$meta['model'],
            (int)$meta['dimensions']
        );
        $expected = [];
        foreach ($current as $row) {
            $expected[$row['skill'] . '#' . $row['anchor_index']] = $row['content_hash'];
        }
        $actual = [];
        foreach ($meta['rows'] as $row) {
            $actual[$row['skill'] . '#' . $row['anchor_index']] = $row['content_hash'];
        }
        // A skill whose description is site configuration (named by the export) carries the site's text in the
        // fixture and the default text here; only that one anchor is exempt, its utterances are still compared.
        $siteconfigured = array_map(static fn(string $skill): string => $skill . '#0', (array)($meta['site_configured'] ?? []));
        $changed = array_values(array_diff(array_keys(array_diff_assoc($expected, $actual)), $siteconfigured));
        $extra = array_keys(array_diff_key($actual, $expected));
        $this->assertSame(
            [[], []],
            [$changed, $extra],
            'The routing-guard catalog is stale: re-export it after rebuilding the index '
                . '(changed or new anchors, then anchors that no longer exist).'
        );
    }

    /**
     * Each recorded request finds its skill among the skills the selector is shown.
     *
     * The discovery meta-skills are appended to every top-k catalog, so they count as always shown.
     */
    public function test_every_recorded_request_keeps_its_skill_in_the_selector_window(): void {
        $catalogmeta = $this->read_json('catalog.json');
        $dims = (int)$catalogmeta['dimensions'];
        $vectors = $this->read_vectors('catalog.f16', count($catalogmeta['rows']), $dims);
        $rows = [];
        foreach ($catalogmeta['rows'] as $i => $row) {
            $rows[] = ['skill' => $row['skill'], 'anchor_index' => (string)$row['anchor_index'], 'embedding' => $vectors[$i]];
        }
        $querymeta = $this->read_json('queries.json');
        $queryvectors = $this->read_vectors('queries.f16', count($querymeta['queries']), $dims);
        $this->assertNotEmpty($querymeta['queries']);

        $retrieval = new embeddings_retrieval_service();
        $lost = [];
        foreach ($querymeta['queries'] as $i => $query) {
            if (in_array($query['skill'], planner_catalog_service::DISCOVERY_META_SKILLS, true)) {
                continue;
            }
            $ranked = array_column($retrieval->search_top_k_skills($queryvectors[$i], $rows, count($rows)), 'skill');
            $rank = array_search($query['skill'], $ranked, true);
            if ($rank === false || $rank >= orchestrator::EMBEDDINGS_DEFAULT_TOP_K) {
                $lost[] = $query['id'] . ' ' . $query['skill'] . ' rank ' . ($rank === false ? '-' : $rank + 1);
            }
        }
        $this->assertSame([], $lost, 'Requests whose skill is no longer shown to the selector.');
    }

    /**
     * Decode one JSON file of the fixture.
     *
     * @param string $name
     * @return array
     */
    private function read_json(string $name): array {
        return json_decode((string)file_get_contents(self::DIR . '/' . $name), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Decode a file of concatenated half-float vectors.
     *
     * @param string $name
     * @param int $count Number of vectors.
     * @param int $dims Dimensions per vector.
     * @return array<int, float[]>
     */
    private function read_vectors(string $name, int $count, int $dims): array {
        $bin = (string)file_get_contents(self::DIR . '/' . $name);
        $this->assertSame($count * $dims * 2, strlen($bin), "$name does not match its index");
        $table = [];
        for ($h = 0; $h < 0x10000; $h++) {
            $sign = ($h & 0x8000) ? -1.0 : 1.0;
            $exp = ($h >> 10) & 0x1f;
            $mant = $h & 0x3ff;
            $table[$h] = $exp === 0 ? $sign * $mant * 2 ** -24 : $sign * (1 + $mant / 1024) * 2 ** ($exp - 15);
        }
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = array_map(
                static fn(int $h): float => $table[$h],
                array_values(unpack('v*', substr($bin, $i * $dims * 2, $dims * 2)))
            );
        }
        return $out;
    }
}
