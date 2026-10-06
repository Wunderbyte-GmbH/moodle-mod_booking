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
 * A clarification with an empty message gets one retry, then an honest clarification.
 *
 * An empty question text is a transient planner flake: the loop retries it once with a
 * contract hint; if the retry stays empty the turn still ends as a clarification asking
 * the user to rephrase — never as a terminal system error.
 *
 * @package   bookingextension_agent
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');
require_once(__DIR__ . '/scripted_llm_trait.php');

/**
 * Deterministic empty-clarification retry tests via the scripted planner seam.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\agent_runtime
 * @covers \bookingextension_agent\local\wizard\interpreter
 */
final class empty_clarification_retry_test extends abstract_agent_testcase {
    use scripted_llm_trait;

    protected function setUp(): void {
        parent::setUp();
        $this->enforcegeneratetextassertion = false;
        $this->grant_agent_capabilities_to_editingteacher();
        $this->register_live_wunderbyte_provider(
            'test-dummy-key-not-used',
            'test-model',
            'test-model',
            'test-embedding',
            'https://llm.wunderbyte.at/v1/chat/completions',
            'https://llm.wunderbyte.at/v1/embeddings'
        );
    }

    protected function tearDown(): void {
        $this->clear_scripted_planner();
        parent::tearDown();
    }

    /**
     * A clarification whose message is empty.
     *
     * @return string
     */
    private function empty_clarification(): string {
        return json_encode([
            'response_type' => 'clarification',
            'message' => '',
            'commands' => [],
            'user_lang' => 'de',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * A proper clarification carrying a question.
     *
     * @return string
     */
    private function real_clarification(): string {
        return json_encode([
            'response_type' => 'clarification',
            'message' => 'Welchen Kurs meinen Sie?',
            'commands' => [],
            'user_lang' => 'de',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Round 1 empty, round 2 proper: the retry hint heals the turn into a clarification.
     */
    public function test_empty_clarification_is_retried_once_and_heals(): void {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();

        $this->install_scripted_planner([
            $this->empty_clarification(),
            $this->real_clarification(),
        ]);

        $result = $this->chat('Buche den Kurs für mich.', (int)$threadid, $store, $runtime);

        $this->assertSame(
            'clarification',
            (string)($result['response_type'] ?? ''),
            'the healed turn must end as a clarification, not a system error: '
                . json_encode($result['issue_codes'] ?? [])
        );
        $this->assertCount(2, $this->scriptedplannerprompts, 'exactly one framework retry round');
        $this->assertStringContainsString(
            'RETRY_HINT',
            (string)$this->scriptedplannerprompts[1],
            'the retry round must carry the contract hint'
        );
    }

    /**
     * Both rounds empty: the turn ends as an honest clarification, never as an error.
     */
    public function test_exhausted_empty_clarification_ends_as_clarification_not_error(): void {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();

        $this->install_scripted_planner([
            $this->empty_clarification(),
            $this->empty_clarification(),
        ]);

        $result = $this->chat('Buche den Kurs für mich.', (int)$threadid, $store, $runtime);

        $this->assertSame(
            'clarification',
            (string)($result['response_type'] ?? ''),
            'an unformulated question must ask the user to rephrase, never end as a system error: '
                . json_encode($result['issue_codes'] ?? [])
        );
        $this->assertNotSame('', trim((string)($result['message'] ?? '')), 'the user must see a real sentence');
        $this->assertContains(
            'LOOP_RETRY_EXHAUSTED_CONTRACT_EMPTY_SELECTION_MESSAGE',
            (array)($result['issue_codes'] ?? []),
            'the exhausted retry must stay visible in telemetry'
        );
    }
}
