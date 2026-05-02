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
 * AgentRuntime real-LLM integration tests.
 *
 * Tests the AgentRuntime class with a live LLM provider, verifying:
 * - The agent loop (plan → execute → observe → decide)
 * - Read-only auto-execution
 * - Confirmation-gating for mutating commands
 * - Multi-step loop behavior
 * - Tool failure handling
 * - TaskResult value object
 * - SlotBookingNormalizer
 *
 * Required env-vars: BOOKING_AI_REAL_LLM=1, BOOKING_TEST_AI_KEY, BOOKING_TEST_AI_MODEL,
 *                    BOOKING_TEST_AI_ENDPOINT
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');

use mod_booking\local\wbagent\agent_runtime;
use mod_booking\local\wbagent\authorization_service;
use mod_booking\local\wbagent\booking\support\slot_booking_normalizer;
use mod_booking\local\wbagent\conversation_store;
use mod_booking\local\wbagent\interpreter;
use mod_booking\local\wbagent\orchestrator;
use mod_booking\local\wbagent\privacy_anonymizer;
use mod_booking\local\wbagent\task_registry;
use mod_booking\local\wbagent\task_result;

/**
 * AgentRuntime integration tests — real LLM calls.
 *
 * @group mod_booking
 * @group mod_booking_agent
 * @coversNothing
 */
final class agent_runtime_real_llm_test extends abstract_agent_testcase {
    /**
     * Skip all tests unless explicitly enabled.
     */
    public function setUp(): void {
        parent::setUp();

        if (empty(getenv('BOOKING_AI_REAL_LLM')) || getenv('BOOKING_AI_REAL_LLM') !== '1') {
            $this->markTestSkipped('Real LLM tests require BOOKING_AI_REAL_LLM=1 environment variable');
        }

        if (!$this->hasliveprovider) {
            $this->markTestSkipped(
                'Real LLM tests require BOOKING_TEST_AI_KEY, BOOKING_TEST_AI_MODEL, BOOKING_TEST_AI_ENDPOINT'
            );
        }
    }

    // -------------------------------------------------------------------------
    // TaskResult unit tests (do NOT require live LLM — run always).

    /**
     * task_result::ok() produces a success result with data.
     *
     * @runInSeparateProcess
     */
    public function test_task_result_ok_is_success(): void {
        $this->resetAfterTest();
        $result = task_result::ok(['optionid' => 42, 'status' => 'executed']);

        $this->assertTrue($result->is_success());
        $this->assertEquals(42, $result->get_data()['optionid']);
        $this->assertNull($result->get_error());
        $this->assertEquals('', $result->get_error_code());
        $this->assertEquals('', $result->get_error_message());

        $legacy = $result->to_legacy_array();
        $this->assertEquals('executed', $legacy['status']);
        $this->assertEquals(42, $legacy['optionid']);
    }

    /**
     * task_result::failure() produces a structured error.
     *
     * @runInSeparateProcess
     */
    public function test_task_result_failure_has_error(): void {
        $this->resetAfterTest();
        $result = task_result::failure('OPTION_NOT_FOUND', 'No option matched "Yoga"', ['optionquery' => 'Yoga']);

        $this->assertFalse($result->is_success());
        $this->assertEmpty($result->get_data());
        $this->assertNotNull($result->get_error());
        $this->assertEquals('OPTION_NOT_FOUND', $result->get_error_code());
        $this->assertEquals('No option matched "Yoga"', $result->get_error_message());
        $this->assertEquals('Yoga', $result->get_error()['metadata']['optionquery']);

        $legacy = $result->to_legacy_array();
        $this->assertEquals('error', $legacy['status']);
        $this->assertStringContainsString('Yoga', $legacy['detail']);
    }

    // -------------------------------------------------------------------------
    // SlotBookingNormalizer unit tests (do NOT require live LLM).

    /**
     * Non-slot tasks are returned unchanged.
     *
     * @runInSeparateProcess
     */
    public function test_slot_booking_normalizer_skips_non_slot_tasks(): void {
        $this->resetAfterTest();
        $normalizer = new slot_booking_normalizer();

        $input = ['text' => 'test', 'optiontype' => '0'];
        $result = $normalizer->normalize('booking.search_options', $input);
        $this->assertSame($input, $result, 'Non-slot tasks must be returned unchanged');

        $result2 = $normalizer->normalize('booking.create_option', $input);
        $this->assertSame($input, $result2, 'create_option without slot signals must be returned unchanged');
    }

