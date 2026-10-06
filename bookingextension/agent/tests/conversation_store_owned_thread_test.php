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

/**
 * Ownership scoping of conversation_store::get_owned_active_thread (S8: raw $DB read moved out of
 * the ai_send_message webservice into the store).
 *
 * @package    bookingextension_agent
 * @category   test
 * @covers \bookingextension_agent\local\wizard\conversation_store
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class conversation_store_owned_thread_test extends advanced_testcase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }
    /**
     * A thread is returned only for its owning user in its own context; everyone else gets null.
     */
    public function test_ownership_scoping(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $b1 = $this->getDataGenerator()->create_module('booking', ['course' => $course->id, 'name' => 'B1']);
        $b2 = $this->getDataGenerator()->create_module('booking', ['course' => $course->id, 'name' => 'B2']);
        $ctx1 = (int)context_module::instance((int)$b1->cmid)->id;
        $ctx2 = (int)context_module::instance((int)$b2->cmid)->id;

        $owner = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();

        $store = new conversation_store();
        $thread = $store->get_or_create_thread((int)$owner->id, $ctx1);
        $threadid = (int)$thread->id;

        // Owner + correct context -> the thread.
        $got = $store->get_owned_active_thread($threadid, (int)$owner->id, $ctx1);
        $this->assertNotNull($got);
        $this->assertSame($threadid, (int)$got->id);

        // Wrong user, wrong context, or id 0 -> null (no cross-user/-context leak).
        $this->assertNull($store->get_owned_active_thread($threadid, (int)$other->id, $ctx1));
        $this->assertNull($store->get_owned_active_thread($threadid, (int)$owner->id, $ctx2));
        $this->assertNull($store->get_owned_active_thread(0, (int)$owner->id, $ctx1));
    }
}
