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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * booking_update_instance() must accept a DB-shaped record (comma-separated string fields).
 *
 * Live-reproduced crash: passing a {booking} record straight from the DB (as e.g. the
 * configure-instance skill does) threw "count(): Argument #1 ($value) must be of type
 * Countable|array, string given" on showviews/categoryid, because those fields are arrays
 * in form submissions but imploded strings in the DB.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::booking_update_instance
 */
final class lib_booking_update_instance_test extends booking_advanced_testcase {
    /**
     * A record fetched via $DB->get_record('booking') updates without a TypeError and the
     * already-imploded string fields are kept as they are.
     *
     * @return void
     */
    public function test_update_instance_accepts_db_shaped_record(): void {
        global $DB;

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $bookingmanager = $this->getDataGenerator()->create_user();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'name' => 'DB record booking',
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
            'course' => $course->id,
            'bookingmanager' => $bookingmanager->username,
        ]);

        // In the DB these fields are comma-separated strings, never arrays.
        $DB->set_field('booking', 'showviews', 'mybooking,showall', ['id' => $booking->id]);
        $DB->set_field('booking', 'categoryid', '5,7', ['id' => $booking->id]);

        $record = $DB->get_record('booking', ['id' => $booking->id], '*', MUST_EXIST);
        $this->assertIsString($record->showviews, 'Precondition: showviews is a string in the DB.');
        $record->instance = $record->id;
        $record->name = 'DB record booking (renamed)';

        // Must not throw a TypeError on the string-typed fields.
        $result = booking_update_instance($record);
        $this->assertTrue((bool)$result);

        $saved = $DB->get_record('booking', ['id' => $booking->id], '*', MUST_EXIST);
        $this->assertSame('DB record booking (renamed)', $saved->name);
        // The already-imploded strings are preserved, matching the sibling fields' behaviour.
        $this->assertSame('mybooking,showall', $saved->showviews);
        $this->assertSame('5,7', $saved->categoryid);
    }
}
