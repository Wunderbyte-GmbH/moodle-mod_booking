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
use bookingextension_agent\local\wizard\services\user_memory_service;

/**
 * Tests for user_memory_service (limits, dedupe, delete ownership, find).
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\user_memory_service
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class user_memory_service_test extends advanced_testcase {
    /**
     * A valid memory is normalized, stored and reported back.
     */
    public function test_add_stores_normalized_memory(): void {
        $this->resetAfterTest();
        $service = new user_memory_service();
        $userid = (int)$this->getDataGenerator()->create_user()->id;

        $result = $service->add($userid, "  I prefer   morning   bookings  ");

        $this->assertSame('ok', $result['status']);
        $this->assertIsInt($result['id']);
        $all = $service->get_all($userid);
        $this->assertCount(1, $all);
        $this->assertSame('I prefer morning bookings', $all[0]->memory);
    }

    /**
     * Empty / whitespace-only memories are rejected.
     */
    public function test_add_rejects_empty(): void {
        $this->resetAfterTest();
        $service = new user_memory_service();
        $userid = (int)$this->getDataGenerator()->create_user()->id;

        $this->assertSame('empty', $service->add($userid, "    ")['status']);
        $this->assertCount(0, $service->get_all($userid));
    }

    /**
     * A single memory longer than the per-memory limit is rejected.
     */
    public function test_add_rejects_too_long(): void {
        $this->resetAfterTest();
        $service = new user_memory_service();
        $userid = (int)$this->getDataGenerator()->create_user()->id;

        $long = str_repeat('a', user_memory_service::MAX_CHARS_PER_MEMORY + 1);
        $this->assertSame('too_long', $service->add($userid, $long)['status']);
        $this->assertCount(0, $service->get_all($userid));
    }

    /**
     * Case-insensitive duplicates are not stored twice.
     */
    public function test_add_dedupes_case_insensitive(): void {
        $this->resetAfterTest();
        $service = new user_memory_service();
        $userid = (int)$this->getDataGenerator()->create_user()->id;

        $this->assertSame('ok', $service->add($userid, 'My employee id is 12345')['status']);
        $this->assertSame('duplicate', $service->add($userid, '  my EMPLOYEE id is 12345 ')['status']);
        $this->assertCount(1, $service->get_all($userid));
    }

    /**
     * The per-user count limit is enforced.
     */
    public function test_add_enforces_count_limit(): void {
        $this->resetAfterTest();
        $service = new user_memory_service();
        $userid = (int)$this->getDataGenerator()->create_user()->id;

        for ($i = 0; $i < user_memory_service::MAX_MEMORIES; $i++) {
            $this->assertSame('ok', $service->add($userid, 'memory number ' . $i)['status']);
        }
        $this->assertSame('limit_count', $service->add($userid, 'one too many')['status']);
        $this->assertCount(user_memory_service::MAX_MEMORIES, $service->get_all($userid));
    }

    /**
     * The per-user total character budget is enforced.
     */
    public function test_add_enforces_total_char_limit(): void {
        $this->resetAfterTest();
        $service = new user_memory_service();
        $userid = (int)$this->getDataGenerator()->create_user()->id;

        // Each ~400 chars; a handful stays under the count limit but crosses the total budget.
        $chunk = str_repeat('x', 400);
        $statuses = [];
        for ($i = 0; $i < 12; $i++) {
            $statuses[] = $service->add($userid, $chunk . ' ' . $i)['status'];
        }

        $this->assertContains('limit_total', $statuses);
        $total = 0;
        foreach ($service->get_all($userid) as $record) {
            $total += \core_text::strlen($record->memory);
        }
        $this->assertLessThanOrEqual(user_memory_service::MAX_TOTAL_CHARS, $total);
    }

    /**
     * Delete is ownership-checked: only the owner can remove a record.
     */
    public function test_delete_is_ownership_checked(): void {
        $this->resetAfterTest();
        $service = new user_memory_service();
        $owner = (int)$this->getDataGenerator()->create_user()->id;
        $other = (int)$this->getDataGenerator()->create_user()->id;

        $id = (int)$service->add($owner, 'owned memory')['id'];

        $this->assertFalse($service->delete($other, $id));
        $this->assertCount(1, $service->get_all($owner));

        $this->assertTrue($service->delete($owner, $id));
        $this->assertCount(0, $service->get_all($owner));
    }

    /**
     * Scopes are normalized (unknown dropped, canonical order) and stored.
     */
    public function test_add_stores_normalized_scopes(): void {
        $this->resetAfterTest();
        $service = new user_memory_service();
        $userid = (int)$this->getDataGenerator()->create_user()->id;

        $service->add($userid, 'Address me as Dr X', ['synchronization', 'bogus', 'SELECTION']);
        $record = $service->get_all($userid)[0];

        // Unknown "bogus" dropped; kept in canonical order (selection before synchronization).
        $this->assertSame(
            [user_memory_service::SCOPE_SELECTION, user_memory_service::SCOPE_SYNCHRONIZATION],
            user_memory_service::parse_scopes($record->scopes)
        );
    }

    /**
     * get_for_scope returns scoped memories plus untagged (all-channel) ones.
     */
    public function test_get_for_scope_filters_by_channel(): void {
        $this->resetAfterTest();
        $service = new user_memory_service();
        $userid = (int)$this->getDataGenerator()->create_user()->id;

        $service->add($userid, 'sync only', [user_memory_service::SCOPE_SYNCHRONIZATION]);
        $service->add($userid, 'construction only', [user_memory_service::SCOPE_CONSTRUCTION]);
        $service->add($userid, 'everywhere'); // Empty scopes = all channels.

        $selection = array_map(static fn($r) => $r->memory, $service->get_for_scope($userid, user_memory_service::SCOPE_SELECTION));
        $this->assertEqualsCanonicalizing(['everywhere'], $selection);

        $construction = array_map(
            static fn($r) => $r->memory,
            $service->get_for_scope($userid, user_memory_service::SCOPE_CONSTRUCTION)
        );
        $this->assertEqualsCanonicalizing(['construction only', 'everywhere'], $construction);

        $sync = array_map(
            static fn($r) => $r->memory,
            $service->get_for_scope($userid, user_memory_service::SCOPE_SYNCHRONIZATION)
        );
        $this->assertEqualsCanonicalizing(['sync only', 'everywhere'], $sync);
    }

    /**
     * find() returns case-insensitive substring matches only for the owner.
     */
    public function test_find_matches_substring_for_owner(): void {
        $this->resetAfterTest();
        $service = new user_memory_service();
        $owner = (int)$this->getDataGenerator()->create_user()->id;
        $other = (int)$this->getDataGenerator()->create_user()->id;

        $service->add($owner, 'I prefer morning bookings');
        $service->add($owner, 'My room is B12');
        $service->add($other, 'I prefer morning bookings');

        $matches = $service->find($owner, 'MORNING');
        $this->assertCount(1, $matches);
        $this->assertStringContainsString('morning', reset($matches)->memory);

        $this->assertCount(0, $service->find($owner, 'nonexistent'));
        $this->assertCount(0, $service->find($owner, '   '));
    }
}
