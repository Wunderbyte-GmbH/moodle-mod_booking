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

namespace bookingextension_todolist;

use advanced_testcase;
use bookingextension_todolist\local\todolist_helper;
use bookingextension_todolist\placeholders\todolist as todolist_placeholder;
use mod_booking\singleton_service;
use mod_booking_generator;
use stdClass;

/**
 * Tests for todo list helper and placeholder behavior.
 *
 * @package     bookingextension_todolist
 * @category    test
 * @covers      \bookingextension_todolist\local\todolist_helper
 * @covers      \bookingextension_todolist\placeholders\todolist
 * @copyright   2026 Wunderbyte GmbH
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class todolist_helper_test extends advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    public function tearDown(): void {
        singleton_service::destroy_instance();
        parent::tearDown();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }

    public function test_parse_lines_trims_and_skips_empty_lines(): void {
        $parsed = todolist_helper::parse_lines("  First\n\n  Second  \r\n\t\nThird");
        $this->assertSame(['First', 'Second', 'Third'], $parsed);
    }

    public function test_texts_from_records_returns_trimmed_texts(): void {
        $records = [
            (object)['text' => '  A  '],
            (object)['text' => 'B'],
            (object)['text' => " C\n"],
        ];
        $this->assertSame(['A', 'B', 'C'], todolist_helper::texts_from_records($records));
    }

    public function test_todolist_completed_false_when_no_items(): void {
        $optionid = $this->create_booking_option();
        $this->assertFalse(todolist_helper::todolist_completed($optionid));
    }

    public function test_has_completed_items_false_when_none_checked(): void {
        $optionid = $this->create_booking_option();
        $this->seed_items($optionid, ['Task A', 'Task B']);
        $this->assertFalse(todolist_helper::has_completed_items($optionid));
    }

    public function test_has_completed_items_true_when_one_checked(): void {
        global $DB;

        $optionid = $this->create_booking_option();
        $this->seed_items($optionid, ['Task A', 'Task B']);

        $items = array_values(todolist_helper::get_items_for_option($optionid));
        $items[0]->status = 1;
        $DB->update_record('bookingextension_todolist_item', $items[0]);

        $this->assertTrue(todolist_helper::has_completed_items($optionid));
    }

    public function test_replace_items_stores_items_in_order(): void {
        $optionid = $this->create_booking_option();
        $this->seed_items($optionid, ['Task 1', 'Task 2', 'Task 3']);

        $records = array_values(todolist_helper::get_items_for_option($optionid));
        $this->assertCount(3, $records);
        $this->assertSame('Task 1', trim((string)$records[0]->text));
        $this->assertSame('Task 2', trim((string)$records[1]->text));
        $this->assertSame('Task 3', trim((string)$records[2]->text));
        $this->assertSame(0, (int)$records[0]->sortorder);
        $this->assertSame(1, (int)$records[1]->sortorder);
        $this->assertSame(2, (int)$records[2]->sortorder);
    }

    public function test_replace_items_preserves_status_for_unchanged_rows(): void {
        global $DB;

        $optionid = $this->create_booking_option();
        $this->seed_items($optionid, ['Task 1', 'Task 2']);

        $items = array_values(todolist_helper::get_items_for_option($optionid));
        $items[0]->status = 1;
        $items[0]->completed_by = 123;
        $items[0]->completed_at = time();
        $DB->update_record('bookingextension_todolist_item', $items[0]);

        $this->seed_items($optionid, ['Task 1', 'Task 2']);

        $after = array_values(todolist_helper::get_items_for_option($optionid));
        $this->assertSame(1, (int)$after[0]->status);
        $this->assertSame(123, (int)$after[0]->completed_by);
        $this->assertNotEmpty($after[0]->completed_at);
    }

    public function test_replace_items_resets_status_when_text_changes(): void {
        global $DB;

        $optionid = $this->create_booking_option();
        $this->seed_items($optionid, ['Task 1']);

        $items = array_values(todolist_helper::get_items_for_option($optionid));
        $items[0]->status = 1;
        $items[0]->completed_by = 123;
        $items[0]->completed_at = time();
        $DB->update_record('bookingextension_todolist_item', $items[0]);

        $this->seed_items($optionid, ['Task 1 changed']);

        $after = array_values(todolist_helper::get_items_for_option($optionid));
        $this->assertSame('Task 1 changed', trim((string)$after[0]->text));
        $this->assertSame(0, (int)$after[0]->status);
        $this->assertNull($after[0]->completed_by);
        $this->assertNull($after[0]->completed_at);
    }

    public function test_refresh_option_todolist_status_sets_zero_for_incomplete_list(): void {
        $optionid = $this->create_booking_option();
        $this->seed_items($optionid, ['Task A', 'Task B']);
        todolist_helper::refresh_option_todolist_status($optionid);

        $this->assertFalse(todolist_helper::todolist_completed($optionid));
    }

    public function test_refresh_option_todolist_status_sets_one_for_completed_list(): void {
        global $DB;

        $optionid = $this->create_booking_option();
        $this->seed_items($optionid, ['Task A', 'Task B']);
        $items = array_values(todolist_helper::get_items_for_option($optionid));

        foreach ($items as $item) {
            $item->status = 1;
            $DB->update_record('bookingextension_todolist_item', $item);
        }

        todolist_helper::refresh_option_todolist_status($optionid);

        $this->assertTrue(todolist_helper::todolist_completed($optionid));
    }

    public function test_get_template_context_contains_item_payload(): void {
        $optionid = $this->create_booking_option();
        $this->seed_items($optionid, ['Task A']);

        $context = todolist_helper::get_template_context($optionid, true, 123);

        $this->assertSame(123, $context['cmid']);
        $this->assertSame($optionid, $context['optionid']);
        $this->assertTrue($context['cancheck']);
        $this->assertTrue($context['hasitems']);
        $this->assertCount(1, $context['items']);
        $this->assertArrayHasKey('optionid', $context['items'][0]);
        $this->assertSame($optionid, $context['items'][0]['optionid']);
    }

    public function test_format_as_plaintext_renders_checked_and_unchecked_prefixes(): void {
        global $DB;

        $optionid = $this->create_booking_option();
        $this->seed_items($optionid, ['Open task', 'Done task']);
        $items = array_values(todolist_helper::get_items_for_option($optionid));
        $items[1]->status = 1;
        $DB->update_record('bookingextension_todolist_item', $items[1]);

        $text = todolist_helper::format_as_plaintext($optionid);

        $this->assertStringContainsString('[ ] Open task', $text);
        $this->assertStringContainsString('[x] Done task', $text);
    }

    public function test_placeholder_return_value_resolves_direct_optionid(): void {
        $optionid = $this->create_booking_option();
        $this->seed_items($optionid, ['Task A']);

        $text = '';
        $params = [];
        $value = todolist_placeholder::return_value(0, $optionid, 0, 0, 0, 0.0, $text, $params);

        $this->assertSame('[ ] Task A', $value);
    }

    public function test_placeholder_return_value_resolves_optionid_from_rulejson(): void {
        $optionid = $this->create_booking_option();
        $this->seed_items($optionid, ['Task A']);

        $rulejson = json_encode((object)[
            'datafromevent' => (object)[
                'other' => (object)['optionid' => $optionid],
            ],
        ]);

        $text = '';
        $params = [];
        $value = todolist_placeholder::return_value(0, 0, 0, 0, 0, 0.0, $text, $params, MOD_BOOKING_DESCRIPTION_WEBSITE, $rulejson);

        $this->assertSame('[ ] Task A', $value);
    }

    public function test_placeholder_return_value_resolves_optionid_from_itemid_fallback(): void {
        $optionid = $this->create_booking_option();
        $this->seed_items($optionid, ['Task A']);
        $items = array_values(todolist_helper::get_items_for_option($optionid));
        $itemid = (int)$items[0]->id;

        $text = '';
        $params = [];
        $value = todolist_placeholder::return_value(0, $itemid, 0, 0, 0, 0.0, $text, $params);

        $this->assertSame('[ ] Task A', $value);
    }

    public function test_is_enabled_for_option_reads_flag_from_option_json(): void {
        $optionid = $this->create_booking_option();

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $settings->json = json_encode((object)['enable_todolist' => 1]);
        $this->assertTrue(todolist_helper::is_enabled_for_option($optionid));

        $settings->json = json_encode((object)['enable_todolist' => 0]);
        $this->assertFalse(todolist_helper::is_enabled_for_option($optionid));
    }

    /**
     * Seed items for one booking option.
     *
     * @param int $optionid
     * @param array $items
     * @return void
     */
    private function seed_items(int $optionid, array $items): void {
        global $USER;
        todolist_helper::replace_items($optionid, $items, (int)$USER->id);
    }

    /**
     * Create a booking option for todolist tests.
     *
     * @return int
     */
    private function create_booking_option(): int {
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Booking for todolist tests',
        ]);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $record = new stdClass();
        $record->bookingid = (int)$booking->id;
        $record->text = 'Option for todolist tests';
        $record->description = 'Option description';
        $record->courseid = (int)$course->id;
        $record->chooseorcreatecourse = 1;
        $record->optiondateid_0 = 0;
        $record->daystonotify_0 = 0;
        $record->coursestarttime_0 = time() + 3600;
        $record->courseendtime_0 = time() + 7200;

        $option = $plugingenerator->create_option($record);
        return (int)$option->id;
    }
}
