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
 * Proof that run_loop can be driven deterministically through the real chat entry (no live LLM).
 *
 * @package   bookingextension_agent
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\external\ai_send_message;
use context_module;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');
require_once(__DIR__ . '/scripted_llm_trait.php');

/**
 * Deterministic run_loop smoke test via the scripted planner.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @coversNothing
 */
final class scripted_planner_smoke_test extends abstract_agent_testcase {
    use scripted_llm_trait;

    protected function setUp(): void {
        parent::setUp();
        // Deterministic path: no live LLM, so the tearDown generate_text assertion must not apply.
        $this->enforcegeneratetextassertion = false;

        // The readiness gate (ai_send_message -> check_use_readiness) requires an enabled Wunderbyte
        // provider whose endpoint targets the WB gateway. Register one with a DUMMY key: the scripted
        // responder intercepts every provider call inside llm_call_service before any HTTP happens,
        // so the key is never used — this only satisfies the config-level readiness check.
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
     * ai_send_message drives run_loop end-to-end off scripted planner output, no provider call.
     */
    public function test_scripted_sufficient_reply_drives_run_loop(): void {
        $this->setUser($this->teacher);

        $marker = 'SCRIPTED-' . 'a1b2c3';
        $this->install_scripted_planner(
            [
                // Selector: nothing to do — terminate immediately (no skill, no catalog dependency).
                json_encode([
                    'response_type' => 'sufficient',
                    'message' => $marker,
                    'commands' => [],
                    'user_lang' => 'en',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
            $marker
        );

        $_POST['sesskey'] = sesskey();
        $contextid = context_module::instance((int)$this->booking->cmid)->id;
        [, , $threadid] = $this->build_runtime();

        $response = ai_send_message::execute($contextid, 'Sag einfach kurz hallo.', (int)$threadid);

        $this->assertSame(
            'sufficient',
            (string)($response['response_type'] ?? ''),
            'Scripted terminal planner output must surface as a sufficient turn.'
        );
        $text = (string)($response['displaymessage'] ?? $response['message'] ?? '');
        $this->assertStringContainsString(
            $marker,
            $text,
            'The final message must come from the scripted (not a live) LLM. Got: ' . $text
        );
    }
}
