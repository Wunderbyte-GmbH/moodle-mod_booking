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

use bookingextension_agent\local\wizard\config\runtime_feature_flags;
use bookingextension_agent\local\wizard\services\telemetry\routing_decision_log_service;
use advanced_testcase;

/**
 * Tests for routing telemetry comparison snapshots.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class routing_decision_log_service_test extends advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Verifies that embedding comparison snapshots expose live-vs-shadow deltas.
     *
     * @covers \bookingextension_agent\local\wizard\services\telemetry\routing_decision_log_service::build_embeddings_comparison
     */
    public function test_build_embeddings_comparison_reports_deltas(): void {
        $comparison = routing_decision_log_service::build_embeddings_comparison([
            'catalogselectionmode' => 'family_boosted',
            'discovery_stage' => 'B',
            'confidence_score' => 0.83,
        ], [
            'catalogselectionmode' => 'slim_all',
            'discovery_stage' => 'A',
            'confidence_score' => 0.50,
        ], [
            runtime_feature_flags::FAMILY_EMBEDDINGS_ENABLED => true,
        ]);

        $this->assertTrue($comparison['family_embeddings_enabled']);
        $this->assertSame('with_vs_without_embeddings', $comparison['comparison_type']);
        $this->assertSame('family_boosted', $comparison['live_catalogselectionmode']);
        $this->assertSame('slim_all', $comparison['shadow_catalogselectionmode']);
        $this->assertTrue($comparison['catalogselectionmode_changed']);
        $this->assertSame('B', $comparison['live_discovery_stage']);
        $this->assertSame('A', $comparison['shadow_discovery_stage']);
        $this->assertTrue($comparison['discovery_stage_changed']);
        $this->assertSame(0.83, $comparison['live_confidence_score']);
        $this->assertSame(0.50, $comparison['shadow_confidence_score']);
        $this->assertSame(0.33, round((float)$comparison['confidence_delta'], 2));
    }
}
