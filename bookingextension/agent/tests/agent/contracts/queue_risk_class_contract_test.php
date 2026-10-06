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

use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\queue\queue_manager;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for queue risk-class TTL and expiry behavior.
 *
 * @covers \bookingextension_agent\local\wizard\queue\queue_manager
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class queue_risk_class_contract_test extends TestCase {
    /**
     * Risk-class specific blocked TTL values must remain stable.
     */
    public function test_resolve_blocked_ttl_seconds_uses_risk_class_specific_values(): void {
        $store = $this->getMockBuilder(conversation_store::class)
            ->disableOriginalConstructor()
            ->getMock();
        $queuesvc = new queue_manager($store);

        $previousttl = get_config('bookingextension_agent', 'queue_blocked_ttl_seconds');

        try {
            set_config('queue_blocked_ttl_seconds', '1111', 'bookingextension_agent');

            $this->assertSame(900, $this->invoke_private_method($queuesvc, 'resolve_blocked_ttl_seconds', [skill_risk_class::R1]));
            $this->assertSame(300, $this->invoke_private_method($queuesvc, 'resolve_blocked_ttl_seconds', [skill_risk_class::R2]));
            $this->assertSame(900, $this->invoke_private_method($queuesvc, 'resolve_blocked_ttl_seconds', [skill_risk_class::R3]));
        } finally {
            set_config('queue_blocked_ttl_seconds', (string)($previousttl ? $previousttl : '900'), 'bookingextension_agent');
        }
    }

    /**
     * Blocked confirmation expiry must follow the risk-class-specific TTL.
     */
    public function test_resolve_blocked_expires_at_uses_risk_class_specific_ttl(): void {
        $store = $this->getMockBuilder(conversation_store::class)
            ->disableOriginalConstructor()
            ->getMock();
        $queuesvc = new queue_manager($store);

        $this->assertSame(1300, $this->invoke_private_method($queuesvc, 'resolve_blocked_expires_at', [
            'blocked_confirmation',
            1000,
            skill_risk_class::R2,
        ]));
        $this->assertSame(1900, $this->invoke_private_method($queuesvc, 'resolve_blocked_expires_at', [
            'blocked_confirmation',
            1000,
            skill_risk_class::R1,
        ]));
        $this->assertSame(1900, $this->invoke_private_method($queuesvc, 'resolve_blocked_expires_at', [
            'blocked_confirmation',
            1000,
            skill_risk_class::R3,
        ]));
        $this->assertNull($this->invoke_private_method($queuesvc, 'resolve_blocked_expires_at', [
            'ready',
            1000,
            skill_risk_class::R2,
        ]));
    }

    /**
     * Invoke a private queue manager helper.
     *
     * @param queue_manager $queuesvc
     * @param string $method
     * @param mixed[] $args
     * @return mixed
     */
    private function invoke_private_method(queue_manager $queuesvc, string $method, array $args) {
        $reflection = new \ReflectionClass(queue_manager::class);
        $m = $reflection->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($queuesvc, $args);
    }
}
