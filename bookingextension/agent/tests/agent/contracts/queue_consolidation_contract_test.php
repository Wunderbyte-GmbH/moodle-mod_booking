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

use bookingextension_agent\local\wizard\services\queue_command_mapper;
use bookingextension_agent\local\wizard\services\queue_status_policy;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for queue status policy and queue command mapping.
 *
 * @covers \bookingextension_agent\local\wizard\services\queue_status_policy
 * @covers \bookingextension_agent\local\wizard\services\queue_command_mapper
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class queue_consolidation_contract_test extends TestCase {
    /**
     * Actionable mutating statuses remain stable for decision and confirm flows.
     */
    public function test_queue_status_policy_actionable_mutating_statuses_are_stable(): void {
        $this->assertSame(
            ['queued', 'blocked_confirmation', 'ready', 'retry_waiting'],
            queue_status_policy::actionable_mutating_statuses()
        );
        $this->assertTrue(queue_status_policy::is_actionable_mutating_status('blocked_confirmation'));
        $this->assertTrue(queue_status_policy::is_actionable_mutating_status('ready'));
        $this->assertFalse(queue_status_policy::is_actionable_mutating_status('failed'));
    }

    /**
     * Pickup-ready statuses stay limited to ready and retry_waiting.
     */
    public function test_queue_status_policy_pickup_statuses_are_stable(): void {
        $this->assertSame(['ready', 'retry_waiting'], queue_status_policy::pickup_ready_statuses());
        $this->assertTrue(queue_status_policy::is_pickup_ready_status('ready'));
        $this->assertTrue(queue_status_policy::is_pickup_ready_status('retry_waiting'));
        $this->assertFalse(queue_status_policy::is_pickup_ready_status('blocked_confirmation'));
    }

    /**
     * Queue command mapper prefers prepared_input and preserves execution metadata when requested.
     */
    public function test_queue_command_mapper_prefers_prepared_input_and_preserves_metadata(): void {
        $command = queue_command_mapper::from_queue_item([
            'skill' => 'booking.create_option',
            'version' => 3,
            'input' => ['title' => 'raw'],
            'prepared_input' => ['title' => 'prepared'],
            'guard_token' => 'guard-123',
            'depends_on' => ['q1', '', 'q2'],
        ], true);

        $this->assertIsArray($command);
        $this->assertSame('booking.create_option', $command['skill']);
        $this->assertSame(3, $command['version']);
        $this->assertSame(['title' => 'prepared'], $command['input']);
        $this->assertSame('guard-123', $command['guard_token']);
        $this->assertSame(['q1', 'q2'], array_values($command['depends_on']));
    }

    /**
     * Queue command mapper drops invalid queue items and falls back to raw input.
     */
    public function test_queue_command_mapper_filters_invalid_items_and_falls_back_to_raw_input(): void {
        $commands = queue_command_mapper::from_queue_items([
            ['skill' => '', 'input' => ['x' => 1]],
            ['skill' => 'booking.update_option', 'version' => 1, 'input' => ['x' => 2]],
        ]);

        $this->assertCount(1, $commands);
        $this->assertSame('booking.update_option', $commands[0]['skill']);
        $this->assertSame(['x' => 2], $commands[0]['input']);
        $this->assertArrayNotHasKey('guard_token', $commands[0]);
    }
}
