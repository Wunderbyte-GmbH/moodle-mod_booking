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
     * Test rule on booking_option_update event.
     *
     * @covers \mod_booking\booking_option::update
     * @covers \mod_booking\option\field_base->check_for_changes
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event->execute
     * @covers \mod_booking\booking_rules\actions\send_mail->execute
     * @covers \mod_booking\placeholders\placeholders\changes->return_value
     * @throws \coding_exception
     */
    public function test_rule_on_booking_option_update() {

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
        $users = [
            ['username' => 'teacher1', 'firstname' => 'Teacher', 'lastname' => '1', 'email' => 'teacher1@example.com'],
            ['username' => 'student1', 'firstname' => 'Student', 'lastname' => '1', 'email' => 'student1@sample.com'],
        ];
        $user1 = $this->getDataGenerator()->create_user($users[0]);
        $user2 = $this->getDataGenerator()->create_user($users[1]);

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
        $record->coursestarttime_1 = strtotime('20 June 2050');
        $record->courseendtime_1 = strtotime('20 July 2050');
        $option = $plugingenerator->create_option($record);

        // Create booking rule.
        $ruledata = [
            'name' => 'emailchanges',
            'conditionname' => 'select_teacher_in_bo',
            'contextid' => 1,
            'conditiondata' => '',
            'actionname' => 'send_mail',
            'actiondata' => '{"subject":"OptionChanged","template":"Changes:{changes}","templateformat":"1"}',
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_updated","condition":"0","aftercompletion":0}',
        ];
        $rule = $plugingenerator->create_rule($ruledata);

        // Trigger and capture emails.
        unset_config('noemailever');
        ob_start();
        $messagesink = $this->redirectMessages();

        // Update booking.
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $record->id = $option->id;
        $record->cmid = $settings->cmid;
        $record->coursestarttime_1 = strtotime('10 April 2055');
        $record->courseendtime_1 = strtotime('10 May 2055');
        $record->description = 'Description updated';
        $record->teachersforoption = [$user1->id];
        booking_option::update($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        $this->runAdhocTasks();

        $messages = $messagesink->get_messages();
        $res = ob_get_clean();
        $messagesink->close();

        // Validate console output.
        $expected = "send_mail_by_rule_adhoc task: mail successfully sent for option " . $option->id . " to user " . $user1->id;
        $this->assertStringContainsString($expected,  $res);

        // Validate emails. Might be more than one dependitg to Moodle's version.
        foreach ($messages as $key => $message) {
            if (strpos($message->subject, "OptionChanged")) {
                // Validate email on option change.
                $this->assertEquals("OptionChanged",  $message->subject);
                $this->assertStringContainsString("Dates has changed",  $message->fullmessage);
                $this->assertStringContainsString("20 June 2050",  $message->fullmessage);
                $this->assertStringContainsString("20 July 2050",  $message->fullmessage);
                $this->assertStringContainsString("10 April 2055",  $message->fullmessage);
                $this->assertStringContainsString("10 May 2055",  $message->fullmessage);
                $this->assertStringContainsString("Teachers has changed",  $message->fullmessage);
                $this->assertStringContainsString("Teacher 1 (ID:",  $message->fullmessage);
                $this->assertStringContainsString("Description has changed",  $message->fullmessage);
                $this->assertStringContainsString("Test description",  $message->fullmessage);
                $this->assertStringContainsString("Description updated",  $message->fullmessage);
            }
        }

        // Mandatory to solve potential cache issues.
        singleton_service::destroy_booking_option_singleton($option->id);
    }

    /**
     * Test rule on before and after cursestart events.
     *
     * @covers \mod_booking\booking_option::update
     * @covers \mod_booking\option\field_base->check_for_changes
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event->execute
     * @covers \mod_booking\booking_rules\actions\send_mail->execute
     * @covers \mod_booking\placeholders\placeholders\changes->return_value
     * @throws \coding_exception
     */
    public function test_rule_on_beforeafter_cursestart() {

        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

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

        // Create booking rule - "ndays before".
        $ruledata = [
            'name' => '1daybefore',
            'conditionname' => 'select_users',
            'contextid' => 1,
            'conditiondata' => '{"userids":["2"]}',
            'actionname' => 'send_mail',
            'actiondata' => '{"subject":"1daybefore","template":"will start tomorrow","templateformat":"1"}',
            'rulename' => 'rule_daysbefore',
            'ruledata' => '{"days":"1","datefield":"coursestarttime"}',
        ];
        $rule1 = $plugingenerator->create_rule($ruledata);

        // Create booking rule - "ndays after".
        $ruledata = [
            'name' => '1dayafter',
            'conditionname' => 'select_users',
            'contextid' => 1,
            'conditiondata' => '{"userids":["2"]}',
            'actionname' => 'send_mail',
            'actiondata' => '{"subject":"1dayafter","template":"was ended yesterday","templateformat":"1"}',
            'rulename' => 'rule_daysbefore',
            'ruledata' => '{"days":"-1","datefield":"courseendtime"}',
        ];
        $rule2 = $plugingenerator->create_rule($ruledata);

        // Trigger and capture emails.
        unset_config('noemailever');
        ob_start();
        $messagesink = $this->redirectMessages();

        // Create booking option 1.
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Option-tomorrow';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course->id;
        $record->description = 'Will start tomorrow';
        $record->optiondateid_1 = "0";
        $record->daystonotify_1 = "0";
        $record->coursestarttime_1 = strtotime('+1440 minutes');
        $record->courseendtime_1 = strtotime('+2 days');
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        // Create booking option 2.
        $record->text = 'Option-yesterday';
        $record->description = 'Ended yesterday';
        $record->coursestarttime_1 = strtotime('-3 days');
        $record->courseendtime_1 = strtotime('-1440 minutes');
        $option2 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option2->id);

        $this->runAdhocTasks();

        $messages = $messagesink->get_messages();
        $res = ob_get_clean();
        $messagesink->close();

        // Validate console output.
        $expected = "send_mail_by_rule_adhoc task: mail successfully sent for option " . $option1->id . " to user 2";
        $this->assertStringContainsString($expected,  $res);
        $expected = "send_mail_by_rule_adhoc task: mail successfully sent for option " . $option2->id . " to user 2";
        $this->assertStringContainsString($expected,  $res);
        // Validate emails.
        $this->assertCount(3, $messages);
        $message = $messages[0];
        $this->assertEquals("1daybefore",  $message->subject);
        $this->assertEquals("bookingconfirmation",  $message->eventtype);
        $this->assertEquals("2",  $message->useridto);
        $this->assertStringContainsString("will start tomorrow",  $message->fullmessage);
        $message = $messages[1];
        $this->assertEquals("1dayafter",  $message->subject);
        $this->assertEquals("bookingconfirmation",  $message->eventtype);
        $this->assertEquals("2",  $message->useridto);
        $this->assertStringContainsString("was ended yesterday",  $message->fullmessage);
        $message = $messages[2];
        $this->assertEquals("1dayafter",  $message->subject);
        $this->assertEquals("bookingconfirmation",  $message->eventtype);
        $this->assertEquals("2",  $message->useridto);
        $this->assertStringContainsString("was ended yesterday",  $message->fullmessage);

        // Mandatory to solve potential cache issues.
        singleton_service::destroy_booking_option_singleton($option1->id);
        singleton_service::destroy_booking_option_singleton($option2->id);
    }
}
