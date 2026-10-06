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
 * Follow-up promotion after confirm_run must come from QUEUE state, not the model's reply.
 *
 * @package   bookingextension_agent
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\external\ai_confirm_run;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\queue\queue_manager;
use bookingextension_agent\local\wizard\skill_registry;
use context_module;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');
require_once(__DIR__ . '/scripted_llm_trait.php');

/**
 * Naht-1 pin of response_type_engine_state_ANALYSE_2026-07-15: with a LIVE provider the
 * confirm continuation frame may close the turn as 'sufficient' although the pending intent
 * still owns a blocked_confirmation follow-up item — the item then strands until its TTL while
 * the reply reads like success. The engine must promote the follow-up to a confirmation_request
 * based on the queue facts, regardless of what the model replied. The scripted planner emulates
 * exactly the live-path shape (every planner call returns 'sufficient') without an LLM; the
 * same scenario without a provider passes via the deterministic fallback
 * (ai_confirm_run_contract_test::test_follow_up_pending_intent_forces_confirmation_request).
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\services\confirm_run_service
 */
final class confirm_followup_promotion_test extends abstract_agent_testcase {
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
     * A model-authored 'sufficient' must not strand the pending intent's follow-up item.
     */
    public function test_followup_item_is_promoted_despite_sufficient_reply(): void {
        global $DB;

        $this->setUser($this->teacher);
        // Every planner/synchronizer call replies 'sufficient' — the live-path failure shape.
        $this->install_scripted_planner([], 'The booking option has been created successfully.');

        $registry = skill_registry::make_default();
        $skill = $registry->get_skill('mod_booking.create_option');
        if ($skill === null) {
            $this->fail('mod_booking.create_option is not available in the current skill catalog.');
        }

        $contextid = (int)context_module::instance((int)$this->booking->cmid)->id;
        $userid = (int)$this->teacher->id;
        $store = new conversation_store();
        $thread = $store->get_or_create_thread($userid, $contextid);
        $threadid = (int)$thread->id;
        $queuesvc = new queue_manager($store);

        $command1 = [
            'skill' => 'mod_booking.create_option',
            'version' => 1,
            'input' => ['text' => 'Follow-up promotion option 1'],
        ];
        $command2 = [
            'skill' => 'mod_booking.create_option',
            'version' => 1,
            'input' => ['text' => 'Follow-up promotion option 2'],
        ];

        $preflight1 = $skill->preflight((array)$command1['input'], $contextid, $userid);
        $preflight2 = $skill->preflight((array)$command2['input'], $contextid, $userid);
        $this->assertSame('pass', $preflight1->status);
        $this->assertSame('pass', $preflight2->status);

        $queued1 = $queuesvc->enqueue_command($threadid, 0, 0, $command1, 'mutating', 'blocked_confirmation');
        $queuesvc->set_prepared_input($threadid, (string)$queued1['queue_item_id'], $contextid, $preflight1->preparedinput);
        $queued2 = $queuesvc->enqueue_command(
            $threadid,
            0,
            0,
            $command2,
            'mutating',
            'blocked_confirmation',
            [(string)$queued1['queue_item_id']]
        );
        $queuesvc->set_prepared_input($threadid, (string)$queued2['queue_item_id'], $contextid, $preflight2->preparedinput);

        $store->set_pending_intent(
            $threadid,
            hash('sha256', $userid . ':' . $threadid . ':initial'),
            $userid,
            $contextid,
            [
                'queue_item_ids' => [
                    (string)$queued1['queue_item_id'],
                    (string)$queued2['queue_item_id'],
                ],
            ]
        );

        $_POST['sesskey'] = sesskey();
        $result = ai_confirm_run::execute($contextid, $threadid, (string)$queued1['queue_item_id'], true);

        // Step 1 executed exactly once.
        $this->assertTrue(
            $DB->record_exists('booking_options', [
                'bookingid' => (int)$this->booking->id,
                'text' => 'Follow-up promotion option 1',
            ]),
            'The confirmed first step must execute.'
        );

        // The queue still owes step 2 — the reply must surface it, whatever the model said.
        $items = $queuesvc->get_queue_items($threadid);
        $item2 = null;
        foreach ($items as $item) {
            if ((string)($item['queue_item_id'] ?? '') === (string)$queued2['queue_item_id']) {
                $item2 = $item;
            }
        }
        $this->assertIsArray($item2);
        $this->assertSame(
            'blocked_confirmation',
            (string)($item2['status'] ?? ''),
            'The follow-up item must still await ITS OWN confirmation (never executed in-frame).'
        );

        $this->assertSame(
            'confirmation_request',
            (string)($result['response_type'] ?? ''),
            'A pending blocked_confirmation follow-up must surface as confirmation_request — a '
                . 'model-authored "sufficient" reply must never strand the series. Message: '
                . (string)($result['message'] ?? '')
        );
        $this->assertSame(
            (string)$queued2['queue_item_id'],
            (string)($result['queueitemid'] ?? ''),
            'The surfaced confirmation must reference the follow-up queue item.'
        );
    }

