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
 * A re-planned series stays confirmable (Wunderbyte-GmbH/Wunderbyte-GmbH#2679).
 *
 * @package   bookingextension_agent
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\queue\queue_manager;
use bookingextension_agent\local\wizard\skill_registry;
use bookingextension_agent\local\wizard\services\confirm_run_service;
use bookingextension_agent\local\wizard\services\pending_intent_service;
use bookingextension_agent\local\wizard\services\security\authorization_service;
use context_module;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');
require_once(__DIR__ . '/scripted_llm_trait.php');

/**
 * Thread 6342 (second VM, 2026-10-20): a three-step series was confirmed once, a stale confirm consumed the
 * intent, the user re-planned ("start with the third, drop the second"), the new card reused the third item
 * with its old dependency on the dropped step, and the confirm ended as DEPENDENCY_WAITING with no card left.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\queue\queue_manager
 * @covers \bookingextension_agent\local\wizard\services\confirm_run_service
 * @covers \bookingextension_agent\local\wizard\services\pending_intent_service
 */
final class replanned_series_dependencies_test extends abstract_agent_testcase {
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
     * A reused item carries the edges of the proposal being staged, not of the one it came from; a cycle keeps the
     * old edges.
     */
    public function test_a_reused_item_takes_the_dependencies_of_the_new_proposal(): void {
        $this->setUser($this->teacher);
        [$store, , $threadid] = $this->build_runtime();
        $threadid = (int)$threadid;
        $queuesvc = new queue_manager($store);

        [$aid, $bid, $cid] = $this->stage_series($queuesvc, $threadid);

        // Re-plan: the third step becomes the head.
        $again = $queuesvc->enqueue_command($threadid, 0, 0, $this->command('third'), 'mutating', 'blocked_confirmation', []);
        $this->assertSame($cid, (string)$again['queue_item_id']);
        $this->assertTrue((bool)($again['idempotency_reused'] ?? false));
        $this->assertSame([], (array)$again['depends_on']);
        $this->assertSame([], (array)$this->find_queue_item($queuesvc, $threadid, $cid)['depends_on']);

        // The next step of the new proposal hangs off the new head.
        $again = $queuesvc->enqueue_command($threadid, 0, 0, $this->command('second'), 'mutating', 'blocked_confirmation', [$cid]);
        $this->assertSame($bid, (string)$again['queue_item_id']);
        $this->assertSame([$cid], (array)$again['depends_on']);

        // An edge that would close a cycle (third -> second -> third) is refused: the old edge stays.
        $again = $queuesvc->enqueue_command($threadid, 0, 0, $this->command('third'), 'mutating', 'blocked_confirmation', [$bid]);
        $this->assertSame($cid, (string)$again['queue_item_id']);
        $this->assertSame([], (array)$again['depends_on']);
        $this->assertSame('succeeded', (string)$this->find_queue_item($queuesvc, $threadid, $aid)['status']);
    }

    /**
     * Confirming the reused head of a re-planned series runs it instead of waiting for a dropped step.
     */
    public function test_confirming_a_reused_head_runs_instead_of_waiting(): void {
        $this->setUser($this->teacher);
        [$store, , $threadid] = $this->build_runtime();
        $threadid = (int)$threadid;
        $contextid = (int)context_module::instance((int)$this->booking->cmid)->id;
        $queuesvc = new queue_manager($store);

        [, , $cid] = $this->stage_series($queuesvc, $threadid);
        $queuesvc->enqueue_command($threadid, 0, 0, $this->command('third'), 'mutating', 'blocked_confirmation', []);
        (new pending_intent_service($store))->set($threadid, (int)$this->teacher->id, $contextid, ['queue_item_ids' => [$cid]]);
        $this->install_scripted_planner([$this->planner_sufficient('done')], 'done');

        $service = new confirm_run_service(skill_registry::make_default(), $store, new authorization_service());
        $payload = $service->confirm($contextid, 0, $threadid, (int)$this->teacher->id, $cid, false);

        $this->assertNotContains('DEPENDENCY_WAITING', (array)($payload['issue_codes'] ?? []), json_encode($payload));
        // Staged without preflight the item carries no guard token, so execution refuses it; the point is that the
        // confirm reached execution instead of waiting for the dropped step.
        $item = $this->find_queue_item($queuesvc, $threadid, $cid);
        $this->assertNotSame('blocked_confirmation', (string)$item['status'], json_encode($item, JSON_UNESCAPED_UNICODE));
        $this->assertNotContains('DEPENDENCY_WAITING', (array)($item['issue_codes'] ?? []));
    }

    /**
     * A stale id or a dependency not met yet runs nothing, so the card stays confirmable; once the dependency has
     * succeeded the same card runs.
     */
    public function test_an_error_before_execution_keeps_the_card_confirmable(): void {
        $this->setUser($this->teacher);
        [$store, , $threadid] = $this->build_runtime();
        $threadid = (int)$threadid;
        $contextid = (int)context_module::instance((int)$this->booking->cmid)->id;
        $queuesvc = new queue_manager($store);
        $intents = new pending_intent_service($store);

        $first = $queuesvc->enqueue_command($threadid, 0, 0, $this->command('first'), 'mutating', 'blocked_confirmation');
        $aid = (string)$first['queue_item_id'];
        $second = $queuesvc->enqueue_command($threadid, 0, 0, $this->command('second'), 'mutating', 'blocked_confirmation', [$aid]);
        $bid = (string)$second['queue_item_id'];
        $code = $intents->set($threadid, (int)$this->teacher->id, $contextid, ['queue_item_ids' => [$bid]]);
        $this->install_scripted_planner([$this->planner_sufficient('done')], 'done');
        $service = new confirm_run_service(skill_registry::make_default(), $store, new authorization_service());

        $payload = $service->confirm($contextid, 0, $threadid, (int)$this->teacher->id, 'q0_0', false);
        $this->assertContains('INVALID_QUEUE_ITEM_ID', (array)($payload['issue_codes'] ?? []));
        $this->assertSame($code, (string)($intents->get($threadid)['confirmationcode'] ?? ''), 'intent survives a stale id');

        $payload = $service->confirm($contextid, 0, $threadid, (int)$this->teacher->id, $bid, false);
        $this->assertContains('DEPENDENCY_WAITING', (array)($payload['issue_codes'] ?? []));
        $this->assertSame($code, (string)($intents->get($threadid)['confirmationcode'] ?? ''),
            'intent survives a waiting dependency');

        $queuesvc->update_status($threadid, $aid, 'succeeded');
        $payload = $service->confirm($contextid, 0, $threadid, (int)$this->teacher->id, $bid, false);
        $this->assertNotContains('DEPENDENCY_WAITING', (array)($payload['issue_codes'] ?? []), json_encode($payload));
        $item = $this->find_queue_item($queuesvc, $threadid, $bid);
        $this->assertNotSame('blocked_confirmation', (string)$item['status'], json_encode($item, JSON_UNESCAPED_UNICODE));
        $this->assertNull($intents->get($threadid), 'a confirm that ran consumes the intent');
    }

    /**
     * Three chained price-category creates: first succeeded, second and third waiting for confirmation.
     *
     * @param queue_manager $queuesvc
     * @param int $threadid
     * @return string[] queue item ids
     */
    private function stage_series(queue_manager $queuesvc, int $threadid): array {
        $first = $queuesvc->enqueue_command($threadid, 0, 0, $this->command('first'), 'mutating', 'queued');
        $aid = (string)$first['queue_item_id'];
        $queuesvc->update_status($threadid, $aid, 'succeeded');
        $second = $queuesvc->enqueue_command($threadid, 0, 0, $this->command('second'), 'mutating', 'blocked_confirmation', [$aid]);
        $bid = (string)$second['queue_item_id'];
        $third = $queuesvc->enqueue_command($threadid, 0, 0, $this->command('third'), 'mutating', 'blocked_confirmation', [$bid]);
        $cid = (string)$third['queue_item_id'];
        $this->assertSame([$bid], (array)$third['depends_on']);
        return [$aid, $bid, $cid];
    }

    /**
     * One mutating command; the same name gives the same input signature.
     *
     * @param string $name
     * @return array
     */
    private function command(string $name): array {
        return ['skill' => 'mod_booking.add_price_category', 'input' => ['name' => 'series ' . $name]];
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
}
