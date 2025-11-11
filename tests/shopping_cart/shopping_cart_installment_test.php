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
use context_system;
use stdClass;
use mod_booking\price;
use mod_booking_generator;
use mod_booking\bo_availability\bo_info;
use local_shopping_cart\shopping_cart;
use local_shopping_cart\local\cartstore;
use local_shopping_cart_generator;
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
    /** @var \core_payment\account account */
    protected $account;

    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
        set_config('country', 'AT');
        /** @var \core_payment_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_payment');
        $this->account = $generator->create_payment_account(['name' => 'PayPal1']);
        $record = new stdClass();
        $record->accountid = $this->account->get('id');
        $record->gateway = 'paypal';
        $record->enabled = 1;
        $record->timecreated = time();
        $record->timemodified = time();

        $config = new stdClass();
        $config->environment = 'sandbox';
        // Load the credentials from Github.
        $config->brandname = getenv('BRANDNAME') ?: 'Test paypal';
        $config->clientid = getenv('CLIENTID') ?: 'Test';
        $config->secret = getenv('SECRET') ?: 'Test';

        $record->config = json_encode($config);

        $accountgateway1 = \core_payment\helper::save_payment_gateway($record);
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        // Mandatory clean-up.
        singleton_service::destroy_instance();
        time_mock::reset_mock_time();
        // Mandatory to deal with static variable in the booking_rules.
        rules_info::destroy_singletons();
        rules_info::$rulestoexecute = [];
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
        global $DB, $OUTPUT, $PAGE;

        // Skip this test if shopping_cart not installed.
        if (!class_exists('local_shopping_cart\shopping_cart')) {
            return;
        }

        time_mock::set_mock_time(strtotime('-4 days', time()));
        $time = time_mock::get_mock_time();
        $now = time();
        $this->assertEquals($time, $now);

        // Validate payment account if it has a config.
        $record1 = $DB->get_record('payment_accounts', ['id' => $this->account->get('id')]);
        $this->assertEquals('PayPal1', $record1->name);
        $this->assertCount(1, $DB->get_records('payment_gateways', ['accountid' => $this->account->get('id')]));

        // Set local_shopping_cart to use the payment account.
        set_config('accountid', $this->account->get('id'), 'local_shopping_cart');

        // Set params requred for installment.
        set_config('enableinstallments', 1, 'local_shopping_cart');
        set_config('timebetweenpayments', 3, 'local_shopping_cart');
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

        $record = (object)$bdata['options'][1];
        $record->bookingid = $booking1->id;
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course2->id;
        $record->useprice = 1; // Use price from the default category.
        $record->importing = 1;
        // Allow and configure installemnts for option.
        $record->sch_allowinstallment = 1;
        $record->sch_downpayment = 44;
        $record->sch_numberofpayments = 2;
        $record->sch_duedatevariable = 4;

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $pricecategorydata = $plugingenerator->create_pricecategory($bdata['pricecategories'][0]);

        $option1 = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        // To avoid retrieving the singleton with the wrong settings, we destroy it.
        singleton_service::destroy_booking_singleton_by_cmid($settings->cmid);

        // Create booking rule - "ndays before".
        $ruledata = [
            'name' => 'installment',
            'conditionname' => 'select_user_shopping_cart',
            'contextid' => 1,
            'conditiondata' => '{}',
            'actionname' => 'send_mail',
            'actiondata' => '{"subject":"installment_custom_subj","template":"installment_custom_msg","templateformat":"1"}',
            'rulename' => 'rule_daysbefore',
            'ruledata' => '{"days":"0","datefield":"installmentpayment"}',
        ];
        $rule1 = $plugingenerator->create_rule($ruledata);
        $rules = $DB->get_records('booking_rules');
        $this->assertCount(1, $rules);
        rules_info::execute_booking_rules();
        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');

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
        $this->assertEquals(27.5, $installment['payments'][0]['price']);
        $exppadate = userdate(strtotime('now + 2 days', $time), get_string('strftimedate', 'langconfig'));
        $this->assertEquals($exppadate, $installment['payments'][0]['date']);
        $this->assertEquals(0, $installment['payments'][1]['paid']);
        $this->assertEquals(27.5, $installment['payments'][1]['price']);
        $exppadate = userdate(strtotime('now + 4 days', $time), get_string('strftimedate', 'langconfig'));
        $this->assertEquals($exppadate, $installment['payments'][1]['date']);

        // Confirm cash payment.
        $res = shopping_cart::confirm_payment($student1->id, LOCAL_SHOPPING_CART_PAYMENT_METHOD_CREDITS);
        // Validate payment.
        $this->assertIsArray($res);
        $this->assertEmpty($res['error']);
        $this->assertEquals(56, $res['credit']);
        rules_info::execute_booking_rules();
        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');

        // In this test, we book the user directly (we don't test the payment process).
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $option->user_submit_response($student1, 0, 0, 0, MOD_BOOKING_VERIFIED);

        // User 1 should be booked now.
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        $this->setUser($student1);
        singleton_service::destroy_user($student1->id);

        // Reset cart and move +2 day forward - we should pay 1st installment.
        cartstore::reset();
        time_mock::set_mock_time(strtotime('+2 days', $time));
        $time = time_mock::get_mock_time();
        $debugdate = userdate($time, get_string('strftimedate', 'langconfig'));

        // Run adhock tasks.
        $sink = $this->redirectMessages();
        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        ob_start();
        $this->runAdhocTasks();
        $messages = $sink->get_messages();
        $res = ob_get_clean();
        $sink->close();
        $messages = message_get_messages($student1->id);
        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');

        // Re-init shoppng cart.
        $cartstore = cartstore::instance($student1->id);
        $data = $cartstore->get_localized_data();

        // Get infor about installments.
        $open = $cartstore->get_open_installments();
        $this->assertCount(3, $open);
        $due = $cartstore->get_due_installments();
        $this->assertCount(1, $due);
        // Validatee reminder message as per https://github.com/Wunderbyte-GmbH/moodle-mod_booking/issues/505.
        $context = context_system::instance();
        $PAGE->set_context($context);
        $PAGE->set_url(new \moodle_url('/local/shopping_cart/checkout.php'));
        $html = local_shopping_cart_render_navbar_output($OUTPUT);
        $notess = \core\notification::fetch();
        $this->assertStringContainsString('Don\'t forget: Test Option 2, 27.5 EUR.', $notess[0]->get_message());
        // Required: add installment to the cart.
        foreach ($due as $dueitem) {
            shopping_cart::add_item_to_cart(
                $dueitem['componentname'],
                $dueitem['area'],
                $dueitem['itemid'],
                $student1->id
            );
        }
        // Prepere checkout, confirm payment.
        $data = $cartstore->get_localized_data();
        $cartstore->get_expanded_checkout_data($data);
        $pay = shopping_cart::confirm_payment($student1->id, LOCAL_SHOPPING_CART_PAYMENT_METHOD_CREDITS, $data);

        // Reset cart and move +2 day forward - we should pay 2nd installment.
        cartstore::reset();
        time_mock::set_mock_time(strtotime('+2 days', $time));
        $time = time_mock::get_mock_time();
        $debugdate = userdate($time, get_string('strftimedate', 'langconfig'));

        // Run adhock tasks.
        $sink = $this->redirectMessages();
        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /* $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc'); */
        ob_start();
        $this->runAdhocTasks();
        $messages = $sink->get_messages();
        $res = ob_get_clean();
        $sink->close();
        $messages = message_get_messages($student1->id);

        // Re-init shoppng cart.
        $cartstore = cartstore::instance($student1->id);
        $data = $cartstore->get_localized_data();

        // Get infor about installments.
        $open = $cartstore->get_open_installments();
        $this->assertCount(2, $open);
        $due = $cartstore->get_due_installments();
        $this->assertCount(1, $due);
        // Validatee reminder message as per https://github.com/Wunderbyte-GmbH/moodle-mod_booking/issues/505.
        $PAGE->set_context($context);
        $PAGE->set_url(new \moodle_url('/local/shopping_cart/checkout.php'));
        $html = local_shopping_cart_render_navbar_output($OUTPUT);
        $notess = \core\notification::fetch();
        $this->assertStringContainsString('Don\'t forget: Test Option 2, 27.5 EUR.', $notess[0]->get_message());
        // Required: add installment to the cart.
        foreach ($due as $dueitem) {
            shopping_cart::add_item_to_cart(
                $dueitem['componentname'],
                $dueitem['area'],
                $dueitem['itemid'],
                $student1->id
            );
        }
        // Prepere checkout, confirm payment.
        $data = $cartstore->get_localized_data();
        $cartstore->get_expanded_checkout_data($data);
        $pay = shopping_cart::confirm_payment($student1->id, LOCAL_SHOPPING_CART_PAYMENT_METHOD_CREDITS, $data);
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
