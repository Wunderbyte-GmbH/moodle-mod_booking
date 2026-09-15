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
 * Tests for the delete_booking_option external service.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use mod_booking\external\delete_booking_option;
use mod_booking_generator;
use moodle_exception;
use required_capability_exception;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * PHPUnit tests for the delete_booking_option webservice, which is called by the
 * delete confirmation modal (the replacement of the old action=deletebookingoption
 * URL flow on report.php).
 *
 * @covers \mod_booking\external\delete_booking_option
 */
final class delete_booking_option_test extends booking_advanced_testcase {
    /**
     * Creates a course, a booking module, two enrolled and booked students and one booking option.
     *
     * @return array [$course, $booking, $option, $student1, $student2]
     */
    private function create_environment(): array {
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student1->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course->id, 'student');

        $bookingmanager = $this->getDataGenerator()->create_user();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'bookingmanager' => $bookingmanager->username,
        ]);

        /** @var mod_booking_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $generator->create_option((object)[
            'bookingid' => $booking->id,
            'courseid' => $course->id,
            'text' => 'Option to delete',
            'chooseorcreatecourse' => 1,
            'maxanswers' => 10,
        ]);

        singleton_service::destroy_instance();

        // Book both students directly (as a trainer would), forcing verified bookings.
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $bookingoption = singleton_service::get_instance_of_booking_option($settings->cmid, $option->id);
        $bookingoption->user_submit_response($student1, 0, 0, 0, MOD_BOOKING_VERIFIED);
        $bookingoption->user_submit_response($student2, 0, 0, 0, MOD_BOOKING_VERIFIED);
        singleton_service::destroy_booking_answers($option->id);

        return [$course, $booking, $option, $student1, $student2];
    }

    /**
     * Deleting through the webservice removes the option and its answers and
     * triggers the bookingoption_deleted event.
     */
    public function test_delete_booking_option_deletes_option_and_answers(): void {
        global $DB;

        [, , $option] = $this->create_environment();
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $cmid = $settings->cmid;

        $this->assertCount(2, $DB->get_records('booking_answers', ['optionid' => $option->id]));

        $sink = $this->redirectEvents();
        $result = delete_booking_option::execute($cmid, $option->id);
        $result = \core_external\external_api::clean_returnvalue(delete_booking_option::execute_returns(), $result);
        $events = $sink->get_events();
        $sink->close();

        $this->assertTrue($result['success']);
        $this->assertFalse($DB->record_exists('booking_options', ['id' => $option->id]));
        $this->assertCount(0, $DB->get_records('booking_answers', ['optionid' => $option->id]));

        $deleteevents = array_filter($events, fn($event) => $event instanceof event\bookingoption_deleted);
        $this->assertCount(1, $deleteevents);
        $this->assertEquals($option->id, reset($deleteevents)->objectid);
    }

    /**
     * Without mod/booking:updatebooking the webservice refuses to delete.
     */
    public function test_delete_booking_option_requires_updatebooking_capability(): void {
        global $DB;

        [, , $option, $student1] = $this->create_environment();
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        $this->setUser($student1);

        $this->expectException(required_capability_exception::class);
        try {
            delete_booking_option::execute($settings->cmid, $option->id);
        } finally {
            // The option has to survive the refused call.
            $this->assertTrue($DB->record_exists('booking_options', ['id' => $option->id]));
        }
    }

    /**
     * The option to delete has to belong to the booking instance the capability
     * was checked on: a cmid of another instance is refused.
     */
    public function test_delete_booking_option_rejects_foreign_cmid(): void {
        global $DB;

        [$course, , $option] = $this->create_environment();

        // A second booking instance in the same course.
        $otherbooking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'bookingmanager' => $this->getDataGenerator()->create_user()->username,
        ]);
        $othercm = get_coursemodule_from_instance('booking', $otherbooking->id);

        try {
            delete_booking_option::execute((int)$othercm->id, $option->id);
            $this->fail('Expected a moodle_exception for the optionid of another instance.');
        } catch (moodle_exception $e) {
            $this->assertSame('error:optionnotinthisinstance', $e->errorcode);
        }
        // The option has to survive the refused call.
        $this->assertTrue($DB->record_exists('booking_options', ['id' => $option->id]));
    }

    /**
     * An optionid that does not exist at all is refused with its own error.
     */
    public function test_delete_booking_option_rejects_unknown_optionid(): void {
        [, , $option] = $this->create_environment();
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        try {
            delete_booking_option::execute($settings->cmid, $option->id + 12345);
            $this->fail('Expected a moodle_exception for an unknown optionid.');
        } catch (moodle_exception $e) {
            $this->assertSame('nooptionid', $e->errorcode);
        }
    }
}
