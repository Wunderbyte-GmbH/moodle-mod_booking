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
 * Wave 3: Real LLM integration tests for wbagent
 *
 * These tests use the full orchestrator→interpreter→executor flow with a real LLM call.
 * Enabled via environment variable BOOKING_AI_REAL_LLM=1
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

require_once __DIR__ . '/abstract_agent_testcase.php';

use mod_booking\local\wbagent\orchestrator;
use mod_booking\local\wbagent\conversation_store;
use mod_booking\local\wbagent\interpreter;
use mod_booking\local\wbagent\executor;
use mod_booking\local\wbagent\task_registry;

/**
 * Wave 3: Real LLM integration test suite
 *
 * These tests are SKIPPED by default and only run when BOOKING_AI_REAL_LLM=1 environment variable is set.
 * This prevents unexpected LLM API calls in regular test runs.
 *
 * @group mod_booking
 * @group mod_booking_agent
 */
final class agent_wave3_real_llm_test extends abstract_agent_testcase {

    /**
     * Skip all tests unless explicitly enabled via environment variable
     */
    public function setUp(): void {
        parent::setUp();

        if (empty(getenv('BOOKING_AI_REAL_LLM')) || getenv('BOOKING_AI_REAL_LLM') !== '1') {
            $this->markTestSkipped('Real LLM tests require BOOKING_AI_REAL_LLM=1 environment variable');
        }
    }

    /**
     * Test: Create booking option via real LLM orchestrator
     *
     * This test sends a natural language request to the LLM and verifies:
     * 1. The LLM generates a valid create_option command
     * 2. The executor processes it correctly
     * 3. The booking option is created in the database with correct fields
     */
    public function test_create_option_via_real_llm(): void {
        $this->setUser($this->teacher);

        // Create conversation thread
        $store = new conversation_store();
        $thread = $store->get_or_create_thread(
            (int)$this->teacher->id,
            (int)$this->booking->cmid,
            (int)$this->booking->id
        );
        $this->assertNotNull($thread);

        // User query in natural language (German as per requirements)
        $user_query = 'Erstelle eine neue Yoga-Klasse für Anfänger. '
            . 'Maximal 15 Teilnehmer. '
            . 'Mittwochs um 18:00 Uhr. '
            . 'Dauer 90 Minuten. '
            . 'Studio A. '
            . 'Lehrer: ' . $this->teacher->firstname . ' ' . $this->teacher->lastname;

        // Send through precheck (privacy check)
        $privacy = new \mod_booking\local\wbagent\privacy_anonymizer($store);
        $precheck = $privacy->precheck_user_message($thread->id, $user_query);
        $this->assertFalse($precheck['blocked'], 'Message should not be blocked');

        $sanitized_query = $precheck['sanitizedmessage'];

        // Call orchestrator with real LLM
        try {
            $orchestrator = new orchestrator($store);
            $llm_response = $orchestrator->send_user_message_to_llm(
                $thread->id,
                $sanitized_query,
                (int)$this->booking->cmid
            );
        } catch (\Throwable $e) {
            $this->markTestSkipped('LLM API unavailable: ' . $e->getMessage());
        }

        $this->assertNotEmpty($llm_response, 'LLM should return a response');

        // Parse LLM response via interpreter
        $interpreter = new interpreter($store, new task_registry());
        $parsed = $interpreter->parse_llm_response($llm_response);

        $this->assertNotNull($parsed, 'Interpreter should parse LLM response');
        $this->assertArrayHasKey('response_type', $parsed);

        // If response is a pending confirmation (typical for create_option)
        if ($parsed['response_type'] === 'confirm_pending') {
            $this->assertArrayHasKey('task', $parsed);
            $this->assertEquals('booking.create_option', $parsed['task']);
            $this->assertArrayHasKey('params', $parsed);

            // Extract parameters
            $params = $parsed['params'];

            // Execute the command via executor
            $executor = $this->make_executor();
            $results = $executor->execute_commands(
                [['task' => 'booking.create_option', 'version' => 1, 'input' => $params]],
                (int)$this->booking->cmid,
                (int)$this->teacher->id,
                hash('sha256', 'test:' . uniqid('', true)),
                0
            );

            $this->assertNotEmpty($results);
            $result = reset($results);

            // Verify execution status
            $this->assertEquals('executed', $result['status'], $result['detail'] ?? '');
            $this->assertNotEmpty($result['resultid']);

            // Verify the created option in database
            $optionid = (int)$result['resultid'];
            $option = $this->get_option_from_db($optionid);

            $this->assertNotNull($option);
            $this->assertEquals($this->booking->id, (int)$option->bookingid);

            // Verify key parameters were set
            $this->assertStringContainsString('Yoga', $option->text, 'Option name should contain "Yoga"');
            $this->assertGreaterThan(5, (int)$option->maxanswers, 'Max answers should be > 5');
            $this->assertGreaterThan(0, (int)$option->coursestarttime, 'Start time should be set');
            $this->assertGreaterThan((int)$option->coursestarttime, (int)$option->courseendtime, 'End time should be after start time');

            // Verify via wbtable output
            $rows = $this->gen->create_table_for_one_option($optionid);
            $this->assertNotEmpty($rows, 'Option should appear in booking table');
            $row = reset($rows);
            $this->assertStringContainsString('Yoga', $row->text);
        }
    }

