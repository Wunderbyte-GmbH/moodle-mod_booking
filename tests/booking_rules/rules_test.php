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
 * Tests for booking rules.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Andraž Prinčič
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use mod_booking\booking_rules\booking_rules;
use stdClass;

/**
 * Tests for booking rules.
 *
 * @package mod_booking
 * @category test
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rules_test extends advanced_testcase {

    /**
     * Tests set up.
     */
    public function setUp(): void {
        $this->resetAfterTest();
    }

    /**
     * Tear Down.
     *
     * @return void
     *
     */
    public function tearDown(): void {
    }

    /**
     * Test teacher_added event.
     *
     * @covers \mod_booking\event\teacher_added
     * @throws \coding_exception
     */
    public function test_rule_on_teacher_added() {

        $bdata = ['name' => 'Test Booking', 'eventtype' => 'Test event',
                    'bookedtext' => ['text' => 'text'], 'waitingtext' => ['text' => 'text'],
                    'notifyemail' => ['text' => 'text'], 'statuschangetext' => ['text' => 'text'],
                    'deletedtext' => ['text' => 'text'], 'pollurltext' => ['text' => 'text'],
                    'pollurlteacherstext' => ['text' => 'text'],
                    'notificationtext' => ['text' => 'text'], 'userleave' => ['text' => 'text'],
                    'bookingpolicy' => 'bookingpolicy', 'tags' => '',
                    'showviews' => ['showall,showactive,mybooking,myoptions,myinstitution'],
        ];

        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $user2->username;

        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($user1->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($user2->id, $course->id, 'student');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Test option';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course->id;
        $record->description = 'Test description';
        $record->optiondateid_1 = "0";
        $record->daystonotify_1 = "0";
        $record->coursestarttime_1 = strtotime('now + 1 day');
        $record->courseendtime_1 = strtotime('now + 3 day');
        $option = $plugingenerator->create_option($record);

        // Create booking rule.
        $ruledata = [
            'name' => 'emailchanges',
            'conditionname' => 'select_teacher_in_bo',
            'contextid' => 1,
            'conditiondata' => '',
            'actionname' => 'send_mail',
            'actiondata' => '{"subject":"OptionChanged","template":"Changes:","templateformat":"1"}',
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_updated","condition":"0","aftercompletion":0}',
        ];
        $rule = $plugingenerator->create_rule($ruledata);

        // Trigger and capture emails.
        unset_config('noemailever');
        ob_start();
        $messagesink = $this->redirectMessages();

        // Debug - check rule exist.
        $rules = booking_rules::get_list_of_saved_rules_by_context();

        // Update booking.
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $record->id = $option->id;
        $record->cmid = $settings->cmid;
        $record->coursestarttime_1 = strtotime('now + 2 day');
        $record->courseendtime_1 = strtotime('now + 4 day');
        $record->teachersforoption = [$user1->id];
        booking_option::update($record);

        $this->runAdhocTasks();

        $messages = $messagesink->get_messages();
        $res = ob_get_clean();
        $messagesink->close();

//var_dump($messages);

        // Validate email.
        $expected = "send_mail_by_rule_adhoc task: mail successfully sent for option " . $option->id . " to user " . $user1->id;
        $this->assertStringContainsString($expected,  $res);
        $this->assertCount(1, $messages);
        $message = reset($messages);
        $this->assertEquals("OptionChanged",  $message->subject);
        $this->assertStringContainsString("Changes",  $message->fullmessage);
    }
}
