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
 * The requester in a person field whose empty value does not mean the requester (#2569).
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\privacy_anonymizer;
use bookingextension_agent\local\wizard\queue\queue_manager;
use bookingextension_agent\local\wizard\skill_registry;
use bookingextension_agent\local\wizard\services\confirm_run_service;
use bookingextension_agent\local\wizard\services\pending_intent_service;
use bookingextension_agent\local\wizard\services\runtime_context_block_builder;
use bookingextension_agent\local\wizard\services\security\authorization_service;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');
require_once(__DIR__ . '/scripted_llm_trait.php');

/**
 * training.wunderbyte.at thread 203 (2026-10-06): "five events next week, I am the trainer" never reached the
 * confirmation. The constructor wrote "me", then "self" into teacherquery; the requester's own token would have been
 * stripped (#2246), and teacherquery has no "empty means the requester" meaning - an option without a trainer.
 * The constructor outputs below are the recorded ones (llm_debug 3751, 3760), with dates moved into the future.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\services\decision\agent_decision_service
 * @covers \bookingextension_agent\local\wizard\services\requester_reference
 */
final class requester_person_field_test extends abstract_agent_testcase {
    use scripted_llm_trait;

    protected function setUp(): void {
        parent::setUp();
        $this->enforcegeneratetextassertion = false;
        $this->grant_agent_capabilities_to_editingteacher();
        set_config('aiprivacymode', 'strict', 'bookingextension_agent');
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
     * The recorded create_option parameters of thread 203 with the given trainer value.
     *
     * @param string $teacherquery
     * @return array
     */
    private function recorded_parameters(string $teacherquery): array {
        $monday = strtotime('next monday', time() + WEEKSECS);
        $dates = [];
        for ($day = 0; $day < 5; $day++) {
            $start = $monday + ($day * DAYSECS) + (10 * HOURSECS);
            $dates[] = [
                'coursestarttime' => date('Y-m-d\TH:i:s', $start),
                'courseendtime' => date('Y-m-d\TH:i:s', $start + (2 * HOURSECS)),
            ];
        }
        return [
            'text' => 'Abendveranstaltung 1',
            'maxanswers' => 20,
            'teacherquery' => $teacherquery,
            'optiondates' => $dates,
            'optiondatesmode' => 'replace',
        ];
    }

    /**
     * Issue codes of a turn result.
     *
     * @param array $result
     * @return string[]
     */
    private function issue_codes(array $result): array {
        return array_values(array_map('strval', (array)($result['issue_codes'] ?? [])));
    }

    /**
     * The requester's own token in teacherquery makes the requester the trainer of the staged option.
     */
    public function test_requester_token_as_trainer_reaches_the_confirmation(): void {
        global $DB;

        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();
        $threadid = (int)$threadid;

        $tokens = runtime_context_block_builder::current_user_identity_tokens(
            new privacy_anonymizer($store),
            $threadid,
            $store
        );
        $this->assertNotEmpty($tokens, 'the requester identity must be expressible as anonymized tokens');

        $this->install_scripted_planner([
            $this->selector_skill_call('mod_booking.create_option'),
            $this->constructor_confirmation_request('mod_booking.create_option', $this->recorded_parameters($tokens[0])),
        ]);

        $result = $this->chat(
            'Erstelle fünf Veranstaltungen "Abendveranstaltung 1" für jeden Wochentag nächster Woche von 10 bis 12. '
                . 'Trainer bin ich. Es können 20 Leute kommen.',
            $threadid,
            $store,
            $runtime
        );

        $codes = $this->issue_codes($result);
        $diagnostics = json_encode([
            'response_type' => $result['response_type'] ?? null,
            'issue_codes' => $codes,
            'errors' => $result['errors'] ?? null,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $this->assertSame('confirmation_request', (string)($result['response_type'] ?? ''), $diagnostics);

        $staged = array_values(array_filter(
            (new queue_manager($store))->get_queue_items($threadid),
            static fn(array $item): bool => (string)($item['status'] ?? '') === 'blocked_confirmation'
        ));
        $this->assertCount(1, $staged, $diagnostics);
        $prepared = (array)($staged[0]['prepared_input'] ?? []);
        $this->assertSame(
            (string)$this->teacher->email,
            (string)($prepared['teacheremail'] ?? ''),
            'the confirmation must name the requester as trainer: ' . json_encode($prepared, JSON_UNESCAPED_UNICODE)
        );

        // One confirm, as the button does: the option exists with the requester as its trainer (DB fact).
        $queueitemid = (string)($staged[0]['queue_item_id'] ?? '');
        $contextid = $this->booking_contextid();
        $userid = (int)$this->teacher->id;
        (new pending_intent_service($store))->set($threadid, $userid, $contextid, ['queue_item_ids' => [$queueitemid]]);
        (new confirm_run_service(skill_registry::make_default(), $store, new authorization_service()))
            ->confirm($contextid, 0, $threadid, $userid, $queueitemid, false);
        $optionid = (int)$DB->get_field(
            'booking_options',
            'id',
            ['bookingid' => (int)$this->booking->id, 'text' => 'Abendveranstaltung 1']
        );
        $this->assertGreaterThan(0, $optionid, 'the confirmed create must add the option');
        $this->assertTrue(
            $DB->record_exists('booking_teachers', ['optionid' => $optionid, 'userid' => $userid]),
            'the requester must be the trainer of the created option'
        );
    }

    /**
     * The shape the constructor gives under the frozen rule 4 (A/B 2026-10-06 on the recorded call: 16 of 16): no
     * person in teacherquery, the yes/no companion set. The requester is the trainer of the created option.
     */
    public function test_requester_flag_as_trainer_reaches_the_confirmation(): void {
        global $DB;

        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();
        $threadid = (int)$threadid;

        $parameters = $this->recorded_parameters('');
        unset($parameters['teacherquery']);
        $parameters['teacherquery_is_requester'] = true;
        $this->install_scripted_planner([
            $this->selector_skill_call('mod_booking.create_option'),
            $this->constructor_confirmation_request('mod_booking.create_option', $parameters),
        ]);

        $result = $this->chat(
            'Erstelle fünf Veranstaltungen "Abendveranstaltung 1" für jeden Wochentag nächster Woche von 10 bis 12. '
                . 'Trainer bin ich. Es können 20 Leute kommen.',
            $threadid,
            $store,
            $runtime
        );
        $diagnostics = json_encode([
            'response_type' => $result['response_type'] ?? null,
            'issue_codes' => $this->issue_codes($result),
            'errors' => $result['errors'] ?? null,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $this->assertSame('confirmation_request', (string)($result['response_type'] ?? ''), $diagnostics);

        $staged = array_values(array_filter(
            (new queue_manager($store))->get_queue_items($threadid),
            static fn(array $item): bool => (string)($item['status'] ?? '') === 'blocked_confirmation'
        ));
        $this->assertCount(1, $staged, $diagnostics);
        $prepared = (array)($staged[0]['prepared_input'] ?? []);
        $this->assertArrayNotHasKey('teacherquery_is_requester', $prepared, 'the companion never reaches the skill');
        $this->assertSame((string)$this->teacher->email, (string)($prepared['teacheremail'] ?? ''), $diagnostics);

        $queueitemid = (string)($staged[0]['queue_item_id'] ?? '');
        $contextid = $this->booking_contextid();
        $userid = (int)$this->teacher->id;
        (new pending_intent_service($store))->set($threadid, $userid, $contextid, ['queue_item_ids' => [$queueitemid]]);
        (new confirm_run_service(skill_registry::make_default(), $store, new authorization_service()))
            ->confirm($contextid, 0, $threadid, $userid, $queueitemid, false);
        $optionid = (int)$DB->get_field(
            'booking_options',
            'id',
            ['bookingid' => (int)$this->booking->id, 'text' => 'Abendveranstaltung 1']
        );
        $this->assertGreaterThan(0, $optionid);
        $this->assertTrue($DB->record_exists('booking_teachers', ['optionid' => $optionid, 'userid' => $userid]));
    }

    /**
     * A bare word in teacherquery never becomes a search hit on unrelated users: the turn ends as a clarification
     * (not as an error) that offers the matching users as choices, and the reply facts name no user id.
     */
    public function test_a_bare_word_for_the_trainer_offers_the_matching_users_as_choices(): void {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        // Thread 203: five users whose names contain the letters of the word matched as a substring.
        $others = [];
        foreach (['Acessamenus', 'Ahmeti', 'Adame', 'Mehmet', 'Clemens'] as $name) {
            $others[] = $this->getDataGenerator()->create_user(['firstname' => $name, 'lastname' => 'Baseline']);
        }
        [$store, $runtime, $threadid] = $this->build_runtime();
        $threadid = (int)$threadid;

        $this->install_scripted_planner([
            $this->selector_skill_call('mod_booking.create_option'),
            $this->constructor_confirmation_request('mod_booking.create_option', $this->recorded_parameters('me')),
            // The selector gets the offered choices once (wave 30) and asks which person is meant.
            json_encode([
                'response_type' => 'clarification',
                'message' => 'Which person is meant?',
                'commands' => [],
                'next_step_intent' => '',
            ]),
        ]);

        $result = $this->chat(
            'Erstelle fünf Veranstaltungen "Abendveranstaltung 1" für jeden Wochentag nächster Woche von 10 bis 12. '
                . 'Trainer bin ich. Es können 20 Leute kommen.',
            $threadid,
            $store,
            $runtime
        );

        // P6a: an ambiguous person is offered as a choice (one more selector call that carries every match),
        // never as an error text listing user ids; the turn ends as a clarification.
        $this->assertCount(3, $this->scriptedplannerprompts, 'the choices must go back to the selector once');
        $choiceprompt = $this->scriptedplannerprompts[2];
        foreach ($others as $other) {
            $this->assertStringContainsString((string)$other->id, $choiceprompt, 'every match must be offered as a choice');
        }
        $this->assertSame('clarification', (string)($result['response_type'] ?? ''));
        $errors = implode("\n", array_map('strval', (array)($result['errors'] ?? [])));
        foreach ($others as $other) {
            $this->assertStringNotContainsString((string)$other->id, $errors, 'no bare user id in the reply facts');
        }
    }
}
