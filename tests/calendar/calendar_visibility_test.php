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
 * Calendar visibility tests.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use coding_exception;
use mod_booking_generator;
use stdClass;
use tool_mocktesttime\time_mock;

/**
 * Calendar visibility tests.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class calendar_visibility_test extends advanced_testcase {
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
        parent::tearDown();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }

    /**
     * Test update of bookig option and tracking changes.
     *
     * @covers \mod_booking\event\teacher_added
     * @covers \mod_booking\booking_option::update
     * @covers \mod_booking\option\field_base::check_for_changes
     *
     * @param array $bdata
     * @throws \coding_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_visibility_of_optiondates_changes(array $bdata): void {
        global $DB;

        // Setup test data.
        $course = $this->getDataGenerator()->create_course();
        $users = [
            ['username' => 'teacher1', 'firstname' => 'Teacher', 'lastname' => '1', 'email' => 'teacher1@example.com'],
            ['username' => 'student1', 'firstname' => 'Student', 'lastname' => '1', 'email' => 'student1@sample.com'],
        ];
        $bdata['course'] = $course->id;
        $teacher1 = $this->getDataGenerator()->create_user($users[0]);
        $student1 = $this->getDataGenerator()->create_user($users[1]);

        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'my-option';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course->id;
        $record->description = 'my-description';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 08:00:00');
        $record->courseendtime_0 = strtotime('20 July 2050 09:00:00');
        $record->optiondateid_1 = "0";
        $record->daystonotify_1 = "0";
        $record->coursestarttime_1 = strtotime('21 June 2050 08:00:00');
        $record->courseendtime_1 = strtotime('21 July 2050 09:00:00');
        $record->optiondateid_2 = "0";
        $record->daystonotify_2 = "0";
        $record->coursestarttime_2 = strtotime('22 June 2050 08:00:00');
        $record->courseendtime_2 = strtotime('22 July 2050 09:00:00');
        $record->addtocalendar = 1; // So it shows up in course calendar too.
        $record->courseid = 0;

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);
        $optionid = $option->id;

        $this->setAdminUser();

        // Assert that events have been created.
        $courseevents = $DB->get_records_sql(
            "SELECT *
            FROM {event}
            WHERE component = 'mod_booking'
            AND eventtype = 'course'
            AND visible = 1
            AND uuid LIKE " . $DB->sql_concat(':optionid', "'-%'"),
            ['optionid' => $optionid]
        );
        $this->assertCount(3, $courseevents);

        $bookingoption = singleton_service::get_instance_of_booking_option($booking->cmid, $optionid);
        $bookingoption->user_submit_response($student1, 0, 0, 0, MOD_BOOKING_VERIFIED);

        // Assert that events have been created for the student.
        $userevents = $DB->get_records_sql(
            "SELECT *
            FROM {event}
            WHERE component = 'mod_booking'
            AND eventtype = 'user'
            AND visible = 1
            AND userid = :studentid",
            ['optionid' => $optionid, 'studentid' => $student1->id]
        );
        $this->assertCount(3, $userevents);

        // Make the booking option invisible.
        $record->id = $optionid;
        $record->cmid = $booking->cmid;
        $record->invisible = 1;
        booking_option::update($record);

        // Now we need to check, if the option dates are invisible too.
        $courseevents = $DB->get_records_sql(
            "SELECT *
            FROM {event}
            WHERE component = 'mod_booking'
            AND eventtype = 'course'
            AND visible = 0 -- Invisible.
            AND uuid LIKE " . $DB->sql_concat(':optionid', "'-%'"),
            ['optionid' => $optionid]
        );
        $this->assertCount(3, $courseevents);

        $userevents = $DB->get_records_sql(
            "SELECT *
            FROM {event}
            WHERE component = 'mod_booking'
            AND eventtype = 'user'
            AND visible = 0 -- Invisible.
            AND userid = :studentid",
            ['optionid' => $optionid, 'studentid' => $student1->id]
        );
        $this->assertCount(3, $userevents);

        // Make the booking option accessible via direct link only.
        $record->invisible = 2; // Accessible via direct link only.
        booking_option::update($record);

        // Now we need to check, if the option dates are invisible too.
        $courseevents = $DB->get_records_sql(
            "SELECT *
            FROM {event}
            WHERE component = 'mod_booking'
            AND eventtype = 'course'
            AND visible = 1 -- Visible.
            AND uuid LIKE " . $DB->sql_concat(':optionid', "'-%'"),
            ['optionid' => $optionid]
        );
        $this->assertCount(3, $courseevents);

        $userevents = $DB->get_records_sql(
            "SELECT *
            FROM {event}
            WHERE component = 'mod_booking'
            AND eventtype = 'user'
            AND visible = 1 -- Visible.
            AND userid = :studentid",
            ['optionid' => $optionid, 'studentid' => $student1->id]
        );
        $this->assertCount(3, $userevents);

        // Make sure that event relations are also stored in booking_userevents.
        $bookinguserevents = $DB->get_records('booking_userevents', ['optionid' => $optionid, 'userid' => $student1->id]);
        $this->assertCount(3, $bookinguserevents);

        // Let's delete an optiondate and set to invisible again.
        unset($record->optiondateid_2);
        unset($record->daystonotify_2);
        unset($record->coursestarttime_2);
        unset($record->courseendtime_2);
        $record->invisible = 1; // Invisible.
        booking_option::update($record);

        // Now we should have 2 remaining invisible course events.
        $courseevents = $DB->get_records_sql(
            "SELECT *
            FROM {event}
            WHERE component = 'mod_booking'
            AND eventtype = 'course'
            AND visible = 0 -- Invisible.
            AND uuid LIKE " . $DB->sql_concat(':optionid', "'-%'"),
            ['optionid' => $optionid]
        );
        $this->assertCount(2, $courseevents);

        // And 2 remaining invisible user events.
        $userevents = $DB->get_records_sql(
            "SELECT *
            FROM {event}
            WHERE component = 'mod_booking'
            AND eventtype = 'user'
            AND visible = 0 -- Invisible.
            AND userid = :studentid",
            ['optionid' => $optionid, 'studentid' => $student1->id]
        );
        $this->assertCount(2, $userevents);
    }

    /**
     * Data provider for booking_option_test
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    public static function booking_common_settings_provider(): array {
        $bdata = [
            'name' => 'Test Booking 1',
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
            'showviews' => ['mybooking,myoptions,optionsiamresponsiblefor,showall,showactive,myinstitution'],
        ];
        return ['bdata' => [$bdata]];
    }
}
