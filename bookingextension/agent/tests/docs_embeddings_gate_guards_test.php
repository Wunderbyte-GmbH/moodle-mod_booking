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
use bookingextension_agent\local\wizard\services\lookup\docs_corpus_registry;
use bookingextension_agent\local\wizard\services\lookup\docs_embeddings_csv_repository;
use bookingextension_agent\local\wizard\services\lookup\docs_embeddings_index_service;
use bookingextension_agent\local\wizard\services\lookup\docs_embeddings_readiness_service;

/**
 * The docs-skill gate suppresses all embedding work when the skill is inactive (Phase E1/E3).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\services\lookup\docs_embeddings_index_service
 * @covers     \bookingextension_agent\local\wizard\services\lookup\docs_embeddings_readiness_service
 */
final class docs_embeddings_gate_guards_test extends advanced_testcase {
    /**
     * Two resolvable corpora with an empty index (a coverage gap that would otherwise schedule).
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);

        $base = make_request_directory();
        mkdir($base . '/a', 0777, true);
        mkdir($base . '/b', 0777, true);
        docs_corpus_registry::set_corpora_for_testing(['corpa' => $base . '/a', 'corpb' => $base . '/b']);
        (docs_embeddings_csv_repository::for_active_variant())->write_rows([]);
    }

    /**
     * Restore parsing.
     */
    public function tearDown(): void {
        docs_corpus_registry::set_corpora_for_testing(null);
        (docs_embeddings_csv_repository::for_active_variant())->write_rows([]);
        parent::tearDown();
    }

    /**
     * E1: with the skill inactive, a coverage gap does NOT schedule a rebuild.
     */
    public function test_inactive_skill_never_schedules(): void {
        if (!class_exists('\\aiprovider_wunderbyte\\aiactions\\generate_embeddings')) {
            $this->markTestSkipped('embeddings provider not available');
        }

        $readiness = new docs_embeddings_readiness_service();

        // Default-off: no scheduling despite the missing coverage.
        $this->assertFalse($readiness->ensure_rebuild_scheduled_if_needed());

        // Once active, the same gap does schedule.
        set_config('aiskillenableall', 1, 'bookingextension_agent');
        $this->assertTrue($readiness->ensure_rebuild_scheduled_if_needed());
    }

    /**
     * E3: a direct rebuild() call opts out when the skill is inactive.
     */
    public function test_inactive_skill_skips_rebuild(): void {
        $summary = (new docs_embeddings_index_service())->rebuild('corpa', 'm', 8, false);

        $this->assertSame('skipped', $summary['status']);
        $this->assertSame('skill_inactive', $summary['reason']);
        $this->assertSame(0, $summary['embedded']);
    }
}
