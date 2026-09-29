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
use mod_booking\booking_rules\rules_info;
use mod_booking\singleton_service;
use mod_booking_generator;
use stdClass;
use tool_mocktesttime\time_mock;

/**
 * Tests for todolist-specific booking rule behavior.
 *
 * @package     bookingextension_todolist
 * @category    test
 * @covers      \bookingextension_todolist\rules\rules\rule_daysbefore_todolist_not_completed
 * @covers      \bookingextension_todolist\local\todolist_helper
 * @copyright   2026 Wunderbyte GmbH
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rule_daysbefore_todolist_not_completed_test extends advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
    }

    public function tearDown(): void {
        singleton_service::destroy_instance();
        parent::tearDown();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }

    /**
     * Check rule sends mail only while todolist is incomplete.
     *
     * @param bool $markcompleted
     * @param int $expectedmessages
     * @return void
     *
     * @dataProvider rule_filters_by_todolist_completion_provider
     */
    public function test_rule_filters_by_todolist_completion(bool $markcompleted, int $expectedmessages): void {
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        [$optionid, $recipientid] = $this->create_option_with_todolist(['Task A', 'Task B']);

        if ($markcompleted) {
            $this->set_all_todolist_items_completed($optionid);
        }

        $this->create_incomplete_todolist_rule($recipientid);
        rules_info::execute_booking_rules();

        $time = time_mock::get_mock_time();
        time_mock::set_mock_time(strtotime('+15 days', $time));
        $time = time_mock::get_mock_time();

        unset_config('noemailever');
        $messagesink = $this->redirectMessages();

        ob_start();
        $plugingenerator->runtaskswithintime($time);
        ob_get_clean();

        $messages = $messagesink->get_messages();
        $messagesink->close();

        $this->assertCount($expectedmessages, $messages);

        if ($expectedmessages > 0) {
            $this->assertSame((int)$recipientid, (int)$messages[0]->useridto);
            $this->assertStringContainsString('todo reminder text', (string)$messages[0]->fullmessage);
        }
    }

    /**
     * Data provider for todolist-completion filtering.
     *
     * @return array
     */
    public static function rule_filters_by_todolist_completion_provider(): array {
        return [
            'incomplete_todolist_queues_task' => [false, 1],
            'completed_todolist_skips_task' => [true, 0],
        ];
    }

    /**
     * Create a booking option with enabled todolist items.
     *
     * @param array $items
     * @return array
     */
    private function create_option_with_todolist(array $items): array {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $recipient = $this->getDataGenerator()->create_user();

        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($recipient->id, $course->id, 'student');

        $this->setAdminUser();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Booking for todolist rules tests',
            'bookingmanager' => $teacher->username,
        ]);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $record = new stdClass();
        $record->bookingid = (int)$booking->id;
        $record->text = 'Option for todolist rule tests';
        $record->description = 'Option description';
        $record->courseid = (int)$course->id;
        $record->chooseorcreatecourse = 1;
        $record->optiondateid_0 = 0;
        $record->daystonotify_0 = 0;
        $record->coursestarttime_0 = time() + 86400 * 4;
        $record->courseendtime_0 = time() + 86400 * 5;

        $option = $plugingenerator->create_option($record);

        $record->id = (int)$option->id;
        $record->cmid = (int)$option->cmid;
        $record->enable_todolist = 1;
        $record->todolist_items = implode(PHP_EOL, $items);

        $boinstance = singleton_service::get_instance_of_booking_option($option->cmid, $option->id);
        $boinstance::update($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        return [(int)$option->id, (int)$recipient->id];
    }

    /**
     * Mark all todolist items for an option as completed.
     *
     * @param int $optionid
     * @return void
     */
    private function set_all_todolist_items_completed(int $optionid): void {
        global $DB;

        $items = $DB->get_records('bookingextension_todolist_item', ['optionid' => $optionid]);
        foreach ($items as $item) {
            $item->status = 1;
            $item->completed_by = 2;
            $item->completed_at = time();
            $DB->update_record('bookingextension_todolist_item', $item);
        }
    }

    /**
     * Create the todolist-specific n-days-before rule.
     *
     * @param int $recipientid
     * @return void
     */
    private function create_incomplete_todolist_rule(int $recipientid): void {
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $ruledata = [
            'name' => 'todo-incomplete-rule',
            'contextid' => 1,
            'conditionname' => 'select_users',
            'conditiondata' => '{"userids":["' . $recipientid . '"]}',
            'actionname' => 'send_mail',
            'actiondata' => '{"sendical":0,"sendicalcreateorcancel":"","subject":"todo reminder",' .
                '"template":"todo reminder text","templateformat":"1"}',
            'rulename' => 'rule_daysbefore_todolist_not_completed',
            'ruledata' => '{"seconds":"86400","datefield":"coursestarttime","cancelrules":[]}',
        ];

        $plugingenerator->create_rule($ruledata);
    }
}
