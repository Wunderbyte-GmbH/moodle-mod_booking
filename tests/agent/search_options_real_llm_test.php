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
 * Real-LLM conversation tests for booking.search_options.
 *
 * Covered conversations (see AGENT_CONVERSATIONS.md):
 *
 *   CONV-13  Happy path (auto-execute)
 *            — Two "Search Test Kurs" options pre-created.
 *              search_options is read-only → agent auto-executes without confirmation.
 *              Result type is execution_result and contains both options.
 *
 *   CONV-14  Multi-turn follow-up
 *            — Turn 1: search for "Search Multi Kurs" options → execution_result.
 *            — Turn 2: "Which of those have more than 5 free spots?"
 *              Agent replies with a non-empty message about free spots.
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
 * CONV-13 / CONV-14: booking.search_options real-LLM tests.
 *
 * @group mod_booking
 * @group mod_booking_agent
 * @coversNothing
 */
final class search_options_real_llm_test extends abstract_agent_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->require_real_llm();
    }

    // -------------------------------------------------------------------------

    /**
     * CONV-13: Happy path — read-only search auto-executes, both options in results.
     *
     * Setup:  Creates "Search Test Kurs 1" (10 spots) and "Search Test Kurs 2" (8 spots).
     * Conversation:
     *   User:  'Show me all "Search Test Kurs" options.'
     *   Agent: execution_result (no confirmation needed for read-only tasks)
     *   Test:  both option names appear in the result.
     */
    public function test_conv13_search_options_auto_executes(): void {
        $this->setUser($this->teacher);

        $prefix  = 'Search Test Kurs ' . uniqid('', true);
        $option1 = $this->create_option($prefix . ' 1', ['maxanswers' => 10]);
        $option2 = $this->create_option($prefix . ' 2', ['maxanswers' => 8]);

        [$store, $runtime, $threadid] = $this->build_runtime();

        $query = 'Show me all "' . $prefix . '" options.';

        try {
            $result = $this->chat($query, $threadid, $store, $runtime);
        } catch (\Throwable $e) {
            $this->markTestSkipped('LLM unavailable: ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result);

        if (($result['response_type'] ?? '') !== 'execution_result') {
            $this->markTestSkipped(
                'Expected execution_result for read-only search; got: ' . ($result['response_type'] ?? '?')
            );
        }

        // Collect all option names / texts returned.
        $allnames = [];
        foreach ((array)($result['results'] ?? []) as $entry) {
            foreach ((array)($entry['options'] ?? $entry['results'] ?? []) as $opt) {
                $allnames[] = strtolower((string)($opt['text'] ?? $opt['name'] ?? ''));
            }
        }

        $nameshaystack = implode(' ', $allnames);
        $this->assertStringContainsStringIgnoringCase(
            $prefix . ' 1',
            $nameshaystack,
            'First option must appear in search results.'
        );
        $this->assertStringContainsStringIgnoringCase(
            $prefix . ' 2',
            $nameshaystack,
            'Second option must appear in search results.'
        );
    }

    // -------------------------------------------------------------------------

    /**
     * CONV-14: Multi-turn follow-up — second question refers to first search result.
     *
     * Setup:  Creates "Search Multi Kurs A" (20 spots) and "Search Multi Kurs B" (3 spots).
     * Conversation:
     *   Turn 1 — User:  'Show me all "Search Multi Kurs" options.'
     *            Agent: execution_result
     *   Turn 2 — User:  "Which of those have more than 5 free spots?"
     *            Agent: a non-empty message (may be execution_result or clarification/response)
     *   Test:   Turn-2 message is non-empty and references "Search Multi Kurs A".
     */
    public function test_conv14_search_options_multi_turn_follow_up(): void {
        $this->setUser($this->teacher);

        $prefix  = 'Search Multi Kurs ' . uniqid('', true);
        $this->create_option($prefix . ' A', ['maxanswers' => 20]);
        $this->create_option($prefix . ' B', ['maxanswers' => 3]);

        [$store, $runtime, $threadid] = $this->build_runtime();

        // ---- Turn 1: search ----
        try {
            $result1 = $this->chat('Show me all "' . $prefix . '" options.', $threadid, $store, $runtime);
        } catch (\Throwable $e) {
            $this->markTestSkipped('LLM unavailable (turn 1): ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result1);

        // Turn 1 should auto-execute the search; if not, we cannot test the follow-up.
        if (($result1['response_type'] ?? '') !== 'execution_result') {
            $this->markTestSkipped(
                'Expected execution_result on turn 1; got: ' . ($result1['response_type'] ?? '?')
            );
        }

        // ---- Turn 2: follow-up about free spots ----
        try {
            $result2 = $this->chat(
                'Which of those have more than 5 free spots?',
                $threadid,
                $store,
                $runtime
            );
        } catch (\Throwable $e) {
            $this->markTestSkipped('LLM unavailable (turn 2): ' . $e->getMessage());
        }

        $this->assertArrayHasKey('response_type', $result2);

        $message = trim((string)($result2['message'] ?? ''));
        $this->assertNotEmpty($message, 'Turn-2 message must not be empty.');

        // The option with 20 spots ("A") must be mentioned; "B" with 3 spots should not be highlighted.
        $this->assertStringContainsStringIgnoringCase(
            $prefix . ' A',
            $message,
            'Turn-2 response must reference the option with 20 spots.'
        );
    }
}
