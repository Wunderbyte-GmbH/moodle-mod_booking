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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Permanent architecture contracts for booking AI agent stack.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use mod_booking\local\wbagent\conversation_store;
use mod_booking\local\wbagent\interpreter;
use mod_booking\local\wbagent\message_trigger_registry;
use mod_booking\local\wbagent\task_registry;

/**
 * Contract tests intended to detect unnoticed architecture drift.
 */
final class agent_architecture_contract_test extends advanced_testcase {
    /**
     * Core response types must remain available in interpreter output contract.
     */
    public function test_interpreter_allows_core_response_types_contract(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Contract Booking',
        ]);

        $registry = task_registry::make_default();
        $interpreter = new interpreter($registry);

        $cases = [
            ['response_type' => 'clarification', 'message' => 'clarify'],
            ['response_type' => 'error', 'message' => 'error'],
            ['response_type' => 'confirm_pending', 'message' => ''],
        ];

        foreach ($cases as $case) {
            $result = $interpreter->interpret(json_encode($case), (int)$booking->cmid, 1);
            $this->assertSame($case['response_type'], $result['response_type']);
            $this->assertArrayHasKey('commands', $result);
            $this->assertArrayHasKey('ambiguities', $result);
            $this->assertArrayHasKey('errors', $result);
        }
    }

    /**
     * Task registry must contain the mandatory baseline tasks.
     */
    public function test_task_registry_mandatory_baseline_contract(): void {
        $registry = task_registry::make_default();
        $names = $registry->get_task_names();

        $required = [
            'booking.create_option',
            'booking.update_option',
            'booking.bulk_update_options',
            'booking.search_options',
            'booking.search_users',
            'booking.search_courses',
            'booking.list_actions',
            'booking.list_option_properties',
            'booking.get_current_user',
            'booking.add_price_category',
        ];

        foreach ($required as $taskname) {
            $this->assertContains($taskname, $names, 'Missing mandatory task: ' . $taskname);
        }
    }

    /**
     * Message trigger catalog must include core trigger ids used by guarded flow control.
     */
    public function test_message_trigger_catalog_core_ids_contract(): void {
        $registry = task_registry::make_default();
        $triggerregistry = new message_trigger_registry($registry);
        $triggerids = $triggerregistry->get_available_trigger_ids();

        $required = [
            'core.is_lookup_request',
            'core.is_confirmation_message',
            'core.is_preview_request',
            'core.force_new_duplicate_option',
        ];

        foreach ($required as $triggerid) {
            $this->assertContains($triggerid, $triggerids, 'Missing core trigger id: ' . $triggerid);
        }
    }

    /**
     * Conversation store baseline methods should preserve key lifecycle data.
     */
    public function test_conversation_store_pending_intent_contract(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Pending Contract Booking',
        ]);

        $store = new conversation_store();
        $thread = $store->get_or_create_thread(11, (int)$booking->cmid, (int)$booking->id);
        $threadid = (int)$thread->id;
        $commands = [[
            'task' => 'booking.create_option',
            'version' => 1,
            'input' => ['text' => 'Contract Option'],
        ]];
        $intentkey = hash('sha256', 'contract-intent');

        $store->set_pending_intent($threadid, $commands, $intentkey, 11, (int)$booking->cmid);
        $pending = $store->get_pending_intent($threadid);

        $this->assertIsArray($pending);
        $this->assertSame(11, (int)($pending['userid'] ?? 0));
        $this->assertSame((int)$booking->cmid, (int)($pending['cmid'] ?? 0));
        $this->assertSame('booking.create_option', (string)($pending['commands'][0]['task'] ?? ''));
        $this->assertNotSame('', (string)($pending['confirmationcode'] ?? ''));

        $store->clear_pending_intent($threadid);
        $this->assertNull($store->get_pending_intent($threadid));
    }
}
