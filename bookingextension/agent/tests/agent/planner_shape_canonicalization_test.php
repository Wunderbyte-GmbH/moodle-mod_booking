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
 * Planner answers in a recognisable but non-canonical shape are canonicalised, not retried or failed.
 *
 * Replays of sofabooking threads 1311 and 1312 (2026-10-01, DeepSeek-V4-Flash): the model wrote the
 * command object under the key "confirmation_request", put selected_skill and parameters next to the
 * response_type without a commands[] list, and put the question under the key "clarification" instead
 * of "message". Each shape carries every value the contract needs — only in the wrong place — so the
 * interpreter reads it structurally (enum keys only) instead of ending the turn as an error (thread
 * 1311, both turns) or burning a framework-retry round (thread 1312: 15 s for one question).
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
 * Deterministic shape-canonicalisation tests via the scripted planner seam.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\interpreter
 */
final class planner_shape_canonicalization_test extends abstract_agent_testcase {
    use scripted_llm_trait;

    /** The mutating skill standing in for oneclick.create_instance (not installed in the test site). */
    private const SKILL = 'mod_booking.create_option';

    /** Parameters of the staged command, in the place of {"sitename": "m1"}. */
    private const PARAMETERS = [
        'text' => 'Shape Workshop',
        'coursestarttime' => '2045-11-10T10:00:00',
        'courseendtime' => '2045-11-10T12:00:00',
    ];

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
     * Shape A (thread 1311, calls 3847/3852): the command object sits under the response_type key.
     *
     * @return string
     */
    private function nested_confirmation_object(): string {
        return json_encode([
            'commands' => [],
            'confirmation_request' => [
                'selected_skill' => self::SKILL,
                'parameters' => self::PARAMETERS,
                'summary' => 'Create the workshop.',
            ],
            'clarification' => null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Shape B (thread 1312, call 3862): skill and parameters top-level, commands[] missing.
     *
     * @return string
     */
    private function top_level_selected_skill(): string {
        return json_encode([
            'response_type' => 'confirmation_request',
            'selected_skill' => self::SKILL,
            'parameters' => self::PARAMETERS,
            'message' => 'Ich erstelle den Workshop. Soll ich fortfahren?',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Shape C (thread 1312, call 3856): the question stands under the key named like the type.
     *
     * @return string
     */
    private function question_under_type_key(): string {
        return json_encode([
            'response_type' => 'clarification',
            'commands' => [],
            'clarification' => 'Wie soll deine neue Instanz heißen?',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Shared expectation for a construction that must stage the command without a retry round.
     *
     * @param string $construction Raw constructor answer.
     * @return void
     */
    private function assert_construction_stages_without_retry(string $construction): void {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();

        $this->install_scripted_planner([
            $this->selector_skill_call(self::SKILL),
            $construction,
        ]);

        $result = $this->chat(
            'Erstelle den Workshop "Shape Workshop" am 10.11.2045 von 10 bis 12 Uhr.',
            (int)$threadid,
            $store,
            $runtime
        );

        $this->assertSame(
            'confirmation_request',
            (string)($result['response_type'] ?? ''),
            'A complete command in a non-canonical place must still be staged: '
                . json_encode($result['issue_codes'] ?? [])
        );
        $command = $this->extract_command($result, self::SKILL);
        $this->assertNotNull($command, 'The staged command must be present.');
        $this->assertSame(
            self::PARAMETERS['text'],
            (string)($command['input']['text'] ?? ''),
            'The parameters must travel into the staged command unchanged.'
        );
        $this->assertCount(
            2,
            $this->scriptedplannerprompts,
            'One selector and one constructor call — no framework retry round.'
        );
    }

    /**
     * Shape A stages the command the model wrote under "confirmation_request".
     */
    public function test_command_object_under_response_type_key_is_staged(): void {
        $this->assert_construction_stages_without_retry($this->nested_confirmation_object());
    }

    /**
     * Shape B stages the command from top-level selected_skill and parameters.
     */
    public function test_top_level_selected_skill_is_staged(): void {
        $this->assert_construction_stages_without_retry($this->top_level_selected_skill());
    }

    /**
     * Shape C: the question under the type key is the message; exactly one selector call.
     */
    public function test_question_under_type_key_is_the_message(): void {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();

        $this->install_scripted_planner([
            $this->question_under_type_key(),
        ]);

        $result = $this->chat('Mach mir ein neues Trial.', (int)$threadid, $store, $runtime);

        $this->assertSame(
            'clarification',
            (string)($result['response_type'] ?? ''),
            json_encode($result['issue_codes'] ?? [])
        );
        $this->assertNotSame('', trim((string)($result['message'] ?? '')), 'The question must reach the user.');
        $this->assertCount(1, $this->scriptedplannerprompts, 'The question needs no retry round.');
        $this->assertNotContains('CONTRACT_EMPTY_MESSAGE_CLARIFICATION', (array)($result['issue_codes'] ?? []));
    }

    /**
     * Negative case: an object without any recognisable skill is still an unknown shape — no command
     * is staged and the contract code stays visible. Canonicalisation reads enum keys, it never guesses.
     */
    public function test_object_without_skill_stays_unknown(): void {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();

        $unknown = json_encode([
            'commands' => [],
            'decision' => ['reason' => 'nothing to do'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->install_scripted_planner([$unknown, $unknown]);

        $result = $this->chat('Mach mir ein neues Trial.', (int)$threadid, $store, $runtime);

        $this->assertNotSame('confirmation_request', (string)($result['response_type'] ?? ''));
        $this->assertNotSame('skill_call', (string)($result['response_type'] ?? ''));
        $this->assertEmpty((array)($result['commands'] ?? []), 'Nothing may be staged from an unknown shape.');
        $this->assertContains(
            'CONTRACT_UNKNOWN_RESPONSE_TYPE',
            (array)($result['issue_codes'] ?? []),
            json_encode($result['issue_codes'] ?? [])
        );
    }
}
