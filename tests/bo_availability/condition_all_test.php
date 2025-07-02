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
use local_shopping_cart\local\cartstore;
use local_shopping_cart\output\shoppingcart_history_list;
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
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
final class condition_all_test extends advanced_testcase {
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
     * Test booking, cancelation, option has started etc.
     *
     * @covers \mod_booking\bo_availability\conditions\bookitbutton::is_available
     * @covers \mod_booking\bo_availability\conditions\alreadybooked::is_available
     * @covers \mod_booking\bo_availability\conditions\fullybooked::is_available
     * @covers \mod_booking\bo_availability\conditions\confirmation::render_page
     * @covers \mod_booking\bo_availability\conditions\notifymelist::is_available
     * @covers \mod_booking\bo_availability\conditions\isloggedin::is_available
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_booking_bookit_capabilitynotneeded(array $bdata): void {
        global $DB, $CFG;

        // Setup test data.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $admin = $this->getDataGenerator()->create_user();
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course->id);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1';
        $record->courseid = $course->id;
        $record->maxanswers = 2;

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        // To avoid retrieving the singleton with the wrong settings, we destroy it.
        singleton_service::destroy_booking_singleton_by_cmid($settings->cmid);

        // Book the first user without any problem.
        $boinfo = new bo_info($settings);

