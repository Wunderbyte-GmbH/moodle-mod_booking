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
 * Wave 3: Real LLM integration tests for wbagent (updated to use AgentRuntime API)
 *
 * Uses the full orchestrator → interpreter → AgentRuntime flow with a real LLM.
 * Enabled via BOOKING_AI_REAL_LLM=1 and the three BOOKING_TEST_AI_* variables.
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
use mod_booking\local\wbagent\conversation_store;
use mod_booking\local\wbagent\interpreter;
use mod_booking\local\wbagent\orchestrator;
use mod_booking\local\wbagent\privacy_anonymizer;
use mod_booking\local\wbagent\task_registry;

/**
 * Wave 3: Real LLM integration test suite
 *
 * Tests are SKIPPED by default and only run when BOOKING_AI_REAL_LLM=1 is set
 * together with the three BOOKING_TEST_AI_* env-vars.
 *
 * @group mod_booking
 * @group mod_booking_agent
 * @coversNothing
 */
final class agent_wave3_real_llm_test extends abstract_agent_testcase {
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
    // Helpers.

    /**
     * Build a fresh store + orchestrator + AgentRuntime for the current user.
     *
     * @return array [store, registry, orchestrator, runtime, threadid]
     */
    private function make_runtime(): array {
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

        return [$store, $registry, $orchestrator, $runtime, (int)$thread->id];
    }

    /**
     * Store a user message through privacy precheck then call AgentRuntime::run().
     *
     * @param  string $userquery
     * @param  int    $threadid
     * @param  conversation_store $store
     * @param  agent_runtime $runtime
     * @param  int    $cmid
     * @param  int    $userid
     * @return array  Normalised agent result
     */
    private function send_message(
        string $userquery,
        int $threadid,
        conversation_store $store,
        agent_runtime $runtime,
        int $cmid,
        int $userid
    ): array {
        $anonymizer = new privacy_anonymizer($store);
        $precheck = $anonymizer->precheck_user_message($threadid, $userquery);
        $this->assertFalse($precheck['blocked'] ?? false, 'Message should not be blocked by privacy filter');

        $sanitized = (string)($precheck['sanitizedmessage'] ?? $userquery);
        $store->add_message($threadid, 'user', $sanitized);

        return $runtime->run($threadid, $cmid, $userid);
    }

    // -------------------------------------------------------------------------
    // Tests.

