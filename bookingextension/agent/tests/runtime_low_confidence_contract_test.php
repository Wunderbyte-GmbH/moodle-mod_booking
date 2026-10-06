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
 * Conditional planner contract for low-confidence anonymizer tokens (#2226 D2).
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use advanced_testcase;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\orchestrator;
use bookingextension_agent\local\wizard\privacy_anonymizer;
use bookingextension_agent\local\wizard\services\assistant_state_guidance_service;
use bookingextension_agent\local\wizard\services\completed_command_history_service;
use bookingextension_agent\local\wizard\services\planner_catalog_service;
use bookingextension_agent\local\wizard\services\runtime_context_block_builder;

/**
 * When a thread's token map holds low-confidence (single-word) name hits, the
 * selection and construction prompts must carry a deterministic notice that the
 * token may be an ordinary word — in the VOLATILE state block only, so the
 * cached prompt prefix stays stable. Full-name hits and words with a stored
 * user decision emit nothing.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\services\runtime_context_block_builder
 */
final class runtime_low_confidence_contract_test extends advanced_testcase {
    /** @var string Marker line of the injected contract. */
    private const MARKER = 'LOW-CONFIDENCE ANONYMIZATION NOTICE:';

    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }

    /**
     * Build the service with its (simple) dependency chain.
     *
     * @param conversation_store $store
     * @return runtime_context_block_builder
     */
    private function builder(conversation_store $store): runtime_context_block_builder {
        return new runtime_context_block_builder(
            $store,
            new completed_command_history_service($store),
            new planner_catalog_service(new assistant_state_guidance_service())
        );
    }

    /**
     * Prepare collision user, thread and strict privacy mode.
     *
     * @return array{0: conversation_store, 1: privacy_anonymizer, 2: int, 3: int}
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
        $contextid = (int)\context_system::instance()->id;
        $threadid = (int)$store->get_or_create_thread((int)$USER->id, $contextid)->id;

        return [$store, new privacy_anonymizer($store), $threadid, $contextid];
    }

    /**
     * A low-confidence token injects the contract into the volatile half of both planner phases.
     */
    public function test_low_confidence_token_injects_contract_in_volatile_half(): void {
        $this->resetAfterTest();
        [$store, $anonymizer, $threadid, $contextid] = $this->prepare();
        $anonymizer->precheck_user_message($threadid, 'Was wird im Herbst angeboten?');

        foreach ([orchestrator::PHASE_SELECTION, orchestrator::PHASE_PARAMETER_CONSTRUCTION] as $phase) {
            $block = $this->builder($store)->build($threadid, $contextid, $phase);
            $this->assertStringContainsString(
                self::MARKER,
                (string)$block['volatile'],
                "Phase {$phase} must carry the low-confidence contract in the volatile state block."
            );
            $this->assertStringNotContainsString(
                self::MARKER,
                (string)$block['stable'],
                'The contract is per-turn state and must never pollute the cached stable prefix.'
            );
        }
    }

    /**
     * Full-name (high-confidence) hits emit no contract.
     */
    public function test_full_name_token_emits_no_contract(): void {
        $this->resetAfterTest();
        [$store, $anonymizer, $threadid, $contextid] = $this->prepare();
        $anonymizer->precheck_user_message($threadid, 'Schreibe Goduuara Herbst in den Kurs ein.');

        $block = $this->builder($store)->build($threadid, $contextid, orchestrator::PHASE_SELECTION);
        $this->assertStringNotContainsString(self::MARKER, (string)$block['volatile']);
        $this->assertStringNotContainsString(self::MARKER, (string)$block['stable']);
    }

    /**
     * A stored "person" decision for the word suppresses the contract.
     */
    public function test_person_decision_suppresses_contract(): void {
        global $USER;
        $this->resetAfterTest();
        [$store, $anonymizer, $threadid, $contextid] = $this->prepare();

        $this->assertTrue(
            method_exists($anonymizer, 'record_anon_word_decision'),
            'privacy_anonymizer must expose record_anon_word_decision(int $userid, string $word, string $decision).'
        );

        $anonymizer->precheck_user_message($threadid, 'Was wird im Herbst angeboten?');
        $anonymizer->record_anon_word_decision((int)$USER->id, 'Herbst', 'person');

        $block = $this->builder($store)->build($threadid, $contextid, orchestrator::PHASE_SELECTION);
        $this->assertStringNotContainsString(
            self::MARKER,
            (string)$block['volatile'],
            'A confirmed person interpretation ends the low-confidence treatment for that word.'
        );
    }
}
