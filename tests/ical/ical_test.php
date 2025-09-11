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

namespace mod_booking;

use advanced_testcase;
use stdClass;
use tool_mocktesttime\time_mock;
use mod_booking_generator;
use mod_booking\option\fields_info;
use mod_booking\bo_availability\bo_info;

/**
 * Tests for ical.
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2025 Mahdi Poustini
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class ical_test extends advanced_testcase {
    /**
     * Setup environment.
     * @return array
     */
    protected function setup_environment() {
        global $DB, $CFG;
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $teacher1 = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $teacher1->username;

        $bookingmodule1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher1->id, $course1->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create booking rule 1 - "bookingoption_booked".
        $event1 = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_booked"';
        $rule1data = [
            'name' => 'send ics file',
            'conditionname' => 'select_user_from_event', // User from the event.
            'contextid' => 1,
            'conditiondata' => '{"userfromeventtype":"relateduserid"}', // User affected by the event.
            'actionname' => 'send_mail',
            'actiondata' => '{"sendical":1,"sendicalcreateorcancel":"create","subject":"Test","template":""}',
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $event1 . ',"aftercompletion":0,"condition":"0"}',
        ];
        $plugingenerator->create_rule($rule1data);

        // Create booking rule 2 - "bookinganswer_cancelled".
        $event2 = '"boevent":"\\\\mod_booking\\\\event\\\\bookinganswer_cancelled"';
        $rule2data = [
            'name' => 'send ics file',
            'conditionname' => 'select_user_from_event', // User from the event.
            'contextid' => 1,
            'conditiondata' => '{"userfromeventtype":"relateduserid"}', // User affected by the event.
            'actionname' => 'send_mail',
            'actiondata' => '{"sendical":1,"sendicalcreateorcancel":"cancel","subject":"Test","template":""}',
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $event2 . ',"aftercompletion":0,"condition":"0"}',
        ];
        $plugingenerator->create_rule($rule2data);

        // Create booking rule 3 - "bookingoption_updated".
        $event3 = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_updated"';
        $rule3data = [
            'name' => 'send ics file',
            'conditionname' => 'select_student_in_bo', // Users from bookig option.
            'contextid' => 1,
            'conditiondata' => '{"borole":"0"}', // Users who booked.
            'actionname' => 'send_mail',
            'actiondata' => '{"sendical":1,"sendicalcreateorcancel":"create","subject":"Test","template":""}',
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $event3 . ',"aftercompletion":0,"condition":"0"}',
        ];
        $plugingenerator->create_rule($rule3data);

        return [
            'course' => $course1,
            'bookingmodule' => $bookingmodule1,
            'plugingenerator' => $plugingenerator,
            'users' => [
                'student1' => $student1,
                'student2' => $student2,
            ],
        ];
    }

    /**
     * Test creation of ical file.
     * @covers \mod_booking\message_controller
     * @return void
     */
    public function test_create_calendar(): void {
        global $DB;
        // Get environment.
        $env = $this->setup_environment();
        $course = $env['course'];
        $bookingmodule = $env['bookingmodule'];
        $plugingenerator = $env['plugingenerator'];
        $student1 = $env['users']['student1'];
        $student2 = $env['users']['student2'];
        $this->resetAfterTest();

        $record = new stdClass();
        $record->bookingid = $bookingmodule->id;
        $record->text = 'Test option1';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course->id;
        $record->maxanswers = 2;
        $record->useprice = 0;
        $record->importing = 1;
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050');
        $record->courseendtime_0 = strtotime('20 July 2050');

        $option = $plugingenerator->create_option($record);

        // Verify if all sessions were updated correctly.
        $optiondata = (object)[
            'id' => $option->id,
            'cmid' => $option->cmid,
        ];
        fields_info::set_data($optiondata);
        [$dates, $highestindexchild] = dates::get_list_of_submitted_dates((array)$optiondata);
        $this->assertCount(1, $dates);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boinfo = new bo_info($settings);

        // Book student 1.
        booking_bookit::bookit('option', $settings->id, $student1->id);
        booking_bookit::bookit('option', $settings->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Book student 2.
        booking_bookit::bookit('option', $settings->id, $student2->id);
        booking_bookit::bookit('option', $settings->id, $student2->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Check if adhoc tasks are created.
        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        $this->assertCount(2, $tasks);

        // Sink messages.
        $sink = $this->redirectMessages();
        $eventsink = $this->redirectEvents();

        // Run adhoc tasks (this executes send_mail_by_rule_adhoc).
        $this->runAdhocTasks();

        $messages = $sink->get_messages();
        $events = $eventsink->get_events();
        $sink->close();

        // Assert: one message was sent.
        $this->assertCount(2, $messages);

        $msg = $messages[0];
        $this->assertEquals('mod_booking', $msg->component);
        $this->assertEquals($student1->id, $msg->useridto);
        $this->assertEquals('Test', $msg->subject);
    }
}
