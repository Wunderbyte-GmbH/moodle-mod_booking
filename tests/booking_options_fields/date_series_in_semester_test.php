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
 * Tests for booking option field class teachers.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2025 Magdalena Holczik
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use coding_exception;
use mod_booking\booking_rules\rules\rule_daysbefore;
use mod_booking\booking_rules\rules_info;
use mod_booking\option\fields_info;
use mod_booking_generator;
use stdClass;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once("$CFG->dirroot/mod/booking/lib.php");
require_once("$CFG->dirroot/mod/booking/classes/price.php");

/**
 * Tests for booking option field class teachers.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2025 Bernhard Fischer-Sengseis
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class date_series_in_semester_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
    }

    /**
     * Test creation and update of recurring options.
     *
     * @covers \mod_booking\option\fields\optiondates
     * @covers \mod_booking\option\dates_handler
     *
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @param array $data
     * @param array $expected
     *
     * @return void
     *
     * @dataProvider provide_test_data
     */
    public function test_create_date_series($data, $expected): void {
        global $DB;
        $bdata = self::provide_bdata();

        // Course is needed for module generator.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $teacher1 = $this->getDataGenerator()->create_user();
        $teacher2 = $this->getDataGenerator()->create_user();
        $teacher3 = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $semester = (object) [
            'identifier' => 'ws2526',
            'name' => 'Semester WS 25/26',
            'startdate' => '1756504800',
            'enddate' => '1772406000',
        ];
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $testsemester = $plugingenerator->create_semester($semester);
        // Create an initial booking option.
        // The option has 2 optiondates and 1 teacher.
        $record = new stdClass();
        $record->importing = 1;
        $record->bookingid = $booking->id;
        $record->text = 'Testoption';
        $record->description = 'Test description';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course->id;
        $record->useprice = 0;
        $record->default = 0;
        $record->dayofweektime = $data['dayofweektime'];
        $record->semesterid = $testsemester->id;

        // Create the booking option.
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);

        // Now we change the teacher and add another optiondate.
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        if (isset($expected['nosessions']) && $expected['nosessions']) {
            $this->assertEmpty($settings->sessions);
        } else {
            $this->assertNotEmpty($settings->sessions);
            $this->assertCount($expected['sessionscount'], $settings->sessions);
            $this->assertSame($expected['dayofweek'], $settings->dayofweek);
        }
        // To avoid retrieving the singleton with the wrong settings, we destroy it.
        singleton_service::destroy_booking_singleton_by_cmid($settings->cmid);

        // TearDown at the very end.
        self::tearDown();
    }

    /**
     * Test creation and update of recurring options.
     *
     * @covers \mod_booking\option\fields\optiondates
     * @covers \mod_booking\option\dates_handler
     *
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @param array $data
     * @param array $expected
     *
     * @return void
     *
     * @dataProvider provide_test_data_for_reminders
     */
    public function test_send_reminders_for_sessions($data, $expected): void {
        global $DB;
        $bdata = self::provide_bdata();

        singleton_service::destroy_instance();

        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

        // Course is needed for module generator.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $teacher1 = $this->getDataGenerator()->create_user();
        $teacher2 = $this->getDataGenerator()->create_user();
        $teacher3 = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $semester = (object) [
            'identifier' => 'monatsemester',
            'name' => '2 Monate Semester',
            // Take some fixed dates in the future to make sure, number of weeks and bank holidays remains the same.
            'startdate' => 2529702000,
            'enddate' => 2535228000,
        ];
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $testsemester = $plugingenerator->create_semester($semester);

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
            'ruledata' => '{"days":"1","datefield":"optiondatestarttime"}',
        ];
        $rule1 = $plugingenerator->create_rule($ruledata1);
        // Create an initial booking option.
        // The option has 2 optiondates and 1 teacher.
        $record = new stdClass();
        $record->importing = 1;
        $record->bookingid = $booking->id;
        $record->text = 'Testoption';
        $record->description = 'Test description';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course->id;
        $record->useprice = 0;
        $record->default = 0;
        $record->dayofweektime = $data['dayofweektime'];
        $record->semesterid = $testsemester->id;

        // Create the booking option.
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);

        // Now we change the teacher and add another optiondate.
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        $this->assertNotEmpty($settings->sessions);
        $this->assertCount($expected['sessionscount'], $settings->sessions);

        // For each session there is a task.
        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        $this->assertCount($expected['sessionscount'], $tasks);

        // Update the sessions, create new entries.
        if (isset($data['extrasessionupdate'])) {
            $record->dayofweektime = $data['extrasessionupdate'];
            $record->id = $option->id;
            booking_option::update($record);

            $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
            $this->assertCount($expected['sessionscountafterupdate'], $tasks);
        }

        if (isset($data['ruleupdate'])) {
            // This is actually not an update but we create a new rule and deactivate the old one.
            $newactiondata = str_replace('1daybefore', 'newtitle', $ruledata1['actiondata']);
            $ruledata1['actiondata'] = $newactiondata;
            $ruledata1['name'] = 'newrule';
            $ruledata1['id'] = $rule1->id;
            $updatedrule = $plugingenerator->create_rule($ruledata1);
            rules_info::execute_booking_rules();
            $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
            // Only one rule that was updated.
            $rules = $DB->get_records('booking_rules');
            $this->assertCount(1, $rules);
            // After the update of the rule, we expect to the tasks to be doubled.
            $this->assertCount($expected['sessionscount'] * 2, $tasks);
            $newruletasks = [];
            foreach ($tasks as $task) {
                $taskdata = $task->get_custom_data();
                if (str_contains($taskdata->rulejson, '"name":"newrule"')) {
                    $newruletasks[] = $task;
                }
            }
            $this->assertSame($expected['mailssent'], count($newruletasks));
        }
        ob_start();
        $messagesink = $this->redirectMessages();
        $this->runAdhocTasks();
        $sentmessages = $messagesink->get_messages();
        $res = ob_get_clean();
        $this->assertNotEmpty($sentmessages);

        // Unfortunately in unit tests, nextruntime of task is ignored and all tasks are executed right away.
        // So we expect a successful mail for each session / task.
        $expectedmails = $expected['mailssent'];
        $this->assertSame($expectedmails, count($sentmessages));
        if (isset($expected['abortmessages'])) {
            $abortmessages = substr_count($res, 'Mail was NOT SENT');
            $this->assertSame($expected['abortmessages'], $abortmessages);
        }

        // To avoid retrieving the singleton with the wrong settings, we destroy it.
        singleton_service::destroy_booking_singleton_by_cmid($settings->cmid);

        // TearDown at the very end.
        self::tearDown();
    }

    /**
     * Provides the data that's constant for the test.
     *
     * @return array
     *
     */
    private static function provide_bdata(): array {
        return [
            'name' => 'Test Booking Policy 1',
            'eventtype' => 'Test event',
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
            'showviews' => ['mybooking,myoptions,showall,showactive,myinstitution'],
        ];
    }

    /**
     * [Description for provide_test_data]
     *
     * @return array
     *
     */
    public static function provide_test_data(): array {
        return [
            '4 days' => [
                'data' => [
                    'dayofweektime' =>
                            "Monday, 10:00 - 12:00
                            Tuesday, 15:00 - 16:30
                            Wednesday, 13:00 - 14:00
                            Thursday, 11 - 12",
                ],
                'expected' => [
                    'sessionscount' => 105,
                    'dayofweek' => "monday,tuesday,wednesday,thursday",
                ],
            ],
            '1 day' => [
                'data' => [
                    'dayofweektime' =>
                            "Mo, 10:00 - 12:00",
                ],
                'expected' => [
                    'sessionscount' => 27,
                    'dayofweek' => "monday",
                ],
            ],
            '2 days short hours string' => [
                'data' => [
                    'dayofweektime' =>
                            "Monday, 10:00 - 12:00
                            Tuesday, 10 - 12",
                ],
                'expected' => [
                    'sessionscount' => 53,
                    'dayofweek' => "monday,tuesday",
                ],
            ],
            '2 days string with additional values' => [
                'data' => [
                    'dayofweektime' =>
                            "Monday, 10:00 - 12:00 (Block)
                            Tuesday, 10 - 12",
                ],
                'expected' => [
                    'nosessions' => true,
                ],
            ],
        ];
    }

    /**
     * [Description for provide_test_data]
     *
     * @return array
     *
     */
    public static function provide_test_data_for_reminders(): array {
        return [
            'send mail to admin standard' => [
                'data' => [
                    'dayofweektime' =>
                            "Monday, 10:00 - 12:00",
                ],
                'expected' => [
                    'sessionscount' => 9,
                    'mailssent' => 9,
                ],
            ],
            'send mail to admin new extra session' => [
                'data' => [
                    'dayofweektime' =>
                            "Monday, 10:00 - 12:00",
                    'extrasessionupdate' =>
                            "Monday, 10:00 - 12:00
                            Tuesday, 10 - 12",
                ],
                'expected' => [
                    'sessionscount' => 9,
                    'sessionscountafterupdate' => 19,
                    'abortmessages' => 0,
                    'mailssent' => 19,
                ],
            ],
            'send mail to admin after update of rule' => [
                'data' => [
                    'dayofweektime' =>
                            "Monday, 10:00 - 12:00",
                    'ruleupdate' => true,
                ],
                'expected' => [
                    'sessionscount' => 9,
                    'sessionscountafterupdate' => 19,
                    'abortmessages' => 9,
                    'mailssent' => 9,
                ],
            ],
        ];
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        // Mandatory clean-up.
        singleton_service::destroy_instance();
    }
}
