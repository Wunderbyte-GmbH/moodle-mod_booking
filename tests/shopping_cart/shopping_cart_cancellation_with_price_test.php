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
final class shopping_cart_cancellation_with_price_test extends advanced_testcase {
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

        // Skip this test if shopping_cart not installed.
        if (!class_exists('local_shopping_cart\shopping_cart')) {
            return;
        }

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

        // Skip this test if shopping_cart not installed.
        if (!class_exists('local_shopping_cart\shopping_cart')) {
            return;
        }

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

        // Skip this test if shopping_cart not installed.
        if (!class_exists('local_shopping_cart\shopping_cart')) {
            return;
        }

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
     * Test of purchase of few booking options with price and cancellation all by cashier with fixed consumption has been set.
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     * @covers \mod_booking\booking_option::cancelbookingoption
     * @dataProvider booking_common_settings_provider
     *
     */
    public function test_booking_option_cancelled_rule_execution(array $bdata): void {
        global $DB, $CFG;

        // Skip this test if shopping_cart not installed.
        if (!class_exists('local_shopping_cart\shopping_cart')) {
            return;
        }

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

        $boevent2 = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_cancelled"';
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"subject":"optcancsubj","template":"optcancmsg","templateformat":"1"}';
        $ruledata2 = [
            'name' => 'notifyusers',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"0"}',
            'actionname' => 'send_mail',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent2 . ',"aftercompletion":"","condition":"0"}',
        ];
        $rule2 = $plugingenerator->create_rule($ruledata2);

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

        // Test dynamic form (partial).
        $formdata = [
            'cancelationfee' => 0,
            'componentname' => 'mod_booking',
            'area' => 'option',
            'itemid' => $settings->id,
        ];
        $form = new modal_cancel_all_addcredit(null, $formdata, 'post', '', [], true, $formdata, true);
        $formdata1 = $form->mock_ajax_submit($formdata);

        // Validate code of modal_cancel_all_addcredit/process_dynamic_submission.
        $data = (object)$formdata1;
        $bookedusers = shopping_cart_history::get_user_list_for_option($data->itemid, $data->componentname, $data->area);

        $componentname = $data->componentname;
        $area = $data->area;

        $userstocancel = [];
        foreach ($bookedusers as $buser) {
            shopping_cart::cancel_purchase(
                $buser->itemid,
                $data->area,
                $buser->userid,
                $componentname,
                $buser->id,
                0,
                0,
                1,
                1
            );
            $userstocancel[] = $buser->userid;
        }

        $answers = $DB->get_records('booking_answers', ['optionid' => $option1->id]);

        // For the booking component, we have a special treatment here.
        if ($componentname === 'mod_booking' && $area === 'option') {
            $pluginmanager = \core_plugin_manager::instance();
            $plugins = $pluginmanager->get_plugins_of_type('mod');
            if (isset($plugins['booking'])) {
                booking_option::cancelbookingoption(
                    $data->itemid,
                    'cancel reason is testcase',
                    false,
                    $userstocancel
                );
            }
        }

        // 3 Users booked, expecting 3 messages triggered by rule.
        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        $this->assertCount(3, $tasks);
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
