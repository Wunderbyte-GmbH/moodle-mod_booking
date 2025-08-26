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
use mod_booking\singleton_service;
use mod_booking\booking_bookit;
use mod_booking\bo_availability\bo_info;
use mod_booking\booking_answers\booking_answers;
use mod_booking\table\manageusers_table;
use mod_booking_generator;
use context_module;




/**
 * Tests for booking_answers class.
 * This test checks if getters are returning the expected values.
 * Getter functions : get_usersonlist, get_usersonwaitinglist, get_usersreserved.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2025 Mahdi Poustini
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
final class confirmation_test extends advanced_testcase {
    /**
     * Creates booking course, users, and booking option with given settings.
     */
    private function setup_booking_environment(
        int $confirmationtrainerenabled,
        int $confirmationsupervisorenabled
    ): array {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/user/profile/lib.php');

        $this->setAdminUser();

        set_config(
            'confirmationtrainerenabled',
            empty($confirmationtrainerenabled) ? 0 : 1,
            'bookingextension_confirmation_trainer'
        );
        set_config(
            'confirmationsupervisorenabled',
            empty($confirmationsupervisorenabled) ? 0 : 1,
            'bookingextension_confirmation_supervisor'
        );

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $admin = $this->getDataGenerator()->create_user();
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $student3 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $manager = $this->getDataGenerator()->create_user();
        $supervisor = $this->getDataGenerator()->create_user();
        $hr = $this->getDataGenerator()->create_user();

        // Enrol users.
        $this->getDataGenerator()->enrol_user($admin->id, $course->id);
        $this->getDataGenerator()->enrol_user($student1->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student3->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'teacher');
        $this->getDataGenerator()->enrol_user($manager->id, $course->id, 'manager');

        // Set custom profile field 'supervisor'.
        profile_save_data((object)[
            'id' => $student1->id,
            'profile_field_supervisor' => $supervisor->id,
        ]);

        profile_save_data((object)[
            'id' => $student2->id,
            'profile_field_supervisor' => $supervisor->id,
        ]);

        profile_save_data((object)[
            'id' => $student3->id,
            'profile_field_supervisor' => $supervisor->id,
        ]);

        // Set HR user ID in config.
        set_config('confirmation_supervisor_hrusers', $hr->id, 'bookingextension_confirmation_supervisor');

        // Create booking module.
        $bookingmanager = $this->getDataGenerator()->create_user();
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
            'confirmationtrainerenabled' => $confirmationtrainerenabled,
            'confirmationsupervisorenabled' => $confirmationsupervisorenabled,
        ]);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boinfo = new bo_info($settings);

        return [
            'course' => $course,
            'booking' => $booking,
            'option' => $option,
            'settings' => $settings,
            'boinfo' => $boinfo,
            'users' => [
                'admin' => $admin,
                'student1' => $student1,
                'student2' => $student2,
                'student3' => $student3,
                'teacher' => $teacher,
                'manager' => $manager,
                'supervisor' => $supervisor,
                'hr' => $hr,
            ],
        ];
    }

    /**
     * Tests booking answer class.
     *
     * @covers \mod_booking\booking_answers\booking_answers
     */
    public function test_confirmation_trainer(): void {
        global $DB;
        $this->resetAfterTest(true);

        // Initial config.
        $env = $this->setup_booking_environment(1, 0);
        $admin = $env['users']['admin'];
        $student1 = $env['users']['student1'];
        $student2 = $env['users']['student2'];
        $student3 = $env['users']['student3'];
        $teacher = $env['users']['teacher'];
        $manager = $env['users']['manager'];
        $supervisor = $env['users']['supervisor'];
        $hr = $env['users']['hr'];
        $settings = $env['settings'];
        $boinfo = $env['boinfo'];
        $table = $this->get_table();

        /*********************************************
         * Book first user. Admi should be able to confirm it.
         *********************************************/
        $this->setUser($student1);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ASKFORCONFIRMATION, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id); // Book the first user.

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        $bookinganswers = singleton_service::get_instance_of_booking_answers($settings);
        $answer = ($bookinganswers->get_users())[$student1->id] ?? null; // Get student 1 answer.
        $this->assertNotEmpty($answer);

        $this->setAdminUser(); // Switch user - admin.
        $result = $table->action_confirmbooking(0, json_encode(['id' => $answer->baid]));
        $this->assertEquals(1, $result['success']); // Make sure confirmation is successful.

        $this->setUser($student1);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        /*********************************************
         * Book second user. Teacher should be able to confirm it.
         *********************************************/
        $this->setUser($student2);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ASKFORCONFIRMATION, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student2->id);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        $bookinganswers = singleton_service::get_instance_of_booking_answers($settings);
        $answer = ($bookinganswers->get_users())[$student2->id] ?? null;
        $this->assertNotEmpty($answer);

        // Check if teacher can confirm.
        $context = context_module::instance($settings->cmid);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'teacher']);
        assign_capability('mod/booking:bookforothers', CAP_ALLOW, $roleid, $context->id, true);
        $hascapability = has_capability('mod/booking:bookforothers', $context, $teacher->id);
        $this->assertTrue($hascapability);
        $this->setUser($teacher);
        $result = $table->action_confirmbooking(0, json_encode(['id' => $answer->baid])); // Confirm answer.
        $this->assertEquals(1, $result['success']); // Make sure confirmation is successful.

        $this->setUser($student2);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        /*********************************************
         * Book third user. Manager should be able to confirm it.
         *********************************************/
        $this->setUser($student3);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ASKFORCONFIRMATION, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student3->id);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        $bookinganswers = singleton_service::get_instance_of_booking_answers($settings);
        $answer = ($bookinganswers->get_users())[$student3->id] ?? null;
        $this->assertNotEmpty($answer);

        // Check if manager can confirm.
        $context = context_module::instance($settings->cmid);
        $this->assertTrue($hascapability);
        $this->setUser($manager);
        $result = $table->action_confirmbooking(0, json_encode(['id' => $answer->baid])); // Confirm answer.
        $this->assertEquals(1, $result['success']); // Make sure confirmation is successful.

        $this->setUser($student2);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);
    }

    /**
     * Test if no one admin, teacher, manager, supervisor, HR can confirm it.
     * @return void
     */
    public function test_confirmation_off(): void {
        global $DB;
        $this->resetAfterTest(true);

        // Initial config.
        $env = $this->setup_booking_environment(0, 0);
        $admin = $env['users']['admin'];
        $student1 = $env['users']['student1'];
        $student2 = $env['users']['student2'];
        $student3 = $env['users']['student3'];
        $teacher = $env['users']['teacher'];
        $manager = $env['users']['manager'];
        $supervisor = $env['users']['supervisor'];
        $hr = $env['users']['hr'];
        $settings = $env['settings'];
        $boinfo = $env['boinfo'];
        $table = $this->get_table();

        /*********************************************
         * Book a user. Admin, manager & teacher should not be able confirm it.
         *********************************************/
        $this->setUser($student1);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ASKFORCONFIRMATION, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id); // Book the first user.

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        $bookinganswers = singleton_service::get_instance_of_booking_answers($settings);
        $answer = ($bookinganswers->get_users())[$student1->id] ?? null; // Get student 1 answer.
        $this->assertNotEmpty($answer);

        // Ensure admi cannot confirm it.
        $this->setAdminUser(); // Switch user - admin.
        $result = $table->action_confirmbooking(0, json_encode(['id' => $answer->baid]));
        $this->assertEquals(0, $result['success']); // Make sure confirmation is not successful.

        // Ensure teacher cannot confirm it.
        $context = context_module::instance($settings->cmid);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'teacher']);
        assign_capability('mod/booking:bookforothers', CAP_ALLOW, $roleid, $context->id, true);
        $hascapability = has_capability('mod/booking:bookforothers', $context, $teacher->id);
        $this->assertTrue($hascapability);
        $this->setUser($teacher);
        $result = $table->action_confirmbooking(0, json_encode(['id' => $answer->baid])); // Confirm answer.
        $this->assertEquals(0, $result['success']); // Make sure confirmation is not successful.

        // Check if manager can confirm.
        $context = context_module::instance($settings->cmid);
        $this->assertTrue($hascapability);
        $this->setUser($manager);
        $result = $table->action_confirmbooking(0, json_encode(['id' => $answer->baid])); // Confirm answer.
        $this->assertEquals(0, $result['success']); // Make sure confirmation is not successful.

        // Answer should be still on waiting list.
        $this->setUser($student1);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);
    }

    /**
     * Intantiates a manageusers_table.
     * @return manageusers_table
     */
    private function get_table(): manageusers_table {
        $ba = new booking_answers();
        $scope = 'optionstoconfirm';
        $scopeid = 0;
        $tablenameprefix = 'test';
        $tablename = "{$tablenameprefix}_{$scope}_{$scopeid}";
        $table = new manageusers_table($tablename);
        return $table;
    }
}
