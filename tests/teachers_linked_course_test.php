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
 * Tests for replacing the teacher of a booking option in the linked course.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use context_course;
use stdClass;

/**
 * Tests that a removed option teacher loses the teacher role in the linked course.
 *
 * @package mod_booking
 * @category test
 * @covers \mod_booking\teachers_handler::save_from_form
 * @covers \mod_booking\teachers_handler::remove_teacher_from_linked_course
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class teachers_linked_course_test extends advanced_testcase {
    /** @var int */
    private int $teacherroleid;

    /** @var stdClass the course linked to the booking options */
    private stdClass $linkedcourse;

    /** @var stdClass the booking instance */
    private stdClass $booking;

    /**
     * Tests set up.
     */
    public function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        $this->setAdminUser();

        $this->teacherroleid = (int)$DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);

        $course = $this->getDataGenerator()->create_course();
        $this->linkedcourse = $this->getDataGenerator()->create_course();
        $bookingmanager = $this->getDataGenerator()->create_user();

        $this->booking = $this->getDataGenerator()->create_module('booking', [
            'name' => 'Teacher change booking',
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
            'course' => $course->id,
            'bookingmanager' => $bookingmanager->username,
            'teacherroleid' => $this->teacherroleid,
        ]);
    }

    /**
     * Creates a booking option linked to the linked course with the given teacher.
     *
     * @param string $text
     * @param stdClass $teacher
     * @return stdClass the record, with id and cmid set for later updates
     */
    private function create_option_with_teacher(string $text, stdClass $teacher): stdClass {
        $record = new stdClass();
        $record->bookingid = $this->booking->id;
        $record->text = $text;
        $record->chooseorcreatecourse = 1; // Choose an existing Moodle course.
        $record->courseid = $this->linkedcourse->id;
        $record->teachersforoption = $teacher->username;
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('now + 3 days');
        $record->courseendtime_0 = strtotime('now + 6 days');

        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $record->id = $option->id;
        $record->cmid = $settings->cmid;
        return $record;
    }

    /**
     * Changes the teacher of the option via a regular option update.
     *
     * @param stdClass $record
     * @param stdClass $newteacher
     */
    private function change_teacher(stdClass $record, stdClass $newteacher): void {
        $record->teachersforoption = [$newteacher->id];
        booking_option::update($record);
        singleton_service::destroy_booking_option_singleton($record->id);
    }

    /**
     * Returns true if the user holds the teacher role in the linked course.
     *
     * @param int $userid
     * @return bool
     */
    private function has_teacher_role(int $userid): bool {
        return user_has_role_assignment($userid, $this->teacherroleid, context_course::instance($this->linkedcourse->id)->id);
    }

    /**
     * Changing the teacher enrols the new one and unenrols the old one.
     */
    public function test_old_teacher_unenrolled_new_teacher_enrolled(): void {
        $teachera = $this->getDataGenerator()->create_user();
        $teacherb = $this->getDataGenerator()->create_user();
        $coursecontext = context_course::instance($this->linkedcourse->id);

        $record = $this->create_option_with_teacher('Option', $teachera);
        $this->assertTrue(is_enrolled($coursecontext, $teachera->id));
        $this->assertTrue($this->has_teacher_role($teachera->id));

        $this->change_teacher($record, $teacherb);

        $this->assertTrue(is_enrolled($coursecontext, $teacherb->id));
        $this->assertTrue($this->has_teacher_role($teacherb->id));
        $this->assertFalse(is_enrolled($coursecontext, $teachera->id));
        $this->assertFalse($this->has_teacher_role($teachera->id));
    }

    /**
     * An old teacher with another role in the course stays enrolled, only the teacher role is removed.
     */
    public function test_old_teacher_with_other_role_keeps_enrolment(): void {
        $teachera = $this->getDataGenerator()->create_user();
        $teacherb = $this->getDataGenerator()->create_user();
        $coursecontext = context_course::instance($this->linkedcourse->id);

        $record = $this->create_option_with_teacher('Option', $teachera);
        $studentroleid = $this->getDataGenerator()->create_role();
        role_assign($studentroleid, $teachera->id, $coursecontext->id);

        $this->change_teacher($record, $teacherb);

        $this->assertTrue(is_enrolled($coursecontext, $teachera->id));
        $this->assertFalse($this->has_teacher_role($teachera->id));
        $this->assertTrue(user_has_role_assignment($teachera->id, $studentroleid, $coursecontext->id));
    }

    /**
     * An old teacher who still teaches another option linked to the same course keeps the teacher role.
     */
    public function test_old_teacher_of_other_option_keeps_teacher_role(): void {
        $teachera = $this->getDataGenerator()->create_user();
        $teacherb = $this->getDataGenerator()->create_user();
        $coursecontext = context_course::instance($this->linkedcourse->id);

        $record = $this->create_option_with_teacher('Option 1', $teachera);
        $this->create_option_with_teacher('Option 2', $teachera);

        $this->change_teacher($record, $teacherb);

        $this->assertTrue(is_enrolled($coursecontext, $teachera->id));
        $this->assertTrue($this->has_teacher_role($teachera->id));
        $this->assertTrue($this->has_teacher_role($teacherb->id));
    }
}
