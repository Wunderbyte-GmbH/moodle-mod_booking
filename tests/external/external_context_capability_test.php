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
 * Tests for the context validation and capability checks of the external services.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\external\bookings;
use mod_booking\external\bookit;
use mod_booking\external\get_booking_option_description;
use mod_booking\external\get_performance_chart;
use mod_booking\external\optiontemplate;
use mod_booking\external\search_booking_options;
use mod_booking\external\search_courses;
use mod_booking\external\search_teachers;
use mod_booking\external\search_templates;
use mod_booking\external\search_users;
use mod_booking\external\toggle_notify_user;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use moodle_exception;
use required_capability_exception;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * PHPUnit tests for the security hardening of the external services: every service
 * has to validate its execution context (which includes the login and course access
 * check) and enforce the required capabilities before processing the request.
 *
 * @covers \mod_booking\external\bookings
 * @covers \mod_booking\external\bookit
 * @covers \mod_booking\external\get_booking_option_description
 * @covers \mod_booking\external\get_performance_chart
 * @covers \mod_booking\external\optiontemplate
 * @covers \mod_booking\external\search_booking_options
 * @covers \mod_booking\external\search_courses
 * @covers \mod_booking\external\search_teachers
 * @covers \mod_booking\external\search_templates
 * @covers \mod_booking\external\search_users
 * @covers \mod_booking\external\toggle_notify_user
 * @covers \mod_booking\permissions
 */
final class external_context_capability_test extends booking_advanced_testcase {
    /**
     * Creates a course with a booking instance and one option, an enrolled student1
     * (booked into the option), an enrolled student2, an enrolled editingteacher
     * and one user who is not enrolled at all.
     *
     * @return array [$course, $booking, $option, $student1, $student2, $teacher, $outsider]
     */
    private function create_environment(): array {
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $outsider = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student1->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $bookingmanager = $this->getDataGenerator()->create_user();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'bookingmanager' => $bookingmanager->username,
            'showinapi' => 1,
        ]);

        /** @var mod_booking_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $generator->create_option((object)[
            'bookingid' => $booking->id,
            'courseid' => $course->id,
            'text' => 'Secured option',
            'chooseorcreatecourse' => 1,
            'maxanswers' => 10,
        ]);

        singleton_service::destroy_instance();

        // Book student1 directly (as a trainer would), forcing a verified booking.
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $bookingoption = singleton_service::get_instance_of_booking_option($settings->cmid, $option->id);
        $bookingoption->user_submit_response($student1, 0, 0, 0, MOD_BOOKING_VERIFIED);
        singleton_service::destroy_booking_answers($option->id);

        return [$course, $booking, $option, $student1, $student2, $teacher, $outsider];
    }

    /**
     * The raw option record is only handed out to users who may manage option templates.
     */
    public function test_optiontemplate_requires_manageoptiontemplates(): void {
        [, , $option, $student1] = $this->create_environment();

        $this->setAdminUser();
        $result = optiontemplate::execute($option->id);
        $this->assertSame('Secured option', $result['name']);

        $this->setUser($student1);
        $this->expectException(required_capability_exception::class);
        optiontemplate::execute($option->id);
    }

    /**
     * Without access to the course of the booking instance, the notification list cannot be toggled.
     */
    public function test_toggle_notify_user_requires_course_access(): void {
        [, , $option, , , , $outsider] = $this->create_environment();

        $this->setUser($outsider);
        $this->expectException(moodle_exception::class);
        toggle_notify_user::execute((int)$outsider->id, (int)$option->id);
    }

    /**
     * Enrolled users can toggle the notification list for themselves, but not for other users.
     */
    public function test_toggle_notify_user_self_allowed_others_denied(): void {
        [, , $option, , $student2, , ] = $this->create_environment();

        $this->setUser($student2);

        // Toggling for yourself puts you on the notification list.
        $result = toggle_notify_user::execute((int)$student2->id, (int)$option->id);
        $this->assertSame(1, $result['status']);

        // Toggling for somebody else needs mod/booking:subscribeusers.
        $other = $this->getDataGenerator()->create_user();
        $result = toggle_notify_user::execute((int)$other->id, (int)$option->id);
        $this->assertSame(0, $result['status']);
        $this->assertNotEmpty($result['error']);
    }

    /**
     * The bookings service requires course access and only exposes the booked users
     * (personal data incl. e-mail addresses) to users with mod/booking:readresponses.
     */
    public function test_bookings_requires_course_access_and_gates_user_data(): void {
        [$course, , , $student1, , , $outsider] = $this->create_environment();

        // An enrolled student gets the option list, but never the booked users.
        $this->setUser($student1);
        singleton_service::destroy_instance();
        $result = bookings::execute((string)$course->id, '1', '0');
        $this->assertCount(1, $result);
        $this->assertSame('Secured option', $result[0]['options'][0]['text']);
        $this->assertSame([], $result[0]['options'][0]['users']);

        // A user with readresponses (admin) gets the booked users.
        $this->setAdminUser();
        singleton_service::destroy_instance();
        $result = bookings::execute((string)$course->id, '1', '0');
        $userids = array_column($result[0]['options'][0]['users'], 'id');
        $this->assertContains((int)$student1->id, array_map('intval', $userids));

        // A user without access to the course is rejected.
        $this->setUser($outsider);
        $this->expectException(moodle_exception::class);
        bookings::execute((string)$course->id, '1', '0');
    }

    /**
     * The autocomplete search backends are restricted to users
     * who may edit booking options somewhere in the system.
     */
    public function test_search_backends_require_option_editing_capability(): void {
        [, , , $student1, , $teacher, ] = $this->create_environment();

        $this->setUser($student1);
        $backends = [
            fn() => search_users::execute('someone'),
            fn() => search_teachers::execute('someone'),
            fn() => search_courses::execute('somecourse'),
            fn() => search_templates::execute('sometemplate'),
            fn() => search_booking_options::execute('someoption'),
        ];
        foreach ($backends as $backend) {
            try {
                $backend();
                $this->fail('A student must not be able to use the search backends.');
            } catch (moodle_exception $e) {
                $this->assertStringContainsString('nopermissions', $e->errorcode);
            }
        }

        // An editingteacher (mod/booking:addeditownoption in the module context) may search.
        $this->setUser($teacher);
        $result = search_teachers::execute('someone');
        $this->assertArrayHasKey('list', $result);
    }

    /**
     * The option description rendered for another user (incl. their booking status)
     * is only available with the book for others rights.
     */
    public function test_get_booking_option_description_gates_foreign_user(): void {
        [, , $option, $student1, $student2, , ] = $this->create_environment();

        $this->setUser($student1);
        $result = get_booking_option_description::execute((int)$option->id, (int)$student1->id);
        $this->assertNotEmpty($result['content']);

        $this->expectException(required_capability_exception::class);
        get_booking_option_description::execute((int)$option->id, (int)$student2->id);
    }

    /**
     * The performance chart is part of the performance tool and needs its view capability.
     */
    public function test_get_performance_chart_requires_viewperformance(): void {
        [, , , $student1] = $this->create_environment();

        $this->setUser($student1);
        $this->expectException(required_capability_exception::class);
        get_performance_chart::execute('somevalue');
    }

    /**
     * Booking via the webservice requires access to the course of the booking instance.
     */
    public function test_bookit_requires_course_access(): void {
        [, , $option, , , , $outsider] = $this->create_environment();

        $this->setUser($outsider);
        $this->expectException(moodle_exception::class);
        bookit::execute('option', (int)$option->id, (int)$outsider->id, '');
    }
}
