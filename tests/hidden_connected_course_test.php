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

use context_course;
use context_system;
use core_external\external_api;
use mod_booking\bo_availability\conditions\alreadybooked;
use mod_booking\external\search_courses;
use mod_booking\local\connectedcourse;
use mod_booking\table\bookingoptions_wbtable;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * A hidden Moodle course can be connected to a booking option and booked, but the links to it
 * are only shown to users who may see hidden courses.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \mod_booking\local\connectedcourse::can_user_see_connected_course
 * @covers \mod_booking\booking::load_courses
 * @covers \mod_booking\external\search_courses
 * @covers \mod_booking\bo_availability\conditions\alreadybooked::render_button
 * @covers \mod_booking\table\bookingoptions_wbtable::col_course
 */
final class hidden_connected_course_test extends booking_advanced_testcase {
    /**
     * A hidden and a visible course, an editing teacher of both who may NOT see hidden courses,
     * a booking instance in a third course with an option connected to the hidden course, and a
     * student enrolled in the booking course.
     *
     * @return array [$hidden, $visible, $bookingcourse, $option, $teacher, $student]
     */
    private function create_environment(): array {
        global $DB;

        $this->setAdminUser();

        $hidden = $this->getDataGenerator()->create_course(['visible' => 0]);
        $visible = $this->getDataGenerator()->create_course();
        $bookingcourse = $this->getDataGenerator()->create_course();

        $teacher = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $hidden->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($teacher->id, $visible->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($student->id, $bookingcourse->id, 'student');

        // Editing teachers may enrol manually (that is what lists a course in the selector), but for
        // this test they must not see hidden courses.
        $editingteacherroleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        assign_capability(
            'moodle/course:viewhiddencourses',
            CAP_PROHIBIT,
            $editingteacherroleid,
            context_system::instance()->id,
            true
        );
        accesslib_clear_all_caches_for_unit_testing();

        $bookingmanager = $this->getDataGenerator()->create_user();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $bookingcourse->id,
            'bookingmanager' => $bookingmanager->username,
            'autoenrol' => 1, // Required to enrol into the connected course.
        ]);

        /** @var mod_booking_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $generator->create_option((object)[
            'bookingid' => $booking->id,
            'text' => 'Option on a hidden course',
            'chooseorcreatecourse' => 1,
            'courseid' => $hidden->id,
            'enrolmentstatus' => 2, // Enrol immediately when booked.
            'maxanswers' => 10,
        ]);

        singleton_service::destroy_instance();

        return [$hidden, $visible, $bookingcourse, $option, $teacher, $student];
    }

    /**
     * The course selector lists the hidden courses in which the user may enrol manually.
     */
    public function test_load_courses_lists_hidden_courses_for_enrollers(): void {
        [$hidden, $visible, $bookingcourse, , $teacher] = $this->create_environment();

        $this->setUser($teacher);
        $this->assertFalse(has_capability('moodle/course:viewhiddencourses', context_course::instance($hidden->id)));

        $list = booking::load_courses('')['list'];

        $this->assertArrayHasKey($hidden->id, $list);
        $this->assertSame(0, $list[$hidden->id]->visible);
        $this->assertArrayHasKey($visible->id, $list);
        $this->assertSame(1, $list[$visible->id]->visible);
        // Not the site course and not a course the teacher may not enrol into.
        $this->assertArrayNotHasKey(SITEID, $list);
        $this->assertArrayNotHasKey($bookingcourse->id, $list);
        // The "no course selected" entry is always there.
        $this->assertArrayHasKey(0, $list);

        // The search words are still applied.
        $list = booking::load_courses($hidden->shortname)['list'];
        $this->assertArrayHasKey($hidden->id, $list);
        $this->assertArrayNotHasKey($visible->id, $list);
    }

    /**
     * A user who may not enrol anywhere gets the "no course selected" entry only - and no exception.
     */
    public function test_load_courses_without_enrol_capability(): void {
        [, , , , , $student] = $this->create_environment();

        $this->setUser($student);
        $result = booking::load_courses('');

        $this->assertSame('', $result['warnings']);
        $this->assertSame([0], array_keys($result['list']));
    }

    /**
     * The webservice exposes the visibility of every course.
     */
    public function test_search_courses_returns_visible_flag(): void {
        [$hidden, $visible] = $this->create_environment();

        $this->setAdminUser();
        $result = external_api::clean_returnvalue(search_courses::execute_returns(), search_courses::execute(''));

        $byid = array_column($result['list'], null, 'id');
        $this->assertSame(0, $byid[$hidden->id]['visible']);
        $this->assertSame(1, $byid[$visible->id]['visible']);
    }

