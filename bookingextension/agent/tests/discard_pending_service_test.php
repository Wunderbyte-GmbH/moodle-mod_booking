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

namespace bookingextension_agent;

use advanced_testcase;
use context_module;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\queue\queue_manager;
use bookingextension_agent\local\wizard\services\discard_pending_service;

/**
 * Tests the discard-pending business logic that was extracted from the ai_discard_pending
 * webservice (S8): the service consumes the pending intent and skips actionable mutating items.
 *
 * @package    bookingextension_agent
 * @category   test
 * @covers \bookingextension_agent\local\wizard\services\discard_pending_service
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class discard_pending_service_test extends advanced_testcase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }
    /**
     * Create a booking module and return [contextid, userid, threadid].
     *
     * @return array{0:int,1:int,2:int}
     */
    private function make_thread(): array {
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id, 'name' => 'Discard test']);
        $contextid = (int)context_module::instance((int)$booking->cmid)->id;
        $user = $this->getDataGenerator()->create_user();

        $store = new conversation_store();
        $thread = $store->get_or_create_thread((int)$user->id, $contextid);
        return [$contextid, (int)$user->id, (int)$thread->id];
    }

    /**
     * An actionable mutating queue item is skipped and counted.
     */
    public function test_discards_actionable_mutating_item(): void {
        $this->resetAfterTest();
        [$contextid, $userid, $threadid] = $this->make_thread();

        $store = new conversation_store();
        $queue = new queue_manager($store);
        $command = ['skill' => 'mod_booking.update_option', 'input' => ['optionid' => 1], 'risk_class' => 'broad_write'];
        $queued = $queue->enqueue_command($threadid, 0, 0, $command, 'mutating', 'blocked_confirmation');
        $queueitemid = (string)($queued['queue_item_id'] ?? '');
        $this->assertNotSame('', $queueitemid);

        $result = (new discard_pending_service($store))->discard($threadid, $userid, $contextid);

        $this->assertSame(1, $result['discardedcount']);
        $this->assertStringContainsString('discarded', $result['message']);

        // The item is no longer in an actionable mutating status (it was skipped).
        $statuses = [];
        foreach ($queue->get_queue_items($threadid) as $item) {
            if ((string)($item['queue_item_id'] ?? '') === $queueitemid) {
                $statuses[] = (string)($item['status'] ?? '');
            }
        }
        $this->assertNotContains('blocked_confirmation', $statuses, 'The discarded item must leave the actionable state.');
    }

    /**
     * With no actionable items the count is zero and the "nothing to discard" message is returned.
     */
    public function test_no_items_to_discard(): void {
        $this->resetAfterTest();
        [$contextid, $userid, $threadid] = $this->make_thread();

        $result = (new discard_pending_service())->discard($threadid, $userid, $contextid);

        $this->assertSame(0, $result['discardedcount']);
        $this->assertStringContainsString('No actionable', $result['message']);
    }

    /**
     * A read-only (non-mutating) item is never discarded.
     */
    public function test_readonly_item_is_not_discarded(): void {
        $this->resetAfterTest();
        [$contextid, $userid, $threadid] = $this->make_thread();

        $store = new conversation_store();
        $queue = new queue_manager($store);
        $command = ['skill' => 'core.get_current_user', 'input' => [], 'risk_class' => 'read_only'];
        $queue->enqueue_command($threadid, 0, 0, $command, 'readonly', 'blocked_confirmation');

        $result = (new discard_pending_service($store))->discard($threadid, $userid, $contextid);

        $this->assertSame(0, $result['discardedcount'], 'Read-only items must not be discarded.');
    }
}
