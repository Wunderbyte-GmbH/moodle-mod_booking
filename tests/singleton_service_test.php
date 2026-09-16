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
 * Tests for the singleton service.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests for the singleton service.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class singleton_service_test extends booking_advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        singleton_service::destroy_instance();
    }

    /**
     * A booking id without a course module must not blow up the singleton service.
     *
     * This happens with orphaned rows in booking_options, for example after a deleted
     * instance or a half deleted course. get_coursemodule_from_instance() then returns
     * false, and reading ->id on it used to hand null to booking_settings::__construct().
     *
     * @covers \mod_booking\singleton_service::get_instance_of_booking_settings_by_bookingid
     *
     * @throws \dml_exception
     */
    public function test_booking_settings_by_bookingid_returns_null_without_course_module(): void {
        global $DB;

        $this->setAdminUser();

        // An id that is guaranteed not to exist as a booking instance.
        $missingbookingid = 1 + (int) $DB->get_field_sql('SELECT COALESCE(MAX(id), 0) FROM {booking}');

        $this->assertNull(singleton_service::get_instance_of_booking_settings_by_bookingid($missingbookingid));
    }

    /**
     * An existing booking instance still resolves to its settings.
     *
     * @covers \mod_booking\singleton_service::get_instance_of_booking_settings_by_bookingid
     *
     * @throws \dml_exception
     */
    public function test_booking_settings_by_bookingid_returns_settings_for_existing_instance(): void {
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Singleton service test instance',
            'eventtype' => 'Test event',
            'bookedtext' => ['text' => 'text'],
            'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'],
            'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'],
            'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'],
            'notificationtext' => ['text' => 'text'],
            'userleave' => ['text' => 'text'],
        ]);

        $settings = singleton_service::get_instance_of_booking_settings_by_bookingid($booking->id);

        $this->assertInstanceOf(booking_settings::class, $settings);
        $this->assertEquals($booking->id, $settings->id);
        $this->assertEquals($booking->cmid, $settings->cmid);
    }
}
