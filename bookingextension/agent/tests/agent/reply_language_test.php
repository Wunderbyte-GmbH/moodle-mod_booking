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
 * The reply language is stated to the synchronizer: the conversation's language, kept while the user answers.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\services\language_policy_service;
use bookingextension_agent\local\wizard\services\synchronizer_prompt_builder;

/**
 * L45 SCC-2, SCC-3, GQ-3, URT-3 (George 2026-09-27): the reply came in the language of the stored memory, of an attached
 * document or of the constructor's question instead of the user's. A/B at the recorded synchronizer calls (20 runs):
 * an explicit line with the conversation's language gave 20/20 where the prompt had competing languages (SCC-2 6/15
 * before). The language is the selector's user_lang of the user's message - engine state, no detection of its own. It
 * has gravity (George): an answer to a waiting question or confirmation keeps the thread language, so a "Yes" in a
 * German conversation stays German; a new request in another language switches it. The constructor's user_lang never
 * counts (URT-3: selector fr, constructor de).
 *
 * @covers \bookingextension_agent\local\wizard\services\language_policy_service
 * @covers \bookingextension_agent\local\wizard\services\synchronizer_prompt_builder
 */
final class reply_language_test extends \advanced_testcase {
    /** @var conversation_store */
    private conversation_store $store;

    /** @var int */
    private int $threadid = 0;

    /** @var language_policy_service */
    private language_policy_service $policy;

    /**
     * A thread of the admin.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;
        $this->store = new conversation_store();
        $this->threadid = (int)$this->store->get_or_create_thread((int)$USER->id, (int)\context_system::instance()->id)->id;
        $this->policy = new language_policy_service();
    }

    /**
     * A planner result whose selection and construction report their own user_lang.
     *
     * @param string $selector
     * @param string $constructor
     * @return array
     */
    private function planner_result(string $selector, string $constructor = ''): array {
        return [
            'response_type' => 'clarification',
            'user_lang' => $constructor,
            'planner_result' => [
                'selection' => ['user_lang' => $selector],
                'parameter_construction' => ['user_lang' => $constructor],
            ],
        ];
    }

    /**
     * One user turn: the user's message, then (optionally) the assistant's reply of that turn.
     *
     * @param string $user
     * @param string $assistantresponsetype Empty = the turn has no reply yet (the current turn).
     */
    private function turn(string $user, string $assistantresponsetype = ''): void {
        $this->store->add_message($this->threadid, 'user', $user);
        if ($assistantresponsetype !== '') {
            $this->store->add_message($this->threadid, 'assistant', 'reply', ['response_type' => $assistantresponsetype]);
        }
    }

    /**
     * The first message sets the language; the synchronizer prompt states it.
     */
    public function test_the_first_message_sets_the_reply_language(): void {
        $this->turn('Génère tout le contenu du cours.');
        $lang = $this->policy->resolve_reply_language($this->store, $this->threadid, $this->planner_result('fr'));
        $this->assertSame('fr', $lang);
        $prompt = (new synchronizer_prompt_builder())->build_prompt('SYS', [], ['obs'], '', '', 'none', [], [], $lang);
        $this->assertStringContainsString('REPLY LANGUAGE: fr (French), the language of this conversation.', $prompt);
    }

    /**
     * An answer to a waiting question or confirmation keeps the thread language.
     */
    public function test_an_answer_keeps_the_conversation_language(): void {
        foreach (['clarification', 'confirmation_request'] as $waiting) {
            $this->resetAfterTest();
            $systemcontextid = (int)\context_system::instance()->id;
            $this->threadid = (int)$this->store->create_fresh_thread((int)get_admin()->id, $systemcontextid)->id;
            $this->turn('Leg bitte eine neue Option an.');
            $first = $this->policy->resolve_reply_language($this->store, $this->threadid, $this->planner_result('de'));
            $this->assertSame('de', $first);
            $this->store->add_message($this->threadid, 'assistant', 'Frage', ['response_type' => $waiting]);
            $this->turn('Yes');
            $this->assertSame(
                'de',
                $this->policy->resolve_reply_language($this->store, $this->threadid, $this->planner_result('en')),
                "a 'Yes' to a $waiting stays in the conversation's language"
            );
        }
    }

