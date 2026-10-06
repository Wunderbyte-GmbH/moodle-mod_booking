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
 * Placeholder lifecycle invariants (F5, threads 544/589): no success claim without execution.
 *
 * @package   bookingextension_agent
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\queue\queue_manager;
use bookingextension_agent\local\wizard\services\queue_status_policy;
use bookingextension_agent\local\wizard\services\queue_transition_service;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');

/**
 * Pins the bind/settle/plant lifecycle that replaced consume-at-enqueue.
 *
 * Thread 589 showed the old semantics end-to-end: a placeholder stood succeeded at ZERO runs
 * in the thread while the step's real command had failed preflight on a category question —
 * and the next selector turn was directed to the wrong pending list, so the course was never
 * created. A placeholder may claim success ONLY when its step's real command executed.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\queue\queue_manager
 * @covers \bookingextension_agent\local\wizard\services\queue_transition_service
 */
final class placeholder_lifecycle_test extends abstract_agent_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->enforcegeneratetextassertion = false;
    }

    /**
     * Build a thread with two future-step placeholders and one staged real command.
     *
     * @return array{0:\bookingextension_agent\local\wizard\conversation_store,1:int,2:queue_manager,3:string}
     */
    private function build_plan_with_real_command(): array {
        $this->setUser($this->teacher);
        [$store, , $threadid] = $this->build_runtime();
        $threadid = (int)$threadid;
        $queuesvc = new queue_manager($store);

        $queuesvc->enqueue_placeholder($threadid, 0, 0, 'Zweiter Schritt: Option aktualisieren');
        $queuesvc->enqueue_placeholder($threadid, 0, 0, 'Dritter Schritt: Teilnehmer buchen');
        $real = $queuesvc->enqueue_command($threadid, 0, 0, [
            'skill' => 'mod_booking.create_option',
            'input' => ['text' => 'Wikinger Option', 'activityquery' => 'Dup target'],
        ], 'mutating', 'queued');

        return [$store, $threadid, $queuesvc, (string)$real['queue_item_id']];
    }

    /**
     * Return one queue item by id (assertion helper).
     *
     * @param queue_manager $queuesvc
     * @param int $threadid
     * @param string $queueitemid
     * @return array
     */
    private function item(queue_manager $queuesvc, int $threadid, string $queueitemid): array {
        $item = $queuesvc->get_queue_item($threadid, $queueitemid);
        $this->assertIsArray($item, 'Queue item ' . $queueitemid . ' must exist.');
        return $item;
    }

    /**
     * Binding marks the oldest placeholder realizing (not succeeded) and success settles it.
     */
    public function test_bind_realizes_and_success_settles_placeholder(): void {
        [, $threadid, $queuesvc, $realid] = $this->build_plan_with_real_command();

        $placeholderid = $queuesvc->bind_next_placeholder($threadid, $realid);
        $this->assertNotNull($placeholderid, 'A planned placeholder must be bindable.');

        $placeholder = $this->item($queuesvc, $threadid, $placeholderid);
        $this->assertSame(queue_status_policy::realizing_status(), (string)$placeholder['status']);
        $this->assertSame($realid, (string)($placeholder['realized_by'] ?? ''));
        $this->assertSame(
            $placeholderid,
            (string)($this->item($queuesvc, $threadid, $realid)['realizes_placeholder'] ?? '')
        );
        // The in-flight step leaves the pending list; the other future step stays.
        $this->assertSame(
            ['Dritter Schritt: Teilnehmer buchen'],
            $queuesvc->get_planned_placeholder_intents($threadid)
        );

        (new queue_transition_service())->to_succeeded($queuesvc, $threadid, $realid, 'EXECUTION_SUCCEEDED');
        $this->assertSame(
            queue_status_policy::succeeded_status(),
            (string)$this->item($queuesvc, $threadid, $placeholderid)['status'],
            'Success of the real command is the only path that may mark the placeholder succeeded.'
        );
    }

    /**
     * A preflight clarification keeps the step owed: the bound placeholder reverts to planned
     * and reappears FIRST in the pending list — it never claims success (the 544/589 lie).
     */
    public function test_clarification_block_reverts_bound_placeholder_to_planned(): void {
        [, $threadid, $queuesvc, $realid] = $this->build_plan_with_real_command();
        $placeholderid = (string)$queuesvc->bind_next_placeholder($threadid, $realid);

        (new queue_transition_service())->to_failed(
            $queuesvc,
            $threadid,
            $realid,
            queue_transition_service::REASON_PREFLIGHT_NEEDS_CLARIFICATION,
            ['CONTEXT_TARGET_UNRESOLVED'],
            'preflight_block',
            'Which booking activity do you mean?'
        );
        // The transition itself must not fail the placeholder (the revert is owned by
        // ensure_blocked_step_representation, which the decision service calls right after).
        $this->assertSame(
            queue_status_policy::realizing_status(),
            (string)$this->item($queuesvc, $threadid, $placeholderid)['status']
        );

        $queuesvc->ensure_blocked_step_representation($threadid, [$realid]);

        $this->assertSame(
            queue_status_policy::planned_status(),
            (string)$this->item($queuesvc, $threadid, $placeholderid)['status']
        );
        $intents = $queuesvc->get_planned_placeholder_intents($threadid);
        $this->assertCount(2, $intents, 'No placeholder may be lost or duplicated by the revert.');
        $this->assertSame('Zweiter Schritt: Option aktualisieren', $intents[0], 'The owed step lists first.');
        $this->assertSame(0, $this->count_placeholders_with_status($queuesvc, $threadid, 'succeeded'));
    }

    /**
     * A hard preflight block fails the bound placeholder — the step is dead, not owed.
     */
    public function test_hard_block_fails_bound_placeholder(): void {
        [, $threadid, $queuesvc, $realid] = $this->build_plan_with_real_command();
        $placeholderid = (string)$queuesvc->bind_next_placeholder($threadid, $realid);

        (new queue_transition_service())->to_failed(
            $queuesvc,
            $threadid,
            $realid,
            'PREFLIGHT_HARD_BLOCK',
            ['NO_NATIVE_CAPABILITY'],
            'preflight_block',
            'Not allowed.'
        );

        $this->assertSame(
            queue_status_policy::failed_status(),
            (string)$this->item($queuesvc, $threadid, $placeholderid)['status']
        );
        $this->assertSame(
            ['Dritter Schritt: Teilnehmer buchen'],
            $queuesvc->get_planned_placeholder_intents($threadid)
        );
    }

    /**
     * The first multi-step turn's current command has no placeholder; when it blocks on a
     * clarification, one is PLANTED at the front — exactly once (thread 589's missing step).
     */
    public function test_unbound_blocked_step_is_planted_once_at_front(): void {
        [, $threadid, $queuesvc, $realid] = $this->build_plan_with_real_command();
        // No bind: the current command of the first turn never had a placeholder.

        (new queue_transition_service())->to_failed(
            $queuesvc,
            $threadid,
            $realid,
            queue_transition_service::REASON_PREFLIGHT_NEEDS_CLARIFICATION,
            ['CONTEXT_TARGET_UNRESOLVED'],
            'preflight_block',
            'Which booking activity do you mean?'
        );
        $queuesvc->ensure_blocked_step_representation($threadid, [$realid]);

        $intents = $queuesvc->get_planned_placeholder_intents($threadid);
        $this->assertCount(3, $intents);
        $this->assertStringStartsWith(
            'mod_booking.create_option',
            $intents[0],
            'The blocked current step must lead the pending list.'
        );
        $this->assertStringContainsString('text: Wikinger Option', $intents[0]);
        $this->assertSame('Zweiter Schritt: Option aktualisieren', $intents[1]);

        // Idempotent: a second pass must not plant a duplicate.
        $queuesvc->ensure_blocked_step_representation($threadid, [$realid]);
        $this->assertCount(3, $queuesvc->get_planned_placeholder_intents($threadid));
    }

    /**
     * Single-command threads (no plan in flight) keep the plain clarification flow.
     */
    public function test_single_command_thread_plants_nothing(): void {
        $this->setUser($this->teacher);
        [$store, , $threadid] = $this->build_runtime();
        $threadid = (int)$threadid;
        $queuesvc = new queue_manager($store);

        $real = $queuesvc->enqueue_command($threadid, 0, 0, [
            'skill' => 'mod_booking.create_option',
            'input' => ['text' => 'Solo Option'],
        ], 'mutating', 'queued');
        (new queue_transition_service())->to_failed(
            $queuesvc,
            $threadid,
            (string)$real['queue_item_id'],
            queue_transition_service::REASON_PREFLIGHT_NEEDS_CLARIFICATION,
            ['CONTEXT_TARGET_UNRESOLVED'],
            'preflight_block',
            'Which booking activity do you mean?'
        );
        $queuesvc->ensure_blocked_step_representation($threadid, [(string)$real['queue_item_id']]);

        $this->assertSame([], $queuesvc->get_planned_placeholder_intents($threadid));
    }

    /**
     * An expired blocked_confirmation never executed its step: the bound placeholder fails too.
     */
    public function test_expired_blocked_confirmation_fails_bound_placeholder(): void {
        [$store, $threadid, $queuesvc, $realid] = $this->build_plan_with_real_command();
        $placeholderid = (string)$queuesvc->bind_next_placeholder($threadid, $realid);

        $queuesvc->update_status($threadid, $realid, 'blocked_confirmation');
        // Backdate the TTL so the expiry sweep collects the item.
        $items = $queuesvc->get_queue_items($threadid);
        foreach ($items as $index => $item) {
            if ((string)($item['queue_item_id'] ?? '') === $realid) {
                $items[$index]['blocked_expires_at'] = time() - 10;
            }
        }
        $store->set_thread_metadata_value($threadid, '_skill_queue_items', $items);

        $this->assertSame(1, $queuesvc->fail_expired_blocked_items($threadid));
        $this->assertSame(
            queue_status_policy::failed_status(),
            (string)$this->item($queuesvc, $threadid, $placeholderid)['status'],
            'A step whose confirmation expired never ran — its placeholder must not stay realizing.'
        );
    }

    /**
     * Count placeholders in a given status (assertion helper).
     *
     * @param queue_manager $queuesvc
     * @param int $threadid
     * @param string $status
     * @return int
     */
    private function count_placeholders_with_status(queue_manager $queuesvc, int $threadid, string $status): int {
        $count = 0;
        foreach ($queuesvc->get_queue_items($threadid) as $item) {
            if (
                (string)($item['skill'] ?? '') === '__placeholder__'
                && (string)($item['status'] ?? '') === $status
            ) {
                $count++;
            }
        }
        return $count;
    }
}
