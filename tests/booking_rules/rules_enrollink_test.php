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

use mod_booking\booking_advanced_testcase;
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
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/../booking_advanced_testcase.php');

/**
 * Tests for booking enrollink rules.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @runTestsInSeparateProcesses
 */
final class rules_enrollink_test extends booking_advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
    }

    /**
     * Test rule on answer and option being cancelled.
     *
     * @covers \mod_booking\event\enrollink_triggered
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event::execute
     * @covers \mod_booking\booking_rules\conditions\select_student_in_bo::execute
     * @covers \mod_booking\booking_rules\actions\send_mail::execute
     *
     * @param array $bdata
     * @throws \coding_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_rule_on_enrollink_and_enroll(array $bdata): void {
        global $USER;

        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

        // Setup test data.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $course2 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher1 = $this->getDataGenerator()->create_user();
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $student3 = $this->getDataGenerator()->create_user();
        $student4 = $this->getDataGenerator()->create_user();
        $student5 = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $teacher1->username;
        // Autoenroll must be enabled!
        $bdata['autoenrol'] = 1;

        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($teacher1->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($student1->id, $course->id, 'student');

        /** @var \local_shopping_cart_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_shopping_cart');
        $usercreditdata = [
            'userid' => $teacher1->id,
            'credit' => 300,
            'currency' => 'EUR',
        ];
        $ucredit = $plugingenerator->create_user_credit($usercreditdata);

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create booking rule - "bookinganswer_cancelled".
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"","subject":"Enrollinksubj",';
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
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $teacher1->username;
        $record->importing = 1;
        // Set test objective setting(s).
        $record->chooseorcreatecourse = 1; // Connected existing course.
        $record->courseid = $course2->id;
        $record->useprice = 1;
        $record->enrolmentstatus = 2;
        $record->bo_cond_customform_restrict = 1;
        $record->bo_cond_customform_select_1_1 = 'enrolusersaction';
        $record->bo_cond_customform_label_1_1 = 'Number of users';
        $record->bo_cond_customform_value_1_1 = 1;
        // Waiting lists NOT used.
        $record->bo_cond_customform_enroluserstowaitinglist1 = null;
        $record->waitforconfirmation = null;

        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        singleton_service::destroy_booking_singleton_by_cmid($settings->cmid); // Require to avoid caching issues.
        $boinfo = new bo_info($settings);

        // Try to book option1 by the teacher1.
        $this->setUser($teacher1);
        singleton_service::destroy_user($teacher1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $teacher1->id);
        $this->assertEquals(MOD_BOOKING_BO_COND_JSON_CUSTOMFORM, $id);

        $price = price::get_price('option', $settings->id);
        $this->assertEquals($pricecategorydata1->defaultvalue, $price["price"]);

        // Submit customform data 1st.
        $customformdata = (object) [
            'id' => $settings->id,
            'userid' => $teacher1->id,
            'customform_enrolusersaction_1' => 4,
            'customform_enroluserwhobookedcheckbox_enrolusersaction_1' => 1,
        ];
        $customformstore = new customformstore($teacher1->id, $settings->id);
        $customformstore->set_customform_data($customformdata);

        // Verify 4x price.
        $price = price::get_price('option', $settings->id);
        $this->assertEquals(100, $price["price"]);

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
        $this->assertEquals(200, $res['credit']);

        // In this test, we book the teacher into option directly.
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $option->user_submit_response($teacher1, 0, 0, 0, MOD_BOOKING_VERIFIED);
        // Teacher1 should be booked now.
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $teacher1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Book student1 as well (skip paynent process for him).
        $option->user_submit_response($student1, 0, 0, 0, MOD_BOOKING_VERIFIED);
        // Teacher1 should be booked now.
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Get messages.
        $messagesobjs = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');

        ob_start();
        $messagesink = $this->redirectMessages();
        $this->runAdhocTasks();
        $messages = $messagesink->get_messages();
        $res = ob_get_clean();
        $messagesink->clear(); // Recommended.
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
                $this->assertStringContainsString("Number of users: 4", $message->fullmessage);
            }
        }

        // Validate incorrect erlid.
        $enrollink = enrollink::get_instance('dummy');
        $info1 = $enrollink->enrolment_blocking();
        $this->assertEquals(MOD_BOOKING_AUTOENROL_STATUS_LINK_NOT_VALID, $enrollink->errorinfo);
        $this->assertEquals(MOD_BOOKING_AUTOENROL_STATUS_LINK_NOT_VALID, $info1);

        // Get erlid string.
        $erlid = str_replace('Number of users: 4', '', $message->fullmessage);
        $erlid = (explode('=', $erlid))[1];
        $enrollink = enrollink::get_instance($erlid);
        $this->assertEquals(3, $enrollink->free_places_left());

        // Validate redirect for not logged users.
        require_logout();
        $info1 = $enrollink->enrolment_blocking();
        $this->assertEmpty($info1);
        $info2 = $enrollink->enrol_user($USER->id);
        $this->assertEquals(MOD_BOOKING_AUTOENROL_STATUS_EXCEPTION, $info2);

        // Validate that student1 already booked and no consumtion.
        $this->setUser($student1);
        $info1 = $enrollink->enrolment_blocking();
        $this->assertEmpty($info1);
        $info2 = $enrollink->enrol_user($student1->id);
        $courselink = $enrollink->get_courselink_url();
        $this->assertEquals(MOD_BOOKING_AUTOENROL_STATUS_ALREADY_ENROLLED, $info2);
        $this->assertStringContainsString('/moodle/course/view.php?id=' . $course2->id, $courselink);
        $this->assertEquals(3, $enrollink->free_places_left());

        // Proceed with enrolling of student2.
        $this->setUser($student2);
        $info1 = $enrollink->enrolment_blocking();
        $this->assertEmpty($info1);
        $info2 = $enrollink->enrol_user($student2->id);
        $infostring = $enrollink->get_readable_info($info2);
        $courselink = $enrollink->get_courselink_url();
        // Validate enrollment status and remainaing free places.
        $this->assertEquals(MOD_BOOKING_AUTOENROL_STATUS_SUCCESS, $info2);
        $this->assertEquals('Successfully enrolled', $infostring);
        $this->assertStringContainsString('/moodle/course/view.php?id=' . $course2->id, $courselink);
        $this->assertEquals(2, $enrollink->free_places_left());

        // Proceed with enrolling of student3.
        $this->setUser($student3);
        $info1 = $enrollink->enrolment_blocking();
        $this->assertEmpty($info1);
        $info2 = $enrollink->enrol_user($student3->id);
        $courselink = $enrollink->get_courselink_url();
        // Validate enrollment status and remainaing free places.
        $this->assertEquals(MOD_BOOKING_AUTOENROL_STATUS_SUCCESS, $info2);
        $this->assertStringContainsString('/moodle/course/view.php?id=' . $course2->id, $courselink);
        $this->assertEquals(1, $enrollink->free_places_left());

        // An attempt to enroll guest.
        $this->setGuestUser();
        $info1 = $enrollink->enrolment_blocking();
        $this->assertEmpty($info1);
        $info2 = $enrollink->enrol_user($USER->id);
        $courselink = $enrollink->get_courselink_url();
        // Validate enrollment status and remainaing free places.
        $this->assertEquals(MOD_BOOKING_AUTOENROL_STATUS_LOGGED_IN_AS_GUEST, $info2);
        $this->assertStringContainsString('/moodle/course/view.php?id=' . $course2->id, $courselink);
        $this->assertEquals(1, $enrollink->free_places_left());

        // Proceed with enrolling of student4.
        $this->setUser($student4);
        $info1 = $enrollink->enrolment_blocking();
        $this->assertEmpty($info1);
        $info2 = $enrollink->enrol_user($USER->id);
        $courselink = $enrollink->get_courselink_url();
        // Validate enrollment status and remainaing free places.
        $this->assertEquals(MOD_BOOKING_AUTOENROL_STATUS_SUCCESS, $info2);
        $this->assertStringContainsString('/moodle/course/view.php?id=' . $course2->id, $courselink);
        $this->assertEquals(0, $enrollink->free_places_left());

        // Proceed with enrolling of student5 - no more seats.
        $this->setUser($student5);
        $info1 = $enrollink->enrolment_blocking();
        $this->assertEquals(MOD_BOOKING_AUTOENROL_STATUS_NO_MORE_SEATS, $info1);
        $info2 = $enrollink->enrol_user($student4->id);
        // Validate "nomoreseats" enrollment status and remainaing free places.
        $this->assertEquals(MOD_BOOKING_AUTOENROL_STATUS_NO_MORE_SEATS, $info2);
        $this->assertEquals(0, $enrollink->free_places_left());
    }

    /**
     * Test rule on answer and option being cancelled.
     *
     * @covers \mod_booking\event\enrollink_triggered
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event::execute
     * @covers \mod_booking\booking_rules\conditions\select_student_in_bo::execute
     * @covers \mod_booking\booking_rules\actions\send_mail::execute
     *
     * @param array $bdata
     * @throws \coding_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_rule_on_enrollink_and_enroll_via_waitinglists(array $bdata): void {
        global $USER, $DB;

        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

        // Setup test data.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $course2 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher1 = $this->getDataGenerator()->create_user();
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $student3 = $this->getDataGenerator()->create_user();
        $student4 = $this->getDataGenerator()->create_user();
        $student5 = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $teacher1->username;
        // Autoenroll must be enabled!
        $bdata['autoenrol'] = 1;

        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($teacher1->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($student1->id, $course->id, 'student');

        /** @var \local_shopping_cart_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_shopping_cart');
        $usercreditdata = [
            'userid' => $teacher1->id,
            'credit' => 300,
            'currency' => 'EUR',
        ];
        $ucredit = $plugingenerator->create_user_credit($usercreditdata);

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create booking rule - "bookinganswer_cancelled".
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"","subject":"Enrollinksubj",';
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
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $teacher1->username;
        $record->importing = 1;
        // Set test objective setting(s).
        $record->chooseorcreatecourse = 1; // Connected existing course.
        $record->courseid = $course2->id;
        $record->useprice = 1;
        $record->enrolmentstatus = 2;
        $record->bo_cond_customform_restrict = 1;
        $record->bo_cond_customform_select_1_1 = 'enrolusersaction';
        $record->bo_cond_customform_label_1_1 = 'Number of users';
        $record->bo_cond_customform_value_1_1 = 1;
        // Waiting lists ARE forced.
        $record->bo_cond_customform_enroluserstowaitinglist1 = 1;
        $record->waitforconfirmation = 1;

        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);
        enrollink::destroy_instances();

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        singleton_service::destroy_booking_singleton_by_cmid($settings->cmid); // Require to avoid caching issues.
        $boinfo = new bo_info($settings);

        // Try to book option1 by the teacher1.
        $this->setUser($teacher1);
        singleton_service::destroy_user($teacher1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $teacher1->id);
        $this->assertEquals(MOD_BOOKING_BO_COND_JSON_CUSTOMFORM, $id);

        $price = price::get_price('option', $settings->id);
        $this->assertEquals($pricecategorydata1->defaultvalue, $price["price"]);

        // Submit customform data 1st.
        $customformdata = (object) [
            'id' => $settings->id,
            'userid' => $teacher1->id,
            'customform_enrolusersaction_1' => 3,
            'customform_enroluserwhobookedcheckbox_enrolusersaction_1' => 1,
        ];
        $customformstore = new customformstore($teacher1->id, $settings->id);
        $customformstore->set_customform_data($customformdata);

        // Verify 3x price.
        $price = price::get_price('option', $settings->id);
        $this->assertEquals(75, $price["price"]);

        // Trigger the booking 3 times to simulate submitting the form, including the confirmation of waitinglist.
        $result = booking_bookit::bookit('option', $settings->id, $teacher1->id);
        $result = booking_bookit::bookit('option', $settings->id, $teacher1->id);
        $result = booking_bookit::bookit('option', $settings->id, $teacher1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $teacher1->id);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Admin confirms the users booking.
        $this->setAdminUser();

        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        // Confirmation from waitinglist.
        $option->user_submit_response(
            $teacher1,
            0,
            0,
            MOD_BOOKING_BO_SUBMIT_STATUS_CONFIRMATION,
            MOD_BOOKING_VERIFIED
        );
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $teacher1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_PRICEISSET, $id);
        // User buying the bundle.
        $option->user_submit_response(
            $teacher1,
            0,
            0,
            MOD_BOOKING_BO_SUBMIT_STATUS_DEFAULT,
            MOD_BOOKING_VERIFIED
        );
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $teacher1->id);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Student1 also buys a bundle (skip payment process for him).
        $option->user_submit_response($student1, 0, 0, MOD_BOOKING_BO_SUBMIT_STATUS_CONFIRMATION, MOD_BOOKING_VERIFIED);
        $option->user_submit_response($student1, 0, 0, 0, MOD_BOOKING_VERIFIED);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Get messages.
        $messagesobjs = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');

        ob_start();
        $messagesink = $this->redirectMessages();
        $this->runAdhocTasks();
        $messages = $messagesink->get_messages();
        $res = ob_get_clean();
        $messagesink->clear(); // Recommended.
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
                $this->assertStringContainsString("Number of users: 3", $message->fullmessage);
            }
        }

        // Validate incorrect erlid.
        $enrollink = enrollink::get_instance('dummy');
        $info1 = $enrollink->enrolment_blocking();
        $this->assertEquals(MOD_BOOKING_AUTOENROL_STATUS_LINK_NOT_VALID, $enrollink->errorinfo);
        $this->assertEquals(MOD_BOOKING_AUTOENROL_STATUS_LINK_NOT_VALID, $info1);

        // Get erlid string.
        $erlid = str_replace('Number of users: 3', '', $message->fullmessage);
        $erlid = (explode('=', $erlid))[1];
        $enrollink = enrollink::get_instance($erlid);
        $count = $enrollink->free_places_left();
        $this->assertEquals(2, $enrollink->free_places_left());

        // Validate redirect for not logged users.
        require_logout();
        $info1 = $enrollink->enrolment_blocking();
        $this->assertEmpty($info1);
        $info2 = $enrollink->enrol_user($USER->id);
        $this->assertEquals(MOD_BOOKING_AUTOENROL_STATUS_EXCEPTION, $info2);

        // Validate that student1 already booked and no consumtion.
        $this->setUser($student1);
        $info1 = $enrollink->enrolment_blocking();
        $this->assertEmpty($info1);
        $info2 = $enrollink->enrol_user($student1->id);
        $courselink = $enrollink->get_courselink_url();
        $this->assertEquals(MOD_BOOKING_AUTOENROL_STATUS_ALREADY_ENROLLED, $info2);
        $this->assertStringContainsString('/moodle/course/view.php?id=' . $course2->id, $courselink);
        $this->assertEquals(2, $enrollink->free_places_left());

        // Proceed with enrolling of student2.
        $this->setUser($student2);
        $info1 = $enrollink->enrolment_blocking();
        $this->assertEmpty($info1);
        $info2 = $enrollink->enrol_user($student2->id);
        $infostring = $enrollink->get_readable_info($info2);
        $courselink = $enrollink->get_courselink_url();
        // Validate enrollment status and remainaing free places.
        $this->assertEquals(MOD_BOOKING_AUTOENROL_STATUS_WAITINGLIST, $info2);
        $this->assertEquals(
            'Your registration has been completed and must still be confirmed by an authorised person.',
            $infostring
        );
        $this->assertStringContainsString('/moodle/course/view.php?id=' . $course2->id, $courselink);
        $this->assertEquals(2, $enrollink->free_places_left());
        // No reduction of seats because user needs confirmation first.

        // Proceed with enrolling of student3.
        $this->setUser($student3);
        $info1 = $enrollink->enrolment_blocking();
        $this->assertEmpty($info1);
        $info2 = $enrollink->enrol_user($student3->id);
        $courselink = $enrollink->get_courselink_url();
        // Validate enrollment status and remainaing free places.
        $this->assertEquals(MOD_BOOKING_AUTOENROL_STATUS_WAITINGLIST, $info2);
        $this->assertStringContainsString('/moodle/course/view.php?id=' . $course2->id, $courselink);
        $this->assertEquals(2, $enrollink->free_places_left());

        // An attempt to enroll guest.
        $this->setGuestUser();
        $info1 = $enrollink->enrolment_blocking();
        $this->assertEmpty($info1);
        $info2 = $enrollink->enrol_user($USER->id);
        $courselink = $enrollink->get_courselink_url();
        // Validate enrollment status and remainaing free places.
        $this->assertEquals(MOD_BOOKING_AUTOENROL_STATUS_LOGGED_IN_AS_GUEST, $info2);
        $this->assertStringContainsString('/moodle/course/view.php?id=' . $course2->id, $courselink);
        $this->assertEquals(2, $enrollink->free_places_left());

        // Admin confirms student2 from waiting list.
        $this->setAdminUser();
        $option->user_submit_response($student2, 0, 0, MOD_BOOKING_BO_SUBMIT_STATUS_AUTOENROL, MOD_BOOKING_VERIFIED);
        $this->assertEquals(1, $enrollink->free_places_left());
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Proceed with enrolling of student4.
        $this->setUser($student4);
        $info1 = $enrollink->enrolment_blocking();
        $this->assertEmpty($info1);
        $info2 = $enrollink->enrol_user($student4->id);
        $courselink = $enrollink->get_courselink_url();
        // Validate enrollment status and remainaing free places.
        $this->assertEquals(MOD_BOOKING_AUTOENROL_STATUS_WAITINGLIST, $info2);
        $this->assertStringContainsString('/moodle/course/view.php?id=' . $course2->id, $courselink);
        $this->assertEquals(1, $enrollink->free_places_left());

        // Admin confirms student3 from waiting list.
        $this->setAdminUser();
        $option->user_submit_response($student3, 0, 0, MOD_BOOKING_BO_SUBMIT_STATUS_AUTOENROL, MOD_BOOKING_VERIFIED);
        $this->assertEquals(0, $enrollink->free_places_left());
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Proceed with enrolling of student5 - no more seats.
        $this->setUser($student5);
        $info1 = $enrollink->enrolment_blocking();
        $this->assertEquals(MOD_BOOKING_AUTOENROL_STATUS_NO_MORE_SEATS, $info1);
        $info2 = $enrollink->enrol_user($student4->id);
        // Validate "nomoreseats" enrollment status and remainaing free places.
        $this->assertEquals(MOD_BOOKING_AUTOENROL_STATUS_NO_MORE_SEATS, $info2);
        $this->assertEquals(0, $enrollink->free_places_left());
        // User Student4 remains on the waitinglist.
    }

    /**
     * Test enrolmultipleusers mode ALSOBOOKMYSELF:
     * when no checkbox key is submitted, the booker is always also enrolled
     * and therefore consumes one enrollink place.
     *
     * @covers \mod_booking\enrollink::enroluseraction_allows_enrolment
     *
     * @param array $bdata
     * @throws \coding_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_enrolmultipleusers_mode_alsobookmyself(array $bdata): void {
        global $DB;

        set_config('enrolmultipleusersformmode', MOD_BOOKING_ENROLMULTIPLEUSERS_ALSOBOOKMYSELF, 'booking');

        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $course2 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher1 = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $teacher1->username;
        $bdata['autoenrol'] = 1;

        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($teacher1->id, $course->id, 'editingteacher');

        /** @var \local_shopping_cart_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_shopping_cart');
        $plugingenerator->create_user_credit(['userid' => $teacher1->id, 'credit' => 300, 'currency' => 'EUR']);

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $actstr = '{"sendical":0,"sendicalcreateorcancel":"","subject":"Enrollinksubj",';
        $actstr .= '"template":';
        $actstr .= '"<p>{enrollink}<\/p><p>{#customform}<\/p><p>{customform}<\/p><p>{\/customform}<\/p>",';
        $actstr .= '"templateformat":"1"}';
        $plugingenerator->create_rule([
            'name' => 'enrollink',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"0"}',
            'actionname' => 'send_mail',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{"boevent":"\\\\mod_booking\\\\event\\\\enrollink_triggered","aftercompletion":"","condition":"0"}',
        ]);

        $plugingenerator->create_pricecategory((object)[
            'ordernum' => 1, 'name' => 'default', 'identifier' => 'default',
            'defaultvalue' => 25, 'pricecatsortorder' => 1,
        ]);

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Option-alsobookmyself';
        $record->description = 'Mode ALSOBOOKMYSELF';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $teacher1->username;
        $record->importing = 1;
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course2->id;
        $record->useprice = 1;
        $record->enrolmentstatus = 2;
        $record->bo_cond_customform_restrict = 1;
        $record->bo_cond_customform_select_1_1 = 'enrolusersaction';
        $record->bo_cond_customform_label_1_1 = 'Number of users';
        $record->bo_cond_customform_value_1_1 = 1;
        $record->bo_cond_customform_enroluserstowaitinglist1 = null;
        $record->waitforconfirmation = null;

        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        singleton_service::destroy_booking_singleton_by_cmid($settings->cmid);
        $boinfo = new bo_info($settings);

        $this->setUser($teacher1);
        singleton_service::destroy_user($teacher1->id);

        // Mode 1: submit customform WITHOUT the checkbox key — booker is always also enrolled.
        $customformdata = (object) [
            'id' => $settings->id,
            'userid' => $teacher1->id,
            'customform_enrolusersaction_1' => 4,
            // No customform_enroluserwhobookedcheckbox_enrolusersaction_1 key.
        ];
        $customformstore = new customformstore($teacher1->id, $settings->id);
        $customformstore->set_customform_data($customformdata);

        $this->setAdminUser();
        shopping_cart::delete_all_items_from_cart($teacher1->id);
        shopping_cart::buy_for_user($teacher1->id);
        cartstore::instance($teacher1->id);
        shopping_cart::add_item_to_cart('mod_booking', 'option', $settings->id, -1);
        shopping_cart::save_used_credit_state($teacher1->id, 1);
        $res = shopping_cart::confirm_payment($teacher1->id, LOCAL_SHOPPING_CART_PAYMENT_METHOD_CREDITS);
        $this->assertEmpty($res['error']);

        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $option->user_submit_response($teacher1, 0, 0, 0, MOD_BOOKING_VERIFIED);
        [$id] = $boinfo->is_available($settings->id, $teacher1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        ob_start();
        $messagesink = $this->redirectMessages();
        $this->runAdhocTasks();
        $messages = $messagesink->get_messages();
        ob_get_clean();
        $messagesink->clear();
        $messagesink->close();

        $message = null;
        foreach ($messages as $msg) {
            if (strpos($msg->subject, 'Enrollinksubj') !== false) {
                $message = $msg;
                break;
            }
        }
        $this->assertNotNull($message, 'Enrollinksubj message not found');
        $this->assertStringContainsString('mod/booking/enrollink.php?erlid=', $message->fullmessage);

        // Without the checkbox key, enroluseraction_allows_enrolment returns true:
        // the booker consumes 1 of the 4 ordered places → 3 free places left.
        preg_match('/erlid=([a-f0-9]+)/i', $message->fullmessage, $matches);
        $erlid = $matches[1] ?? '';
        $this->assertNotEmpty($erlid, 'erlid not found in message');
        $enrollink = enrollink::get_instance($erlid);
        $this->assertEquals(3, $enrollink->free_places_left());

        // Assert a booking_enrollink_items row exists for teacher1, with consumed = 1.
        $items = $DB->get_records('booking_enrollink_items', ['erlid' => $erlid, 'userid' => $teacher1->id]);
        $this->assertCount(1, $items, 'ALSOBOOKMYSELF: one item should exist for the booker');
        $item = reset($items);
        $this->assertEquals(1, $item->consumed, 'ALSOBOOKMYSELF: item must be consumed');
        $this->assertEquals($teacher1->id, $item->userid, 'ALSOBOOKMYSELF: item userid must match booker');
    }

    /**
     * Test enrolmultipleusers mode DONOTBOOKMYSELF:
     * when the checkbox key is submitted with value 0, the booker is never enrolled
     * and therefore does NOT consume an enrollink place.
     *
     * @covers \mod_booking\enrollink::enroluseraction_allows_enrolment
     *
     * @param array $bdata
     * @throws \coding_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_enrolmultipleusers_mode_donotbookmyself(array $bdata): void {
        global $DB;

        set_config('enrolmultipleusersformmode', MOD_BOOKING_ENROLMULTIPLEUSERS_DONOTBOOKMYSELF, 'booking');

        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $course2 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher1 = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $teacher1->username;
        $bdata['autoenrol'] = 1;

        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($teacher1->id, $course->id, 'editingteacher');

        /** @var \local_shopping_cart_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_shopping_cart');
        $plugingenerator->create_user_credit(['userid' => $teacher1->id, 'credit' => 300, 'currency' => 'EUR']);

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $actstr = '{"sendical":0,"sendicalcreateorcancel":"","subject":"Enrollinksubj",';
        $actstr .= '"template":';
        $actstr .= '"<p>{enrollink}<\/p><p>{#customform}<\/p><p>{customform}<\/p><p>{\/customform}<\/p>",';
        $actstr .= '"templateformat":"1"}';
        $plugingenerator->create_rule([
            'name' => 'enrollink',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"0"}',
            'actionname' => 'send_mail',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{"boevent":"\\\\mod_booking\\\\event\\\\enrollink_triggered","aftercompletion":"","condition":"0"}',
        ]);

        $plugingenerator->create_pricecategory((object)[
            'ordernum' => 1, 'name' => 'default', 'identifier' => 'default',
            'defaultvalue' => 25, 'pricecatsortorder' => 1,
        ]);

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Option-donotbookmyself';
        $record->description = 'Mode DONOTBOOKMYSELF';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $teacher1->username;
        $record->importing = 1;
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course2->id;
        $record->useprice = 1;
        $record->enrolmentstatus = 2;
        $record->bo_cond_customform_restrict = 1;
        $record->bo_cond_customform_select_1_1 = 'enrolusersaction';
        $record->bo_cond_customform_label_1_1 = 'Number of users';
        $record->bo_cond_customform_value_1_1 = 1;
        $record->bo_cond_customform_enroluserstowaitinglist1 = null;
        $record->waitforconfirmation = null;

        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        singleton_service::destroy_booking_singleton_by_cmid($settings->cmid);
        $boinfo = new bo_info($settings);

        $this->setUser($teacher1);
        singleton_service::destroy_user($teacher1->id);

        // Mode 2: submit customform WITH checkbox value 0 — booker is never enrolled.
        $customformdata = (object) [
            'id' => $settings->id,
            'userid' => $teacher1->id,
            'customform_enrolusersaction_1' => 4,
            'customform_enroluserwhobookedcheckbox_enrolusersaction_1' => 0,
        ];
        $customformstore = new customformstore($teacher1->id, $settings->id);
        $customformstore->set_customform_data($customformdata);

        $this->setAdminUser();
        shopping_cart::delete_all_items_from_cart($teacher1->id);
        shopping_cart::buy_for_user($teacher1->id);
        cartstore::instance($teacher1->id);
        shopping_cart::add_item_to_cart('mod_booking', 'option', $settings->id, -1);
        shopping_cart::save_used_credit_state($teacher1->id, 1);
        $res = shopping_cart::confirm_payment($teacher1->id, LOCAL_SHOPPING_CART_PAYMENT_METHOD_CREDITS);
        $this->assertEmpty($res['error']);

        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $option->user_submit_response($teacher1, 0, 0, 0, MOD_BOOKING_VERIFIED);
        [$id] = $boinfo->is_available($settings->id, $teacher1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        ob_start();
        $messagesink = $this->redirectMessages();
        $this->runAdhocTasks();
        $messages = $messagesink->get_messages();
        ob_get_clean();
        $messagesink->clear();
        $messagesink->close();

        $message = null;
        foreach ($messages as $msg) {
            if (strpos($msg->subject, 'Enrollinksubj') !== false) {
                $message = $msg;
                break;
            }
        }
        $this->assertNotNull($message, 'Enrollinksubj message not found');
        $this->assertStringContainsString('mod/booking/enrollink.php?erlid=', $message->fullmessage);

        // With checkbox key = 0, enroluseraction_allows_enrolment returns false:
        // the booker does NOT consume any place → all 4 ordered places remain free.
        preg_match('/erlid=([a-f0-9]+)/i', $message->fullmessage, $matches);
        $erlid = $matches[1] ?? '';
        $this->assertNotEmpty($erlid, 'erlid not found in message');
        $enrollink = enrollink::get_instance($erlid);
        $this->assertEquals(4, $enrollink->free_places_left());

        // Assert no booking_enrollink_items row for teacher1 (booker not self-enrolled).
        $items = $DB->get_records('booking_enrollink_items', ['erlid' => $erlid, 'userid' => $teacher1->id]);
        $this->assertCount(0, $items, 'DONOTBOOKMYSELF: no item should exist for the booker');
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
