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

        $this->grant_capability_to_role('teacher', 'moodle/course:view', $coursecotext);
        $this->grant_capability_to_role('teacher', 'moodle/course:update', $coursecotext);
        $this->grant_capability_to_role('manager', 'moodle/course:view', $coursecotext);
        $this->grant_capability_to_role('manager', 'moodle/course:update', $coursecotext);

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
        role_assign($approverroleid, $manager->id, $syscontext->id);
        role_assign($approverroleid, $teacher->id, $syscontext->id);
        role_assign($approverroleid, $supervisor1->id, $syscontext->id);
        role_assign($approverroleid, $supervisor2->id, $syscontext->id);
        role_assign($approverroleid, $hr1->id, $syscontext->id);
        role_assign($approverroleid, $hr2->id, $syscontext->id);
        role_assign($approverroleid, $deputy1->id, $syscontext->id);
        role_assign($approverroleid, $deputy2->id, $syscontext->id);
        role_assign($approverroleid, $deputy3->id, $syscontext->id);

        // Enrol always admin, teacher, manager & students to course.
        $this->getDataGenerator()->enrol_user($admin->id, $course->id);
        $this->getDataGenerator()->enrol_user($student1->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'teacher');
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

        // Ensure supervisor cannot confirm it.
        $this->setUser($supervisor1);
        $result = $table->action_confirmbooking(0, json_encode(['id' => $answer->baid])); // Confirm answer.
        $this->assertEquals(0, $result['success']); // Make sure confirmation is successful.

        $this->setUser($student4);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student4->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        /*********************************************
         * Book 5th user. HR1 should NOT be able to confirm it.
         *********************************************/
        $this->setUser($student5);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student5->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ASKFORCONFIRMATION, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student5->id);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student5->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        $bookinganswers = singleton_service::get_instance_of_booking_answers($settings);
        $answer = ($bookinganswers->get_users())[$student5->id] ?? null;
        $this->assertNotEmpty($answer);

        // Ensure HR cannot confirm it.
        $this->setUser($hr1);
        $result = $table->action_confirmbooking(0, json_encode(['id' => $answer->baid])); // Confirm answer.
        $this->assertEquals(0, $result['success']); // Make sure confirmation is successful.

        $this->setUser($student4);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student5->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);
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
        $table = $this->get_table();

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
        int $confirmations,
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
        $settings = $env['settings'];
        $boinfo = $env['boinfo'];
        $table = $this->get_table();

        /*********************************************
         * Book 1st user. Supervisor should be able to confirm it. Admin, Teacher, Manager & HR should NOT be able to confirm it.
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

        foreach ($notalloweduserkeys as $key) {
            // Ensure user cannot confirm it.
            $this->setUser($users[$key]);
            $result = $table->action_confirmbooking(0, json_encode(['id' => $answer->baid])); // Confirm answer.
            $this->assertEquals(0, $result['success']); // Make sure confirmation is not successful.
        }

        // Answer should be still on waiting list.
        $this->setUser($student1);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Now we heck if allowed users in order can confirm.
        foreach ($alloweduserkeys as $key) {
            // We even check if allowed users (or their replacements) are not able to confirm answer out of the order.
            // So if there is more than one allowed user, we need to make sure that they (or their replacements) can not
            // confirm out of order.
            $outoforder = array_filter($alloweduserkeys, fn($k) => $k !== $key);
            foreach ($outoforder as $wrongkey) {
                // Ensure user cannot confirm it.
                $this->setUser($users[$wrongkey]);
                $result = $table->action_confirmbooking(0, json_encode(['id' => $answer->baid])); // Confirm answer.
                $this->assertEquals(0, $result['success']); // Make sure confirmation is not successful.

                // Ensure user replacment who can confirm in behalf of approver with $wrongkey is not allowed to confirm the answer.
                if (!empty($replacements[$wrongkey])) {
                    $rkeys = $replacements[$wrongkey];
                    foreach ($rkeys as $userkey) {
                        $this->setUser($users[$userkey]);
                        $result = $table->action_confirmbooking(0, json_encode(['id' => $answer->baid])); // Confirm answer.
                        $this->assertEquals(0, $result['success']); // Make sure confirmation is not successful.
                    }
                }
            }

            // Ensure user can confirm it because it his turn.
            $this->setUser($users[$key]);
            $result = $table->action_confirmbooking(0, json_encode(['id' => $answer->baid])); // Confirm answer.
            $this->assertEquals(1, $result['success']); // Make sure confirmation is not successful.

            // Now the desired allowed user has confirmed the answer. That means the other users who are alloweerd
            // to confirm in behalf of the desired approover should not be longer possible to conform the answer.
            // First we check any replacement for the approver is defined. The we try to confirm the the booking answer.
            // This replaced person should not be able to confirm the booking answer.
            if (!empty($replacements[$key])) {
                $rkeys = $replacements[$key];
                foreach ($rkeys as $userkey) {
                    $this->setUser($users[$userkey]);
                    $result = $table->action_confirmbooking(0, json_encode(['id' => $answer->baid])); // Confirm answer.
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
     * Test if confirmation meets the expectations when both confirmation_trainer
     * and confirmation_supervisor plugin are enabled.
     * @return void
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
