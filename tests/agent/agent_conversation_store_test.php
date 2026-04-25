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
 * Tests for the AI agent conversation store.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use mod_booking\local\wbagent\conversation_store;

/**
 * Tests for the AI agent conversation store (DB layer).
 *
 * @package    mod_booking
 * @category   test
 * @coversNothing
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class agent_conversation_store_test extends advanced_testcase {
    /**
     * Test thread creation and retrieval.
     */
    public function test_get_or_create_thread_creates_new(): void {
        $this->resetAfterTest();
        $store = new conversation_store();
        $thread = $store->get_or_create_thread(1, 10, 5);

        $this->assertNotEmpty($thread->id);
        $this->assertEquals(1, $thread->userid);
        $this->assertEquals(10, $thread->cmid);
        $this->assertEquals('active', $thread->status);
    }

    /**
     * Test that calling get_or_create_thread twice returns the same thread.
     */
    public function test_get_or_create_thread_returns_existing(): void {
        $this->resetAfterTest();
        $store   = new conversation_store();
        $thread1 = $store->get_or_create_thread(2, 20, 7);
        $thread2 = $store->get_or_create_thread(2, 20, 7);

        $this->assertEquals($thread1->id, $thread2->id);
    }

    /**
     * Test adding and retrieving messages.
     */
    public function test_add_and_get_messages(): void {
        $this->resetAfterTest();
        $store  = new conversation_store();
        $thread = $store->get_or_create_thread(3, 30, 9);

        $store->add_message($thread->id, 'user', 'Hello AI');
        $store->add_message($thread->id, 'assistant', 'Hello human');

        $messages = $store->get_messages($thread->id);
        $this->assertCount(2, $messages);
        $this->assertEquals('user', $messages[0]->role);
        $this->assertEquals('assistant', $messages[1]->role);
    }

    /**
     * Test run creation and status update.
     */
    public function test_create_and_update_run(): void {
        $this->resetAfterTest();
        $store  = new conversation_store();
        $thread = $store->get_or_create_thread(4, 40, 11);
        $key    = hash('sha256', 'test-idempotency-key-' . time());

        $runid = $store->create_run($thread->id, 4, 40, $key, [['task' => 'booking.create_option']]);
        $this->assertGreaterThan(0, $runid);

        $run = $store->get_run($runid);
        $this->assertEquals('pending', $run->status);

        $store->update_run_status($runid, 'completed', [['status' => 'executed', 'detail' => 'done', 'resultid' => 99]]);
        $run = $store->get_run($runid);
        $this->assertEquals('completed', $run->status);
        $this->assertStringContainsString('executed', $run->resultsjson);
    }

    /**
     * Test idempotency key detection.
     */
    public function test_run_exists_detects_duplicate(): void {
        $this->resetAfterTest();
        $store  = new conversation_store();
        $thread = $store->get_or_create_thread(5, 50, 13);
        $key    = hash('sha256', 'unique-key-' . uniqid());

        $this->assertFalse($store->run_exists($key));

        $store->create_run($thread->id, 5, 50, $key, []);
        $this->assertTrue($store->run_exists($key));
    }

    /**
     * Test get_recent_messages returns N most recent in chronological order.
     */
    public function test_get_recent_messages_order(): void {
        $this->resetAfterTest();
        $store  = new conversation_store();
        $thread = $store->get_or_create_thread(6, 60, 15);

        for ($i = 1; $i <= 5; $i++) {
            $store->add_message($thread->id, 'user', "Message $i");
        }

        $recent = $store->get_recent_messages($thread->id, 3);
        $this->assertCount(3, $recent);
        // Should be messages 3, 4, 5 in chronological order.
        $this->assertStringContainsString('Message 3', $recent[0]->content);
        $this->assertStringContainsString('Message 5', $recent[2]->content);
    }
}
