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
use bookingextension_agent\local\wizard\services\debug\skill_selection_debug_service;
use bookingextension_agent\local\wizard\services\retrieval\db_embeddings_store;
use bookingextension_agent\local\wizard\services\retrieval\embedding_row;
use bookingextension_agent\local\wizard\services\retrieval\embeddings_store_factory;
use bookingextension_agent\local\wizard\services\retrieval\skill_row_mapper;

/**
 * The collision analysis is computed once (rebuild task / debug recompute) and persisted; the
 * governance page only ever reads the persisted result. Same-skill anchor pairs are excluded.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\debug\skill_selection_debug_service
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class skill_collision_cache_test extends advanced_testcase {
    /**
     * Compute-and-cache persists the analysis, a fresh reader sees it, and no self-pairs appear.
     */
    public function test_compute_and_cache_roundtrip_without_self_pairs(): void {
        $this->resetAfterTest();
        set_config('embeddingsstore', 'db', 'bookingextension_agent');

        $resolved = (new embeddings_action_config_resolver())->resolve();
        $model = (string)$resolved['model'];
        $dims = (int)$resolved['dimensions'];

        // Two skills with two anchors each; within-skill vectors are near-identical so a missing
        // same-skill filter would dominate the pair list.
        $store = new db_embeddings_store(embeddings_store_factory::mappers());
        $gen = $store->begin_generation(skill_row_mapper::AREA, $model, $dims);
        $anchors = [
            ['skill.one', 0, $this->vector($dims, 0)],
            ['skill.one', 1, $this->vector($dims, 0, 0.01)],
            ['skill.two', 0, $this->vector($dims, 1)],
            ['skill.two', 1, $this->vector($dims, 1, 0.01)],
        ];
        foreach ($anchors as [$skill, $index, $vector]) {
            $store->upsert(skill_row_mapper::AREA, $gen, new embedding_row(
                skill_row_mapper::AREA,
                $skill,
                'description',
                $index,
                $skill . ' anchor ' . $index,
                $model,
                $dims,
                sha1($skill . $index),
                $vector
            ));
        }
        $store->commit_generation(skill_row_mapper::AREA, $model, $dims, $gen);

        $service = new skill_selection_debug_service();
        $this->assertNull($service->get_cached_collisions());

        $computed = $service->compute_and_cache_collisions(50);
        $this->assertTrue($computed['has_embeddings']);
        $this->assertNotEmpty($computed['pairs']);
        $this->assertGreaterThan(0, (int)$computed['computedat']);
        foreach ($computed['pairs'] as $pair) {
            $this->assertNotSame($pair['skill_a'], $pair['skill_b'], 'Same-skill pairs must be excluded.');
        }

        // A fresh service instance reads the identical persisted result — no recomputation involved.
        $cached = (new skill_selection_debug_service())->get_cached_collisions();
        $this->assertIsArray($cached);
        $this->assertSame($computed['computedat'], $cached['computedat']);
        $this->assertSame(
            json_encode($computed['pairs']),
            json_encode($cached['pairs'])
        );
    }

    /**
     * An absent, corrupt or shape-invalid persisted value reads as null (callers fall back gracefully).
     */
    public function test_cache_absent_or_corrupt_reads_null(): void {
        $this->resetAfterTest();
        $service = new skill_selection_debug_service();

        $this->assertNull($service->get_cached_collisions());

        set_config('skillcollisioncache', 'not-json{', 'bookingextension_agent');
        $this->assertNull($service->get_cached_collisions());

        set_config('skillcollisioncache', json_encode(['nopairs' => true]), 'bookingextension_agent');
        $this->assertNull($service->get_cached_collisions());
    }

    /**
     * A unit vector along one of two axes, optionally tilted slightly (stays float32-exact enough).
     *
     * @param int $dims
     * @param int $axis 0 or 1.
     * @param float $tilt Small component on the other axis.
     * @return float[]
     */
    private function vector(int $dims, int $axis, float $tilt = 0.0): array {
        $vec = array_fill(0, $dims, 0.0);
        $vec[$axis] = 1.0;
        $vec[1 - $axis] = $tilt;
        return $vec;
    }
}
