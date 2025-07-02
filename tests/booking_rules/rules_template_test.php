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
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use local_shopping_cart\local\cartstore;
use local_shopping_cart\shopping_cart;
use mod_booking\booking_rules\rules\templates\ruletemplate_bookingoption_booked;
use mod_booking\booking_rules\rules\templates\ruletemplate_bookingoptioncompleted;
use mod_booking\booking_rules\rules\templates\ruletemplate_confirmwaitinglist;
use mod_booking\booking_rules\rules\templates\ruletemplate_courseupdate;
use mod_booking\booking_rules\rules\templates\ruletemplate_daysbeforestart;
use mod_booking\booking_rules\rules\templates\ruletemplate_paymentconfirmation;
use mod_booking\booking_rules\rules\templates\ruletemplate_trainerpoll;
use mod_booking\booking_rules\rules\templates\ruletemplate_userpoll;
use mod_booking\local\templaterule;
use stdClass;
use mod_booking\teachers_handler;
use mod_booking\booking_rules\booking_rules;
use mod_booking\booking_rules\rules_info;
use mod_booking\bo_availability\bo_info;
use mod_booking\bo_availability\conditions\customform;
use mod_booking\local\mobile\customformstore;
use mod_booking_generator;
use tool_mocktesttime\time_mock;