    /**
     * The helper tells for whom a link to the connected course makes sense.
     */
    public function test_can_user_see_connected_course(): void {
        global $DB;
        [$hidden, $visible, , , $teacher, $student] = $this->create_environment();
        $admin = get_admin();

        $this->assertFalse(connectedcourse::can_user_see_connected_course(0, $student->id));
        $this->assertFalse(connectedcourse::can_user_see_connected_course(999999, $admin->id));

        $this->assertTrue(connectedcourse::can_user_see_connected_course($visible->id, $student->id));
        $this->assertTrue(connectedcourse::can_user_see_connected_course($visible->id, $admin->id));

        $this->assertFalse(connectedcourse::can_user_see_connected_course($hidden->id, $student->id));
        $this->assertFalse(connectedcourse::can_user_see_connected_course($hidden->id, $teacher->id));
        $this->assertTrue(connectedcourse::can_user_see_connected_course($hidden->id, $admin->id));

        // Userid 0 means the current user.
        $this->setUser($student);
        $this->assertFalse(connectedcourse::can_user_see_connected_course($hidden->id));
        $this->setAdminUser();
        $this->assertTrue(connectedcourse::can_user_see_connected_course($hidden->id));

        // Granting the capability in the course opens the link.
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student']);
        $this->getDataGenerator()->enrol_user($student->id, $hidden->id, 'student');
        assign_capability(
            'moodle/course:viewhiddencourses',
            CAP_ALLOW,
            $studentroleid,
            context_course::instance($hidden->id)->id,
            true
        );
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertTrue(connectedcourse::can_user_see_connected_course($hidden->id, $student->id));

        // Making the course visible opens it for everybody.
        $DB->set_field('course', 'visible', 1, ['id' => $hidden->id]);
        singleton_service::destroy_instance();
        $this->assertTrue(connectedcourse::can_user_see_connected_course($hidden->id, $teacher->id));
    }

    /**
     * Booking an option connected to a hidden course enrols the user as usual.
     */
    public function test_booking_enrols_into_hidden_course(): void {
        [$hidden, , , $option, , $student] = $this->create_environment();

        $this->book($option->id, $student);

        $this->assertTrue(is_enrolled(context_course::instance($hidden->id), $student->id));
    }

    /**
     * The "Start" link on the booked button (setting linktomoodlecourseonbookedbutton on) and the
     * "Go to Moodle course" button of the options table (setting off) are only shown to users who
     * may see the hidden course - and to everybody once the course is visible.
     */
    public function test_course_links_are_hidden_with_the_course(): void {
        global $DB;
        [$hidden, , , $option, , $student] = $this->create_environment();
        $admin = get_admin();

        $this->book($option->id, $student);
        $courseurl = 'course/view.php?id=' . $hidden->id;

        // Student: plain "booked" label without link, no course button.
        $this->setUser($student);
        $this->assertStringNotContainsString($courseurl, $this->render_booked_button($option->id, $student->id));
        $this->assertSame('', $this->render_course_column($option->id));

        // Admin: linked.
        $this->setAdminUser();
        $this->assertStringContainsString($courseurl, $this->render_booked_button($option->id, $admin->id));
        $this->assertStringContainsString($courseurl, $this->render_course_column($option->id));

        // Course released: the student gets the links.
        $DB->set_field('course', 'visible', 1, ['id' => $hidden->id]);
        singleton_service::destroy_instance();
        $this->setUser($student);
        $this->assertStringContainsString($courseurl, $this->render_booked_button($option->id, $student->id));
        $this->assertStringContainsString($courseurl, $this->render_course_column($option->id));
    }

    /**
     * Books the user into the option as a trainer would (verified, no availability checks).
     *
     * @param int $optionid
     * @param \stdClass $user
     * @return void
     */
    private function book(int $optionid, \stdClass $user): void {
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $bookingoption = singleton_service::get_instance_of_booking_option($settings->cmid, $optionid);
        $bookingoption->user_submit_response($user, 0, 0, 0, MOD_BOOKING_VERIFIED);
        singleton_service::destroy_booking_answers($optionid);
    }

    /**
     * The link of the booked button as json, empty when there is none.
     *
     * @param int $optionid
     * @param int $userid
     * @return string
     */
    private function render_booked_button(int $optionid, int $userid): string {
        // With this setting, the link to the course is on the booked button.
        set_config('linktomoodlecourseonbookedbutton', 1, 'booking');
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        [, $data] = (new alreadybooked())->render_button($settings, $userid);
        return $data['main']['link'] ?? '';
    }

    /**
     * The "course" column of the options table for the current user.
     *
     * @param int $optionid
     * @return string
     */
    private function render_course_column(int $optionid): string {
        // Without the setting, booked users get the "Go to Moodle course" button in the table.
        set_config('linktomoodlecourseonbookedbutton', 0, 'booking');
        $table = new bookingoptions_wbtable('hiddenconnectedcoursetest');
        return $table->col_course((object)['id' => $optionid]);
    }
}
