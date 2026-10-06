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
 * Truncated provider output and unreachable providers are handled structurally.
 *
 * Reasoning runaways of the upstream model are cut off at the output-token cap and come back as a
 * successful call with finish_reason 'length' and the partial reasoning as content. The phase
 * call is retried once with the same prompt; exhausted, the
 * turn ends as an honest clarification. The partial output never reaches the parser, retry
 * observations, the synchronizer or the user. An unreachable provider (HTTP 502/503/504) ends as a
 * template without a second LLM call against the failing service.
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
 * Deterministic provider truncation / unreachable tests via the scripted planner seam.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\agent_runtime
 * @covers \bookingextension_agent\local\wizard\services\planner_phase_service
 * @covers \bookingextension_agent\local\wizard\orchestrator
 * @covers \bookingextension_agent\local\wizard\services\llm\llm_call_service
 */
final class provider_truncation_retry_test extends abstract_agent_testcase {
    use scripted_llm_trait;

    /** Marker inside the scripted partial reasoning; it must never surface anywhere. */
    private const PARTIAL_MARKER = 'PARTIALREASONINGMARKER';

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
     * Partial reasoning as delivered by a provider cut off at the token cap.
     *
     * @return array
     */
    private function truncated(): array {
        return $this->scripted_truncated_output(
            '<think>The user asks about the team. ' . self::PARTIAL_MARKER . ' I should call {"response_type": "skill'
        );
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
     * Assert that the partial reasoning marker appears in no stored message of the thread.
     *
     * @param \bookingextension_agent\local\wizard\conversation_store $store
     * @param int $threadid
     * @return void
     */
    private function assert_partial_output_not_stored($store, int $threadid): void {
        foreach ($store->get_messages($threadid) as $message) {
            $this->assertStringNotContainsString(
                self::PARTIAL_MARKER,
                (string)($message->content ?? '') . (string)($message->structuredjson ?? ''),
                'partial provider output must never be persisted'
            );
        }
    }

    /**
     * Truncated selection output is retried once with the SAME prompt and heals.
     */
    public function test_truncated_selection_is_retried_once_with_the_same_prompt_and_heals(): void {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();

        $this->install_scripted_planner([
            $this->truncated(),
            $this->real_clarification(),
        ]);

        $result = $this->chat('Buche den Kurs für mich.', (int)$threadid, $store, $runtime);

        $this->assertSame('clarification', (string)($result['response_type'] ?? ''));
        $this->assertCount(2, $this->scriptedplannerprompts, 'exactly one retry of the selection call');
        $this->assertSame(
            $this->scriptedplannerprompts[0],
            $this->scriptedplannerprompts[1],
            'the truncation retry repeats the same phase call: no retry observation, no framework re-plan'
        );
        $this->assertNotContains('CONTRACT_PARSE_ERROR', (array)($result['issue_codes'] ?? []));
        $this->assert_partial_output_not_stored($store, (int)$threadid);
    }

    /**
     * Twice truncated selection ends as an honest clarification, never as a system error.
     */
    public function test_exhausted_truncated_selection_ends_as_clarification(): void {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();

        $this->install_scripted_planner([
            $this->truncated(),
            $this->truncated(),
        ]);

        $result = $this->chat('Wie steht mein Team da?', (int)$threadid, $store, $runtime);

        $this->assertSame(
            'clarification',
            (string)($result['response_type'] ?? ''),
            'an exhausted truncation must end as a clarification: ' . json_encode($result['issue_codes'] ?? [])
        );
        $this->assertContains('PROVIDER_OUTPUT_TRUNCATED', (array)($result['issue_codes'] ?? []));
        $this->assertCount(2, $this->scriptedplannerprompts, 'one retry only, no framework re-plan round');
        $message = trim((string)($result['message'] ?? ''));
        $this->assertNotSame('', $message, 'the user must see a real sentence');
        foreach ((array)($result['issue_codes'] ?? []) as $code) {
            $this->assertStringNotContainsString((string)$code, $message, 'no issue codes in user text');
        }
        $this->assertStringNotContainsString(self::PARTIAL_MARKER, $message);
        foreach (array_merge($this->scriptedplannerprompts, $this->scriptedsyncprompts) as $prompt) {
            $this->assertStringNotContainsString(
                self::PARTIAL_MARKER,
                (string)$prompt,
                'partial output must never be fed back to an LLM'
            );
        }
        $this->assert_partial_output_not_stored($store, (int)$threadid);
    }

    /**
     * Twice truncated construction ends as a clarification without re-running the selection.
     */
    public function test_exhausted_truncated_construction_ends_as_clarification(): void {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();

        $this->install_scripted_planner([
            $this->selector_skill_call('mod_booking.create_option'),
            $this->truncated(),
            $this->truncated(),
        ]);

        $result = $this->chat(
            'Erstelle den Workshop "Truncation Workshop" am 10.11.2045 von 10 bis 12 Uhr.',
            (int)$threadid,
            $store,
            $runtime
        );

        $this->assertSame(
            'clarification',
            (string)($result['response_type'] ?? ''),
            'an exhausted construction truncation must end as a clarification: '
                . json_encode($result['issue_codes'] ?? [])
        );
        $this->assertContains('PROVIDER_OUTPUT_TRUNCATED', (array)($result['issue_codes'] ?? []));
        $this->assertEmpty((array)($result['commands'] ?? []), 'nothing may be staged');
        $this->assertCount(3, $this->scriptedplannerprompts, 'selector once, constructor twice');
        $this->assertSame($this->scriptedplannerprompts[1], $this->scriptedplannerprompts[2]);
        $this->assert_partial_output_not_stored($store, (int)$threadid);
    }

    /**
     * Truncated synchronizer output is retried once; the healed wording is used.
     */
    public function test_truncated_synchronizer_output_is_retried_once_and_heals(): void {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();

        $healed = json_encode([
            'response_type' => 'sufficient',
            'message' => 'Hier ist die Antwort.',
            'commands' => [],
            'user_lang' => 'de',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->install_scripted_planner([], 'Done.', [$this->truncated(), $healed]);

        $result = $this->chat('Was kann ich hier tun?', (int)$threadid, $store, $runtime);

        $this->assertCount(2, $this->scriptedsyncprompts, 'exactly one retry of the synchronizer call');
        $this->assertSame($this->scriptedsyncprompts[0], $this->scriptedsyncprompts[1]);
        $this->assertSame('Hier ist die Antwort.', trim((string)($result['message'] ?? '')));
        $this->assert_partial_output_not_stored($store, (int)$threadid);
    }

    /**
     * Twice truncated synchronizer output never shows partial output to the user.
     */
    public function test_exhausted_truncated_synchronizer_output_falls_back_without_leak(): void {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();

        $this->install_scripted_planner([], 'Done.', [$this->truncated(), $this->truncated()]);

        $result = $this->chat('Was kann ich hier tun?', (int)$threadid, $store, $runtime);

        $this->assertCount(2, $this->scriptedsyncprompts, 'one retry of the synchronizer call, then stop');
        $message = trim((string)($result['message'] ?? ''));
        $this->assertNotSame('', $message);
        $this->assertStringNotContainsString(self::PARTIAL_MARKER, $message);
        $this->assert_partial_output_not_stored($store, (int)$threadid);
    }

    /**
     * An unreachable provider ends as a template: no synchronizer call against the failing service.
     */
    public function test_unreachable_provider_ends_as_template_without_synchronizer_call(): void {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();

        $this->install_scripted_planner([$this->scripted_provider_failure(504)]);

        $result = $this->chat('Wie steht mein Team da?', (int)$threadid, $store, $runtime);

        $this->assertSame('error', (string)($result['response_type'] ?? ''));
        $this->assertSame('provider_unreachable', (string)($result['error_class'] ?? ''));
        $this->assertCount(0, $this->scriptedsyncprompts, 'no LLM call may be made against an unreachable provider');
        $this->assertCount(1, $this->scriptedplannerprompts, 'an unreachable provider is not retried');
        $this->assertNotSame('', trim((string)($result['message'] ?? '')));
    }

    /**
     * Pin: a completed but unparseable answer (finish_reason 'stop') keeps the framework parse retry.
     */
    public function test_completed_unparseable_output_keeps_the_framework_parse_retry(): void {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();

        $this->install_scripted_planner([
            'this is not a planner payload',
            $this->real_clarification(),
        ]);

        $result = $this->chat('Buche den Kurs für mich.', (int)$threadid, $store, $runtime);

        $this->assertSame('clarification', (string)($result['response_type'] ?? ''));
        $this->assertCount(2, $this->scriptedplannerprompts);
        $this->assertStringContainsString(
            'RETRY_HINT',
            (string)$this->scriptedplannerprompts[1],
            'a completed unparseable answer still goes through the framework parse retry'
        );
    }
}
