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

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\services\model_authored_text;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');
require_once(__DIR__ . '/scripted_llm_trait.php');

/**
 * George 2026-09-26: text an LLM wrote is only ever resolved from placeholders to names (display), never masked
 * again. The model sees masked data only, so re-masking its words protects nothing and corrupts them - N36, ED-1
 * thread 13358: the selector quoted the documentation ("Max. number of participants") and the synchronizer received
 * "ANON_USER_2_firstname. number of participants" because the site has a user called Max. Engine-built texts that
 * carry a de-anonymized value (the gate question of threads 1301-1304) stay masked: the engine marks a text as the
 * model's only while it is byte-identical to what the model returned (llm_boundary_masking_test pins that side).
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\interpreter
 * @covers \bookingextension_agent\local\wizard\services\synchronizer_input_builder
 * @covers \bookingextension_agent\local\wizard\services\synchronizer_output_contract
 * @covers \bookingextension_agent\local\wizard\services\messaging\message_persistence_service
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class model_text_is_never_remasked_test extends abstract_agent_testcase {
    use scripted_llm_trait;

    /** Documentation wording the selector quotes (the site has a user called Max). */
    private const QUOTE = 'Max. number of participants';

    /**
     * Provider, capabilities, strict privacy, all skills and a user whose first name is a word of the documentation.
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
        set_config('aiprivacymode', 'strict', 'bookingextension_agent');
        set_config('aiskillenableall', 1, 'bookingextension_agent');
        $this->getDataGenerator()->create_user(['firstname' => 'Max', 'lastname' => 'Quellhuber']);
    }

    /**
     * Release the scripted planner.
     */
    protected function tearDown(): void {
        $this->clear_scripted_planner();
        parent::tearDown();
    }

    /**
     * Thread 13358 replayed: the selector's own words and the synchronizer's reply stay as the model wrote them.
     */
    public function test_the_models_words_reach_the_synchronizer_and_the_history_unchanged(): void {
        global $DB;
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();
        $reply = 'Laut Doku: "' . self::QUOTE . '" (maxanswers) sind die bestätigten Plätze.';
        $this->install_phase_scripted_planner(
            [
                $this->selector_skill_call('wizard.explain_docs'),
                $this->planner_sufficient('Die Doku sagt: ' . self::QUOTE . ' = bestätigte Plätze.'),
            ],
            [
                $this->constructor_skill_call('wizard.explain_docs', [
                    'question' => 'Wie funktioniert die Warteliste?',
                    'doc_path' => 'booking-option/09-waitinglist.md',
                ]),
            ],
            $reply
        );

        $this->chat('Wie funktioniert die Warteliste?', (int)$threadid, $store, $runtime);

        $sync = implode("\n", $this->scriptedsyncprompts);
        // Since thread 23502 (2026-09-30) the selector's "sufficient" text is no observation once a skill result exists
        // in the turn (synchronizer_input_contract_test); what reaches the synchronizer is never a re-masked text.
        $this->assertStringNotContainsString('PLANNER_TEXT', $sync, 'the skill result is the fact, not the planner text');
        $this->assertStringContainsString(self::QUOTE, $sync, 'the skill observation as written');
        $this->assertDoesNotMatchRegularExpression('/ANON_USER_\d+_firstname\. number/', $sync);

        $stored = $DB->get_records(
            'bx_agent_ai_messages',
            ['threadid' => (int)$threadid, 'role' => 'assistant'],
            'id DESC',
            'id, content',
            0,
            1
        );
        $content = (string)(reset($stored)->content ?? '');
        $this->assertStringContainsString(self::QUOTE, $content, 'the stored reply as the model wrote it');
    }

    /**
     * The mark holds only while the message is exactly the model's: an engine rewrite or a stale mark (the selector's
     * words while the message is already another text) is masked as before.
     */
    public function test_an_engine_rewrite_or_a_stale_mark_is_not_model_text(): void {
        $marked = model_authored_text::mark(['message' => 'Max. number'], 'Max. number');
        $this->assertTrue(model_authored_text::is_model_message($marked));

        $rewritten = $marked;
        $rewritten['message'] = 'Should I look up a person called "Max"?';
        $this->assertFalse(model_authored_text::is_model_message($rewritten), 'engine rewrite');

        $this->assertFalse(model_authored_text::is_model_message(['message' => 'x']), 'never marked');
        $this->assertFalse(
            model_authored_text::is_model_message(model_authored_text::mark(['message' => 'a'], 'b')),
            'a mark for another text is not set'
        );
    }
}