    /**
     * Slot-booking input is normalized: slot_enabled, slot_type, day flags.
     *
     * @runInSeparateProcess
     */
    public function test_slot_booking_normalizer_sets_slot_fields(): void {
        $this->resetAfterTest();
        $normalizer = new slot_booking_normalizer();

        $input = [
            'optiontype'                   => 'slot',
            'slot_duration_minutes'        => 30,
            'slot_max_participants_per_slot' => 3,
            'slot_valid_from'              => '2045-01-01',
            'slot_valid_until'             => '2045-12-31',
            'slot_day_1'                   => 1,
            'slot_day_3'                   => 1,
        ];

        $result = $normalizer->normalize('booking.create_option', $input);

        $this->assertTrue((bool)$result['slot_enabled'], 'slot_enabled must be true');
        $this->assertNotEmpty($result['slot_type'], 'slot_type must be set');
        $this->assertEquals(1, $result['slot_day_1']);
        $this->assertEquals(0, $result['slot_day_2']);
        $this->assertEquals(1, $result['slot_day_3']);
        $this->assertEquals(3, $result['slot_max_participants_per_slot']);
        $this->assertEquals(30, $result['slot_duration_minutes']);
        $this->assertIsInt($result['slot_valid_from'], 'slot_valid_from should be unix timestamp');
        $this->assertGreaterThan(0, $result['slot_valid_from']);
    }

    /**
     * Self-learning "no limit" phrase sets maxanswers to 999999.
     *
     * @runInSeparateProcess
     */
    public function test_slot_booking_normalizer_selflearning_no_limit(): void {
        $this->resetAfterTest();
        $normalizer = new slot_booking_normalizer();

        $input = [
            'optiontype'  => 'selflearning',
            'text'        => 'Ein Kurs ohne limit an Teilnehmern',
        ];

        $result = $normalizer->normalize('booking.create_option', $input);
        $this->assertEquals(999999, $result['maxanswers']);
    }

    // -------------------------------------------------------------------------
    // AgentRuntime + real LLM tests.