        // Check option availability if user is not logged yet.
        require_logout();
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ISLOGGEDIN, $id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ISLOGGEDIN, $id);

        // Booking is possible for student1.
        $this->setUser($student1);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMBOOKIT, $id);

        // Booking is impossible for studnet2 because he does not have 'mod/booking:choose' capability (not enrolled in course).
        $this->setUser($student2);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, false);
        $this->assertEquals(MOD_BOOKING_CONDPARAM_CANBEOVERRIDDEN, $id);
        $this->assertEquals(false, $isavailable);
        $this->assertEquals("No right to book", $description);

        // Update option to allow booking without capability.
        $this->setAdminUser();
        $record->id = $option1->id;
        $record->bo_cond_allowedtobookininstance_restrict = 1;
        $record->bo_cond_allowedtobookininstance_capabilitynotneeded = 1;
        $record->cmid = $settings->cmid;
        booking_option::update($record);

        // Ensure that student 2 now capable to book option.
        $this->setUser($student2);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);
    }

    /**
     * Test of booking option with price as well as cancellation by user.
     *
     * @covers \mod_booking\bo_availability\conditions\priceisset::is_available
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     *
     */
    public function test_booking_bookit_with_price_and_cancellation(array $bdata): void {
        global $DB, $CFG;

        // Set parems requred for cancellation.
        $bdata['cancancelbook'] = 1;
        if (class_exists('local_shopping_cart\shopping_cart')) {
            set_config('cancelationfee', 0, 'local_shopping_cart');
        }

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

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student3->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student4->id, $course1->id);
        $this->getDataGenerator()->enrol_user($teacher->id, $course1->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course1->id);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course2->id;
        $record->maxanswers = 2;
        $record->useprice = 1; // Use price from the default category.
        $record->importing = 1;

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $pricecategorydata = (object) [
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

        // Check option availability if user is not logged yet.
        require_logout();
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_ISLOGGEDINPRICE, $id);

        // Book option1 by the student1 himself.
        $this->setUser($student1);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        // The user sees now either the payment button or the noshoppingcart message.
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
        $this->assertEquals($pricecategorydata->defaultvalue, $price["price"]);
        // Purchase item in behalf of user if shopping_cart installed.
        if (class_exists('local_shopping_cart\shopping_cart')) {
            // Clean cart.
            shopping_cart::delete_all_items_from_cart($student1->id);
            // Set user to buy in behalf of.
            shopping_cart::buy_for_user($student1->id);
            // Get cached data or setup defaults.
            $cartstore = cartstore::instance($student1->id);
            // Put in a test item with given ID (or default if ID > 4).
            shopping_cart::add_item_to_cart('mod_booking', 'option', $settings->id, -1);
            // Confirm cash payment.
            $res = shopping_cart::confirm_payment($student1->id, LOCAL_SHOPPING_CART_PAYMENT_METHOD_CASHIER_CASH);
        }
        // In this test, we book the user directly (we don't test the payment process).
        $option->user_submit_response($student1, 0, 0, 0, MOD_BOOKING_VERIFIED);

        // User 1 should be booked now.
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // The student1 attempt to camcel purchase by himself.
        $this->setUser($student1);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);
        // Render to see if "cancel purchase" present.
        $buttons = booking_bookit::render_bookit_button($settings, $student1->id);
        $this->assertStringContainsString('Cancel purchase', $buttons);
        // Cancellation of purcahse if shopping_cart installed.
        if (class_exists('local_shopping_cart\shopping_cart')) {
            // Getting history of purchased item and verify.
            $item = shopping_cart_history::get_most_recent_historyitem('mod_booking', 'option', $settings->id, $student1->id);
            shopping_cart::add_quota_consumed_to_item($item, $student1->id);
            shoppingcart_history_list::add_round_config($item);
            $this->assertEquals($settings->id, $item->itemid);
            $this->assertEquals($student1->id, $item->userid);
            $this->assertEquals($pricecategorydata->defaultvalue, (int) $item->price);
            $this->assertEquals(0, $item->quotaconsumed);
            // Actual cancellation of purcahse and verify.
            $res = shopping_cart::cancel_purchase($settings->id, 'option', $student1->id, 'mod_booking', $item->id, 0);
            $this->assertEquals(1, $res['success']);
            $this->assertEquals($pricecategorydata->defaultvalue, $res['credit']);
            $this->assertEmpty($res['error']);
        }

        // Mandatory clean-up.
        singleton_service::get_instance()->userpricecategory = [];
    }

    /**
     * Test of booking option with zero price as different displayemptyprice settings.
     *
     * @covers \mod_booking\bo_availability\conditions\priceisset::is_available
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     *
     */
    public function test_booking_bookit_with_pricecategories_and_zero_price(array $bdata): void {
        global $DB, $CFG;

        $this->setAdminUser();

        // Set parems requred for cancellation.
        $bdata['cancancelbook'] = 1;
        if (class_exists('local_shopping_cart\shopping_cart')) {
            set_config('cancelationfee', 0, 'local_shopping_cart');
        }

        // Setup test data.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $course2 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create user profile custom fields.
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'pricecat',
            'name' => 'pricecat',
        ]);
        set_config('pricecategoryfield', 'pricecat', 'booking');
        set_config('displayemptyprice', 1, 'booking');

        // Create users.
        $student1 = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'realprice']);
        $student2 = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'zeroprice']);
        $student3 = $this->getDataGenerator()->create_user();
        $student4 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->getDataGenerator()->enrol_user($student1->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student3->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student4->id, $course1->id);
        $this->getDataGenerator()->enrol_user($teacher->id, $course1->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course1->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $pricecategorydata1 = (object) [
            'ordernum' => 1,
            'name' => 'default',
            'identifier' => 'default',
            'defaultvalue' => 50,
            'pricecatsortorder' => 1,
        ];
        $plugingenerator->create_pricecategory($pricecategorydata1);
        $pricecategorydata2 = (object) [
            'ordernum' => 2,
            'name' => 'ZeroPrice',
            'identifier' => 'zeroprice',
            'defaultvalue' => 0,
            'pricecatsortorder' => 2,
        ];
        $plugingenerator->create_pricecategory($pricecategorydata2);

        $pricecategorydata3 = (object) [
            'ordernum' => 3,
            'name' => 'RealPrice',
            'identifier' => 'realprice',
            'defaultvalue' => 100,
            'pricecatsortorder' => 3,
        ];
        $plugingenerator->create_pricecategory($pricecategorydata3);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course2->id;
        $record->maxanswers = 2;
        $record->useprice = 1; // Use price from the default category.
        $record->importing = 1;
        $option1 = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        // To avoid retrieving the singleton with the wrong settings, we destroy it.
        singleton_service::destroy_booking_singleton_by_cmid($settings->cmid);
        $boinfo = new bo_info($settings);

        // Try to book option1 by the student1 - blocked.
        $this->setUser($student1);
        singleton_service::destroy_user($student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id);
        // The user sees now either the payment button or the noshoppingcart message.
        if (class_exists('local_shopping_cart\shopping_cart')) {
            $this->assertEquals(MOD_BOOKING_BO_COND_PRICEISSET, $id);
        } else {
            $this->assertEquals(MOD_BOOKING_BO_COND_NOSHOPPINGCART, $id);
        }
        // Validation that price == category price.
        $price = price::get_price('option', $settings->id);
        $this->assertEquals($pricecategorydata3->defaultvalue, $price["price"]);

        // Try to book option1 by the student2 - blocked but with 0 price.
        $this->setUser($student2);
        singleton_service::destroy_user($student2->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id);
        // The user sees now either the payment button or the noshoppingcart message.
        if (class_exists('local_shopping_cart\shopping_cart')) {
            $this->assertEquals(MOD_BOOKING_BO_COND_PRICEISSET, $id);
        } else {
            $this->assertEquals(MOD_BOOKING_BO_COND_NOSHOPPINGCART, $id);
        }
        // Validation of 0 price.
        $price = price::get_price('option', $settings->id);
        $this->assertEquals(0, $price["price"]);

        // Change displayemptyprice setting value.
        $this->setAdminUser();
        set_config('displayemptyprice', 0, 'booking');

        // Try to book option1 by the student1 - still blocked.
        $this->setUser($student1);
        singleton_service::destroy_user($student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id);
        // The user sees now either the payment button or the noshoppingcart message.
        if (class_exists('local_shopping_cart\shopping_cart')) {
            $this->assertEquals(MOD_BOOKING_BO_COND_PRICEISSET, $id);
        } else {
            $this->assertEquals(MOD_BOOKING_BO_COND_NOSHOPPINGCART, $id);
        }
        $price = price::get_price('option', $settings->id);
        $this->assertEquals($pricecategorydata3->defaultvalue, $price["price"]);

        // Try to book option1 by the student2 - allowed.
        $this->setUser($student2);
        singleton_service::destroy_user($student2->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id);
        // The user sees now either the payment button or the noshoppingcart message.
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);

        // Book student2 and verify it.
        $result = booking_bookit::bookit('option', $settings->id, $student2->id);
        $result = booking_bookit::bookit('option', $settings->id, $student2->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Mandatory clean-up.
        singleton_service::get_instance()->userpricecategory = [];
    }

    /**
     * Test of booking option with fallback different displayemptyprice settings.
     *
     * @covers \mod_booking\bo_availability\conditions\priceisset::is_available
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     *
     */
    public function test_booking_bookit_with_pricecategories_and_fallback(array $bdata): void {
        global $DB, $CFG;

        $this->tearDown();

        $this->setAdminUser();

        // Set parems requred for cancellation.
        $bdata['cancancelbook'] = 1;
        if (class_exists('local_shopping_cart\shopping_cart')) {
            set_config('cancelationfee', 0, 'local_shopping_cart');
        }

        // Setup test data.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $course2 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create user profile custom fields.
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'pricecat',
            'name' => 'pricecat',
        ]);
        set_config('pricecategoryfield', 'pricecat', 'booking');
        set_config('displayemptyprice', 1, 'booking');

        // Create users.
        $student1 = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'student']);
        $student2 = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'staff']);
        $student3 = $this->getDataGenerator()->create_user();
        $student4 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->getDataGenerator()->enrol_user($student1->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student3->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student4->id, $course1->id);
        $this->getDataGenerator()->enrol_user($teacher->id, $course1->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course1->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $pricecategorydata1 = (object) [
            'ordernum' => 1,
            'name' => 'default',
            'identifier' => 'default',
            'defaultvalue' => 50,
            'pricecatsortorder' => 1,
        ];
        $plugingenerator->create_pricecategory($pricecategorydata1);
        $pricecategorydata2 = (object) [
            'ordernum' => 2,
            'name' => 'staff',
            'identifier' => 'staff',
            'defaultvalue' => 100,
            'pricecatsortorder' => 2,
        ];
        $plugingenerator->create_pricecategory($pricecategorydata2);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course2->id;
        $record->maxanswers = 2;
        $record->useprice = 1; // Use price from the default category.
        $record->importing = 1;
        $option1 = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $boinfo = new bo_info($settings);

        // Student one should see the price no price -> blocked.
        $this->setUser($student1);
        singleton_service::destroy_instance();

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id);
        // The user sees now either the payment button or the noshoppingcart message.
        if (class_exists('local_shopping_cart\shopping_cart')) {
            $this->assertEquals(MOD_BOOKING_BO_COND_PRICEISSET, $id);
        } else {
            $this->assertEquals(MOD_BOOKING_BO_COND_NOSHOPPINGCART, $id);
        }

        // Validation that price == category price.
        $price = price::get_price('option', $settings->id);
        $this->assertEmpty($price);

        // Student one should see the the staff price.
        $this->setUser($student2);
        singleton_service::destroy_instance();

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id);
        // The user sees now either the payment button or the noshoppingcart message.
        if (class_exists('local_shopping_cart\shopping_cart')) {
            $this->assertEquals(MOD_BOOKING_BO_COND_PRICEISSET, $id);
        } else {
            $this->assertEquals(MOD_BOOKING_BO_COND_NOSHOPPINGCART, $id);
        }

        // Validation that price == category price.
        $price = price::get_price('option', $settings->id);
        $this->assertEquals($pricecategorydata2->defaultvalue, $price["price"]);

        // Student one should see the the default price.
        $this->setUser($student3);
        singleton_service::destroy_instance();

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id);
        // The user sees now either the payment button or the noshoppingcart message.
        if (class_exists('local_shopping_cart\shopping_cart')) {
            $this->assertEquals(MOD_BOOKING_BO_COND_PRICEISSET, $id);
        } else {
            $this->assertEquals(MOD_BOOKING_BO_COND_NOSHOPPINGCART, $id);
        }

        // Validation that price == category price.
        $price = price::get_price('option', $settings->id);
        $this->assertEquals($pricecategorydata1->defaultvalue, $price["price"]);

        // Now we change the default setting an run everything again.
        $this->setAdminUser();
        set_config('pricecategoryfallback', 1, 'booking');

        // Student one should see the price no price -> blocked.
        $this->setUser($student1);
        singleton_service::destroy_instance();

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id);
        // The user sees now either the payment button or the noshoppingcart message.
        if (class_exists('local_shopping_cart\shopping_cart')) {
            $this->assertEquals(MOD_BOOKING_BO_COND_PRICEISSET, $id);
        } else {
            $this->assertEquals(MOD_BOOKING_BO_COND_NOSHOPPINGCART, $id);
        }

        // Validation that price == category price.
        $price = price::get_price('option', $settings->id);
        $this->assertEquals($pricecategorydata1->defaultvalue, $price["price"]);

        // Student one should see the the staff price.
        $this->setUser($student2);
        singleton_service::destroy_instance();

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id);
        // The user sees now either the payment button or the noshoppingcart message.
        if (class_exists('local_shopping_cart\shopping_cart')) {
            $this->assertEquals(MOD_BOOKING_BO_COND_PRICEISSET, $id);
        } else {
            $this->assertEquals(MOD_BOOKING_BO_COND_NOSHOPPINGCART, $id);
        }

        // Validation that price == category price.
        $price = price::get_price('option', $settings->id);
        $this->assertEquals($pricecategorydata2->defaultvalue, $price["price"]);

        // Student one should see the the default price.
        $this->setUser($student3);
        singleton_service::destroy_instance();

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id);
        // The user sees now either the payment button or the noshoppingcart message.
        if (class_exists('local_shopping_cart\shopping_cart')) {
            $this->assertEquals(MOD_BOOKING_BO_COND_PRICEISSET, $id);
        } else {
            $this->assertEquals(MOD_BOOKING_BO_COND_NOSHOPPINGCART, $id);
        }

        // Validation that price == category price.
        $price = price::get_price('option', $settings->id);
        $this->assertEquals($pricecategorydata1->defaultvalue, $price["price"]);

        // Now we change the default setting an run everything again.
        $this->setAdminUser();
        set_config('pricecategoryfallback', 2, 'booking');

        // Student one should see the price no price -> blocked.
        $this->setUser($student1);
        singleton_service::destroy_instance();

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id);
        // The user sees now either the payment button or the noshoppingcart message.
        if (class_exists('local_shopping_cart\shopping_cart')) {
            $this->assertEquals(MOD_BOOKING_BO_COND_PRICEISSET, $id);
        } else {
            $this->assertEquals(MOD_BOOKING_BO_COND_NOSHOPPINGCART, $id);
        }

        // Validation that price == category price.
        $price = price::get_price('option', $settings->id);
        $this->assertEmpty($price);

        // Student one should see the the staff price.
        $this->setUser($student2);
        singleton_service::destroy_instance();

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id);
        // The user sees now either the payment button or the noshoppingcart message.
        if (class_exists('local_shopping_cart\shopping_cart')) {
            $this->assertEquals(MOD_BOOKING_BO_COND_PRICEISSET, $id);
        } else {
            $this->assertEquals(MOD_BOOKING_BO_COND_NOSHOPPINGCART, $id);
        }

        // Validation that price == category price.
        $price = price::get_price('option', $settings->id);
        $this->assertEquals($pricecategorydata2->defaultvalue, $price["price"]);

        // Student one should see the the default price.
        $this->setUser($student3);
        singleton_service::destroy_instance();

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id);
        // The user sees now either the payment button or the noshoppingcart message.
        if (class_exists('local_shopping_cart\shopping_cart')) {
            $this->assertEquals(MOD_BOOKING_BO_COND_PRICEISSET, $id);
        } else {
            $this->assertEquals(MOD_BOOKING_BO_COND_NOSHOPPINGCART, $id);
        }

        // Validation that price == category price.
        $price = price::get_price('option', $settings->id);
        $this->assertEmpty($price);
    }

    /**
     * Test booking, cancelation, option has started etc.
     *
     * @covers \mod_booking\bo_availability\conditions\bookitbutton::is_available
     * @covers \mod_booking\bo_availability\conditions\alreadybooked::is_available
     * @covers \mod_booking\bo_availability\conditions\fullybooked::is_available
     * @covers \mod_booking\bo_availability\conditions\confirmation::render_page
     * @covers \mod_booking\bo_availability\conditions\notifymelist::is_available
     * @covers \mod_booking\bo_availability\conditions\isloggedin::is_available
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_booking_bookit_simple(array $bdata): void {
        global $DB, $CFG;

        $bdata['cancancelbook'] = 1;

        // Setup test data.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $admin = $this->getDataGenerator()->create_user();
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $student3 = $this->getDataGenerator()->create_user();
        $student4 = $this->getDataGenerator()->create_user();
        $student5 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course->id);
        $this->getDataGenerator()->enrol_user($student2->id, $course->id);
        $this->getDataGenerator()->enrol_user($student3->id, $course->id);
        $this->getDataGenerator()->enrol_user($student4->id, $course->id);
        $this->getDataGenerator()->enrol_user($student5->id, $course->id);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1';
        $record->courseid = 0;
        $record->maxanswers = 2;

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        // To avoid retrieving the singleton with the wrong settings, we destroy it.
        singleton_service::destroy_booking_singleton_by_cmid($settings->cmid);

        // Book the first user without any problem.
        $boinfo = new bo_info($settings);

        // Check option availability if user is not logged yet.
        require_logout();
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_ISLOGGEDIN, $id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ISLOGGEDIN, $id);

        $this->setAdminUser();
        // Via this line, we can get the blocking condition.
        // The true is only hardblocking, which means low blockers used to only show buttons etc. wont be shown.
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);

        // We are allowed to book.
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMBOOKIT, $id);

        // Now we can actually book.
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // When we run it again, we might want to cancel.
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMCANCEL, $id);

        // Now confirm cancel.
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);

        // The result is, that we see the bookingbutton again.
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);

        // That was just for fun. Now we make sure the user is booked again.
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);

        // Now book the second user.
        $result = booking_bookit::bookit('option', $settings->id, $student2->id);
        $result = booking_bookit::bookit('option', $settings->id, $student2->id);

        // Now, all the available places are booked. We try to book the third user.
        $this->setUser($student3);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student3->id, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_FULLYBOOKED, $id);

        // We still try to book, but no chance.
        $result = booking_bookit::bookit('option', $settings->id, $student3->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_FULLYBOOKED, $id);

        // Check for guest user too - should be "fully booked" as well.
        $this->setGuestUser();
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, 1, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_FULLYBOOKED, $id);

        // Now we add waitinglist to option.
        $this->setAdminUser();
        $record->id = $option1->id;
        $record->maxoverbooking = 1;
        $record->cmid = $settings->cmid;
        booking_option::update($record);

        // Check for guest user - should be allowed to booking in general.
        $this->setGuestUser();
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, 1, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_ISLOGGEDIN, $id);

        // Book student3 again.
        $this->setUser($student3);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student3->id, false);

        // Bookitbutton blocks.
        $result = booking_bookit::bookit('option', $settings->id, $student3->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student3->id, false);

        // Now student3 is on waitinglist.
        $result = booking_bookit::bookit('option', $settings->id, $student3->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student3->id, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Waitinglist is full, no further user can be booked.
        $this->setUser($student4);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student4->id, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_FULLYBOOKED, $id);

        // Now we set waitinglist to unlimited.
        $this->setAdminUser();
        $record->id = $option1->id;
        $record->maxoverbooking = -1;
        $record->cmid = $settings->cmid;
        booking_option::update($record);

        // And try again to book user4 again.
        $this->setUser($student4);
        $result = booking_bookit::bookit('option', $settings->id, $student4->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student4->id, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMBOOKIT, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student4->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student4->id, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Make sure, waitinglist is full and use notification list.
        $this->setAdminUser();
        $record->id = $option1->id;
        $record->maxoverbooking = 2;
        $record->cmid = $settings->cmid;
        booking_option::update($record);
        $res = set_config('usenotificationlist', 1, 'booking');

        $this->setUser($student5);

        // Now student4 is on notification list.
        $result = booking_bookit::bookit('option', $settings->id, $student5->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student5->id, false);

        // User really is booked to notifylist.
        $this->assertEquals(MOD_BOOKING_BO_COND_NOTIFYMELIST, $id);
    }

    /**
     * Test of booking option availability by cohorts and bookingtime.
     *
     * @covers \mod_booking\bo_availability\conditions\booking_time::is_available
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_booking_bookit_cohorts_and_bookingtime(array $bdata): void {
        global $DB, $CFG;

        $bdata['cancancelbook'] = 1;

        // Setup test data.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $course2 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $admin = $this->getDataGenerator()->create_user();
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $student3 = $this->getDataGenerator()->create_user();
        $student4 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        // Create 2 cohorts.
        $contextsystem = \context_system::instance();
        $cohort1 = $this->getDataGenerator()->create_cohort([
            'contextid' => $contextsystem->id,
            'name' => 'System booking cohort 1',
            'idnumber' => 'SBC1',
        ]);
        $cohort2 = $this->getDataGenerator()->create_cohort([
            'contextid' => $contextsystem->id,
            'name' => 'System booking cohort 2',
            'idnumber' => 'SBC2',
        ]);

        $this->setAdminUser();

        cohort_add_member($cohort1->id, $student1->id);
        cohort_add_member($cohort1->id, $student2->id);
        cohort_add_member($cohort2->id, $student2->id);

        $this->getDataGenerator()->enrol_user($student1->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student3->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student4->id, $course1->id);
        $this->getDataGenerator()->enrol_user($teacher->id, $course1->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course1->id);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1 (availability by cohort and time)';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course1->id;

        // Set test availability setting(s).
        $record->bo_cond_enrolledincohorts_restrict = 1;
        $record->bo_cond_enrolledincohorts_cohortids = [$cohort1->id, $cohort2->id];
        $record->bo_cond_enrolledincohorts_cohortids_operator = 'AND';
        $record->bo_cond_enrolledincohorts_sqlfiltercheck = 1;

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $boinfo = new bo_info($settings);

        // Try to book student1 NOT - allowed.
        $this->setUser($student1);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_JSON_ENROLLEDINCOHORTS, $id);

        // Try to book student2 - allowed.
        $this->setUser($student2);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);

        $result = booking_bookit::bookit('option', $settings->id, $student2->id);
        $result = booking_bookit::bookit('option', $settings->id, $student2->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Try to book student3 - NOT allowed.
        $this->setUser($student3);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_JSON_ENROLLEDINCOHORTS, $id);

        // Now we  update test availability setting(s).
        $this->setAdminUser();
        $record->id = $option1->id;
        $record->bo_cond_enrolledincohorts_cohortids_operator = 'OR';
        $record->cmid = $settings->cmid;
        booking_option::update($record);

        // Try to book student1 - allowed.
        $this->setUser($student1);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);

        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Try to book student3 - NOT allowed.
        $this->setUser($student3);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_JSON_ENROLLEDINCOHORTS, $id);
    }

    /**
     * Test enrol user and add to group.
     *
     * @covers \mod_booking\booking_bookit::bookit
     * @covers \mod_booking\booking_option::enrol_user
     * @covers \mod_booking\option\fields\addtogroup::save_data
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_booking_bookit_add_to_group(array $bdata): void {
        global $DB, $CFG;

        $bdata['cancancelbook'] = 1;
        $bdata['addtogroup'] = 1;
        $bdata['autoenrol'] = "1";

        // Setup test data.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $course2 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $admin = $this->getDataGenerator()->create_user();
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $student3 = $this->getDataGenerator()->create_user();
        $student4 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($admin->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student1->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student3->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student4->id, $course1->id);
        $this->getDataGenerator()->enrol_user($teacher->id, $course1->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course1->id);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1 (enroll to group)';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course2->id;
        $record->maxanswers = 2;

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($record);

        singleton_service::destroy_booking_option_singleton($option1->id);
        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        // To avoid retrieving the singleton with the wrong settings, we destroy it.
        singleton_service::destroy_booking_singleton_by_cmid($settings->cmid);

        // Book the first user without any problem.
        $boinfo = new bo_info($settings);

        // Book the student right away.
        $this->setUser($student1);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);

        // Via this line, we can get the blocking condition.
        // The true is only hardblocking, which means low blockers used to only show buttons etc. wont be shown.
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Now check if the user is enrolled to the course.
        // We should get two courses.
        $courses = enrol_get_users_courses($student1->id);
        $this->assertEquals(count($courses), 2);

        // Now check if the user is enrolled in the right group.
        $groups = groups_get_all_groups($course2->id);
        $group = reset($groups);

        // First assert that the group is actually created.
        $this->assertStringContainsString($settings->text, $group->name);

        // No check if the user is in the group.
        $groupmembers = groups_get_members($group->id);

        $this->assertArrayHasKey($student1->id, $groupmembers);

        // Unenrol user again.
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);
    }

    /**
     * Test add to group.
     *
     * @covers \mod_booking\bo_availability\conditions\booking_time::is_available
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_booking_bookit_bookingtime(array $bdata): void {
        global $DB, $CFG;

        $bdata['cancancelbook'] = 1;

        // Setup test data.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $course2 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $admin = $this->getDataGenerator()->create_user();
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $student3 = $this->getDataGenerator()->create_user();
        $student4 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($admin->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student1->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student3->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student4->id, $course1->id);
        $this->getDataGenerator()->enrol_user($teacher->id, $course1->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course1->id);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1 (option time)';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course2->id;
        $record->maxanswers = 2;
        $record->restrictanswerperiodopening = 1;
        $record->bookingopeningtime = strtotime('now + 2 day');
        $record->bookingclosingtime = strtotime('now + 3 day');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);

        // Book the first user without any problem.
        $boinfo = new bo_info($settings);

        // Book the student right away.
        $this->setUser($student1);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKING_TIME, $id);

        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);

        // Via this line, we can get the blocking condition.
        // The true is only hardblocking, which means low blockers used to only show buttons etc. wont be shown.
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKING_TIME, $id);
    }

    /**
     * Test add to group.
     *
     * @covers \mod_booking\bo_availability\conditions\alreadybooked::is_available
     * @covers \mod_booking\bo_availability\conditions\confirmation::is_available
     * @covers \mod_booking\bo_availability\conditions\onwaitinglist::is_available
     * @covers \mod_booking\bo_availability\conditions\askforconfirmation::render_page
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_booking_bookit_askforconfirmation(array $bdata): void {
        global $DB, $CFG;

        $bdata['cancancelbook'] = 1;

        // Setup test data.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $course2 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $admin = $this->getDataGenerator()->create_user();
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $student3 = $this->getDataGenerator()->create_user();
        $student4 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($admin->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student1->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student3->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student4->id, $course1->id);
        $this->getDataGenerator()->enrol_user($teacher->id, $course1->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course1->id);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course2->id;
        $record->maxanswers = 2;
        $record->waitforconfirmation = 1;

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);

        // Book the first user without any problem.
        $boinfo = new bo_info($settings);

        // Book the student right away.
        $this->setUser($student1);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ASKFORCONFIRMATION, $id);

        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        $this->setAdminUser();
        $option->user_submit_response($student1, 0, 0, 0, MOD_BOOKING_VERIFIED);

        $this->setUser($student1);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);
    }

    /**
     * Test add to group.
     *
     * @covers \mod_booking\bo_availability\conditions\askforconfirmation::is_available
     * @covers \mod_booking\bo_availability\conditions\onwaitinglist::is_available
     * @covers \mod_booking\bo_availability\conditions\priceisset::is_available
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_booking_bookit_askforconfirmation_with_price(array $bdata): void {
        global $DB, $CFG;

        $bdata['cancancelbook'] = 1;

        // Setup test data.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $course2 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $admin = $this->getDataGenerator()->create_user();
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $student3 = $this->getDataGenerator()->create_user();
        $student4 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($admin->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student1->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student3->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student4->id, $course1->id);
        $this->getDataGenerator()->enrol_user($teacher->id, $course1->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course1->id);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course2->id;
        $record->maxanswers = 2;
        $record->maxoverbooking = 2;
        $record->waitforconfirmation = 1;
        $record->useprice = 1; // Use price from the default category.
        $record->importing = 1;

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $pricecategorydata = (object) [
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

        // Check option availability if user is not logged yet.
        require_logout();
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_ISLOGGEDINPRICE, $id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ISLOGGEDINPRICE, $id);

        // Book option1 by the student1 himself.
        $this->setUser($student1);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ASKFORCONFIRMATION, $id);

        // The user books now and is only on waitinglist.
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        // Admin confirms the users booking.
        $this->setAdminUser();
        // Verify price.
        $price = price::get_price('option', $settings->id);
        // Default price expected.
        $this->assertEquals($price["price"], 100);

        // Add to the shopping_cart - set ALREADYRESERVED and verify status.
        $option->user_submit_response($student1, 0, 0, 1, MOD_BOOKING_VERIFIED);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYRESERVED, $id);

        // Confirm user's booking.
        $option->user_submit_response($student1, 0, 0, 2, MOD_BOOKING_VERIFIED);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);

        $this->setUser($student1);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);

        // The user sees now, after confirmation, correctly either the payment button or the noshoppingcart message.
        if (class_exists('local_shopping_cart\shopping_cart')) {
            $this->assertEquals(MOD_BOOKING_BO_COND_PRICEISSET, $id);
        } else {
            $this->assertEquals(MOD_BOOKING_BO_COND_NOSHOPPINGCART, $id);
        }

        $this->setAdminUser();
        // In this test, we book the user directly (we don't test the payment process).
        $option->user_submit_response($student1, 0, 0, 0, MOD_BOOKING_VERIFIED);

        // User 1 should be booked now.
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Book a second user to fill the waitinglist.
        $option->user_submit_response($student2, 0, 0, 0, MOD_BOOKING_VERIFIED);
        // We need to do it again, because we are booked on waitinglist first.
        $option->user_submit_response($student2, 0, 0, 0, MOD_BOOKING_VERIFIED);

        // Verify that second user is booked.
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Now we check the third user.
        $this->setUser($student3);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ASKFORCONFIRMATION, $id);

        // The user is booked on the waitinglist.
        $result = booking_bookit::bookit('option', $settings->id, $student3->id);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Now we check the fourth user.
        $this->setUser($student4);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student4->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ASKFORCONFIRMATION, $id);

        // The user is booked on the waitinglist.
        $result = booking_bookit::bookit('option', $settings->id, $student4->id);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student4->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Confirm the user.
        $this->setAdminUser();
        $option->user_submit_response($student3, 0, 0, 2, MOD_BOOKING_VERIFIED);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        $ba = singleton_service::get_instance_of_booking_answers($settings);

        // Should still be on waitinglist.
        // Not seeing price.
        $this->setUser($student3);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Now we take one user off the waitinglist.
        $this->setUser($student1);
        $option->user_delete_response($student1->id);

        // So student1 is now delelted again, a place on the waitinglist has freed up.
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ASKFORCONFIRMATION, $id);

        // Also take student 2 from the list, for a second place to free up.
        $this->setUser($student2);
        $option->user_delete_response($student2->id);

        // So student2 is now delelted again, a place on the waitinglist has freed up.
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ASKFORCONFIRMATION, $id);

        // There should be still one free place left.
        $ba = singleton_service::get_instance_of_booking_answers($settings);

        // Status for user 3 should have changed, from waitinglist to price is set.
        $this->setUser($student3);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student3->id, true);
        if (class_exists('local_shopping_cart\shopping_cart')) {
            $this->assertEquals(MOD_BOOKING_BO_COND_PRICEISSET, $id);
        } else {
            $this->assertEquals(MOD_BOOKING_BO_COND_NOSHOPPINGCART, $id);
        }

        // Even though there are still two free places:
        // As we didn't confirm student4, he should still be on waitinglist, even though a place would be free.
        $this->setUser($student4);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student4->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Mandatory clean-up.
        singleton_service::get_instance()->userpricecategory = [];
    }

    /**
     * Test booking option availability: \condition\bookwithcredits.
     *
     * @covers \mod_booking\bo_availability\conditions\bookwithcredits::is_available
     * @covers \mod_booking\bo_availability\conditions\confirmbookwithcredits::is_available
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_booking_bookwithcredits(array $bdata): void {
        global $CFG;

        $this->resetAfterTest();

        // Setup test data.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create custom profile field.
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'credit',
            'name' => 'Credit',
            'visible' => PROFILE_VISIBLE_NONE,
        ]);

        // Create users.
        $users = [
            ['username' => 'student1', 'email' => 'student1@example.com', 'profile_field_credit' => '50'],
            ['username' => 'student2', 'email' => 'student2@sample.com', 'profile_field_credit' => '150'],
            ['username' => 'teacher', 'email' => 'teacher@sample.com', 'profile_field_credit' => '0'],
        ];
        $student1 = $this->getDataGenerator()->create_user($users[0]);
        $student2 = $this->getDataGenerator()->create_user($users[1]);
        $teacher = $this->getDataGenerator()->create_user($users[2]);
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        $bdata['cancancelbook'] = 1;
        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_bookingid($booking1->id);
        // To avoid retrieving the singleton with the wrong settings, we destroy it.
        singleton_service::destroy_booking_singleton_by_cmid($bookingsettings->cmid);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id);
        $this->getDataGenerator()->enrol_user($teacher->id, $course1->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course1->id);

        $res = set_config('bookwithcreditsactive', 1, 'booking');
        $res = set_config('bookwithcreditsprofilefield', 'credit', 'booking');

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course1->id;
        $record->credits = 100;

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($record);
        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $optionobj1 = singleton_service::get_instance_of_booking_option($settings1->cmid, $settings1->id);

        $boinfo1 = new bo_info($settings1);

        // Book the student2.
        $this->setUser($student2);
        singleton_service::destroy_user($student2->id);

        // Verify book with credits.
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKWITHCREDITS, $id);

        // Student2 does allowed to book option1 in course1.
        $result = booking_bookit::bookit('option', $settings1->id, $student2->id);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMBOOKWITHCREDITS, $id);

        $result = booking_bookit::bookit('option', $settings1->id, $student2->id);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // When we run it again, we might want to cancel.
        $result = booking_bookit::bookit('option', $settings1->id, $student2->id);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMCANCEL, $id);
        // Now confirm cancel.
        $result = booking_bookit::bookit('option', $settings1->id, $student2->id);

        // The result is, that we see the bookingbutton again.
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKWITHCREDITS, $id);

        // Book the student1.
        $this->setUser($student1);
        singleton_service::destroy_user($student1->id);

        // Student1 does not allowed to book option1 in course1 - no enough credits.
        $result = booking_bookit::bookit('option', $settings1->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMBOOKWITHCREDITS, $id);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('mod_booking/notenoughcredits');
        $result = booking_bookit::bookit('option', $settings1->id, $student1->id);
    }

    /**
     * Test overbooking with price when confirmation and waiting list disabled.
     *
     * @covers \mod_booking\bo_availability\conditions\askforconfirmation::is_available
     * @covers \mod_booking\bo_availability\conditions\onwaitinglist::is_available
     * @covers \mod_booking\bo_availability\conditions\priceisset::is_available
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_booking_bookit_overbooking_with_price(array $bdata): void {
        global $DB, $CFG;

        // Setup test data.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $course2 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id);
        $this->getDataGenerator()->enrol_user($teacher->id, $course1->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course1->id);

        // Disable allowoverbooking at all.
        $res = set_config('allowoverbooking', 0, 'booking');
        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /* $res = set_config('allowoverbooking', null, 'booking'); */

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course2->id;
        $record->maxanswers = 1;
        $record->maxoverbooking = 0;  // Disable waitinglist.
        $record->waitforconfirmation = 0; // Waitinglist nof enforced.
        $record->useprice = 1; // Use price from the default category.
        $record->importing = 1;

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $pricecategorydata = (object) [
            'ordernum' => 1,
            'name' => 'default',
            'identifier' => 'default',
            'defaultvalue' => 100,
            'pricecatsortorder' => 1,
        ];

        $plugingenerator->create_pricecategory($pricecategorydata);
        $option1 = $plugingenerator->create_option($record);

        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);
        // To avoid retrieving the singleton with the wrong settings, we destroy it.
        singleton_service::destroy_booking_singleton_by_cmid($settings1->cmid);
        $price = price::get_price('option', $settings1->id);

        // Default price expected.
        $this->assertEquals($price["price"], 100);

        // Book the first user without any problem.
        $boinfo1 = new bo_info($settings1);

        // Book the student1 right away.
        $this->setUser($student1);

        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student1->id, true);
        // The user sees now, after confirmation, correctly either the payment button or the noshoppingcart message.
        if (class_exists('local_shopping_cart\shopping_cart')) {
            $this->assertEquals(MOD_BOOKING_BO_COND_PRICEISSET, $id);
        } else {
            $this->assertEquals(MOD_BOOKING_BO_COND_NOSHOPPINGCART, $id);
        }

        // Admin confirms the users booking.
        $this->setAdminUser();

        $optionobj1 = singleton_service::get_instance_of_booking_option($settings1->cmid, $settings1->id);
        // Confirm user's booking.
        $optionobj1->user_submit_response($student1, 0, 0, 0, MOD_BOOKING_VERIFIED);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Book option 1 by the student2.
        $this->setUser($student2);

        // The student2 cannot book - option is fully booked.
        $result = booking_bookit::bookit('option', $settings1->id, $student2->id);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_FULLYBOOKED, $id);

        // Admin try to override.
        $this->setAdminUser();

        // Cehck current state. We should not use hard block to get "fully booked" (will get permanent "ask confirmation" than).
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student2->id, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_FULLYBOOKED, $id);
        // Even Admin denied to override and confirms the stundet2 booking.
        $optionobj1->user_submit_response($student2, 0, 0, 0, MOD_BOOKING_VERIFIED);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student2->id, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_FULLYBOOKED, $id);

        // Enable overbooking by athorized user.
        $res = set_config('allowoverbooking', 1, 'booking');

        // Admin's override now successfull - student2 being booked.
        $optionobj1->user_submit_response($student2, 0, 0, 0, MOD_BOOKING_VERIFIED);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Mandatory clean-up.
        singleton_service::get_instance()->userpricecategory = [];
    }

    /**
     * Test overlapping.
     *
     * @covers \mod_booking\bo_availability\conditions\isbookable::is_available
     * @covers \mod_booking\bo_availability\conditions\bookitbutton::is_available
     * @covers \mod_booking\bo_availability\conditions\alreadybooked::is_available
     * @covers \mod_booking\bo_availability\conditions\nooverlapping::is_available
     * @covers \mod_booking\bo_availability\conditions\nooverlappingproxy::is_available
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_booking_bookit_overlapping(array $bdata): void {
        global $DB, $CFG;

        // Setup test data.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course->id);
        $this->getDataGenerator()->enrol_user($student2->id, $course->id);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1';
        $record->courseid = 0;
        $record->maxanswers = 2;
        $record->disablebookingusers = 0;
        $record->coursestarttime = strtotime('now + 3 day');
        $record->courseendtime = strtotime('now + 6 day');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($record);

        // Times are overlapping, so expected to be blocked by this condtion.
        $record->coursestarttime = strtotime('now + 2 day');
        $record->courseendtime = strtotime('now + 4 day');
        $record->bo_cond_nooverlapping_restrict = 1;
        $record->bo_cond_nooverlapping_handling = MOD_BOOKING_COND_OVERLAPPING_HANDLING_BLOCK;
        $option2 = $plugingenerator->create_option($record);

        // Not overlapping.
        $record->coursestarttime = strtotime('now + 7 day');
        $record->courseendtime = strtotime('now + 8 day');
        $record->bo_cond_nooverlapping_restrict = 1;
        $record->bo_cond_nooverlapping_handling = MOD_BOOKING_COND_OVERLAPPING_HANDLING_BLOCK;
        $option3 = $plugingenerator->create_option($record);

        // Overlapping without flag. Should trigger NOOVERLAPPINGPROXY.
        $record->coursestarttime = strtotime('now + 6 day');
        $record->courseendtime = strtotime('now + 9 day');
        $record->bo_cond_nooverlapping_restrict = 0;
        unset($record->bo_cond_nooverlapping_handling);
        $option4 = $plugingenerator->create_option($record);

        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $settings2 = singleton_service::get_instance_of_booking_option_settings($option2->id);
        $settings3 = singleton_service::get_instance_of_booking_option_settings($option3->id);
        $settings4 = singleton_service::get_instance_of_booking_option_settings($option4->id);
        $boinfo1 = new bo_info($settings1);
        $boinfo2 = new bo_info($settings2);
        $boinfo3 = new bo_info($settings3);
        $boinfo4 = new bo_info($settings4);

        // Book user to first option.
        $this->setUser($student1);
        $result = booking_bookit::bookit('option', $settings1->id, $student1->id);
        $result = booking_bookit::bookit('option', $settings1->id, $student1->id);

        // Check for option2, should be blocked because of overlapping.
        [$id, $isavailable, $description] = $boinfo2->is_available($settings2->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_JSON_NOOVERLAPPING, $id);

        // Check for option3, should not be blocked.
        [$id, $isavailable, $description] = $boinfo3->is_available($settings3->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);

        // Now enrol into bookingoption 3 which is forbidden to be booked with overlapping times.
        $result = booking_bookit::bookit('option', $settings3->id, $student1->id);
        $result = booking_bookit::bookit('option', $settings3->id, $student1->id);
        // Check that it really was booked.
        [$id, $isavailable, $description] = $boinfo3->is_available($settings3->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        singleton_service::destroy_answers_for_user($student1->id); // Destroy all answers for this user.
        // Now try to book an option that doesn't contain the nooverlapping flab BUT overlaps with previously booked option 3.
        [$id, $isavailable, $description] = $boinfo4->is_available($settings4->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_JSON_NOOVERLAPPINGPROXY, $id);
    }

    /**
     * Test overlapping.
     *
     * @covers \mod_booking\bo_availability\conditions\isbookable::is_available
     * @covers \mod_booking\bo_availability\conditions\bookitbutton::is_available
     * @covers \mod_booking\bo_availability\conditions\alreadybooked::is_available
     * @covers \mod_booking\bo_availability\conditions\nooverlapping::is_available
     * @covers \mod_booking\bo_availability\conditions\nooverlappingproxy::is_available
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_booking_bookit_overlapping_sessions(array $bdata): void {
        global $DB, $CFG;
        $this->tearDown();

        // Setup test data.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course->id);
        $this->getDataGenerator()->enrol_user($student2->id, $course->id);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1';
        $record->courseid = 0;
        $record->maxanswers = 2;
        $record->disablebookingusers = 0;
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('now + 3 day');
        $record->courseendtime_0 = strtotime('now + 4 day');
        $record->optiondateid_1 = "0";
        $record->daystonotify_1 = "0";
        $record->coursestarttime_1 = strtotime('now + 6 day');
        $record->courseendtime_1 = strtotime('now + 7 day');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($record);

        // Times are overlapping, so expected to be blocked by this condtion.
        $record->coursestarttime_0 = strtotime('now + 2 day');
        $record->courseendtime_0 = strtotime('now + 3 day');
        $record->coursestarttime_1 = strtotime('now + 5 day');
        $record->courseendtime_1 = strtotime('now + 8 day');
        $record->bo_cond_nooverlapping_restrict = 1;
        $record->bo_cond_nooverlapping_handling = MOD_BOOKING_COND_OVERLAPPING_HANDLING_BLOCK;
        $option2 = $plugingenerator->create_option($record);

        // Not overlapping.
        $record->text = '2 sessions should overlap';
        $record->coursestarttime_0 = strtotime('now + 10 day');
        $record->courseendtime_0 = strtotime('now + 11 day');
        $record->coursestarttime_1 = strtotime('now + 14 day');
        $record->courseendtime_1 = strtotime('now + 15 day');
        $record->bo_cond_nooverlapping_restrict = 1;
        $record->bo_cond_nooverlapping_handling = MOD_BOOKING_COND_OVERLAPPING_HANDLING_BLOCK;
        $option3 = $plugingenerator->create_option($record);

        // Overlapping without flag. Should trigger NOOVERLAPPINGPROXY.
        $record->coursestarttime_0 = strtotime('now + 1 day');
        $record->courseendtime_0 = strtotime('now + 11 day');
        $record->coursestarttime_1 = strtotime('now + 8 day');
        $record->courseendtime_1 = strtotime('now + 15 day');
        $record->bo_cond_nooverlapping_restrict = 0;
        unset($record->bo_cond_nooverlapping_handling);
        $option4 = $plugingenerator->create_option($record);

        // Testing combinations of multiple and single sessions.
        // Only one session that isn't really overlapping.
        $record->text = 'No session, not overlapping';
        $record->coursestarttime = strtotime('now + 12 day');
        $record->courseendtime = strtotime('now + 13 day');
        $record->bo_cond_nooverlapping_restrict = 1;
        unset($record->coursestarttime_0);
        unset($record->courseendtime_0);
        unset($record->coursestarttime_1);
        unset($record->courseendtime_1);
        unset($record->optiondateid_0);
        unset($record->daystonotify_0);
        unset($record->optiondateid_1);
        unset($record->daystonotify_1);
        $record->bo_cond_nooverlapping_handling = MOD_BOOKING_COND_OVERLAPPING_HANDLING_BLOCK;
        $option5 = $plugingenerator->create_option($record);

        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $settings2 = singleton_service::get_instance_of_booking_option_settings($option2->id);
        $settings3 = singleton_service::get_instance_of_booking_option_settings($option3->id);
        $settings4 = singleton_service::get_instance_of_booking_option_settings($option4->id);
        $settings5 = singleton_service::get_instance_of_booking_option_settings($option5->id);
        $boinfo1 = new bo_info($settings1);
        $boinfo2 = new bo_info($settings2);
        $boinfo3 = new bo_info($settings3);
        $boinfo4 = new bo_info($settings4);
        $boinfo5 = new bo_info($settings5);

        // Book user to first option.
        $this->setUser($student1);
        $result = booking_bookit::bookit('option', $settings1->id, $student1->id);
        $result = booking_bookit::bookit('option', $settings1->id, $student1->id);

        // Check for option2, should be blocked because of overlapping.
        [$id, $isavailable, $description] = $boinfo2->is_available($settings2->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_JSON_NOOVERLAPPING, $id);

        // Check for option3, should not be blocked.
        [$id, $isavailable, $description] = $boinfo3->is_available($settings3->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);

        // Now enrol into bookingoption 3 which is forbidden to be booked with overlapping times.
        $result = booking_bookit::bookit('option', $settings3->id, $student1->id);
        $result = booking_bookit::bookit('option', $settings3->id, $student1->id);
        // Check that it really was booked.
        [$id, $isavailable, $description] = $boinfo3->is_available($settings3->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        singleton_service::destroy_answers_for_user($student1->id); // Destroy all answers for this user.

        // Now try to book an option that doesn't contain the nooverlapping flag BUT overlaps with previously booked option 3.
        [$id, $isavailable, $description] = $boinfo4->is_available($settings4->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_JSON_NOOVERLAPPINGPROXY, $id);

        singleton_service::destroy_instance();
        // Check for option5, should not be blocked.
        [$id, $isavailable, $description] = $boinfo5->is_available($settings5->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);
    }

    /**
     * Data provider for condition_bookingpolicy_test
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    public static function booking_common_settings_provider(): array {
        $bdata = [
            'name' => 'Test Booking Policy 1',
            'eventtype' => 'Test event',
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

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        // Mandatory clean-up.
        singleton_service::destroy_instance();
        cartstore::reset();
    }
}
