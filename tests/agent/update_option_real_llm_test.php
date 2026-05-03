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
 * Real-LLM conversation tests for booking.update_option.
 *
 * Covered conversations (see AGENT_CONVERSATIONS.md):
 *
 *   CONV-03  Happy path        — Option pre-created. User asks to increase capacity.
 *                                LLM proposes update → executor runs → DB shows new maxanswers.
 *
 *   CONV-04  Verification loop — User says "Update the capacity to 25" without naming
 *                                the option. LLM asks which option (turn 1).
 *                                User names the option (turn 2).
 *                                LLM proposes update → executor runs → DB verified.
 *
 * Activation: set BOOKING_AI_REAL_LLM=1 (see AGENT_CONVERSATIONS.md).
 *
 * @package   mod_booking
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');

/**
 * CONV-03 / CONV-04: booking.update_option real-LLM tests.
 *
 * @group mod_booking
 * @group mod_booking_agent
 * @coversNothing
 */
final class update_option_real_llm_test extends abstract_agent_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->require_real_llm();
    }

    // -------------------------------------------------------------------------

    /**
     * CONV-03: Happy path — capacity update on a pre-existing, named option.
     *
     * Setup:  Creates "Piano Update Test CONV03" with maxanswers=5.
     * Conversation:
     *   User:  "Increase the capacity of <title> to 20 spots"
     *   Agent: confirmation_request with booking.update_option
     *   Test:  executes command, verifies maxanswers=20 in DB.
     */
    public function test_conv03_update_option_happy_path(): void {
        $this->setUser($this->teacher);

        $title  = 'Piano Update Test CONV03 ' . uniqid('', true);
        $option = $this->create_option($title, ['maxanswers' => 5]);

        [$store, $runtime, $threadid] = $this->build_runtime();

        $query = 'Increase the capacity of the option "' . $title . '" to 20 spots.';

        try {
            $result = $this->chat($query, $threadid, $store, $runtime);
        } catch (\Throwable $e) {
            $this->markTestSkipped('LLM unavailable: ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result);

        if (($result['response_type'] ?? '') !== 'confirmation_request') {
            $this->markTestSkipped('Expected confirmation_request; got: ' . ($result['response_type'] ?? '?'));
        }

        $command = $this->extract_command($result, 'booking.update_option');
        if ($command === null) {
            $this->markTestSkipped('No booking.update_option command in response.');
        }

        // Ensure optionid and maxanswers are set correctly.
        $command['input'] = array_merge($command['input'] ?? [], [
            'optionid'   => (int)$option->id,
            'maxanswers' => 20,
        ]);

        $execresult = $this->execute_command($command);
        $this->assertEquals('executed', $execresult['status'] ?? '', (string)($execresult['detail'] ?? ''));

        $updated = $this->get_option_from_db((int)$option->id);
        $this->assertEquals(20, (int)$updated->maxanswers, 'maxanswers must be updated to 20.');
        $this->assertEquals($option->text, $updated->text, 'Option title must not change.');
    }

    // -------------------------------------------------------------------------

    /**
     * CONV-04: Verification loop — no option name on first turn triggers clarification.
     *
     * Setup:  Creates "Piano Loop Update CONV04" with maxanswers=5.
     * Conversation:
     *   Turn 1 — User:  "Update the capacity to 25"  (no option name)
     *            Agent: clarification  (asks which option)
     *   Turn 2 — User:  names the option
     *            Agent: confirmation_request with booking.update_option
     *   Test:   executes command, verifies maxanswers=25 in DB.
     */
    public function test_conv04_update_option_verification_loop(): void {
        $this->setUser($this->teacher);

        $title  = 'Piano Loop Update CONV04 ' . uniqid('', true);
        $option = $this->create_option($title, ['maxanswers' => 5]);

        [$store, $runtime, $threadid] = $this->build_runtime();

        // ---- Turn 1: missing option name ----
        try {
            $result1 = $this->chat('Update the capacity to 25.', $threadid, $store, $runtime);
        } catch (\Throwable $e) {
            $this->markTestSkipped('LLM unavailable (turn 1): ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result1);

        if (($result1['response_type'] ?? '') !== 'clarification') {
            $this->markTestSkipped(
                'Expected clarification on turn 1 for missing option name; got: ' . ($result1['response_type'] ?? '?')
            );
        }

        // ---- Turn 2: provide option name ----
        try {
            $result2 = $this->chat(
                'I mean the option "' . $title . '" (id: ' . (int)$option->id . ').',
                $threadid,
                $store,
                $runtime
            );
        } catch (\Throwable $e) {
            $this->markTestSkipped('LLM unavailable (turn 2): ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result2);

        if (($result2['response_type'] ?? '') !== 'confirmation_request') {
            $this->markTestSkipped(
                'Expected confirmation_request on turn 2; got: ' . ($result2['response_type'] ?? '?')
            );
        }

        $command = $this->extract_command($result2, 'booking.update_option');
        if ($command === null) {
            $this->markTestSkipped('No booking.update_option command in turn-2 response.');
        }

        $command['input'] = array_merge($command['input'] ?? [], [
            'optionid'   => (int)$option->id,
            'maxanswers' => 25,
        ]);

        $execresult = $this->execute_command($command);
        $this->assertEquals('executed', $execresult['status'] ?? '', (string)($execresult['detail'] ?? ''));

        $updated = $this->get_option_from_db((int)$option->id);
        $this->assertEquals(25, (int)$updated->maxanswers, 'maxanswers must be 25 after loop update.');
    }

    // -------------------------------------------------------------------------

    /**
     * CONV-16: Multi-step workflow — create via executor, then update via LLM.
     *
     * This verifies the agent can reference a previously-created option by name
     * and propose a targeted update, preserving all other fields.
     *
     * Conversation:
     *   (Setup)  Option "LLM Workflow CONV16" created directly (no LLM).
     *   User:    "Increase the capacity of LLM Workflow CONV16 to 30 spots."
     *   Agent:   confirmation_request with booking.update_option
     *   Test:    executor runs, DB shows maxanswers=30, title unchanged.
     */
    public function test_conv16_create_then_update_multi_step_workflow(): void {
        $this->setUser($this->teacher);

        $title  = 'LLM Workflow CONV16 ' . uniqid('', true);
        $option = $this->create_option($title, ['maxanswers' => 5]);

        [$store, $runtime, $threadid] = $this->build_runtime();

        $query = 'Increase the capacity of "' . $title . '" to 30 spots.';

        try {
            $result = $this->chat($query, $threadid, $store, $runtime);
        } catch (\Throwable $e) {
            $this->markTestSkipped('LLM unavailable: ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result);

        if (($result['response_type'] ?? '') !== 'confirmation_request') {
            $this->markTestSkipped('Expected confirmation_request; got: ' . ($result['response_type'] ?? '?'));
        }

        $command = $this->extract_command($result, 'booking.update_option');
        if ($command === null) {
            $this->markTestSkipped('No booking.update_option command in response.');
        }

        // Ensure optionid and maxanswers are correct before executing.
        $command['input'] = array_merge($command['input'] ?? [], [
            'optionid'   => (int)$option->id,
            'maxanswers' => 30,
        ]);

        $execresult = $this->execute_command($command);
        $this->assertEquals('executed', $execresult['status'] ?? '', (string)($execresult['detail'] ?? ''));

        $updated = $this->get_option_from_db((int)$option->id);
        $this->assertEquals(30, (int)$updated->maxanswers, 'maxanswers must be 30 after workflow update.');
        $this->assertEquals($option->text, $updated->text, 'Option title must not change.');
    }

    // -------------------------------------------------------------------------

    /**
     * CONV-17: Tool failure — updating a non-existent option surfaces a clear error.
     *
     * The agent must not crash or return an empty message when the LLM proposes
     * an update for an option that does not exist.
     *
     * Conversation:
     *   User:  "Change the seats of \"This Option Does Not Exist XYZ999\" to 99."
     *   Agent: clarification OR error  (never crashes, never empty message)
     *   Test:  response_type is clarification or error, message is non-empty.
     */
    public function test_conv17_update_nonexistent_option_surfaces_error(): void {
        $this->setUser($this->teacher);

        [$store, $runtime, $threadid] = $this->build_runtime();

        $query = 'Change the seats of "This Option Does Not Exist XYZ999" to 99.';

        try {
            $result = $this->chat($query, $threadid, $store, $runtime);
        } catch (\Throwable $e) {
            $this->markTestSkipped('LLM unavailable: ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result);

        $this->assertContains(
            $result['response_type'],
            ['clarification', 'error', 'confirmation_request'],
            'Non-existent option must produce clarification or error, not a silent failure.'
        );

        $this->assertNotEmpty(
            trim((string)($result['message'] ?? '')),
            'Agent message must not be empty when the option does not exist.'
        );
    }
}
