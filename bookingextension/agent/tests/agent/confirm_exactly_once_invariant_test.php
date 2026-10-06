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
 * Exactly-once invariants for the confirm/series machinery (audit 554, P2 package).
 *
 * @package   bookingextension_agent
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\agent_runtime;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\interpreter;
use bookingextension_agent\local\wizard\orchestrator;
use bookingextension_agent\local\wizard\queue\queue_manager;
use bookingextension_agent\local\wizard\skill_registry;
use bookingextension_agent\local\wizard\services\confirm_run_service;
use bookingextension_agent\local\wizard\services\decision\agent_decision_service;
use bookingextension_agent\local\wizard\services\pending_intent_service;
use bookingextension_agent\local\wizard\services\security\authorization_service;
use context_module;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');
require_once(__DIR__ . '/scripted_llm_trait.php');

/**
 * Pins the exactly-once building blocks: enforced running claim, stale-corpse reaper,
 * continuation-frame mutation gate and the turn-global loop budget.
 *
 * Thread 554 double-created series options because these invariants were only inferred
 * at the drivers (response-type guards), never enforced at the queue item itself.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\services\confirm_run_service
 * @covers \bookingextension_agent\local\wizard\queue\queue_manager
 * @covers \bookingextension_agent\local\wizard\agent_runtime
 */
final class confirm_exactly_once_invariant_test extends abstract_agent_testcase {
    use scripted_llm_trait;

