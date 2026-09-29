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
use mod_booking\singleton_service;
use mod_booking\booking_bookit;
use mod_booking\bo_availability\bo_info;
use mod_booking\booking_answers\booking_answers;
use mod_booking\table\manageusers_table;
use mod_booking_generator;
use context_system;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/lib/accesslib.php');

/**
 * Tests for Confirmation workflow by supervisor
 *
 * @package    bookingextension_confirmation_supervisor
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @author     2026 Mahdi Poustini
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class capability_alwayscanconfirm_test extends advanced_testcase {
    /**
     * Creates booking course, users, and booking option with given settings.
     * @param int $confirmationsupervisororder
     * @return array
     */
    private function setup_booking_environment(int $confirmationsupervisororder): array {
        global $DB, $CFG, $USER;

        require_once($CFG->dirroot . '/user/profile/lib.php');

        $this->setAdminUser();

        $admin = $USER;

        set_config('confirmationtrainerenabled', 0, 'bookingextension_confirmation_trainer');
        set_config('confirmationsupervisorenabled', 1, 'bookingextension_confirmation_supervisor');

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

        // Create booking module.
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
            'confirmationsupervisorenabled' => $confirmationsupervisororder,
        ]);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boinfo = new bo_info($settings);

        // Enrol always admin, teacher, manager & students to course.
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($manager->id, $course->id, 'manager');
        $this->getDataGenerator()->enrol_user($student1->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student3->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student4->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student5->id, $course->id, 'student');

        // Create a test role and assign the alwayscanconfirm capability to it, then assign to admin and manager.
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('mod/booking:alwayscanapprove', CAP_ALLOW, $roleid, context_system::instance());
        role_assign($roleid, $admin->id, context_system::instance()->id);
        role_assign($roleid, $manager->id, context_system::instance()->id);

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
            ],
        ];
    }

    /**
     * Tests confirmation capability when confirmation trainer plugin is enabled.
     * @param int $order
     * @param array $alloweduserkeys
     * @return void
     * @dataProvider confirmation_provider
     * @covers \bookingextension_confirmation_supervisor\local\confirmbooking
     */
    public function test_confirmation(
        int $order,
        array $alloweduserkeys,
    ): void {
        global $DB;

        $this->resetAfterTest(true);

        // Initial config.
        $env = $this->setup_booking_environment($order);
        $users = $env['users'];
        $student1 = $env['users']['student1'];
        $student2 = $env['users']['student2'];
        $settings = $env['settings'];
        $boinfo = $env['boinfo'];
        $mutable = $this->get_manage_users_table(); // Manage users table.

        /*********************************************
         * Book 1st & 2nd users. Admin and anyone with alwayscanapprove should be able to approve.
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

        // Now we heck if allowed users in order can confirm.
        foreach ($alloweduserkeys as $key) {
            // Ensure user can confirm it because it their turn.
            $this->setUser($users[$key]);
            // Now we confirm student 1's booking answer. The approver should be able to confirm it.
            $result = $mutable->action_confirmbooking(0, json_encode(['id' => $student1answer->baid])); // Confirm answer.
            $this->assertEquals(1, $result['success']); // Make sure confirmation is not successful.

            // Now we confirm student 2's booking answer. The approver should be able to confirm it.
            $result = $mutable->action_confirmbooking(0, json_encode(['id' => $student2answer->baid])); // Confirm answer.
            $this->assertEquals(1, $result['success']); // Make sure confirmation is not successful.
        }

        // Answer should be fully booked.
        $this->setUser($student1);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);
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
     * Data provider for test_confirmation_supervisor.
     *
     * @return array[]
     */
    public static function confirmation_provider(): array {
        return [
            'Only supervsior --> by Admin' => [
                1, // Confirmation order.
                ['admin'], // Allowed users (Order of keys is important).
            ],
            'Only supervsior --> by Manager' => [
                1,
                ['manager'],
            ],
            'HR then supervisor --> Admin then Admin' => [
                2,
                ['admin', 'admin'],
            ],
            'HR then supervisor --> by Manager then Admin' => [
                2,
                ['manager', 'admin'],
            ],
            'Only HR --> by Admin' => [
                3,
                ['admin'],
            ],
            'Only HR --> by Manager' => [
                3,
                ['manager'],
            ],
            'Supervisor then HR --> Admin then Manager' => [
                4,
                ['admin', 'manager'],
            ],
            'Supervisor or HR --> by Admin' => [
                5,
                ['admin'],
            ],
            'Supervisor or HR --> by Manager' => [
                5,
                ['manager'],
            ],
        ];
    }
}
