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
use mod_booking\booking_bookit;
use mod_booking\bo_availability\bo_info;
use mod_booking\booking_answers\booking_answers;
use mod_booking\table\manageusers_table;
use local_wunderbyte_table\wunderbyte_table;
use mod_booking_generator;
use context_module;

/**
 * Tests for confirmation capability.
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
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
        global $DB, $CFG, $USER;

        require_once($CFG->dirroot . '/user/profile/lib.php');

        $this->setAdminUser();

        $admin = $USER;

        set_config(
            'confirmationtrainerenabled',
            empty($confirmationtrainerenabled) ? 0 : 1,
            'bookingextension_confirmation_trainer'
        );

        if (\core_component::get_component_directory('bookingextension_confirmation_supervisor')) {
            set_config(
                'confirmationsupervisorenabled',
                empty($confirmationsupervisorenabled) ? 0 : 1,
                'bookingextension_confirmation_supervisor'
            );
        }

        // The user ID of supervisor will be set in a custom profile for each user.
        // Here we define a new custom field.
        $this->create_custom_profile_field('supervisor', 'Supervisor');

        // Check if supervisor custom prifile field exists.
        $exists = $DB->record_exists('user_info_field', ['shortname' => 'supervisor']);
        $this->assertTrue($exists, 'Custom profile field "supervisor" was not created.');

        // The user ID of supervisor will be set in a custom profile for each user.
        // Here we define a new custom field.
        $this->create_custom_profile_field('deputy', 'Deputy');

        // Check if supervisor custom prifile field exists.
        $exists = $DB->record_exists('user_info_field', ['shortname' => 'deputy']);
        $this->assertTrue($exists, 'Custom profile field "deputy" was not created.');

        if (\core_component::get_component_directory('bookingextension_confirmation_supervisor')) {
            // Here we set the supervisor custom field as the field that stores the supervisor's user ID.
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
        }

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
        $supervisor1 = $this->getDataGenerator()->create_user();
        $supervisor2 = $this->getDataGenerator()->create_user();
        $hr1 = $this->getDataGenerator()->create_user();
        $hr2 = $this->getDataGenerator()->create_user();
        $deputy1 = $this->getDataGenerator()->create_user();
        $deputy2 = $this->getDataGenerator()->create_user();
        $deputy3 = $this->getDataGenerator()->create_user();
        $deputy4 = $this->getDataGenerator()->create_user();
        $deputy5 = $this->getDataGenerator()->create_user();

        // Set custom profile field 'supervisor'.
        profile_save_data((object)['id' => $student1->id, 'profile_field_supervisor' => $supervisor1->id]);
        profile_save_data((object)['id' => $student2->id, 'profile_field_supervisor' => $supervisor1->id]);
        profile_save_data((object)['id' => $student3->id, 'profile_field_supervisor' => $supervisor2->id]);
        profile_save_data((object)['id' => $student4->id, 'profile_field_supervisor' => $supervisor1->id]);
        profile_save_data((object)['id' => $student5->id, 'profile_field_supervisor' => $supervisor2->id]);
        // Set custom profile field 'deputy'.
        $deputies = implode(',', [$deputy1->id, $deputy2->id, $deputy3->id]);
        profile_save_data((object)['id' => $supervisor1->id, 'profile_field_deputy' => $deputies]);

        // Ensure profile custom filed is set as expected.
        $this->assertEquals($supervisor1->id, profile_user_record($student1->id)->supervisor);

        if (\core_component::get_component_directory('bookingextension_confirmation_supervisor')) {
            // Set HR user ID in config.
            set_config('confirmation_supervisor_hrusers', "{$hr1->id},{$hr2->id}", 'bookingextension_confirmation_supervisor');
        }

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

        // Create the 'approver' role in system context.
        $approverroleid = create_role('Approver', 'approver', 'Approver with special booking capabilities');

        // Assign required capabilities to the role.
        assign_capability('mod/booking:bookforothers', CAP_ALLOW, $approverroleid, SYSCONTEXTID, true);
        assign_capability('mod/booking:managebookedusers', CAP_ALLOW, $approverroleid, SYSCONTEXTID, true);
        assign_capability('mod/booking:readresponses', CAP_ALLOW, $approverroleid, SYSCONTEXTID, true);

        // Assign role to specific users in system context.
        $syscontext = \context_system::instance();
        role_assign($approverroleid, $supervisor1->id, $syscontext->id);
        role_assign($approverroleid, $supervisor2->id, $syscontext->id);
        role_assign($approverroleid, $hr1->id, $syscontext->id);
        role_assign($approverroleid, $hr2->id, $syscontext->id);
        role_assign($approverroleid, $deputy1->id, $syscontext->id);
        role_assign($approverroleid, $deputy2->id, $syscontext->id);
        role_assign($approverroleid, $deputy3->id, $syscontext->id);
        role_assign($approverroleid, $deputy4->id, $syscontext->id);
        role_assign($approverroleid, $deputy5->id, $syscontext->id);

        // Enrol always admin, teacher, manager & students to course.
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($manager->id, $course->id, 'manager');
        $this->getDataGenerator()->enrol_user($student1->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student3->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student4->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student5->id, $course->id, 'student');

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
                'student4' => $student4,
                'student5' => $student5,
                'teacher' => $teacher,
                'manager' => $manager,
                'supervisor1' => $supervisor1,
                'supervisor2' => $supervisor2,
                'hr1' => $hr1,
                'hr2' => $hr2,
                'deputy1' => $deputy1,
                'deputy2' => $deputy2,
                'deputy3' => $deputy3,
                'deputy4' => $deputy4,
                'deputy5' => $deputy5,
            ],
        ];
    }

    /**
     * Tests confirmation capability when confirmation trainer plugin is enabled.
     *
     * @covers \bookingextension_confirmation_trainer\local\confirmbooking
     */
    public function test_confirmation_trainer(): void {
        global $DB;
        $this->resetAfterTest(true);

        // Initial config.
        $env = $this->setup_booking_environment(1, 0);
        $course = $env['course'];
        $option = $env['option'];
        $student1 = $env['users']['student1'];
        $student2 = $env['users']['student2'];
        $student3 = $env['users']['student3'];
        $student4 = $env['users']['student4'];
        $student5 = $env['users']['student5'];
        $teacher = $env['users']['teacher'];
        $manager = $env['users']['manager'];
        $supervisor1 = $env['users']['supervisor1'];
        $hr1 = $env['users']['hr1'];
        $settings = $env['settings'];
        $boinfo = $env['boinfo'];
        $table = $this->get_manage_users_table();

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
        $this->setUser($manager);
        $result = $table->action_confirmbooking(0, json_encode(['id' => $answer->baid])); // Confirm answer.
        $this->assertEquals(1, $result['success']); // Make sure confirmation is successful.

        $this->setUser($student3);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        /*********************************************
         * Book 4th user. Supervisor1 should NOT be able to confirm it.
         *********************************************/
        $this->setUser($student4);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student4->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ASKFORCONFIRMATION, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student4->id);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student4->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        $bookinganswers = singleton_service::get_instance_of_booking_answers($settings);
        $answer = ($bookinganswers->get_users())[$student4->id] ?? null;
        $this->assertNotEmpty($answer);
    }

    /**
     * Test confirmation capability when both the confirmation trainer and confirmation supervisor
     * plugins are disabled, so none of the following roles (admin, teacher, manager, supervisor, HR)
     * can confirm a booking answer.
     * @return void
     * @covers \bookingextension_confirmation_trainer\local\confirmbooking
     * @covers \bookingextension_confirmation_supervisor\local\confirmbooking
     */
    public function test_confirmation_plugins_off(): void {
        global $DB;
        $this->resetAfterTest(true);

        // Initial config.
        $env = $this->setup_booking_environment(0, 0);
        $admin = $env['users']['admin'];
        $student1 = $env['users']['student1'];
        $teacher = $env['users']['teacher'];
        $manager = $env['users']['manager'];
        $supervisor1 = $env['users']['supervisor1'];
        $hr1 = $env['users']['hr1'];
        $settings = $env['settings'];
        $boinfo = $env['boinfo'];
        $table = $this->get_manage_users_table();

        /*********************************************
         * Book a user. Admin, manager, teacher, supervisor & HR should not be able to confirm it.
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

        // Ensure admin,teacher, manager, supervisor & hr cannot confirm it.
        $notallowedusers = [$admin, $teacher, $manager, $supervisor1, $hr1];
        foreach ($notallowedusers as $user) {
            // Ensure user cannot confirm it.
            $this->setUser($user);
            $result = $table->action_confirmbooking(0, json_encode(['id' => $answer->baid])); // Confirm answer.
            $this->assertEquals(0, $result['success']); // Make sure confirmation is not successful.
        }

        // Answer should be still on waiting list.
        $this->setUser($student1);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);
    }

    /**
     * Tests confirmation capability when confirmation trainer plugin is enabled.
     * @param int $order
     * @param array $alloweduserkeys
     * @param array $notalloweduserkeys
     * @param array $confirmations Number of required confirmations
     * @return void
     * @dataProvider confirmation_supervisor_provider
     * @covers \bookingextension_confirmation_supervisor\local\confirmbooking
     */
    public function test_confirmation_supervisor(
        int $order,
        array $alloweduserkeys,
        array $notalloweduserkeys,
        int $requiredconfirmations,
        array $replacements
    ): void {
        global $DB;

        if (!\core_component::get_component_directory('bookingextension_confirmation_supervisor')) {
            $this->markTestSkipped('Subplugin confirmation_supervisor is not available.');
        }

        $this->resetAfterTest(true);

        // Initial config.
        $env = $this->setup_booking_environment(0, $order);
        $users = $env['users'];
        $student1 = $env['users']['student1'];
        $student2 = $env['users']['student2'];
        $settings = $env['settings'];
        $boinfo = $env['boinfo'];
        $mutable = $this->get_manage_users_table(); // Manage users table.

        /*********************************************
         * Book 1st & 2nd users. Supervisor should be able to confirm their answers.
         * Admin, Teacher, Manager & HR should NOT be able to confirm their answers.
         *********************************************/
        // Login as student 1 & book.
        $this->setUser($student1);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ASKFORCONFIRMATION, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id); // Book the first user.
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Login as student 2 & book.
        $this->setUser($student2);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ASKFORCONFIRMATION, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student2->id); // Book the 2nd user.
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        $bookinganswers = singleton_service::get_instance_of_booking_answers($settings);
        $student1answer = ($bookinganswers->get_users())[$student1->id] ?? null; // Get student 1 answer.
        $this->assertNotEmpty($student1answer);
        $student2answer = ($bookinganswers->get_users())[$student2->id] ?? null; // Get student 1 answer.
        $this->assertNotEmpty($student2answer);

        foreach ($notalloweduserkeys as $key) {
            // Ensure the disallowed user cannot confirm any answers.
            $this->setUser($users[$key]);
            // Student 1 answer.
            $result = $mutable->action_confirmbooking(0, json_encode(['id' => $student1answer->baid])); // Confirm answer.
            $this->assertEquals(0, $result['success']); // Make sure confirmation is not successful.
            // Student 2 answer.
            $result = $mutable->action_confirmbooking(0, json_encode(['id' => $student2answer->baid])); // Confirm answer.
            $this->assertEquals(0, $result['success']); // Make sure confirmation is not successful.
        }

        // Answer of student 1 & 2 should be still on waiting list.
        $this->setUser($student1);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        $this->setUser($student2);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        $confirmationscount = 0;
        // Now we heck if allowed users in order can confirm.
        foreach ($alloweduserkeys as $key) {
            // We even check if allowed users (or their replacements) are not able to confirm answer out of the order.
            // So if there is more than one allowed user, we need to make sure that they (or their replacements) can not
            // confirm out of order.
            $outoforder = array_filter($alloweduserkeys, fn($k) => $k !== $key);
            foreach ($outoforder as $wrongkey) {
                // Ensure user cannot confirm it.
                $this->setUser($users[$wrongkey]);
                $result = $mutable->action_confirmbooking(0, json_encode(['id' => $student1answer->baid])); // Confirm answer.
                $this->assertEquals(0, $result['success']); // Make sure confirmation is not successful.

                // Ensure user replacment who can confirm in behalf of approver with $wrongkey is not allowed to confirm the answer.
                if (!empty($replacements[$wrongkey])) {
                    $rkeys = $replacements[$wrongkey];
                    foreach ($rkeys as $userkey) {
                        $this->setUser($users[$userkey]);
                        $result = $mutable->action_confirmbooking(0, json_encode(['id' => $student1answer->baid]));
                        $this->assertEquals(0, $result['success']); // Make sure confirmation is not successful.
                    }
                }
            }

            // Ensure user can confirm it because it their turn.
            $this->setUser($users[$key]);

            // Check the number of records that the user sees in the table.
            // Since we booked options with student 1 and student 2,
            // the approver should see both answers in the table.
            $viewingtable = $this->get_booked_users_table();
            $this->assertCount(2, $viewingtable->rawdata);
            $usersids = array_map(fn($record) => $record->userid, $viewingtable->rawdata);
            $this->assertContains($student1->id, $usersids);
            $this->assertContains($student2->id, $usersids);

            // Now we confirm student 1's booking answer. The approver should be able to confirm it.
            $result = $mutable->action_confirmbooking(0, json_encode(['id' => $student1answer->baid])); // Confirm answer.
            $this->assertEquals(1, $result['success']); // Make sure confirmation is not successful.
            $confirmationscount++;

            // Now we check the records in the table. The record for student 1 should no longer be visible
            // if all approvers have confirmed the answer.
            // We compare the number of required confirmations with the number of confirmations
            // to determine if all approvers have confirmed the answer.
            if ($requiredconfirmations == $confirmationscount) {
                $viewingtable = $this->get_booked_users_table();
                $this->assertCount(1, $viewingtable->rawdata);
                $usersids = array_map(fn($record) => $record->userid, $viewingtable->rawdata);
                $this->assertNotContains($student1->id, $usersids);
                $this->assertContains($student2->id, $usersids);
            }

            // Now the desired allowed user has confirmed the answer. That means the other users who are allowed
            // to confirm in behalf of the desired approver should not be longer possible to confirm the answer.
            // First we check any replacement for the approver is defined. The we try to confirm the the booking answer.
            // This replaced person should not be able to confirm the booking answer.
            if (!empty($replacements[$key])) {
                $rkeys = $replacements[$key];
                foreach ($rkeys as $userkey) {
                    $this->setUser($users[$userkey]);
                    $result = $mutable->action_confirmbooking(0, json_encode(['id' => $student1answer->baid])); // Confirm answer.
                    $this->assertEquals(0, $result['success']); // Make sure confirmation is not successful.
                }
            }
        }

        // Answer should be fully booked.
        $this->setUser($student1);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);
    }

    /**
     * Check if the a shared deputy between 2 supervisors can see the answers of both supervisors
     * while the supervisors should see only the their subordinates.
     *
     * @dataProvider supervisors_with_same_deputy_provider
     * @return void
     * @covers \bookingextension_confirmation_supervisor\local\confirmbooking
     */
    public function test_supervisors_with_same_deputy(string $userkey, array $mustsee, array $mustnotsee): void {
        global $DB;

        if (!\core_component::get_component_directory('bookingextension_confirmation_supervisor')) {
            $this->markTestSkipped('Subplugin confirmation_supervisor is not available.');
        }

        $this->resetAfterTest(true);

        // Initial config.
        $env = $this->setup_booking_environment(0, 1);
        $users = $env['users'];
        $deputy1 = $env['users']['deputy1'];
        $deputy2 = $env['users']['deputy2'];
        $deputy3 = $env['users']['deputy3'];
        $deputy4 = $env['users']['deputy4'];
        $deputy5 = $env['users']['deputy5'];
        $supervisor1 = $env['users']['supervisor1'];
        $supervisor2 = $env['users']['supervisor2'];
        $settings = $env['settings'];
        $boinfo = $env['boinfo'];
        $mutable = $this->get_manage_users_table(); // Manage users table.

        // Set custom profile field 'deputy'. Deputy 1 is shared between supervisor 1 & supervisor 2.
        $deputies = implode(',', [$deputy1->id, $deputy2->id, $deputy3->id]);
        profile_save_data((object)['id' => $supervisor1->id, 'profile_field_deputy' => $deputies]);
        $deputies = implode(',', [$deputy1->id, $deputy4->id, $deputy5->id]);
        profile_save_data((object)['id' => $supervisor2->id, 'profile_field_deputy' => $deputies]);

        // Book options 1 for the students 1 to 5.
        $studnetkeys = ['student1', 'student2', 'student3', 'student4', 'student5'];
        foreach ($studnetkeys as $skey) {
            $this->setUser($users[$skey]);
            booking_bookit::bookit('option', $settings->id, $users[$skey]->id);
        }

        // Get answers.
        $bookinganswers = singleton_service::get_instance_of_booking_answers($settings);
        $this->assertCount(5, $bookinganswers->get_usersonwaitinglist());

        // We have defined in setup_booking_environment function:
        // Supervisor1 is set for students 1,2 & 4.
        // Supervisor1 is set for students 3 & 5.
        // We have follwing expectations:
        // The supervisor 1 should see the answers of students 1,2 & 4.
        // The supervisor 2 should see the answers of students 3 & 5.
        // The deputy 1 should see the answers of all students as it is a selected by both supervisor 1 & supervisor2 as deputy.
        // The deputies 2 & 3 should see the answers of students 1,2 & 4 same as supervisor1.
        // The deputies 4 & 5 should see the answers of students 3 & 5 same as supervisor2.
        // We check each case using data provider.

        // Check the number of records that the user sees in the table based on expectations.
        $this->setUser($users[$userkey]);
        $viewingtable = $this->get_booked_users_table();
        $this->assertCount(count($mustsee), $viewingtable->rawdata);
        // Check if user can see their allowed records and able to confirm that.
        // Extract user IDs of fetched booking answers.
        $usersids = array_map(fn($record) => $record->userid, $viewingtable->rawdata);
        foreach ($mustsee as $allowedkey) {
            $this->setUser($users[$userkey]); // Switch to approver.
            // Check if user ID of allowed student exists in fetched records.
            $this->assertContains($users[$allowedkey]->id, $usersids);
            $answer = ($bookinganswers->get_users())[$users[$allowedkey]->id] ?? null; // Get student's answer.
            $this->assertNotEmpty($answer);
            $result = $mutable->action_confirmbooking(0, json_encode(['id' => $answer->baid]));
            $this->assertEquals(1, $result['success']); // Make sure confirmation is successful.
            // switch user to student to see if their response is really confirmed.
            $this->setUser($users[$allowedkey]);
            [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $users[$allowedkey]->id, true);
            $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);
        }

        // Ensure user cannot see their forbiiden records.
        foreach ($mustnotsee as $forbiddenkey) {
            $this->setUser($users[$userkey]); // Switch to approver.
            // Ensure the user ID of now allowed student not exists in fetched records.
            $this->assertNotContains($users[$forbiddenkey]->id, $usersids);
            $answer = ($bookinganswers->get_users())[$users[$forbiddenkey]->id] ?? null; // Get student's answer.
            $this->assertNotEmpty($answer);
            $result = $mutable->action_confirmbooking(0, json_encode(['id' => $answer->baid]));
            $this->assertEquals(0, $result['success']); // Make sure confirmation is successful.
            // switch user to student to ensure their response is not confirmed.
            $this->setUser($users[$allowedkey]);
            [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $users[$forbiddenkey]->id, true);
            $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);
        }
    }

    /**
     * Test if confirmation meets the expectations when both confirmation_trainer
     * and confirmation_supervisor plugin are enabled.
     * @return void
     * @dataProvider confirmation_mixed_provider
     * @covers \bookingextension_confirmation_trainer\local\confirmbooking
     * @covers \bookingextension_confirmation_supervisor\local\confirmbooking
     *
     */
    public function test_confirmation_mixed(): void {
        // TODO: MDL-0 Some options use cofirmation trainer, some use confirmation supervisor.
        // Make sure results correspond to expectations.
        $this->assertTrue(true);
    }

    /**
     * Data provider for test_confirmation_supervisor.
     *
     * @return array[]
     */
    public static function confirmation_supervisor_provider(): array {
        return [
            'Only supervsior --> by Supervisor' => [
                1, // Confirmation order.
                ['supervisor1'], // Allowed users (Order of keys is important).
                ['admin', 'teacher', 'manager', 'hr1'], // Not allowed users.
                1, // Number of required confirmations.
                ['supervisor1' => ['deputy1', 'deputy2']], // Users replacements.
            ],
            'Only supervsior --> by Deputy 1' => [
                1,
                ['deputy1'],
                ['admin', 'teacher', 'manager', 'hr1', 'hr2'],
                1,
                ['deputy1' => ['deputy2', 'supervisor1']],
            ],
            'Only supervsior --> by Deputy 2' => [
                1,
                ['deputy2'],
                ['admin', 'teacher', 'manager', 'hr1'],
                1,
                ['deputy1' => ['deputy2', 'supervisor1']],
            ],
            'HR then supervisor --> by Supervisor 1' => [
                2,
                ['hr1', 'supervisor1'],
                ['admin', 'teacher', 'manager'],
                2,
                ['supervisor1' => ['deputy1', 'deputy2'], 'hr1' => ['hr2']],
            ],
            'HR then supervisor --> by Deputy 1' => [
                2,
                ['hr1', 'deputy1'],
                ['admin', 'teacher', 'manager'],
                2,
                ['deputy1' => ['deputy2', 'supervisor1'], 'h1' => ['hr2']],
            ],
            'HR then supervisor --> by Deputy 2' => [
                2,
                ['hr1', 'deputy2'],
                ['admin', 'teacher', 'manager'],
                2,
                ['deputy2' => ['deputy1', 'supervisor1'], 'h1' => ['hr2']],
            ],
            'Only HR --> by HR1' => [
                3,
                ['hr1'],
                ['admin', 'teacher', 'manager', 'supervisor1', 'deputy1', 'deputy2'],
                1,
                ['hr1' => ['hr2']],
            ],
            'Only HR --> by HR2' => [
                3,
                ['hr2'],
                ['admin', 'teacher', 'manager', 'supervisor1'],
                1,
                ['hr' => ['hr1']],
            ],
            'Supervisor then HR --> by Supervisor 1' => [
                4,
                ['supervisor1', 'hr1'],
                ['admin', 'teacher', 'manager'],
                2,
                ['supervisor1' => ['deputy1', 'deputy2'], 'hr1' => ['hr2']],
            ],
            'Supervisor then HR --> by Deputy 1' => [
                4,
                ['deputy1', 'hr1'],
                ['admin', 'teacher', 'manager'],
                2,
                ['deputy1' => ['deputy2', 'supervisor1'], 'h1' => ['hr2']],
            ],
            'Supervisor then HR --> by Deputy 2' => [
                4,
                ['deputy2', 'hr1'],
                ['admin', 'teacher', 'manager'],
                2,
                ['deputy2' => ['deputy1', 'supervisor1'], 'h1' => ['hr2']],
            ],
            'Supervisor or HR --> by HR' => [
                5,
                ['hr1'],
                ['admin', 'teacher', 'manager'],
                1,
                ['hr1' => ['hr2', 'supervisor1', 'deputy1', 'deputy2']],
            ],
            'Supervisor or HR --> by Supervisor' => [
                5,
                ['supervisor1'],
                ['admin', 'teacher', 'manager'],
                1,
                ['supervisor1' => ['hr1', 'hr2', 'deputy1', 'deputy2']],
            ],
            'Supervisor or HR --> by Deputy1' => [
                5,
                ['deputy1'],
                ['admin', 'teacher', 'manager'],
                1,
                ['deputy1' => ['hr1', 'hr2', 'supervisor1', 'deputy2']],
            ],
        ];
    }

    /**
     * Data provider for test_supervisors_with_same_deputy.
     *
     * @return array[]
     */
    public static function supervisors_with_same_deputy_provider(): array {
        return [
            'supervisor1' => [
                'supervisor1', // User.
                ['student1', 'student2', 'student4'], // Must see.
                ['student3', 'student5'], // Must not see.
            ],
            'supervisor2' => [
                'supervisor2',
                ['student3', 'student5'],
                ['student1', 'student2', 'student4'],
            ],
            'deputy1' => [
                'deputy1',
                ['student1', 'student2', 'student3', 'student4', 'student5'],
                [],
            ],
            'deputy2' => [
                'deputy2',
                ['student1', 'student2', 'student4'],
                ['student3', 'student5'],
            ],
            'deputy3' => [
                'deputy3',
                ['student1', 'student2', 'student4'],
                ['student3', 'student5'],
            ],
            'deputy4' => [
                'deputy4',
                ['student3', 'student5'],
                ['student1', 'student2', 'student4'],
            ],
            'deputy5' => [
                'deputy5',
                ['student3', 'student5'],
                ['student1', 'student2', 'student4'],
            ],
        ];
    }

    /**
     * Data provider for test_confirmation_mixed.
     *
     * @return array[]
     */
    public static function confirmation_mixed_provider(): array {
        return [
            'by admin' => [
                5, // Active confirmation supervisor order.
                'admin', // User key as approver.
                ['student1', 'student2', 'student3', 'student4', 'student5'], // User must see the answers of these keys.
                [], // User must not see te answers of this keys.
            ],
            'by supervisor1' => [
                5,
                'supervisor1',
                ['student1', 'student2', 'student4', 'student3', 'student5'],
                [],
            ],
            'by supervisor2' => [
                5,
                'supervisor2',
                ['student1', 'student2', 'student4', 'student3', 'student5'],
                [],
            ],
            'by teacher' => [
                5, // Active confirmation supervisor order.
                'teacher', // User key as approver.
                ['student1', 'student2', 'student3', 'student4', 'student5'], // User must see the answers of these keys.
                [], // User must not see te answers of this keys.
            ],
        ];
    }

    /**
     * Intantiates a manageusers_table.
     * @return manageusers_table
     */
    private function get_manage_users_table(): manageusers_table {
        $ba = new booking_answers();
        $scope = 'optionstoconfirm';
        $scopeid = 0;
        $tablenameprefix = 'test';
        $tablename = "{$tablenameprefix}_{$scope}_{$scopeid}";
        $table = new manageusers_table($tablename);
        return $table;
    }

    /**
     * This function returns the table that the approver will see in the UI.
     * With this table, we can determine the actual records that will be returned to the approver.
     *
     * @return wunderbyte_table|null
     */
    private function get_booked_users_table(): wunderbyte_table|null {
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

    /**
     * Creates a custom user profile field.
     *
     * @param string $shortname Field shortname (e.g. 'supervisor')
     * @param string $name Field name shown in UI
     * @return void
     */
    private function create_custom_profile_field(string $shortname, string $name): void {
        global $DB;

        // Insert into user_info_field_category (required).
        if (!$DB->record_exists('user_info_category', ['name' => 'Test Category'])) {
            $cat = new \stdClass();
            $cat->name = 'Test Category';
            $cat->sortorder = 1;
            $cat->id = $DB->insert_record('user_info_category', $cat);
        } else {
            $cat = $DB->get_record('user_info_category', ['name' => 'Test Category']);
        }

        // Define the profile field.
        $field = new \stdClass();
        $field->shortname = $shortname;
        $field->name = $name;
        $field->datatype = 'text'; // Could be 'text', 'menu', 'checkbox', etc.
        $field->description = '';
        $field->descriptionformat = FORMAT_HTML;
        $field->categoryid = $cat->id;
        $field->sortorder = 1;
        $field->required = 0;
        $field->locked = 0;
        $field->visible = 1;
        $field->forceunique = 0;
        $field->signup = 0;
        $field->defaultdata = '';
        $field->defaultdataformat = FORMAT_HTML;
        $field->param1 = 30; // Max length for 'text'.
        $field->id = $DB->insert_record('user_info_field', $field);
    }

    /**
     *
     */
    private function grant_capability_to_role(string $rolename, string $capability, \core\context $context) {
        global $DB;
        $roleid = $DB->get_field('role', 'id', ['shortname' => $rolename], MUST_EXIST);
        assign_capability($capability, CAP_ALLOW, $roleid, $context->id, true);
        // Clear access caches so changes take effect in the test run.
        accesslib_clear_all_caches_for_unit_testing();
    }
}