    protected function setUp(): void {
        parent::setUp();
        $this->enforcegeneratetextassertion = false;
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
     * A lost running claim hard-skips execution: while another item holds the thread's
     * running slot, confirming a second item returns RUNNING_SLOT_OCCUPIED and executes
     * nothing (previously it reset the item to ready and executed anyway).
     */
    public function test_lost_running_claim_skips_execution(): void {
        $this->setUser($this->teacher);
        [$store, , $threadid] = $this->build_runtime();
        $threadid = (int)$threadid;
        $contextid = (int)context_module::instance((int)$this->booking->cmid)->id;
        $queuesvc = new queue_manager($store);

        // A FRESH running item occupies the whole-thread slot.
        $running = $queuesvc->enqueue_command($threadid, 0, 0, [
            'skill' => 'mod_booking.add_price_category',
            'input' => ['name' => 'occupier'],
        ], 'mutating', 'queued');
        $this->assertTrue($queuesvc->try_mark_running($threadid, (string)$running['queue_item_id']));

        // A second item awaits confirmation, backed by a pending intent.
        $pending = $queuesvc->enqueue_command($threadid, 0, 0, [
            'skill' => 'mod_booking.add_price_category',
            'input' => ['name' => 'confirmed one'],
        ], 'mutating', 'blocked_confirmation');
        $pendingid = (string)$pending['queue_item_id'];
        (new pending_intent_service($store))->set($threadid, (int)$this->teacher->id, $contextid, [
            'queue_item_ids' => [$pendingid],
        ]);

        $service = new confirm_run_service(skill_registry::make_default(), $store, new authorization_service());
        $payload = $service->confirm($contextid, 0, $threadid, (int)$this->teacher->id, $pendingid, false);

        $this->assertFalse((bool)($payload['success'] ?? true));
        $this->assertContains('RUNNING_SLOT_OCCUPIED', (array)($payload['issue_codes'] ?? []));

        // Nothing was executed and the confirmed item was NOT reset/consumed by the loser.
        $this->assertSame(0, $this->count_thread_runs_with_results($store, $threadid));
    }

    /**
     * A stale running corpse no longer blocks the thread: the reaper fails it
     * (RUNNING_REAPED) and the confirm proceeds past the claim.
     */
    public function test_stale_running_corpse_is_reaped_and_confirm_proceeds(): void {
        $this->setUser($this->teacher);
        [$store, , $threadid] = $this->build_runtime();
        $threadid = (int)$threadid;
        $contextid = (int)context_module::instance((int)$this->booking->cmid)->id;
        $queuesvc = new queue_manager($store);

        // A STALE running corpse (claim older than the threshold).
        $corpse = $queuesvc->enqueue_command($threadid, 0, 0, [
            'skill' => 'mod_booking.add_price_category',
            'input' => ['name' => 'corpse'],
        ], 'mutating', 'queued');
        $corpseid = (string)$corpse['queue_item_id'];
        $this->assertTrue($queuesvc->try_mark_running($threadid, $corpseid));
        $this->age_queue_item($store, $threadid, $corpseid, time() - 3600);

        $pending = $queuesvc->enqueue_command($threadid, 0, 0, [
            'skill' => 'mod_booking.add_price_category',
            'input' => ['name' => 'fresh confirm'],
        ], 'mutating', 'blocked_confirmation');
        $pendingid = (string)$pending['queue_item_id'];
        (new pending_intent_service($store))->set($threadid, (int)$this->teacher->id, $contextid, [
            'queue_item_ids' => [$pendingid],
        ]);

        // The nested continuation loop terminates immediately via the scripted planner.
        $this->install_scripted_planner([
            json_encode([
                'response_type' => 'sufficient',
                'message' => 'done',
                'commands' => [],
                'user_lang' => 'en',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ], 'done');

        $service = new confirm_run_service(skill_registry::make_default(), $store, new authorization_service());
        $payload = $service->confirm($contextid, 0, $threadid, (int)$this->teacher->id, $pendingid, false);

        // The claim must NOT be the blocker anymore.
        $this->assertNotContains('RUNNING_SLOT_OCCUPIED', (array)($payload['issue_codes'] ?? []));

        // The corpse is terminally failed with the reaper code.
        $corpseitem = $this->find_queue_item($queuesvc, $threadid, $corpseid);
        $this->assertSame('failed', (string)($corpseitem['status'] ?? ''));
        $this->assertContains('RUNNING_REAPED', (array)($corpseitem['issue_codes'] ?? []));
    }

    /**
     * In a confirm CONTINUATION frame with the plan exhausted (no placeholders, no new
     * planned_steps), a mutating command is refused instead of enqueued — the thread-554
     * re-derive corridor.
     */
    public function test_continuation_frame_blocks_planless_mutation(): void {
        $this->setUser($this->teacher);
        [$store, , $threadid] = $this->build_runtime();
        $threadid = (int)$threadid;
        $contextid = (int)context_module::instance((int)$this->booking->cmid)->id;

        $store->set_thread_metadata_value($threadid, '_confirm_continuation', 1);

        $service = new agent_decision_service(skill_registry::make_default(), $store, new authorization_service());
        $result = $service->process([
            'response_type' => 'skill_call',
            'message' => '',
            'commands' => [[
                'skill' => 'mod_booking.add_price_category',
                'input' => ['name' => 're-derived duplicate'],
            ]],
            'planned_steps' => [],
        ], $threadid, $contextid, (int)$this->teacher->id, 'en');

        $this->assertSame('clarification', (string)($result['response_type'] ?? ''));
        $this->assertContains('PLAN_COMPLETED_MUTATION_BLOCKED', (array)($result['issue_codes'] ?? []));

        // Nothing was enqueued.
        $queuesvc = new queue_manager($store);
        foreach ($queuesvc->get_queue_items($threadid) as $item) {
            $this->assertNotSame('mod_booking.add_price_category', (string)($item['skill'] ?? ''));
        }
    }

    /**
     * The turn-global loop budget bounds nested frames: with the shared budget exhausted,
     * run_loop finalizes BUDGET_EXCEEDED without a planner call; a new user message resets it.
     */
    public function test_turn_budget_bounds_nested_frames(): void {
        global $DB;
        $this->setUser($this->teacher);
        [$store, $runtime, $threadid] = $this->build_runtime();
        $threadid = (int)$threadid;
        $contextid = (int)context_module::instance((int)$this->booking->cmid)->id;

        $store->add_message($threadid, 'user', 'erster turn');
        $latestmsgid = (int)$DB->get_field_sql(
            "SELECT MAX(id) FROM {bx_agent_ai_messages} WHERE threadid = :t AND role = 'user'",
            ['t' => $threadid]
        );
        $store->set_thread_metadata_value($threadid, '_turn_loop_budget', [
            'msgid' => $latestmsgid,
            'remaining' => 0,
        ]);

        $result = $runtime->run_loop($threadid, $contextid, (int)$this->teacher->id);
        $this->assertContains('BUDGET_EXCEEDED', (array)($result['issue_codes'] ?? []));

        // A new user message opens a new turn: the budget resets and the loop runs again.
        $store->add_message($threadid, 'user', 'zweiter turn');
        $this->install_scripted_planner([
            json_encode([
                'response_type' => 'sufficient',
                'message' => 'fresh-turn-ok',
                'commands' => [],
                'user_lang' => 'en',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ], 'fresh-turn-ok');

        $result = $runtime->run_loop($threadid, $contextid, (int)$this->teacher->id);
        $this->assertNotContains('BUDGET_EXCEEDED', (array)($result['issue_codes'] ?? []));
        $this->assertStringContainsString('fresh-turn-ok', (string)($result['message'] ?? ''));
    }

    /**
     * Count runs of the thread that carry any result payload (i.e. actually executed).
     *
     * @param conversation_store $store
     * @param int $threadid
     * @return int
     */
    private function count_thread_runs_with_results(conversation_store $store, int $threadid): int {
        global $DB;
        $count = 0;
        $runs = $DB->get_records('bx_agent_ai_runs', ['threadid' => $threadid]);
        foreach ($runs as $run) {
            $results = json_decode((string)($run->resultsjson ?? ''), true);
            if (is_array($results) && !empty($results)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Fetch one queue item by id.
     *
     * @param queue_manager $queuesvc
     * @param int $threadid
     * @param string $queueitemid
     * @return array
     */
    private function find_queue_item(queue_manager $queuesvc, int $threadid, string $queueitemid): array {
        foreach ($queuesvc->get_queue_items($threadid) as $item) {
            if ((string)($item['queue_item_id'] ?? '') === $queueitemid) {
                return (array)$item;
            }
        }
        return [];
    }

    /**
     * Backdate a queue item's updated_at (simulates a crash corpse).
     *
     * @param conversation_store $store
     * @param int $threadid
     * @param string $queueitemid
     * @param int $timestamp
     */
    private function age_queue_item(conversation_store $store, int $threadid, string $queueitemid, int $timestamp): void {
        $items = (array)$store->get_thread_metadata_value($threadid, '_skill_queue_items');
        foreach ($items as &$item) {
            if (is_array($item) && (string)($item['queue_item_id'] ?? '') === $queueitemid) {
                $item['updated_at'] = $timestamp;
            }
        }
        unset($item);
        $store->set_thread_metadata_value($threadid, '_skill_queue_items', array_values($items));
    }
}
