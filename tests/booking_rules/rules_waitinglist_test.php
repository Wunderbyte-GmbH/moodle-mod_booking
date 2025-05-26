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
use tool_mocktesttime\time_mock;
use mod_booking_generator;

/**
 * Tests for booking rules.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rules_waitinglist_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $time = time();
        time_mock::init();
        time_mock::set_mock_time($time);
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
    }

    /**
     * Test rules for "option free to bookagain" and "notification in intervals" events
     * ...when waitinglist is forced and maxanswers has been increased.
     *
     * @covers \condition\alreadybooked::is_available
     * @covers \condition\onwaitinglist::is_available
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
        $ruledata1 = [
            'name' => 'intervlqs',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail_interval',
            'actiondata' => '{"interval":1440,"subject":"freeplacedelaysubj","template":"freeplacedelaymsg","templateformat":"1"}',
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent1 . ',"aftercompletion":0,"cancelrules":[],"condition":"2"}',
        ];
        $rule1 = $plugingenerator->create_rule($ruledata1);

        // Create booking rule 2 - "bookingoption_freetobookagain".
        $boevent2 = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_freetobookagain"';
        $ruledata2 = [
            'name' => 'override',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail',
            'actiondata' => '{"subject":"freeplacesubj","template":"freeplacemsg","templateformat":"1"}',
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

        // Mandatory to solve potential cache issues.
        singleton_service::destroy_instance();
        // Mandatory to deal with static variable in the booking_rules.
        rules_info::$rulestoexecute = [];
        booking_rules::$rules = [];
        time_mock::set_mock_time(time()); // Set "now".
        $time = time_mock::get_mock_time();
    }

    /**
     * Test rules for "option free to bookagain" and "notification in intervals" events
     * ...when waitinglist is reorderd and booking is cancelled.
     *
     * @covers \condition\alreadybooked::is_available
     * @covers \condition\onwaitinglist::is_available
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
        $ruledata1 = [
            'name' => 'intervlqs',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail_interval',
            'actiondata' => '{"interval":1440,"subject":"freeplacedelaysubj","template":"freeplacedelaymsg","templateformat":"1"}',
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
        singleton_service::destroy_booking_option_singleton($option->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $ba = singleton_service::get_instance_of_booking_answers($settings);
        $this->assertIsArray($ba->usersonlist);
        $this->assertCount(1, $ba->usersonlist);
        $this->assertIsArray($ba->usersonwaitinglist);
        $this->assertCount(2, $ba->usersonwaitinglist);
        $this->assertEquals($student2->id, array_key_first($ba->usersonlist));
        // Execute tasks, get messages and validate it.
        // Get all scheduled task messages.

        // Don't expect tasks, since users are directly enrolled and no free places to book.
        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        $this->assertCount(0, $tasks);

        time_mock::set_mock_time(strtotime('now', time()));
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
        $this->assertEquals($student4->id, array_key_first($ba2->usersonlist));

        // Mandatory to solve potential cache issues.
        singleton_service::destroy_instance();
        // Mandatory to deal with static variable in the booking_rules.
        rules_info::$rulestoexecute = [];
        booking_rules::$rules = [];
        time_mock::set_mock_time(strtotime('+1 days', time())); // Set "now".
        $time = time_mock::get_mock_time();
    }

    /**
     * Test rules for "option free to bookagain" and "notification in intervals" events
     * ...when waitinglist is reordered forced and bookingoption cancelled.
     *
     * @covers \condition\alreadybooked::is_available
     * @covers \condition\onwaitinglist::is_available
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
        $ruledata1 = [
            'name' => 'intervlqs',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail_interval',
            'actiondata' => '{"interval":1440,"subject":"freeplacedelaysubj","template":"freeplacedelaymsg","templateformat":"1"}',
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
        $record->waitforconfirmation = 1; // Force waitinglist.
        $record->description = 'Will start in 2050';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00', time());
        $record->courseendtime_0 = strtotime('20 July 2050 14:00', time());
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

        // Book the student5 via waitinglist.
        time_mock::set_mock_time(strtotime('+1 day', time()));
        $time = time_mock::get_mock_time();
        $this->setUser($student5);
        singleton_service::destroy_user($student5->id);
        $result = booking_bookit::bookit('option', $settings->id, $student5->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student5->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Back to now.
        time_mock::set_mock_time(time());
        $time = time_mock::get_mock_time();

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

        // Mandatory to solve potential cache issues.
        singleton_service::destroy_instance();
        // Mandatory to deal with static variable in the booking_rules.
        rules_info::$rulestoexecute = [];
        booking_rules::$rules = [];
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
