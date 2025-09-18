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
use local_shopping_cart\local\cartstore;
use local_shopping_cart\shopping_cart;
use stdClass;
use mod_booking\teachers_handler;
use mod_booking\booking_rules\booking_rules;
use mod_booking\booking_rules\rules_info;
use mod_booking\bo_availability\bo_info;
use mod_booking\bo_availability\conditions\customform;
use mod_booking\local\mobile\customformstore;
use tool_mocktesttime\time_mock;
use mod_booking_generator;

/**
 * Tests for booking rules.
 *
 * @package mod_booking
 * @category test
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @runTestsInSeparateProcesses
 */
final class rules_override_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        global $DB;

        parent::tearDown();
        // Mandatory to solve potential cache issues.
        singleton_service::destroy_instance();
        // Mandatory to deal with static variable in the booking_rules.
        rules_info::destroy_singletons();
        booking_rules::$rules = [];
        cartstore::reset();
    }

    /**
     * Test rule on rule override.
     *
     * @covers \mod_booking\event\bookinganswer_cancelled
     * @covers \mod_booking\event\bookingoption_cancelled
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event::execute
     * @covers \mod_booking\booking_rules\conditions\select_users::execute
     * @covers \mod_booking\booking_rules\conditions\select_teacher_in_bo::execute
     * @covers \mod_booking\booking_rules\actions\send_mail::execute
     *
     * @param array $bdata
     * @throws \coding_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_rule_on_rule_override(array $bdata): void {

        singleton_service::destroy_instance();

        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

        // Allow optioncacellation.
        $bdata['cancancelbook'] = 1;

        // Setup test data.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
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

        // Create booking rule - "bookinganswer_cancelled".
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"subject":"answcancsubj","template":"answcancmsg","templateformat":"1"}';
        $ruledata1 = [
            'name' => 'notifyadmin',
            'conditionname' => 'select_users',
            'contextid' => 1,
            'conditiondata' => '{"userids":["2"]}',
            'actionname' => 'send_mail',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{"boevent":"\\\\mod_booking\\\\event\\\\bookinganswer_cancelled","aftercompletion":"","condition":"0"}',
        ];
        $rule1 = $plugingenerator->create_rule($ruledata1);

        // Create booking rule - "override".
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"subject":"overridesubj","template":"overridemsg","templateformat":"1"}';
        $boevent2 = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_cancelled"';
        $cancelrules2 = '"cancelrules":["' . $rule1->id . '"]';
        $ruledata2 = [
            'name' => 'override',
            'conditionname' => 'select_teacher_in_bo',
            'contextid' => 1,
            'conditiondata' => '',
            'actionname' => 'send_mail',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent2 . ',"aftercompletion":"","condition":"0",' . $cancelrules2 . '}',
        ];
        $rule2 = $plugingenerator->create_rule($ruledata2);

        // Create booking option 1.
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Option-tomorrow';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course->id;
        $record->description = 'Will start tomorrow';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $user1->username;
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        // Create a booking option answer.
        $result = $plugingenerator->create_answer(['optionid' => $option1->id, 'userid' => $user2->id]);
        $this->assertSame(MOD_BOOKING_BO_COND_ALREADYBOOKED, $result);
        singleton_service::destroy_booking_answers($option1->id);

        // Cancel entire booking option.
        booking_option::cancelbookingoption($option1->id);

        // Get messages.
        $messages = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');

        // Validate scheduled adhoc tasks. Order might be free. Override does not applied yet, 2 messages scheduled.
        foreach ($messages as $key => $message) {
            $customdata = $message->get_custom_data();
            if (strpos($customdata->customsubject, "overridesubj") !== false) {
                $this->assertSame("overridemsg", $customdata->custommessage);
                $this->assertSame($user1->id, $customdata->userid);
                $this->assertStringContainsString($boevent2, $customdata->rulejson);
                $this->assertStringContainsString($cancelrules2, $customdata->rulejson);
                $this->assertStringContainsString($ruledata2['conditiondata'], $customdata->rulejson);
                $this->assertStringContainsString($ruledata2['actiondata'], $customdata->rulejson);
            } else if (strpos($customdata->customsubject, "answcancsubj") !== false) {
                $this->assertSame("answcancmsg", $customdata->custommessage);
                $this->assertSame(2, $customdata->userid);
                $this->assertStringContainsString($ruledata1['conditiondata'], $customdata->rulejson);
                $this->assertStringContainsString($ruledata1['actiondata'], $customdata->rulejson);
            } else {
                continue;
            }
        }

        // Override working only upon execution of adhoc task.
        unset_config('noemailever');
        ob_start();
        $messagesink = $this->redirectMessages();

        $this->runAdhocTasks();

        $sentmessages = $messagesink->get_messages();
        $res = ob_get_clean();
        $messagesink->close();

        // Validate override.
        foreach ($sentmessages as $key => $sentmessage) {
            if (strpos($sentmessage->subject, "overridesubj") !== false) {
                $this->assertSame("overridemsg", $sentmessage->fullmessage);
                $this->assertSame($user2->id, $sentmessage->useridfrom);
                $this->assertSame($user1->id, $sentmessage->useridto);
            } else {
                $this->assertStringNotContainsString(
                    "answcancmsg",
                    $sentmessage->fullmessage,
                    'Error: no overriding, answcancmsg message found'
                );
            }
        }
    }

    /**
     * Data provider for condition_bookingpolicy_test
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    public static function booking_common_settings_provider(): array {
        $bdata = [
            'name' => 'Rule Booking Test',
            'eventtype' => 'Test rules',
            'enablecompletion' => 1,
            'bookedtext' => ['text' => 'text'],
            'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'],
            'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'],
            'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'],
            'notificationtext' => ['text' => 'text'],
            'userleave' => ['text' => 'text'],
            'tags' => '',
            'completion' => 2,
            'showviews' => ['mybooking,myoptions,optionsiamresponsiblefor,showall,showactive,myinstitution'],
        ];
        return ['bdata' => [$bdata]];
    }
}
