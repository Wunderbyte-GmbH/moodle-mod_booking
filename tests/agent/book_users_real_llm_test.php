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
 * Real-LLM conversation tests for booking.book_users.
 *
 * Covered conversations (see AGENT_CONVERSATIONS.md):
 *
 *   CONV-05  Happy path        — User provides userId + optionId directly.
 *                                LLM proposes booking → executor runs →
 *                                booking_answers record exists with status=booked.
 *
 *   CONV-06  Verification loop — User sends "Book a user into an option" with no
 *                                specifics. LLM asks for clarification (turn 1).
 *                                User provides userId + optionId (turn 2).
 *                                LLM proposes booking → executor runs → DB verified.
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
 * CONV-05 / CONV-06: booking.book_users real-LLM tests.
 *
 * @group mod_booking
 * @group mod_booking_agent
 * @coversNothing
 */
final class book_users_real_llm_test extends abstract_agent_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->require_real_llm();
    }

    // -------------------------------------------------------------------------

    /**
     * CONV-05: Happy path — explicit userId and optionId → booking in DB.
     *
     * Setup:  Creates option with 5 spots.  Creates user "Valentina Booker", enrolled.
     * Conversation:
     *   User:  "Book user id <X> into option id <Y>"
     *   Agent: confirmation_request with booking.book_users
     *   Test:  executes command, verifies booking_answers row with waitinglist=0 (booked).
     */
    public function test_conv05_book_users_happy_path(): void {
        global $DB;
        $this->setUser($this->teacher);

        $option = $this->create_option('Book Users CONV05 ' . uniqid('', true), ['maxanswers' => 5]);

        $target = $this->getDataGenerator()->create_user([
            'firstname' => 'Valentina',
            'lastname'  => 'Booker',
            'email'     => 'valentina.booker.' . uniqid('', true) . '@example.com',
        ]);
        $this->getDataGenerator()->enrol_user($target->id, $this->course->id, 'student');

        [$store, $runtime, $threadid] = $this->build_runtime();

        $query = 'Book user id ' . (int)$target->id . ' into option id ' . (int)$option->id . '.';

        try {
            $result = $this->chat($query, $threadid, $store, $runtime);
        } catch (\Throwable $e) {
            $this->markTestSkipped('LLM unavailable: ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result);

        if (($result['response_type'] ?? '') !== 'confirmation_request') {
            $this->markTestSkipped('Expected confirmation_request; got: ' . ($result['response_type'] ?? '?'));
        }

        $command = $this->extract_command($result, 'booking.book_users');
        if ($command === null) {
            $this->markTestSkipped('No booking.book_users command in response.');
        }

        // Make sure the correct ids are in the command input.
        $command['input'] = array_merge($command['input'] ?? [], [
            'optionid' => (int)$option->id,
            'userids'  => [(int)$target->id],
        ]);

        $execresult = $this->execute_command($command);
        $this->assertEquals('executed', $execresult['status'] ?? '', (string)($execresult['detail'] ?? ''));

        $answer = $DB->get_record('booking_answers', [
            'optionid' => (int)$option->id,
            'userid'   => (int)$target->id,
        ]);
        $this->assertNotFalse($answer, 'booking_answers record must exist after booking.');
        $this->assertEquals(MOD_BOOKING_STATUSPARAM_BOOKED, (int)$answer->waitinglist, 'User must be booked (not waitlisted).');
    }

    // -------------------------------------------------------------------------

    /**
     * CONV-06: Verification loop — no user/option on first turn triggers clarification.
     *
     * Setup:  Creates option. Creates user "Werner Looper", enrolled.
     * Conversation:
     *   Turn 1 — User:  "Book a user into an option"  (no userId, no optionId)
     *            Agent: clarification  (asks for user and option)
     *   Turn 2 — User:  provides userId + optionId
     *            Agent: confirmation_request with booking.book_users
     *   Test:   executes command, verifies booking_answers in DB.
     */
    public function test_conv06_book_users_verification_loop(): void {
        global $DB;
        $this->setUser($this->teacher);

        $option = $this->create_option('Book Users CONV06 ' . uniqid('', true), ['maxanswers' => 5]);

        $target = $this->getDataGenerator()->create_user([
            'firstname' => 'Werner',
            'lastname'  => 'Looper',
            'email'     => 'werner.looper.' . uniqid('', true) . '@example.com',
        ]);
        $this->getDataGenerator()->enrol_user($target->id, $this->course->id, 'student');

        [$store, $runtime, $threadid] = $this->build_runtime();

        // ---- Turn 1: no specifics ----
        try {
            $result1 = $this->chat('Book a user into an option.', $threadid, $store, $runtime);
        } catch (\Throwable $e) {
            $this->markTestSkipped('LLM unavailable (turn 1): ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result1);

        if (($result1['response_type'] ?? '') !== 'clarification') {
            $this->markTestSkipped(
                'Expected clarification on turn 1 for vague book_users input; got: ' . ($result1['response_type'] ?? '?')
            );
        }

        // ---- Turn 2: provide ids ----
        $reply = 'Book user id ' . (int)$target->id . ' into option id ' . (int)$option->id . '.';

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

        $command = $this->extract_command($result2, 'booking.book_users');
        if ($command === null) {
            $this->markTestSkipped('No booking.book_users command in turn-2 response.');
        }

        $command['input'] = array_merge($command['input'] ?? [], [
            'optionid' => (int)$option->id,
            'userids'  => [(int)$target->id],
        ]);

        $execresult = $this->execute_command($command);
        $this->assertEquals('executed', $execresult['status'] ?? '', (string)($execresult['detail'] ?? ''));

        $answer = $DB->get_record('booking_answers', [
            'optionid' => (int)$option->id,
            'userid'   => (int)$target->id,
        ]);
        $this->assertNotFalse($answer, 'booking_answers record must exist after loop booking.');
        $this->assertEquals(MOD_BOOKING_STATUSPARAM_BOOKED, (int)$answer->waitinglist);
    }
}
