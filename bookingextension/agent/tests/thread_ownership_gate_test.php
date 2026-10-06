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
 * Tests for the thread-ownership gate that protects the threadid webservice entry points.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\conversation_store;

/**
 * IDOR regression: a client-supplied threadid must only resolve when it belongs to the calling
 * user (and lives in the validated context). Guessing another user's thread id must not grant
 * read or mutate access.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\conversation_store::thread_belongs_to_user
 */
final class thread_ownership_gate_test extends \advanced_testcase {
    /**
     * The gate accepts the owner in the right context and rejects everything else.
     */
    public function test_gate_only_accepts_owner_in_matching_context(): void {
        $this->resetAfterTest();

        $alice = $this->getDataGenerator()->create_user();
        $bob = $this->getDataGenerator()->create_user();
        $coursea = $this->getDataGenerator()->create_course();
        $courseb = $this->getDataGenerator()->create_course();
        $ctxa = (int)\context_course::instance($coursea->id)->id;
        $ctxb = (int)\context_course::instance($courseb->id)->id;

        $store = new conversation_store();
        $alicethread = (int)$store->get_or_create_thread((int)$alice->id, $ctxa)->id;

        // Owner in the matching context: allowed.
        $this->assertTrue($store->thread_belongs_to_user($alicethread, (int)$alice->id, $ctxa));

        // Another user guessing the id: rejected (the IDOR case).
        $this->assertFalse($store->thread_belongs_to_user($alicethread, (int)$bob->id, $ctxa));

        // Owner but wrong context: rejected (defense in depth).
        $this->assertFalse($store->thread_belongs_to_user($alicethread, (int)$alice->id, $ctxb));

        // Non-existent / zero thread id: rejected.
        $this->assertFalse($store->thread_belongs_to_user(0, (int)$alice->id, $ctxa));
        $this->assertFalse($store->thread_belongs_to_user($alicethread + 99999, (int)$alice->id, $ctxa));
    }
}
