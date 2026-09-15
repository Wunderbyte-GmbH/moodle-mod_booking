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
use mod_booking\output\booked_users;
use mod_booking\singleton_service;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests that the bookings tracker scopes return each booking answer / option exactly once,
 * even if another activity shares its instance id with the booking instance.
 *
 * course_modules.instance is only unique per module. A forum (or any other activity) with the
 * same instance id as the booking instance must not duplicate the rows of the tracker tables,
 * which would end up in "Duplicate value found in column id" on report2.php.
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @runInSeparateProcess
 * @runTestsInSeparateProcesses
 */
final class answers_scope_duplicate_cm_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
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
     * A forum sharing the instance id of the booking instance must not duplicate tracker rows.
     *
     * @covers \mod_booking\booking_answers\scope_base_answers::get_selectpart
     * @covers \mod_booking\booking_answers\scope_base_options::get_selectpart
     */
    public function test_scopes_ignore_course_modules_of_other_modules(): void {
        global $DB;

        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        // The forum is created first, so it gets the same instance id (1) as the booking instance below.
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id]);
        singleton_service::destroy_instance();
        $this->assertSame((int)$forum->id, (int)$booking->id, 'Test precondition: forum and booking share the instance id.');

        // Precondition: two course modules with the same instance id, in different modules.
        $this->assertSame(2, $DB->count_records('course_modules', ['instance' => $booking->id]));

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option((object)[
            'bookingid' => $booking->id,
            'text' => 'Option with colliding instance id',
            'courseid' => $course->id,
            'maxanswers' => 5,
            'optiondateid_1' => '0',
            'daystonotify_1' => '0',
            'coursestarttime_1' => strtotime('now + 1 day'),
            'courseendtime_1' => strtotime('now + 2 day'),
        ]);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boption = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $boption->user_submit_response($student, 0, 0, 0, MOD_BOOKING_VERIFIED);

        \cache::make('local_wunderbyte_table', 'cachedrawdata')->purge();
        \cache::make('mod_booking', 'bookedusertable')->purge();

        $bookedusers = new booked_users();

        // Non-aggregated scopes: exactly one row per booking answer.
        foreach (
            [
                ['systemanswers', 0],
                ['courseanswers', (int)$course->id],
                ['instanceanswers', (int)$settings->cmid],
            ] as [$scopename, $sid]
        ) {
            $table = $bookedusers->return_raw_table($scopename, $sid, MOD_BOOKING_STATUSPARAM_BOOKED);
            $this->assertCount(1, $table->rawdata, "answer duplicated in scope $scopename");
            $row = reset($table->rawdata);
            $this->assertSame((int)$settings->cmid, (int)$row->cmid, "wrong cmid in scope $scopename");
        }

        // Aggregated scopes: exactly one row per booking option, counting the answer once.
        foreach (
            [
                ['system', 0],
                ['course', (int)$course->id],
                ['instance', (int)$settings->cmid],
            ] as [$scopename, $sid]
        ) {
            $table = $bookedusers->return_raw_table($scopename, $sid, MOD_BOOKING_STATUSPARAM_BOOKED);
            $this->assertCount(1, $table->rawdata, "option duplicated in scope $scopename");
            $row = reset($table->rawdata);
            $this->assertSame((int)$settings->cmid, (int)$row->cmid, "wrong cmid in scope $scopename");
            $this->assertSame(1, (int)$row->answerscount, "wrong answers count in scope $scopename");
        }
    }
}