/**
 * Tests for booking rules.
 *
 * @package mod_booking
 * @category test
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rules_template_test extends advanced_testcase {
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
        global $DB;

        parent::tearDown();
        // Mandatory to solve potential cache issues.
        singleton_service::destroy_instance();
        // Mandatory to deal with static variable in the booking_rules.
        rules_info::destroy_singletons();
        booking_rules::$rules = [];
        cartstore::reset();
    }

    /**
     * Test rulestemplate for "payment_confirmation".
     *
     * @covers \mod_booking\bo_availability\conditions\alreadybooked::is_available
     * @covers \mod_booking\bo_availability\conditions\onwaitinglist::is_available
     * @covers \mod_booking\event\bookingoption_freetobookagain
     * @covers \mod_booking\event\bookingoptionwaitinglist_booked
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event
     * @covers \mod_booking\booking_rules\actions\send_mail
     * @covers \mod_booking\booking_rules\conditions\select_teacher_in_bo
     * @covers \mod_booking\booking_rules\conditions\select_student_in_bo
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_rules_template_payment_conformation(array $bdata): void {
        global $DB, $CFG;

        $bdata['cancancelbook'] = 1;

        // Create course.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $student3 = $this->getDataGenerator()->create_user();
        $teacher1 = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $teacher1->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student3->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher1->id, $course1->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create booking rule 1 - "payment_conformation".
        $boevent1 = '"boevent":"\\\\local_shopping_cart\\\\event\\\\payment_confirmed"';
        $template = ruletemplate_paymentconfirmation::return_template();
        $ruledatanew = json_decode($template->rulejson, true);
        $ruledatanew["conditiondata"] = json_encode($ruledatanew["conditiondata"]);
        $ruledatanew["actiondata"] = json_encode($ruledatanew["actiondata"]);
        $ruledatanew["ruledata"] = json_encode($ruledatanew["ruledata"]);
        $ruledatanew["contextid"] = $template->contextid;
        $rule1 = $plugingenerator->create_rule($ruledatanew);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course1->id;
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

        // Get all scheduled task messages.
        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');

        $this->assertCount(1, $tasks);
        // Validate task messages. Might be free order.
        foreach ($tasks as $key => $task) {
            $customdata = $task->get_custom_data();
                // Validate 2 task messages on the bookingoption_freetobookagain event.
                $this->assertEquals("Payment for {Title} confirmed", $customdata->customsubject);
                $this->assertEquals(
                    "Thank you for your booking!<br>Your booking {Title} with the price: {price} has been successfully made." .
                    "<br>Here is the confirmation link:<br>{bookingconfirmationlink}<br>Here is the course link:<br>{courselink}" .
                    "<br>Best regards",
                    $customdata->custommessage
                );
                $this->assertStringContainsString($boevent1, $customdata->rulejson);
                $this->assertStringContainsString($ruledatanew['conditiondata'], $customdata->rulejson);
                $this->assertStringContainsString($ruledatanew['actiondata'], $customdata->rulejson);
        }
        // Mandatory to solve potential cache issues.
        singleton_service::destroy_instance();
        // Mandatory to deal with static variable in the booking_rules.
        rules_info::$rulestoexecute = [];
        booking_rules::$rules = [];
    }
    /**
     * Test rulestemplate on before and after coursestart events.
     *
     * @covers \mod_booking\booking_option::update
     * @covers \mod_booking\option\field_base::check_for_changes
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event::execute
     * @covers \mod_booking\booking_rules\actions\send_mail::execute
     * @covers \mod_booking\booking_rules\conditions\select_users::execute
     * @covers \mod_booking\placeholders\placeholders\changes::return_value
     *
     * @param array $bdata
     * @throws \coding_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_rules_template_on_beforeafter_coursestart(array $bdata): void {

        singleton_service::destroy_instance();

        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

        // Setup test data.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $user2->username;

        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($user1->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($user2->id, $course->id, 'student');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create booking rule - "ndays before".
        $template = ruletemplate_daysbeforestart::return_template();
        $ruledatanew = json_decode($template->rulejson, true);
        $ruledatanew["conditiondata"] = json_encode($ruledatanew["conditiondata"]);
        $ruledatanew["actiondata"] = json_encode($ruledatanew["actiondata"]);
        $ruledatanew["ruledata"] = json_encode($ruledatanew["ruledata"]);
        $ruledatanew["contextid"] = $template->contextid;

        $rule1 = $plugingenerator->create_rule($ruledatanew);

        // Create booking rule - "ndays after".
        $template = ruletemplate_userpoll::return_template();
        $ruledatanew = json_decode($template->rulejson, true);
        $ruledatanew["conditiondata"] = json_encode($ruledatanew["conditiondata"]);
        $ruledatanew["actiondata"] = json_encode($ruledatanew["actiondata"]);
        $ruledatanew["ruledata"] = json_encode($ruledatanew["ruledata"]);
        $ruledatanew["contextid"] = $template->contextid;

        $rule2 = $plugingenerator->create_rule($ruledatanew);

        // Create booking rule - "ndays after teacher".
        $template = ruletemplate_trainerpoll::return_template();
        $ruledatanew = json_decode($template->rulejson, true);
        $ruledatanew["conditiondata"] = json_encode($ruledatanew["conditiondata"]);
        $ruledatanew["actiondata"] = json_encode($ruledatanew["actiondata"]);
        $ruledatanew["ruledata"] = json_encode($ruledatanew["ruledata"]);
        $ruledatanew["contextid"] = $template->contextid;

        $rule3 = $plugingenerator->create_rule($ruledatanew);

        // Create booking option 1.
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Option-tomorrow';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course->id;
        $record->description = 'Will start tomorrow';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('now + 30 days');
        $record->courseendtime_0 = strtotime('now + 31 days');
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $boinfo = new bo_info($settings);

        $result = booking_bookit::bookit('option', $settings->id, $user1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $user1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMBOOKIT, $id);

        $result = booking_bookit::bookit('option', $settings->id, $user1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $user1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        $messages = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        $this->assertCount(2, $messages);

        // Validate scheduled adhoc tasks. Validate messages - order might be free.
        foreach ($messages as $key => $message) {
            $customdata = $message->get_custom_data();
            if (strpos($customdata->customsubject, "ndaybefore") !== false) {
                $this->assertEquals(strtotime('17 June 2050 15:00'), $message->get_next_run_time());
                $this->assertEquals("2", $customdata->userid);
                $this->assertStringContainsString($ruledatanew['ruledata'], $customdata->rulejson);
                $this->assertStringContainsString($ruledatanew['conditiondata'], $customdata->rulejson);
                $this->assertStringContainsString($ruledatanew['actiondata'], $customdata->rulejson);
            } else if (strpos($customdata->customsubject, "ndayafter") !== false) {
                $this->assertEquals(strtotime('20 July 2050 14:00'), $message->get_next_run_time());
                $this->assertEquals("2", $customdata->userid);
                $this->assertStringContainsString($ruledatanew['ruledata'], $customdata->rulejson);
                $this->assertStringContainsString($ruledatanew['conditiondata'], $customdata->rulejson);
                $this->assertStringContainsString($ruledatanew['actiondata'], $customdata->rulejson);
            } else {
                continue;
            }
        }

        // Mandatory to solve potential cache issues.
        singleton_service::destroy_instance();
        // Mandatory to deal with static variable in the booking_rules.
        rules_info::$rulestoexecute = [];
        booking_rules::$rules = [];
    }

    /**
     * Test rulestemplate on booking_option_update event.
     *
     * @covers \mod_booking\booking_option::update
     * @covers \mod_booking\option\field_base::check_for_changes
     * @covers \mod_booking\event\bookingoption_updated
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event::execute
     * @covers \mod_booking\booking_rules\actions\send_mail::execute
     * @covers \mod_booking\booking_rules\conditions\select_teacher_in_bo::execute
     * @covers \mod_booking\placeholders\placeholders\changes::return_value
     *
     * @param array $bdata
     * @throws \coding_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_rules_template_on_booking_option_update(array $bdata): void {

        singleton_service::destroy_instance();

        // Setup test data.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $users = [
            ['username' => 'teacher1', 'firstname' => 'Teacher', 'lastname' => '1', 'email' => 'teacher1@example.com'],
            ['username' => 'student1', 'firstname' => 'Student', 'lastname' => '1', 'email' => 'student1@sample.com'],
        ];
        $user1 = $this->getDataGenerator()->create_user($users[0]);
        $user2 = $this->getDataGenerator()->create_user($users[1]);

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $user2->username;

        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($user1->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($user2->id, $course->id, 'student');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Test option';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course->id;
        $record->description = 'Test description';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050');
        $record->courseendtime_0 = strtotime('20 July 2050');
        $option = $plugingenerator->create_option($record);

        // Create booking rule.
        $template = ruletemplate_courseupdate::return_template();
        $ruledatanew = json_decode($template->rulejson, true);
        $ruledatanew["conditiondata"] = json_encode($ruledatanew["conditiondata"]);
        $ruledatanew["actiondata"] = json_encode($ruledatanew["actiondata"]);
        $ruledatanew["ruledata"] = json_encode($ruledatanew["ruledata"]);
        $ruledatanew["contextid"] = $template->contextid;

        $rule1 = $plugingenerator->create_rule($ruledatanew);

        // Trigger and capture emails.
        unset_config('noemailever');
        ob_start();

        $messagesink = $this->redirectMessages();

        // Update booking.
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $record->id = $option->id;
        $record->cmid = $settings->cmid;
        $record->coursestarttime_0 = strtotime('10 April 2055');
        $record->courseendtime_0 = strtotime('10 May 2055');
        $record->description = 'Description updated';
        $record->teachersforoption = [$user1->id];
        booking_option::update($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        $this->runAdhocTasks();

        $messages = $messagesink->get_messages();
        $res = ob_get_clean();
        $messagesink->close();

        // Validate emails. Might be more than one dependitg to Moodle's version.
        foreach ($messages as $key => $message) {
            if (strpos($message->subject, "OptionChanged")) {
                // Validate email on option change.
                $this->assertEquals("OptionChanged", $message->subject);
                $this->assertStringContainsString("Dates has changed", $message->fullmessage);
                $this->assertStringContainsString("20 June 2050", $message->fullmessage);
                $this->assertStringContainsString("20 July 2050", $message->fullmessage);
                $this->assertStringContainsString("10 April 2055", $message->fullmessage);
                $this->assertStringContainsString("10 May 2055", $message->fullmessage);
                $this->assertStringContainsString("Teachers has changed", $message->fullmessage);
                $this->assertStringContainsString("Teacher 1 (ID:", $message->fullmessage);
                $this->assertStringContainsString("Description has changed", $message->fullmessage);
                $this->assertStringContainsString("Test description", $message->fullmessage);
                $this->assertStringContainsString("Description updated", $message->fullmessage);
            }
        }

        // Mandatory to solve potential cache issues.
        singleton_service::destroy_instance();
        // Mandatory to deal with static variable in the booking_rules.
        rules_info::$rulestoexecute = [];
        booking_rules::$rules = [];
    }
    /**
     * Test rulestemplate for "booking on waitinglist booked".
     *
     * @covers \mod_booking\bo_availability\conditions\alreadybooked::is_available
     * @covers \mod_booking\bo_availability\conditions\onwaitinglist::is_available
     * @covers \mod_booking\event\bookingoption_freetobookagain
     * @covers \mod_booking\event\bookingoptionwaitinglist_booked
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event
     * @covers \mod_booking\booking_rules\actions\send_mail
     * @covers \mod_booking\booking_rules\conditions\select_teacher_in_bo
     * @covers \mod_booking\booking_rules\conditions\select_student_in_bo
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_ruletemplate_on_waitinglist_booked(array $bdata): void {
        global $DB, $CFG;

        singleton_service::destroy_instance();

        $bdata['cancancelbook'] = 1;

        // Create course.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $student3 = $this->getDataGenerator()->create_user();
        $teacher1 = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $teacher1->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student3->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher1->id, $course1->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create booking rule 1 - "bookinganswer_waitingforconfirmation".
        $boevent1 = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoptionwaitinglist_booked"';
        $template = ruletemplate_confirmwaitinglist::return_template();
        $ruledatanew = json_decode($template->rulejson, true);
        $ruledatanew["conditiondata"] = json_encode($ruledatanew["conditiondata"]);
        $ruledatanew["actiondata"] = json_encode($ruledatanew["actiondata"]);
        $ruledatanew["ruledata"] = json_encode($ruledatanew["ruledata"]);
        $ruledatanew["contextid"] = $template->contextid;

        $rule1 = $plugingenerator->create_rule($ruledatanew);

        // Create booking rule 2 - "bookingoption_freetobookagain".
        $boevent2 = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_freetobookagain"';
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"subject":"freeplacesubj","template":"freeplacemsg","templateformat":"1"}';
        $ruledata2 = [
            'name' => 'override',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent2 . ',"aftercompletion":"","condition":"0"}',
        ];
        $rule2 = $plugingenerator->create_rule($ruledata2);

        // Create booking option 1.
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'football';
        $record->chooseorcreatecourse = 1; // Connected existing course.
        $record->courseid = $course1->id;
        $record->maxanswers = 1;
        $record->maxoverbooking = 2; // Enable waitinglist.
        $record->waitforconfirmation = 1; // Do not force waitinglist.
        $record->description = 'Will start in 2050';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $teacher1->username;
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $boinfo = new bo_info($settings);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        // Create a booking option answer - book student2.
        $this->setUser($student2);

        $result = booking_bookit::bookit('option', $settings->id, $student2->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Confirm booking as admin.
        $this->setAdminUser();
        $option->user_submit_response($student2, 0, 0, 0, MOD_BOOKING_VERIFIED);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Book the student1 via waitinglist.
        $this->setUser($student1);

        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Now take student 2 from the list, for a place to free up.
        $this->setUser($student2);
        $option->user_delete_response($student2->id);
        singleton_service::destroy_booking_option_singleton($option1->id);
        singleton_service::destroy_booking_answers($option1->id);

        // Execute tasks, get messages and validate it.
        $this->setAdminUser();

        // Get messages.
        $messages = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');

        $this->assertCount(3, $messages);
        // Validate messages. Might be free order.
        $counter = 1;
        foreach ($messages as $key => $message) {
            $customdata = $message->get_custom_data();
            if (strpos($customdata->customsubject, "freeplacesubj") !== false) {
                // Validate message on the bookingoption_freetobookagain event.
                $this->assertEquals("freeplacesubj", $customdata->customsubject);
                $this->assertEquals("freeplacemsg", $customdata->custommessage);
                $this->assertEquals($student1->id, $customdata->userid);
                $this->assertStringContainsString($boevent2, $customdata->rulejson);
                $this->assertStringContainsString($ruledata2['conditiondata'], $customdata->rulejson);
                $this->assertStringContainsString($ruledata2['actiondata'], $customdata->rulejson);
                $this->assertEquals($student1->id, $message->get_userid());
            } else {
                // Validate message on the bookingoptionwaitinglist_booked event.
                $this->assertEquals("You are on the waiting list", $customdata->customsubject);
                $this->assertEquals(
                    "Dear {firstname} {lastname},<br>You are on the waiting list<br>{bookingdetails}<br>All the best!",
                    $customdata->custommessage
                );
                $this->assertEquals($counter == 1 ? $student2->id : $student1->id, $customdata->userid);
                $this->assertStringContainsString($boevent1, $customdata->rulejson);
                $this->assertStringContainsString($ruledatanew['conditiondata'], $customdata->rulejson);
                $this->assertStringContainsString($ruledatanew['actiondata'], $customdata->rulejson);
                $this->assertEquals($counter == 1 ? $student2->id : $student1->id, $message->get_userid());
                $rulejson = json_decode($customdata->rulejson);
                $this->assertContains($rulejson->datafromevent->relateduserid, [$student1->id, $student2->id]);
                $counter++;
            }
        }
    }

    /**
     * Test rulestemplate on option being completed for user.
     *
     * @covers \mod_booking\event\bookingoption_booked
     * @covers \mod_booking\event\bookingoption_completed
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event::execute
     * @covers \mod_booking\booking_rules\conditions\select_user_from_event::execute
     * @covers \mod_booking\booking_rules\conditions\match_userprofilefield::execute
     * @covers \mod_booking\booking_rules\actions\send_mail::execute
     *
     * @param array $bdata
     * @throws \coding_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_rules_template_on_option_completion(array $bdata): void {

        singleton_service::destroy_instance();

        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

        // Allow optioncacellation.
        $bdata['cancancelbook'] = 1;

        // Add a user profile field of text type.
        $fieldid1 = $this->getDataGenerator()->create_custom_profile_field([
            'shortname' => 'sport', 'name' => 'Sport', 'datatype' => 'text',
        ])->id;

        // Setup test data.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $user3 = $this->getDataGenerator()->create_user(['profile_field_sport' => 'football']);

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $user1->username;

        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($user1->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($user2->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($user3->id, $course->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create booking rule 1 - "bookingoption_booked".
        $template = ruletemplate_bookingoption_booked::return_template();
        $ruledatanew = json_decode($template->rulejson, true);
        $ruledatanew["conditiondata"] = json_encode($ruledatanew["conditiondata"]);
        $ruledatanew["actiondata"] = json_encode($ruledatanew["actiondata"]);
        $ruledatanew["ruledata"] = json_encode($ruledatanew["ruledata"]);
        $ruledatanew["contextid"] = $template->contextid;

        $rule1 = $plugingenerator->create_rule($ruledatanew);

        // Create booking rule 2 - "bookingoption_completed".
        $template = ruletemplate_bookingoptioncompleted::return_template();
        $ruledatanew2 = json_decode($template->rulejson, true);
        $ruledatanew2["conditiondata"] = json_encode($ruledatanew2["conditiondata"]);
        $ruledatanew2["actiondata"] = json_encode($ruledatanew2["actiondata"]);
        $ruledatanew2["ruledata"] = json_encode($ruledatanew2["ruledata"]);
        $ruledatanew2["contextid"] = $template->contextid;
        $rule2 = $plugingenerator->create_rule($ruledatanew2);

        // Create booking option 1.
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'football';
        $record->chooseorcreatecourse = 1; // Connected existing course.
        $record->courseid = $course->id;
        $record->description = 'Will start tomorrow';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $user1->username;
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        // Create a booking option answer - book user2.
        $result = $plugingenerator->create_answer(['optionid' => $option1->id, 'userid' => $user2->id]);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $result);
        singleton_service::destroy_booking_answers($option1->id);

        // Complete booking option for user2.
        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        $this->assertEquals(false, $option->user_completed_option());
        booking_activitycompletion([$user2->id], $option->booking->settings, $settings->cmid, $option1->id);
        $this->assertEquals(true, $option->user_completed_option());

        // Get messages.
        $messages = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');

        $this->assertCount(2, $messages);
        $keys = array_keys($messages);
        // Task 1 has to be "match_userprofilefield".
        $message = $messages[$keys[0]];
        // Validate adhoc tasks for rule 1.
        $customdata = $message->get_custom_data();
        $this->assertEquals($user2->id, $customdata->userid);
        $this->assertStringContainsString('bookingoption_booked', $customdata->rulejson);
        $this->assertStringContainsString($ruledatanew['conditiondata'], $customdata->rulejson);
        $this->assertStringContainsString($ruledatanew['actiondata'], $customdata->rulejson);
        $this->assertEquals($user2->id, $message->get_userid());
        // Task 2 has to be "select_user_from_event".
        $message = $messages[$keys[1]];
        // Validate adhoc tasks for rule 2.
        $customdata = $message->get_custom_data();
        $this->assertEquals($user2->id, $customdata->userid);
        $this->assertStringContainsString("bookingoption_completed", $customdata->rulejson);
        $this->assertStringContainsString($ruledatanew2['conditiondata'], $customdata->rulejson);
        $this->assertStringContainsString($ruledatanew2['actiondata'], $customdata->rulejson);
        $rulejson = json_decode($customdata->rulejson);
        $this->assertEquals($user2->id, $rulejson->datafromevent->relateduserid);
        $this->assertEquals($user2->id, $message->get_userid());
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