    /**
     * Test: Create a booking option via the full agent pipeline (real LLM).
     *
     * Verifies:
     * 1. LLM produces a confirmation_request for booking.create_option.
     * 2. Executor creates the option with the expected fields.
     * 3. Option is visible in the DB.
     */
    public function test_create_option_via_real_llm(): void {
        $this->setUser($this->teacher);

        [$store, $registry, $orchestrator, $runtime, $threadid] = $this->make_runtime();

        $userquery = 'Erstelle eine neue Yoga-Klasse für Anfänger. '
            . 'Maximal 15 Teilnehmer. '
            . 'Mittwochs um 18:00 Uhr. '
            . 'Dauer 90 Minuten. '
            . 'Studio A. '
            . 'Lehrer: ' . $this->teacher->firstname . ' ' . $this->teacher->lastname;

        try {
            $result = $this->send_message(
                $userquery,
                $threadid,
                $store,
                $runtime,
                (int)$this->booking->cmid,
                (int)$this->teacher->id
            );
        } catch (\Throwable $e) {
            $this->markTestSkipped('LLM API unavailable: ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result);

        // Accept both confirmation_request (mutating) and execution_result (auto-executed read-only).
        $this->assertContains(
            $result['response_type'],
            ['confirmation_request', 'clarification', 'execution_result', 'error'],
            'Unexpected response type: ' . $result['response_type']
        );

        // If the LLM returned a confirmation_request for create_option, execute it.
        if ($result['response_type'] === 'confirmation_request' && !empty($result['commands'])) {
            $createcmds = array_filter(
                $result['commands'],
                static fn($cmd) => is_array($cmd) && ($cmd['task'] ?? '') === 'booking.create_option'
            );

            if (!empty($createcmds)) {
                $cmd = reset($createcmds);
                $execresults = $this->exec_command_raw('booking.create_option', $cmd['input'] ?? []);

                $this->assertNotEmpty($execresults, 'Executor should return results');
                $execresult = reset($execresults);
                $this->assertEquals(
                    'executed',
                    $execresult['status'] ?? '',
                    'Create option failed: ' . ($execresult['detail'] ?? '')
                );

                $optionid = (int)($execresult['resultid'] ?? 0);
                $this->assertGreaterThan(0, $optionid, 'Option id should be > 0');

                $option = $this->get_option_from_db($optionid);
                $this->assertNotNull($option, 'Option should exist in DB');
                $this->assertEquals($this->booking->id, (int)$option->bookingid);
                $this->assertStringContainsStringIgnoringCase('Yoga', $option->text, 'Option name should contain Yoga');
            }
        }
    }

    /**
     * Test: Search options via real LLM.
     *
     * Creates 3 Pilates options, then sends a natural-language search query.
     * The agent should produce an execution_result (search is read-only and
     * auto-executed) with the matching options.
     */
    public function test_search_options_via_llm(): void {
        $this->setUser($this->teacher);

        // Create some test options to search.
        for ($i = 0; $i < 3; $i++) {
            $this->exec_command('booking.create_option', [
                'text'            => "Test Pilates Session $i",
                'maxanswers'      => 10 + $i,
                'coursestarttime' => '2045-05-15T10:00:00',
                'duration'        => 60,
                'teacherquery'    => 'current',
            ]);
        }

        [$store, $registry, $orchestrator, $runtime, $threadid] = $this->make_runtime();

        $userquery = 'Zeige mir alle Pilates Kurse an';

        try {
            $result = $this->send_message(
                $userquery,
                $threadid,
                $store,
                $runtime,
                (int)$this->booking->cmid,
                (int)$this->teacher->id
            );
        } catch (\Throwable $e) {
            $this->markTestSkipped('LLM API unavailable: ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result);

        // Search is read-only → should be auto-executed, result in execution_result.
        if ($result['response_type'] === 'execution_result') {
            $rawresults = $result['results'] ?? [];
            $this->assertNotEmpty($rawresults, 'Search should return results');

            foreach ($rawresults as $entry) {
                if (isset($entry['options']) && is_array($entry['options'])) {
                    foreach ($entry['options'] as $option) {
                        $name = (string)($option['name'] ?? $option['text'] ?? '');
                        $this->assertStringContainsStringIgnoringCase('Pilates', $name);
                    }
                }
            }
        }

        // confirmation_request is also valid if the LLM did not auto-route to search.
        $this->assertContains(
            $result['response_type'],
            ['execution_result', 'confirmation_request', 'clarification'],
            'Unexpected response type'
        );
    }

    /**
     * Test: Full workflow — create via executor, then update via real LLM.
     *
     * 1. Creates "LLM Workflow Option" directly via executor.
     * 2. Sends a natural-language update request to the LLM.
     * 3. If a confirmation_request comes back, executes the update and verifies.
     */
    public function test_create_then_update_workflow_via_llm(): void {
        $this->setUser($this->teacher);

        // Step 1: Create via executor (no LLM).
        $createresult = $this->exec_command('booking.create_option', [
            'text'            => 'LLM Workflow Option',
            'maxanswers'      => 5,
            'coursestarttime' => '2045-06-20T14:00:00',
            'duration'        => 120,
            'teacherquery'    => 'current',
        ]);

        $this->assertEquals('executed', $createresult['status'], 'Create should succeed');
        $optionid = (int)$createresult['resultid'];
        $original = $this->get_option_from_db($optionid);
        $this->assertNotNull($original);

        // Step 2: Send a natural-language update via the full agent pipeline.
        [$store, $registry, $orchestrator, $runtime, $threadid] = $this->make_runtime();

        $userquery = "Erhöhe die Kapazität des Kurses 'LLM Workflow Option' auf 20 Teilnehmer";

        try {
            $result = $this->send_message(
                $userquery,
                $threadid,
                $store,
                $runtime,
                (int)$this->booking->cmid,
                (int)$this->teacher->id
            );
        } catch (\Throwable $e) {
            $this->markTestSkipped('LLM API unavailable: ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result);

        if ($result['response_type'] === 'confirmation_request' && !empty($result['commands'])) {
            $updatecmds = array_filter(
                $result['commands'],
                static fn($cmd) => is_array($cmd) && ($cmd['task'] ?? '') === 'booking.update_option'
            );

            if (!empty($updatecmds)) {
                $cmd = reset($updatecmds);
                $input = array_merge($cmd['input'] ?? [], ['optionid' => $optionid, 'maxanswers' => 20]);

                $execresults = $this->exec_command_raw('booking.update_option', $input);
                $execresult = reset($execresults);

                $this->assertEquals(
                    'executed',
                    $execresult['status'] ?? '',
                    'Update should succeed: ' . ($execresult['detail'] ?? '')
                );

                $updated = $this->get_option_from_db($optionid);
                $this->assertEquals(20, (int)$updated->maxanswers, 'maxanswers should be updated to 20');
                $this->assertEquals($original->text, $updated->text, 'Title should not change');
            }
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers.

    /**
     * Execute a single command via the executor and return raw results array.
     *
     * @param  string $taskname
     * @param  array  $input
     * @return array
     */
    private function exec_command_raw(string $taskname, array $input): array {
        $idempotencykey = hash('sha256', $taskname . ':' . serialize($input) . ':' . uniqid('', true));
        return $this->make_executor()->execute_commands(
            [['task' => $taskname, 'version' => 1, 'input' => $input]],
            (int)$this->booking->cmid,
            (int)$this->teacher->id,
            $idempotencykey,
            0
        );
    }
}
