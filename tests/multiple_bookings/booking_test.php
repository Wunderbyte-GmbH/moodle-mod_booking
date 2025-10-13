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
use tool_mocktesttime\time_mock;
use local_shopping_cart\shopping_cart;
use local_shopping_cart\local\cartstore;
use local_shopping_cart_generator;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests booking when multiple bookings is enabled.
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
            'cancancelbook' => 0,
        ]);

        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));

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
     * This function gets the predefined environment settings and then creates an option based on the provided data.
     * It then calls the book function to book the option. In some cases, it rebooks the option to check if everything
     * is working correctly when multiple booking is enabled.
     *
     * @dataProvider booking_provider
     * @covers \bookingextension_confirmation_trainer\local\confirmbooking
     * @param string $userbookingfunction
     * @param array $otherbookingoptionsettings
     * @param int $clockforwardshift
     * @param array $expected
     * @param int $tryrebooking
     * @return void
     */
    public function test_booking(
        string $userbookingfunction,
        array $otherbookingoptionsettings,
        int $clockforwardshift,
        array $expected,
        int $tryrebooking
    ): void {
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

        // If userprice is available, we need to create a credit for the user and create a price category.
        if (!empty($otherbookingoptionsettings['useprice'])) {
            /** @var local_shopping_cart_generator $shoppingcartgenerator */
            $shoppingcartgenerator = self::getDataGenerator()->get_plugin_generator('local_shopping_cart');
            $usercreditdata = [
                'userid' => $student1->id,
                'credit' => 100,
                'currency' => 'EUR',
            ];
            $shoppingcartgenerator->create_user_credit($usercreditdata);

            $pricecategorydata = (object) [
                'ordernum' => 1,
                'name' => 'default',
                'identifier' => 'default',
                'defaultvalue' => 100,
                'pricecatsortorder' => 1,
            ];

            $generator->create_pricecategory($pricecategorydata);
        }

        // Merge the default booking option settings with the provided ones from the data provider.
        $bookingoptionsettings = array_merge(
            [
                'bookingid' => $bookingmodule->id,
                'courseid' => $course->id,
                'text' => 'Option test',
                'chooseorcreatecourse' => 1,
            ],
            $otherbookingoptionsettings
        );
        // Create a booking option.
        $option = $generator->create_option((object)$bookingoptionsettings);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boinfo = new bo_info($settings);

        $data['userbookingfunction'] = $userbookingfunction;
        $data['boinfo'] = $boinfo;
        $data['settings'] = $settings;
        $data['student1'] = $student1;
        $data['clockforwardshift'] = $clockforwardshift;
        $data['expected'] = $expected;

        // Book for the first time and check if expectations are met.
        $this->book($data);

        // We need to book the option again to verify that everything works correctly, but only if
        // multiple bookings are enabled and the required time has passed since the previous booking.
        if (!empty($tryrebooking)) {
            $this->book($data);
            $answers = $DB->get_records('booking_answers', null, 'timecreated ASC');
            $this->assertCount(2, $answers);
            $answers = array_values($answers);
            $this->assertSame(MOD_BOOKING_STATUSPARAM_PREVIOUSLYBOOKED, (int) $answers[0]->waitinglist);
            $this->assertSame(MOD_BOOKING_STATUSPARAM_BOOKED, (int) $answers[1]->waitinglist);

            // Book for the N time and check if expectations are met.
            for ($i = 3; $i <= 10; $i++) {
                $this->book($data);
                $answers = $DB->get_records('booking_answers', null, 'timecreated ASC');
                $this->assertCount($i, $answers);
                $answers = array_values($answers);
                // The waitinglist column should have value equal to MOD_BOOKING_STATUSPARAM_PREVIOUSLYBOOKED
                // for all the answers expect the last one.
                for ($ai = 0; $ai < ($i - 1); $ai++) {
                    $this->assertSame(
                        MOD_BOOKING_STATUSPARAM_PREVIOUSLYBOOKED,
                        (int) $answers[$ai]->waitinglist
                    );
                }
                // The waitinglist column value of the last answer should be equal to MOD_BOOKING_STATUSPARAM_BOOKED.
                $this->assertSame(
                    MOD_BOOKING_STATUSPARAM_BOOKED,
                    (int) $answers[count($answers) - 1]->waitinglist
                );
            }
        }
    }

    /**
     * Books an option.
     * @param mixed $data
     * @return void
     */
    private function book($data) {
        $userbookingfunction = $data['userbookingfunction'];
        $boinfo = $data['boinfo'];
        $settings = $data['settings'];
        $student1 = $data['student1'];
        $clockforwardshift = $data['clockforwardshift'];
        $expected = $data['expected'];
        // Call the provided function from the data provider to book the option once,
        // and check if any exceptions in the function are met.
        call_user_func([$this, $userbookingfunction], $boinfo, $settings, $student1);

        // After calling the provided function from the data provider, the option should be booked.
        $this->setUser($student1);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // There should be a booked answer.
        $bookinganswers = singleton_service::get_instance_of_booking_answers($settings);
        $answers = $bookinganswers->get_users();
        $this->assertCount(1, $answers);
        $answer = $answers[$student1->id] ?? null; // Get student 1 answer.
        $this->assertNotEmpty($answer);
        $this->assertSame(MOD_BOOKING_STATUSPARAM_BOOKED, (int) $answer->waitinglist);

        // We advance the time and check bo_availabilty to see if expectation are met.
        $time = time_mock::get_mock_time();
        $this->assertSame(time(), $time);
        time_mock::set_mock_time($time + $clockforwardshift); // Jump N seconds into the future.
        $future = time_mock::get_mock_time();
        $this->assertEquals(time(), $future);

        // No we are in future, we will check the booking option availability.
        // It should match the expected value from the data provider.
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals($expected['bo_availability'], $id);
    }

    /**
     * Data provider for booking.
     * @return array
     */
    public static function booking_provider(): array {
        return [
            'Option - Multiple: No, Confirmation: No, Price: No' => [
                'student_books_without_price', // Name of the function within we can book the option.
                'otherbookingoptionsettings' => [], // Additional booking options settings.
                'clockforwardshift' => 0, // Amount of time to add to clock to forward the clock.
                'expected' => [ // Expections to book again.
                    'bo_availability' => MOD_BOOKING_BO_COND_ALREADYBOOKED, // Expected amount of bo_availability
                    // when attempting to book the option for the seconds time.
                ],
                'tryrebooking' => 0,
            ],
            'Option - Multiple: Yes, Confirmation: No, Price: No, After: after 30 seconds' => [
                'student_books_without_price',
                'otherbookingoptionsettings' => [
                    'waitforconfirmation' => 0,
                    'multiplebookings' => 1,
                    'allowtobookagainafter' => 60, // 60 seconds.
                ],
                'clockforwardshift' => 30,
                'expected' => [
                    'bo_availability' => MOD_BOOKING_BO_COND_ALREADYBOOKED, // Clockforwardshift > allowtobookagainafter.
                ],
                'tryrebooking' => 0,
            ],
            'Option - Multiple: Yes, Confirmation: No, Price: No, After: 70 seconds' => [
                'student_books_without_price',
                'otherbookingoptionsettings' => [
                    'waitforconfirmation' => 0,
                    'multiplebookings' => 1,
                    'allowtobookagainafter' => 60, // 60 seconds.
                ],
                'clockforwardshift' => 70,
                'expected' => [
                    'bo_availability' => MOD_BOOKING_BO_COND_BOOKITBUTTON, // Clockforwardshift > allowtobookagainafter.
                ],
                'tryrebooking' => 1,
            ],
            'Option - Multiple: Yes, Confirmation: Yes, Price: No, After: 30 seconds' => [
                'student_books_without_price_on_waiting_list',
                'otherbookingoptionsettings' => [
                    'waitforconfirmation' => 1,
                    'confirmationtrainerenabled' => 1,
                    'multiplebookings' => 1,
                    'allowtobookagainafter' => 60, // 60 seconds.
                ],
                'clockforwardshift' => 30,
                'expected' => [
                    'bo_availability' => MOD_BOOKING_BO_COND_ALREADYBOOKED, // Clockforwardshift < allowtobookagainafter.
                ],
                'tryrebooking' => 0,
            ],
            'Option - Multiple: Yes, Confirmation: Yes, Price: No, After: 60 seconds' => [
                'student_books_without_price_on_waiting_list',
                'otherbookingoptionsettings' => [
                    'waitforconfirmation' => 1,
                    'confirmationtrainerenabled' => 1,
                    'multiplebookings' => 1,
                    'allowtobookagainafter' => 60, // 60 seconds.
                    'maxperuser' => 1, // Test for https://github.com/Wunderbyte-GmbH/moodle-mod_booking/issues/1145.
                    'maxanswers' => 1,
                    'maxoverbooking' => 1,
                ],
                'clockforwardshift' => 60,
                'expected' => [
                    'bo_availability' => MOD_BOOKING_BO_COND_ASKFORCONFIRMATION, // Clockforwardshift = allowtobookagainafter.
                ],
                'tryrebooking' => 1,
            ],
            'Option - Multiple: Yes, Confirmation: Yes, Price: No, After: 70 seconds' => [
                'student_books_without_price_on_waiting_list',
                'otherbookingoptionsettings' => [
                    'waitforconfirmation' => 1,
                    'confirmationtrainerenabled' => 1,
                    'multiplebookings' => 1,
                    'allowtobookagainafter' => 60, // 60 seconds.
                ],
                'clockforwardshift' => 70,
                'expected' => [
                    'bo_availability' => MOD_BOOKING_BO_COND_ASKFORCONFIRMATION, // Clockforwardshift > allowtobookagainafter.
                ],
                'tryrebooking' => 1,
            ],
            'Option - Multiple: No, Confirmation: No, Price: Yes' => [
                'student_books_with_price',
                'otherbookingoptionsettings' => [
                    'useprice' => 1,
                    'importing' => 1,
                ],
                'clockforwardshift' => 0,
                'expected' => [
                    'bo_availability' => MOD_BOOKING_BO_COND_ALREADYBOOKED,
                ],
                'tryrebooking' => 0,
            ],
            'Option - Multiple: Yes, Confirmation: Yes, Price: Yes, After: 30 seconds' => [
                'student_books_with_price_on_waitinglist',
                'otherbookingoptionsettings' => [
                    'waitforconfirmation' => 1,
                    'confirmationtrainerenabled' => 1,
                    'multiplebookings' => 1,
                    'allowtobookagainafter' => 60, // 60 seconds.
                    'useprice' => 1,
                    'importing' => 1,
                ],
                'clockforwardshift' => 30,
                'expected' => [
                    'bo_availability' => MOD_BOOKING_BO_COND_ALREADYBOOKED, // Clockforwardshift < allowtobookagainafter.
                ],
                'tryrebooking' => 0,
            ],
            'Option - Multiple: Yes, Confirmation: Yes, Price: Yes, After: 70 seconds' => [
                'student_books_with_price_on_waitinglist',
                'otherbookingoptionsettings' => [
                    'waitforconfirmation' => 1,
                    'confirmationtrainerenabled' => 1,
                    'multiplebookings' => 1,
                    'allowtobookagainafter' => 60, // 60 seconds.
                    'useprice' => 1,
                    'importing' => 1,
                ],
                'clockforwardshift' => 70,
                'expected' => [
                    'bo_availability' => MOD_BOOKING_BO_COND_ASKFORCONFIRMATION, // Clockforwardshift > allowtobookagainafter.
                ],
                'tryrebooking' => 1,
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

    /**
     * Checks the expections when the option needs no confirmation then books the option.
     * @param mixed $boinfo
     * @param mixed $settings
     * @param mixed $student
     * @return void
     */
    private function student_books_without_price($boinfo, $settings, $student) {
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
     * Checks the expections when the option needs no confirmation then books the option.
     * @param mixed $boinfo
     * @param mixed $settings
     * @param mixed $student
     * @return void
     */
    private function student_books_with_price($boinfo, $settings, $student) {
        $this->setUser($student);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student->id, true);
        if (class_exists('local_shopping_cart\shopping_cart')) {
            $this->assertEquals(MOD_BOOKING_BO_COND_PRICEISSET, $id);
        } else {
            $this->assertEquals(MOD_BOOKING_BO_COND_NOSHOPPINGCART, $id);
        }

        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        // Admin confirms the users booking.
        $this->setAdminUser();
        // Verify price.
        $price = price::get_price('option', $settings->id);
        // Default price expected.
        $this->assertEquals(100, $price["price"]);
        // Purchase item in behalf of user if shopping_cart installed.
        if (class_exists('local_shopping_cart\shopping_cart')) {
            // Clean cart.
            shopping_cart::delete_all_items_from_cart($student->id);
            // Set user to buy in behalf of.
            shopping_cart::buy_for_user($student->id);
            // Get cached data or setup defaults.
            $cartstore = cartstore::instance($student->id);
            // Put in a test item with given ID (or default if ID > 4).
            shopping_cart::add_item_to_cart('mod_booking', 'option', $settings->id, -1);
            // Confirm cash payment.
            $res = shopping_cart::confirm_payment($student->id, LOCAL_SHOPPING_CART_PAYMENT_METHOD_CASHIER_CASH);
        }
        // In this test, we book the user directly (we don't test the payment process).
        $option->user_submit_response($student, 0, 0, 0, MOD_BOOKING_VERIFIED);
    }

    /**
     * Checks the expections when the option needs confirmation then books the option.
     * @param mixed $boinfo
     * @param mixed $settings
     * @param mixed $student
     * @return void
     */
    private function student_books_with_price_on_waitinglist($boinfo, $settings, $student) {
        $this->setUser($student);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ASKFORCONFIRMATION, $id);

        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        // Admin confirms the users booking.
        $this->setAdminUser();
        // Verify price.
        $price = price::get_price('option', $settings->id);
        // Default price expected.
        $this->assertEquals(100, $price["price"]);
        // Purchase item in behalf of user if shopping_cart installed.
        if (class_exists('local_shopping_cart\shopping_cart')) {
            // Clean cart.
            shopping_cart::delete_all_items_from_cart($student->id);
            // Set user to buy in behalf of.
            shopping_cart::buy_for_user($student->id);
            // Get cached data or setup defaults.
            $cartstore = cartstore::instance($student->id);
            // Put in a test item with given ID (or default if ID > 4).
            shopping_cart::add_item_to_cart('mod_booking', 'option', $settings->id, -1);
            // Confirm cash payment.
            $res = shopping_cart::confirm_payment($student->id, LOCAL_SHOPPING_CART_PAYMENT_METHOD_CASHIER_CASH);
        }
        // In this test, we book the user directly (we don't test the payment process).
        $option->user_submit_response($student, 0, 0, 0, MOD_BOOKING_VERIFIED);
    }

    /**
     * Checks the expections when the option needs confirmation then books the option.
     * @param mixed $boinfo
     * @param mixed $settings
     * @param mixed $student
     * @return void
     */
    private function student_books_without_price_on_waiting_list($boinfo, $settings, $student) {
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
}
