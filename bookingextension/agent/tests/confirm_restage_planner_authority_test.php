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

/**
 * Deterministic regression for the thread-554 planner-terminal-authority guard.
 *
 * Thread 554 double-executed the later steps of a multi-step series because the confirm queue
 * drain (Driver B) re-animated a stale/over-planned mutating item AFTER run_loop had already
 * returned 'sufficient'. The full failure needs a stochastic constructor over-plan, which a live
 * series run does not reliably reproduce, so this test pins the exact decision the fix introduces:
 * confirm_run_service::should_restage_next_queue_item() must refuse to re-stage once the planner
 * returned a terminal verdict. Pre-fix, the equivalent condition was `!is_array($pendingintent)`
 * alone (true for null+sufficient) — this truth table would have failed on that code.
 *
 * @package   bookingextension_agent
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\services\confirm_run_service;
use ReflectionClass;
use ReflectionMethod;

/**
 * Truth table for the Driver-B re-stage gate.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @coversNothing
 */
final class confirm_restage_planner_authority_test extends \advanced_testcase {
    /**
     * The re-stage gate honours the planner's terminal verdict and the already-staged next step.
     */
    public function test_restage_gate_truth_table(): void {
        // The method is pure (no constructed state), so build the instance without the constructor.
        $service = (new ReflectionClass(confirm_run_service::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(confirm_run_service::class, 'should_restage_next_queue_item');
        $method->setAccessible(true);

        $nopending = null;
        $pending = ['queue_item_ids' => ['q1'], 'confirmationcode' => 'C1'];

        // THE FIX — no pending intent + planner terminal → must NOT re-animate (thread 554).
        $this->assertFalse(
            $method->invoke($service, $nopending, ['response_type' => 'sufficient']),
            'A terminal "sufficient" planner verdict must block the queue-drain re-stage (thread 554).'
        );
        $this->assertFalse(
            $method->invoke($service, $nopending, ['response_type' => 'clarification']),
            'A "clarification" planner verdict must block the queue-drain re-stage.'
        );

        // Legitimate advancement — no pending intent + planner still has open work → re-stage.
        $this->assertTrue(
            $method->invoke($service, $nopending, ['response_type' => 'confirmation_request']),
            'Driver B must still advance a genuine next step (planner produced a confirmation_request).'
        );
        $this->assertTrue(
            $method->invoke($service, $nopending, ['response_type' => 'error']),
            'A non-terminal planner outcome must still allow follow-up advancement.'
        );
        $this->assertTrue(
            $method->invoke($service, $nopending, []),
            'An unknown/empty response_type is non-terminal and must allow advancement.'
        );

        // The run_loop call already staged the next step, so Driver B must not re-stage another.
        $this->assertFalse(
            $method->invoke($service, $pending, ['response_type' => 'confirmation_request']),
            'When run_loop already set a pending intent, Driver B must not re-stage another.'
        );
        $this->assertFalse(
            $method->invoke($service, $pending, ['response_type' => 'sufficient']),
            'A set pending intent short-circuits the re-stage regardless of response_type.'
        );
    }
}
