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
use context_system;
use stdClass;
use mod_booking_generator;
use mod_booking\option\fields_info;
use mod_booking\bo_availability\bo_info;
use mod_booking\booking_rules\booking_rules;
use mod_booking\booking_rules\rules_info;

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
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
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
    }

    /**
     * Setup environment.
     * @param int $numberofdatesinoption
     * @return array
     */
    protected function setup_environment($numberofdatesinoption = 1) {
        global $DB, $CFG;
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $student1 = $this->getDataGenerator()->create_user([
            'firstname' => 'Maximiliana',
            'lastname'  => 'Hieronymopolous-Cavendish-Montenegresco',
            'email'  => 'Maximiliana.Hieronymopolous-Cavendish-Montenegresco@example.com',
        ]);
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

        $record = new stdClass();
        $record->bookingid = $bookingmodule1->id;
        $record->text = 'Test option1';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course1->id;
        $record->maxanswers = 2;
        $record->useprice = 0;
        $record->importing = 1;
        for ($i = 0; $i < $numberofdatesinoption; $i++) {
            $record->{"optiondateid_$i"} = "0";
            $record->{"daystonotify_$i"} = "0";
            $record->{"coursestarttime_$i"} = strtotime('20 June 2050') + ($i * 3600 * 24);
            $record->{"courseendtime_$i"} = strtotime('20 July 2050') + ($i * 3600 * 24);
        }

        $option = $plugingenerator->create_option($record);

        return [
            'course' => $course1,
            'bookingmodule' => $bookingmodule1,
            'plugingenerator' => $plugingenerator,
            'option' => $option,
            'record' => $record,
            'users' => [
                'student1' => $student1,
                'student2' => $student2,
            ],
        ];
    }

    /**
     * Checks if ical creates the booking ics file.
     * @covers \mod_booking\ical
     * @dataProvider ical_class_provider
     * @param int $numberofdates
     * @return void
     */
    public function test_ical_class(int $numberofdates): void {
        $env = $this->setup_environment($numberofdates);
        $option = $env['option'];
        $student1 = $env['users']['student1'];
        $this->resetAfterTest();

        // Settings.
        $optionsettings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($optionsettings->cmid);
        $bookingmanager = $bookingsettings->bookingmanageruser;

        $ical = new ical($bookingsettings, $optionsettings, $student1, $bookingmanager, false);
        $attachments = $ical->get_attachments(true);
        $attachname = $ical->get_name();
        $this->assertArrayHasKey('booking.ics', $attachments);
        $this->assertEquals('booking.ics', $attachname);

        $file = file_get_contents($attachments['booking.ics']);
        $this->assertNotEmpty($file, 'ICS file content is empty');

        // General structure.
        $this->assertStringContainsString('BEGIN:VCALENDAR', $file);
        $this->assertStringContainsString('END:VCALENDAR', $file);

        // Check that there is exactly N VEVENT.
        $this->assertEquals($numberofdates, substr_count($file, 'BEGIN:VEVENT'));
        $this->assertEquals($numberofdates, substr_count($file, 'END:VEVENT'));

        // Core fields.
        $this->assertStringContainsString('SUMMARY:', $file, 'ICS file missing SUMMARY');
        $this->assertStringContainsString('DTSTART:', $file, 'ICS file missing DTSTART');
        $this->assertStringContainsString('DTEND:', $file, 'ICS file missing DTEND');
        $this->assertStringContainsString('UID:', $file, 'ICS file missing UID');
        $this->assertStringContainsString('SEQUENCE:', $file, 'ICS file missing SEQUENCE');

        // Attendee line should include student’s email.
        $this->assertStringContainsString('ATTENDEE', $file, 'ICS file missing ATTENDEE');
        $unfolded = preg_replace("/\r\n[ \t]/", '', $file);
        $this->assertStringContainsString('MAILTO:' . $student1->email, $unfolded);

        // Organizer should be present (booking manager or noreply fallback).
        $this->assertStringContainsString('ORGANIZER', $file);

        // If you expect a CANCEL status, check it.
        $this->assertStringContainsString('STATUS:CANCELLED', $file, 'ICS file should be marked cancelled');

        // Optionally: ensure proper folding (line breaks with CRLF + space).
        $this->assertMatchesRegularExpression('/\r\n /', $file, 'ICS file lines are not folded properly');
    }

    /**
     * Scenario:
     * We book an option for 2 students.
     * We must have some adhoc tasks for each user that sends a message.
     * Every booked user must receive a message.
     * The ICS file with method REQUEST.
     *
     * @covers \mod_booking\message_controller
     * @covers \mod_booking\ical
     * @return void
     */
    public function test_create_calendar(): void {
        global $DB;
        // Get environment.
        $env = $this->setup_environment(1);
        $option = $env['option'];
        $student1 = $env['users']['student1'];
        $student2 = $env['users']['student2'];
        $this->resetAfterTest();

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
        ob_start();
        // Run adhoc tasks (this executes send_mail_by_rule_adhoc).
        $this->runAdhocTasks();
        $messages = $sink->get_messages();
        $res = ob_get_clean();
        $sink->close();

        // Assert: one message was sent.
        $this->assertCount(2, $messages);

        // Get first message.
        $msg = $messages[0];
        $this->assertSame('mod_booking', $msg->component);
        // Order of messages is not guaranteed, so we check that the recipient is one of the two students.
        $this->assertSame(true, in_array($msg->useridto, [$student1->id, $student2->id]));
        $this->assertSame('Test', $msg->subject);

        // Check the created ics file for the user.
        $fs = get_file_storage();
        $files = $fs->get_area_files(
            context_system::instance()->id,
            'mod_booking',
            'message_attachments',
            $student1->id,
            'id',
            false
        );
        $this->assertNotEmpty($files, 'ICS attachment not found in file storage.');

        $icsfile = reset($files); // First (and usually only) file.
        $this->assertInstanceOf(\stored_file::class, $icsfile);
        $this->assertEquals('booking.ics', $icsfile->get_filename());
        $this->assertEquals('text/calendar', $icsfile->get_mimetype());

        // Get content as string.
        $content = $icsfile->get_content();
        $this->assertNotEmpty($content, 'ICS file content is empty.');

        // Now you can assert ICS internals.
        $this->assertStringContainsString('BEGIN:VCALENDAR', $content);
        $this->assertStringContainsString('BEGIN:VEVENT', $content);
        $this->assertStringContainsString('SUMMARY:', $content);
        $this->assertStringContainsString('ATTENDEE', $content);
        // It should have REQUEST method as user booked the option.
        $this->assertStringContainsString('METHOD:REQUEST', $content);
    }

    /**
     * Scenario:
     * We book an option for 2 students.
     * Admin changes the title of the booking option.
     * We must have some adhoc tasks for each user that sends a message.
     * Every booked user must receive a message.
     * The ICS file with method REQUEST.
     *
     * @covers \mod_booking\message_controller
     * @covers \mod_booking\ical
     * @return void
     */
    public function test_update_calendar(): void {
        global $DB;

        $this->resetAfterTest();

        $this->setAdminUser();

        // Get environment.
        $env = $this->setup_environment(1);
        $option = $env['option'];
        $record = $env['record'];
        $student1 = $env['users']['student1'];
        $student2 = $env['users']['student2'];

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

        // Run adhoc tasks. Now users will receive ics file of booking event.
        // These messages are not something that we nedd to check. We need to check the messages on
        // update event.
        ob_start();
        $this->runAdhocTasks();
        $res = ob_get_clean();

        $this->setAdminUser();

        // Change title of the option.
        // Update booking.
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $record->id = $option->id;
        $record->cmid = $settings->cmid;
        $record->text = 'New booking option text';
        booking_option::update($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        // Check if adhoc tasks are created as we updated the booking option and defined a rule for it.
        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        $this->assertCount(2, $tasks); // As we have two students we should have 2 adhoc tasks.

        // Delete files as we have a conditin in message_controlelr that prevents
        // deleting files for the unit tests. This important to prevent duplication.
        $fs = get_file_storage();
        $fs->delete_area_files(
            context_system::instance()->id,
            'mod_booking',
            'message_attachments'
        );

        // Sink messages.
        $sink = $this->redirectMessages();
        $eventsink = $this->redirectEvents();
        ob_start();
        // Run adhoc tasks (this executes send_mail_by_rule_adhoc).
        $this->runAdhocTasks();
        $messages = $sink->get_messages();
        $res = ob_get_clean();
        $sink->close();

        // Assert: one message was sent.
        $this->assertCount(2, $messages);

        // Get first message.
        $msg = $messages[0];
        $this->assertEquals('mod_booking', $msg->component);
        $this->assertEquals($student1->id, $msg->useridto);
        $this->assertEquals('Test', $msg->subject);

        // Check the created ics file for the user.
        $fs = get_file_storage();
        $files = $fs->get_area_files(
            context_system::instance()->id,
            'mod_booking',
            'message_attachments',
            $student1->id,
            'id',
            false
        );
        $this->assertNotEmpty($files, 'ICS attachment not found in file storage.');

        $icsfile = reset($files); // First (and usually only) file.
        $this->assertInstanceOf(\stored_file::class, $icsfile);
        $this->assertEquals('booking.ics', $icsfile->get_filename());
        $this->assertEquals('text/calendar', $icsfile->get_mimetype());

        // Get content as string.
        $content = $icsfile->get_content();
        $this->assertNotEmpty($content, 'ICS file content is empty.');

        // Now you can assert ICS internals.
        $this->assertStringContainsString('BEGIN:VCALENDAR', $content);
        $this->assertStringContainsString('BEGIN:VEVENT', $content);
        $this->assertStringContainsString('SUMMARY:', $content);
        $this->assertStringContainsString('ATTENDEE', $content);
        // It should have REQUEST method as user booked the option.
        $this->assertStringContainsString('METHOD:REQUEST', $content);
    }

    /**
     * Scenario:
     * We book an option for 2 students.
     * One student cancels the booking answer.
     * We must have some adhoc tasks for each user that sends a message.
     * The user who cancels the booknig option must receive a message.
     * The ICS file with method CANCEL.
     *
     * @covers \mod_booking\message_controller
     * @covers \mod_booking\ical
     * @return void
     */
    public function test_cancel_calendar(): void {
        global $DB;
        // Get environment.
        $env = $this->setup_environment(1);
        $option = $env['option'];
        $record = $env['record'];
        $student1 = $env['users']['student1'];
        $student2 = $env['users']['student2'];
        $this->resetAfterTest();

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

        // Run adhoc tasks. Now users will receive ics file of booking event.
        // These messages are not something that we nedd to check. We need to check the messages on
        // update event.
        ob_start();
        $this->runAdhocTasks();
        $res = ob_get_clean();

        // Cancel the booking answer.
        $optioninstance = singleton_service::get_instance_of_booking_option($option->cmid, $option->id);
        $optioninstance->user_delete_response($student1->id);

        // Check if adhoc tasks are created as we updated the booking option and defined a rule for it.
        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        $this->assertCount(1, $tasks); // As we have two students we should have 2 adhoc tasks.

        // Delete files as we have a conditin in message_controlelr that prevents
        // deleting files for the unit tests. This important to prevent duplication.
        $fs = get_file_storage();
        $fs->delete_area_files(
            context_system::instance()->id,
            'mod_booking',
            'message_attachments'
        );

        // Sink messages.
        $sink = $this->redirectMessages();
        $eventsink = $this->redirectEvents();
        ob_start();
        // Run adhoc tasks (this executes send_mail_by_rule_adhoc).
        $this->runAdhocTasks();
        $messages = $sink->get_messages();
        $res = ob_get_clean();
        $sink->close();

        // Assert: one message was sent.
        $this->assertCount(1, $messages);

        // Get first message.
        $msg = $messages[0];
        $this->assertEquals('mod_booking', $msg->component);
        $this->assertEquals($student1->id, $msg->useridto);
        $this->assertEquals('Test', $msg->subject);

        // Check the created ics file for the user.
        $fs = get_file_storage();
        $files = $fs->get_area_files(
            context_system::instance()->id,
            'mod_booking',
            'message_attachments',
            $student1->id,
            'id',
            false
        );
        $this->assertNotEmpty($files, 'ICS attachment not found in file storage.');

        $icsfile = reset($files); // First (and usually only) file.
        $this->assertInstanceOf(\stored_file::class, $icsfile);
        $this->assertEquals('booking.ics', $icsfile->get_filename());
        $this->assertEquals('text/calendar', $icsfile->get_mimetype());

        // Get content as string.
        $content = $icsfile->get_content();
        $this->assertNotEmpty($content, 'ICS file content is empty.');

        // Now you can assert ICS internals.
        $this->assertStringContainsString('BEGIN:VCALENDAR', $content);
        $this->assertStringContainsString('BEGIN:VEVENT', $content);
        $this->assertStringContainsString('SUMMARY:', $content);
        $this->assertStringContainsString('ATTENDEE', $content);
        // It should have REQUEST method as user booked the option.
        $this->assertStringContainsString('METHOD:CANCEL', $content);
    }

    /**
     * Data provider for test_ical_class.
     *
     * @return array
     */
    public static function ical_class_provider(): array {
        return [
            'Option with single date' => [
                1, // Number of dates in the booking option.
            ],
            'Option with double dates' => [
                2,
            ],
            'Option with triple dates' => [
                3,
            ],
        ];
    }
}
