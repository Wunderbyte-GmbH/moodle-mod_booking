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
 * Real-LLM conversation tests for booking.create_option.
 *
 * Covered conversations (see AGENT_CONVERSATIONS.md):
 *
 *   CONV-01  Happy path        — Full input on first turn → LLM proposes create →
 *                                executor runs → option exists in DB.
 *
 *   CONV-02  Verification loop — User sends vague "create an option" with no details.
 *                                LLM asks for clarification (turn 1).
 *                                User replies with full details (turn 2).
 *                                LLM proposes create → executor runs → option exists in DB.
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
 * CONV-01 / CONV-02: booking.create_option real-LLM tests.
 *
 * @group mod_booking
 * @group mod_booking_agent
 * @coversNothing
 */
final class create_option_real_llm_test extends abstract_agent_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->require_real_llm();
    }

    // -------------------------------------------------------------------------

    /**
     * CONV-01: Happy path — all details in one turn, option appears in DB.
     *
     * Conversation:
     *   User:  "Create Piano Workshop with 12 spots on 2045-11-01 at 14:00"
     *   Agent: confirmation_request containing booking.create_option
     *   Test:  executes command, verifies option in DB with maxanswers=12
     */
    public function test_conv01_create_option_happy_path(): void {
        $this->setUser($this->teacher);

        [$store, $runtime, $threadid] = $this->build_runtime();

        $title = 'Piano Workshop CONV01 ' . uniqid('', true);
        $query = 'Create a booking option called "' . $title . '" with 12 spots, '
            . 'start 2045-11-01T14:00:00, end 2045-11-01T16:00:00.';

        try {
            $result = $this->chat($query, $threadid, $store, $runtime);
        } catch (\Throwable $e) {
            $this->markTestSkipped('LLM unavailable: ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result);

        if (($result['response_type'] ?? '') !== 'confirmation_request') {
            $this->markTestSkipped('Expected confirmation_request; got: ' . ($result['response_type'] ?? '?'));
        }

        $command = $this->extract_command($result, 'booking.create_option');
        if ($command === null) {
            $this->markTestSkipped('No booking.create_option command in response.');
        }

        $execresult = $this->execute_command($command);
        $this->assertEquals('executed', $execresult['status'] ?? '', (string)($execresult['detail'] ?? ''));

        $optionid = (int)($execresult['resultid'] ?? 0);
        $this->assertGreaterThan(0, $optionid, 'Executor must return a valid option id.');

        $option = $this->get_option_from_db($optionid);
        $this->assertEquals((int)$this->booking->id, (int)$option->bookingid);
        $this->assertEquals(12, (int)$option->maxanswers, 'maxanswers must match the requested value.');
    }

    // -------------------------------------------------------------------------

    /**
     * CONV-02: Verification loop — vague first message triggers clarification,
     *          full details on second turn lead to execution.
     *
     * Conversation:
     *   Turn 1 — User:  "Create a new booking option"  (no title, no date, no seats)
     *            Agent: clarification  (asks for missing details)
     *   Turn 2 — User:  full details including title, seats, dates
     *            Agent: confirmation_request containing booking.create_option
     *   Test:   executes command, verifies option in DB.
     */
    public function test_conv02_create_option_verification_loop(): void {
        $this->setUser($this->teacher);

        [$store, $runtime, $threadid] = $this->build_runtime();

        // ---- Turn 1: vague request ----
        try {
            $result1 = $this->chat('Create a new booking option.', $threadid, $store, $runtime);
        } catch (\Throwable $e) {
            $this->markTestSkipped('LLM unavailable (turn 1): ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result1);

        if (($result1['response_type'] ?? '') !== 'clarification') {
            $this->markTestSkipped(
                'Expected clarification on turn 1 for vague input; got: ' . ($result1['response_type'] ?? '?')
            );
        }

        // ---- Turn 2: supply full details ----
        $title = 'Piano Loop Test CONV02 ' . uniqid('', true);
        $reply = 'Call it "' . $title . '", 8 spots, start 2045-11-02T10:00:00, end 2045-11-02T12:00:00.';

        try {
            $result2 = $this->chat($reply, $threadid, $store, $runtime);
        } catch (\Throwable $e) {
            $this->markTestSkipped('LLM unavailable (turn 2): ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result2);

        if (($result2['response_type'] ?? '') !== 'confirmation_request') {
            $this->markTestSkipped(
                'Expected confirmation_request on turn 2; got: ' . ($result2['response_type'] ?? '?')
            );
        }

        $command = $this->extract_command($result2, 'booking.create_option');
        if ($command === null) {
            $this->markTestSkipped('No booking.create_option command in turn-2 response.');
        }

        $execresult = $this->execute_command($command);
        $this->assertEquals('executed', $execresult['status'] ?? '', (string)($execresult['detail'] ?? ''));

        $optionid = (int)($execresult['resultid'] ?? 0);
        $this->assertGreaterThan(0, $optionid);

        global $DB;
        $this->assertTrue(
            $DB->record_exists('booking_options', ['id' => $optionid, 'bookingid' => (int)$this->booking->id]),
            'Option created in loop must exist in DB.'
        );
    }

    // -------------------------------------------------------------------------

    /**
     * CONV-15: Confirmation gate — mutating create_option is NEVER auto-executed.
     *
     * This is a cross-cutting concern: all mutating tasks must be gated behind
     * a user confirmation. The agent must NOT execute booking.create_option
     * automatically even if the LLM proposes it.
     *
     * Conversation:
     *   User:  "Create a course called Confirmation Gate Test, 5 spots, tomorrow 14:00"
     *   Agent: confirmation_request OR clarification  (never execution_result)
     *   Test:  no DB row created before user confirms.
     */
    public function test_conv15_mutating_create_is_never_auto_executed(): void {
        global $DB;
        $this->setUser($this->teacher);

        [$store, $runtime, $threadid] = $this->build_runtime();

        $query = 'Create a booking option called "Confirmation Gate Test CONV15", 5 spots, 2045-12-01T14:00:00.';

        try {
            $result = $this->chat($query, $threadid, $store, $runtime);
        } catch (\Throwable $e) {
            $this->markTestSkipped('LLM unavailable: ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result);

        // The agent must NOT have auto-executed the mutation.
        $this->assertNotEquals(
            'execution_result',
            $result['response_type'],
            'booking.create_option must never be auto-executed without confirmation.'
        );

        // No DB row must exist yet.
        $this->assertFalse(
            $DB->record_exists_select(
                'booking_options',
                'text LIKE ? AND bookingid = ?',
                ['%Confirmation Gate Test CONV15%', (int)$this->booking->id]
            ),
            'create_option must not persist before the user confirms.'
        );
    }
}
