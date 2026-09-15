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
 * Tests for the enrolment into group(s) of the current course when OTHER users are
 * booked ("Book other users", subscribeusers.php) as the very first bookings of an option.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use mod_booking\task\enrol_bookedusers_tocourse;
use mod_booking_generator;
use stdClass;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * The instance setting addtogroupofcurrentcourse lives inside booking_option::enrol_user,
 * which runs at booking time only while the option enrols immediately (enrolmentstatus 2,
 * the form default) or its coursestarttime has passed - otherwise the scheduled task
 * enrol_bookedusers_tocourse applies it after the course start. These tests pin down both
 * behaviours for the "Book other users" flow (user_submit_response like subscribeusers.php),
 * including the creation of the option group by the very first booking.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\booking_option::enrol_user
 * @covers \mod_booking\booking_option::enrol_user_coursestart
 * @covers \mod_booking\booking_option::create_group
 * @covers \mod_booking\task\enrol_bookedusers_tocourse
 */
final class booking_groupenrolment_bookothers_test extends booking_advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
    }

    /**
     * Booking two OTHER users as the very first bookings of a fresh option (enrolmentstatus 2,
     * the form default: enrol immediately) must create the option group in the current course
     * and add both users to it as well as to the fixed group selected in the instance setting.
     */
    public function test_book_other_users_creates_group_in_current_course(): void {
        [$course, $fixedgroup, $option, $bookingoption, $students] = $this->setup_instance_and_option(2);

        // No group of the booking option exists yet.
        $this->assertFalse(
            $this->find_option_group($course->id, $option->id),
            'Before the first booking, no option group must exist in the current course.'
        );

        // Book the two users like subscribeusers.php ("Book other users") does.
        foreach ($students as $student) {
            $this->assertTrue(
                (bool) $bookingoption->user_submit_response(
                    $student,
                    0,
                    0,
                    MOD_BOOKING_BO_SUBMIT_STATUS_DEFAULT,
                    MOD_BOOKING_VERIFIED
                ),
                "Booking user {$student->id} via the book-other-users flow must succeed."
            );
        }

        // The group of the booking option has been created in the current course...
        $optiongroup = $this->find_option_group($course->id, $option->id);
        $this->assertNotFalse(
            $optiongroup,
            'Booking other users as first bookings must create the option group in the current course.'
        );
        // ...and both users are members of it and of the fixed group.
        foreach ($students as $student) {
            $this->assertTrue(
                groups_is_member($optiongroup->id, $student->id),
                "User {$student->id} must be a member of the option group."
            );
            $this->assertTrue(
                groups_is_member($fixedgroup->id, $student->id),
                "User {$student->id} must be a member of the fixed group."
            );
        }
    }

    /**
     * With "Enrol users only at coursestart" active (enrolmentstatus 0) and a coursestarttime
     * in the future, booking other users does NOT touch the groups of the current course yet:
     * the whole enrolment - and with it the group logic - is deferred to the scheduled task
     * enrol_bookedusers_tocourse, which processes the option only once its course has started.
     */
    public function test_book_other_users_group_deferred_until_coursestart(): void {
        global $DB;

        [$course, $fixedgroup, $option, $bookingoption, $students] =
            $this->setup_instance_and_option(2, ['enrolmentstatus' => 0]);

        foreach ($students as $student) {
            $this->assertTrue(
                (bool) $bookingoption->user_submit_response(
                    $student,
                    0,
                    0,
                    MOD_BOOKING_BO_SUBMIT_STATUS_DEFAULT,
                    MOD_BOOKING_VERIFIED
                )
            );
        }

        // Nothing happens at booking time: the option waits for its course start.
        $this->assertFalse(
            $this->find_option_group($course->id, $option->id),
            'With enrolmentstatus 0 and a future coursestarttime, booking must not create the option group yet.'
        );
        $this->assertFalse(groups_is_member($fixedgroup->id, $students[0]->id));

        // The scheduled task skips the option as long as its coursestarttime lies in the future.
        ob_start();
        (new enrol_bookedusers_tocourse())->execute();
        ob_end_clean();
        $this->assertFalse(
            $this->find_option_group($course->id, $option->id),
            'The scheduled task must not process an option before its coursestarttime.'
        );

        // Once the course has started, the task enrols the booked users and applies the group setting.
        $DB->set_field('booking_options', 'coursestarttime', strtotime('-1 hour'), ['id' => $option->id]);
        \cache::make('mod_booking', 'bookingoptionsettings')->delete($option->id);
        singleton_service::destroy_instance();

        ob_start();
        (new enrol_bookedusers_tocourse())->execute();
        ob_end_clean();

        $optiongroup = $this->find_option_group($course->id, $option->id);
        $this->assertNotFalse(
            $optiongroup,
            'After the course start, the scheduled task must create the option group in the current course.'
        );
        foreach ($students as $student) {
            $this->assertTrue(
                groups_is_member($optiongroup->id, $student->id),
                "User {$student->id} must be a member of the option group after the task has run."
            );
            $this->assertTrue(
                groups_is_member($fixedgroup->id, $student->id),
                "User {$student->id} must be a member of the fixed group after the task has run."
            );
        }
    }

    /**
     * When the connected course is the very course the booking instance lives in, the
     * automatically created group of the connected course (addtogroup) and the option group
     * of the current course (addtogroupofcurrentcourse with "specific group per option")
     * carry the identical generated name "<instance> - <option> (<optionid>)".
     *
     * The option group of the current course is identified by its idnumber, so it must be
     * created and filled although a group with the very same name already exists in the
     * course - it must not be collapsed onto the connected-course group.
     */
    public function test_option_group_created_despite_same_named_connected_course_group(): void {
        global $DB;

        [$course, $fixedgroup, $option, $bookingoption, $students] = $this->setup_instance_and_option(
            1,
            [],
            ['addtogroup' => 1, 'autoenrol' => 1],
            'current'
        );

        // The group of the connected course (= the current course here) has been created on
        // option save and carries the generated name "<instance> - <option> (<optionid>)".
        $optionrecord = $DB->get_record('booking_options', ['id' => $option->id], '*', MUST_EXIST);
        $this->assertNotEmpty($optionrecord->groupid, 'addtogroup must have created the connected-course group.');
        $targetgroup = groups_get_group($optionrecord->groupid);
        $this->assertEquals("Booking instance - Booking option 1 ({$option->id})", $targetgroup->name);

        // Book a user via the book-other-users flow.
        $this->assertTrue(
            (bool) $bookingoption->user_submit_response(
                $students[0],
                0,
                0,
                MOD_BOOKING_BO_SUBMIT_STATUS_DEFAULT,
                MOD_BOOKING_VERIFIED
            )
        );

        // The option group of the current course has been created as a second group with the
        // same generated name, and the user is a member of all three groups.
        $optiongroup = $this->find_option_group($course->id, $option->id);
        $this->assertNotFalse(
            $optiongroup,
            'The option group of the current course must be created although a group with the '
            . 'identical generated name exists in the course.'
        );
        $this->assertNotEquals($targetgroup->id, $optiongroup->id);
        $this->assertEquals($targetgroup->name, $optiongroup->name);
        $this->assertTrue(groups_is_member($optiongroup->id, $students[0]->id));
        $this->assertTrue(groups_is_member($targetgroup->id, $students[0]->id));
        $this->assertTrue(groups_is_member($fixedgroup->id, $students[0]->id));
    }

    /**
     * The other direction of the name collision: when the option group of the current course
     * already exists and the automatic group of the connected course (addtogroup) is activated
     * only afterwards, the connected-course lookup must not link the same-named option group -
     * a separate connected-course group has to be created.
     */
    public function test_connected_course_group_ignores_same_named_option_group(): void {
        global $DB;

        [$course, $fixedgroup, $option, $bookingoption, $students] =
            $this->setup_instance_and_option(2, [], [], 'current');

        // The first booking creates the option group of the current course; addtogroup is
        // off, so no connected-course group is linked to the option yet.
        $this->assertTrue(
            (bool) $bookingoption->user_submit_response(
                $students[0],
                0,
                0,
                MOD_BOOKING_BO_SUBMIT_STATUS_DEFAULT,
                MOD_BOOKING_VERIFIED
            )
        );
        $optiongroup = $this->find_option_group($course->id, $option->id);
        $this->assertNotFalse($optiongroup);
        $this->assertEmpty(
            $DB->get_field('booking_options', 'groupid', ['id' => $option->id]),
            'Without addtogroup, no connected-course group may be linked to the option.'
        );

        // Activate the automatic group of the connected course in the instance settings.
        $bookingid = $DB->get_field('booking_options', 'bookingid', ['id' => $option->id]);
        $DB->set_field('booking', 'addtogroup', 1, ['id' => $bookingid]);
        $cmid = $bookingoption->cmid;
        \cache::make('mod_booking', 'cachedbookinginstances')->delete($cmid);
        singleton_service::destroy_instance();
        $bookingoption = singleton_service::get_instance_of_booking_option($cmid, $option->id);

        // The next booking creates and links the connected-course group: a separate group
        // with the same generated name - not the option group of the current course.
        $this->assertTrue(
            (bool) $bookingoption->user_submit_response(
                $students[1],
                0,
                0,
                MOD_BOOKING_BO_SUBMIT_STATUS_DEFAULT,
                MOD_BOOKING_VERIFIED
            )
        );

        $optionrecord = $DB->get_record('booking_options', ['id' => $option->id], '*', MUST_EXIST);
        $this->assertNotEmpty(
            $optionrecord->groupid,
            'addtogroup must have created and linked the connected-course group.'
        );
        $this->assertNotEquals(
            $optiongroup->id,
            (int) $optionrecord->groupid,
            'The connected-course group must not link the same-named option group of the current course.'
        );
        $targetgroup = groups_get_group($optionrecord->groupid);
        $this->assertEquals($optiongroup->name, $targetgroup->name);
        $this->assertEmpty($targetgroup->idnumber);
        $this->assertTrue(groups_is_member($optionrecord->groupid, $students[1]->id));
        $this->assertTrue(groups_is_member($optiongroup->id, $students[1]->id));
    }

    /**
     * The scenario with two different courses: the option connects a separate course while
     * the instance course keeps its own option group. Self-booking via the "Book now" flow
     * (booking_bookit) must create BOTH groups - the linked group in the connected course
     * and the idnumber-linked option group in the current course - although they carry the
     * identical generated name.
     *
     * The second part documents the admin caveat: core groups_add_member silently refuses
     * users who are not enrolled in the course of the group. Booking enrols the user into
     * the CONNECTED course before adding them to its group, but never enrols anyone into
     * the current course - so a site admin (usually not enrolled there) fills only the
     * connected-course group when booking themself.
     */
    public function test_self_booking_creates_groups_in_both_courses(): void {
        global $DB;

        [$course, $fixedgroup, $option, $bookingoption, $students, $connectedcourse] =
            $this->setup_instance_and_option(1, [], ['addtogroup' => 1, 'autoenrol' => 1], 'separate');

        // The student books themself via the "Book now" flow (first call answers the
        // confirmation modal, the second one books).
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $this->setUser($students[0]);
        booking_bookit::bookit('option', $settings->id, (int) $students[0]->id);
        booking_bookit::bookit('option', $settings->id, (int) $students[0]->id);
        $this->setAdminUser();

        // The group of the connected course has been created, linked and filled.
        $optionrecord = $DB->get_record('booking_options', ['id' => $option->id], '*', MUST_EXIST);
        $this->assertNotEmpty($optionrecord->groupid, 'addtogroup must have created the connected-course group.');
        $targetgroup = groups_get_group($optionrecord->groupid);
        $this->assertEquals($connectedcourse->id, $targetgroup->courseid);
        $this->assertTrue(groups_is_member($targetgroup->id, $students[0]->id));

        // The option group of the current course has been created and filled as well.
        $optiongroup = $this->find_option_group($course->id, $option->id);
        $this->assertNotFalse(
            $optiongroup,
            'Self-booking must create the option group in the current course.'
        );
        $this->assertTrue(groups_is_member($optiongroup->id, $students[0]->id));
        $this->assertTrue(groups_is_member($fixedgroup->id, $students[0]->id));

        // Both groups carry the identical generated name - in two different courses.
        $this->assertEquals($targetgroup->name, $optiongroup->name);

        // A site admin booking themself is NOT enrolled in the current course: the groups of
        // the current course are silently skipped (groups_add_member refuses users without
        // enrolment), while the connected course enrols the admin and fills its group.
        $admin = get_admin();
        booking_bookit::bookit('option', $settings->id, (int) $admin->id);
        booking_bookit::bookit('option', $settings->id, (int) $admin->id);
        $this->assertTrue(groups_is_member($targetgroup->id, $admin->id));
        $this->assertFalse(groups_is_member($optiongroup->id, $admin->id));
        $this->assertFalse(groups_is_member($fixedgroup->id, $admin->id));
    }

    /**
     * Create course, fixed group, booking instance with addtogroupofcurrentcourse
     * (specific group per option + the fixed group) and a booking option, plus the
     * requested number of enrolled students.
     *
     * @param int $numberofstudents number of students to create and enrol
     * @param array $optionoverrides values overriding the option record defaults
     * @param array $instanceoverrides values overriding the instance data defaults
     * @param bool|string $connectcourse false for no connected course, 'current' to connect
     *                                   the course of the instance itself, 'separate' to
     *                                   create and connect a second course
     * @return array [$course, $fixedgroup, $option, $bookingoption, $students, $connectedcourse]
     */
    private function setup_instance_and_option(
        int $numberofstudents,
        array $optionoverrides = [],
        array $instanceoverrides = [],
        $connectcourse = false
    ): array {
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['fullname' => 'Source course']);
        $fixedgroup = $this->getDataGenerator()->create_group([
            'courseid' => $course->id,
            'name' => 'Fixed group',
        ]);

        $bdata = [
            'name' => 'Booking instance',
            'course' => $course->id,
            'addtogroupofcurrentcourse' => [MOD_BOOKING_ENROL_INTO_GROUP_OF_BOOKINGOPTION, $fixedgroup->id],
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
            'tags' => '',
            'showviews' => ['mybooking,myoptions,optionsiamresponsiblefor,showall,showactive,myinstitution'],
        ];
        foreach ($instanceoverrides as $key => $value) {
            $bdata[$key] = $value;
        }
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $students = [];
        for ($i = 0; $i < $numberofstudents; $i++) {
            $student = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($student->id, $course->id);
            $students[] = $student;
        }

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Booking option 1';
        $connectedcourse = null;
        if ($connectcourse === 'separate') {
            // Create and connect a second course.
            $connectedcourse = $this->getDataGenerator()->create_course(['fullname' => 'Connected course']);
            $record->chooseorcreatecourse = 1;
            $record->courseid = $connectedcourse->id;
            $record->resetgroupid = 0;
        } else if ($connectcourse === 'current') {
            // Connect the course the booking instance lives in.
            $record->chooseorcreatecourse = 1;
            $record->courseid = $course->id;
            $record->resetgroupid = 0;
        } else {
            // No connected course: the setting concerns the current course only.
            $record->chooseorcreatecourse = 0;
            $record->courseid = 0;
            $record->resetgroupid = 0;
        }
        $record->enrolmentstatus = 2; // Enrol users immediately on booking (form default).
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('+2 days', time());
        $record->courseendtime_0 = strtotime('+3 days', time());
        foreach ($optionoverrides as $key => $value) {
            $record->{$key} = $value;
        }

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $bookingoption = singleton_service::get_instance_of_booking_option($settings->cmid, $option->id);

        return [$course, $fixedgroup, $option, $bookingoption, $students, $connectedcourse];
    }

    /**
     * Find the group which booking_option::create_group generates for a booking option in the
     * current course. These groups are linked via the idnumber (sourcecourseboid_<optionid>).
     *
     * @param int $courseid id of the course to search in
     * @param int $optionid id of the booking option
     * @return stdClass|false the group record or false if there is none
     */
    private function find_option_group(int $courseid, int $optionid) {
        foreach (groups_get_all_groups($courseid) as $group) {
            if ($group->idnumber === MOD_BOOKING_ENROL_GROUPTYPE_SOURCECOURSE . $optionid) {
                return $group;
            }
        }
        return false;
    }
}
