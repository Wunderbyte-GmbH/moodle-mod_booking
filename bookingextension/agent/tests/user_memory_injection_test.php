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
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\interpreter;
use bookingextension_agent\local\wizard\orchestrator;
use bookingextension_agent\local\wizard\services\user_memory_service;
use bookingextension_agent\local\wizard\skill_registry_factory;

/**
 * Tests that stored user memories are injected into the runtime context at the
 * selection phase (which feeds both the planner selection call and the synchronizer),
 * and nowhere a memory would never reach a model.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\orchestrator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class user_memory_injection_test extends advanced_testcase {
    /**
     * Invoke the private runtime-context builder for a phase.
     *
     * @param orchestrator $orc
     * @param int $threadid
     * @param int $contextid
     * @param string $phase
     * @param string $channel
     * @return string
     */
    private function build_block(
        orchestrator $orc,
        int $threadid,
        int $contextid,
        string $phase,
        string $channel = ''
    ): string {
        $method = new \ReflectionMethod(orchestrator::class, 'build_runtime_context_block');
        $method->setAccessible(true);
        $blocks = (array)$method->invoke($orc, $threadid, $contextid, $phase, false, false, [], [], [], $channel);
        return (string)($blocks['stable'] ?? '') . "\n" . (string)($blocks['volatile'] ?? '');
    }

    /**
     * Memories are injected only into the channel they were tagged for; untagged memories
     * (empty scopes) appear in every channel. Discovery carries no channel (no LLM call).
     */
    public function test_memory_injected_per_channel(): void {
        $this->resetAfterTest();

        $userid = (int)$this->getDataGenerator()->create_user()->id;
        $context = \context_system::instance();
        $store = new conversation_store();
        $thread = $store->get_or_create_thread($userid, (int)$context->id);

        $service = new user_memory_service();
        $service->add($userid, 'Address me as Dr Maisser', [user_memory_service::SCOPE_SYNCHRONIZATION]);
        $service->add($userid, 'I prefer morning bookings', [user_memory_service::SCOPE_CONSTRUCTION]);
        $service->add($userid, 'I am an admin everywhere'); // No scopes = all channels.

        $registry = skill_registry_factory::get_default();
        $orc = new orchestrator($registry, new interpreter($registry), $store);
        $tid = (int)$thread->id;
        $cid = (int)$context->id;

        // Planner selection (channel derived from phase).
        $selection = $this->build_block($orc, $tid, $cid, orchestrator::PHASE_SELECTION);
        $this->assertStringContainsString('I am an admin everywhere', $selection);
        $this->assertStringNotContainsString('Address me as Dr Maisser', $selection);
        $this->assertStringNotContainsString('I prefer morning bookings', $selection);

        // Parameter construction.
        $construction = $this->build_block($orc, $tid, $cid, orchestrator::PHASE_PARAMETER_CONSTRUCTION);
        $this->assertStringContainsString('I prefer morning bookings', $construction);
        $this->assertStringContainsString('I am an admin everywhere', $construction);
        $this->assertStringNotContainsString('Address me as Dr Maisser', $construction);

        // Synchronizer reply (explicit channel; phase is SELECTION as in process_synchronizer).
        $sync = $this->build_block(
            $orc,
            $tid,
            $cid,
            orchestrator::PHASE_SELECTION,
            user_memory_service::SCOPE_SYNCHRONIZATION
        );
        $this->assertStringContainsString('Address me as Dr Maisser', $sync);
        $this->assertStringContainsString('I am an admin everywhere', $sync);
        $this->assertStringNotContainsString('I prefer morning bookings', $sync);

        // Discovery carries no channel → no memory block at all.
        $discovery = $this->build_block($orc, $tid, $cid, orchestrator::PHASE_DISCOVERY);
        $this->assertStringNotContainsString('USER MEMORY', $discovery);
    }

    /**
     * No memory block is emitted when the user has nothing stored.
     */
    public function test_no_block_when_empty(): void {
        $this->resetAfterTest();

        $userid = (int)$this->getDataGenerator()->create_user()->id;
        $context = \context_system::instance();
        $store = new conversation_store();
        $thread = $store->get_or_create_thread($userid, (int)$context->id);

        $registry = skill_registry_factory::get_default();
        $orc = new orchestrator($registry, new interpreter($registry), $store);

        $selection = $this->build_block($orc, (int)$thread->id, (int)$context->id, orchestrator::PHASE_SELECTION);
        $this->assertStringNotContainsString('USER MEMORY', $selection);
    }
}