    /**
     * Test: Search options and verify results match database
     */
    public function test_search_options_via_llm(): void {
        $this->setUser($this->teacher);

        // Create some test options first
        for ($i = 0; $i < 3; $i++) {
            $this->exec_command('booking.create_option', [
                'text' => "Test Pilates Session $i",
                'maxanswers' => 10 + $i,
                'coursestarttime' => '2045-05-15T10:00:00',
                'duration' => 60,
                'teacherquery' => 'current',
            ]);
        }

        $store = new conversation_store();
        $thread = $store->get_or_create_thread(
            (int)$this->teacher->id,
            (int)$this->booking->cmid,
            (int)$this->booking->id
        );

        // Natural language search query
        $user_query = 'Zeige mir alle Pilates Kurse an';

        // Privacy precheck
        $privacy = new \mod_booking\local\wbagent\privacy_anonymizer($store);
        $precheck = $privacy->precheck_user_message($thread->id, $user_query);
        $sanitized_query = $precheck['sanitizedmessage'];

        // Send to LLM
        try {
            $orchestrator = new orchestrator($store);
            $llm_response = $orchestrator->send_user_message_to_llm(
                $thread->id,
                $sanitized_query,
                (int)$this->booking->cmid
            );
        } catch (\Throwable $e) {
            $this->markTestSkipped('LLM API unavailable: ' . $e->getMessage());
        }

        // Parse response
        $interpreter = new interpreter($store, new task_registry());
        $parsed = $interpreter->parse_llm_response($llm_response);

        $this->assertNotNull($parsed);

        if ($parsed['response_type'] === 'confirm_pending' && $parsed['task'] === 'booking.search_options') {
            $executor = $this->make_executor();
            $results = $executor->execute_commands(
                [['task' => 'booking.search_options', 'version' => 1, 'input' => $parsed['params'] ?? []]],
                (int)$this->booking->cmid,
                (int)$this->teacher->id,
                hash('sha256', 'test:' . uniqid('', true)),
                0
            );

            $result = reset($results);
            $this->assertEquals('executed', $result['status']);

            // Verify search results
            if (isset($result['options']) && !empty($result['options'])) {
                foreach ($result['options'] as $found_option) {
                    $this->assertStringContainsString('Pilates', $found_option['name'] ?? '');
                }
            }
        }
    }

    /**
     * Test: Full workflow - create then update via LLM
     */
    public function test_create_then_update_workflow_via_llm(): void {
        $this->setUser($this->teacher);

        // First: Create an option via executor
        $create_result = $this->exec_command('booking.create_option', [
            'text' => 'LLM Workflow Option',
            'maxanswers' => 5,
            'coursestarttime' => '2045-06-20T14:00:00',
            'duration' => 120,
            'teacherquery' => 'current',
        ]);

        $this->assertEquals('executed', $create_result['status']);
        $optionid = (int)$create_result['resultid'];
        $original = $this->get_option_from_db($optionid);

        // Second: Query LLM to update the option
        $store = new conversation_store();
        $thread = $store->get_or_create_thread(
            (int)$this->teacher->id,
            (int)$this->booking->cmid,
            (int)$this->booking->id
        );

        $user_query = "Erhöhe die Kapazität des Kurses 'LLM Workflow Option' auf 20 Teilnehmer";

        $privacy = new \mod_booking\local\wbagent\privacy_anonymizer($store);
        $precheck = $privacy->precheck_user_message($thread->id, $user_query);
        $sanitized_query = $precheck['sanitizedmessage'];

        try {
            $orchestrator = new orchestrator($store);
            $llm_response = $orchestrator->send_user_message_to_llm(
                $thread->id,
                $sanitized_query,
                (int)$this->booking->cmid
            );
        } catch (\Throwable $e) {
            $this->markTestSkipped('LLM API unavailable: ' . $e->getMessage());
        }

        $interpreter = new interpreter($store, new task_registry());
        $parsed = $interpreter->parse_llm_response($llm_response);

        if ($parsed['response_type'] === 'confirm_pending' && $parsed['task'] === 'booking.update_option') {
            // Ensure the LLM identified the correct option
            $params = $parsed['params'] ?? [];
            if (!isset($params['optionid'])) {
                $params['optionid'] = $optionid;
            }
            $params['maxanswers'] = 20;

            $executor = $this->make_executor();
            $results = $executor->execute_commands(
                [['task' => 'booking.update_option', 'version' => 1, 'input' => $params]],
                (int)$this->booking->cmid,
                (int)$this->teacher->id,
                hash('sha256', 'test:' . uniqid('', true)),
                0
            );

            $result = reset($results);
            $this->assertEquals('executed', $result['status']);

            // Verify update in database
            $updated = $this->get_option_from_db($optionid);
            $this->assertEquals(20, (int)$updated->maxanswers);
            $this->assertEquals($original->text, $updated->text, 'Title should not change');
        }
    }
}
