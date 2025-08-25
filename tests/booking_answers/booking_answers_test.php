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
use stdClass;
use mod_booking_generator;

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
final class booking_answers_test extends advanced_testcase {
    /**
     * Tests booking answer class.
     *
     * @covers \mod_booking\booking_answers\booking_answers
     */
    public function test_booking_answers_all_methods(): void {
        global $DB;

        $this->resetAfterTest(true);

        $this->setAdminUser();

        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

        // Setup test data.

        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $student3 = $this->getDataGenerator()->create_user();
        $student4 = $this->getDataGenerator()->create_user();
        $student5 = $this->getDataGenerator()->create_user();
        $student6 = $this->getDataGenerator()->create_user();
        $student7 = $this->getDataGenerator()->create_user();

        // Create courses.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create a booking module.
        $booking1 = $this->getDataGenerator()->create_module('booking', [
            'name' => 'Booking module 1',
            'course' => $course1->id,

        ]);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create booking option 1. Without price. No confirmation.
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course1->id;
        $record->maxanswers = 3;
        $record->maxoverbooking = 3;
        $record->waitforconfirmation = 0;
        $option1 = $plugingenerator->create_option($record);
        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $boinfo1 = new bo_info($settings1);

        // Create booking option 2. Without price. Book always after confirmation.
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option2';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course1->id;
        $record->maxanswers = 3;
        $record->maxoverbooking = 3;
        $record->waitforconfirmation = 1;
        $option2 = $plugingenerator->create_option($record);
        $settings2 = singleton_service::get_instance_of_booking_option_settings($option2->id);
        $boinfo2 = new bo_info($settings2);

        // Create booking option 3. Without price. Confirmation only for waiting list.
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option3';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course1->id;
        $record->maxanswers = 3;
        $record->maxoverbooking = 3;
        $record->waitforconfirmation = 2;
        $option3 = $plugingenerator->create_option($record);
        $settings3 = singleton_service::get_instance_of_booking_option_settings($option3->id);
        $boinfo3 = new bo_info($settings2);

        // We start booking options for users. And check if everything is as expected.

        // Book option 1 for student 1.
        booking_bookit::bookit('option', $settings1->id, $student1->id); // Try to book.
        booking_bookit::bookit('option', $settings1->id, $student1->id); // Confirm booking.
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id); // Check if booked.
        $bookinganswers1 = singleton_service::get_instance_of_booking_answers($settings1);
        $this->assertCount(1, $bookinganswers1->get_users());  // Total of booked users & users on waiting list.
        $this->assertCount(1, $bookinganswers1->get_usersonlist());  // Total of booked users.
        $this->assertCount(0, $bookinganswers1->get_usersonwaitinglist());  // Total users on waiting list.
        $this->assertCount(0, $bookinganswers1->get_usersdeleted());  // Total of deleted users.
        // Book option 1 for student 2 & 3.
        booking_bookit::bookit('option', $settings1->id, $student2->id);
        booking_bookit::bookit('option', $settings1->id, $student2->id);
        booking_bookit::bookit('option', $settings1->id, $student3->id);
        booking_bookit::bookit('option', $settings1->id, $student3->id);
        $bookinganswers1 = singleton_service::get_instance_of_booking_answers($settings1);
        $this->assertCount(3, $bookinganswers1->get_users());  // Total of booked & on waiting list users.

        // Book option 1 for student 4, 5 & 6. Must be on waiting list.
        booking_bookit::bookit('option', $settings1->id, $student4->id);
        booking_bookit::bookit('option', $settings1->id, $student4->id);
        booking_bookit::bookit('option', $settings1->id, $student5->id);
        booking_bookit::bookit('option', $settings1->id, $student5->id);
        booking_bookit::bookit('option', $settings1->id, $student6->id);
        booking_bookit::bookit('option', $settings1->id, $student6->id);
        $bookinganswers1 = singleton_service::get_instance_of_booking_answers($settings1);
        $this->assertCount(6, $bookinganswers1->get_users());
        $this->assertCount(3, $bookinganswers1->get_usersonlist());
        $this->assertCount(3, $bookinganswers1->get_usersonwaitinglist());
        $this->assertCount(0, $bookinganswers1->get_usersdeleted());

        // More students sould see fully booked as no more free palces is available.
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student7->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_FULLYBOOKED, $id); // Check if booked.

        // Student 1 cancels. In this case:
        // - We souldn't see more in booked users but should see in deleted ones.
        // - Number of booked should be eqaul to 5.
        // - The student 4 on the waiting list must be moved to booked users.
        // - The persons on waiting list should be equal to 2.
        singleton_service::destroy_booking_option_singleton($option1->id);
        $option1 = singleton_service::get_instance_of_booking_option($settings1->cmid, $settings1->id);

        $this->setAdminUser();
        $option1->delete_responses([$student1->id]);
        $bookinganswers1 = singleton_service::get_instance_of_booking_answers($settings1);

        $this->assertCount(5, $bookinganswers1->get_answers());
        $this->assertCount(5, $bookinganswers1->get_users());
        $this->assertCount(3, $bookinganswers1->get_usersonlist());
        $this->assertCount(2, $bookinganswers1->get_usersonwaitinglist());
    }
}
