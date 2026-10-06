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
 * Tests for the bookingextension_agent Privacy API provider.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\privacy\provider;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;

/**
 * Verifies the provider declares all personal-data tables + the external LLM transmission and
 * correctly enumerates, exports and deletes the AI conversation data and the user memory.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\privacy\provider
 */
final class privacy_provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * get_metadata declares every personal-data table and the external LLM link.
     */
    public function test_get_metadata_declares_all_tables_and_external_link(): void {
        $collection = provider::get_metadata(new collection('bookingextension_agent'));
        $names = [];
        foreach ($collection->get_collection() as $item) {
            $names[] = $item->get_name();
        }

        $this->assertContains('bx_agent_user_memory', $names);
        $this->assertContains('bx_agent_ai_threads', $names);
        $this->assertContains('bx_agent_ai_messages', $names);
        $this->assertContains('bx_agent_ai_runs', $names);
        $this->assertContains('bx_agent_ai_llm_debug', $names);
        // The external LLM provider transmission must be declared.
        $this->assertContains('llm_provider', $names);
    }

    /**
     * A user's AI conversation context and their user (memory) context are both discovered,
     * exported and then fully deleted.
     */
    public function test_contexts_export_and_delete_for_user(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $coursectx = \context_course::instance($course->id);
        $userctx = \context_user::instance($user->id);

        // Seed AI conversation data in the course context for $user.
        $store = new conversation_store();
        $thread = $store->get_or_create_thread((int)$user->id, (int)$coursectx->id);
        $threadid = (int)$thread->id;
        $store->add_message($threadid, 'user', 'Please enrol me into the course.');
        $store->add_message($threadid, 'assistant', 'Done.');
        $store->create_run($threadid, (int)$user->id, (int)$coursectx->id, 'idem-key-1', [['skill' => 'noop']]);
        $store->add_llm_debug_entry($threadid, (int)$user->id, (int)$coursectx->id, 'orchestrator', 'PROMPT', 'RESPONSE', 1);

        // Seed AI data for a different user in the same context (must survive a per-user delete).
        $otherthread = (int)$store->get_or_create_thread((int)$other->id, (int)$coursectx->id)->id;
        $store->add_message($otherthread, 'user', 'Other user message.');

        // Seed user-stated memory at the user context.
        $DB->insert_record('bx_agent_user_memory', (object)[
            'userid' => (int)$user->id,
            'memory' => 'Call me Alex.',
            'scopes' => 'synchronization',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        // The get_contexts_for_userid call returns both the course context and the user context.
        // Normalise to int: contextlist stores the raw DB ctxid (a string under mysqli) and assertContains
        // compares strictly.
        $contextids = array_map('intval', provider::get_contexts_for_userid((int)$user->id)->get_contextids());
        $this->assertContains((int)$coursectx->id, $contextids);
        $this->assertContains((int)$userctx->id, $contextids);

        // Export writes data for both contexts.
        $this->export_context_data_for_user((int)$user->id, $coursectx, 'bookingextension_agent');
        $this->assertTrue(writer::with_context($coursectx)->has_any_data());
        $this->export_context_data_for_user((int)$user->id, $userctx, 'bookingextension_agent');
        $this->assertTrue(writer::with_context($userctx)->has_any_data());

        // Delete all data for $user in both contexts.
        $approved = new approved_contextlist($user, 'bookingextension_agent', [$coursectx->id, $userctx->id]);
        provider::delete_data_for_user($approved);

        // The user's rows are gone...
        $this->assertFalse($DB->record_exists('bx_agent_ai_threads', ['userid' => $user->id]));
        $this->assertFalse($DB->record_exists('bx_agent_ai_messages', ['threadid' => $threadid]));
        $this->assertFalse($DB->record_exists('bx_agent_ai_runs', ['userid' => $user->id]));
        $this->assertFalse($DB->record_exists('bx_agent_ai_llm_debug', ['userid' => $user->id]));
        $this->assertFalse($DB->record_exists('bx_agent_user_memory', ['userid' => $user->id]));

        // ...but the other user's data in the same context is untouched.
        $this->assertTrue($DB->record_exists('bx_agent_ai_threads', ['userid' => $other->id]));
        $this->assertTrue($DB->record_exists('bx_agent_ai_messages', ['threadid' => $otherthread]));
    }

    /**
     * get_users_in_context finds conversation owners, and a context-wide delete clears everyone.
     */
    public function test_users_in_context_and_delete_all(): void {
        global $DB;
        $this->resetAfterTest();

        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $coursectx = \context_course::instance($course->id);

        $store = new conversation_store();
        foreach ([$user1, $user2] as $u) {
            $tid = (int)$store->get_or_create_thread((int)$u->id, (int)$coursectx->id)->id;
            $store->add_message($tid, 'user', 'Hello from ' . $u->id);
        }

        $userlist = new \core_privacy\local\request\userlist($coursectx, 'bookingextension_agent');
        provider::get_users_in_context($userlist);
        $found = $userlist->get_userids();
        $this->assertContains((int)$user1->id, $found);
        $this->assertContains((int)$user2->id, $found);

        provider::delete_data_for_all_users_in_context($coursectx);
        $this->assertFalse($DB->record_exists('bx_agent_ai_threads', ['contextid' => $coursectx->id]));
        $this->assertEquals(0, $DB->count_records('bx_agent_ai_messages'));
    }
}
