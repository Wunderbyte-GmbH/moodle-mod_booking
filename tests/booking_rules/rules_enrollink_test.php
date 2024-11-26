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
 * Tests for booking enrollink rules.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Andraž Prinčič
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use stdClass;
use mod_booking\teachers_handler;
use mod_booking\booking_rules\booking_rules;
use mod_booking\booking_rules\rules_info;
use mod_booking\bo_availability\bo_info;
use mod_booking\bo_availability\conditions\customform;
use mod_booking\local\mobile\customformstore;
use local_shopping_cart\shopping_cart;
use local_shopping_cart\local\cartstore;
use mod_booking\enrollink;

/**
 * Tests for booking enrollink rules.
 *
 * @package mod_booking
 * @category test
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rules_enrollink_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        // Mandatory clean-up.
        singleton_service::get_instance()->users = [];
        singleton_service::get_instance()->bookinganswers = [];
        singleton_service::get_instance()->userpricecategory = [];
    }

    /**
     * Test rule on answer and option being cancelled.
     *
     * @covers \mod_booking\event\enrollink_triggered
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event->execute
     * @covers \mod_booking\booking_rules\conditions\select_student_in_bo->execute
     * @covers \mod_booking\booking_rules\actions\send_mail->execute
     *
     * @param array $bdata
     * @throws \coding_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_rule_on_enrollink_and_enroll(array $bdata): void {

        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

        // Setup test data.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $course2 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher1 = $this->getDataGenerator()->create_user();
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $teacher1->username;

        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($teacher1->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($student1->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course->id, 'student');

        /** @var local_shopping_cart_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_shopping_cart');
        $usercreditdata = [
            'userid' => $teacher1->id,
            'credit' => 300,
            'currency' => 'EUR',
        ];
        $ucredit = $plugingenerator->create_user_credit($usercreditdata);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create booking rule - "bookinganswer_cancelled".
        $actstr = '{"subject":"Enrollinksubj",';
        $actstr .= '"template":';
        $actstr .= '"<p>{enrollink}<\/p><p>{qrenrollink}<\/p><p>{#customform}<\/p><p>{customform}<\/p><p>{\/customform}<\/p>",';
        $actstr .= '"templateformat":"1"}';
        $ruledata1 = [
            'name' => 'enrollink',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"0"}',
            'actionname' => 'send_mail',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{"boevent":"\\\\mod_booking\\\\event\\\\enrollink_triggered","aftercompletion":"","condition":"0"}',
        ];
        $rule1 = $plugingenerator->create_rule($ruledata1);

        // Create price categories.
        $pricecategorydata1 = (object)[
            'ordernum' => 1,
            'name' => 'default',
            'identifier' => 'default',
            'defaultvalue' => 25,
            'pricecatsortorder' => 1,
        ];
        $pricecategory1 = $plugingenerator->create_pricecategory($pricecategorydata1);

        $pricecategorydata2 = (object)[
            'ordernum' => 2,
            'name' => 'discount1',
            'identifier' => 'discount1',
            'defaultvalue' => 20,
            'pricecatsortorder' => 2,
        ];
        $pricecategory2 = $plugingenerator->create_pricecategory($pricecategorydata2);

        // Create booking option 1.
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Option-2050';
        $record->description = 'Will start 2050';
        $record->optiondateid_1 = "0";
        $record->daystonotify_1 = "0";
        $record->coursestarttime_1 = strtotime('20 June 2050 15:00');
        $record->courseendtime_1 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $teacher1->username;
        // Set test objective setting(s).
        $record->chooseorcreatecourse = 1; // Connected existing course.
        $record->courseid = $course2->id;
        $record->useprice = 1;
        $record->enrolmentstatus = 2;
        $record->bo_cond_customform_restrict = 1;
        $record->bo_cond_customform_select_1_1 = 'enrolusersaction';
        $record->bo_cond_customform_label_1_1 = 'Number of user';
        $record->bo_cond_customform_value_1_1 = 1;

        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        singleton_service::destroy_booking_singleton_by_cmid($settings->cmid); // Require to avoid caching issues.
        $boinfo = new bo_info($settings);

        // Try to book option1 by the teacher1.
        $this->setUser($teacher1);
        singleton_service::destroy_user($teacher1->id);
        list($id, $isavailable, $description) = $boinfo->is_available($settings->id, $teacher1->id);
        $this->assertEquals(MOD_BOOKING_BO_COND_JSON_CUSTOMFORM, $id);

        $price = price::get_price('option', $settings->id);
        $this->assertEquals($pricecategorydata1->defaultvalue, $price["price"]);

        // Submit customform data 1st.
        $customformdata = (object) [
            'id' => $settings->id,
            'userid' => $teacher1->id,
            'customform_enrolusersaction_1' => 3,
        ];
        $customformstore = new customformstore($teacher1->id, $settings->id);
        $customformstore->set_customform_data($customformdata);

        // Verify 3x price.
        $price = price::get_price('option', $settings->id);
        $this->assertEquals(75, $price["price"]);

        // Admin confirms the users booking.
        $this->setAdminUser();
        // Purchase item in behalf of teacher1.
        shopping_cart::delete_all_items_from_cart($teacher1->id);
        shopping_cart::buy_for_user($teacher1->id);

        // Get cached data or setup defaults.
        $cartstore = cartstore::instance($teacher1->id);

        // Put in a test item with given ID (or default if ID > 4).
        $item = shopping_cart::add_item_to_cart('mod_booking', 'option', $settings->id, -1);

        shopping_cart::save_used_credit_state($teacher1->id, 1);

        // Confirm credit payment.
        $res = shopping_cart::confirm_payment($teacher1->id, LOCAL_SHOPPING_CART_PAYMENT_METHOD_CREDITS);
        // Validate payment.
        $this->assertIsArray($res);
        $this->assertEmpty($res['error']);
        $this->assertEquals(225, $res['credit']);

        // In this test, we book the user directly (we don't test the payment process).
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $option->user_submit_response($teacher1, 0, 0, 0, MOD_BOOKING_VERIFIED);

        // User 1 should be booked now.
        list($id, $isavailable, $description) = $boinfo->is_available($settings->id, $teacher1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Get messages.
        $messagesobjs = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');

        ob_start();
        $messagesink = $this->redirectMessages();
        $this->runAdhocTasks();
        $messages = $messagesink->get_messages();
        $res = ob_get_clean();
        $messagesink->close();

        // Validate scheduled adhoc tasks. Validate messages - order might be free.
        foreach ($messagesobjs as $key => $messageobj) {
            $customdata = $messageobj->get_custom_data();
            if (strpos($customdata->customsubject, "Enrollinksubj") !== false) {
                // Validate message on the option's enrol link.
                $this->assertEquals($teacher1->id, $customdata->userid);
                $this->assertStringContainsString('enrollink_triggered', $customdata->rulejson);
                $this->assertStringContainsString($ruledata1['conditiondata'], $customdata->rulejson);
                $this->assertStringContainsString($ruledata1['actiondata'], $customdata->rulejson);
            }
        }

        // Validate console output.
        $expected = "send_mail_by_rule_adhoc task: mail successfully sent for option " . $option->id . " to user " . $teacher1->id;
        $this->assertStringContainsString($expected, $res);

        // Validate emails. Might be more than one dependitg to Moodle's version.
        foreach ($messages as $key => $message) {
            if (strpos($message->subject, "Enrollinksubj")) {
                // Validate email on enrol link.
                $this->assertStringContainsString("mod/booking/enrollink.php?erlid=", $message->fullmessage);
                $this->assertStringContainsString("Number of user: 3", $message->fullmessage);
            }
        }
        // phpcs:ignore
        //enrollink::trigger_enrolbot_actions();
        // Mandatory to solve potential cache issues.
        singleton_service::destroy_booking_option_singleton($option1->id);
        // Mandatory to deal with static variable in the booking_rules.
        rules_info::$rulestoexecute = [];
        booking_rules::$rules = [];
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
            'showviews' => ['mybooking,myoptions,showall,showactive,myinstitution'],
        ];
        return ['bdata' => [$bdata]];
    }
}
