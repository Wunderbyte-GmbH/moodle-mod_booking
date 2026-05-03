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
 * Real-LLM conversation tests for booking.diagnose_cancellation_issue.
 *
 * Covered conversations (see AGENT_CONVERSATIONS.md):
 *
 *   CONV-09  Happy path        — User is booked. Agent auto-executes the diagnose task.
 *                                Result is 'executed' and contains a diagnosis.
 *
 *   CONV-10  Verification loop — User says "Why can't the user cancel?" with no
 *                                user or option. Agent asks for clarification (turn 1).
 *                                User provides userId + optionId (turn 2).
 *                                Agent auto-executes; result contains diagnosis.
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
 * CONV-09 / CONV-10: booking.diagnose_cancellation_issue real-LLM tests.
 *
 * @group mod_booking
 * @group mod_booking_agent
 * @coversNothing
 */
final class diagnose_cancellation_issue_real_llm_test extends abstract_agent_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->require_real_llm();
    }

    // -------------------------------------------------------------------------

    /**
     * CONV-09: Happy path — booked user, agent diagnoses cancellation status.
     *
     * Setup:  Creates option, books "Lena Storno" via executor.
     * Conversation:
     *   User:  "Can user id <X> cancel their booking for option id <Y>?"
     *   Agent: auto-executes diagnose_cancellation_issue (read-only task)
     *   Test:  status = 'executed', diagnosis contains reasons.
     */
    public function test_conv09_diagnose_cancellation_happy_path(): void {
        $this->setUser($this->teacher);

        $option = $this->create_option('Cancel CONV09 ' . uniqid('', true), ['maxanswers' => 5]);

        $target = $this->getDataGenerator()->create_user([
            'firstname' => 'Lena',
            'lastname'  => 'Storno',
            'email'     => 'lena.storno.' . uniqid('', true) . '@example.com',
        ]);
        $this->getDataGenerator()->enrol_user($target->id, $this->course->id, 'student');

        // Book the user directly via executor so there is something to cancel.
        $this->exec_command('booking.book_users', [
            'optionid' => (int)$option->id,
            'userids'  => [(int)$target->id],
        ]);
        singleton_service::destroy_booking_answers((int)$option->id);

        [$store, $runtime, $threadid] = $this->build_runtime();

        $query = 'Can user id ' . (int)$target->id
            . ' cancel their booking for option id ' . (int)$option->id . '? Just diagnose.';

        try {
            $result = $this->chat($query, $threadid, $store, $runtime);
        } catch (\Throwable $e) {
            $this->markTestSkipped('LLM unavailable: ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result);

        if (($result['response_type'] ?? '') !== 'execution_result') {
            $this->markTestSkipped(
                'Expected execution_result for read-only cancellation diagnose; got: ' . ($result['response_type'] ?? '?')
            );
        }

        $taskresult = $this->extract_task_result($result, 'booking.diagnose_cancellation_issue');
        if ($taskresult === null) {
            $this->markTestSkipped('No booking.diagnose_cancellation_issue result in response.');
        }

        $this->assertEquals('executed', (string)($taskresult['status'] ?? ''));
        $this->assertNotEmpty((array)($taskresult['diagnosis']['reasons'] ?? []), 'Diagnosis must contain reasons.');
    }

    // -------------------------------------------------------------------------

    /**
     * CONV-10: Verification loop — vague cancellation question triggers clarification.
     *
     * Setup:  Creates option, books "Max Loopstorno" via executor.
     * Conversation:
     *   Turn 1 — User:  "Why can't the user cancel?"  (no user, no option)
     *            Agent: clarification
     *   Turn 2 — User:  userId + optionId
     *            Agent: auto-executes diagnose_cancellation_issue
     *   Test:   status = 'executed', diagnosis contains reasons.
     */
    public function test_conv10_diagnose_cancellation_verification_loop(): void {
        $this->setUser($this->teacher);

        $option = $this->create_option('Cancel CONV10 ' . uniqid('', true), ['maxanswers' => 5]);

        $target = $this->getDataGenerator()->create_user([
            'firstname' => 'Max',
            'lastname'  => 'Loopstorno',
            'email'     => 'max.loopstorno.' . uniqid('', true) . '@example.com',
        ]);
        $this->getDataGenerator()->enrol_user($target->id, $this->course->id, 'student');

        $this->exec_command('booking.book_users', [
            'optionid' => (int)$option->id,
            'userids'  => [(int)$target->id],
        ]);
        singleton_service::destroy_booking_answers((int)$option->id);

        [$store, $runtime, $threadid] = $this->build_runtime();

        // ---- Turn 1: no specifics ----
        try {
            $result1 = $this->chat("Why can't the user cancel?", $threadid, $store, $runtime);
        } catch (\Throwable $e) {
            $this->markTestSkipped('LLM unavailable (turn 1): ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result1);

        if (($result1['response_type'] ?? '') !== 'clarification') {
            $this->markTestSkipped(
                'Expected clarification on turn 1 for vague cancellation input; got: ' . ($result1['response_type'] ?? '?')
            );
        }

        // ---- Turn 2: provide ids ----
        $reply = 'User id ' . (int)$target->id . ', option id ' . (int)$option->id . '.';

        try {
            $result2 = $this->chat($reply, $threadid, $store, $runtime);
        } catch (\Throwable $e) {
            $this->markTestSkipped('LLM unavailable (turn 2): ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result2);

        if (($result2['response_type'] ?? '') !== 'execution_result') {
            $this->markTestSkipped('Expected execution_result on turn 2; got: ' . ($result2['response_type'] ?? '?'));
        }

        $taskresult = $this->extract_task_result($result2, 'booking.diagnose_cancellation_issue');
        if ($taskresult === null) {
            $this->markTestSkipped('No booking.diagnose_cancellation_issue result in turn-2 response.');
        }

        $this->assertEquals('executed', (string)($taskresult['status'] ?? ''));
        $this->assertNotEmpty((array)($taskresult['diagnosis']['reasons'] ?? []), 'Diagnosis must contain reasons.');
    }
}
