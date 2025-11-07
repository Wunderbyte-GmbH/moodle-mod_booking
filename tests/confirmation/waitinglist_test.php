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
 * Tests for booking rules.
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2025 Mahdi Poustini
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use mod_booking\table\manageusers_table;
use stdClass;
use mod_booking\bo_availability\bo_info;
use local_shopping_cart\shopping_cart;
use local_shopping_cart\shopping_cart_history;
use local_shopping_cart\local\cartstore;
use local_shopping_cart\output\shoppingcart_history_list;
use mod_booking_generator;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests for booking rules.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Mahdi Poustini
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class waitinglist_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        // Mandatory clean-up.
        singleton_service::destroy_instance();
    }

    /**
     * This test verifies that an answer is immediately booked for the first user on the waiting list.
     *
     * The booking option has a price of 25 euros for the default category and 0 euros for the student category.
     * Both the "maxanswers" and "users on the waiting list" settings are set to 2.
     *
     * The option requires confirmation only for users on the waiting list.
     *
     * We will check the availability of the booking option for five students:
     * - Students 1, 2, 4, and 5 have the default price category.
     * - Student 3 has the student price category.
     *
     * We book the option for students 1 and 2, and place students 3 and 4 on the waiting list.
     * Then, we remove the booking answer of student 1.
     *
     * After confirmation of admin, we expect that the option will be automatically booked for student 3,
     * as this student is the first person on the waiting list and has the student price category.
     *
     * @covers \mod_booking\table\manageusers_table::action_confirmbooking
     *
     * @return void
     */
    public function test_confirmation_for_option_with_price_but_students_for_free(): void {
        global $DB;
        $this->resetAfterTest();

        $bdata = self::booking_common_settings_provider();
        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

        $bdata['cancancelbook'] = 1;
        set_config('cancelationfee', 0, 'local_shopping_cart');

        // Create a custom profile field to set price category for eahc user.
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'pricecat',
            'name' => 'pricecat',
        ]);
        set_config('pricecategoryfield', 'pricecat', 'booking');
        set_config('displayemptyprice', 1, 'booking');

        // Create course.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users, some of them with second price category.
        $student[1] = $this->getDataGenerator()->create_user();
        $student[2] = $this->getDataGenerator()->create_user();
        $student[3] = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'student'] ?? []);
        $student[4] = $this->getDataGenerator()->create_user();
        $student[5] = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $teacher->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student[1]->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student[2]->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student[3]->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student[4]->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student[5]->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create price categories.
        $pricecategories = [
            'default' => (object) [
                'ordernum' => 1,
                'name' => 'default',
                'identifier' => 'default',
                'defaultvalue' => 100,
                'pricecatsortorder' => 1,
            ],
            'student' => (object) [
                'ordernum' => 1,
                'name' => 'student',
                'identifier' => 'student',
                'defaultvalue' => 0,
                'pricecatsortorder' => 2,
            ],
        ];
        foreach ($pricecategories as $pc) {
            $plugingenerator->create_pricecategory($pc);
        }

        // Create booking option 1.
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'football';
        $record->maxanswers = 2;
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course->id;
        $record->maxoverbooking = 2; // Enable waitinglist.
        $record->waitforconfirmation = 2; // Enable confirmation only for the users on the waiting list.
        $record->confirmationonnotification = 0; // Notifications have no effects on confirmations.
        $record->description = 'Will start in 2050';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00', time());
        $record->courseendtime_0 = strtotime('20 July 2050 14:00', time());
        $record->teachersforoption = $teacher->username;
        $record->useprice = 1;
        $record->importing = 1;
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        singleton_service::destroy_booking_singleton_by_cmid($settings->cmid); // Require to avoid caching issues.
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $boinfo = new bo_info($settings);

        // Verify price.
        $price = price::get_price('option', $settings->id);
        $this->assertEquals($pricecategories['default']->defaultvalue, $price["price"]);

        $studnetprice = price::get_price('option', $settings->id, $student[3]);
        $this->assertEquals($pricecategories['student']->defaultvalue, $studnetprice["price"]);

        // Check the button for each student.
        for ($i = 1; $i <= 5; $i++) {
            $this->setUser($student[$i]);

            [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student[$i]->id, true);
            // The user sees now either the payment button or the noshoppingcart message.
            if (class_exists('local_shopping_cart\shopping_cart')) {
                $this->assertEquals(MOD_BOOKING_BO_COND_PRICEISSET, $id);
            } else {
                $this->assertEquals(MOD_BOOKING_BO_COND_NOSHOPPINGCART, $id);
            }
        }

        // Purchase item in behalf of user if shopping_cart installed.
        if (class_exists('local_shopping_cart\shopping_cart')) {
            // Book option for students 1 & 2.
            for ($i = 1; $i <= 2; $i++) {
                // Admin confirms the users booking.
                $this->setAdminUser();
                // Clean cart.
                shopping_cart::delete_all_items_from_cart($student[1]->id);
                shopping_cart::delete_all_items_from_cart($student[2]->id);
                // Set user to buy in behalf of.
                shopping_cart::buy_for_user($student[$i]->id);
                // Get cached data or setup defaults.
                $cartstore = cartstore::instance($student[$i]->id);
                // Put in a test item with given ID (or default if ID > 4).
                shopping_cart::add_item_to_cart('mod_booking', 'option', $settings->id, -1);
                // Confirm cash payment.
                $res = shopping_cart::confirm_payment($student[$i]->id, LOCAL_SHOPPING_CART_PAYMENT_METHOD_CASHIER_CASH);
                // User 1 & 2 should be booked now.
                [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student[$i]->id, true);
                $this->assertEquals(
                    MOD_BOOKING_BO_COND_ALREADYBOOKED,
                    $id,
                    'Can confirm that item is alread booked for the student ' . $i
                );
                // Switch to user to check what this user see.
                $this->setUser($student[$i]);

                [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student[$i]->id, true);
                $this->assertEquals(
                    MOD_BOOKING_BO_COND_ALREADYBOOKED,
                    $id,
                    'Can confirm that item is alread booked for the student ' . $i
                );
            }
        }

        // Now we book the option for students 3 & 4 on the waiting list.
        for ($i = 3; $i <= 4; $i++) {
            // Student 3&4 should see 'book it - on waiting list' button.
            $this->setUser($student[$i]);
            [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student[$i]->id, true);
            $this->assertEquals(MOD_BOOKING_BO_COND_ASKFORCONFIRMATION, $id);
            // They book it. So they should see 'wait for confirmation' button.
            $result = booking_bookit::bookit('option', $settings->id, $student[$i]->id);
            [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student[$i]->id, true);
            $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);
        }

        // Chekc if 5th student see the fully booked.
        $this->setUser($student[5]);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student[5]->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_FULLYBOOKED, $id);

        $this->setUser($student[1]);
        // Render to see if "cancel purchase" present.
        $buttons = booking_bookit::render_bookit_button($settings, $student[1]->id);
        $this->assertStringContainsString('Cancel purchase', $buttons);

        // Now we cancel the booking if the 1st student.
        if (class_exists('local_shopping_cart\shopping_cart')) {
            // Getting history of purchased item and verify.
            $item = shopping_cart_history::get_most_recent_historyitem('mod_booking', 'option', $settings->id, $student[1]->id);
            shopping_cart::add_quota_consumed_to_item($item, $student[1]->id);
            shoppingcart_history_list::add_round_config($item);
            $this->assertEquals($settings->id, $item->itemid);
            $this->assertEquals($student[1]->id, $item->userid);
            $this->assertEquals(0, $item->quotaconsumed);
            // Actual cancellation of purcahse and verify.
            $res = shopping_cart::cancel_purchase($settings->id, 'option', $student[1]->id, 'mod_booking', $item->id, 0);
            $this->assertEquals(1, $res['success']);
            $this->assertEmpty($res['error']);
        }

        // Check if answer of student 1 is really deleted.
        $answer = $DB->get_record('booking_answers', ['userid' => $student[1]->id]);
        $this->assertNotEmpty($answer);
        $this->assertEquals(MOD_BOOKING_STATUSPARAM_DELETED, $answer->waitinglist);

        // Now the mamgaer confirms the answer of the student 3.
        $this->setAdminUser();
        $table = new manageusers_table('test');
        $answer = $DB->get_record('booking_answers', ['userid' => $student[3]->id]);
        $table->action_confirmbooking(0, json_encode($answer));

        $answers = singleton_service::get_instance_of_booking_answers($settings);
        $bookedusers = $answers->get_usersonlist();

        // Check if student 2 & 3 booked this option.
        $userids = array_map(fn($o) => $o->userid, $bookedusers);
        $allowed = ["Student 2" => $student[2]->id, "Student 3" => $student[3]->id];
        foreach ($allowed as $std => $expectedid) {
            $this->assertContains(
                $expectedid,
                $userids,
                "{$std} not found in booked users."
            );
        }

        $this->assertCount(2, $bookedusers, 'Expected exactly 2 booked students.');

        // Check if the answer of student 3 is confirmed.
        $this->setUser($student[3]);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student[3]->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Check the answer json. It must contain confirmation info.
        $answer = $DB->get_record('booking_answers', ['userid' => $student[3]->id]);
        $answerjson = json_decode($answer->json);
        $this->assertNotEmpty($answerjson);
        $this->assertTrue(property_exists($answerjson, 'confirmwaitinglist'));
    }

    /**
     * Data provider for condition_bookingpolicy_test
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    public static function booking_common_settings_provider(): array {
        $bdata = [
            'name' => 'Rule Booking Test',
            'eventtype' => 'Test rules',
            'enablecompletion' => 1,
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
            'completion' => 2,
            'showviews' => ['mybooking,myoptions,optionsiamresponsiblefor,showall,showactive,myinstitution'],
        ];
        return ['bdata' => [$bdata]];
    }
}
