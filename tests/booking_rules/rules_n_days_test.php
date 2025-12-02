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
use local_entities_generator;
use stdClass;
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
     * String that is displayed in the mtask log when mail was send successfully.
     *
     * @var string
     */
    public const MAIL_SUCCES_TRACE = 'send_mail_by_rule_adhoc task: mail successfully sent';
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
        parent::tearDown();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
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
     * @dataProvider rule_update_provider
     */
    public function test_rule_update(array $data, array $expected): void {
        global $DB;

        $bdata = self::booking_common_settings_provider();

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
        $standardtext = '"subject":"1dayafter","template":"was ended yesterday","templateformat":"1"}';
        $actstr .= $standardtext;
        $standardruledata = '{"days":"-1","datefield":"courseendtime","cancelrules":[]}';
        $standardconditiondata = '{"userids":["2"]}';
        $ruledata1 = [
            'conditionname' => 'select_users',
            'name' => 'oldrule',
            'contextid' => 1,
            'conditiondata' => $standardconditiondata,
            'actionname' => 'send_mail',
            'actiondata' => $actstr,
            'rulename' => 'rule_daysbefore',
            'ruledata' => $standardruledata,
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
        $record->coursestarttime_0 = strtotime('+5 days', time());
        $record->courseendtime_0 = strtotime('+6 days', time());
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        $messages = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        $this->assertCount(1, $messages);
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
        $rules = $DB->get_records('booking_rules');
        $this->assertCount(1, $rules);
        rules_info::execute_booking_rules();

        // Update conditiondata if necessary.
        if (isset($data['conditiondata']) && str_contains($data['conditiondata'], 'REPLACE_WITH_USERID')) {
            $data['conditiondata'] = str_replace(
                'REPLACE_WITH_USERID',
                $user1->id,
                $data['conditiondata']
            );
            // Just for safety.
            if (isset($expected['destination']) && str_contains($expected['destination'], 'REPLACE_WITH_USERID')) {
                $expected['destination'] = $user1->id;
            }
        }
        // Update booking rule to "ndays before".
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= $data['text'] ?? $standardtext;
        $ruledata1upd = [
            'id' => $rule1->id, // Update existing rule.
            'name' => 'updatedrule',
            'conditionname' => 'select_users',
            'contextid' => 1,
            'conditiondata' => $data['conditiondata'] ?? $standardconditiondata,
            'actionname' => 'send_mail',
            'actiondata' => $actstr,
            'rulename' => 'rule_daysbefore',
            'ruledata' => $data['ruledata'] ?? $standardruledata,
        ];
        $rule1 = $plugingenerator->create_rule($ruledata1upd);

        $rules = $DB->get_records('booking_rules');
        $this->assertCount(1, $rules);
        // New tasks should be created on booking rule update - without update of option.
        rules_info::execute_booking_rules();

        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        $this->assertCount(2, $tasks);

        $oldtaskcount = 0;
        $newtaskcount = 0;
        // Validate scheduled adhoc tasks. Validate messages - order might be free.
        foreach ($tasks as $key => $message) {
            // Old task expected to be deleted - error if found.
            $customdata = $message->get_custom_data();
            if (strpos($customdata->rulejson, "oldrule") !== false) {
                $this->assertEquals(strtotime('+7 days', time()), $message->get_next_run_time());
                $this->assertEquals("was ended yesterday", $customdata->custommessage);
                $this->assertEquals("2", $customdata->userid);
                $this->assertStringContainsString($ruledata1['ruledata'], $customdata->rulejson);
                $this->assertStringContainsString($ruledata1['conditiondata'], $customdata->rulejson);
                $this->assertStringContainsString($ruledata1['actiondata'], $customdata->rulejson);
                $oldtaskcount++;
            } else if (strpos($customdata->rulejson, "updatedrule") !== false) {
                $this->assertEquals(strtotime($expected['newdate'] ?? '+7 days', time()), $message->get_next_run_time());
                $this->assertEquals($expected['textpart'] ?? "was ended yesterday", $customdata->custommessage);
                $this->assertEquals($expected['destination'] ?? "2", $customdata->userid);
                $newtaskcount++;
            } else {
                continue;
            }
        }
        $this->assertSame(1, $oldtaskcount);
        $this->assertSame(1, $newtaskcount);

        // Trigger adhoctasks and capture emails.
        unset_config('noemailever');
        ob_start();
        $messagesink = $this->redirectMessages();
        $this->runAdhocTasks();
        $messages = $messagesink->get_messages();
        $res = ob_get_clean();
        $messagesink->close();

        // Validate message.
        $this->assertCount(1, $messages);
        if (isset($expected['contains_prevent'])) {
            $this->assertTrue(substr_count($res, $expected['contains_prevent']) == 1);
        }
        if (isset($expected['contains_success'])) {
            $this->assertTrue(substr_count($res, $expected['contains_success']) == 1);
        }
        if (isset($expected['textpart'])) {
            $this->assertTrue(substr_count($messages[0]->fullmessage, $expected['textpart']) == 1);
        }
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
     * Test rule with multiple session dates and n days before reminders.
     * Checks whether messages are sent or skipped correctly
     * when option dates are added, removed or modified.
     *
     * @covers \mod_booking\booking_option::update
     * @covers \mod_booking\booking_rules\rules\rule_daysbefore::execute
     * @covers \mod_booking\booking_rules\actions\send_mail::execute
     * @covers \mod_booking\booking_rules\conditions\select_student_in_bo::execute
     *
     * @dataProvider rule_multiple_dates_provider
     *
     * @param array $updateaction describes the type of change to the option dates
     * @param array $expected expected traces for messages sent vs prevented
     * @throws \coding_exception
     */
    public function test_rule_on_multiple_optiondates_update(array $updateaction, array $expected): void {
        global $DB;

        $this->resetAfterTest(true);
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));

        // Setup course, users, booking instance.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();

        $bdata = self::booking_common_settings_provider()['bdata'][0];
        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $teacher->username;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create rule: 1 day before optiondatestarttime.
        $ruledata = [
            'conditionname' => 'select_users',
            'name' => 'Session reminder',
            'contextid' => 1,
            'conditiondata' => '{"userids":["2"]}',
            'actionname' => 'send_mail',
            'actiondata' => '{"sendical":0,"sendicalcreateorcancel":"",
                "subject":"A new session of {Title} will start soon",
                "template":"<p>Hi {firstname},<br>the next session of \\"{title}\\" will start soon:<br><br>{bookingdetails}</p>",
                "templateformat":"1"}',
            'rulename' => 'rule_daysbefore',
            'ruledata' => '{"days":"1","datefield":"optiondatestarttime"}',
        ];
        $plugingenerator->create_rule($ruledata);

        // Create entities.
        $entitydata1 = [
            'name' => 'Entity1',
            'shortname' => 'entity1',
            'description' => 'Ent1desc',
        ];
        $entitydata2 = [
            'name' => 'Entity2',
            'shortname' => 'entity2',
            'description' => 'Ent2desc',
        ];

        /** @var local_entities_generator *  $egenerator */
        $egenerator = self::getDataGenerator()->get_plugin_generator('local_entities');
        $entityid1 = $egenerator->create_entities($entitydata1);
        $entityid2 = $egenerator->create_entities($entitydata2);

        // Create booking option with two session dates.
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Option-with-two-sessions';
        $record->chooseorcreatecourse = 1; // Required.
        $record->courseid = $course->id;
        $record->description = 'This option has two optiondates';

        // First date (tomorrow).
        $record->optiondateid_0 = 0;
        $record->daystonotify_0 = 0;
        $record->coursestarttime_0 = strtotime('2 June 2050 15:00');
        $record->courseendtime_0 = strtotime('2 June 2050 16:00');

        // Second date (next week).
        $record->optiondateid_1 = 0;
        $record->daystonotify_1 = 0;
        $record->coursestarttime_1 = strtotime('8 June 2050 15:00');
        $record->courseendtime_1 = strtotime('8 June 2050 16:00');

        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        // Initially two tasks are scheduled.
        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        $this->assertCount(2, $tasks);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        // As we don't run through the set_data functions of the form submissions,...
        // ... the id of the exisiting option date needs to be set manually.
        $record->optiondateid_0 = array_keys($settings->sessions)[0];
        $record->optiondateid_1 = array_keys($settings->sessions)[1];

        // Modify booking option based on scenario.
        switch ($updateaction['type']) {
            case 'add_date':
                $record->optiondateid_2 = 0;
                $record->daystonotify_2 = 0;
                $record->coursestarttime_2 = strtotime('6 June 2050 15:00');
                $record->courseendtime_2 = strtotime('6 June 2050 16:00');
                $record->import = 1;
                break;
            case 'add_first_date':
                $record->optiondateid_2 = 0;
                $record->daystonotify_2 = 0;
                $record->coursestarttime_2 = strtotime('1 June 2050 15:00');
                $record->courseendtime_2 = strtotime('1 June 2050 16:00');
                $record->import = 1;
                break;
            case 'remove_date':
                unset($record->optiondateid_1);
                unset($record->coursestarttime_1);
                unset($record->courseendtime_1);
                break;
            case 'modify_date':
                $record->coursestarttime_1 = strtotime('+10 days', time());
                $record->courseendtime_1 = strtotime('+10 days 2 hours', time());
                break;
            case 'modify_location_of_date':
                $record->local_entities_entityarea_1 = "optiondate";
                $record->local_entities_entityid_1 = $entityid2; // Option date entity.
                break;
            default:
                break;
        }
        $record->id = $option->id;
        $record->cmid = $settings->cmid;
        if ($updateaction['type'] !== 'no_change') {
            // Update the option (simulates editing the existing one).
            booking_option::update($record);
            singleton_service::destroy_booking_option_singleton($option->id);
        }

        $dates = $DB->get_records('booking_optiondates', ['optionid' => $option->id]);
        $this->assertCount($expected['numberofdatesafterupdate'], $dates);

        // Execute rules and run adhoc tasks.
        rules_info::execute_booking_rules();

        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        $this->assertCount($expected['numberoftasks'], $tasks, 'wrong number of tasks');

        $time = time_mock::get_mock_time();
        time_mock::set_mock_time(strtotime('30 June 2050 16:00', $time));

        unset_config('noemailever');
        ob_start();
        $messagesink = $this->redirectMessages();
        $plugingenerator->runtaskswithintime(time_mock::get_mock_time());
        $messages = $messagesink->get_messages();
        $trace = ob_get_clean();
        $messagesink->close();

        // Assertions.
        $this->assertCount($expected['messages_sent'], $messages);

        // Check the log contains "mail successfully sent" or "Rule does not apply anymore".
        if (isset($expected['contains_success'])) {
            $this->assertTrue(substr_count($trace, $expected['contains_success']) >= $expected['messages_sent']);
        }
        if (isset($expected['contains_prevent'])) {
            $this->assertTrue(substr_count($trace, $expected['contains_prevent']) >= $expected['messages_prevented']);
        }
    }

    /**
     * Data provider for test_rule_on_multiple_optiondates_update.
     *
     * @return array
     */
    public static function rule_multiple_dates_provider(): array {
        return [
            'no_change' => [
                ['type' => 'no_change'],
                [
                    'messages_sent' => 2,
                    'messages_prevented' => 0,
                    'contains_success' => self::MAIL_SUCCES_TRACE,
                    'numberofdatesafterupdate' => 2,
                    'numberoftasks' => 2,
                ],
            ],
            'add_new_date' => [
                ['type' => 'add_date'],
                [
                    'messages_sent' => 3,
                    'messages_prevented' => 0,
                    'contains_success' => self::MAIL_SUCCES_TRACE,
                    'numberofdatesafterupdate' => 3,
                    'numberoftasks' => 3,
                ],
            ],
            'add_first_date_coursestart' => [
                ['type' => 'add_first_date'],
                [
                    'messages_sent' => 3,
                    'messages_prevented' => 0,
                    'contains_success' => self::MAIL_SUCCES_TRACE,
                    'numberofdatesafterupdate' => 3,
                    'numberoftasks' => 3,
                ],
            ],
            'remove_existing_date' => [
                ['type' => 'remove_date'],
                [
                    'messages_sent' => 1,
                    'messages_prevented' => 1,
                    'contains_success' => self::MAIL_SUCCES_TRACE,
                    'contains_prevent' => 'Rule does not apply anymore. Mail was NOT SENT',
                    'numberofdatesafterupdate' => 1,
                    'numberoftasks' => 2,
                ],
            ],
            'modify_existing_date' => [
                ['type' => 'modify_date'],
                [
                    'messages_sent' => 2,
                    'messages_prevented' => 1,
                    'contains_success' => self::MAIL_SUCCES_TRACE,
                    'contains_prevent' => 'Rule does not apply anymore. Mail was NOT SENT for option',
                    'numberofdatesafterupdate' => 2,
                    'numberoftasks' => 3,
                ],
            ],
            'modify_location_of_date' => [
                ['type' => 'modify_location_of_date'],
                [
                    'messages_sent' => 2,
                    'messages_prevented' => 0,
                    'contains_success' => self::MAIL_SUCCES_TRACE,
                    'contains_prevent' => 'Rule does not apply anymore. Mail was NOT SENT for option',
                    'numberofdatesafterupdate' => 2,
                    'numberoftasks' => 2,
                ],
            ],
        ];
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
                    'contains' => self::MAIL_SUCCES_TRACE,
                ],
            ],
        ];
    }

    /**
     * Data provider for test_rule_update
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    public static function rule_update_provider(): array {
        return [
            'change mailtext' => [
                [
                    'text' => '"subject":"new subject","template":"new text","templateformat":"1"}',
                ],
                [
                    'textpart' => 'new text',
                    'contains_success' => self::MAIL_SUCCES_TRACE,
                ],
            ],
            'change date' => [
                [
                    'text' => '"subject":"1daybefore","template":"will start tomorrow","templateformat":"1"}',
                    'ruledata' => '{"days":"1","datefield":"coursestarttime","cancelrules":[]}',
                ],
                [
                    'newdate' => '+4 days',
                    'textpart' => 'will start tomorrow',
                    'contains_prevent' => "send_mail_by_rule_adhoc task: Rule has changed. Mail was NOT SENT",
                    'contains_success' => self::MAIL_SUCCES_TRACE,
                ],
            ],
            'change destination' => [
                [
                    'conditiondata' => '{"userids":["REPLACE_WITH_USERID"]}',
                ],
                [
                    'destination' => 'REPLACE_WITH_USERID',
                    'contains_success' => self::MAIL_SUCCES_TRACE,
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
