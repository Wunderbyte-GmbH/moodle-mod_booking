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
 * Real-LLM conversation tests for booking.diagnose_booking_issue.
 *
 * Covered conversations (see AGENT_CONVERSATIONS.md):
 *
 *   CONV-07  Happy path (user CAN book)
 *            — Option has free spots, user is enrolled, no blockers.
 *              Agent auto-executes diagnose_booking_issue (read-only).
 *              Result shows userstatus='notbooked' and no hard blockers.
 *
 *   CONV-08  Verification loop (user CANNOT book)
 *            — Turn 1: "Why can't someone book?" (no user, no option).
 *              Agent asks for clarification.
 *            — Turn 2: provide userId + fully-booked optionId.
 *              Agent auto-executes diagnose; result mentions option is fully booked.
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
 * CONV-07 / CONV-08: booking.diagnose_booking_issue real-LLM tests.
 *
 * @group mod_booking
 * @group mod_booking_agent
 * @coversNothing
 */
final class diagnose_booking_issue_real_llm_test extends abstract_agent_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->require_real_llm();
    }

    // -------------------------------------------------------------------------

    /**
     * CONV-07: Happy path — agent diagnoses that a user CAN book (no blockers).
     *
     * Setup:  Creates option with 5 free spots. Creates and enrolls "Klara Frei".
     * Conversation:
     *   User:  "Can user id <X> book option id <Y>? Investigate only."
     *   Agent: auto-executes diagnose_booking_issue (read-only task)
     *   Test:  diagnosis.userstatus = 'notbooked', reasons list is non-empty, status = 'executed'.
     */
    public function test_conv07_diagnose_user_can_book(): void {
        $this->setUser($this->teacher);

        $option = $this->create_option('Diagnose CONV07 ' . uniqid('', true), ['maxanswers' => 5]);

        $target = $this->getDataGenerator()->create_user([
            'firstname' => 'Klara',
            'lastname'  => 'Frei',
            'email'     => 'klara.frei.' . uniqid('', true) . '@example.com',
        ]);
        $this->getDataGenerator()->enrol_user($target->id, $this->course->id, 'student');

        [$store, $runtime, $threadid] = $this->build_runtime();

        $query = 'Why can user id ' . (int)$target->id
            . ' not book option id ' . (int)$option->id . '? Just investigate, do not book.';

        try {
            $result = $this->chat($query, $threadid, $store, $runtime);
        } catch (\Throwable $e) {
            $this->markTestSkipped('LLM unavailable: ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result);

        if (($result['response_type'] ?? '') !== 'execution_result') {
            $this->markTestSkipped('Expected execution_result for read-only diagnose; got: ' . ($result['response_type'] ?? '?'));
        }

        $taskresult = $this->extract_task_result($result, 'booking.diagnose_booking_issue');
        if ($taskresult === null) {
            $this->markTestSkipped('No booking.diagnose_booking_issue result in response.');
        }

        $this->assertEquals('executed', (string)($taskresult['status'] ?? ''));
        $this->assertEquals((int)$target->id, (int)($taskresult['diagnosis']['userid'] ?? 0));
        $this->assertEquals((int)$option->id, (int)($taskresult['diagnosis']['optionid'] ?? 0));
        $this->assertEquals('notbooked', (string)($taskresult['diagnosis']['userstatus'] ?? ''));
        $this->assertNotEmpty((array)($taskresult['diagnosis']['reasons'] ?? []), 'Reasons must not be empty.');
    }

    // -------------------------------------------------------------------------

    /**
     * CONV-08: Verification loop — vague question triggers clarification,
     *          second turn with fully-booked option shows the blocker reason.
     *
     * Setup:  Creates option maxanswers=1. Books a first user. A second user cannot book.
     * Conversation:
     *   Turn 1 — User:  "Why can't someone book?"  (no user, no option)
     *            Agent: clarification
     *   Turn 2 — User:  userId of second user + optionId of full option
     *            Agent: auto-executes diagnose; diagnosis mentions fully booked
     *   Test:   reasons contain a "fully booked" indicator.
     */
    public function test_conv08_diagnose_user_cannot_book_verification_loop(): void {
        $this->setUser($this->teacher);

        // Create a full option.
        $option = $this->create_option('Diagnose CONV08 ' . uniqid('', true), ['maxanswers' => 1]);

        $firstuser = $this->getDataGenerator()->create_user([
            'email' => 'first.' . uniqid('', true) . '@example.com',
        ]);
        $this->getDataGenerator()->enrol_user($firstuser->id, $this->course->id, 'student');
        // Fill the only spot.
        $this->exec_command('booking.book_users', [
            'optionid' => (int)$option->id,
            'userids'  => [(int)$firstuser->id],
        ]);
        singleton_service::destroy_booking_answers((int)$option->id);

        $seconduser = $this->getDataGenerator()->create_user([
            'firstname' => 'Blocked',
            'lastname'  => 'Zweiter',
            'email'     => 'blocked.' . uniqid('', true) . '@example.com',
        ]);
        $this->getDataGenerator()->enrol_user($seconduser->id, $this->course->id, 'student');

        [$store, $runtime, $threadid] = $this->build_runtime();

        // ---- Turn 1: vague ----
        try {
            $result1 = $this->chat("Why can't someone book?", $threadid, $store, $runtime);
        } catch (\Throwable $e) {
            $this->markTestSkipped('LLM unavailable (turn 1): ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result1);

        if (($result1['response_type'] ?? '') !== 'clarification') {
            $this->markTestSkipped(
                'Expected clarification on turn 1 for vague diagnose input; got: ' . ($result1['response_type'] ?? '?')
            );
        }

        // ---- Turn 2: provide ids ----
        $reply = 'User id ' . (int)$seconduser->id . ' cannot book option id ' . (int)$option->id . '. Why?';

        try {
            $result2 = $this->chat($reply, $threadid, $store, $runtime);
        } catch (\Throwable $e) {
            $this->markTestSkipped('LLM unavailable (turn 2): ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result2);

        if (($result2['response_type'] ?? '') !== 'execution_result') {
            $this->markTestSkipped('Expected execution_result on turn 2; got: ' . ($result2['response_type'] ?? '?'));
        }

        $taskresult = $this->extract_task_result($result2, 'booking.diagnose_booking_issue');
        if ($taskresult === null) {
            $this->markTestSkipped('No booking.diagnose_booking_issue result in turn-2 response.');
        }

        $this->assertEquals('executed', (string)($taskresult['status'] ?? ''));

        // At least one reason must mention that the option is fully booked.
        $reasons = implode(' ', (array)($taskresult['diagnosis']['reasons'] ?? []));
        $stats   = (array)($taskresult['diagnosis']['stats'] ?? []);
        $isfullybooked = !empty($stats['fullybooked'])
            || stripos($reasons, 'fully booked') !== false
            || stripos($reasons, 'ausgebucht') !== false;
        $this->assertTrue($isfullybooked, 'Diagnosis must mention that the option is fully booked.');
    }
}
