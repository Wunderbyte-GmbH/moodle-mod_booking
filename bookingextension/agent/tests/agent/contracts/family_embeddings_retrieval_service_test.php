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

declare(strict_types=1);

namespace bookingextension_agent\agent\contracts;

use bookingextension_agent\local\wizard\services\embeddings\family_embeddings_retrieval_service;
use advanced_testcase;

/**
 * Tests for family embeddings retrieval helper.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class family_embeddings_retrieval_service_test extends advanced_testcase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }
    /**
     * Verifies that family scores can re-rank skill rows.
     *
     * @covers \bookingextension_agent\local\wizard\services\embeddings\family_embeddings_retrieval_service::boost_skill_rows
     */
    public function test_boost_skill_rows_uses_family_scores(): void {
        $service = new family_embeddings_retrieval_service();

        // Until 2026-09-20 this case asserted the opposite: create_option (0.20) beat get_current_user (0.50)
        // because its family scored 0.90 against 0.10. That is a family weight of 0.30 deciding the ranking
        // outright, and it is the reason the preference was never safe to switch on — on a booking page the
        // correct answer is regularly a course or core skill (baseline runs 17-19: UA-3, SC-2, ACS-2, EU-2).
        // The family is now a tie-breaker at 0.08, so a candidate that is clearly better semantically stays on
        // top; test_boost_skill_rows_breaks_a_tie below pins the other direction.
        $rows = $service->boost_skill_rows([
            ['skill' => 'mod_booking.create_option', 'score' => '0.20'],
            ['skill' => 'mod_booking.list_options', 'score' => '0.10'],
            ['skill' => 'core.get_current_user', 'score' => '0.50'],
        ], [
            'mod_booking.general' => 0.90,
            'core.general' => 0.10,
        ]);

        $this->assertSame('core.get_current_user', $rows[0]['skill']);
        $this->assertSame('core.general', $rows[0]['family']);
        $this->assertSame(0.47, round((float)$rows[0]['score'], 2));
        $families = array_values(array_unique(array_map(static fn(array $row): string => (string)$row['family'], $rows)));
        $this->assertContains('mod_booking.general', $families);
    }

    /**
     * The other direction: where the semantic scores are even, the better-matching family decides.
     *
     * That is the whole point of the preference — it settles a tie, it does not overrule one.
     *
     * @covers \bookingextension_agent\local\wizard\services\embeddings\family_embeddings_retrieval_service::boost_skill_rows
     */
    public function test_boost_skill_rows_breaks_a_tie(): void {
        $service = new family_embeddings_retrieval_service();

        $rows = $service->boost_skill_rows([
            ['skill' => 'core.get_current_user', 'score' => '0.500'],
            ['skill' => 'mod_booking.create_option', 'score' => '0.499'],
        ], [
            'mod_booking.general' => 0.90,
            'core.general' => 0.10,
        ]);

        $this->assertSame('mod_booking.create_option', $rows[0]['skill']);
    }

    /**
     * Verifies that only requested families receive semantic scores.
     *
     * @covers \bookingextension_agent\local\wizard\services\embeddings\family_embeddings_retrieval_service::score_families
     */
    public function test_score_families_returns_requested_families_only(): void {
        $service = new family_embeddings_retrieval_service();

        $scores = $service->score_families([
            'mod_booking.general',
            'local_entities.general',
        ], [1.0, 0.0], [
            ['skill' => 'mod_booking.create_option', 'embedding_json' => json_encode([1.0, 0.0])],
            ['skill' => 'forum_reply', 'embedding_json' => json_encode([0.0, 1.0])],
        ]);

        $this->assertArrayHasKey('mod_booking.general', $scores);
        $this->assertArrayHasKey('local_entities.general', $scores);
        $this->assertSame(1.0, round($scores['mod_booking.general'], 2));
        $this->assertSame(0.0, round($scores['local_entities.general'], 2));
    }
}
