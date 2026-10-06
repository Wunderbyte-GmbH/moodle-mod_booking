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

use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\queue\queue_manager;
use bookingextension_agent\local\wizard\services\pending_intent_service;
use bookingextension_agent\local\wizard\services\queue_transition_service;
use bookingextension_agent\local\wizard\services\queue_status_policy;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for pending intent and queue transition services.
 *
 * @covers \bookingextension_agent\local\wizard\services\pending_intent_service
 * @covers \bookingextension_agent\local\wizard\services\queue_transition_service
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class pending_intent_and_queue_transition_contract_test extends TestCase {
    /**
     * Pending intent service writes and returns confirmation code from store.
     */
    public function test_pending_intent_service_set_returns_confirmation_code(): void {
        $store = $this->getMockBuilder(conversation_store::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['set_pending_intent', 'get_pending_intent'])
            ->getMock();

        $store->expects($this->once())
            ->method('set_pending_intent');
        $store->expects($this->once())
            ->method('get_pending_intent')
            ->with(42)
            ->willReturn(['confirmationcode' => 'C123456']);

        $service = new pending_intent_service($store);
        $code = $service->set(42, 7, 99, [
            'queue_item_ids' => ['q1'],
        ]);

        $this->assertSame('C123456', $code);
    }

    /**
     * Queue transition service maps retry_waiting transition to canonical update_status call.
     */
    public function test_queue_transition_service_retry_waiting_transition(): void {
        $queue = $this->getMockBuilder(queue_manager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_queue_item', 'update_status'])
            ->getMock();

        $queue->expects($this->once())
            ->method('get_queue_item')
            ->with(12, 'q12_1')
            ->willReturn([
                'queue_item_id' => 'q12_1',
                'mutability' => 'mutating',
                'risk_class' => skill_risk_class::R2,
                'status' => 'ready',
                'retry_count' => 0,
            ]);

        $queue->expects($this->once())
            ->method('update_status')
            ->with(
                12,
                'q12_1',
                'retry_waiting',
                ['TRANSIENT_IO', 'RETRY_DECISION_LAYER_EXECUTION', 'RETRY_CATEGORY_TECHNICAL'],
                'transient_io',
                'temporary I/O issue',
                $this->callback(static function (array $extra): bool {
                    return (int)($extra['retry_count'] ?? 0) === 2
                        && (int)($extra['retry_after_ms'] ?? 0) === 500
                        && (string)($extra['reason_code'] ?? '') === 'EXECUTION_RETRY_HINT';
                })
            );

        $service = new queue_transition_service();
        $service->to_retry_waiting(
            $queue,
            12,
            'q12_1',
            'EXECUTION_RETRY_HINT',
            ['TRANSIENT_IO'],
            'transient_io',
            'temporary I/O issue',
            ['retry_count' => 2, 'retry_after_ms' => 500]
        );
    }

    /**
     * R3 retry hints must be converted to failed status without retrying.
     */
    public function test_queue_transition_service_forbids_retry_waiting_for_r3(): void {
        $queue = $this->getMockBuilder(queue_manager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_queue_item', 'update_status'])
            ->getMock();

        $queue->expects($this->once())
            ->method('get_queue_item')
            ->with(12, 'q12_1')
            ->willReturn([
                'queue_item_id' => 'q12_1',
                'mutability' => 'mutating',
                'risk_class' => skill_risk_class::R3,
                'status' => 'ready',
                'retry_count' => 0,
            ]);

        $queue->expects($this->once())
            ->method('update_status')
            ->with(
                12,
                'q12_1',
                queue_status_policy::failed_status(),
                ['TRANSIENT_IO', 'R3_NO_RETRY'],
                'preflight_retry_forbidden',
                'Retry is forbidden for irreversible_or_external skills.',
                $this->callback(static function (array $extra): bool {
                    return (string)($extra['reason_code'] ?? '') === 'R3_NO_RETRY';
                })
            );

        $service = new queue_transition_service();
        $service->apply_preflight_decision(
            $queue,
            12,
            ['q12_1'],
            'retry_hint',
            ['TRANSIENT_IO'],
            ['Retry requested'],
            ['retry_after_ms' => 500],
            false
        );
    }

    /**
     * R3 pass decisions must stay blocked_confirmation even if autoconfirm is enabled.
     */
    public function test_queue_transition_service_keeps_r3_blocked_confirmation_under_autoconfirm(): void {
        $queue = $this->getMockBuilder(queue_manager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_queue_item', 'update_status'])
            ->getMock();

        $queue->expects($this->once())
            ->method('get_queue_item')
            ->with(12, 'q12_2')
            ->willReturn([
                'queue_item_id' => 'q12_2',
                'mutability' => 'mutating',
                'risk_class' => skill_risk_class::R3,
                'status' => 'blocked_confirmation',
                'retry_count' => 0,
            ]);

        $queue->expects($this->once())
            ->method('update_status')
            ->with(
                12,
                'q12_2',
                'blocked_confirmation',
                ['TRANSIENT_IO'],
                '',
                '',
                $this->callback(static function (array $extra): bool {
                    return (string)($extra['reason_code'] ?? '') === 'PREFLIGHT_R3_MANUAL_CONFIRMATION';
                })
            );

        $service = new queue_transition_service();
        $service->apply_preflight_decision(
            $queue,
            12,
            ['q12_2'],
            'pass',
            ['TRANSIENT_IO'],
            [],
            ['retry_after_ms' => 0],
            true
        );
    }
}
