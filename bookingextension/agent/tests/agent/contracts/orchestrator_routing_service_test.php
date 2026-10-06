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

use advanced_testcase;
use bookingextension_agent\local\wizard\services\orchestrator_routing_service;

/**
 * Contract tests for routing debug source telemetry.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class orchestrator_routing_service_test extends advanced_testcase {
    /**
     * Ensures phase telemetry is emitted and can be rewritten on existing sources.
     *
     * @covers \bookingextension_agent\local\wizard\services\orchestrator_routing_service::build_debug_source
     * @covers \bookingextension_agent\local\wizard\services\orchestrator_routing_service::with_phase_in_debug_source
     */
    public function test_debug_source_contains_phase_and_phase_can_be_upserted(): void {
        $service = new orchestrator_routing_service(
            'aiprovider_wunderbyte\\aiactions\\planner_decide'
        );

        $source = $service->build_debug_source(
            'aiprovider_wunderbyte\\aiactions\\planner_decide',
            'wunderbyte',
            false,
            orchestrator_routing_service::PHASE_SELECTION,
            'aiprovider_wunderbyte',
            3,
            1,
            'embed_topk',
            'applied',
            6,
            false,
            false
        );

        $this->assertStringContainsString('|p=sel|', $source);

        $updatedsource = $service->with_phase_in_debug_source(
            $source,
            orchestrator_routing_service::PHASE_PARAMETER_CONSTRUCTION
        );
        $this->assertStringContainsString('|p=cons', $updatedsource);
        $this->assertStringNotContainsString('|p=sel', $updatedsource);
    }
}
