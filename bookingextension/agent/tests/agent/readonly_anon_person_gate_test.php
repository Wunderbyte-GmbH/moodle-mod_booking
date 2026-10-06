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
 * Anonymizer person-reference gate on the read-only chat path (#2363).
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\privacy_anonymizer;
use bookingextension_agent\local\wizard\queue\queue_manager;
use bookingextension_agent\local\wizard\services\decision\agent_decision_service;
use bookingextension_agent\local\wizard\services\execution_observation_ledger;
use bookingextension_agent\local\wizard\services\preflight_pipeline;
use bookingextension_agent\local\wizard\services\preview_passthrough;
use bookingextension_agent\local\wizard\services\security\authorization_service;
use bookingextension_agent\local\wizard\skill_registry;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');

/**
 * Taskflow baseline threads 1173/1201/1214/1224: "Was hab ich noch offen" masked the word
 * "hab" as the first name of a site user; the constructor bound the token to userquery and the
 * read-only path de-anonymized it to that user's real e-mail address. The #2226 D3 gate
 * existed only inside the preflight pipeline, which the chat runs for confirmation requests
 * alone — read-only commands executed without it. The gate now runs in the decision service
 * before read-only execution, triggered by the binding to a person field, not by a skill
 * attribute.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\services\decision\agent_decision_service
 * @covers \bookingextension_agent\local\wizard\services\preflight_pipeline
 */
final class readonly_anon_person_gate_test extends abstract_agent_testcase {
    /**
     * Mint a low-confidence single-word token in a fresh thread of the teacher.
     *
     * @return array{store: \bookingextension_agent\local\wizard\conversation_store, threadid: int, token: string,
     *     anonymizer: privacy_anonymizer}
     */
    private function prepare_thread_with_token(): array {
        $this->setUser($this->teacher);
        $this->grant_agent_capabilities_to_editingteacher();
        set_config('aiprivacymode', 'strict', 'bookingextension_agent');
        // A site user whose first name collides with an ordinary word of the prompt.
        $this->getDataGenerator()->create_user([
            'firstname' => 'Hab',
            'lastname' => 'Jacquemin',
            'email' => 'hab.jacquemin@example.com',
        ]);

        [$store, , $threadid] = $this->build_runtime();
        $threadid = (int)$threadid;
        $anonymizer = new privacy_anonymizer($store);
        $precheck = $anonymizer->precheck_user_message($threadid, 'Was hab ich eigentlich noch offen?');
        preg_match('/ANON_USER_\d+_[a-z]+/', (string)$precheck['sanitizedmessage'], $m);
        $token = (string)($m[0] ?? '');
        $this->assertNotSame('', $token, 'Precondition: the single word "hab" was masked as a low-confidence token.');

        return ['store' => $store, 'threadid' => $threadid, 'token' => $token, 'anonymizer' => $anonymizer];
    }

    /**
     * Run one read-only command through the decision service exactly as the planner hands it over.
     *
     * @param array $ctx Output of prepare_thread_with_token().
     * @param string $skillname
     * @param array $input Raw (still anonymized) input.
     * @return array Decision result.
     */
    private function decide(array $ctx, string $skillname, array $input): array {
        $service = new agent_decision_service(skill_registry::make_default(), $ctx['store'], new authorization_service());
        return $service->process([
            'response_type' => 'skill_call',
            'message' => '',
            'commands' => [[
                'skill' => $skillname,
                'version' => 1,
                'input' => $input,
                '_structural_validated' => true,
            ]],
        ], $ctx['threadid'], $this->booking_contextid(), (int)$this->teacher->id, 'en');
    }

    /**
     * Number of runs recorded for the thread (a gated turn must create none).
     *
     * @param int $threadid
     * @return int
     */
    private function run_count(int $threadid): int {
        global $DB;
        return $DB->count_records('bx_agent_ai_runs', ['threadid' => $threadid]);
    }

    /**
     * A read-only skill whose person field carries the suspect token ends the turn as a
     * clarification with the decision chips; nothing is executed, no run exists.
     */
    public function test_token_in_person_field_ends_turn_as_clarification_without_execution(): void {
        $ctx = $this->prepare_thread_with_token();

        $decision = $this->decide($ctx, 'mod_booking.diagnose_user_booking', [
            'userquery' => $ctx['token'],
            'optionquery' => 'Irrelevant',
        ]);

        $this->assertSame('clarification', (string)($decision['response_type'] ?? ''), json_encode($decision));
        $this->assertContains(preflight_pipeline::ISSUE_ANON_PERSON_REFERENCE, (array)($decision['issue_codes'] ?? []));
        $this->assertStringContainsString(
            $ctx['token'],
            (string)($decision['message'] ?? ''),
            'The clarification carries the token (LLM input); the display resolves it (HARD RULE 2026-09-11).'
        );
        $this->assertSame(0, $this->run_count($ctx['threadid']), 'a gated read-only command must never execute');

        // The decision chips travel via the same-turn preview stash (source C).
        $preview = preview_passthrough::consume_clarification_preview_json(
            $ctx['store'],
            $ctx['threadid'],
            'clarification',
            ''
        );
        $this->assertNotSame('', (string)$preview);
        $decoded = json_decode((string)$preview, true);
        $this->assertSame('anon_word_decision', (string)($decoded['type'] ?? ''));
        $this->assertSame('hab', (string)($decoded['payload']['word'] ?? ''));

        // The queue item settled as a preflight-class block, not as ready/succeeded.
        $items = (new queue_manager($ctx['store']))->get_queue_items($ctx['threadid']);
        $this->assertCount(1, $items);
        $this->assertContains(
            preflight_pipeline::ISSUE_ANON_PERSON_REFERENCE,
            (array)($items[0]['issue_codes'] ?? [])
        );
        $this->assertNotSame('ready', (string)($items[0]['status'] ?? ''));
        $this->assertNotSame('succeeded', (string)($items[0]['status'] ?? ''));
    }

    /**
     * Taskflow baseline F23 (thread 1295): core.search_users bound the suspect token to its
     * free-text query and listed the real users carrying that name. The skill declares the
     * query as a person lookup (get_person_reference_fields()), so the gate ends the turn first.
     */
    public function test_search_users_query_is_gated(): void {
        $ctx = $this->prepare_thread_with_token();

        $decision = $this->decide($ctx, 'core.search_users', ['query' => $ctx['token']]);

        $this->assertSame('clarification', (string)($decision['response_type'] ?? ''), json_encode($decision));
        $this->assertContains(preflight_pipeline::ISSUE_ANON_PERSON_REFERENCE, (array)($decision['issue_codes'] ?? []));
        $this->assertSame(0, $this->run_count($ctx['threadid']), 'the colliding user must never be looked up');
    }

    /**
     * F23: a lookup of a DIFFERENT person earlier in the thread no longer opens the gate —
     * person context counts only for the same word, backed by more identity material.
     */
    public function test_unrelated_person_context_does_not_open_the_gate(): void {
        $ctx = $this->prepare_thread_with_token();
        (new execution_observation_ledger($ctx['store']))->append_from_results($ctx['threadid'], [[
            'skill' => 'core.search_users',
            'status' => 'executed',
            'input' => ['query' => 'Maria Muster'],
            'observation_full' => 'Found 1 user: userid=42.',
        ]]);

        $decision = $this->decide($ctx, 'core.search_users', ['query' => $ctx['token']]);

        $this->assertContains(preflight_pipeline::ISSUE_ANON_PERSON_REFERENCE, (array)($decision['issue_codes'] ?? []));
        $this->assertSame(0, $this->run_count($ctx['threadid']));
    }

    /**
     * The same token in a NON-person field passes: the command executes as before.
     */
    public function test_token_in_nonperson_field_executes(): void {
        $ctx = $this->prepare_thread_with_token();

        $decision = $this->decide($ctx, 'mod_booking.search_options', ['query' => $ctx['token']]);

        $this->assertNotContains(preflight_pipeline::ISSUE_ANON_PERSON_REFERENCE, (array)($decision['issue_codes'] ?? []));
        $this->assertNotSame('clarification', (string)($decision['response_type'] ?? ''));
        $this->assertSame(1, $this->run_count($ctx['threadid']), 'a non-person slot never gates');
    }

    /**
     * A stored decision for the word ("ordinary word") ends the gate: the command executes.
     */
    public function test_stored_word_decision_lets_the_command_execute(): void {
        $ctx = $this->prepare_thread_with_token();
        $ctx['anonymizer']->record_anon_word_decision((int)$this->teacher->id, 'hab', 'word');

        $decision = $this->decide($ctx, 'mod_booking.diagnose_user_booking', [
            'userquery' => $ctx['token'],
            'optionquery' => 'Irrelevant',
        ]);

        $this->assertNotContains(preflight_pipeline::ISSUE_ANON_PERSON_REFERENCE, (array)($decision['issue_codes'] ?? []));
        $this->assertSame(1, $this->run_count($ctx['threadid']), 'a decided word never gates again');
    }
}
