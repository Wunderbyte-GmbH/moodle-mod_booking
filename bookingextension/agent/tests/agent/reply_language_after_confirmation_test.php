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
 * The reply after a confirmation is written in the conversation's language.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\services\language_policy_service;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');
require_once(__DIR__ . '/scripted_llm_trait.php');

/**
 * Write path W6, GQ-1 (thread 17337): "Take the handout PDF ... turn it into a dozen bank questions." (English) - the
 * selector said user_lang en, the constructor asked for confirmation, and after the click the reply came in German.
 * A turn that ends as a confirmation_request never reaches the synchronizer, so the conversation's language
 * (language_policy_service, b05a47b) was never stored; the synchronizer after the confirmation had no selector output
 * of its own and got no REPLY LANGUAGE line. In the VM data every thread whose first turn asked for confirmation
 * (GQ-1, UQ-4, UA-1, AQ-2, ...) carried no reply_language.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\agent_runtime
 * @covers \bookingextension_agent\local\wizard\services\language_policy_service
 */
final class reply_language_after_confirmation_test extends abstract_agent_testcase {
    use scripted_llm_trait;

    /**
     * Provider, capabilities, a German-speaking user and a page to rename.
     */
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
        global $DB;
        // The Moodle profile language differs from the conversation's (the VM test user works in German).
        $DB->set_field('user', 'lang', 'de', ['id' => $this->teacher->id]);
        $this->getDataGenerator()->create_module('page', ['course' => $this->course->id, 'name' => 'Untitled page']);
    }

    /**
     * Release the scripted planner.
     */
    protected function tearDown(): void {
        $this->clear_scripted_planner();
        parent::tearDown();
    }

    /**
     * A selector output in the shape of thread 17337.
     *
     * @param string $userlang '' for a selector that states no language.
     * @return string
     */
    private function selector(string $userlang): string {
        $output = [
            'response_type' => 'skill_call',
            'commands' => [['skill' => 'course.update_activity', 'input' => []]],
            'planned_steps' => [],
            'next_step_intent' => '',
            'message' => '',
        ];
        if ($userlang !== '') {
            $output['lang'] = $userlang;
            $output['user_lang'] = $userlang;
        }
        return json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Ask, confirm, and return the synchronizer prompt of the confirmation turn and the thread id.
     *
     * @param string $userlang
     * @return array [prompt after the confirmation, threadid]
     */
    private function confirm_turn(string $userlang): array {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();
        $this->install_scripted_planner([
            $this->selector($userlang),
            $this->constructor_confirmation_request('course.update_activity', [
                'activityquery' => 'Untitled page',
                'name' => 'Programme Overview',
            ]),
        ]);
        $result = $this->chat('Rename the "Untitled page" to "Programme Overview".', (int)$threadid, $store, $runtime);
        $this->assertSame('confirmation_request', (string)($result['response_type'] ?? ''));
        $before = count($this->scriptedsyncprompts);

        $this->confirm_pending_result($result, (int)$threadid, $store);

        $after = array_slice($this->scriptedsyncprompts, $before);
        $this->assertNotEmpty($after, 'The confirmation turn is answered by the synchronizer.');
        return [(string)end($after), (int)$threadid];
    }

    /**
     * The request's language reaches the reply after the confirmation, also when the user's profile language differs.
     */
    public function test_the_reply_after_a_confirmation_keeps_the_request_language(): void {
        [$prompt, $threadid] = $this->confirm_turn('en');

        $this->assertStringContainsString('REPLY LANGUAGE: en', $prompt);
        $this->assertSame('en', (string)(new \bookingextension_agent\local\wizard\conversation_store())
            ->get_thread_metadata_value($threadid, language_policy_service::THREAD_REPLY_LANGUAGE_KEY));
    }

    /**
     * Non-success path: a selector that states no language gives no line - nothing is guessed, and the turn still ends
     * with the synchronizer's answer.
     */
    public function test_no_language_known_no_line(): void {
        [$prompt] = $this->confirm_turn('');

        $this->assertStringNotContainsString('REPLY LANGUAGE', $prompt);
    }
}