    /**
     * A new request in another language switches the conversation.
     */
    public function test_a_new_request_in_another_language_switches(): void {
        $this->turn('Leg bitte eine neue Option an.');
        $this->policy->resolve_reply_language($this->store, $this->threadid, $this->planner_result('de'));
        $this->store->add_message($this->threadid, 'assistant', 'Erledigt.', ['response_type' => 'sufficient']);
        $this->turn('Now show me all options of next week.');
        $this->assertSame('en', $this->policy->resolve_reply_language($this->store, $this->threadid, $this->planner_result('en')));
        $this->store->add_message($this->threadid, 'assistant', 'Which one?', ['response_type' => 'clarification']);
        $this->turn('Ja');
        $this->assertSame('en', $this->policy->resolve_reply_language($this->store, $this->threadid, $this->planner_result('de')));
    }

    /**
     * The constructor's user_lang never decides (URT-3: selector fr, constructor de).
     */
    public function test_the_constructor_language_does_not_count(): void {
        $this->turn('Modifie le texte du message de la règle de rappel existante.');
        $lang = $this->policy->resolve_reply_language($this->store, $this->threadid, $this->planner_result('fr', 'de'));
        $this->assertSame('fr', $lang);
    }

    /**
     * The shape the engine really delivers (thread 15788, WRITERUN4): the selection state keeps the selector's parsed
     * output under phase_output, without a top-level user_lang. The first version read only the top level, so no
     * synchronizer prompt of L46 carried the line.
     */
    public function test_the_selector_language_is_read_from_the_phase_output(): void {
        $this->turn('Take the handout PDF sitting in Brandschutz im Betrieb and turn it into a dozen bank questions.');
        $result = [
            'response_type' => 'clarification',
            'lang' => 'de',
            'planner_result' => [
                'selection' => [
                    'phase' => 'selection',
                    'response_type' => 'skill_call',
                    'selected_skill' => 'question.generate_questions',
                    'phase_output' => ['response_type' => 'skill_call', 'lang' => 'en', 'user_lang' => 'en'],
                ],
                'parameter_construction' => ['user_lang' => 'de'],
            ],
        ];
        $this->assertSame('en', $this->policy->resolve_reply_language($this->store, $this->threadid, $result));
    }

    /**
     * Threads 23502/23481/23506 (2026-09-30): nothing in the synchronizer prompt said that the user has not seen the
     * observations; the runtime ledger even said "[already shown in OBSERVATION blocks above]". gpt-oss answered
     * "I have already listed them". The sentence stands directly before the first observation block (measured: in the
     * rules or in the turn-state line it changed nothing, 2/2 stubs; before the block, 2/2 full lists).
     */
    public function test_the_observations_are_marked_as_unseen_by_the_user(): void {
        $prompt = (new synchronizer_prompt_builder())
            ->build_prompt('SYS', [], ['first fact', 'second fact'], '', 'state', 'none', [], [], 'de');
        $sentence = synchronizer_prompt_builder::OBSERVATIONS_UNSEEN_LINE;
        $this->assertSame(1, substr_count($prompt, $sentence));
        $this->assertStringContainsString($sentence . "\n\n[OBSERVATION 1]\nfirst fact", $prompt);
        $this->assertStringContainsString("[OBSERVATION 2]\nsecond fact", $prompt);
        $this->assertLessThan(strpos($prompt, $sentence), strpos($prompt, '[SYSTEM_RUNTIME_STATE]'));

        $none = (new synchronizer_prompt_builder())->build_prompt('SYS', [], [], '', '', 'none', [], [], 'de');
        $this->assertStringNotContainsString($sentence, $none, 'no observations, nothing to mark');
    }

    /**
     * Non-success path: no usable code and no thread language - no line, the prompt is as before.
     */
    public function test_without_a_language_there_is_no_line(): void {
        $this->turn('???');
        $lang = $this->policy->resolve_reply_language($this->store, $this->threadid, $this->planner_result('', 'xx-invalid-'));
        $this->assertSame('', $lang);
        $prompt = (new synchronizer_prompt_builder())->build_prompt('SYS', [], ['obs'], '', '', 'none', [], [], $lang);
        $this->assertStringNotContainsString('REPLY LANGUAGE', $prompt);
        $this->assertSame('', $this->policy->resolve_reply_language($this->store, $this->threadid, $this->planner_result('zz')));
    }
}
