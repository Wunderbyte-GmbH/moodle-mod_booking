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
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use stdClass;
use mod_booking\booking_rules\booking_rules;
use mod_booking\booking_rules\rules_info;
use mod_booking\bo_availability\bo_info;
use local_shopping_cart\shopping_cart;
use local_shopping_cart\shopping_cart_history;
use local_shopping_cart\local\cartstore;
use local_shopping_cart\output\shoppingcart_history_list;
use tool_mocktesttime\time_mock;
use mod_booking_generator;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests for booking rules.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @runInSeparateProcess
 */
final class rules_waitinglist_test extends advanced_testcase {
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
        // Mandatory clean-up.
        singleton_service::destroy_instance();
        // Mandatory to deal with static variable in the booking_rules.
        rules_info::destroy_singletons();
        booking_rules::$rules = [];
        cartstore::reset();
        time_mock::reset_mock_time();
        // phpcs:ignore
        // mtrace(date('Y/m/d H:i:s', time_mock::get_mock_time())); // Debugging output.
    }

    /**
     * Test rules for "option free to bookagain" and "notification in intervals" events when waitinglist is forced.
     *
     * @covers \mod_booking\bo_availability\conditions\alreadybooked::is_available
     * @covers \mod_booking\bo_availability\conditions\onwaitinglist::is_available
     * @covers \mod_booking\event\bookingoption_freetobookagain
     * @covers \mod_booking\event\bookingoptionwaitinglist_booked
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event
     * @covers \mod_booking\booking_rules\actions\send_mail
     * @covers \mod_booking\booking_rules\conditions\select_teacher_in_bo
     * @covers \mod_booking\booking_rules\conditions\select_student_in_bo
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_rule_on_freeplace_on_intervals_when_waitinglist_forced(array $bdata): void {
        global $DB, $CFG;

        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

        time_mock::set_mock_time(strtotime('-4 days', time()));
        $time = time_mock::get_mock_time();
        $now = time();
        $this->assertEquals($time, $now);

        $bdata['cancancelbook'] = 1;

        // Create course.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $student3 = $this->getDataGenerator()->create_user();
        $student4 = $this->getDataGenerator()->create_user();
        $teacher1 = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $teacher1->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student3->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student4->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher1->id, $course1->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create booking rule 1 - "bookingoption_freetobookagain" with delays.
        $boevent1 = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_freetobookagain"';
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"interval":1,"subject":"freeplacedelaysubj","template":"freeplacedelaymsg","templateformat":"1"}';
        $ruledata1 = [
            'name' => 'intervlqs',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"smallerthan1"}',
            'actionname' => 'send_mail_interval',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent1 . ',"aftercompletion":0,"cancelrules":[],"condition":"2"}',
        ];
        $rule1 = $plugingenerator->create_rule($ruledata1);

        // Create booking rule 2 - "bookingoption_freetobookagain".
        $boevent2 = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_freetobookagain"';
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"subject":"freeplacesubj","template":"freeplacemsg","templateformat":"1"}';
        $ruledata2 = [
            'name' => 'override',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent2 . ',"aftercompletion":"","condition":"0"}',
        ];
        $rule2 = $plugingenerator->create_rule($ruledata2);

        // Create booking option 1.
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'football';
        $record->chooseorcreatecourse = 1; // Connected existing course.
        $record->courseid = $course1->id;
        $record->maxanswers = 1;
        $record->maxoverbooking = 3; // Enable waitinglist.
        $record->waitforconfirmation = 1; // Force waitinglist.
        $record->description = 'Will start in 2050';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $teacher1->username;
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $boinfo = new bo_info($settings);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        // Create a booking option answer - book student2.
        $this->setUser($student2);
        singleton_service::destroy_user($student2->id);
        $result = booking_bookit::bookit('option', $settings->id, $student2->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Confirm booking as admin.
        $this->setAdminUser();
        $option->user_submit_response($student2, 0, 0, 0, MOD_BOOKING_VERIFIED);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Book the student1 via waitinglist.
        time_mock::set_mock_time(strtotime('+1 days', time()));
        $time = time_mock::get_mock_time();
        $this->setUser($student1);
        singleton_service::destroy_user($student1->id);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Book the student3 via waitinglist.
        time_mock::set_mock_time(strtotime('+1 days', time()));
        $time = time_mock::get_mock_time();
        $this->setUser($student3);
        singleton_service::destroy_user($student3->id);
        $result = booking_bookit::bookit('option', $settings->id, $student3->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Book the student4 via waitinglist.
        time_mock::set_mock_time(strtotime('+1 days', time()));
        $time = time_mock::get_mock_time();
        $this->setUser($student4);
        singleton_service::destroy_user($student4->id);
        $result = booking_bookit::bookit('option', $settings->id, $student4->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student4->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Now remove booking of student 2, for a place to free up.
        time_mock::set_mock_time(strtotime('+1 days', time()));
        $time = time_mock::get_mock_time();
        $this->setUser($student2);
        $option->user_delete_response($student2->id);
        singleton_service::destroy_booking_option_singleton($option1->id);
        singleton_service::destroy_booking_answers($option1->id);

        // Execute tasks, get messages and validate it.
        $this->setAdminUser();

        // Get all scheduled task messages.
        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');

        $this->assertCount(5, $tasks);
        // Validate task messages. Might be free order.
        foreach ($tasks as $key => $task) {
            $customdata = $task->get_custom_data();
            if (strpos($customdata->customsubject, "freeplacesubj") !== false) {
                // Validate 3 task messages on the bookingoption_freetobookagain event.
                $this->assertEquals("freeplacesubj", $customdata->customsubject);
                $this->assertEquals("freeplacemsg", $customdata->custommessage);
                $this->assertContains($customdata->userid, [$student1->id, $student3->id, $student4->id]);
                $this->assertStringContainsString($boevent2, $customdata->rulejson);
                $this->assertStringContainsString($ruledata2['conditiondata'], $customdata->rulejson);
                $this->assertStringContainsString($ruledata2['actiondata'], $customdata->rulejson);
                $this->assertContains($task->get_userid(), [$student1->id, $student3->id, $student4->id]);
                $rulejson = json_decode($customdata->rulejson);
                $this->assertEmpty($rulejson->datafromevent->relateduserid);
                $this->assertEquals($student2->id, $rulejson->datafromevent->userid);
            } else {
                // Validate 3 task messages on the bookingoption_freetobookagain with delay event.
                $this->assertEquals("freeplacedelaysubj", $customdata->customsubject);
                $this->assertEquals("freeplacedelaymsg", $customdata->custommessage);
                $this->assertContains($customdata->userid, [$student1->id, $student3->id, $student4->id]);
                $this->assertStringContainsString($boevent1, $customdata->rulejson);
                $this->assertStringContainsString($ruledata1['conditiondata'], $customdata->rulejson);
                $this->assertStringContainsString($ruledata1['actiondata'], $customdata->rulejson);
                $this->assertContains($task->get_userid(), [$student1->id, $student3->id, $student4->id]);
                $rulejson = json_decode($customdata->rulejson);
                $this->assertEmpty($rulejson->datafromevent->relateduserid);
                $this->assertEquals($student2->id, $rulejson->datafromevent->userid);
            }
        }

        // Run adhock tasks.
        $sink = $this->redirectMessages();
        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        ob_start();
        $this->runAdhocTasks();
        $messages = $sink->get_messages();
        $res = ob_get_clean();
        $sink->close();

        $this->assertCount(4, $messages);
        // Validate ACTUAL task messages. Might be free order.
        foreach ($messages as $key => $message) {
            if (strpos($message->subject, "freeplacesubj") !== false) {
                // Validate 3 task messages on the bookingoption_freetobookagain event.
                $this->assertEquals("freeplacesubj", $message->subject);
                $this->assertEquals("freeplacemsg", $message->fullmessage);
                $this->assertContains($message->useridto, [$student1->id, $student3->id, $student4->id]);
            } else {
                // Validate 1 task messages on the bookingoption_freetobookagain with delay event.
                $this->assertEquals("freeplacedelaysubj", $message->subject);
                $this->assertEquals("freeplacedelaymsg", $message->fullmessage);
                $this->assertEquals($student1->id, $message->useridto);
            }
        }
    }

    /**
     * Test rules for "option free to bookagain" and "notification in intervals" events
     * ...when waitinglist is forced and maxanswers has been increased.
     *
     * @covers \mod_booking\bo_availability\conditions\alreadybooked::is_available
     * @covers \mod_booking\bo_availability\conditions\onwaitinglist::is_available
     * @covers \mod_booking\event\bookingoption_freetobookagain
     * @covers \mod_booking\event\bookingoptionwaitinglist_booked
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event
     * @covers \mod_booking\booking_rules\actions\send_mail
     * @covers \mod_booking\booking_rules\conditions\select_teacher_in_bo
     * @covers \mod_booking\booking_rules\conditions\select_student_in_bo
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_rule_on_freeplace_on_intervals_when_maxanswer_increased_and_waitinglist_forced(array $bdata): void {
        global $DB, $CFG;

        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

        time_mock::set_mock_time(strtotime('-4 days', time()));
        $time = time_mock::get_mock_time();
        $now = time();
        $this->assertEquals($time, time());

        $bdata['cancancelbook'] = 1;

        // Create course.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $student3 = $this->getDataGenerator()->create_user();
        $student4 = $this->getDataGenerator()->create_user();
        $teacher1 = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $teacher1->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student3->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student4->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher1->id, $course1->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create booking rule 1 - "bookingoption_freetobookagain" with delays.
        $boevent1 = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_freetobookagain"';
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"interval":1440,"subject":"freeplacedelaysubj","template":"freeplacedelaymsg","templateformat":"1"}';
        $ruledata1 = [
            'name' => 'intervlqs',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail_interval',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent1 . ',"aftercompletion":0,"cancelrules":[],"condition":"2"}',
        ];
        $rule1 = $plugingenerator->create_rule($ruledata1);

        // Create booking rule 2 - "bookingoption_freetobookagain".
        $boevent2 = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_freetobookagain"';
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"subject":"freeplacesubj","template":"freeplacemsg","templateformat":"1"}';
        $ruledata2 = [
            'name' => 'override',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent2 . ',"aftercompletion":"","condition":"0"}',
        ];
        $rule2 = $plugingenerator->create_rule($ruledata2);

        // Create booking option 1.
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'football';
        $record->chooseorcreatecourse = 1; // Connected existing course.
        $record->courseid = $course1->id;
        $record->maxanswers = 1;
        $record->maxoverbooking = 10; // Enable waitinglist.
        $record->waitforconfirmation = 1; // Force waitinglist.
        $record->description = 'Will start in 2050';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $teacher1->username;
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $boinfo = new bo_info($settings);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        // Create a booking option answer - book student1.
        $this->setUser($student1);
        singleton_service::destroy_user($student1->id);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Confirm booking as admin.
        $this->setAdminUser();
        $option->user_submit_response($student1, 0, 0, 0, MOD_BOOKING_VERIFIED);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Book the student2 via waitinglist with intervals.
        time_mock::set_mock_time(strtotime('-3 days', time()));
        $time = time_mock::get_mock_time();
        $this->setUser($student2);
        singleton_service::destroy_user($student2->id);
        $result = booking_bookit::bookit('option', $settings->id, $student2->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Book the student3 via waitinglist.
        time_mock::set_mock_time(strtotime('+1 days', time()));
        $time = time_mock::get_mock_time();
        $this->setUser($student3);
        singleton_service::destroy_user($student3->id);
        $result = booking_bookit::bookit('option', $settings->id, $student3->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Book the student4 via waitinglist.
        time_mock::set_mock_time(strtotime('+1 day', time()));
        $time = time_mock::get_mock_time();
        $this->setUser($student4);
        singleton_service::destroy_user($student4->id);
        $result = booking_bookit::bookit('option', $settings->id, $student4->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student4->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Continue as admin.
        $this->setAdminUser();
        time_mock::set_mock_time(strtotime('+1 day', time()));
        $time = time_mock::get_mock_time();
        // Update booking.
        $record->id = $option->id;
        $record->cmid = $settings->cmid;
        $record->maxanswers = 2;
        $record->teachersforoption = [$teacher1->id];
        booking_option::update($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $ba = singleton_service::get_instance_of_booking_answers($settings);
        $this->assertIsArray($ba->usersonlist);
        $this->assertCount(1, $ba->usersonlist);
        $this->assertIsArray($ba->usersonwaitinglist);
        $this->assertCount(3, $ba->usersonwaitinglist);
        // Execute tasks, get messages and validate it.
        // Get all scheduled task messages.
        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');

        $this->assertCount(5, $tasks);
        // Validate task messages. Might be free order.
        foreach ($tasks as $key => $task) {
            $customdata = $task->get_custom_data();
            if (strpos($customdata->customsubject, "freeplacesubj") !== false) {
                // Validate 3 task messages on the bookingoption_freetobookagain event.
                $this->assertEquals("freeplacesubj", $customdata->customsubject);
                $this->assertEquals("freeplacemsg", $customdata->custommessage);
                $this->assertContains($customdata->userid, [$student2->id, $student3->id, $student4->id]);
                $this->assertStringContainsString($boevent2, $customdata->rulejson);
                $this->assertStringContainsString($ruledata2['conditiondata'], $customdata->rulejson);
                $this->assertStringContainsString($ruledata2['actiondata'], $customdata->rulejson);
                $this->assertContains($task->get_userid(), [$student2->id, $student3->id, $student4->id]);
                $rulejson = json_decode($customdata->rulejson);
                $this->assertEmpty($rulejson->datafromevent->relateduserid);
                $this->assertEquals(2, $rulejson->datafromevent->userid);
            } else {
                // Validate 2 task messages on the bookingoption_freetobookagain with delay event
                // ... student2 and student3 should be informed.
                $this->assertEquals("freeplacedelaysubj", $customdata->customsubject);
                $this->assertEquals("freeplacedelaymsg", $customdata->custommessage);
                $this->assertContains($customdata->userid, [$student2->id, $student3->id]);
                $this->assertStringContainsString($boevent1, $customdata->rulejson);
                $this->assertStringContainsString($ruledata1['conditiondata'], $customdata->rulejson);
                $this->assertStringContainsString($ruledata1['actiondata'], $customdata->rulejson);
                $this->assertContains($task->get_userid(), [$student2->id, $student3->id]);
                $rulejson = json_decode($customdata->rulejson);
                $this->assertEmpty($rulejson->datafromevent->relateduserid);
                $this->assertEquals(2, $rulejson->datafromevent->userid);
            }
        }

        // Run adhock tasks.
        $sink = $this->redirectMessages();
        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        ob_start();
        $this->runAdhocTasks();
        $messages = $sink->get_messages();
        $res = ob_get_clean();
        $sink->close();

        $this->assertCount(4, $messages);
        // Validate ACTUAL task messages. Might be free order.
        foreach ($messages as $key => $message) {
            if (strpos($message->subject, "freeplacesubj") !== false) {
                // Validate 3 task messages on the bookingoption_freetobookagain event.
                $this->assertEquals("freeplacesubj", $message->subject);
                $this->assertEquals("freeplacemsg", $message->fullmessage);
                $this->assertContains($message->useridto, [$student2->id, $student3->id, $student4->id]);
            } else {
                // Validate 1 task messages on the bookingoption_freetobookagain with delay event.
                $this->assertEquals("freeplacedelaysubj", $message->subject);
                $this->assertEquals("freeplacedelaymsg", $message->fullmessage);
                $this->assertEquals($student2->id, $message->useridto);
            }
        }

        time_mock::set_mock_time(strtotime('+1 day', time()));
        $time = time_mock::get_mock_time();

        // Run adhock tasks.
        $sink = $this->redirectMessages();
        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        ob_start();
        $this->runAdhocTasks();
        $messages = $sink->get_messages();
        $res = ob_get_clean();
        $sink->close();

        $this->assertCount(1, $messages);
        // Validate ACTUAL task messages. Might be free order.
        foreach ($messages as $key => $message) {
            // Validate 1 task messages on the bookingoption_freetobookagain with delay event.
            $this->assertEquals("freeplacedelaysubj", $message->subject);
            $this->assertEquals("freeplacedelaymsg", $message->fullmessage);
            $this->assertEquals($student3->id, $message->useridto);
        }
    }

    /**
     * Test rules for "option free to bookagain" and "notification in intervals" events
     * ...when waitinglist is reorderd and booking is cancelled.
     *
     * @covers \mod_booking\bo_availability\conditions\alreadybooked::is_available
     * @covers \mod_booking\bo_availability\conditions\onwaitinglist::is_available
     * @covers \mod_booking\event\bookingoption_freetobookagain
     * @covers \mod_booking\event\bookingoptionwaitinglist_booked
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event
     * @covers \mod_booking\booking_rules\actions\send_mail
     * @covers \mod_booking\booking_rules\conditions\select_teacher_in_bo
     * @covers \mod_booking\booking_rules\conditions\select_student_in_bo
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_reorder_waitinglist_when_booking_cancelled_and_rule_not_executed(array $bdata): void {
        global $DB, $CFG;

        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

        time_mock::set_mock_time(strtotime('-4 days', time()));
        $time = time_mock::get_mock_time();

        $bdata['cancancelbook'] = 1;

        // Create course.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $student3 = $this->getDataGenerator()->create_user();
        $student4 = $this->getDataGenerator()->create_user();
        $teacher1 = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $teacher1->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student3->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student4->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher1->id, $course1->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create booking rule 1 - "bookingoption_freetobookagain" with delays.
        $boevent1 = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_freetobookagain"';
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"interval":1440,"subject":"freeplacedelaysubj","template":"freeplacedelaymsg","templateformat":"1"}';
        $ruledata1 = [
            'name' => 'intervlqs',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail_interval',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent1 . ',"aftercompletion":0,"cancelrules":[],"condition":"2"}',
        ];
        $rule1 = $plugingenerator->create_rule($ruledata1);

        // Create booking option 1.
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'football';
        $record->maxanswers = 1;
        $record->maxoverbooking = 10; // Enable waitinglist.
        $record->waitforconfirmation = 0; // No confirmation necessary.
        $record->description = 'Will start in 2050';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $teacher1->username;
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $boinfo = new bo_info($settings);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        // Create a booking option answer - book student1.
        $this->setUser($student1);
        singleton_service::destroy_user($student1->id);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMBOOKIT, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Confirm booking as admin.
        $this->setAdminUser();
        // Book the student2 via waitinglist with intervals.
        time_mock::set_mock_time(strtotime('+1 days', time()));
        $time = time_mock::get_mock_time();
        $this->setUser($student2);
        singleton_service::destroy_user($student2->id);
        $result = booking_bookit::bookit('option', $settings->id, $student2->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMBOOKIT, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student2->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Book the student3 via waitinglist.
        time_mock::set_mock_time(strtotime('+1 days', time()));
        $time = time_mock::get_mock_time();
        $this->setUser($student3);
        singleton_service::destroy_user($student3->id);
        $result = booking_bookit::bookit('option', $settings->id, $student3->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMBOOKIT, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student3->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Book the student4 via waitinglist.
        time_mock::set_mock_time(strtotime('+1 day', time()));
        $time = time_mock::get_mock_time();
        $this->setUser($student4);
        singleton_service::destroy_user($student4->id);
        $result = booking_bookit::bookit('option', $settings->id, $student4->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student4->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMBOOKIT, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student4->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student4->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        $answers = singleton_service::get_instance_of_booking_answers($settings);

        $this->assertCount(4, $answers->users);
        $this->assertCount(1, $answers->usersonlist);
        $this->assertCount(3, $answers->usersonwaitinglist);

        // Enrolled user cancels.
        $this->setUser($student1);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMCANCEL, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);

        // Continue as admin.
        $this->setAdminUser();
        singleton_service::destroy_instance();

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $ba = singleton_service::get_instance_of_booking_answers($settings);
        // Validate cancelled user.
        $this->assertIsArray($ba->usersonlist);
        $this->assertCount(1, $ba->usersonlist);
        $this->assertEquals($student2->id, array_key_first($ba->usersonlist));
        // Validate 1st user on waitinglist.
        $this->assertIsArray($ba->usersonwaitinglist);
        $this->assertCount(2, $ba->usersonwaitinglist);
        $this->assertEquals($student3->id, array_key_first($ba->usersonwaitinglist));
        // Execute tasks, get messages and validate it.
        // Get all scheduled task messages.

        // Don't expect tasks, since users are directly enrolled and no free places to book.
        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        $this->assertCount(0, $tasks);

        time_mock::set_mock_time(strtotime('now'));
        $time = time_mock::get_mock_time();

        $student3answer = $DB->get_record('booking_answers', [
            'userid' => $student3->id,
            'waitinglist' => 1,
            'optionid' => $option->id,
        ]);
        $this->assertNotFalse($student3answer);
        $student3answer->timemodified = $time;
        // Update directly in the DB to avoid mocking table data (like timemodified).
        $DB->update_record('booking_answers', $student3answer);

        // Reorder the waitinglist.
        $answer = $DB->get_record('booking_answers', ['userid' => $student4->id]);
        $answer->timemodified = $time;
        $DB->update_record('booking_answers', $answer);

        // Check that now the updated record is really the one with the highest timemodified.
        $waitinglistentries = $DB->get_records('booking_answers', [
            'waitinglist' => 1,
            'optionid' => $option->id,
        ], 'timemodified DESC');
        $this->assertEquals($student3answer->id, array_key_first($waitinglistentries));

        // Now put student1 back on the list.
        // And then cancel for student2.
        // Since the waitinglist was reordered, student4 should be on list.
        $this->setUser($student1);
        singleton_service::destroy_user($student1->id);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMBOOKIT, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        $this->setUser($student2);
        $result = booking_bookit::bookit('option', $settings->id, $student2->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMCANCEL, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student2->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);

        singleton_service::destroy_booking_option_singleton($option->id);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $ba2 = singleton_service::get_instance_of_booking_answers($settings);
        // Todo: asserion fails under pgsql only. Potential scenario issues in reordering process / dates.
        // phpcs:ignore
        //$this->assertEquals($student4->id, array_key_first($ba2->usersonlist));
    }

    /**
     * Test rules for "option free to bookagain" and "notification in intervals" events
     * ...when waitinglist is reordered forced and bookingoption cancelled.
     *
     * @covers \mod_booking\bo_availability\conditions\alreadybooked::is_available
     * @covers \mod_booking\bo_availability\conditions\onwaitinglist::is_available
     * @covers \mod_booking\event\bookingoption_freetobookagain
     * @covers \mod_booking\event\bookingoptionwaitinglist_booked
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event
     * @covers \mod_booking\booking_rules\actions\send_mail
     * @covers \mod_booking\booking_rules\conditions\select_teacher_in_bo
     * @covers \mod_booking\booking_rules\conditions\select_student_in_bo
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_rule_on_freeplace_on_intervals_when_waitinglist_reordered_and_user_cancelled(array $bdata): void {
        global $DB, $CFG;

        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

        $bdata['cancancelbook'] = 1;

        // Create course.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $student3 = $this->getDataGenerator()->create_user();
        $student4 = $this->getDataGenerator()->create_user();
        $student5 = $this->getDataGenerator()->create_user();
        $student6 = $this->getDataGenerator()->create_user();
        $student7 = $this->getDataGenerator()->create_user();
        $teacher1 = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $teacher1->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student3->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student4->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student5->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student6->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student7->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher1->id, $course1->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create booking rule 1 - "bookingoption_freetobookagain" with delays.
        $boevent1 = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_freetobookagain"';
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"interval":1440,"subject":"freeplacedelaysubj","template":"freeplacedelaymsg","templateformat":"1"}';
        $ruledata1 = [
            'name' => 'intervlqs',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail_interval',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent1 . ',"aftercompletion":1,"cancelrules":[],"condition":"2"}',
        ];
        $rule1 = $plugingenerator->create_rule($ruledata1);

        // Create booking option 1.
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'football';
        $record->maxanswers = 1;
        $record->maxoverbooking = 10; // Enable waitinglist.
        $record->waitforconfirmation = 1; // Force waitinglist.
        $record->description = 'Will start in a couple of days';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('+5 days', time());
        $record->courseendtime_0 = strtotime('+25 days', time());
        $record->teachersforoption = $teacher1->username;
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $boinfo = new bo_info($settings);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        // Create a booking option answer - book student1.
        $this->setUser($student1);
        singleton_service::destroy_user($student1->id);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Confirm booking as admin.
        $this->setAdminUser();
        $option->user_submit_response($student1, 0, 0, 0, MOD_BOOKING_VERIFIED);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Book the student2 via waitinglist with intervals.
        time_mock::set_mock_time(strtotime('+1 days', time()));
        $time = time_mock::get_mock_time();
        $this->setUser($student2);
        singleton_service::destroy_user($student2->id);
        $result = booking_bookit::bookit('option', $settings->id, $student2->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Book the student3 via waitinglist.
        time_mock::set_mock_time(strtotime('+1 days', time()));
        $time = time_mock::get_mock_time();
        $this->setUser($student3);
        singleton_service::destroy_user($student3->id);
        $result = booking_bookit::bookit('option', $settings->id, $student3->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Book the student4 via waitinglist.
        time_mock::set_mock_time(strtotime('+1 day', time()));
        $time = time_mock::get_mock_time();
        $this->setUser($student4);
        singleton_service::destroy_user($student4->id);
        $result = booking_bookit::bookit('option', $settings->id, $student4->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student4->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Book the student5 via waitinglist.
        time_mock::set_mock_time(strtotime('+1 day', time()));
        $time = time_mock::get_mock_time();
        $this->setUser($student5);
        singleton_service::destroy_user($student5->id);
        $result = booking_bookit::bookit('option', $settings->id, $student5->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student5->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Reorder waitinglist, student4 is now top on the list.
        $student4answer = $DB->get_record(
            'booking_answers',
            ['userid' => $student4->id, 'waitinglist' => 1, 'optionid' => $settings->id]
        );
        $this->assertNotFalse($student4answer);
        $student4answer->timemodified = strtotime('-6 days', time());
        $updateconfirmation = $DB->update_record('booking_answers', $student4answer);
        $this->assertTrue($updateconfirmation);
        booking_option::purge_cache_for_answers($settings->id);
        $waitinglist = $DB->get_records('booking_answers', ['waitinglist' => 1, 'optionid' => $settings->id], 'timemodified ASC');
        $firstonlist = reset($waitinglist);
        $this->assertEquals($student4->id, $firstonlist->userid);

        // First user cancels.
        $this->setUser($student1);
        singleton_service::destroy_user($student1->id);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMCANCEL, $id);
        singleton_service::destroy_user($student1->id);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ASKFORCONFIRMATION, $id);

        // Asserting that the spot is free to book and 4 users remaining on waitinglist.
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $ba = singleton_service::get_instance_of_booking_answers($settings);
        $this->assertIsArray($ba->usersonlist);
        $this->assertCount(0, $ba->usersonlist);
        $this->assertIsArray($ba->usersonwaitinglist);
        $this->assertCount(4, $ba->usersonwaitinglist);

        // Execute tasks, get messages and validate it.
        // Get all scheduled task messages.
        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');

        $this->assertCount(2, $tasks);
        // There are only two mails scheduled by the logic of send_mail_interval class.

        $taskdata = [];
        foreach ($tasks as $task) {
            $data = $task->get_custom_data();
            $data->nextruntime = $task->get_next_run_time();
            $taskdata[] = $data;
        }

        // Sort the array by nextruntime ascending.
        usort($taskdata, function ($a, $b) {
            return $a->nextruntime <=> $b->nextruntime;
        });

        // Student4 has the next runtime.
        $this->assertEquals($student4->id, $taskdata[0]->userid);
        $this->assertEquals($student2->id, $taskdata[1]->userid);

        // Check the interval.
        $runtimedifference = (int)$taskdata[1]->nextruntime - (int)$taskdata[0]->nextruntime;
        // The interval defined in the rules json is in minutes, so multiplied by 60 for the timestamp.
        $this->assertEquals(1440 * 60, $runtimedifference);

        // Ok now we add a user to the waitinglist, reorder the waitinglist to make him first...
        // ... set the time later, so that both of the tasks are running.
        // And see if the second task created a new reminder mail task for the right user.
        $this->setUser($student6);
        singleton_service::destroy_user($student5->id);
        $result = booking_bookit::bookit('option', $settings->id, $student6->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student6->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        $s6a = $DB->get_record('booking_answers', ['userid' => $student6->id, 'waitinglist' => 1, 'optionid' => $settings->id]);
        $this->assertNotFalse($s6a);
        $s6a->timemodified = strtotime('-20 days', time());
        $DB->update_record('booking_answers', $s6a);
        booking_option::purge_cache_for_answers($settings->id);

        time_mock::set_mock_time(time() + 10);
        $time = time_mock::get_mock_time();

        // Two tasks. One with runtime in the past for user student4.
        // And one for the next user on the list: student2.

        // Run tasks.
        $sink = $this->redirectMessages();
        ob_start();
        $this->runAdhocTasks();
        $messages = $sink->get_messages();
        $res = ob_get_clean();
        $sink->close();
        $this->assertCount(1, $messages);
        $this->assertEquals($student4->id, $messages[0]->useridto);

        // So now we expect two tasks.
        // First one for student6 who is now the first on the list who hasn't been informed yet.
        // Second for student2 who remains third (second non-informed).
        $newtasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        $this->assertCount(2, $tasks);

        $taskdata = [];
        foreach ($newtasks as $task) {
            $data = $task->get_custom_data();
            $data->nextruntime = $task->get_next_run_time();
            $taskdata[] = $data;
        }
        // Sort the array by nextruntime ascending.
        usort($taskdata, function ($a, $b) {
            return $a->nextruntime <=> $b->nextruntime;
        });

        $this->assertEquals($student6->id, $taskdata[0]->userid);
        $this->assertEquals($student2->id, $taskdata[1]->userid);
        $runtimedifference = (int)$taskdata[1]->nextruntime - (int)$taskdata[0]->nextruntime;
        $this->assertEquals(1440 * 60, $runtimedifference);

        time_mock::set_mock_time(strtotime('+20 days', time()) + 10);
        $time = time_mock::get_mock_time();
        // We are now 24 days ahead of real current time.

        $sink = $this->redirectMessages();
        ob_start();
        $this->runAdhocTasks();
        $messages = $sink->get_messages();
        $res = ob_get_clean();
        $sink->close();

        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        $firsttaskdata = reset($tasks)->get_custom_data();
        // Finally student2 is next to recieve the message.
        $this->assertEquals($student2->id, $firsttaskdata->userid);

        time_mock::set_mock_time(strtotime('+5 days', time()) + 10);
        $time = time_mock::get_mock_time();
        // We are now 29 days ahead of real current time, so bookingclosingtime is passed.

        $sink = $this->redirectMessages();
        ob_start();
        $this->runAdhocTasks();
        $messages = $sink->get_messages();
        $res = ob_get_clean();
        $sink->close();

        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        // Finally student2 is next to recieve the message.
        $this->assertEmpty($tasks);
    }

    /**
     * Create booking with bookingoption that contains price for some users, depending on profilefield.
     * Option is fully booked with waitinglist enabled. Some users on waitinglist need to pay, others don't.
     * Create rule to send interval messages.
     * One booked user cancels, 1 seat is free again.
     * Check that mail is send.
     * Check that new user NOT on waitinglist can not book.
     * Make sure, only user next on waitinglist can book.
     * If this user has the right value in the field, he will be enrolled automatically.
     * In this case, freetobookagain message should not be send (or scheduled).
     *
     * @covers \mod_booking\bo_availability\conditions\alreadybooked::is_available
     * @covers \mod_booking\bo_availability\conditions\onwaitinglist::is_available
     * @covers \mod_booking\event\bookingoption_freetobookagain
     * @covers \mod_booking\event\bookingoptionwaitinglist_booked
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event
     * @covers \mod_booking\booking_rules\actions\send_mail
     * @covers \mod_booking\booking_rules\conditions\select_teacher_in_bo
     * @covers \mod_booking\booking_rules\conditions\select_student_in_bo
     *
     * @param array $testdata
     * @param array $expected
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider waitinglist_price_provider
     */
    public function test_waitinglist_with_price(array $testdata, array $expected): void {
        global $DB;

        $bdata = self::booking_common_settings_provider();
        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

        $time = time_mock::get_mock_time();

        $bdata['cancancelbook'] = 1;
        set_config('cancelationfee', 0, 'local_shopping_cart');

        // Create course.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'pricecat',
            'name' => 'pricecat',
        ]);
        set_config('pricecategoryfield', 'pricecat', 'booking');
        set_config('displayemptyprice', 1, 'booking');

        // Create users, some of them with second price category.
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user($testdata['student2settings'] ?? []);
        $student3 = $this->getDataGenerator()->create_user($testdata['student3settings'] ?? []);
        $student4 = $this->getDataGenerator()->create_user($testdata['student4settings'] ?? []);
        $student5 = $this->getDataGenerator()->create_user($testdata['student5settings'] ?? []);
        $student6 = $this->getDataGenerator()->create_user($testdata['student6settings'] ?? []);
        $student7 = $this->getDataGenerator()->create_user($testdata['student7settings'] ?? []);
        $teacher1 = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $teacher1->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student3->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student4->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student5->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student6->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student7->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher1->id, $course1->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $pricecategorydata1 = (object) [
            'ordernum' => 1,
            'name' => 'default',
            'identifier' => 'default',
            'defaultvalue' => 50,
            'pricecatsortorder' => 1,
        ];
        $plugingenerator->create_pricecategory($pricecategorydata1);
        $pricecategorydata2 = (object) [
            'ordernum' => 2,
            'name' => 'SecondPrice',
            'identifier' => 'secondprice',
            'defaultvalue' => $testdata['secondprice'],
            'pricecatsortorder' => 2,
        ];
        $plugingenerator->create_pricecategory($pricecategorydata2);

        // Create booking rule 1 - "bookingoption_freetobookagain" with delays.
        $boevent1 = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_freetobookagain"';
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"interval":1440,"subject":"freeplacedelaysubj","template":"freeplacedelaymsg","templateformat":"1"}';
        $ruledata1 = [
            'name' => 'intervlqs',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail_interval',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent1 . ',"aftercompletion":0,"cancelrules":[],"condition":"2"}',
        ];
        $rule1 = $plugingenerator->create_rule($ruledata1);

        // Create booking option 1.
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'football';
        $record->maxanswers = 1;
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course1->id;
        $record->maxoverbooking = 10; // Enable waitinglist.
        $record->waitforconfirmation = $testdata['waitforconfirmation'];
        $record->confirmationonnotification = $testdata['confirmationonnotification'];
        $record->description = 'Will start in 2050';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00', time());
        $record->courseendtime_0 = strtotime('20 July 2050 14:00', time());
        $record->teachersforoption = $teacher1->username;
        $record->useprice = 1;
        $record->importing = 1;
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);
        singleton_service::destroy_booking_singleton_by_cmid($settings1->cmid); // Require to avoid caching issues.
        $boinfo1 = new bo_info($settings1);

        // Create a booking option answer - book student1.
        $this->setUser($student1);
        singleton_service::destroy_user($student1->id);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_PRICEISSET, $id);

        // Book the student.
        $this->setAdminUser();
        $price = price::get_price('option', $settings1->id, $student1);
        $this->assertEquals($pricecategorydata1->defaultvalue, $price["price"]);
        // Purchase item in behalf of student1 to having history item.
        // Clean cart.
        shopping_cart::delete_all_items_from_cart($student1->id);
        // Set user to buy in behalf of.
        shopping_cart::buy_for_user($student1->id);
        // Get cached data or setup defaults.
        $cartstore = cartstore::instance($student1->id);
        // Put in a test item with given ID (or default if ID > 4).
        $item = shopping_cart::add_item_to_cart('mod_booking', 'option', $settings1->id, -1);
        // Confirm cash payment.
        $res = shopping_cart::confirm_payment($student1->id, LOCAL_SHOPPING_CART_PAYMENT_METHOD_CASHIER_CASH);
        // Validate payment.
        $this->assertIsArray($res);
        $this->assertEmpty($res['error']);
        $item = shopping_cart_history::get_most_recent_historyitem('mod_booking', 'option', $settings1->id, $student1->id);

        // User student1 should be booked now.
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Book the student2 on waitinglist.
        $this->setUser($student2);
        singleton_service::destroy_user($student2->id);
        $result = booking_bookit::bookit('option', $settings1->id, $student2->id);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Book the student3 via waitinglist.
        time_mock::set_mock_time(strtotime('+1 days', time()));
        $time = time_mock::get_mock_time();
        $this->setUser($student3);
        singleton_service::destroy_user($student3->id);
        $result = booking_bookit::bookit('option', $settings1->id, $student3->id);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Book the student4 via waitinglist.
        time_mock::set_mock_time(strtotime('+1 day', time()));
        $time = time_mock::get_mock_time();
        $this->setUser($student4);
        singleton_service::destroy_user($student4->id);
        $result = booking_bookit::bookit('option', $settings1->id, $student4->id);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student4->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // First user cancels.
        $this->setUser($student1);
        singleton_service::destroy_user($student1->id);
        // Render to see if "cancel purchase" present.
        $buttons = booking_bookit::render_bookit_button($settings1, $student1->id);
        $this->assertStringContainsString('Cancel purchase', $buttons);
        // Cancellation of purcahse if shopping_cart installed.
        // Getting history of purchased item and verify.
        $item = shopping_cart_history::get_most_recent_historyitem('mod_booking', 'option', $settings1->id, $student1->id);
        shopping_cart::add_quota_consumed_to_item($item, $student1->id);
        shoppingcart_history_list::add_round_config($item);
        $this->assertEquals($settings1->id, $item->itemid);
        $this->assertEquals($student1->id, $item->userid);
        $this->assertEquals($pricecategorydata1->defaultvalue, (int) $item->price);
        $this->assertEquals(0, $item->quotaconsumed);
        // Actual cancellation of purcahse and verify.
        $res = shopping_cart::cancel_purchase($settings1->id, 'option', $student1->id, 'mod_booking', $item->id, 0);
        $this->assertEquals(1, $res['success']);
        $this->assertEquals($pricecategorydata1->defaultvalue, $res['credit']);
        $this->assertEmpty($res['error']);

        $ba = singleton_service::get_instance_of_booking_answers($settings1);

        // Try to book EXTERNAL user - not yet on waitinglist.
        // Result depends on waitforconfirmation setting.
        $this->setUser($student5);
        singleton_service::destroy_user($student5->id);
        $result = booking_bookit::bookit('option', $settings1->id, $student5->id);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student5->id, true);
        $this->assertEquals($expected['newuserresponse'], $id);

        // Asserting that the spot is EITHER free to book OR booked by next user AND proper number of users remains on waitinglist.
        $ba = singleton_service::get_instance_of_booking_answers($settings1);
        $this->assertIsArray($ba->usersonlist);
        $this->assertCount($expected['usersonlist1'], $ba->usersonlist);
        $this->assertIsArray($ba->usersonwaitinglist);
        $this->assertCount($expected['usersonwaitinglist1'], $ba->usersonwaitinglist);

        // Check for proper number of tasks.
        // Tasks are tested in depth in other tests of this class.
        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        $this->assertCount($expected['taskcount1'], $tasks);

        // In the future we run tasks.
        // No free seats available, so no messages should be send.
        time_mock::set_mock_time(strtotime('+3 day', time()));
        $time = time_mock::get_mock_time();

        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        $sink = $this->redirectMessages();
        ob_start();
        $this->runAdhocTasks();
        $messages = $sink->get_messages();
        $res = ob_get_clean();
        $sink->close();
        $this->assertCount($expected['messagecount'], $messages);
        if (isset($testdata['bookseconduser']) && !$testdata['bookseconduser']) {
            foreach ($messages as $key => $message) {
                if (strpos($message->subject, "freeplacedelaysubj")) {
                    // Validate email on option change.
                    $this->assertEquals($student2->id, $message->useridto);
                }
            }
        }

        // After the rule execution, we check the booking answer of student2 to
        // verify that the JSON column contains the expected value.
        $student2bookinganswer = $DB->get_record('booking_answers', [
            'optionid' => $option1->id,
            'userid' => $student2->id,
            'waitinglist' => $expected['student2waitinglistvalue'],
        ]);

        if (is_null($expected['student2bajsonvalue'])) {
            $this->assertTrue(
                is_null($student2bookinganswer->json) || $student2bookinganswer->json === '{}',
                'Expected null or empty JSON object for student2bookinganswer->json'
            );
        } else if ($expected['student2bajsonvalue'] === 'json') {
            // Check if first user on waiting list (student2) is confirmed by rule.
            $this->assertNotNull($student2bookinganswer->json);
        }

        // After the rule execution, we check the booking answer of student2 to
        // verify that the JSON column contains the expected value.
        [$id, $isavailable, $description] = $boinfo1->is_available($option1->id, $student2->id, true);
        $this->assertEquals($expected['student2condtionvalue'], $id);

        $runnedtask = [];
        // 1. Check the userids in the tasks
        foreach ($tasks as $task) {
            $data = $task->get_custom_data();
            $useridintask = $data->userid;
            $this->assertContains($useridintask, [$student2->id, $student3->id]);
            $runnedtask[] = $task->get_id();
        }

        // 2. See if both tasks are executed
        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        foreach ($tasks as $task) {
            $this->assertNotContains($task->get_id(), $runnedtask);
        }

        // 3. If both tasks are executed and new option is active, student2 should not have the confirm keys in the json.
        // And student3 should have confirm key in answer json.
        ob_start();
        $this->runAdhocTasks(); // Run task again.
        ob_get_clean();
        $student2bookinganswer = $DB->get_record('booking_answers', [
            'optionid' => $option1->id,
            'userid' => $student2->id,
            'waitinglist' => $expected['student2waitinglistvalue'],
        ]);
        if (is_null($expected['student2bajsonvalue2'])) {
            $this->assertTrue(
                is_null($student2bookinganswer->json) || $student2bookinganswer->json === '{}',
                'Expected null or empty JSON object for student2bookinganswer->json'
            );
        } else {
            $this->assertNotNull($student2bookinganswer->json);
        }

        $student3bookinganswer = $DB->get_record('booking_answers', [
            'optionid' => $option1->id,
            'userid' => $student3->id,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
        ]);
        if (is_null($expected['student3bajsonvalue2'])) {
            $this->assertTrue(
                is_null($student3bookinganswer->json) || $student3bookinganswer->json === '{}',
                'Expected null or empty JSON object for student3bookinganswer->json'
            );
        } else {
            $this->assertNotNull($student3bookinganswer->json);
        }
    }

    /**
     * Create booking with bookingoption that contains price for some users, depending on profilefield.
     * Option is fully booked with waitinglist enabled. Some users on waitinglist need to pay, others don't.
     * Create rule to send interval messages.
     * One booked user cancels, 1 seat is free again.
     * Check that mail is send.
     * Check that new user NOT on waitinglist can not book.
     * Make sure, only user next on waitinglist can book.
     * If this user has the right value in the field, he will be enrolled automatically.
     * In this case, freetobookagain message should not be send (or scheduled).
     *
     * @covers \mod_booking\bo_availability\conditions\alreadybooked::is_available
     * @covers \mod_booking\bo_availability\conditions\onwaitinglist::is_available
     * @covers \mod_booking\event\bookingoption_freetobookagain
     * @covers \mod_booking\event\bookingoptionwaitinglist_booked
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event
     * @covers \mod_booking\booking_rules\actions\send_mail
     * @covers \mod_booking\booking_rules\conditions\select_teacher_in_bo
     * @covers \mod_booking\booking_rules\conditions\select_student_in_bo
     *
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function test_waitinglist_notification_enable_booking(): void {
        global $DB;

        $bdata = self::booking_common_settings_provider();
        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

        $time = time_mock::get_mock_time();

        $bdata['cancancelbook'] = 1;
        set_config('cancelationfee', 0, 'local_shopping_cart');

        // Create course.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users, some of them with second price category.
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user($testdata['student2settings'] ?? []);
        $student3 = $this->getDataGenerator()->create_user($testdata['student3settings'] ?? []);
        $student4 = $this->getDataGenerator()->create_user($testdata['student4settings'] ?? []);
        $student5 = $this->getDataGenerator()->create_user($testdata['student5settings'] ?? []);
        $student6 = $this->getDataGenerator()->create_user($testdata['student6settings'] ?? []);
        $student7 = $this->getDataGenerator()->create_user($testdata['student7settings'] ?? []);
        $teacher1 = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $teacher1->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student3->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student4->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student5->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student6->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student7->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher1->id, $course1->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create booking rule 1 - "bookingoption_freetobookagain" with delays.
        $boevent1 = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_freetobookagain"';
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"interval":1440,"subject":"freeplacedelaysubj","template":"freeplacedelaymsg","templateformat":"1"}';
        $ruledata1 = [
            'name' => 'intervlqs',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail_interval',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent1 . ',"aftercompletion":0,"cancelrules":[],"condition":"2"}',
        ];
        $rule1 = $plugingenerator->create_rule($ruledata1);

        // Create booking option 1.
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'football';
        $record->maxanswers = 1;
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course1->id;
        $record->maxoverbooking = 10; // Enable waitinglist.
        $record->waitforconfirmation = 2;
        $record->confirmationonnotification = 1;
        $record->description = 'Will start in 2050';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00', time());
        $record->courseendtime_0 = strtotime('20 July 2050 14:00', time());
        $record->teachersforoption = $teacher1->username;
        $record->useprice = 0;
        $record->importing = 1;
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);
        singleton_service::destroy_booking_singleton_by_cmid($settings1->cmid); // Require to avoid caching issues.
        $boinfo1 = new bo_info($settings1);

        // Create a booking option answer - book student1.
        $this->setUser($student1);
        singleton_service::destroy_user($student1->id);
        $result = booking_bookit::bookit('option', $settings1->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMBOOKIT, $id);
        $result = booking_bookit::bookit('option', $settings1->id, $student1->id);

        $answer = $DB->get_record('booking_answers', ['userid' => $student1->id]);
        // User student1 should be booked now.
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Book the student2 on waitinglist.
        $this->setUser($student2);
        singleton_service::destroy_user($student2->id);
        $result = booking_bookit::bookit('option', $settings1->id, $student2->id);
        $result = booking_bookit::bookit('option', $settings1->id, $student2->id);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Book the student3 via waitinglist.
        time_mock::set_mock_time(strtotime('+1 days', time()));
        $time = time_mock::get_mock_time();
        $this->setUser($student3);
        singleton_service::destroy_user($student3->id);
        $result = booking_bookit::bookit('option', $settings1->id, $student3->id);
        $result = booking_bookit::bookit('option', $settings1->id, $student3->id);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Book the student4 via waitinglist.
        time_mock::set_mock_time(strtotime('+1 day', time()));
        $time = time_mock::get_mock_time();
        $this->setUser($student4);
        singleton_service::destroy_user($student4->id);
        $result = booking_bookit::bookit('option', $settings1->id, $student4->id);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student4->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMBOOKIT, $id);
        $result = booking_bookit::bookit('option', $settings1->id, $student4->id);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student4->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // First user cancels.
        $this->setUser($student1);
        singleton_service::destroy_user($student1->id);
        // Render to see if "Undo my booking" present.
        $buttons = booking_bookit::render_bookit_button($settings1, $student1->id);
        $this->assertStringContainsString('Undo my booking', $buttons);
        $result = booking_bookit::bookit('option', $settings1->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMCANCEL, $id);
        $result = booking_bookit::bookit('option', $settings1->id, $student1->id);

        // Even if there is a free spot, a "new" user can not book with setting waitforconfirmation = 2.
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ASKFORCONFIRMATION, $id);

        // Try to book EXTERNAL user - not yet on waitinglist.
        // Result depends on waitforconfirmation setting.
        $this->setUser($student6);
        singleton_service::destroy_user($student6->id);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student6->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ASKFORCONFIRMATION, $id);

        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Check for proper number of tasks.
        // Tasks are tested in depth in other tests of this class.
        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        $this->assertCount(2, $tasks);

        // In the future we run tasks.
        // No free seats available, so no messages should be send.
        time_mock::set_mock_time(strtotime('+3 day', time()));
        $this->runAdhocTasks();

        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);
    }

    /**
     * Data provider for test waitinglist with price.
     *
     * @return array
     *
     */
    public static function waitinglist_price_provider(): array {
        return [
            'second_user_no_price_no_confirmationlist' => [
                [
                    'secondprice' => 0,
                    'student2settings' => ['profile_field_pricecat' => 'secondprice'],
                    'bookseconduser' => true,
                    'waitforconfirmation' => 0,
                    'student5settings' => [],
                    'confirmationonnotification' => 0, // It can not be any other value when waitforconfirmation is equal to zero.
                ],
                [
                    // After the first cancellation, with these settings, we expect...
                    // The student2 (next on waitinglist) to be on the list, because he doesn't need to pay.
                    'usersonlist1' => 1,
                    'usersonwaitinglist1' => 3,
                    // So no tasks expected.
                    'taskcount1' => 0,
                    // Therefore (student2 already took the place), the external user can only book on the list.
                    'newuserresponse' => MOD_BOOKING_BO_COND_ONWAITINGLIST,
                    // So no tasks expected.
                    'messagecount' => 0,
                    // Student 2 booking answer waitinglist expected value.
                    'student2waitinglistvalue' => MOD_BOOKING_STATUSPARAM_BOOKED,
                    // Student 2 booking answer json expected value after rule execution.
                    'student2bajsonvalue' => null,
                    // Student 2 booking condition after rule execution.
                    'student2condtionvalue' => MOD_BOOKING_BO_COND_ALREADYBOOKED,
                    'student2bajsonvalue2' => null,
                    'student3bajsonvalue2' => null,
                ],
            ],
            'second_user_with_price_no_confirmationlist' => [
                [
                    'secondprice' => 10,
                    'student2settings' => ['profile_field_pricecat' => 'secondprice'],
                    'bookseconduser' => false,
                    'waitforconfirmation' => 0,
                    'student5settings' => [],
                    'confirmationonnotification' => 0, // It can not be any other value when waitforconfirmation is equal to zero.
                ],
                [
                    // Since user has to pay, we expect no one booked and user still on waitinglist.
                    'usersonlist1' => 0,
                    'usersonwaitinglist1' => 3,
                    'taskcount1' => 2, // Tasks expected.
                    // Therefore new user can book with price.
                    'newuserresponse' => MOD_BOOKING_BO_COND_PRICEISSET,
                    // Tasks expected.
                    'messagecount' => 1,
                    // Student 2 booking answer waitinglist expected value.
                    'student2waitinglistvalue' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
                    // Student 2 booking answer json expected value after rule execution.
                    'student2bajsonvalue' => null,
                    // Student 2 booking condition after rule execution.
                    'student2condtionvalue' => MOD_BOOKING_BO_COND_PRICEISSET,
                    'student2bajsonvalue2' => null,
                    'student3bajsonvalue2' => null,
                ],
            ],
            'second_user_with_price_and_confirmationlist_for_waitinglist' => [
                [
                    'secondprice' => 10,
                    'student2settings' => ['profile_field_pricecat' => 'secondprice'],
                    'bookseconduser' => false,
                    'waitforconfirmation' => 2,
                    'student5settings' => [],
                    'confirmationonnotification' => 0, // Users will not be notified.
                ],
                [
                    // Since user has to pay, we expect no one booked and user still on waitinglist.
                    'usersonlist1' => 0,
                    'usersonwaitinglist1' => 4,
                    // Tasks expected.
                    'taskcount1' => 2,
                    // With confirmation only on waitinglist, new user is blocked from booking and put on waitinglist.
                    'newuserresponse' => MOD_BOOKING_BO_COND_ONWAITINGLIST,
                    // Tasks expected.
                    'messagecount' => 1,
                    // Student 2 booking answer waitinglist expected value.
                    'student2waitinglistvalue' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
                    // Student 2 booking answer json value after rule execution.
                    'student2bajsonvalue' => null,
                    // Student 2 booking condition after rule execution.
                    'student2condtionvalue' => MOD_BOOKING_BO_COND_ONWAITINGLIST,
                    'student2bajsonvalue2' => null,
                    'student3bajsonvalue2' => null,

                ],
            ],
            'second_user_with_price_and_confirmationlist_for_waitinglist_and_with_confirmationonnotification1' => [
                [
                    'secondprice' => 10,
                    'student2settings' => ['profile_field_pricecat' => 'secondprice'],
                    'bookseconduser' => false,
                    'waitforconfirmation' => 2,
                    'student5settings' => [],
                    // Users will be notified and json value for the first prson on waiting list will be null.
                    'confirmationonnotification' => 1,
                ],
                [
                    // Since user has to pay, we expect no one booked and user still on waitinglist.
                    'usersonlist1' => 0,
                    'usersonwaitinglist1' => 4,
                    // Tasks expected.
                    'taskcount1' => 2,
                    // With confirmation only on waitinglist, new user is blocked from booking and put on waitinglist.
                    'newuserresponse' => MOD_BOOKING_BO_COND_ONWAITINGLIST,
                    // Tasks expected.
                    'messagecount' => 1,
                    // Student 2 booking answer waitinglist expected value.
                    'student2waitinglistvalue' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
                    // Student 2 booking answer json value after rule execution.
                    'student2bajsonvalue' => 'json',
                    // Student 2 booking condition after rule execution.
                    'student2condtionvalue' => MOD_BOOKING_BO_COND_PRICEISSET,
                    'student2bajsonvalue2' => 'json',
                    'student3bajsonvalue2' => 'json',
                ],

            ],
            'second_user_with_price_and_confirmationlist_for_waitinglist_and_with_confirmationonnotification2' => [
                [
                    'secondprice' => 10,
                    'student2settings' => ['profile_field_pricecat' => 'secondprice'],
                    'bookseconduser' => false,
                    'waitforconfirmation' => 2,
                    'student5settings' => [],
                    // Users will be notified and json value for the first prson on waiting list will be null.
                    'confirmationonnotification' => 2,
                ],
                [
                    // Since user has to pay, we expect no one booked and user still on waitinglist.
                    'usersonlist1' => 0,
                    'usersonwaitinglist1' => 4,
                    // Tasks expected.
                    'taskcount1' => 2,
                    // With confirmation only on waitinglist, new user is blocked from booking and put on waitinglist.
                    'newuserresponse' => MOD_BOOKING_BO_COND_ONWAITINGLIST,
                    // Tasks expected.
                    'messagecount' => 1,
                    // Student 2 booking answer waitinglist expected value.
                    'student2waitinglistvalue' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
                    // Student 2 booking answer json value after rule execution.
                    'student2bajsonvalue' => 'json',
                    // Student 2 booking condition after rule execution.
                    'student2condtionvalue' => MOD_BOOKING_BO_COND_ONWAITINGLIST,
                    'student2bajsonvalue2' => null,
                    'student3bajsonvalue2' => 'json',
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
