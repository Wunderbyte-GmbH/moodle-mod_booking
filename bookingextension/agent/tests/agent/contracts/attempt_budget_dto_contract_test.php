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

namespace bookingextension_agent\agent\contracts;

use bookingextension_agent\local\wizard\services\attempt_budget_dto;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for attempt budget DTO.
 *
 * @covers \bookingextension_agent\local\wizard\services\attempt_budget_dto
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class attempt_budget_dto_contract_test extends TestCase {
    /**
     * from_loop exports stable counters and exhausted reason.
     */
    public function test_from_loop_exports_stable_payload(): void {
        $payload = attempt_budget_dto::from_loop(3, 6, 'BUDGET_EXCEEDED')->to_array();

        $this->assertSame('global_view', $payload['scope']);
        $this->assertSame(3, $payload['total_attempts']);
        $this->assertSame(3, $payload['loop_attempts']);
        $this->assertSame(0, $payload['preflight_retries']);
        $this->assertSame(0, $payload['execution_retries']);
        $this->assertSame(0, $payload['queue_retries']);
        $this->assertSame(6, $payload['hard_limit']);
        $this->assertSame('BUDGET_EXCEEDED', $payload['exhausted_reason']);
        $this->assertSame(3, $payload['remaining_llm_calls']);
    }

    /**
     * from_queue_item maps retry counters consistently.
     */
    public function test_from_queue_item_maps_retry_counters(): void {
        $payload = attempt_budget_dto::from_queue_item([
            'preflight_retry_count' => 2,
            'retry_count' => 3,
        ], 8)->to_array();

        $this->assertSame(5, $payload['total_attempts']);
        $this->assertSame(0, $payload['loop_attempts']);
        $this->assertSame(2, $payload['preflight_retries']);
        $this->assertSame(3, $payload['execution_retries']);
        $this->assertSame(3, $payload['queue_retries']);
        $this->assertSame(8, $payload['hard_limit']);
        $this->assertSame('', $payload['exhausted_reason']);
        $this->assertSame(8, $payload['remaining_llm_calls']);
    }
}
