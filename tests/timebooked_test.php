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
 * Tests for correct timebooked handling in booking_option::write_user_answer_to_db().
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use mod_booking_generator;
use mod_booking\booking_bookit;
use mod_booking\booking_option;
use stdClass;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests that timebooked in mdl_booking_answers is always set to the actual
 * booking confirmation time, not the original record creation time.
 *
 * Background: records on the notify/waiting list have a timecreated that pre-dates
 * the campaign or the booking confirmation. Previously, write_user_answer_to_db()
 * copied timecreated into timebooked for those records, producing an incorrect value.
 * After the fix, timebooked is always time() at the moment of the BOOKED status
 * transition, regardless of the record's original timecreated.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class timebooked_test extends advanced_testcase {
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
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }

    /**
     * Sanity check: a fresh direct booking sets timebooked to the booking time.
     *
     * @covers \mod_booking\booking_option::write_user_answer_to_db
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_timebooked_fresh_booking(array $bdata): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;
        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($student->id, $course->id);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Fresh booking test';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->useprice = 0;
        $record->maxanswers = 4;
        $record->maxoverbooking = 0;
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('now + 3 days');
        $record->courseendtime_0 = strtotime('now + 6 days');
        $record->teachersforoption = $teacher->username;
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        $tbooking = strtotime('now + 1 hour');
        time_mock::set_mock_time($tbooking);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $option->user_submit_response($student, 0, 0, 0, MOD_BOOKING_VERIFIED);

        $answer = $DB->get_record('booking_answers', [
            'optionid' => $option1->id,
            'userid' => $student->id,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
        ]);

        $this->assertNotEmpty($answer, 'Booking answer must exist.');
        $this->assertEquals($tbooking, $answer->timebooked, 'timebooked must equal the booking time.');
        $this->assertEquals($tbooking, $answer->timecreated, 'timecreated must equal the booking time for fresh bookings.');
    }

    /**
     * Core regression test: timebooked must reflect the promotion time, not the
     * original record creation time.
     *
     * Scenario:
     * 1. At T_before: student books and lands on the waiting list
     *    (option has waitforconfirmation=1).
     * 2. At T_after (= T_before + 2 hours): admin promotes the student to BOOKED.
     *
     * Expected after fix:
     *   timebooked = T_after  (actual booking confirmation time)
     *   timecreated = T_before (original record creation time is preserved)
     *
     * Before the fix:
     *   timebooked = T_before  (copied from timecreated — wrong)
     *
     * @covers \mod_booking\booking_option::write_user_answer_to_db
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_timebooked_reflects_promotion_time_not_creation_time(array $bdata): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;
        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($student->id, $course->id);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Waitinglist promotion test';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->useprice = 0;
        $record->maxanswers = 4;
        $record->maxoverbooking = 0;
        $record->waitforconfirmation = 1; // Forces new bookings to land on the waiting list.
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('now + 3 days');
        $record->courseendtime_0 = strtotime('now + 6 days');
        $record->teachersforoption = $teacher->username;
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        $tbefore = strtotime('now');
        $tafter = strtotime('now + 2 hours');

        // Step 1: student books at T_before → goes to waiting list (waitforconfirmation=1).
        time_mock::set_mock_time($tbefore);
        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        $this->setUser($student);
        singleton_service::destroy_user($student->id);
        booking_bookit::bookit('option', $settings->id, $student->id); // Shows confirmation prompt.
        booking_bookit::bookit('option', $settings->id, $student->id); // Submits → WAITINGLIST.

        // Verify the student is on the waiting list at T_before.
        singleton_service::destroy_booking_answers($option1->id);
        $ba = singleton_service::get_instance_of_booking_answers($settings);
        $this->assertArrayHasKey(
            $student->id,
            $ba->get_usersonwaitinglist(),
            'Student must be on the waiting list after initial booking.'
        );

        $waitinganswerbeforepromotion = $DB->get_record('booking_answers', [
            'optionid' => $option1->id,
            'userid' => $student->id,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
        ]);
        $this->assertEquals(
            $tbefore,
            $waitinganswerbeforepromotion->timecreated,
            'timecreated must equal T_before when the waitinglist record was created.'
        );

        // Step 2: advance mock time to T_after and admin promotes the student to BOOKED.
        time_mock::set_mock_time($tafter);

        $this->setAdminUser();
        singleton_service::destroy_booking_option_singleton($option1->id);
        singleton_service::destroy_booking_answers($option1->id);
        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $option->user_submit_response($student, 0, 0, 0, MOD_BOOKING_VERIFIED);

        // Verify the final booking answer.
        $answer = $DB->get_record('booking_answers', [
            'optionid' => $option1->id,
            'userid' => $student->id,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
        ]);

        $this->assertNotEmpty($answer, 'Booking answer must exist after promotion.');
        $this->assertEquals(
            $tafter,
            $answer->timebooked,
            'timebooked must equal T_after (the actual promotion/booking time), not T_before.'
        );
        $this->assertEquals(
            $tbefore,
            $answer->timecreated,
            'timecreated must still equal T_before (original record creation time must be preserved).'
        );
    }

    /**
     * Verify that an explicit timebooked passed during import is preserved.
     *
     * When importing historical booking data via bookusers.php, the caller passes a
     * specific past timestamp as $timebooked. This must be stored verbatim and must NOT
     * be overwritten by time().
     *
     * @covers \mod_booking\booking_option::write_user_answer_to_db
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_timebooked_import_preserves_explicit_value(array $bdata): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;
        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($student->id, $course->id);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Import timebooked test';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->useprice = 0;
        $record->maxanswers = 4;
        $record->maxoverbooking = 0;
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('now + 3 days');
        $record->courseendtime_0 = strtotime('now + 6 days');
        $record->teachersforoption = $teacher->username;
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        // Simulate importing a booking with a historical timestamp.
        $timport = strtotime('2025-01-15 10:00:00');
        $tnow = strtotime('now + 1 hour');
        time_mock::set_mock_time($tnow);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        // Call user_submit_response with explicit $timebooked (position 7 in the signature).
        $option->user_submit_response(
            $student,
            0, // Frombookingid.
            0, // Subtractfromlimit.
            0, // Status default.
            MOD_BOOKING_VERIFIED,
            '', // Erlid.
            $timport // Timebooked: explicit import timestamp.
        );

        $answer = $DB->get_record('booking_answers', [
            'optionid' => $option1->id,
            'userid' => $student->id,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
        ]);

        $this->assertNotEmpty($answer, 'Booking answer must exist after import.');
        $this->assertEquals(
            $timport,
            $answer->timebooked,
            'timebooked must equal the explicitly passed import timestamp, not the current time.'
        );
        $this->assertEquals(
            $timport,
            $answer->timecreated,
            'timecreated must also be set to the import timestamp for historical imports.'
        );
    }

    /**
     * Data provider for timebooked tests.
     *
     * @return array
     */
    public static function booking_common_settings_provider(): array {
        $bdata = [
            'name' => 'Test Booking timebooked',
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
