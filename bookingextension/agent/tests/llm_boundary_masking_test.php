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
 * No masked value reaches an LLM in clear text (HARD RULE 2026-09-11).
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use advanced_testcase;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\privacy_anonymizer;
use bookingextension_agent\local\wizard\services\messaging\message_persistence_service;
use bookingextension_agent\local\wizard\services\preflight_pipeline;
use bookingextension_agent\local\wizard\services\synchronizer_input_builder;

/**
 * Taskflow baseline threads 1301-1304: the collision clarification was built with the original
 * word, the synchronizer received it unmasked and the stored reply carried it in clear text, so
 * every later turn sent the masked name to the model through the history — right next to its
 * token. Three layers now hold the invariant: engine texts carry the token, the synchronizer
 * input is masked, and the stored conversation holds masked values only.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\services\preflight_pipeline
 * @covers     \bookingextension_agent\local\wizard\services\synchronizer_input_builder
 * @covers     \bookingextension_agent\local\wizard\services\messaging\message_persistence_service
 */
final class llm_boundary_masking_test extends advanced_testcase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }

    /**
     * A site user whose lastname is an ordinary word, a thread and the token the precheck minted.
     *
     * @return array{0: conversation_store, 1: privacy_anonymizer, 2: int, 3: string}
     */
    private function prepare(): array {
        global $USER;
        $this->setAdminUser();
        $this->getDataGenerator()->create_user([
            'firstname' => 'Goduuara',
            'lastname' => 'Herbst',
            'email' => 'goduuara.herbst@example.com',
        ]);
        set_config('aiprivacymode', 'strict', 'bookingextension_agent');

        $store = new conversation_store();
        $threadid = (int)$store->get_or_create_thread((int)$USER->id, (int)\context_system::instance()->id)->id;
        $anonymizer = new privacy_anonymizer($store);
        $precheck = $anonymizer->precheck_user_message($threadid, 'Was wird im Herbst angeboten?');
        preg_match('/ANON_USER_\d+_[a-z]+/', (string)$precheck['sanitizedmessage'], $m);
        $token = (string)($m[0] ?? '');
        $this->assertNotSame('', $token, 'Precondition: the single word was masked.');

        return [$store, $anonymizer, $threadid, $token];
    }

    /**
     * Layer 1: the gate clarification names the token; the display resolves it; the chips name the word.
     */
    public function test_gate_clarification_carries_the_token(): void {
        $this->resetAfterTest();
        [, $anonymizer, $threadid, $token] = $this->prepare();

        $issue = preflight_pipeline::build_person_reference_issue($token, 'Herbst');

        $this->assertStringContainsString($token, (string)$issue['message']);
        $this->assertStringNotContainsString('Herbst', (string)$issue['message']);
        $this->assertSame('Herbst', (string)$issue['preview']['payload']['word']);
        $display = $anonymizer->deanonymize_message_for_display($threadid, (string)$issue['message']);
        $this->assertStringContainsString('Herbst', (string)$display['message']);
    }

    /**
     * Layer 2: engine-built texts with a de-anonymized word never reach the synchronizer in clear
     * text; instructional engine-static rows stay untouched.
     */
    public function test_synchronizer_input_is_masked(): void {
        $this->resetAfterTest();
        [, $anonymizer, $threadid, $token] = $this->prepare();
        $builder = new synchronizer_input_builder();

        $result = [
            'response_type' => 'error',
            'message' => 'Should I look up a person called "Herbst"?',
            'errors' => ['Command #1: No person matches "Herbst".'],
            'issue_codes' => ['DEMO_FAILURE'],
            'results' => [
                ['skill' => 'core.search_users', 'status' => 'error', 'usermessage' => 'No person matches "Herbst".'],
                [
                    'skill' => 'wizard.search_skills',
                    'status' => 'executed',
                    'observation_engine_static' => true,
                    'observation_full' => 'Catalog text Herbst stays.',
                ],
            ],
        ];

        $masked = $builder->mask_for_llm($result, $anonymizer, $threadid);
        $observations = implode("\n", $builder->build_observations($masked));

        $this->assertStringNotContainsString('Herbst', $observations, 'No masked value may reach the synchronizer.');
        $this->assertStringContainsString($token, $observations);
        $this->assertSame('Catalog text Herbst stays.', (string)$masked['results'][1]['observation_full']);
    }

    /**
     * Layer 3: the stored reply — LLM input of every later turn — holds masked values only and
     * the display still shows the word.
     */
    public function test_persisted_reply_is_masked_and_displays_the_word(): void {
        global $DB;
        $this->resetAfterTest();
        [$store, $anonymizer, $threadid, $token] = $this->prepare();

        (new message_persistence_service($store))->persist_assistant_message($threadid, [
            'response_type' => 'clarification',
            'message' => 'Soll ich nach einer Person namens „Herbst“ suchen?',
            'errors' => ['Command #1: I was about to interpret "Herbst" as a person\'s name.'],
        ]);

        $records = $DB->get_records('bx_agent_ai_messages', ['threadid' => $threadid, 'role' => 'assistant'], 'id DESC', '*', 0, 1);
        $record = reset($records);
        $this->assertNotFalse($record);
        $this->assertStringNotContainsString('Herbst', (string)$record->content);
        $this->assertStringContainsString($token, (string)$record->content);
        $this->assertStringNotContainsString('Herbst', (string)$record->structuredjson);

        $display = $anonymizer->deanonymize_message_for_display($threadid, (string)$record->content);
        $this->assertStringContainsString('Herbst', (string)$display['message']);
    }

    /**
     * Lauf 8 F60 (thread 1555): since 2f1121a the persistence ran the single-word name fallback over
     * engine and planner texts, so ordinary words that happen to be site first names ("Note", "will")
     * became new tokens and the stored history (LLM input of later turns) turned unreadable.
     * Storage re-masks known values only and never mints single-word tokens.
     */
    public function test_persisted_text_does_not_mint_tokens_for_unmapped_single_words(): void {
        global $DB;
        $this->resetAfterTest();
        // Users first: the anonymizer's name index is built on the first precheck of the request.
        $this->getDataGenerator()->create_user(['firstname' => 'Note', 'lastname' => 'Quellenbach']);
        $this->getDataGenerator()->create_user(['firstname' => 'Will', 'lastname' => 'Pardubitz']);
        [$store, $anonymizer, $threadid] = $this->prepare();
        $before = count((array)($store->get_thread_metadata_value($threadid, 'privacy_anon_map')['entries'] ?? []));

        $text = 'You are about to delete all stored memories. Note: this will be carried out in activity "ai".';
        (new message_persistence_service($store))->persist_assistant_message($threadid, [
            'response_type' => 'confirmation_request',
            'message' => $text,
        ]);

        $records = $DB->get_records('bx_agent_ai_messages', ['threadid' => $threadid, 'role' => 'assistant'], 'id DESC', '*', 0, 1);
        $record = reset($records);
        $this->assertSame($text, (string)$record->content, 'Unmapped single words must be stored as written.');
        $after = count((array)($store->get_thread_metadata_value($threadid, 'privacy_anon_map')['entries'] ?? []));
        $this->assertSame($before, $after, 'Storage must not mint new single-word tokens.');
    }

    /**
     * The leak stays closed for text the LLM never saw masked: a full name of a site user that is not
     * in the thread map (e.g. an engine-built candidate list) is still masked before it is stored.
     */
    public function test_persisted_text_masks_unmapped_full_names(): void {
        global $DB;
        $this->resetAfterTest();
        $this->getDataGenerator()->create_user(['firstname' => 'Annabel', 'lastname' => 'Maierhofer']);
        [$store, $anonymizer, $threadid] = $this->prepare();

        (new message_persistence_service($store))->persist_assistant_message($threadid, [
            'response_type' => 'clarification',
            'message' => 'Did you mean Annabel Maierhofer?',
            'errors' => ['Two people match: Annabel Maierhofer.'],
        ]);

        $records = $DB->get_records('bx_agent_ai_messages', ['threadid' => $threadid, 'role' => 'assistant'], 'id DESC', '*', 0, 1);
        $record = reset($records);
        $this->assertStringNotContainsString('Maierhofer', (string)$record->content);
        $this->assertStringNotContainsString('Maierhofer', (string)$record->structuredjson);
        $display = $anonymizer->deanonymize_message_for_display($threadid, (string)$record->content);
        $this->assertStringContainsString('Annabel Maierhofer', (string)$display['message']);
    }
}