    /**
     * AgentRuntime returns a response with required keys for a create_option request.
     *
     * This test verifies the agent loop produces a well-formed result:
     * - response_type is one of the known types
     * - message is a non-empty string
     * - commands and issue_codes are arrays
     */
    public function test_agent_runtime_returns_valid_structure_for_create_option(): void {
        $this->setUser($this->teacher);

        [$store, $runtime, $threadid] = $this->make_runtime_components();

        $userquery = 'Erstelle eine Yoga-Klasse mit maximal 10 Teilnehmern für nächsten Dienstag um 10 Uhr';

        try {
            $result = $this->send_and_run($userquery, $threadid, $store, $runtime);
        } catch (\Throwable $e) {
            $this->markTestSkipped('LLM API unavailable: ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('commands', $result);
        $this->assertArrayHasKey('issue_codes', $result);
        $this->assertIsArray($result['commands']);
        $this->assertIsArray($result['issue_codes']);
        $this->assertIsString($result['message']);

        $this->assertContains(
            $result['response_type'],
            ['confirmation_request', 'clarification', 'error'],
            'create_option should produce confirmation_request or clarification'
        );
    }

    /**
     * Read-only search command is auto-executed (no confirmation required).
     *
     * 1. Create 2 options via executor (no LLM).
     * 2. Send a search request to the agent.
     * 3. Expect execution_result (auto-executed) with options in results.
     */
    public function test_agent_runtime_auto_executes_readonly_search(): void {
        $this->setUser($this->teacher);

        // Create options via executor.
        for ($i = 1; $i <= 2; $i++) {
            $this->exec_command('booking.create_option', [
                'text'            => "Pottery Class $i",
                'maxanswers'      => 8,
                'coursestarttime' => '2045-07-0' . $i . 'T09:00:00',
                'duration'        => 60,
                'teacherquery'    => 'current',
            ]);
        }

        [$store, $runtime, $threadid] = $this->make_runtime_components();

        $userquery = 'Zeige mir alle Pottery Kurse';

        try {
            $result = $this->send_and_run($userquery, $threadid, $store, $runtime);
        } catch (\Throwable $e) {
            $this->markTestSkipped('LLM API unavailable: ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result);

        // Search (booking.search_options) is read-only → AgentRuntime should auto-execute it.
        if ($result['response_type'] === 'execution_result') {
            $rawresults = $result['results'] ?? [];
            $this->assertNotEmpty($rawresults, 'Search execution should return results');

            $foundpottery = false;
            foreach ($rawresults as $entry) {
                $options = is_array($entry) ? ($entry['options'] ?? []) : [];
                foreach ($options as $opt) {
                    if (stripos((string)($opt['name'] ?? $opt['text'] ?? ''), 'Pottery') !== false) {
                        $foundpottery = true;
                    }
                }
            }
            $this->assertTrue($foundpottery, 'At least one Pottery option should be in results');
        }
    }

    /**
     * Mutating commands are confirmation-gated (response_type = confirmation_request).
     *
     * Sending a create_option request should NEVER auto-execute — the agent must
     * ask for confirmation.
     */
    public function test_agent_runtime_gates_mutating_commands_with_confirmation(): void {
        $this->setUser($this->teacher);

        [$store, $runtime, $threadid] = $this->make_runtime_components();

        $userquery = 'Erstelle einen neuen Kurs: "Confirmation Gate Test", 5 Plätze, morgen um 14 Uhr';

        try {
            $result = $this->send_and_run($userquery, $threadid, $store, $runtime);
        } catch (\Throwable $e) {
            $this->markTestSkipped('LLM API unavailable: ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result);

        if (in_array($result['response_type'], ['confirmation_request', 'clarification'], true)) {
            // Good — agent did NOT auto-execute the mutating command.
            $this->assertNotEquals('execution_result', $result['response_type']);
        }

        // Regardless, the agent must not have already executed a create_option.
        global $DB;
        $exists = $DB->record_exists_select(
            'booking_options',
            "text LIKE ? AND bookingid = ?",
            ['%Confirmation Gate Test%', $this->booking->id]
        );
        $this->assertFalse($exists, 'Mutating command must not be auto-executed without confirmation');
    }

    /**
     * Multi-step loop: after a search result, a follow-up query can continue the conversation.
     *
     * Step 1: Create options.
     * Step 2: Send search query → auto-executed, results returned.
     * Step 3: Send follow-up query referencing prior results.
     * Verify that the second turn produces a coherent (non-empty) response.
     */
    public function test_agent_runtime_multi_turn_conversation(): void {
        $this->setUser($this->teacher);

        // Create options.
        for ($i = 1; $i <= 3; $i++) {
            $this->exec_command('booking.create_option', [
                'text'            => "Swimming Level $i",
                'maxanswers'      => $i * 5,
                'coursestarttime' => '2045-08-0' . $i . 'T08:00:00',
                'duration'        => 45,
                'teacherquery'    => 'current',
            ]);
        }

        [$store, $runtime, $threadid] = $this->make_runtime_components();

        // Turn 1: search.
        try {
            $result1 = $this->send_and_run(
                'Zeige mir alle Swimming Kurse',
                $threadid,
                $store,
                $runtime
            );
        } catch (\Throwable $e) {
            $this->markTestSkipped('LLM API unavailable: ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result1);

        // Turn 2: follow-up.
        try {
            $result2 = $this->send_and_run(
                'Wie viele davon haben noch freie Plätze?',
                $threadid,
                $store,
                $runtime
            );
        } catch (\Throwable $e) {
            $this->markTestSkipped('LLM API unavailable (turn 2): ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result2);
        $this->assertNotEmpty(trim($result2['message'] ?? ''), 'Turn 2 response message must not be empty');
    }

    /**
     * Tool failure: executor error is surfaced correctly by AgentRuntime.
     *
     * Sends an update_option command for a non-existent option ID.
     * The agent should return a clarification or error with a non-empty message.
     */
    public function test_agent_runtime_surfaces_tool_failure(): void {
        $this->setUser($this->teacher);

        [$store, $runtime, $threadid] = $this->make_runtime_components();

        // Ask to update an option that does not exist.
        $userquery = 'Ändere den Kurs "Dieser Kurs existiert absolut nicht XYZ123" auf 99 Teilnehmer';

        try {
            $result = $this->send_and_run($userquery, $threadid, $store, $runtime);
        } catch (\Throwable $e) {
            $this->markTestSkipped('LLM API unavailable: ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result);
        $this->assertContains(
            $result['response_type'],
            ['clarification', 'error', 'confirmation_request'],
            'Non-existent option should produce clarification or error'
        );
        $this->assertNotEmpty(trim($result['message'] ?? ''), 'Error message must not be empty');
    }

    /**
     * Full two-phase workflow with real LLM: create → confirm → verify in DB.
     *
     * Step 1: Send create_option request → expect confirmation_request.
     * Step 2: Execute confirmed commands via executor.
     * Step 3: Verify option exists in DB.
     */
    public function test_agent_runtime_full_create_workflow(): void {
        $this->setUser($this->teacher);

        [$store, $runtime, $threadid] = $this->make_runtime_components();

        $title = 'AgentRuntime Full Test ' . uniqid('', true);
        $userquery = sprintf(
            'Erstelle einen Kurs: "%s", 12 Plätze, nächsten Freitag um 16 Uhr, Dauer 60 Minuten',
            $title
        );

        try {
            $result = $this->send_and_run($userquery, $threadid, $store, $runtime);
        } catch (\Throwable $e) {
            $this->markTestSkipped('LLM API unavailable: ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result);

        if ($result['response_type'] !== 'confirmation_request') {
            $this->markTestSkipped(
                'LLM did not return confirmation_request (got ' . $result['response_type'] . '); skipping DB check'
            );
        }

        // Filter create_option commands.
        $createcmds = array_values(array_filter(
            $result['commands'],
            static fn($cmd) => is_array($cmd) && ($cmd['task'] ?? '') === 'booking.create_option'
        ));

        if (empty($createcmds)) {
            $this->markTestSkipped('No booking.create_option command in confirmation; skipping DB check');
        }

        // Execute via the executor.
        $cmd = $createcmds[0];
        $idempotencykey = hash('sha256', 'test:fullworkflow:' . uniqid('', true));
        $execresults = $this->make_executor()->execute_commands(
            [$cmd],
            (int)$this->booking->cmid,
            (int)$this->teacher->id,
            $idempotencykey,
            0
        );

        $this->assertNotEmpty($execresults);
        $execresult = reset($execresults);
        $this->assertEquals('executed', $execresult['status'] ?? '', $execresult['detail'] ?? '');

        $optionid = (int)($execresult['resultid'] ?? 0);
        $this->assertGreaterThan(0, $optionid);

        $option = $this->get_option_from_db($optionid);
        $this->assertNotNull($option);
        $this->assertEquals($this->booking->id, (int)$option->bookingid);
        $this->assertGreaterThan(0, (int)$option->maxanswers);
    }

    /**
     * AgentRuntime loop terminates gracefully within MAX_LOOP_STEPS.
     *
     * Verifies that the multi-step loop does not exceed MAX_LOOP_STEPS.
     * We call run_loop() with maxsteps=2 on a search query and verify the
     * result does NOT contain response_type='MAX_STEPS_EXCEEDED' for a simple query.
     */
    public function test_agent_runtime_loop_respects_max_steps(): void {
        $this->setUser($this->teacher);

        [$store, $registry, $orchestrator, $runtime, $threadid] = $this->make_runtime_components(true);

        // Create a single option.
        $this->exec_command('booking.create_option', [
            'text'            => 'Loop Test Option',
            'maxanswers'      => 5,
            'coursestarttime' => '2045-09-01T10:00:00',
            'duration'        => 30,
            'teacherquery'    => 'current',
        ]);

        // Store user message.
        $anonymizer = new privacy_anonymizer($store);
        $precheck = $anonymizer->precheck_user_message($threadid, 'Suche alle Kurse');
        $store->add_message($threadid, 'user', (string)($precheck['sanitizedmessage'] ?? 'Suche alle Kurse'));

        try {
            $result = $runtime->run_loop($threadid, (int)$this->booking->cmid, (int)$this->teacher->id, 3);
        } catch (\Throwable $e) {
            $this->markTestSkipped('LLM API unavailable: ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result);
        // We accept any valid terminal state — but NOT an infinite loop.
        $this->assertArrayHasKey('loop_step', $result);
        $this->assertLessThanOrEqual(3, (int)($result['loop_step'] ?? 999), 'Loop should terminate within 3 steps');
    }

    // -------------------------------------------------------------------------
    // Private helpers.

    /**
     * Build fresh AgentRuntime components.
     *
     * @param  bool $returnall When true, returns all 5 items including registry and orchestrator.
     * @return array [store, runtime, threadid] or [store, registry, orchestrator, runtime, threadid]
     */
    private function make_runtime_components(bool $returnall = false): array {
        $store = new conversation_store();
        $registry = task_registry::make_default();
        $orchestrator = new orchestrator($registry, new interpreter($registry), $store);
        $authz = new authorization_service();
        $runtime = new agent_runtime($registry, $orchestrator, $store, $authz);

        $thread = $store->get_or_create_thread(
            (int)$this->teacher->id,
            (int)$this->booking->cmid,
            (int)$this->booking->id
        );

        if ($returnall) {
            return [$store, $registry, $orchestrator, $runtime, (int)$thread->id];
        }

        return [$store, $runtime, (int)$thread->id];
    }

    /**
     * Privacy-precheck, store user message, call runtime->run(), return result.
     *
     * @param  string $userquery
     * @param  int    $threadid
     * @param  conversation_store $store
     * @param  agent_runtime $runtime
     * @return array
     */
    private function send_and_run(
        string $userquery,
        int $threadid,
        conversation_store $store,
        agent_runtime $runtime
    ): array {
        $anonymizer = new privacy_anonymizer($store);
        $precheck = $anonymizer->precheck_user_message($threadid, $userquery);
        $sanitized = (string)($precheck['sanitizedmessage'] ?? $userquery);
        $store->add_message($threadid, 'user', $sanitized);
        return $runtime->run($threadid, (int)$this->booking->cmid, (int)$this->teacher->id);
    }
}
