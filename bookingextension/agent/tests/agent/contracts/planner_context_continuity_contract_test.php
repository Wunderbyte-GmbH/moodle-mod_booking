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

use bookingextension_agent\local\wizard\agent_runtime;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\orchestrator;
use bookingextension_agent\local\wizard\services\security\authorization_service;
use bookingextension_agent\local\wizard\skill_registry;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for planner context continuity across runtime decision routing.
 *
 * @covers \bookingextension_agent\local\wizard\agent_runtime
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class planner_context_continuity_contract_test extends TestCase {
    /**
     * Runtime must preserve planner_result/phase_trace verbatim without recomposition.
     */
    public function test_runtime_re_attaches_existing_planner_context_without_recomposition(): void {
        $runtime = $this->build_runtime();

        $plannerresult = [
            'phase_trace' => [
                'discovery' => ['phase' => 'discovery', 'response_type' => 'clarification'],
                'selection' => ['phase' => 'selection', 'response_type' => 'clarification'],
                'parameter_construction' => ['phase' => 'parameter_construction', 'response_type' => 'skill_call'],
            ],
            'parameter_construction' => ['response_type' => 'skill_call'],
        ];
        $source = [
            'response_type' => 'skill_call',
            'phase_trace' => $plannerresult['phase_trace'],
            'planner_result' => $plannerresult,
        ];

        $context = $this->invoke_private_method($runtime, 'extract_planner_context', [$source]);
        $merged = $this->invoke_private_method($runtime, 'merge_planner_context', [['response_type' => 'clarification'], $context]);

        $this->assertSame($plannerresult, $merged['planner_result']);
        $this->assertSame($plannerresult['phase_trace'], $merged['phase_trace']);
    }

    /**
     * Runtime must not invent planner_result when only phase_trace is available.
     */
    public function test_runtime_does_not_synthesize_planner_result_from_phase_trace_only(): void {
        $runtime = $this->build_runtime();

        $source = [
            'response_type' => 'clarification',
            'phase_trace' => [
                'discovery' => ['phase' => 'discovery', 'response_type' => 'clarification'],
            ],
        ];

        $context = $this->invoke_private_method($runtime, 'extract_planner_context', [$source]);
        $merged = $this->invoke_private_method($runtime, 'merge_planner_context', [['response_type' => 'error'], $context]);

        $this->assertArrayHasKey('phase_trace', $merged);
        $this->assertArrayNotHasKey('planner_result', $merged);
    }

    /**
     * Build a minimal runtime with mocked dependencies.
     *
     * @return agent_runtime
     */
    private function build_runtime(): agent_runtime {
        $registry = $this->createMock(skill_registry::class);
        $orchestrator = $this->createMock(orchestrator::class);
        $store = $this->createMock(conversation_store::class);
        $authz = $this->createMock(authorization_service::class);

        return new agent_runtime($registry, $orchestrator, $store, $authz);
    }

    /**
     * Invoke a private runtime method.
     *
     * @param agent_runtime $runtime
     * @param string $method
     * @param mixed[] $args
     * @return mixed
     */
    private function invoke_private_method(agent_runtime $runtime, string $method, array $args) {
        $reflection = new \ReflectionClass(agent_runtime::class);
        $m = $reflection->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($runtime, $args);
    }
}
