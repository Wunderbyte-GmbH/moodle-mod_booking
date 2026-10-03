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
use bookingextension_todolist\external\toggle_todolist_item;
use bookingextension_todolist\local\todolist_helper;
use mod_booking\singleton_service;
use mod_booking_generator;
use stdClass;

/**
 * Tests for external toggle endpoint.
 *
 * @package     bookingextension_todolist
 * @category    test
 * @covers      \bookingextension_todolist\external\toggle_todolist_item
 * @covers      \bookingextension_todolist\todolist
 * @runTestsInSeparateProcesses
 * @copyright   2026 Wunderbyte GmbH
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class toggle_todolist_item_external_test extends advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->preventResetByRollback();
        $this->resetAfterTest();
        // The extension is off by default; without it the option form stores no todo list items.
        set_config('enableglobally', 1, 'bookingextension_todolist');
    }

    public function tearDown(): void {
        singleton_service::destroy_instance();
        parent::tearDown();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }

    public function test_execute_checks_item_and_returns_success_notification(): void {
        global $DB;

        [$teacher, $optionid] = $this->create_teachercancheck_setup(['Task A']);
        $itemid = $this->get_todolist_item_id($optionid, 'Task A');

        $this->setUser($teacher);
        $this->set_valid_sesskey();
        $sink = $this->redirectEvents();

        $result = toggle_todolist_item::execute($itemid, $optionid, true);

        $events = $sink->get_events();
        $sink->close();

        $record = $DB->get_record('bookingextension_todolist_item', ['id' => $itemid], '*', MUST_EXIST);
        $this->assertSame(1, (int)$record->status);
        $this->assertSame('success', $result['notificationtype']);
        $this->assertNotEmpty($result['notification']);
        $this->assertGreaterThanOrEqual(1, count($events));
    }

    public function test_execute_unchecks_item_and_returns_info_notification(): void {
        global $DB;

        [$teacher, $optionid] = $this->create_teachercancheck_setup(['Task A']);
        $itemid = $this->get_todolist_item_id($optionid, 'Task A');
        $this->set_todolist_item_status($itemid, 1);

        $this->setUser($teacher);
        $this->set_valid_sesskey();

        $result = toggle_todolist_item::execute($itemid, $optionid, false);

        $record = $DB->get_record('bookingextension_todolist_item', ['id' => $itemid], '*', MUST_EXIST);
        $this->assertSame(0, (int)$record->status);
        $this->assertNull($record->completed_by);
        $this->assertNull($record->completed_at);
        $this->assertSame('info', $result['notificationtype']);
        $this->assertNotEmpty($result['notification']);
    }

    public function test_execute_on_last_item_triggers_completed_history_entry(): void {
        global $DB;

        [$teacher, $optionid, $bookingid] = $this->create_teachercancheck_setup(['Task A', 'Task B']);
        $firstitemid = $this->get_todolist_item_id($optionid, 'Task A');
        $seconditemid = $this->get_todolist_item_id($optionid, 'Task B');
        $this->set_todolist_item_status($firstitemid, 1);

        $this->setUser($teacher);
        $this->set_valid_sesskey();

        $result = toggle_todolist_item::execute($seconditemid, $optionid, true);

        $this->assertSame('success', $result['notificationtype']);
        $this->assertStringContainsString('completed', strtolower($result['notification']));
        $this->assertTrue(todolist_helper::todolist_completed($optionid));

        $history = $DB->get_records('booking_history', ['bookingid' => $bookingid, 'optionid' => $optionid], 'id ASC');
        $this->assertGreaterThanOrEqual(2, count($history));

        $jsons = array_map(static fn($row) => (string)$row->json, $history);
        $this->assertTrue((bool)array_filter($jsons, static fn($json) => strpos($json, 'toggle_todolist_item') !== false));
        $this->assertTrue((bool)array_filter($jsons, static fn($json) => strpos($json, 'todolist_completed') !== false));

        // Keep first item touched to avoid dead code style warnings in strict tools.
        $this->assertGreaterThan(0, $firstitemid);
    }

    public function test_history_description_uses_real_item_text(): void {
        [$teacher, $optionid, , $cmid] = $this->create_teachercancheck_setup(['prepare classroom']);
        $itemid = $this->get_todolist_item_id($optionid, 'prepare classroom');

        $values = (object)[
            'cmid' => $cmid,
            'optionid' => $optionid,
            'usermodified' => $teacher->id,
        ];

        $description = todolist::get_booking_history_description($values, [
            'component' => 'bookingextension_todolist',
            'action' => 'toggle_todolist_item',
            'itemid' => $itemid,
            'checked' => 1,
        ]);

        $this->assertStringContainsString('prepare classroom', $description);
        $this->assertStringNotContainsString('#' . $itemid, $description);
        $this->assertStringContainsString('was completed', $description);

        $uncheckeddescription = todolist::get_booking_history_description($values, [
            'component' => 'bookingextension_todolist',
            'action' => 'toggle_todolist_item',
            'itemid' => $itemid,
            'checked' => 0,
        ]);

        $this->assertStringContainsString('prepare classroom', $uncheckeddescription);
        $this->assertStringContainsString('was uncompleted', $uncheckeddescription);
    }

    public function test_execute_throws_for_user_without_capability(): void {
        [$teacher, $optionid, $bookingid, $cmid, $student] = $this->create_teachercancheck_setup_with_student(['Task A']);
        $itemid = $this->get_todolist_item_id($optionid, 'Task A');

        $this->setUser($student);
        $this->set_valid_sesskey();

        $this->expectException(\required_capability_exception::class);
        toggle_todolist_item::execute($itemid, $optionid, true);
    }

    /**
     * Create a basic teacher-can-check setup.
     *
     * @param array $todolistitems
     * @return array
     */
    private function create_teachercancheck_setup(array $todolistitems = ['Task A', 'Task B']): array {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $this->setAdminUser();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Booking for external tests',
        ]);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $record = new stdClass();
        $record->bookingid = (int)$booking->id;
        $record->text = 'Option for external tests';
        $record->description = 'Option description';
        $record->courseid = (int)$course->id;
        $record->chooseorcreatecourse = 1;
        $record->optiondateid_0 = 0;
        $record->daystonotify_0 = 0;
        $record->coursestarttime_0 = time() + 3600;
        $record->courseendtime_0 = time() + 7200;
        $option = $plugingenerator->create_option($record);

        $record->id = (int)$option->id;
        $record->cmid = (int)$option->cmid;
        $record->enable_todolist = 1;
        $record->todolist_items = implode(PHP_EOL, $todolistitems);

        $boinstance = singleton_service::get_instance_of_booking_option($option->cmid, $option->id);
        $boinstance::update($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        $cm = get_coursemodule_from_instance('booking', (int)$booking->id, (int)$course->id, false, MUST_EXIST);
        return [$teacher, (int)$option->id, (int)$booking->id, (int)$cm->id];
    }

    /**
     * Create a teacher-can-check setup including a student without permission.
     *
     * @param array $todolistitems
     * @return array
     */
    private function create_teachercancheck_setup_with_student(array $todolistitems = ['Task A', 'Task B']): array {
        [$teacher, $optionid, $bookingid, $cmid] = $this->create_teachercancheck_setup($todolistitems);
        $courseid = (int)get_coursemodule_from_id('booking', $cmid)->course;
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $courseid, 'student');
        return [$teacher, $optionid, $bookingid, $cmid, $student];
    }

    /**
     * Return a todolist item id for an option by text.
     *
     * @param int $optionid
     * @param string $text
     * @return int
     */
    private function get_todolist_item_id(int $optionid, string $text): int {
        $items = todolist_helper::get_items_for_option($optionid);
        foreach ($items as $item) {
            if ((string)$item->text === $text) {
                return (int)$item->id;
            }
        }
        $this->fail('Failed to find todolist item with text: ' . $text);
        return 0;
    }

    /**
     * Set todolist completion state for an existing item.
     *
     * @param int $itemid
     * @param int $status
     * @return void
     */
    private function set_todolist_item_status(int $itemid, int $status): void {
        global $DB;

        $record = (object)[
            'id' => $itemid,
            'status' => $status,
            'completed_by' => $status ? 2 : null,
            'completed_at' => $status ? time() : null,
        ];
        $DB->update_record('bookingextension_todolist_item', $record);
    }

    /**
     * Populate request globals with the active session key.
     *
     * @return void
     */
    private function set_valid_sesskey(): void {
        $_POST['sesskey'] = sesskey();
        $_GET['sesskey'] = sesskey();
    }
}
