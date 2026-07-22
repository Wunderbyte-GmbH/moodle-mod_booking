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
 * Tests for the "Enrol users in the course" bulk action of the bookings tracker.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use context_course;
use mod_booking\table\manageusers_table;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use moodle_exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->dirroot/mod/booking/lib.php");

/**
 * The bulk action was migrated from the old report.php subscribetocourse
 * button: it enrols the users of the checked booking answers manually into
 * the course connected to the option, even when auto-enrolment is off.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class enrol_checked_answers_test extends booking_advanced_testcase {
    /**
     * The checked users get enrolled into the connected course although the
     * instance has auto-enrolment disabled; running the action again for an
     * already enrolled user does not fail.
     *
     * @covers \mod_booking\table\manageusers_table::action_enrol_checked_booking_answers
     */
    public function test_checked_users_are_enrolled_despite_disabled_autoenrol(): void {
        $this->setAdminUser();

        [$answerids, $targetcourse, $students] = $this->create_booked_option_with_target_course();

        // Precondition: nobody is enrolled in the target course yet (autoenrol off).
        $coursecontext = context_course::instance($targetcourse->id);
        foreach ($students as $student) {
            $this->assertFalse(is_enrolled($coursecontext, $student));
        }

        $table = new manageusers_table('enrolcheckedtest');
        $result = $table->action_enrol_checked_booking_answers(
            -1,
            json_encode(['checkedids' => $answerids])
        );

        $this->assertEquals(1, $result['success']);
        $this->assertEquals(1, $result['reload']);
        foreach ($students as $student) {
            $this->assertTrue(
                is_enrolled($coursecontext, $student),
                "User {$student->id} must be enrolled in the connected course."
            );
        }

        // Idempotent: enrolling again must not throw.
        $result = $table->action_enrol_checked_booking_answers(
            -1,
            json_encode(['checkedids' => $answerids])
        );
        $this->assertEquals(1, $result['success']);
    }

    /**
     * Without a connected course the action reports a failure message and
     * enrols nobody.
     *
     * @covers \mod_booking\table\manageusers_table::action_enrol_checked_booking_answers
     */
    public function test_option_without_course_returns_error(): void {
        $this->setAdminUser();

        [$answerids] = $this->create_booked_option_with_target_course(false);

        $table = new manageusers_table('enrolcheckedtest');
        $result = $table->action_enrol_checked_booking_answers(
            -1,
            json_encode(['checkedids' => $answerids])
        );

        $this->assertEquals(0, $result['success']);
        $this->assertEquals(get_string('nocourse', 'mod_booking'), $result['message']);
    }

    /**
     * The capabilities are rechecked server-side: a student (no
     * subscribeusers) cannot run the action.
     *
     * @covers \mod_booking\table\manageusers_table::action_enrol_checked_booking_answers
     */
    public function test_action_requires_subscribeusers_capability(): void {
        $this->setAdminUser();

        [$answerids, , $students] = $this->create_booked_option_with_target_course();

        // A student has no mod/booking:subscribeusers.
        $this->setUser($students[0]);

        $table = new manageusers_table('enrolcheckedtest');
        $this->expectException(moodle_exception::class);
        $table->action_enrol_checked_booking_answers(
            -1,
            json_encode(['checkedids' => $answerids])
        );
    }

    /**
     * On new installations non-editing teachers do not carry the write
     * capability mod/booking:subscribeusers by default (archetype), so the
     * action is rejected for them. (Existing installations keep their role
     * definitions - archetype changes never touch them on upgrade.)
     *
     * @covers \mod_booking\table\manageusers_table::action_enrol_checked_booking_answers
     */
    public function test_nonediting_teacher_is_rejected_by_default(): void {
        $this->setAdminUser();

        [$answerids, , , $course] = $this->create_booked_option_with_target_course();

        $courseteacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($courseteacher->id, $course->id, 'teacher');
        $this->setUser($courseteacher);

        $context = \context_course::instance($course->id);
        $this->assertFalse(
            has_capability('mod/booking:subscribeusers', $context),
            'Precondition: the teacher archetype must not carry subscribeusers on a fresh installation.'
        );

        $table = new manageusers_table('enrolcheckedtest');
        $this->expectException(moodle_exception::class);
        $table->action_enrol_checked_booking_answers(
            -1,
            json_encode(['checkedids' => $answerids])
        );
    }

    /**
     * Helper: booking instance (autoenrol off) with one option connected to a
     * separate target course and two booked students.
     *
     * @param bool $withcourse connect the option to a target course
     * @return array{0: int[], 1: stdClass|null, 2: stdClass[], 3: stdClass} answer ids, target course, students, course
     */
    private function create_booked_option_with_target_course(bool $withcourse = true): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $targetcourse = $withcourse ? $this->getDataGenerator()->create_course() : null;
        $bookingmanager = $this->getDataGenerator()->create_user();

        $booking = $this->getDataGenerator()->create_module('booking', [
            'name' => 'Enrol action test booking',
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
            'autoenrol' => 0,
            'course' => $course->id,
            'bookingmanager' => $bookingmanager->username,
        ]);

        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student1->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course->id, 'student');

        $record = new stdClass();
        $record->importing = 1;
        $record->bookingid = $booking->id;
        $record->text = 'Option for enrol action';
        $record->useprice = 0;
        $record->maxanswers = 5;
        if ($withcourse) {
            $record->courseid = $targetcourse->id;
            $record->chooseorcreatecourse = 1;
        }

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        booking_bookit::bookit('option', $settings->id, $student1->id);
        booking_bookit::bookit('option', $settings->id, $student1->id);
        booking_bookit::bookit('option', $settings->id, $student2->id);
        booking_bookit::bookit('option', $settings->id, $student2->id);

        $answerids = array_keys(
            $DB->get_records('booking_answers', ['optionid' => $option->id], '', 'id')
        );
        $this->assertCount(2, $answerids, 'Precondition: both students must be booked.');

        return [$answerids, $targetcourse, [$student1, $student2], $course];
    }
}