    /**
     * The 554 protection stands: an actionable item OUTSIDE the consumed intent is NOT restaged
     * on a terminal 'sufficient' — planner-terminal authority still terminalizes stale or
     * over-planned queue items; the R1 carve-out is scoped to the user-authorized series only.
     */
    public function test_item_outside_consumed_intent_stays_terminalized(): void {
        $this->setUser($this->teacher);
        $this->install_scripted_planner([], 'Done.');

        $registry = skill_registry::make_default();
        $skill = $registry->get_skill('mod_booking.create_option');
        if ($skill === null) {
            $this->fail('mod_booking.create_option is not available in the current skill catalog.');
        }

        $contextid = (int)context_module::instance((int)$this->booking->cmid)->id;
        $userid = (int)$this->teacher->id;
        $store = new conversation_store();
        $thread = $store->get_or_create_thread($userid, $contextid);
        $threadid = (int)$thread->id;
        $queuesvc = new queue_manager($store);

        $command1 = [
            'skill' => 'mod_booking.create_option',
            'version' => 1,
            'input' => ['text' => 'Authorized intent option'],
        ];
        $stalecommand = [
            'skill' => 'mod_booking.create_option',
            'version' => 1,
            'input' => ['text' => 'Stale over-planned option'],
        ];

        $preflight1 = $skill->preflight((array)$command1['input'], $contextid, $userid);
        $this->assertSame('pass', $preflight1->status);

        $queued1 = $queuesvc->enqueue_command($threadid, 0, 0, $command1, 'mutating', 'blocked_confirmation');
        $queuesvc->set_prepared_input($threadid, (string)$queued1['queue_item_id'], $contextid, $preflight1->preparedinput);
        // The stale item is actionable but was NEVER shown to the user on this confirm card.
        $stale = $queuesvc->enqueue_command($threadid, 0, 0, $stalecommand, 'mutating', 'blocked_confirmation');

        $store->set_pending_intent(
            $threadid,
            hash('sha256', $userid . ':' . $threadid . ':initial'),
            $userid,
            $contextid,
            ['queue_item_ids' => [(string)$queued1['queue_item_id']]]
        );

        $_POST['sesskey'] = sesskey();
        $result = ai_confirm_run::execute($contextid, $threadid, (string)$queued1['queue_item_id'], true);

        $this->assertSame(
            'sufficient',
            (string)($result['response_type'] ?? ''),
            'With the authorized series complete, the planner-terminal verdict must stand — the '
                . 'stale item outside the consumed intent may not be re-animated (thread 554).'
        );

        $staleitem = null;
        foreach ($queuesvc->get_queue_items($threadid) as $item) {
            if ((string)($item['queue_item_id'] ?? '') === (string)$stale['queue_item_id']) {
                $staleitem = $item;
            }
        }
        $this->assertIsArray($staleitem);
        $this->assertSame('blocked_confirmation', (string)($staleitem['status'] ?? ''));
    }
}
