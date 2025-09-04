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
 * Tests for booking when musltiple bookings is enabled.
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2025 Mahdi Poustini
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
final class booking_test extends advanced_testcase {
    /**
     * Creates booking course, users, and booking option with given settings.
     * @return array
     */
    private function setup_booking_environment(): array {
        global $DB, $CFG, $USER;

        require_once($CFG->dirroot . '/user/profile/lib.php');

        $this->setAdminUser();

        set_config(
            'confirmationtrainerenabled',
            1,
            'bookingextension_confirmation_trainer'
        );

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $coursecotext = \context_course::instance($course->id);

        // Create users.
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();

        $this->getDataGenerator()->enrol_user($student1->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course->id, 'student');

        // Create booking module.
        $bookingmanager = $this->getDataGenerator()->create_user();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'bookingmanager' => $bookingmanager->username,
            'cancancelbook' => 1,
        ]);

        return [
            'course' => $course,
            'bookingmodule' => $booking,
            'users' => [
                'student1' => $student1,
                'student2' => $student2,
            ],
        ];
    }

    /**
     * Tests confirmation capability when confirmation trainer plugin is enabled.
     *
     * @dataProvider booking_provider
     * @covers \bookingextension_confirmation_trainer\local\confirmbooking
     */
    public function test_booking($otherbookingoptionsettings, string $userbookingfunction): void {
        global $DB;
        $this->resetAfterTest(true);

        // Initial config.
        $env = $this->setup_booking_environment();
        $course = $env['course'];
        $bookingmodule = $env['bookingmodule'];
        $student1 = $env['users']['student1'];
        $table = $this->get_manage_users_table();

        // Create booking option.
        /** @var mod_booking_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_booking');

        $option = $generator->create_option((object)array_merge(
            [
                'bookingid' => $bookingmodule->id,
                'courseid' => $course->id,
                'text' => 'Option test',
                'chooseorcreatecourse' => 1,
            ],
            $otherbookingoptionsettings
        ));

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boinfo = new bo_info($settings);

        call_user_func([$this, $userbookingfunction], $boinfo, $settings, $student1);

        $this->setUser($student1);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);
    }

    /**
     * Checks the expections when the option needs no confirmation then books the option.
     * @param mixed $boinfo
     * @param mixed $settings
     * @param mixed $student
     * @return void
     */
    public function student_books($boinfo, $settings, $student) {
        $this->setUser($student);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student->id); // Book the first user.

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMBOOKIT, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student->id); // Book the first user.

        $bookinganswers = singleton_service::get_instance_of_booking_answers($settings);
        $answer = ($bookinganswers->get_users())[$student->id] ?? null; // Get student 1 answer.
        $this->assertNotEmpty($answer);
    }

    /**
     * Checks the expections when the option needs confirmation then books the option.
     * @param mixed $boinfo
     * @param mixed $settings
     * @param mixed $student
     * @return void
     */
    public function student_books_on_waiting_list($boinfo, $settings, $student) {
        $this->setUser($student);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ASKFORCONFIRMATION, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student->id); // Book the first user.

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        $bookinganswers = singleton_service::get_instance_of_booking_answers($settings);
        $answer = ($bookinganswers->get_users())[$student->id] ?? null; // Get student 1 answer.
        $this->assertNotEmpty($answer);

        $this->setAdminUser(); // Switch user - admin.
        $table = $this->get_manage_users_table();
        $result = $table->action_confirmbooking(0, json_encode(['id' => $answer->baid]));
        $this->assertEquals(1, $result['success']); // Make sure confirmation is successful.
    }

    /**
     * Data provider for booking.
     * @return array
     */
    public static function booking_provider(): array {
        return [
            'Option' => [
                'bookingsoptionsettings' => [], // Additional booking options settings.
                'student_books', // Student book function.
            ],

            'Option - with multiople bookings' => [
                'bookingsoptionsettings' => [
                    'waitforconfirmation' => 1,
                    'confirmationtrainerenabled' => 1,
                ],
                'student_books_on_waiting_list',
            ],
            /*
            'Option - with multiople bookings - with price' => [
                'bookingsoptionsettings' => [
                    'text' => 'Option 1',
                    'chooseorcreatecourse' => 1,
                    'waitforconfirmation' => 1,
                    'confirmationtrainerenabled' => 1,
                ],
            ],
            */
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
}
