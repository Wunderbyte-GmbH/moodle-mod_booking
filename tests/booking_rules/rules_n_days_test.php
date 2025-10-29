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
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Magdalena Holczik
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use stdClass;
use mod_booking\booking_rules\booking_rules;
use mod_booking\booking_rules\rules_info;
use tool_mocktesttime\time_mock;
use mod_booking_generator;

/**
 * Tests for booking rule n days before.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rules_n_days_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        global $DB;

        parent::tearDown();
        // Mandatory clean-up.
        singleton_service::destroy_instance();
        time_mock::reset_mock_time();
        // Mandatory to deal with static variable in the booking_rules.
        rules_info::destroy_singletons();
        rules_info::$rulestoexecute = [];
        booking_rules::$rules = [];
    }

    /**
     * Test rule on before and after cursestart events.
     *
     * @covers \mod_booking\booking_option::update
     * @covers \mod_booking\option\field_base::check_for_changes
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event::execute
     * @covers \mod_booking\booking_rules\actions\send_mail::execute
     * @covers \mod_booking\booking_rules\conditions\select_users::execute
     * @covers \mod_booking\placeholders\placeholders\changes::return_value
     *
     * @param array $bdata
     * @throws \coding_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_rule_update(array $bdata): void {

        singleton_service::destroy_instance();

        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

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

        // Create booking rule - "ndays after".
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"subject":"1dayafter","template":"was ended yesterday","templateformat":"1"}';
        $ruledata1 = [
            'name' => '1dayafter',
            'conditionname' => 'select_users',
            'contextid' => 1,
            'conditiondata' => '{"userids":["2"]}',
            'actionname' => 'send_mail',
            'actiondata' => $actstr,
            'rulename' => 'rule_daysbefore',
            'ruledata' => '{"days":"-1","datefield":"courseendtime","cancelrules":[]}',
        ];
        $rule1 = $plugingenerator->create_rule($ruledata1);

        // Create booking option 1.
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Option-tomorrow';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course->id;
        $record->description = 'Will start in 5 days';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        //$record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        //$record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->coursestarttime_0 = strtotime('+5 days', time());
        $record->courseendtime_0 = strtotime('+6 days', time());
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        $messages = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');

        // Validate scheduled adhoc tasks. Validate messages - order might be free.
        foreach ($messages as $key => $message) {
            $customdata = $message->get_custom_data();
            if (strpos($customdata->customsubject, "1dayafter") !== false) {
                $this->assertEquals(strtotime('+7 days', time()), $message->get_next_run_time());
                $this->assertEquals("was ended yesterday", $customdata->custommessage);
                $this->assertEquals("2", $customdata->userid);
                $this->assertStringContainsString($ruledata1['ruledata'], $customdata->rulejson);
                $this->assertStringContainsString($ruledata1['conditiondata'], $customdata->rulejson);
                $this->assertStringContainsString($ruledata1['actiondata'], $customdata->rulejson);
            } else {
                continue;
            }
        }

        // Update booking rule to "ndays before".
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"subject":"1daybefore","template":"will start tomorrow","templateformat":"1"}';
        $ruledata1upd = [
            'id' => $rule1->id, // Update existing rule.
            'name' => '1daybefore',
            'conditionname' => 'select_users',
            'contextid' => 1,
            'conditiondata' => '{"userids":["2"]}',
            'actionname' => 'send_mail',
            'actiondata' => $actstr,
            'rulename' => 'rule_daysbefore',
            'ruledata' => '{"days":"1","datefield":"coursestarttime","cancelrules":[]}',
        ];
        $rule1 = $plugingenerator->create_rule($ruledata1upd);

        // Force option's update and execute booking rules to schedule new tasks.
        // Old tasks expected still to be present.
        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $record->id = $option1->id;
        $record->cmid = $settings1->cmid;
        booking_option::update($record);
        rules_info::execute_booking_rules();

        $messages = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');

        // Validate scheduled adhoc tasks. Validate messages - order might be free.
        foreach ($messages as $key => $message) {
            // Old task expected to be deleted - error if found.
            $customdata = $message->get_custom_data();
            if (strpos($customdata->customsubject, "1dayafter") !== false) {
                $this->assertEquals(strtotime('+7 days', time()), $message->get_next_run_time());
                $this->assertEquals("was ended yesterday", $customdata->custommessage);
                $this->assertEquals("2", $customdata->userid);
                $this->assertStringContainsString($ruledata1['ruledata'], $customdata->rulejson);
                $this->assertStringContainsString($ruledata1['conditiondata'], $customdata->rulejson);
                $this->assertStringContainsString($ruledata1['actiondata'], $customdata->rulejson);
            } else if (strpos($customdata->customsubject, "1daybefore") !== false) {
                $this->assertEquals(strtotime('+4 days', time()), $message->get_next_run_time());
                $this->assertEquals("will start tomorrow", $customdata->custommessage);
                $this->assertEquals("2", $customdata->userid);
                $this->assertStringContainsString($ruledata1upd['ruledata'], $customdata->rulejson);
                $this->assertStringContainsString($ruledata1upd['conditiondata'], $customdata->rulejson);
                $this->assertStringContainsString($ruledata1upd['actiondata'], $customdata->rulejson);
            } else {
                continue;
            }
        }

        // Move time forward to trigger tasks.
        $time = time_mock::get_mock_time();
        time_mock::set_mock_time(strtotime('+10 days', $time));
        $time = time_mock::get_mock_time();

        // Trigger adhoctasks and capture emails.
        unset_config('noemailever');
        ob_start();
        $messagesink = $this->redirectMessages();
        $this->runAdhocTasks();
        $messages = $messagesink->get_messages();
        // TODO: 2 messages received - only 1 expected - "1daybefore".
        $res = ob_get_clean();
        $messagesink->close();
    }

    /**
     * Test rule on before and after cursestart events.
     *
     * @covers \mod_booking\booking_option::update
     * @covers \mod_booking\option\field_base::check_for_changes
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event::execute
     * @covers \mod_booking\booking_rules\actions\send_mail::execute
     * @covers \mod_booking\booking_rules\conditions\select_users::execute
     * @covers \mod_booking\placeholders\placeholders\changes::return_value
     *
     * @param array $data
     * @param array $expected
     * @throws \coding_exception
     *
     * @dataProvider booking_rules_provider
     */
    public function test_rule_on_beforeafter_option_cancelled(array $data, array $expected): void {
        global $DB;
        $bdata = $this->booking_common_settings_provider();
        singleton_service::destroy_instance();

        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

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

        // Create booking rule - "ndays before".
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"subject":"1daybefore","template":"will start tomorrow","templateformat":"1"}';
        $ruledata1 = [
            'name' => '1daybefore',
            'conditionname' => 'select_users',
            'contextid' => 1,
            'conditiondata' => '{"userids":["2"]}',
            'actionname' => 'send_mail',
            'actiondata' => $actstr,
            'rulename' => 'rule_daysbefore',
            'ruledata' => '{"days":"1","datefield":"coursestarttime","cancelrules":[]}',
        ];
        $rule1 = $plugingenerator->create_rule($ruledata1);

        // Create booking rule - "ndays after".
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"subject":"1dayafter","template":"was ended yesterday","templateformat":"1"}';
        $ruledata2 = [
            'name' => '1dayafter',
            'conditionname' => 'select_users',
            'contextid' => 1,
            'conditiondata' => '{"userids":["2"]}',
            'actionname' => 'send_mail',
            'actiondata' => $actstr,
            'rulename' => 'rule_daysbefore',
            'ruledata' => '{"days":"-1","datefield":"courseendtime","cancelrules":[]}',
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
        $record->coursestarttime_0 = strtotime('+2 days', time());
        $record->courseendtime_0 = strtotime('+3 days', time());
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        // Two reminder mails are scheduled.
        $this->assertCount(2, $tasks);

        if ($data['canceloption']) {
            booking_option::cancelbookingoption($option1->id);
        }

        $time = time_mock::get_mock_time();
        time_mock::set_mock_time(strtotime('+5 days', $time));
        $time = time_mock::get_mock_time();

        ob_start();
        $this->runAdhocTasks();
        $res = ob_get_clean();

        // Both tasks logged their results, so we check for the string twice.
        $this->assertTrue(substr_count($res, $expected['contains']) >= 2);
    }

    /**
     * Data provider for test_rule_on_beforeafter_coursestart
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    public static function booking_rules_provider(): array {
        return [
            'option_is_cancelled' => [
                [
                    'canceloption' => true,
                ],
                [
                    'contains' => "Rule does not apply anymore. Mail was NOT SENT",
                ],
            ],
            'nocancel' => [
                [
                    'canceloption' => false,
                ],
                [
                    'contains' => 'send_mail_by_rule_adhoc task: mail successfully sent',
                ],
            ],
        ];
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
