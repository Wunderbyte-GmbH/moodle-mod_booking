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

namespace bookingextension_confirmation_supervisor;

use advanced_testcase;
use mod_booking\output\booked_users;
use mod_booking\singleton_service;
use mod_booking\booking_bookit;
use mod_booking\bo_availability\bo_info;
use mod_booking\booking_answers\booking_answers;
use mod_booking\table\manageusers_table;
use local_wunderbyte_table\wunderbyte_table;
use mod_booking_generator;
use context_module;

/**
 * Tests for Confirmation workflow by supervisor
 *
 * @package    bookingextension_confirmation_supervisor
 * @category   test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2025 Mahdi Poustini
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class capability_seealllisttoapprove_test extends advanced_testcase {
    /**
     * Check whether everyone with the capability seealllisttoapprove can see the listtoapprove.
     * We expect that anyone who has the seealllisttoapprove capability is able to see all records,
     * even when there are no configurations for the supervisor or deputy fields.
     *
     * @covers \bookingextension_confirmation_supervisor\local\confirmbooking
     */
    public function test_answers_visiblity_in_table(): void {
        global $DB, $USER;

        $this->resetAfterTest(true);
        $this->preventResetByRollback();

        $this->setAdminUser();
        $admin = $USER;
        set_config(
            'confirmationtrainerenabled',
            empty($confirmationtrainerenabled) ? 0 : 1,
            'bookingextension_confirmation_trainer'
        );
        set_config(
            'supervisor',
            'supervisor',
            'bookingextension_confirmation_supervisor'
        );

        // Here we set the deputy custom field as the field that stores the deputy's user ID.
        set_config(
            'deputy',
            'deputy',
            'bookingextension_confirmation_supervisor'
        );
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $coursecotext = \context_course::instance($course->id);

        // Create users.
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $student3 = $this->getDataGenerator()->create_user();
        $student4 = $this->getDataGenerator()->create_user();
        $student5 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $manager = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        $users = [
            'admin' => $admin,
            'student1' => $student1,
            'student2' => $student2,
            'student3' => $student3,
            'student4' => $student4,
            'student5' => $student5,
            'teacher' => $teacher,
            'manager' => $manager,
            'bookingmanager' => $bookingmanager,
        ];

        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'bookingmanager' => $bookingmanager->username,
            'cancancelbook' => 1,
        ]);
        // Create booking option.
        /** @var mod_booking_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $generator->create_option((object)[
            'bookingid' => $booking->id,
            'text' => 'Test option',
            'courseid' => $course->id,
            'chooseorcreatecourse' => 1,
            'waitforconfirmation' => 1,
            'confirmationsupervisorenabled' => 1,
        ]);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boinfo = new bo_info($settings);
        $observer = create_role('Observer', 'observer', 'Person with capability to see all listtoapprove');

        // Assign required capabilities to the role.
        assign_capability('mod/booking:seealllisttoapprove', CAP_ALLOW, $observer, SYSCONTEXTID, true);

        // Assign role to specific users in system context.
        $syscontext = \context_system::instance();
        role_assign($observer, $manager->id, $syscontext->id);
        // Enrol always admin, teacher, manager & students to course.
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($manager->id, $course->id, 'manager');
        $this->getDataGenerator()->enrol_user($student1->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student3->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student4->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student5->id, $course->id, 'student');

        /*********************************************
         * Book 1st & 2nd users.
         * Admin, Teacher, Manager & HR should NOT be able to confirm their answers.
         *********************************************/
        // Login as student 1 & book.
        $this->setUser($student1);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ASKFORCONFIRMATION, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id); // Attempt to book.
        $result = booking_bookit::bookit('option', $settings->id, $student1->id); // Confirm to book.
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Login as student 2 & book.
        $this->setUser($student2);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ASKFORCONFIRMATION, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student2->id); // Attempt to book.
        $result = booking_bookit::bookit('option', $settings->id, $student2->id); // Confirm to book.
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        $bookinganswers = singleton_service::get_instance_of_booking_answers($settings);
        $student1answer = ($bookinganswers->get_users())[$student1->id] ?? null; // Get student 1 answer.
        $this->assertNotEmpty($student1answer);
        $student2answer = ($bookinganswers->get_users())[$student2->id] ?? null; // Get student 1 answer.
        $this->assertNotEmpty($student2answer);

        /*********************************************
         * We should check visibility here.
         * Only manager and admin can see the answers.
         *********************************************/
        $allowedusers = ['admin', 'manager']; // Admin is sasuperuser and manager has seealllisttoapprove capability.
        $notallowedusers = ['bookingmanager', 'teacher', 'student1'];

        foreach ($notallowedusers as $key) {
            $this->setUser($users[$key]);
            $viewingtable = $this->get_booked_users_table();
            $this->assertCount(
                0,
                $viewingtable->rawdata,
                'The user (' . $key . ') must not access the answers via the listtoapprove shortcode.'
            );
        }

        foreach ($allowedusers as $key) {
            $this->setUser($users[$key]);
            $viewingtable = $this->get_booked_users_table();
            $this->assertCount(
                2,
                $viewingtable->rawdata,
                'The user (' . $key . ') must access the answers via the listtoapprove shortcode.'
            );
            $usersids = array_map(fn($record) => $record->userid, $viewingtable->rawdata);
            $this->assertContains($student1->id, $usersids);
            $this->assertContains($student2->id, $usersids);
        }
    }

    /**
     * This function returns the table that the approver will see in the UI.
     * With this table, we can determine the actual records that will be returned to the approver.
     *
     * @return ?wunderbyte_table
     */
    private function get_booked_users_table(): ?wunderbyte_table {
        $bookeduserstable = new booked_users(
            'optionstoconfirm',
            0,
            false, // Booked users.
            false, // Users on waiting list.
            false, // Reserved answers (e.g. in shopping cart).
            false, // Users on notify list.
            false, // Deleted users.
            false, // Booking history.
            true // Options to confirm.
        );

        return $bookeduserstable->return_raw_table(
            'optionstoconfirm',
            0,
            MOD_BOOKING_STATUSPARAM_WAITINGLIST
        );
    }
}
