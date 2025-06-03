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
final class shopping_cart_test extends advanced_testcase {
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
     * Test of purchase of booking option with subbooking in form of item with price.
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     * @covers \local_shopping_cart\shopping_cart::add_item_to_cart
     * @dataProvider booking_common_settings_provider
     */
    public function test_booking_bookit_subbookings_item_price(array $bdata): void {
        global $DB, $CFG;

        self::tearDown();

        // Setup test data.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        $bdata['booking']['course'] = $course->id;
        $bdata['booking']['bookingmanager'] = $bookingmanager->username;
        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata['booking']);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id, 'editingteacher');

        /** @var local_shopping_cart_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_shopping_cart');
        $usercreditdata = [
            'userid' => $student1->id,
            'credit' => 200,
            'currency' => 'EUR',
        ];
        $ucredit = $plugingenerator->create_user_credit($usercreditdata);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course->id;
        $record->useprice = 1; // Use price from the default category.
        $record->maxanswers = 3;
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('now + 3 day');
        $record->courseendtime_0 = strtotime('now + 6 day');
        $record->importing = 1;

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create price categories.
        $pricecategorydata1 = (object)[
            'ordernum' => 1,
            'name' => 'default',
            'identifier' => 'default',
            'defaultvalue' => 88,
            'pricecatsortorder' => 1,
        ];
        $pricecategory1 = $plugingenerator->create_pricecategory($pricecategorydata1);

        $pricecategorydata2 = (object)[
            'ordernum' => 2,
            'name' => 'discount1',
            'identifier' => 'discount1',
            'defaultvalue' => 77,
            'pricecatsortorder' => 2,
        ];
        $pricecategory2 = $plugingenerator->create_pricecategory($pricecategorydata2);

        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id); // Mandatory there.

        // Create subbokingdata.
        $subbokingdata = (object)[
            'name' => 'SubItem',
            'type' => 'subbooking_additionalitem',
            'data' => (object)[
                'description' => 'You can bring your item(s):',
                'descriptionformat' => 1,
                'useprice' => 1,
                'subbookingadditemformlink' => 0,
                'subbookingadditemformlinkvalue' => '',
            ],
        ];
        $subbokingdata = (object)[
            'name' => 'SubItem', 'type' => 'subbooking_additionalitem',
            'block' => 1, 'optionid' => $option1->id,
            'json' => json_encode($subbokingdata),
        ];
        $subboking1 = $plugingenerator->create_subbooking($subbokingdata);

        $pricedata1 = (object)[
            'itemname' => 'SubItem',
            'area' => 'subbooking',
            'pricecategoryidentifier' => 'default',
            'price' => 55,
            'currency' => 'EUR',
        ];
        $plugingenerator->create_price($pricedata1);
        $pricedata2 = (object)[
            'itemname' => 'SubItem',
            'area' => 'subbooking',
            'pricecategoryidentifier' => 'discount1',
            'price' => 44,
            'currency' => 'EUR',
        ];
        $plugingenerator->create_price($pricedata2);

        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $boinfo1 = new bo_info($settings1);

        // Validate subbooking presence.
        if ((float)\PHPUnit\Runner\Version::series() < 9.6) {
            $this->assertObjectHasAttribute('subbookings', $settings1);
        } else {
            $this->assertObjectHasProperty('subbookings', $settings1);
        }
        $this->assertIsArray($settings1->subbookings);
        $this->assertCount(1, $settings1->subbookings);
        $subbookingobj = $settings1->subbookings[0];
        $this->assertInstanceOf('mod_booking\subbookings\sb_types\subbooking_additionalitem', $subbookingobj);
        $this->assertEquals($subboking1->name, $subbookingobj->name);
        $this->assertEquals($subboking1->type, $subbookingobj->type);

        $this->setUser($student1);
        singleton_service::destroy_user($student1->id);
        // Validate that subboking is available and bloking.
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student1->id, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_SUBBOOKINGBLOCKS, $id);

        // Admin confirms the users booking.
        $this->setAdminUser();
        // Verify price.
        $price = price::get_price('option', $settings1->id);
        // Default price expected.
        $this->assertEquals($pricecategorydata1->defaultvalue, $price["price"]);

        // Purchase item in behalf of user if shopping_cart installed.
        // Clean cart.
        shopping_cart::delete_all_items_from_cart($student1->id);

        // Set user to buy in behalf of.
        shopping_cart::buy_for_user($student1->id);

        // Get cached data or setup defaults.
        $cartstore = cartstore::instance($student1->id);

        // Put in a test item with given ID (or default if ID > 4).
        $item1 = shopping_cart::add_item_to_cart('mod_booking', 'option', $settings1->id, -1);
        $item2 = shopping_cart::add_item_to_cart('mod_booking', 'subbooking', $subboking1->id, -1);

        shopping_cart::save_used_credit_state($student1->id, 1);
        $cartstore->save_useinstallments_state(0);

        // The price is calculated from the cache, but there is a fallback to DB, if no cache is available.
        $cartstore = cartstore::instance($student1->id);
        $data = $cartstore->get_data();

        // Validate subboking in shopping_cart.
        $this->assertIsArray($data);
        $this->assertEquals($usercreditdata['credit'], $data['credit']);
        $this->assertEquals($usercreditdata['currency'], $data['currency']);
        $this->assertEquals(143, $data['deductible']);
        $this->assertEquals(143, $data['initialtotal']);
        $this->assertEquals(false, $data['taxesenabled']);
        $this->assertEquals(57, $data['remainingcredit']);
        $this->assertEmpty($data['costcenter']);
        $this->assertEmpty($data['useinstallments']);
        // Validate cart items.
        $this->assertArrayHasKey('items', $data);
        $this->assertEquals(2, $data['count']);
        $this->assertCount(2, $data['items']);
        foreach ($data['items'] as $cartitem) {
            $this->assertIsArray($cartitem);
            if (strpos($cartitem['area'], "option") !== false) {
                $this->assertEquals($option1->text, $cartitem['itemname']);
                $this->assertEquals($pricecategorydata1->defaultvalue, $cartitem['price']);
                $this->assertEquals('option', $cartitem['area']);
                $this->assertEmpty($cartitem['costcenter']);
                $this->assertEmpty($cartitem['installment']);
                $this->assertEquals('A', $cartitem['taxcategory']);
            }
            if (strpos($cartitem['area'], "subbooking") !== false) {
                $this->assertEquals($subbokingdata->name, $cartitem['itemname']);
                $this->assertEquals($pricedata1->price, $cartitem['price']);
                $this->assertEquals('subbooking', $cartitem['area']);
                $this->assertEmpty($cartitem['costcenter']);
                $this->assertEmpty($cartitem['installment']);
                $this->assertEquals('A', $cartitem['taxcategory']);
            }
        }

        // Confirm cash payment.
        $res = shopping_cart::confirm_payment($student1->id, LOCAL_SHOPPING_CART_PAYMENT_METHOD_CREDITS);
        // Validate payment.
        $this->assertIsArray($res);
        $this->assertEmpty($res['error']);
        $this->assertEquals(1, $res['status']);
        $this->assertEquals(57, $res['credit']);

        // In this test, we book the user directly (we don't test the payment process).
        $option = singleton_service::get_instance_of_booking_option($settings1->cmid, $settings1->id);
        $option->user_submit_response($student1, 0, 0, 0, MOD_BOOKING_VERIFIED);

        // User 1 should be booked now.
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);
    }

    /**
     * Test of purchase of booking option with price and additional feature selected by customform.
     *
     * @covers \mod_booking\local\mobile\customformstore
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     *
     */
    public function test_booking_customform_select_with_prices(array $bdata): void {
        global $DB, $CFG;

        self::tearDown();

        // Setup test data.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $course2 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create user profile custom fields.
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text', 'shortname' => 'pricecat', 'name' => 'pricecat',
        ]);
        set_config('pricecategoryfield', 'pricecat', 'booking');

        // Create users.
        $student1 = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'default']);
        $student2 = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'discount1']);
        $student3 = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'discount2']);
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        $bdata['booking']['course'] = $course1->id;
        $bdata['booking']['bookingmanager'] = $bookingmanager->username;
        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata['booking']);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student3->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher->id, $course1->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course1->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create set of price categories.
        $plugingenerator->create_pricecategory($bdata['pricecategories'][0]);
        $plugingenerator->create_pricecategory($bdata['pricecategories'][1]);
        $plugingenerator->create_pricecategory($bdata['pricecategories'][2]);

        // Create a booking option.
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course2->id;
        $record->maxanswers = 4;
        $record->useprice = 1; // Use price from the default category.
        $record->importing = 1;
        // Set test objective setting(s) - custoform "select".
        $record->bo_cond_customform_restrict = 1;
        $record->bo_cond_customform_select_1_1 = 'select';
        $record->bo_cond_customform_label_1_1 = 'Rooms';
        $record->bo_cond_customform_notempty_1_1 = 1;
        $record->bo_cond_customform_value_1_1 =
            'choose => Select...' . PHP_EOL .
            'singleroom => Single Room => 10 => 100' . PHP_EOL .
            'doubleroom => Double Room => 5 => discount2:100,discount1:200,default:150';
        $option1 = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        singleton_service::destroy_booking_singleton_by_cmid($settings->cmid); // Require to avoid caching issues.
        $boinfo = new bo_info($settings);

        // Try to book option1 by the student1.
        $this->setUser($student1);
        singleton_service::destroy_user($student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id);
        $this->assertEquals(MOD_BOOKING_BO_COND_JSON_CUSTOMFORM, $id);

        $price = price::get_price('option', $settings->id);
        $this->assertEquals($bdata['pricecategories'][0]->defaultvalue, $price["price"]);

        // Try to book option1 by the student2.
        $this->setUser($student2);
        singleton_service::destroy_user($student2->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id);
        $this->assertEquals(MOD_BOOKING_BO_COND_JSON_CUSTOMFORM, $id);

        $customformdata = (object) [
            'id' => $settings->id,
            'userid' => $student2->id,
            'customform_select_1' => 'doubleroom',
        ];
        $customformstore = new customformstore($student2->id, $settings->id);
        $customformstore->set_customform_data($customformdata);
        $price = price::get_price('option', $settings->id);
        $this->assertEquals(289, $price["price"]);

        $this->setAdminUser();
        // Simulate purchase: clean cart 1st.
        shopping_cart::delete_all_items_from_cart($student2->id);

        // Set user to buy in behalf of.
        shopping_cart::buy_for_user($student2->id);
        // Get cached data or setup defaults.
        $cartstore = cartstore::instance($student2->id);
        // Put in a test item with given ID (or default if ID > 4).
        shopping_cart::add_item_to_cart('mod_booking', 'option', $settings->id, -1);
        $total = $cartstore->get_total_price_of_items();
        $this->assertEquals(289, $total);

        // Confirm payment.
        $res = shopping_cart::confirm_payment($student2->id, LOCAL_SHOPPING_CART_PAYMENT_METHOD_CASHIER_CASH);
        // Validate payment.
        $this->assertIsArray($res);
        $this->assertEmpty($res['error']);

        // In this test, we book the user directly (we don't test the payment process).
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $option->user_submit_response($student2, 0, 0, 0, MOD_BOOKING_VERIFIED);
        // Validate that already booked.
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);
    }

    /**
     * Test of purchase of few booking options with price and cancellation all by cashier with fixed consumption has been set.
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     * @covers \mod_booking\booking_option::cancelbookingoption
     * @dataProvider booking_common_settings_provider
     *
     */
    public function test_booking_cancellation_wiht_fixed_consumption(array $bdata): void {
        global $DB, $CFG;

        self::tearDown();

        // Set parems requred for cancellation.
        $bdata['booking']['cancancelbook'] = 1;
        set_config('cancelationfee', 4, 'local_shopping_cart');
        set_config('calculateconsumation', 1, 'local_shopping_cart');
        set_config('calculateconsumationfixedpercentage', 30, 'local_shopping_cart');
        set_config('fixedpercentageafterserviceperiodstart', 1, 'local_shopping_cart');

        // Setup test data.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $course2 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create user profile custom fields.
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text', 'shortname' => 'pricecat', 'name' => 'pricecat',
        ]);
        set_config('pricecategoryfield', 'pricecat', 'booking');

        // Create users.
        $students[0] = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'default']);
        $students[1] = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'discount1']);
        $students[2] = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'discount2']);
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        $bdata['booking']['course'] = $course1->id;
        $bdata['booking']['bookingmanager'] = $bookingmanager->username;
        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata['booking']);

        $this->setAdminUser();

        foreach ($students as $student) {
            $this->getDataGenerator()->enrol_user($student->id, $course1->id, 'student');
        }
        $this->getDataGenerator()->enrol_user($teacher->id, $course1->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course1->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create set of price categories.
        $plugingenerator->create_pricecategory($bdata['pricecategories'][0]);
        $plugingenerator->create_pricecategory($bdata['pricecategories'][1]);
        $plugingenerator->create_pricecategory($bdata['pricecategories'][2]);

        // Create a booking option - setup only properties different form given in data provider.
        $record = (object)$bdata['options'][1];
        $record->bookingid = $booking1->id;
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course2->id;
        $record->useprice = 1; // Use price from the default category.
        $record->importing = 1;
        $record->teachersforoption = $teacher->username;
        $option1 = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        singleton_service::destroy_booking_singleton_by_cmid($settings->cmid); // Require to avoid caching issues.
        $boinfo = new bo_info($settings);

        // Create users' purchases in background.
        foreach ($students as $student) {
            $userpurchase = ['optionid' => $option1->id, 'userid' => $student->id];
            $plugingenerator->create_user_purchase($userpurchase);
        }

        // Validate that option already booked.
        foreach ($students as $student) {
            [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student->id);
            $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);
        }

        // Validation: consumend quota should be 0 because option not started yet and fixedpercentageafterserviceperiodstart==1.
        foreach ($students as $student) {
            $userhistory = shopping_cart_history::get_most_recent_historyitem(
                'mod_booking',
                'option',
                $settings->id,
                $student->id
            );
            $consumed = (object)shopping_cart::get_quota_consumed(
                'mod_booking',
                'option',
                $settings->id,
                $student->id,
                $userhistory->id
            );
            $this->assertEquals(0, $consumed->quota);
            $this->assertEquals(4, $consumed->cancelationfee);
        }

        unset_config('fixedpercentageafterserviceperiodstart', 'local_shopping_cart');
        // Validation: consumend quota should be 30% beacuse fixedpercentageafterserviceperiodstart unset.
        foreach ($students as $student) {
            $userhistory = shopping_cart_history::get_most_recent_historyitem(
                'mod_booking',
                'option',
                $settings->id,
                $student->id
            );
            $consumed = (object)shopping_cart::get_quota_consumed(
                'mod_booking',
                'option',
                $settings->id,
                $student->id,
                $userhistory->id
            );
            $this->assertEquals(0.3, $consumed->quota);
            $this->assertEquals(4, $consumed->cancelationfee);
        }

        // Test dynamic form (partial).
        $formdata = [
            'cancelationfee' => $consumed->cancelationfee,
            'componentname' => 'mod_booking',
            'area' => 'option',
            'itemid' => $settings->id,
        ];
        $form = new modal_cancel_all_addcredit(null, $formdata, 'post', '', [], true, $formdata, true);
        $formdata1 = $form->mock_ajax_submit($formdata);
        // phpcs:ignore
        // $form->process_dynamic_submission();

        // Validate code of modal_cancel_all_addcredit/process_dynamic_submission.
        $data = (object)$formdata1;
        $bookedusers = shopping_cart_history::get_user_list_for_option($data->itemid, $data->componentname, $data->area);

        $cancelationfee = $data->cancelationfee ?? 0;

        if ($data->cancelationfee < 0) {
                $cancelationfee = 0;
        }

        $componentname = $data->componentname;
        $area = $data->area;

        foreach ($bookedusers as $buser) {
            $credit = $buser->price - $cancelationfee;

            // Negative credits are not allowed.
            if ($credit < 0.0) {
                $credit = 0.0;
            }

            shopping_cart::cancel_purchase(
                $buser->itemid,
                $data->area,
                $buser->userid,
                $componentname,
                $buser->id,
                $credit,
                $cancelationfee,
                1,
                1
            );
        }

        // For the booking component, we have a special treatment here.
        if ($componentname === 'mod_booking' && $area === 'option') {
            $pluginmanager = \core_plugin_manager::instance();
            $plugins = $pluginmanager->get_plugins_of_type('mod');
            if (isset($plugins['booking'])) {
                booking_option::cancelbookingoption($data->itemid);
            }
        }

        // Validate that option have been cancelled and users' credits.
        foreach ($students as $key => $student) {
            [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student->id);
            $this->assertEquals(MOD_BOOKING_BO_COND_ISCANCELLED, $id);

            // Validate user credits.
            $balance1 = shopping_cart_credits::get_balance($student->id);
            // Check get_balance response.
            $this->assertIsArray($balance1);
            $this->assertEquals('EUR', $balance1[1]);
            $this->assertArrayNotHasKey(2, $balance1);
            switch ($key) {
                case 0:
                    $this->assertEquals(65, $balance1[0]);
                    break;
                case 1:
                    $this->assertEquals(58, $balance1[0]);
                    break;
                case 2:
                    $this->assertEquals(51, $balance1[0]);
                    break;
            }
        }
    }

    /**
     * Test of purchase of few booking options with price and cancellation all by cashier with consumption has been enabled.
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     * @covers \mod_booking\booking_option::cancelbookingoption
     * @dataProvider booking_common_settings_provider
     *
     */
    public function test_booking_cancellation_wiht_consumption_enabled(array $bdata): void {
        global $DB, $CFG;

        self::tearDown();

        // Set parems requred for cancellation.
        $bdata['booking']['cancancelbook'] = 1;
        set_config('cancelationfee', 4, 'local_shopping_cart');
        set_config('calculateconsumation', 1, 'local_shopping_cart');
        // Ensure no fixed consumption set.
        set_config('calculateconsumationfixedpercentage', -1, 'local_shopping_cart');
        unset_config('fixedpercentageafterserviceperiodstart', 'local_shopping_cart');

        // Setup test data.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $course2 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create user profile custom fields.
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text', 'shortname' => 'pricecat', 'name' => 'pricecat',
        ]);
        set_config('pricecategoryfield', 'pricecat', 'booking');

        // Create users.
        $students[0] = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'default']);
        $students[1] = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'discount1']);
        $students[2] = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'discount2']);
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        $bdata['booking']['course'] = $course1->id;
        $bdata['booking']['bookingmanager'] = $bookingmanager->username;
        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata['booking']);

        $this->setAdminUser();

        foreach ($students as $student) {
            $this->getDataGenerator()->enrol_user($student->id, $course1->id, 'student');
        }
        $this->getDataGenerator()->enrol_user($teacher->id, $course1->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course1->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create set of price categories.
        $plugingenerator->create_pricecategory($bdata['pricecategories'][0]);
        $plugingenerator->create_pricecategory($bdata['pricecategories'][1]);
        $plugingenerator->create_pricecategory($bdata['pricecategories'][2]);

        // Create a booking option - setup only properties different form given in data provider.
        $record = (object)$bdata['options'][2];
        $record->bookingid = $booking1->id;
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course2->id;
        $record->useprice = 1; // Use price from the default category.
        $record->importing = 1;
        $record->teachersforoption = $teacher->username;
        $option1 = $plugingenerator->create_option($record);

        singleton_service::destroy_booking_option_singleton($option1->id); // Require to avoid caching issues.
        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $boinfo = new bo_info($settings1);

        // Create users' purchases in background.
        foreach ($students as $student) {
            $userpurchase = ['optionid' => $option1->id, 'userid' => $student->id];
            $plugingenerator->create_user_purchase($userpurchase);
        }

        // Validate that option already booked.
        foreach ($students as $student) {
            [$id, $isavailable, $description] = $boinfo->is_available($settings1->id, $student->id);
            $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);
        }

        singleton_service::destroy_booking_option_singleton($option1->id); // Require to avoid caching issues.
        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);

        // Validation: consumend quota should be 0 beacuse option not started yet and fixedpercentageafterserviceperiodstart==1.
        foreach ($students as $student) {
            $userhistory = shopping_cart_history::get_most_recent_historyitem(
                'mod_booking',
                'option',
                $settings1->id,
                $student->id
            );
            $consumed = (object)shopping_cart::get_quota_consumed(
                'mod_booking',
                'option',
                $settings1->id,
                $student->id,
                $userhistory->id
            );
            $this->assertEquals(0.4, $consumed->quota);
            $this->assertEquals(4, $consumed->cancelationfee);
        }

        // Test dynamic form (partial).
        $formdata = [
            'cancelationfee' => $consumed->cancelationfee,
            'componentname' => 'mod_booking',
            'area' => 'option',
            'itemid' => $settings1->id,
        ];
        $form = new modal_cancel_all_addcredit(null, $formdata, 'post', '', [], true, $formdata, true);
        $formdata1 = $form->mock_ajax_submit($formdata);
        // phpcs:ignore
        // $form->process_dynamic_submission();

        // Validate code of modal_cancel_all_addcredit/process_dynamic_submission.
        $data = (object)$formdata1;
        $bookedusers = shopping_cart_history::get_user_list_for_option($data->itemid, $data->componentname, $data->area);

        $cancelationfee = $data->cancelationfee ?? 0;

        if ($data->cancelationfee < 0) {
                $cancelationfee = 0;
        }

        $componentname = $data->componentname;
        $area = $data->area;

        foreach ($bookedusers as $buser) {
            $credit = $buser->price - $cancelationfee;

            // Negative credits are not allowed.
            if ($credit < 0.0) {
                $credit = 0.0;
            }

            shopping_cart::cancel_purchase(
                $buser->itemid,
                $data->area,
                $buser->userid,
                $componentname,
                $buser->id,
                $credit,
                $cancelationfee,
                1,
                1
            );
        }

        // For the booking component, we have a special treatment here.
        if ($componentname === 'mod_booking' && $area === 'option') {
            $pluginmanager = \core_plugin_manager::instance();
            $plugins = $pluginmanager->get_plugins_of_type('mod');
            if (isset($plugins['booking'])) {
                booking_option::cancelbookingoption($data->itemid);
            }
        }

        // Validate that option have been cancelled and users' credits.
        foreach ($students as $key => $student) {
            [$id, $isavailable, $description] = $boinfo->is_available($settings1->id, $student->id);
            $this->assertEquals(MOD_BOOKING_BO_COND_ISCANCELLED, $id);

            // Validate user credits.
            $balance1 = shopping_cart_credits::get_balance($student->id);
            // Check get_balance response.
            $this->assertIsArray($balance1);
            $this->assertEquals('EUR', $balance1[1]);
            $this->assertArrayNotHasKey(2, $balance1);
            switch ($key) {
                case 0:
                    $this->assertEquals(55, $balance1[0]);
                    break;
                case 1:
                    $this->assertEquals(49, $balance1[0]);
                    break;
                case 2:
                    $this->assertEquals(43, $balance1[0]);
                    break;
            }
        }
    }

    /**
     * Test of purchase of few booking options with price and cancellation all by cashier with consumption has been enabled.
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @covers \mod_booking\booking_option::cancelbookingoption
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_booking_cancellation_wiht_multiple_dates_and_consumption_enabled(array $bdata): void {
        global $DB, $CFG;

        self::tearDown();

        // Set parems requred for cancellation.
        $bdata['booking']['cancancelbook'] = 1;
        set_config('cancelationfee', 4, 'local_shopping_cart');
        set_config('calculateconsumation', 1, 'local_shopping_cart');
        // Ensure no fixed consumption set.
        set_config('calculateconsumationfixedpercentage', -1, 'local_shopping_cart');
        unset_config('fixedpercentageafterserviceperiodstart', 'local_shopping_cart');

        // Setup test data.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $course2 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create user profile custom fields.
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text', 'shortname' => 'pricecat', 'name' => 'pricecat',
        ]);
        set_config('pricecategoryfield', 'pricecat', 'booking');

        // Create users.
        $students[0] = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'default']);
        $students[1] = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'discount1']);
        $students[2] = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'discount2']);
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        $bdata['booking']['course'] = $course1->id;
        $bdata['booking']['bookingmanager'] = $bookingmanager->username;
        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata['booking']);

        $this->setAdminUser();

        foreach ($students as $student) {
            $this->getDataGenerator()->enrol_user($student->id, $course1->id, 'student');
        }
        $this->getDataGenerator()->enrol_user($teacher->id, $course1->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course1->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create set of price categories.
        $plugingenerator->create_pricecategory($bdata['pricecategories'][0]);
        $plugingenerator->create_pricecategory($bdata['pricecategories'][1]);
        $plugingenerator->create_pricecategory($bdata['pricecategories'][2]);

        // Create a booking option - setup only properties different form given in data provider.
        $record = (object)$bdata['options'][3];
        $record->bookingid = $booking1->id;
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course2->id;
        $record->useprice = 1; // Use price from the default category.
        $record->importing = 1;
        $record->teachersforoption = $teacher->username;
        $option1 = $plugingenerator->create_option($record);

        singleton_service::destroy_booking_option_singleton($option1->id); // Require to avoid caching issues.
        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $boinfo = new bo_info($settings1);

        // Create users' purchases in background.
        foreach ($students as $student) {
            $userpurchase = ['optionid' => $option1->id, 'userid' => $student->id];
            $plugingenerator->create_user_purchase($userpurchase);
        }

        // Validate that option already booked.
        foreach ($students as $student) {
            [$id, $isavailable, $description] = $boinfo->is_available($settings1->id, $student->id);
            $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);
        }

        singleton_service::destroy_booking_option_singleton($option1->id); // Require to avoid caching issues.
        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);

        // Validation: consumend quota should be 0 beacuse option not started yet and fixedpercentageafterserviceperiodstart==1.
        foreach ($students as $student) {
            $userhistory = shopping_cart_history::get_most_recent_historyitem(
                'mod_booking',
                'option',
                $settings1->id,
                $student->id
            );
            $consumed = (object)shopping_cart::get_quota_consumed(
                'mod_booking',
                'option',
                $settings1->id,
                $student->id,
                $userhistory->id
            );
            $this->assertEquals(0.67, $consumed->quota);
            $this->assertEquals(4, $consumed->cancelationfee);
        }

        // Test dynamic form (partial).
        $formdata = [
            'cancelationfee' => $consumed->cancelationfee,
            'componentname' => 'mod_booking',
            'area' => 'option',
            'itemid' => $settings1->id,
        ];
        $form = new modal_cancel_all_addcredit(null, $formdata, 'post', '', [], true, $formdata, true);
        $formdata1 = $form->mock_ajax_submit($formdata);
        // phpcs:ignore
        // $form->process_dynamic_submission();

        // Validate code of modal_cancel_all_addcredit/process_dynamic_submission.
        $data = (object)$formdata1;
        $bookedusers = shopping_cart_history::get_user_list_for_option($data->itemid, $data->componentname, $data->area);

        $cancelationfee = $data->cancelationfee ?? 0;

        if ($data->cancelationfee < 0) {
                $cancelationfee = 0;
        }

        $componentname = $data->componentname;
        $area = $data->area;

        foreach ($bookedusers as $buser) {
            $credit = $buser->price - $cancelationfee;

            // Negative credits are not allowed.
            if ($credit < 0.0) {
                $credit = 0.0;
            }

            shopping_cart::cancel_purchase(
                $buser->itemid,
                $data->area,
                $buser->userid,
                $componentname,
                $buser->id,
                $credit,
                $cancelationfee,
                1,
                1
            );
        }

        // For the booking component, we have a special treatment here.
        if ($componentname === 'mod_booking' && $area === 'option') {
            $pluginmanager = \core_plugin_manager::instance();
            $plugins = $pluginmanager->get_plugins_of_type('mod');
            if (isset($plugins['booking'])) {
                booking_option::cancelbookingoption($data->itemid);
            }
        }

        // Validate that option have been cancelled and users' credits.
        foreach ($students as $key => $student) {
            [$id, $isavailable, $description] = $boinfo->is_available($settings1->id, $student->id);
            $this->assertEquals(MOD_BOOKING_BO_COND_ISCANCELLED, $id);

            // Validate user credits.
            $balance1 = shopping_cart_credits::get_balance($student->id);
            // Check get_balance response.
            $this->assertIsArray($balance1);
            $this->assertEquals('EUR', $balance1[1]);
            $this->assertArrayNotHasKey(2, $balance1);
            switch ($key) {
                case 0:
                    $this->assertEquals(29, $balance1[0]);
                    break;
                case 1:
                    $this->assertEquals(25, $balance1[0]);
                    break;
                case 2:
                    $this->assertEquals(22, $balance1[0]);
                    break;
            }
        }
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
