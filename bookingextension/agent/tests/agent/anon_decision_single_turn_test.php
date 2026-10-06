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
 * Replay of thread 22045: a decided person reference is answered in one turn.
 *
 * @package   bookingextension_agent
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\external\ai_privacy_precheck;
use bookingextension_agent\local\wizard\services\preflight_pipeline;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');
require_once(__DIR__ . '/scripted_llm_trait.php');

/**
 * Thread 22045 (2026-09-29) needed three overlapping turns and produced two final answers for one
 * question, because the decision about the suspect word arrived while the first turn was running.
 * With the decision taken in the precheck, the planner outputs of that thread lead to exactly one
 * turn: person search, booking diagnosis, answer. Without a decision the gate stays the fallback
 * for callers that skip the blocking precheck.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\external\ai_privacy_precheck
 * @covers \bookingextension_agent\local\wizard\agent_runtime
 */
final class anon_decision_single_turn_test extends abstract_agent_testcase {
    use scripted_llm_trait;

    /** @var string The question of thread 22045. */
    private const QUESTION = 'Welche Kurse hat Billy schon abgeschlossen?';

    /** @var \stdClass The person behind the suspect word. */
    private \stdClass $billy;

    /**
     * Shared setup: strict privacy, scripted provider, one user with a colliding first name.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->enforcegeneratetextassertion = false;
        set_config('aiprivacymode', 'strict', 'bookingextension_agent');
        $this->register_live_wunderbyte_provider(
            'test-dummy-key-not-used',
            'test-model',
            'test-model',
            'test-embedding',
            'https://llm.wunderbyte.at/v1/chat/completions',
            'https://llm.wunderbyte.at/v1/embeddings'
        );
        $this->billy = $this->getDataGenerator()->create_user([
            'firstname' => 'Billy',
            'lastname' => 'Teachy',
            'email' => 'billy.teachy@example.com',
        ]);
        $this->setAdminUser();
        $_POST['sesskey'] = sesskey();
    }

    /**
     * Remove the scripted planner.
     *
     * @return void
     */
    protected function tearDown(): void {
        $this->clear_scripted_planner();
        parent::tearDown();
    }

    /**
     * Precheck the question, then run the turn with the planner outputs of thread 22045.
     *
     * @param array $decision Structured chip decision, empty for none.
     * @return array{precheck: array, result: array, threadid: int}
     */
    private function ask(array $decision): array {
        global $USER;

        $precheck = ai_privacy_precheck::execute(
            $this->booking_contextid(),
            self::QUESTION,
            1,
            json_encode((object)$decision)
        );
        $threadid = (int)$precheck['threadid'];
        $sanitized = (string)$precheck['sanitizedmessage'];
        preg_match('/ANON_USER_\d+_[a-z]+/', $sanitized, $m);
        $token = (string)($m[0] ?? '');
        $this->assertNotSame('', $token, 'Precondition: the first name is masked in the question.');

        $this->install_scripted_planner([
            $this->selector_skill_call('core.search_users', [], 'List completed courses for the found user'),
            $this->constructor_skill_call('core.search_users', ['query' => $token]),
            $this->selector_skill_call('mod_booking.diagnose_user_booking', [], ''),
            $this->constructor_skill_call('mod_booking.diagnose_user_booking', ['userid' => (int)$this->billy->id]),
            $this->planner_sufficient('done'),
        ], 'done');

        [$store, $runtime] = $this->build_runtime();
        $store->add_message($threadid, 'user', $sanitized);
        $result = $runtime->run_loop($threadid, $this->booking_contextid(), (int)$USER->id);

        return ['precheck' => $precheck, 'result' => $result, 'threadid' => $threadid];
    }

    /**
     * Skills of the completed runs of a thread, in order.
     *
     * @param int $threadid
     * @return string[]
     */
    private function executed_skills(int $threadid): array {
        global $DB;

        $skills = [];
        foreach ($DB->get_records('bx_agent_ai_runs', ['threadid' => $threadid], 'id ASC') as $run) {
            foreach ((array)json_decode((string)$run->commandsjson, true) as $command) {
                $skills[] = (string)($command['skill'] ?? '');
            }
        }
        return $skills;
    }

    /**
     * Decision taken in the precheck: one turn, both skills run once, the gate stays silent.
     */
    public function test_decided_person_is_answered_in_one_turn(): void {
        global $DB;

        $asked = $this->ask(['word' => 'Billy', 'decision' => 'person']);

        $this->assertSame('ok', (string)$asked['precheck']['status'], json_encode($asked['precheck']));
        $this->assertSame(
            'sufficient',
            (string)($asked['result']['response_type'] ?? ''),
            json_encode($asked['result']['issue_codes'] ?? [])
        );
        $this->assertNotContains(
            preflight_pipeline::ISSUE_ANON_PERSON_REFERENCE,
            (array)($asked['result']['issue_codes'] ?? [])
        );
        $this->assertSame(
            ['core.search_users', 'mod_booking.diagnose_user_booking'],
            $this->executed_skills($asked['threadid'])
        );
        $this->assertSame(
            1,
            $DB->count_records('bx_agent_ai_messages', ['threadid' => $asked['threadid'], 'role' => 'user']),
            'The decision is no message of its own.'
        );
        $this->assertSame(
            1,
            $DB->count_records('bx_agent_ai_messages', ['threadid' => $asked['threadid'], 'role' => 'assistant']),
            'One question, one answer.'
        );
        $stored = $DB->get_fieldset_select('bx_agent_ai_messages', 'content', 'threadid = ?', [$asked['threadid']]);
        $this->assertStringNotContainsString('Billy', implode("\n", $stored), 'Stored history carries the token only.');
    }

    /**
     * Fallback for callers that continue without a decision: the gate asks, nothing is executed.
     */
    public function test_undecided_person_still_ends_at_the_gate(): void {
        $asked = $this->ask([]);

        $this->assertSame('needs_decision', (string)$asked['precheck']['status'], json_encode($asked['precheck']));
        $this->assertSame('clarification', (string)($asked['result']['response_type'] ?? ''));
        $this->assertContains(
            preflight_pipeline::ISSUE_ANON_PERSON_REFERENCE,
            (array)($asked['result']['issue_codes'] ?? [])
        );
        $this->assertSame([], $this->executed_skills($asked['threadid']));
    }
}
