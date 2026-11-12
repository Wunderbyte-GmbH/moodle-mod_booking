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
 * A yes to an open clarification about an attempted skill re-plans that skill instead of ending the turn.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\agent_runtime;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\interpreter;
use bookingextension_agent\local\wizard\queue\queue_manager;
use bookingextension_agent\local\wizard\services\decision\agent_decision_service;
use bookingextension_agent\local\wizard\skill_registry_factory;

/**
 * The selector answers a yes with confirm_pending and an empty message (its contract); when no confirmation is
 * waiting but a clarification about an attempted skill is open, the engine grants one re-plan round (thread 5613).
 *
 * @covers \bookingextension_agent\local\wizard\interpreter
 * @covers \bookingextension_agent\local\wizard\services\decision\agent_decision_service
 * @covers \bookingextension_agent\local\wizard\agent_runtime
 */
final class confirm_pending_clarification_retry_test extends \advanced_testcase {
    /**
     * Set up the engine aliases.
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        \mod_booking\local\wizard\engine_component::ensure_engine_aliases();
        parent::setUp();
    }

    /**
     * The selection interpreter accepts confirm_pending with the empty message the contract prescribes; a
     * clarification still needs its question.
     */
    public function test_selection_accepts_confirm_pending_with_an_empty_message(): void {
        $interpreter = new interpreter(skill_registry_factory::get_default());
        $context = ['contextid' => 12, 'userid' => 34, 'lastusermessage' => 'Ja, trotzdem.'];

        $result = $interpreter->interpret_phase_output(
            '{"response_type":"confirm_pending","commands":[],"message":""}',
            'selection',
            $context
        );
        $this->assertSame('confirm_pending', $result['response_type']);

        $result = $interpreter->interpret_phase_output(
            '{"response_type":"clarification","commands":[],"message":""}',
            'selection',
            $context
        );
        $this->assertSame('error', $result['response_type']);
        $this->assertContains('CONTRACT_EMPTY_SELECTION_MESSAGE', (array)($result['issue_codes'] ?? []));
    }

    /**
     * No confirmation waiting, no planned steps, but a recorded clarification about an attempted skill: the
     * fallback is the retryable error that re-plans the step, not a terminal "nothing to confirm".
     */
    public function test_no_intent_fallback_with_an_open_clarification_is_a_retryable_error(): void {
        $record = json_encode([
            'skill' => 'course.scaffold_course_content',
            'issue_codes' => ['SCAFFOLD_COURSE_NOT_EMPTY_CONFIRM_REQUIRED'],
            'question' => 'This course is not empty. Generate the scaffold content into it anyway?',
        ]);

        $fallback = $this->invoke_fallback($this->build_service($record));

        $this->assertSame('error', $fallback['response_type']);
        $this->assertContains('CONFIRM_PENDING_NO_INTENT_CLARIFICATION', (array)($fallback['issue_codes'] ?? []));
    }

    /**
     * Without such a record the fallback stays the terminal clarification it always was.
     */
    public function test_no_intent_fallback_without_a_clarification_stays_terminal(): void {
        $fallback = $this->invoke_fallback($this->build_service(''));

        $this->assertSame('clarification', $fallback['response_type']);
        $this->assertSame([], (array)($fallback['issue_codes'] ?? []));
    }

    /**
     * The runtime grants exactly one framework retry and its hint points at the pending clarification context.
     */
    public function test_runtime_retries_the_flake_once_with_the_context_hint(): void {
        $reflection = new \ReflectionClass(agent_runtime::class);
        $runtime = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('resolve_framework_retry_issue_code');
        $method->setAccessible(true);
        $result = [
            'response_type' => 'error',
            'issue_codes' => ['CONFIRM_PENDING_NO_INTENT_CLARIFICATION'],
            'commands' => [],
        ];

        $this->assertSame('CONFIRM_PENDING_NO_INTENT_CLARIFICATION', $method->invoke($runtime, $result, []));
        $this->assertNull($method->invoke($runtime, $result, ['CONFIRM_PENDING_NO_INTENT_CLARIFICATION' => 1]));

        $hintmethod = $reflection->getMethod('build_framework_retry_observation');
        $hintmethod->setAccessible(true);
        $hint = $hintmethod->invoke($runtime, 'CONFIRM_PENDING_NO_INTENT_CLARIFICATION');
        $this->assertStringStartsWith('RETRY_HINT:', $hint);
        $this->assertStringContainsString('[PENDING CLARIFICATION CONTEXT]', $hint);
        $this->assertStringContainsString('skill_call', $hint);
    }

    /**
     * A decision service without planned placeholders whose store carries the given clarification record.
     *
     * @param string $pendingrecord
     * @return agent_decision_service
     */
    private function build_service(string $pendingrecord): agent_decision_service {
        $reflection = new \ReflectionClass(agent_decision_service::class);
        $service = $reflection->newInstanceWithoutConstructor();

        $queue = $this->getMockBuilder(queue_manager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['has_planned_placeholders'])
            ->getMock();
        $queue->method('has_planned_placeholders')->willReturn(false);
        $property = $reflection->getProperty('queuesvc');
        $property->setAccessible(true);
        $property->setValue($service, $queue);

        $store = $this->getMockBuilder(conversation_store::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_thread_metadata_value'])
            ->getMock();
        $store->method('get_thread_metadata_value')->willReturn($pendingrecord);
        $property = $reflection->getProperty('store');
        $property->setAccessible(true);
        $property->setValue($service, $store);

        return $service;
    }

    /**
     * Invoke the private no-intent fallback with the empty model message of the selector contract.
     *
     * @param agent_decision_service $service
     * @return array
     */
    private function invoke_fallback(agent_decision_service $service): array {
        $method = new \ReflectionMethod($service, 'build_confirm_pending_no_intent_fallback');
        $method->setAccessible(true);
        return $method->invoke($service, ['response_type' => 'confirm_pending'], '', false, 'de', 5613);
    }
}
