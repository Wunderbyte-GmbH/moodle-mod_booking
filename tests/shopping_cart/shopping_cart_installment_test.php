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
 * Tests for booking option events.
 *
 * @package mod_booking
 * @category test
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2017 Andraž Prinčič
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use coding_exception;
use mod_booking\price;
use mod_booking_generator;
use mod_booking\bo_availability\bo_info;
use local_shopping_cart\shopping_cart;
use local_shopping_cart\shopping_cart_history;
use mod_booking\local\mobile\customformstore;
use local_shopping_cart\local\cartstore;
use local_shopping_cart\form\modal_cancel_all_addcredit;
use local_shopping_cart\shopping_cart_credits;
use local_shopping_cart_generator;
use stdClass;
use tool_mocktesttime\time_mock;
use mod_booking\booking_rules\booking_rules;
use mod_booking\booking_rules\rules_info;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Class handling tests for booking options.
 *
 * @package mod_booking
 * @category test
 * @copyright 2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
final class shopping_cart_installment_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
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
        // Mandatory to deal with static variable in the booking_rules.
        rules_info::destroy_singletons();
        booking_rules::$rules = [];
        cartstore::reset();
    }

    /**
     * Test of purchase of booking option with price and installments enabled.
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     * @covers \local_shopping_cart\shopping_cart::add_item_to_cart
     * @dataProvider booking_common_settings_provider
     *
     */
    public function test_booking_bookit_with_price_and_installment(array $bdata): void {
        global $DB, $CFG;

        self::tearDown();

        // Skip this test if shopping_cart not installed.
        if (!class_exists('local_shopping_cart\shopping_cart')) {
            return;
        }
        // Set params requred for installment.
        set_config('enableinstallments', 1, 'local_shopping_cart');
        set_config('timebetweenpayments', 2, 'local_shopping_cart');
        set_config('reminderdaysbefore', 1, 'local_shopping_cart');

        // Setup test data.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $course2 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $student3 = $this->getDataGenerator()->create_user();
        $student4 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        $bdata['booking']['course'] = $course1->id;
        $bdata['booking']['bookingmanager'] = $bookingmanager->username;
        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata['booking']);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student3->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student4->id, $course1->id);
        $this->getDataGenerator()->enrol_user($teacher->id, $course1->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course1->id);

        /** @var local_shopping_cart_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_shopping_cart');
        $usercreditdata = [
            'userid' => $student1->id,
            'credit' => 100,
            'currency' => 'EUR',
        ];
        $ucredit = $plugingenerator->create_user_credit($usercreditdata);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course2->id;
        $record->maxanswers = 2;
        $record->useprice = 1; // Use price from the default category.
        $record->importing = 1;
        // Allow and configure installemnts for option.
        $record->sch_allowinstallment = 1;
        $record->sch_downpayment = 44;
        $record->sch_numberofpayments = 2;
        $record->sch_duedatevariable = 2;

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $pricecategorydata = (object)[
            'ordernum' => 1,
            'name' => 'default',
            'identifier' => 'default',
            'defaultvalue' => 100,
            'pricecatsortorder' => 1,
        ];

        $plugingenerator->create_pricecategory($pricecategorydata);

        $option1 = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        // To avoid retrieving the singleton with the wrong settings, we destroy it.
        singleton_service::destroy_booking_singleton_by_cmid($settings->cmid);

        // Book the first user without any problem.
        $boinfo = new bo_info($settings);

        // Book option1 by the student1 himself.
        $this->setUser($student1);
        singleton_service::destroy_user($student1->id);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        // The user sees now either the payment button or the noshoppingcart message.
        $this->assertEquals(MOD_BOOKING_BO_COND_PRICEISSET, $id);

        // Admin confirms the users booking.
        $this->setAdminUser();
        // Verify price.
        $price = price::get_price('option', $settings->id);
        // Default price expected.
        $this->assertEquals($pricecategorydata->defaultvalue, $price["price"]);

        // Purchase item in behalf of user if shopping_cart installed.
        // Clean cart.
        shopping_cart::delete_all_items_from_cart($student1->id);

        // Set user to buy in behalf of.
        shopping_cart::buy_for_user($student1->id);

        // Get cached data or setup defaults.
        $cartstore = cartstore::instance($student1->id);

        // Put in a test item with given ID (or default if ID > 4).
        $item = shopping_cart::add_item_to_cart('mod_booking', 'option', $settings->id, -1);

        shopping_cart::save_used_credit_state($student1->id, 1);
        $cartstore->save_useinstallments_state(1);

        // The price is calculated from the cache, but there is a fallback to DB, if no cache is available.
        $cartstore = cartstore::instance($student1->id);
        $data = $cartstore->get_data();

        // Validate installment.
        $this->assertIsArray($data);
        $this->assertArrayHasKey('installments', $data);
        $this->assertCount(1, $data['installments']);
        $installment = $data['installments'][0];
        $this->assertIsArray($installment);
        $this->assertEquals($pricecategorydata->defaultvalue, $installment['originalprice']);
        $this->assertEquals($price['currency'], $installment['currency']);
        $this->assertEquals($record->sch_downpayment, $installment['initialpayment']);
        $this->assertEquals($record->sch_numberofpayments, $installment['installments']);
        $this->assertIsArray($installment['payments']);
        $this->assertCount(2, $installment['payments']);
        $this->assertEquals(0, $installment['payments'][0]['paid']);
        $this->assertEquals(28, $installment['payments'][0]['price']);
        $exppadate = userdate(strtotime('now + 1 days'), get_string('strftimedate', 'langconfig'));
        $this->assertEquals($exppadate, $installment['payments'][0]['date']);
        $this->assertEquals(0, $installment['payments'][1]['paid']);
        $this->assertEquals(28, $installment['payments'][1]['price']);
        $exppadate = userdate(strtotime('now + 2 days'), get_string('strftimedate', 'langconfig'));
        $this->assertEquals($exppadate, $installment['payments'][1]['date']);

        // Confirm cash payment.
        $res = shopping_cart::confirm_payment($student1->id, LOCAL_SHOPPING_CART_PAYMENT_METHOD_CREDITS);
        // Validate payment.
        $this->assertIsArray($res);
        $this->assertEmpty($res['error']);
        $this->assertEquals(56, $res['credit']);

        // In this test, we book the user directly (we don't test the payment process).
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $option->user_submit_response($student1, 0, 0, 0, MOD_BOOKING_VERIFIED);

        // User 1 should be booked now.
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);
    }

    /**
     * Data provider for shopping_cart_test
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    public static function booking_common_settings_provider(): array {
        $bdata = [
            'booking' => [
                'name' => 'Test Booking',
                'eventtype' => 'Test event',
                'enablecompletion' => 1,
                'bookedtext' => ['text' => 'text'],
                'waitingtext' => ['text' => 'text'],
                'notifyemail' => ['text' => 'text'],
                'statuschangetext' => ['text' => 'text'],
                'deletedtext' => ['text' => 'text'],
                'pollurltext' => ['text' => 'text'],
                'pollurlteacherstext' => ['text' => 'text'],
                'notificationtext' => ['text' => 'text'], 'userleave' => ['text' => 'text'],
                'tags' => '',
                'completion' => 2,
                'cancancelbook' => 0,
                'showviews' => ['mybooking,myoptions,optionsiamresponsiblefor,showall,showactive,myinstitution'],
            ],
            'options' => [
                // Option 1 with 1 session in remote future.
                0 => [
                    'text' => 'Test Option 1',
                    'courseid' => 0,
                    'maxanswers' => 2,
                    'optiondateid_0' => "0",
                    'daystonotify_0' => "0",
                    'coursestarttime_0' => strtotime('20 May 2050 15:00'),
                    'courseendtime_0' => strtotime('20 June 2050 14:00'),
                ],
                // Option 2 with 1 session started tomorrow.
                1 => [
                    'text' => 'Test Option 2',
                    'courseid' => 0,
                    'maxanswers' => 4,
                    'optiondateid_0' => "0",
                    'daystonotify_0' => "0",
                    'coursestarttime_0' => strtotime('now +1 day'),
                    'courseendtime_0' => strtotime('now +3 day'),
                ],
                // Option 3 with 1 ongoing session started yesterday.
                2 => [
                    'text' => 'Test Option 3',
                    'courseid' => 0,
                    'maxanswers' => 4,
                    'optiondateid_0' => "0",
                    'daystonotify_0' => "0",
                    'coursestarttime_0' => strtotime('now -48 hours'),
                    'courseendtime_0' => strtotime('now +72 hours'),
                ],
                // Option 3 with 1 ongoing and 2 past non-overlaping sessions.
                3 => [
                    'text' => 'Test Option 4',
                    'courseid' => 0,
                    'maxanswers' => 4,
                    'optiondateid_0' => "0",
                    'daystonotify_0' => "0",
                    'coursestarttime_0' => strtotime('now -6 day'),
                    'courseendtime_0' => strtotime('now -5 day'),
                    'optiondateid_1' => "0",
                    'daystonotify_1' => "0",
                    'coursestarttime_1' => strtotime('now -4 day'),
                    'courseendtime_1' => strtotime('now -3 day'),
                    'optiondateid_2' => "0",
                    'daystonotify_2' => "0",
                    'coursestarttime_2' => strtotime('now -48 hours'),
                    'courseendtime_2' => strtotime('now +72 hours'),
                ],
            ],
            'pricecategories' => [
                0 => (object)[
                    'ordernum' => 1,
                    'name' => 'default',
                    'identifier' => 'default',
                    'defaultvalue' => 99,
                    'pricecatsortorder' => 1,
                ],
                1 => (object)[
                    'ordernum' => 2,
                    'name' => 'discount1',
                    'identifier' => 'discount1',
                    'defaultvalue' => 89,
                    'pricecatsortorder' => 2,
                ],
                2 => (object)[
                    'ordernum' => 3,
                    'name' => 'discount2',
                    'identifier' => 'discount2',
                    'defaultvalue' => 79,
                    'pricecatsortorder' => 3,
                ],
            ],
        ];
        return ['bdata' => [$bdata]];
    }
}
