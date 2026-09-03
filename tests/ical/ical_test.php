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

use mod_booking\tests\booking_advanced_testcase;
use context_system;
use stdClass;
use mod_booking_generator;
use mod_booking\option\fields_info;
use mod_booking\bo_availability\bo_info;
use mod_booking\placeholders\placeholders_info;
use tool_mocktesttime\time_mock;

/**
 * Tests for ical.
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2025 Mahdi Poustini
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class ical_test extends booking_advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
    }

    /**
     * Setup environment.
     * @param int $numberofdatesinoption
     * @param array $extrarecordfields additional fields for the booking option record
     * @return array
     */
    protected function setup_environment($numberofdatesinoption = 1, array $extrarecordfields = []) {
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
        foreach ($extrarecordfields as $key => $value) {
            if ($value === null) {
                unset($record->{$key});
                continue;
            }
            $record->{$key} = $value;
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
     * The iTIP method depends on the number of dates of the option: A METHOD:REQUEST may only carry
     * one single event (RFC 5546, all VEVENTs of a REQUEST must have the same UID), Outlook imports
     * just the first event of a REQUEST with several events. So an option with one date is sent as
     * REQUEST (with the attendee, so that the mail client offers accept/decline), an option with
     * several dates as PUBLISH (without attendee, which is not allowed in a PUBLISH), which every
     * client imports completely. A cancellation is always a CANCEL.
     *
     * @covers \mod_booking\ical::get_method
     * @covers \mod_booking\ical::get_attachments
     * @dataProvider ical_method_provider
     * @param int $numberofdates
     * @param string $expectedmethod
     * @return void
     */
    public function test_ical_method_depends_on_number_of_dates(int $numberofdates, string $expectedmethod): void {
        $env = $this->setup_environment($numberofdates);
        $option = $env['option'];
        $student1 = $env['users']['student1'];
        $this->resetAfterTest();

        $optionsettings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($optionsettings->cmid);
        $bookingmanager = $bookingsettings->bookingmanageruser;

        // Creation of the events.
        $ical = new ical($bookingsettings, $optionsettings, $student1, $bookingmanager, false);
        $this->assertEquals($expectedmethod, $ical->get_method(false));
        $this->assertEquals(ical::METHOD_CANCEL, $ical->get_method(true));

        $attachments = $ical->get_attachments(false);
        $file = file_get_contents($attachments['booking.ics']);
        $unfolded = preg_replace("/\r\n[ \t]/", '', $file);

        $this->assertStringContainsString("\r\nMETHOD:{$expectedmethod}\r\n", $file);
        // All dates are in the file, no matter which method is used.
        $this->assertEquals($numberofdates, substr_count($file, 'BEGIN:VEVENT'));
        $this->assertEquals($numberofdates, substr_count($file, 'END:VEVENT'));
        // Every event has its own UID.
        $this->assertEquals($numberofdates, preg_match_all('/^UID:.+$/m', $unfolded, $uids));
        $this->assertCount($numberofdates, array_unique($uids[0]));
        // The organizer is mandatory for every method.
        $this->assertEquals($numberofdates, substr_count($file, 'ORGANIZER;'));
        $this->assertStringNotContainsString('STATUS:CANCELLED', $file);

        if ($expectedmethod === ical::METHOD_REQUEST) {
            // The meeting request asks the user to accept or decline.
            $this->assertEquals(1, substr_count($unfolded, 'ATTENDEE;'));
            $this->assertStringContainsString('PARTSTAT=NEEDS-ACTION', $unfolded);
            $this->assertStringContainsString('MAILTO:' . $student1->email, $unfolded);
        } else {
            // A PUBLISH must not have attendees.
            $this->assertStringNotContainsString('ATTENDEE', $file);
            $this->assertStringNotContainsString('MAILTO:' . $student1->email, $unfolded);
        }

        // Cancellation of the events: always CANCEL, the attendee declines every date.
        $ical = new ical($bookingsettings, $optionsettings, $student1, $bookingmanager, false);
        $attachments = $ical->get_attachments(true);
        $file = file_get_contents($attachments['booking.ics']);
        $unfolded = preg_replace("/\r\n[ \t]/", '', $file);
        $this->assertStringContainsString("\r\nMETHOD:CANCEL\r\n", $file);
        $this->assertStringNotContainsString('METHOD:PUBLISH', $file);
        $this->assertEquals($numberofdates, substr_count($file, 'BEGIN:VEVENT'));
        $this->assertEquals($numberofdates, substr_count($unfolded, 'ATTENDEE;'));
        $this->assertEquals($numberofdates, substr_count($file, 'STATUS:CANCELLED'));
    }

    /**
     * Two time slots on the same day (the reported case) are two events with different UIDs and
     * different times, sent as PUBLISH.
     *
     * @covers \mod_booking\ical::get_method
     * @covers \mod_booking\ical::get_attachments
     * @return void
     */
    public function test_ical_two_slots_on_the_same_day(): void {
        $day = strtotime('20 June 2050 00:00 UTC');
        $env = $this->setup_environment(2, [
            'coursestarttime_0' => $day + 9 * 3600,
            'courseendtime_0' => $day + 10 * 3600,
            'coursestarttime_1' => $day + 14 * 3600,
            'courseendtime_1' => $day + 15 * 3600,
        ]);
        $option = $env['option'];
        $student1 = $env['users']['student1'];
        $this->resetAfterTest();

        $optionsettings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($optionsettings->cmid);
        $bookingmanager = $bookingsettings->bookingmanageruser;

        $ical = new ical($bookingsettings, $optionsettings, $student1, $bookingmanager, false);
        $this->assertCount(2, $ical->get_times());
        $this->assertEquals(ical::METHOD_PUBLISH, $ical->get_method());

        $attachments = $ical->get_attachments(false);
        $file = file_get_contents($attachments['booking.ics']);
        $unfolded = preg_replace("/\r\n[ \t]/", '', $file);

        $this->assertStringContainsString("\r\nMETHOD:PUBLISH\r\n", $file);
        $this->assertEquals(2, substr_count($file, 'BEGIN:VEVENT'));
        $this->assertEquals(2, preg_match_all('/^DTSTART:(.+)$/m', $unfolded, $starts));
        $this->assertEquals(['20500620T090000Z', '20500620T140000Z'], array_map('trim', $starts[1]));
        $this->assertEquals(2, preg_match_all('/^UID:(.+)$/m', $unfolded, $uids));
        $this->assertNotEquals(trim($uids[1][0]), trim($uids[1][1]));
        $this->assertStringNotContainsString('ATTENDEE', $file);
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

        // Order of messages is not guaranteed, so we check that the recipient is one of the two students.
        foreach ($messages as $msg) {
            $this->assertEquals('mod_booking', $msg->component);
            $this->assertEquals(true, in_array($msg->useridto, [$student1->id, $student2->id]));
            $this->assertEquals('Test', $msg->subject);
        }

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
        // Only the title is changed. The date keys of the creation record must not be submitted again:
        // the existing date is merged into the form data anyway, so submitting optiondateid_0 = 0 on
        // top would create a second date and the option would not be a meeting request any more.
        foreach (['optiondateid_0', 'daystonotify_0', 'coursestarttime_0', 'courseendtime_0'] as $key) {
            unset($record->{$key});
        }
        booking_option::update($record);
        singleton_service::destroy_booking_option_singleton($option->id);
        $this->assertEquals(1, $DB->count_records('booking_optiondates', ['optionid' => $option->id]));

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

        // Order of messages is not guaranteed, so we check that the recipient is one of the two students.
        foreach ($messages as $msg) {
            $this->assertEquals('mod_booking', $msg->component);
            $this->assertEquals(true, in_array($msg->useridto, [$student1->id, $student2->id]));
            $this->assertEquals('Test', $msg->subject);
        }

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
        $this->assertEquals(1, substr_count($content, 'BEGIN:VEVENT'));
        $this->assertStringContainsString('SUMMARY:New booking option text', $content);
        $this->assertStringContainsString('ATTENDEE', $content);
        // It should have REQUEST method as the option has one single date.
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

        // Order of messages is not guaranteed, so we check that the recipient is one of the two students.
        foreach ($messages as $msg) {
            $this->assertEquals('mod_booking', $msg->component);
            $this->assertEquals(true, in_array($msg->useridto, [$student1->id, $student2->id]));
            $this->assertEquals('Test', $msg->subject);
        }

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
     * Create the booking option custom field which holds the user defined ical description and
     * configure it in the plugin settings.
     *
     * @param string $shortname
     * @return void
     */
    protected function create_ical_description_customfield(string $shortname): void {
        $categorydata = new stdClass();
        $categorydata->name = 'icaldescriptioncat';
        $categorydata->component = 'mod_booking';
        $categorydata->area = 'booking';
        $categorydata->itemid = 0;
        $categorydata->contextid = context_system::instance()->id;
        $bookingcat = $this->getDataGenerator()->create_custom_field_category((array) $categorydata);
        $bookingcat->save();

        $fielddata = new stdClass();
        $fielddata->categoryid = $bookingcat->get('id');
        $fielddata->name = 'Ical description';
        $fielddata->shortname = $shortname;
        // The templates are usually longer texts, so admins use a textarea for them.
        $fielddata->type = 'textarea';
        $fielddata->configdata = "";
        $bookingfield = $this->getDataGenerator()->create_custom_field((array) $fielddata);
        $bookingfield->save();

        set_config('icaldescriptionfield', $shortname, 'booking');
    }

    /**
     * Return the (unfolded) value of a property of the generated ics file.
     *
     * @param string $file content of the ics file
     * @param string $property e.g. DESCRIPTION or X-ALT-DESC
     * @return string
     */
    protected function get_ics_property(string $file, string $property): string {
        // Unfold the file first, folded lines start with a space or a tab.
        $unfolded = preg_replace("/\r\n[ \t]/", '', $file);
        $matches = [];
        $found = preg_match('/^' . preg_quote($property, '/') . '[^:]*:(.*)$/m', $unfolded, $matches);
        $this->assertEquals(1, $found, 'The ics file does not contain the property ' . $property);
        return rtrim($matches[1], "\r");
    }

    /**
     * The description of the ics file can be defined by the admin via a booking option custom field.
     * This test makes sure that the placeholders used in such a template are rendered and that the
     * result is correctly formatted for the ics file - for the plain text DESCRIPTION as well as for
     * the HTML in X-ALT-DESC.
     *
     * @covers \mod_booking\ical
     * @covers \mod_booking\output\description\description_ical
     * @return void
     */
    public function test_ical_description_with_placeholders_from_custom_field(): void {
        global $CFG, $DB;

        $this->resetAfterTest();
        placeholders_info::$placeholders = [];
        $this->setAdminUser();

        /* The description is run through the filters, which on some sites includes urltolink.
        We switch it off to test the linkifying of the ical class itself. */
        filter_set_global_state('urltolink', TEXTFILTER_DISABLED);

        $this->create_ical_description_customfield('icaldescription');

        // The placeholders {dates} and {location} deliver values containing commas and
        // {gotobookingoption} delivers a ready made link. The last paragraph holds a bare url
        // which has to be linkified.
        $template = '<p>Dates: {dates}</p>'
            . '<p>Location: {location}</p>'
            . '<p>Booking option: {gotobookingoption}</p>'
            . '<p>Info: https://www.example.com/moodle/info.php</p>';

        $env = $this->setup_environment(1, [
            // Importing would prefill the custom fields from the (still empty) stored values.
            'importing' => null,
            'customfield_icaldescription_editor' => $template,
        ]);
        $option = $env['option'];
        $student1 = $env['users']['student1'];

        // Set the location directly. Depending on whether local_entities is installed, the location
        // of an option comes from the entity instead of the option form.
        $DB->set_field('booking_options', 'location', 'Hauptstrasse 1, 1010 Vienna', ['id' => $option->id]);

        // Make sure the option settings are read freshly, including the custom field we just set.
        booking_option::purge_cache_for_option($option->id);
        singleton_service::destroy_instance();
        $optionsettings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($optionsettings->cmid);

        $ical = new ical($bookingsettings, $optionsettings, $student1, $bookingsettings->bookingmanageruser, false);
        $attachments = $ical->get_attachments(false);
        $file = file_get_contents($attachments['booking.ics']);

        $description = $this->get_ics_property($file, 'DESCRIPTION');
        $htmldescription = $this->get_ics_property($file, 'X-ALT-DESC');

        // The user defined template is used instead of the default one.
        $this->assertStringContainsString('Dates:', $description);
        $this->assertStringContainsString('Location:', $description);
        $this->assertStringContainsString('Booking option:', $description);

        // The placeholders have been replaced.
        $this->assertStringNotContainsString('{dates}', $description);
        $this->assertStringNotContainsString('{location}', $description);
        $this->assertStringNotContainsString('{gotobookingoption}', $description);
        $this->assertStringNotContainsString('{dates}', $htmldescription);
        $this->assertStringNotContainsString('{location}', $htmldescription);
        $this->assertStringNotContainsString('{gotobookingoption}', $htmldescription);

        // The dates of the option are rendered, at least the year has to be there.
        $this->assertStringContainsString('2050', $description);

        // The location is rendered and its comma is escaped as required by RFC 5545.
        $this->assertStringContainsString('Hauptstrasse 1\, 1010 Vienna', $description);

        // No comma, semicolon or line break may remain unescaped in the plain text description.
        $this->assertDoesNotMatchRegularExpression('/(?<!\\\\)[,;]/', $description);
        $this->assertStringNotContainsString("\n", $description);

        // The link of {gotobookingoption} has to work. In the plain text description the html
        // entities have to be decoded, otherwise the url parameters are broken.
        $this->assertStringContainsString('/mod/booking/view.php?id=', $description);
        $this->assertStringContainsString('&optionid=' . $option->id, $description);
        $this->assertStringNotContainsString('&amp;', $description);

        // In the html description the link of the placeholder must stay untouched. Nesting anchors
        // into anchors would destroy the description in mail clients like Outlook.
        $this->assertStringNotContainsString('<a href="<a', $htmldescription);
        $this->assertStringContainsString('whichview=showonlyone">', $htmldescription);

        // The bare url of the last paragraph is still turned into a link.
        $this->assertStringContainsString('<a href="https://www.example.com/moodle/info.php">Link</a>', $htmldescription);

        // Which means we have exactly two links: the placeholder one and the linkified bare url.
        $this->assertEquals(2, substr_count($htmldescription, '<a '));
        $this->assertEquals(2, substr_count($htmldescription, '</a>'));

        $optionurl = $CFG->wwwroot . '/mod/booking/view.php?id=' . $optionsettings->cmid
            . '&optionid=' . $option->id . '&whichview=showonlyone';

        // This is what clients showing the plain text description (e.g. the calendar app of Apple)
        // end up with after unescaping the value: readable text and a working link.
        $unescaped = str_replace(['\n', '\,', '\;', '\\\\'], ["\n", ',', ';', '\\'], $description);
        $this->assertStringContainsString('Location: Hauptstrasse 1, 1010 Vienna', $unescaped);
        $this->assertStringContainsString($optionurl, $unescaped);

        // And this is what clients preferring the html description (e.g. Outlook) end up with: valid
        // anchors, the first one being the link of the {gotobookingoption} placeholder.
        $anchors = [];
        preg_match_all('/<a href="([^"]*)">/', $htmldescription, $anchors);
        $this->assertCount(2, $anchors[1]);
        $this->assertEquals($optionurl, html_entity_decode($anchors[1][0]));
        $this->assertEquals('https://www.example.com/moodle/info.php', $anchors[1][1]);

        // No physical line of the file may be longer than 75 octets, otherwise strict clients
        // refuse to read it.
        foreach (explode("\r\n", $file) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line), 'Line is too long: ' . $line);
        }
    }

    /**
     * An ical is always created for one specific user. So user related placeholders of a user
     * defined description have to be rendered for the receiving user and not for the user (or the
     * cron job) triggering the mail - even when several icals are created within the same request.
     *
     * @covers \mod_booking\ical
     * @covers \mod_booking\output\description\description_base
     * @return void
     */
    public function test_ical_description_placeholders_are_rendered_for_the_receiving_user(): void {
        $this->resetAfterTest();
        placeholders_info::$placeholders = [];
        $this->setAdminUser();

        $this->create_ical_description_customfield('icaldescription');

        $template = '<p>Hello {firstname} {lastname}</p><p>Details: {bookingoptiondetaillink}</p>';

        $env = $this->setup_environment(1, [
            // Importing would prefill the custom fields from the (still empty) stored values.
            'importing' => null,
            'customfield_icaldescription_editor' => $template,
        ]);
        $option = $env['option'];
        $student1 = $env['users']['student1'];
        $student2 = $env['users']['student2'];

        booking_option::purge_cache_for_option($option->id);
        singleton_service::destroy_instance();
        $optionsettings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($optionsettings->cmid);

        // The admin user is the one triggering the mails, both icals are created in one request.
        $descriptions = [];
        foreach ([$student1, $student2] as $student) {
            $ical = new ical($bookingsettings, $optionsettings, $student, $bookingsettings->bookingmanageruser, false);
            $attachments = $ical->get_attachments(false);
            $descriptions[$student->id] = $this->get_ics_property(
                file_get_contents($attachments['booking.ics']),
                'DESCRIPTION'
            );
        }

        foreach ([$student1, $student2] as $student) {
            $description = $descriptions[$student->id];
            $this->assertStringContainsString('Hello ' . $student->firstname . ' ' . $student->lastname, $description);
            $this->assertStringNotContainsString('Admin', $description);
            /* The link to the option is not user specific: optionview.php always shows the page for
            the user opening it, so it must not carry a userid. */
            $this->assertStringContainsString('/mod/booking/optionview.php?optionid=' . $option->id, $description);
            $this->assertStringNotContainsString('userid=', $description);
        }
    }

    /**
     * The description of an ical has to be rendered in the language of the receiving user, which
     * means that the filters (e.g. the multilang filter) have to be applied to the user defined
     * template as well.
     *
     * @covers \mod_booking\ical
     * @covers \mod_booking\output\description\description_base
     * @return void
     */
    public function test_ical_description_is_rendered_in_the_language_of_the_user(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        filter_set_global_state('multilang', TEXTFILTER_ON);

        $files = $this->render_icals_for_an_english_and_a_german_user(
            '<p><span lang="en" class="multilang">English description</span>'
            . '<span lang="de" class="multilang">Deutsche Beschreibung</span></p>'
        );
        $descriptions = [
            'en' => $this->get_ics_property($files['en'], 'DESCRIPTION'),
            'de' => $this->get_ics_property($files['de'], 'DESCRIPTION'),
        ];

        $this->assertStringContainsString('English description', $descriptions['en']);
        $this->assertStringNotContainsString('Deutsche Beschreibung', $descriptions['en']);

        $this->assertStringContainsString('Deutsche Beschreibung', $descriptions['de']);
        $this->assertStringNotContainsString('English description', $descriptions['de']);

        // The language of the current user has to be restored afterwards.
        $this->assertEquals('en', current_language());
    }

    /**
     * Admins usually write their multilingual templates with the {mlang} syntax of the additional
     * filter_multilang2 plugin. Those tags must survive the rendering of the placeholders, and if
     * the filter is installed, they have to be resolved for the language of the receiving user.
     *
     * @covers \mod_booking\ical
     * @covers \mod_booking\output\description\description_base
     * @covers \mod_booking\placeholders\placeholders_info
     * @return void
     */
    public function test_ical_description_supports_mlang_tags(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $multilang2isinstalled = array_key_exists('multilang2', \core_component::get_plugin_list('filter'));
        if ($multilang2isinstalled) {
            filter_set_global_state('multilang2', TEXTFILTER_ON);
        }

        // The placeholder inside the tags has to be replaced in both language sections.
        $files = $this->render_icals_for_an_english_and_a_german_user(
            '<p>{mlang de}Deutscher Text fuer {firstname}{mlang}{mlang en}English text for {firstname}{mlang}</p>'
        );
        $descriptions = [
            'en' => $this->get_ics_property($files['en'], 'DESCRIPTION'),
            'de' => $this->get_ics_property($files['de'], 'DESCRIPTION'),
        ];

        if (!$multilang2isinstalled) {
            /* Without the filter we can only check what mod_booking is responsible for: the tags
            have to reach the filter chain unharmed, so that they can be resolved on the sites
            where filter_multilang2 is installed. */
            foreach ($descriptions as $description) {
                $this->assertMatchesRegularExpression('/{mlang de}Deutscher Text fuer \w+{mlang}/u', $description);
                $this->assertMatchesRegularExpression('/{mlang en}English text for \w+{mlang}/u', $description);
            }
            return;
        }

        $this->assertStringContainsString('English text for', $descriptions['en']);
        $this->assertStringNotContainsString('Deutscher Text', $descriptions['en']);
        $this->assertStringNotContainsString('{mlang', $descriptions['en']);

        $this->assertStringContainsString('Deutscher Text fuer', $descriptions['de']);
        $this->assertStringNotContainsString('English text', $descriptions['de']);
        $this->assertStringNotContainsString('{mlang', $descriptions['de']);
    }

    /**
     * Not only the description, also the title and the location of an option can be written in
     * several languages. They have to be resolved for the language of the receiving user too, and
     * they have to be escaped as required by RFC 5545.
     *
     * @covers \mod_booking\ical
     * @return void
     */
    public function test_ical_summary_and_location_are_localized_and_escaped(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $multilang2isinstalled = array_key_exists('multilang2', \core_component::get_plugin_list('filter'));
        if (!$multilang2isinstalled) {
            $this->markTestSkipped('The filter_multilang2 plugin is not installed.');
        }
        filter_set_global_state('multilang2', TEXTFILTER_ON);
        /* Title and location are strings, not content. Like everywhere else in moodle they are
        rendered with format_string, which only applies the filters the admin has set to
        "content and headings" (Site administration / Plugins / Filters / Manage filters). */
        filter_set_applies_to_strings('multilang2', true);

        // Take the location of the option itself for the LOCATION property.
        set_config('icalfieldlocation', 2, 'booking');

        $files = $this->render_icals_for_an_english_and_a_german_user(
            '<p>Some description</p>',
            ['text' => '{mlang de}Deutscher Titel{mlang}{mlang en}English title{mlang}'],
            'Hauptstrasse 1, 1010 Vienna'
        );

        // The title of the option is resolved for the language of the user.
        $this->assertEquals('English title', $this->get_ics_property($files['en'], 'SUMMARY'));
        $this->assertEquals('Deutscher Titel', $this->get_ics_property($files['de'], 'SUMMARY'));

        foreach (['en', 'de'] as $language) {
            // The comma of the location has to be escaped, otherwise strict clients cut it off.
            $this->assertEquals(
                'Hauptstrasse 1\, 1010 Vienna',
                $this->get_ics_property($files[$language], 'LOCATION')
            );

            // The attendee line names the language of the receiving user.
            $unfolded = preg_replace("/\r\n[ \t]/", '', $files[$language]);
            $this->assertStringContainsString('LANGUAGE=' . $language . ':MAILTO:', $unfolded);

            // Every line break of the file has to be a CRLF, a single LF makes the file invalid.
            $this->assertDoesNotMatchRegularExpression("/(?<!\r)\n/", $files[$language]);
        }
    }

    /**
     * Render the ical description of one and the same booking option for a user using english and
     * for a user using german, both within the same request.
     *
     * @param string $template the user defined template stored in the custom field
     * @param array $extrarecordfields additional fields for the booking option record
     * @param ?string $location written directly to the option, as it may come from an entity
     * @return array keys 'en' and 'de', values are the content of the ics file
     */
    protected function render_icals_for_an_english_and_a_german_user(
        string $template,
        array $extrarecordfields = [],
        ?string $location = null
    ): array {
        global $CFG, $DB;

        placeholders_info::$placeholders = [];

        /* Create a minimal german language pack, otherwise the language cannot be forced. It lives
        in the dataroot of the test run and is removed together with it. */
        $langdir = $CFG->dataroot . '/lang/de';
        make_writable_directory($langdir);
        file_put_contents($langdir . '/langconfig.php', '<?php $string[\'thislanguage\'] = \'Deutsch\';');
        get_string_manager()->reset_caches();

        $this->create_ical_description_customfield('icaldescription');

        $env = $this->setup_environment(1, $extrarecordfields + [
            // Importing would prefill the custom fields from the (still empty) stored values.
            'importing' => null,
            'customfield_icaldescription_editor' => $template,
        ]);
        $option = $env['option'];
        $users = ['en' => $env['users']['student1'], 'de' => $env['users']['student2']];
        $DB->set_field('user', 'lang', 'de', ['id' => $users['de']->id]);
        $users['de'] = $DB->get_record('user', ['id' => $users['de']->id]);

        if ($location !== null) {
            $DB->set_field('booking_options', 'location', $location, ['id' => $option->id]);
        }

        booking_option::purge_cache_for_option($option->id);
        singleton_service::destroy_instance();
        $optionsettings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($optionsettings->cmid);

        $files = [];
        foreach ($users as $language => $user) {
            $ical = new ical($bookingsettings, $optionsettings, $user, $bookingsettings->bookingmanageruser, false);
            $attachments = $ical->get_attachments(false);
            $files[$language] = file_get_contents($attachments['booking.ics']);
        }

        return $files;
    }

    /**
     * Data provider for test_ical_method_depends_on_number_of_dates.
     *
     * @return array
     */
    public static function ical_method_provider(): array {
        return [
            'Option with single date is a meeting request' => [
                1, // Number of dates in the booking option.
                ical::METHOD_REQUEST,
            ],
            'Option with double dates is published' => [
                2,
                ical::METHOD_PUBLISH,
            ],
            'Option with triple dates is published' => [
                3,
                ical::METHOD_PUBLISH,
            ],
        ];
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
