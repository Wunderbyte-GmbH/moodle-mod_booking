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
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Andraž Prinčič
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use local_shopping_cart\local\cartstore;
use local_shopping_cart\shopping_cart;
use stdClass;
use mod_booking\teachers_handler;
use mod_booking\booking_rules\booking_rules;
use mod_booking\booking_rules\rules_info;
use mod_booking\bo_availability\bo_info;
use mod_booking\bo_availability\conditions\customform;
use mod_booking\local\mobile\customformstore;
use tool_mocktesttime\time_mock;
use mod_booking_generator;

/**
 * Tests for booking rules.
 *
 * @package mod_booking
 * @category test
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rules_test extends advanced_testcase {
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
     * Test rules for "test if message is triggered on payment confirmation".
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
    public function test_payment_confirmation(array $bdata): void {
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

        // Create booking rule 1 - "payment_confirmation".
        $boevent1 = '"boevent":"\\\\local_shopping_cart\\\\event\\\\payment_confirmed"';
        $ruledata1 = [
            'name' => 'Payment_confirmed',
            'conditionname' => 'select_user_from_event',
            'contextid' => 1,
            'conditiondata' => '{"userfromeventtype":"userid"}',
            'actionname' => 'send_mail',
            'actiondata' => '{"sendical":0,"sendicalcreateorcancel":"","subject":"Test","template":"{price}"}',
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent1 . ',"aftercompletion":0,"cancelrules":[],"condition":"0"}',
        ];
        $rule1 = $plugingenerator->create_rule($ruledata1);

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
                $this->assertEquals("Test", $customdata->customsubject);
                $this->assertEquals("{price}", $customdata->custommessage);
                $this->assertStringContainsString($boevent1, $customdata->rulejson);
                $this->assertStringContainsString($ruledata1['conditiondata'], $customdata->rulejson);
                $this->assertStringContainsString($ruledata1['actiondata'], $customdata->rulejson);
        }
    }

    /**
     * Test rule on option's teacher added.
     *
     * @covers \mod_booking\event\teacher_added
     * @covers \mod_booking\teachers_handler::subscribe_teacher_to_booking_option
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event::execute
     * @covers \mod_booking\booking_rules\actions\send_mail::execute
     * @covers \mod_booking\booking_rules\conditions\select_users::execute
     * @covers \mod_booking\task\send_mail_by_rule_adhoc::execute
     *
     * @param array $bdata
     * @throws \coding_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_rule_on_teacher_added(array $bdata): void {

        singleton_service::destroy_instance();

        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

        // Allow optioncacellation.
        $bdata['cancancelbook'] = 1;

        // Setup test data.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $user2->username;

        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($user1->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($user2->id, $course->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create booking rule 1 - "teacher_added".
        $boevent1 = '"boevent":"\\\\mod_booking\\\\event\\\\teacher_added"';
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"subject":"teacher added","template":"teacher added msg","templateformat":"1"}';
        $ruledata1 = [
            'name' => 'teacher_added',
            'conditionname' => 'select_users',
            'contextid' => 1,
            'conditiondata' => '{"userids":["2"]}',
            'actionname' => 'send_mail',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent1 . ',"aftercompletion":"","condition":"0"}',
        ];
        $rule1 = $plugingenerator->create_rule($ruledata1);

        global $DB;

        // Create booking option 1.
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Option-2050';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course->id;
        $record->description = 'Will start 2050';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->importing = 1;
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        // Add a user1 as a teacher to the booking option.
        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $th = new teachers_handler($option1->id);
        $res = $th->subscribe_teacher_to_booking_option($user1->id, $option1->id, $settings1->cmid);
        $this->assertEquals(true, (bool) $res);

        // Get messages.
        $messages = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        // Validate adhoc tasks for rule 1.
        $this->assertCount(1, $messages);
        $keys = array_keys($messages);
        // Message 1 has to be "teacher_removed" sent to user1.
        $message = $messages[$keys[0]];
        $customdata = $message->get_custom_data();
        $this->assertEquals("teacher added", $customdata->customsubject);
        $this->assertEquals("teacher added msg", $customdata->custommessage);
        $this->assertEquals(2, $customdata->userid);
        $this->assertStringContainsString($boevent1, $customdata->rulejson);
        $this->assertStringContainsString($ruledata1['conditiondata'], $customdata->rulejson);
        $this->assertStringContainsString($ruledata1['actiondata'], $customdata->rulejson);
        $rulejson = json_decode($customdata->rulejson);
        $this->assertEquals($user1->id, $rulejson->datafromevent->relateduserid);
    }

    /**
     * Test rule on option's teacher removed.
     *
     * @covers \mod_booking\event\teacher_removed
     * @covers \mod_booking\teachers_handler::unsubscribe_teacher_from_booking_option
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event::execute
     * @covers \mod_booking\booking_rules\actions\send_mail::execute
     * @covers \mod_booking\booking_rules\conditions\select_teacher_in_bo::execute
     * @covers \mod_booking\task\send_mail_by_rule_adhoc::execute
     *
     * @param array $bdata
     * @throws \coding_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_rule_on_teacher_removed(array $bdata): void {

        singleton_service::destroy_instance();

        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

        // Allow optioncacellation.
        $bdata['cancancelbook'] = 1;

        // Setup test data.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $user2->username;

        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($user1->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($user2->id, $course->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create booking rule 2 - "teacher_removed".
        $boevent1 = '"boevent":"\\\\mod_booking\\\\event\\\\teacher_removed"';
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"subject":"teacher removed","template":"teacher removed msg","templateformat":"1"}';
        $ruledata1 = [
            'name' => 'teacher_removed',
            'conditionname' => 'select_teacher_in_bo',
            'contextid' => 1,
            'conditiondata' => '',
            'actionname' => 'send_mail',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent1 . ',"aftercompletion":"","condition":"0"}',
        ];
        $rule1 = $plugingenerator->create_rule($ruledata1);

        // Create booking option 1.
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Option-2050';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course->id;
        $record->description = 'Will start 2050';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $user1->username . ',' . $user2->username;
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        // Add a user1 as a teacher to the booking option.
        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $th = new teachers_handler($option1->id);
        $res = $th->unsubscribe_teacher_from_booking_option($user1->id, $option1->id, $settings1->cmid);
        $this->assertEquals(true, (bool) $res);

        // Get messages.
        $messages = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');

        // Validate adhoc tasks for rule 1.
        $this->assertCount(2, $messages);
        $keys = array_keys($messages);
        // Message 1 has to be "teacher_removed" sent to user1.
        $message = $messages[$keys[0]];
        $this->assertEquals($user1->id, $message->get_userid());
        $customdata = $message->get_custom_data();
        $this->assertEquals("teacher removed", $customdata->customsubject);
        $this->assertEquals("teacher removed msg", $customdata->custommessage);
        $this->assertEquals($user1->id, $customdata->userid);
        $this->assertStringContainsString($boevent1, $customdata->rulejson);
        $this->assertStringContainsString($ruledata1['conditiondata'], $customdata->rulejson);
        $this->assertStringContainsString($ruledata1['actiondata'], $customdata->rulejson);
        $rulejson = json_decode($customdata->rulejson);
        $this->assertEquals($user1->id, $rulejson->datafromevent->relateduserid);
        // Message 2 has to be "teacher_removed" sent to user2.
        $message = $messages[$keys[1]];
        $this->assertEquals($user2->id, $message->get_userid());
        $customdata = $message->get_custom_data();
        $this->assertEquals("teacher removed", $customdata->customsubject);
        $this->assertEquals($user2->id, $customdata->userid);
        $rulejson = json_decode($customdata->rulejson);
        $this->assertEquals($user1->id, $rulejson->datafromevent->relateduserid);
    }

    /**
     * Test rule on answer and option being cancelled.
     *
     * @covers \mod_booking\event\bookinganswer_cancelled
     * @covers \mod_booking\event\bookingoption_cancelled
     * @covers \mod_booking\booking_option::user_delete_response
     * @covers \mod_booking\booking_option::cancelbookingoption
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event::execute
     * @covers \mod_booking\booking_rules\conditions\select_student_in_bo::execute
     * @covers \mod_booking\booking_rules\conditions\select_teacher_in_bo::execute
     * @covers \mod_booking\booking_rules\actions\send_mail::execute
     *
     * @param array $bdata
     * @throws \coding_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_rule_on_answer_and_option_cancelled(array $bdata): void {

        singleton_service::destroy_instance();

        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

        // Allow optioncacellation.
        $bdata['cancancelbook'] = 1;

        // Setup test data.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $user3 = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $user1->username;

        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($user1->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($user2->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($user3->id, $course->id, 'student');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create booking rule - "bookinganswer_cancelled".
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"subject":"answcancsubj","template":"answcancmsg","templateformat":"1"}';
        $ruledata1 = [
            'name' => 'notifystudents',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"0"}',
            'actionname' => 'send_mail',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{"boevent":"\\\\mod_booking\\\\event\\\\bookinganswer_cancelled","aftercompletion":"","condition":"0"}',
        ];
        $rule1 = $plugingenerator->create_rule($ruledata1);

        // Create booking rule - "override".
        $boevent2 = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_cancelled"';
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"subject":"optcancsubj","template":"optcancmsg","templateformat":"1"}';
        $ruledata2 = [
            'name' => 'notifyteachers',
            'conditionname' => 'select_teacher_in_bo',
            'contextid' => 1,
            'conditiondata' => '',
            'actionname' => 'send_mail',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent2 . ',"aftercompletion":"","condition":"0"}',
        ];
        $rule2 = $plugingenerator->create_rule($ruledata2);

        // Create booking option 1.
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Option-2050';
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

        // Create a booking option answer.
        $result = $plugingenerator->create_answer(['optionid' => $option1->id, 'userid' => $user2->id]);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $result);
        $result = $plugingenerator->create_answer(['optionid' => $option1->id, 'userid' => $user3->id]);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $result);
        singleton_service::destroy_booking_answers($option1->id);

        // Cancel booking option answer for user2.
        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $option->user_delete_response($user2->id);
        // Cancel entire booking option.
        booking_option::cancelbookingoption($option1->id);

        // Get messages.
        $messages = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');

        // Validate scheduled adhoc tasks. Validate messages - order might be free.
        foreach ($messages as $key => $message) {
            $customdata = $message->get_custom_data();
            if (strpos($customdata->customsubject, "answcancsubj") !== false) {
                // Validate message on the option's answer cancellation.
                $this->assertEquals("answcancsubj", $customdata->customsubject);
                $this->assertEquals("answcancmsg", $customdata->custommessage);
                $this->assertEquals($user3->id, $customdata->userid);
                $this->assertStringContainsString('bookinganswer_cancelled', $customdata->rulejson);
                $this->assertStringContainsString($ruledata1['conditiondata'], $customdata->rulejson);
                $this->assertStringContainsString($ruledata1['actiondata'], $customdata->rulejson);
            } else {
                // Validate message on the entire option cancellation.
                $this->assertEquals("optcancsubj", $customdata->customsubject);
                $this->assertEquals("optcancmsg", $customdata->custommessage);
                $this->assertEquals($user1->id, $customdata->userid);
                $this->assertStringContainsString($boevent2, $customdata->rulejson);
                $this->assertStringContainsString($ruledata2['conditiondata'], $customdata->rulejson);
                $this->assertStringContainsString($ruledata2['actiondata'], $customdata->rulejson);
            }
        }
    }

    /**
     * Test rule on before and after cursestart events.
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
    public function test_rule_on_beforeafter_coursestart(array $bdata): void {

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
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"subject":"1daybefore","template":"will start tomorrow","templateformat":"1"}';
        $ruledata1 = [
            'name' => '1daybefore',
            'conditionname' => 'select_users',
            'contextid' => 1,
            'conditiondata' => '{"userids":["2"]}',
            'actionname' => 'send_mail',
            'actiondata' => $actstr,
            'rulename' => 'rule_daysbefore',
            'ruledata' => '{"days":"1","datefield":"coursestarttime","cancelrules":[]}',
        ];
        $rule1 = $plugingenerator->create_rule($ruledata1);

        // Create booking rule - "ndays after".
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"subject":"1dayafter","template":"was ended yesterday","templateformat":"1"}';
        $ruledata2 = [
            'name' => '1dayafter',
            'conditionname' => 'select_users',
            'contextid' => 1,
            'conditiondata' => '{"userids":["2"]}',
            'actionname' => 'send_mail',
            'actiondata' => $actstr,
            'rulename' => 'rule_daysbefore',
            'ruledata' => '{"days":"-1","datefield":"courseendtime","cancelrules":[]}',
        ];
        $rule2 = $plugingenerator->create_rule($ruledata2);

        // Create booking option 1.
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Option-tomorrow';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course->id;
        $record->description = 'Will start tomorrow';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        $messages = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');

        // Validate scheduled adhoc tasks. Validate messages - order might be free.
        foreach ($messages as $key => $message) {
            $customdata = $message->get_custom_data();
            if (strpos($customdata->customsubject, "1daybefore") !== false) {
                $this->assertEquals(strtotime('19 June 2050 15:00'), $message->get_next_run_time());
                $this->assertEquals("will start tomorrow", $customdata->custommessage);
                $this->assertEquals("2", $customdata->userid);
                $this->assertStringContainsString($ruledata1['ruledata'], $customdata->rulejson);
                $this->assertStringContainsString($ruledata1['conditiondata'], $customdata->rulejson);
                $this->assertStringContainsString($ruledata1['actiondata'], $customdata->rulejson);
            } else if (strpos($customdata->customsubject, "1dayafter") !== false) {
                $this->assertEquals(strtotime('21 July 2050 14:00'), $message->get_next_run_time());
                $this->assertEquals("was ended yesterday", $customdata->custommessage);
                $this->assertEquals("2", $customdata->userid);
                $this->assertStringContainsString($ruledata2['ruledata'], $customdata->rulejson);
                $this->assertStringContainsString($ruledata2['conditiondata'], $customdata->rulejson);
                $this->assertStringContainsString($ruledata2['actiondata'], $customdata->rulejson);
            } else {
                continue;
            }
        }
    }

    /**
     * Test session reminder rules.
     *
     * @covers \mod_booking\booking_option::update
     *
     * @param array $bdata
     * @throws \coding_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_session_reminder_rules(array $bdata): void {

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
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"subject":"sessionreminderrule","template":"session about to start","templateformat":"1"}';
        $sessionreminderruledata = [
            'name' => 'sessionreminderrule',
            'contextid' => 1,
            'conditionname' => 'select_users',
            'conditiondata' => '{"userids":["' . $user1->id . '","' . $user2->id . '"]}',
            'actionname' => 'send_mail',
            'actiondata' => $actstr,
            'rulename' => 'rule_daysbefore',
            'ruledata' => '{"days":"1","datefield":"optiondatestarttime","cancelrules":[]}',
        ];
        $sessionreminderrule = $plugingenerator->create_rule($sessionreminderruledata);

        // Create booking option 1.
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Option-with-two-sessions';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course->id;
        $record->description = 'This option has two optiondates, one tomorrow and one next week';
        $record->optiondateid_0 = 0; // Has to be 0 so a new optiondate is created.
        $record->daystonotify_0 = 0;
        $record->coursestarttime_0 = strtotime('2 June 2050 15:00'); // Tomorrow.
        $record->courseendtime_0 = strtotime('2 June 2050 16:00');
        $record->optiondateid_1 = 0; // Has to be 0 so a new optiondate is created.
        $record->daystonotify_1 = 7;
        $record->coursestarttime_1 = strtotime('8 June 2050 15:00'); // Tomorrow.
        $record->courseendtime_1 = strtotime('8 June 2050 16:00');
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        $messages = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');

        // Validate scheduled adhoc tasks. Validate messages - order might be free.
        foreach ($messages as $key => $message) {
            $customdata = $message->get_custom_data();
            if (strpos($customdata->customsubject, "sessionreminderrule") !== false) {
                $this->assertEquals(strtotime('1 June 2050 15:00'), $message->get_next_run_time(), 'error with next run time');
                $this->assertEquals("session about to start", $customdata->custommessage, 'error with custom message');
                $this->assertContains($customdata->userid, [$user1->id, $user2->id], 'error with user id');
                $this->assertStringContainsString(
                    $sessionreminderruledata['ruledata'],
                    $customdata->rulejson,
                    'error with ruledata'
                );
                $this->assertStringContainsString(
                    $sessionreminderruledata['conditiondata'],
                    $customdata->rulejson,
                    'error with conditiondata'
                );
                $this->assertStringContainsString(
                    $sessionreminderruledata['actiondata'],
                    $customdata->rulejson,
                    'error with actiondata'
                );
            } else {
                continue;
            }
        }
    }

    /**
     * Test rule on rule override.
     *
     * @covers \mod_booking\event\bookinganswer_cancelled
     * @covers \mod_booking\event\bookingoption_cancelled
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event::execute
     * @covers \mod_booking\booking_rules\conditions\select_users::execute
     * @covers \mod_booking\booking_rules\conditions\select_teacher_in_bo::execute
     * @covers \mod_booking\booking_rules\actions\send_mail::execute
     *
     * @param array $bdata
     * @throws \coding_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_rule_on_rule_override(array $bdata): void {

        singleton_service::destroy_instance();

        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

        // Allow optioncacellation.
        $bdata['cancancelbook'] = 1;

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

        // Create booking rule - "bookinganswer_cancelled".
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"subject":"answcancsubj","template":"answcancmsg","templateformat":"1"}';
        $ruledata1 = [
            'name' => 'notifyadmin',
            'conditionname' => 'select_users',
            'contextid' => 1,
            'conditiondata' => '{"userids":["2"]}',
            'actionname' => 'send_mail',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{"boevent":"\\\\mod_booking\\\\event\\\\bookinganswer_cancelled","aftercompletion":"","condition":"0"}',
        ];
        $rule1 = $plugingenerator->create_rule($ruledata1);

        // Create booking rule - "override".
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"subject":"overridesubj","template":"overridemsg","templateformat":"1"}';
        $boevent2 = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_cancelled"';
        $cancelrules2 = '"cancelrules":["' . $rule1->id . '"]';
        $ruledata2 = [
            'name' => 'override',
            'conditionname' => 'select_teacher_in_bo',
            'contextid' => 1,
            'conditiondata' => '',
            'actionname' => 'send_mail',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent2 . ',"aftercompletion":"","condition":"0",' . $cancelrules2 . '}',
        ];
        $rule2 = $plugingenerator->create_rule($ruledata2);

        // Create booking option 1.
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Option-tomorrow';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course->id;
        $record->description = 'Will start tomorrow';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $user1->username;
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        // Create a booking option answer.
        $result = $plugingenerator->create_answer(['optionid' => $option1->id, 'userid' => $user2->id]);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $result);
        singleton_service::destroy_booking_answers($option1->id);

        // Cancel entire booking option.
        booking_option::cancelbookingoption($option1->id);

        // Get messages.
        $messages = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');

        // Validate scheduled adhoc tasks.
        // phpcs:ignore
        //$this->assertCount(1, $messages); // Override does not work in phpunit, 2 messages.
        $keys = array_keys($messages);
        // Task 1 has to be "override".
        $message = $messages[$keys[0]];
        $customdata = $message->get_custom_data();
        $this->assertEquals("overridesubj", $customdata->customsubject);
        $this->assertEquals("overridemsg", $customdata->custommessage);
        $this->assertEquals($user1->id, $customdata->userid);
        $this->assertStringContainsString($boevent2, $customdata->rulejson);
        $this->assertStringContainsString($cancelrules2, $customdata->rulejson);
        $this->assertStringContainsString($ruledata2['conditiondata'], $customdata->rulejson);
        $this->assertStringContainsString($ruledata2['actiondata'], $customdata->rulejson);
    }

    /**
     * Test rule on booking_option_update event.
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
    public function test_rule_on_booking_option_update(array $bdata): void {

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
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"subject":"OptionChanged","template":"Changes:{changes}","templateformat":"1"}';
        $ruledata1 = [
            'name' => 'emailchanges',
            'conditionname' => 'select_teacher_in_bo',
            'contextid' => 1,
            'conditiondata' => '',
            'actionname' => 'send_mail',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_updated","condition":"0","aftercompletion":""}',
        ];
        $rule1 = $plugingenerator->create_rule($ruledata1);

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

        // Validate console output.
        $expected = "send_mail_by_rule_adhoc task: mail successfully sent for option " . $option->id . " to user " . $user1->id;
        $this->assertStringContainsString($expected, $res);

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
    }

    /**
     * Test rules on ask for confirmation of the booking.
     *
     * @covers \mod_booking\bo_availability\conditions\confirmation::is_available
     * @covers \mod_booking\bo_availability\conditions\onwaitinglist::is_available
     * @covers \mod_booking\event\bookinganswer_waitingforconfirmation
     * @covers \mod_booking\event\bookingoptionwaitinglist_booked
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event
     * @covers \mod_booking\booking_rules\actions\send_mail
     * @covers \mod_booking\booking_rules\conditions\select_teacher_in_bo
     * @covers \mod_booking\booking_rules\conditions\enter_userprofilefield
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_rule_on_askforconfirmation(array $bdata): void {
        global $DB, $CFG;

        singleton_service::destroy_instance();

        $bdata['cancancelbook'] = 1;

        // Add a user profile field of text type.
        $fieldid1 = $this->getDataGenerator()->create_custom_profile_field([
            'shortname' => 'sport', 'name' => 'Sport', 'datatype' => 'text',
        ])->id;

        // Create course.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $teacher1 = $this->getDataGenerator()->create_user();
        $teacher2 = $this->getDataGenerator()->create_user(['profile_field_sport' => 'football']);

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $teacher1->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher1->id, $course1->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($teacher2->id, $course1->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create booking rule 1 - "bookinganswer_waitingforconfirmation".
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"subject":"waitinglistconfirmsubj","template":"waitinglistconfirmmsg","templateformat":"1"}';
        $boevent1 = '"boevent":"\\\\mod_booking\\\\event\\\\bookinganswer_waitingforconfirmation"';
        $ruledata1 = [
            'name' => 'notifystudent',
            'conditionname' => 'enter_userprofilefield',
            'contextid' => 1,
            'conditiondata' => '{"cpfield":"sport","operator":"~","textfield":"football"}',
            'actionname' => 'send_mail',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent1 . ',"aftercompletion":"","condition":"0"}',
        ];
        $rule1 = $plugingenerator->create_rule($ruledata1);

        // Create booking rule 2 - "bookingoptionwaitinglist_booked".
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"subject":"waitinglistsubj","template":"waitinglistmsg","templateformat":"1"}';
        $boevent2 = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoptionwaitinglist_booked"';
        $ruledata2 = [
            'name' => 'override',
            'conditionname' => 'select_teacher_in_bo',
            'contextid' => 1,
            'conditiondata' => '',
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
        $record->waitforconfirmation = 1; // Force waitinglist.
        $record->description = 'Will start in 2050';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $teacher1->username . ',' . $teacher2->username;
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        // Book students via waitinglist.
        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $boinfo = new bo_info($settings);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        // Book the student1 right away.
        $this->setUser($student1);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ASKFORCONFIRMATION, $id);

        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Confirm student1.
        $this->setAdminUser();

        // Get messages.
        $messages = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');

        $this->assertCount(3, $messages);
        $keys = array_keys($messages);
        // Task 1 has to be "bookinganswer_waitingforconfirmation".
        $message = $messages[$keys[0]];
        // Validate adhoc tasks for rule 1.
        $customdata = $message->get_custom_data();
        $this->assertEquals("waitinglistconfirmsubj", $customdata->customsubject);
        $this->assertEquals("waitinglistconfirmmsg", $customdata->custommessage);
        $this->assertEquals($teacher2->id, $customdata->userid);
        $this->assertStringContainsString($boevent1, $customdata->rulejson);
        $this->assertStringContainsString($ruledata1['conditiondata'], $customdata->rulejson);
        $this->assertStringContainsString($ruledata1['actiondata'], $customdata->rulejson);
        $rulejson = json_decode($customdata->rulejson);
        $this->assertEquals($student1->id, $rulejson->datafromevent->relateduserid);
        $this->assertEquals($teacher2->id, $message->get_userid());
        // Task 2 has to be "bookingoptionwaitinglist_booked".
        $message = $messages[$keys[1]];
        // Validate adhoc tasks for rule 1.
        $customdata = $message->get_custom_data();
        $this->assertEquals("waitinglistsubj", $customdata->customsubject);
        $this->assertEquals("waitinglistmsg", $customdata->custommessage);
        $this->assertEquals($teacher1->id, $customdata->userid);
        $this->assertStringContainsString($boevent2, $customdata->rulejson);
        $this->assertStringContainsString($ruledata2['conditiondata'], $customdata->rulejson);
        $this->assertStringContainsString($ruledata2['actiondata'], $customdata->rulejson);
        $rulejson = json_decode($customdata->rulejson);
        $this->assertEquals($student1->id, $rulejson->datafromevent->relateduserid);
        $this->assertEquals($teacher1->id, $message->get_userid());
    }

    /**
     * Test rules for "booking on waitinglist booked" and "option free to bookagain" events.
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
    public function test_rule_on_freeplaceagain(array $bdata): void {
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
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"subject":"waitinglistsubj","template":"waitinglistmsg","templateformat":"1"}';
        $boevent1 = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoptionwaitinglist_booked"';
        $ruledata1 = [
            'name' => 'notifystudent',
            'conditionname' => 'select_teacher_in_bo',
            'contextid' => 1,
            'conditiondata' => '',
            'actionname' => 'send_mail',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent1 . ',"aftercompletion":"","condition":"0"}',
        ];
        $rule1 = $plugingenerator->create_rule($ruledata1);

        // Create booking rule 2 - "bookingoption_freetobookagain".
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"subject":"freeplacesubj","template":"freeplacemsg","templateformat":"1"}';
        $boevent2 = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_freetobookagain"';
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
        $record->waitforconfirmation = 1; // Force waitinglist.
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
                $this->assertEquals("waitinglistsubj", $customdata->customsubject);
                $this->assertEquals("waitinglistmsg", $customdata->custommessage);
                $this->assertEquals($teacher1->id, $customdata->userid);
                $this->assertStringContainsString($boevent1, $customdata->rulejson);
                $this->assertStringContainsString($ruledata1['conditiondata'], $customdata->rulejson);
                $this->assertStringContainsString($ruledata1['actiondata'], $customdata->rulejson);
                $this->assertEquals($teacher1->id, $message->get_userid());
                $rulejson = json_decode($customdata->rulejson);
                $this->assertContains($rulejson->datafromevent->relateduserid, [$student1->id, $student2->id]);
            }
        }
    }

    /**
     * Test rule on option being completed for user.
     *
     * @covers \mod_booking\booking_option::user_completed_option
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
    public function test_rule_on_option_completion(array $bdata): void {

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
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"subject":"bookedsubj","template":"bookednmsg","templateformat":"1"}';
        $ruledata1 = [
            'name' => 'notifystudent',
            'conditionname' => 'match_userprofilefield',
            'contextid' => 1,
            'conditiondata' => '{"optionfield":"text","operator":"~","cpfield":"sport"}',
            'actionname' => 'send_mail',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_booked","aftercompletion":"","condition":"0"}',
        ];
        $rule1 = $plugingenerator->create_rule($ruledata1);

        // Create booking rule 2 - "bookingoption_completed".
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"subject":"completionsubj","template":"completionmsg","templateformat":"1"}';
        $ruledata2 = [
            'name' => 'notifystudent',
            'conditionname' => 'select_user_from_event',
            'contextid' => 1,
            'conditiondata' => '{"userfromeventtype":"relateduserid"}',
            'actionname' => 'send_mail',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_completed","aftercompletion":"","condition":"0"}',
        ];
        $rule2 = $plugingenerator->create_rule($ruledata2);

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
        $this->assertEquals("bookedsubj", $customdata->customsubject);
        $this->assertEquals("bookednmsg", $customdata->custommessage);
        $this->assertEquals($user3->id, $customdata->userid);
        $this->assertStringContainsString("bookingoption_booked", $customdata->rulejson);
        $this->assertStringContainsString($ruledata1['conditiondata'], $customdata->rulejson);
        $this->assertStringContainsString($ruledata1['actiondata'], $customdata->rulejson);
        $this->assertEquals($user3->id, $message->get_userid());
        // Task 2 has to be "select_user_from_event".
        $message = $messages[$keys[1]];
        // Validate adhoc tasks for rule 1.
        $customdata = $message->get_custom_data();
        $this->assertEquals("completionsubj", $customdata->customsubject);
        $this->assertEquals("completionmsg", $customdata->custommessage);
        $this->assertEquals($user2->id, $customdata->userid);
        $this->assertStringContainsString("bookingoption_completed", $customdata->rulejson);
        $this->assertStringContainsString($ruledata2['conditiondata'], $customdata->rulejson);
        $this->assertStringContainsString($ruledata2['actiondata'], $customdata->rulejson);
        $rulejson = json_decode($customdata->rulejson);
        $this->assertEquals($user2->id, $rulejson->datafromevent->relateduserid);
        $this->assertEquals($user2->id, $message->get_userid());
    }

    /**
     * Test booking option availability: \condition\customform with supporting of data deletion.
     *
     * @covers \mod_booking\bo_availability\conditions\customform::is_available
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_booking_rules_customform_delete_data(array $bdata): void {

        singleton_service::destroy_instance();

        // Setup test data.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $student3 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_bookingid($booking1->id);
        singleton_service::destroy_booking_singleton_by_cmid($bookingsettings->cmid);
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_bookingid($booking1->id);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student3->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course1->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Option 1 - custom form with admin deleteion.
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course1->id;
        // Set test objective setting(s) - customform and admin deletion.
        $record->bo_cond_customform_restrict = 1;
        $record->bo_cond_customform_select_1_1 = 'shorttext';
        $record->bo_cond_customform_label_1_1 = 'Personal requirement:';
        $record->bo_cond_customform_deleteinfoscheckboxadmin = 1;
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('yesterday');
        $record->courseendtime_0 = strtotime('now + 3 seconds'); // Ending time must be in future.
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);
        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);

        // Option 2 - custom form with user deleteion.
        $record->text = 'Test option2';
        $record->bo_cond_customform_restrict = 1;
        $record->bo_cond_customform_select_1_1 = 'shorttext';
        $record->bo_cond_customform_label_1_1 = 'Personal requirement:';
        $record->bo_cond_customform_select_1_2 = 'deleteinfoscheckboxuser';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('yesterday');
        $record->courseendtime_0 = strtotime('now + 3 seconds');
        $option2 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option2->id);
        $settings2 = singleton_service::get_instance_of_booking_option_settings($option2->id);

        // Create booking rule - "ndays before".
        $ruledata1 = [
            'name' => '1daybefore',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"0"}',
            'actionname' => 'delete_conditions_from_bookinganswer',
            'actiondata' => '{}',
            'rulename' => 'rule_daysbefore',
            'ruledata' => '{"days":"0","datefield":"courseendtime","cancelrules":[]}',
        ];
        $rule1 = $plugingenerator->create_rule($ruledata1);

        // Book option1 by the 1st student.
        $result = $plugingenerator->create_answer(['optionid' => $option1->id, 'userid' => $student1->id]);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $result);
        $answer1 = singleton_service::get_instance_of_booking_answers($settings1)->answers;
        $this->assertIsArray($answer1);
        $this->assertCount(1, $answer1);
        $answer1 = array_shift($answer1);

        // Create option1/student1 answer custom form data record.
        $formrecord1 = new stdClass();
        $formrecord1->id = $option1->id;
        $formrecord1->userid = $student1->id;
        $formrecord1->customform_shorttext_1 = 'lactose-free milk (o1s1)';
        $formrecord1->deleteinfoscheckboxadmin = 1; // Forece delete (should be provided explicitly).
        $customformstore1 = new customformstore($student1->id, $settings1->id);
        $customformstore1->set_customform_data($formrecord1);
        customform::add_json_to_booking_answer($answer1, $student1->id);

        // Book option2 by the 2nd and 3rd students.
        $result = $plugingenerator->create_answer(['optionid' => $option2->id, 'userid' => $student2->id]);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $result);
        $result = $plugingenerator->create_answer(['optionid' => $option2->id, 'userid' => $student3->id]);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $result);

        // Create custom form records for answers of the option2.
        $answers2 = singleton_service::get_instance_of_booking_answers($settings2)->answers;
        $this->assertIsArray($answers2);
        $this->assertCount(2, $answers2);
        // Create option2/student2 answer custom form data record.
        $answer2 = array_shift($answers2);
        $formrecord2 = new stdClass();
        $formrecord2->id = $option2->id;
        $formrecord2->userid = $student2->id;
        $formrecord2->customform_shorttext_1 = 'honey (o2s2)';
        $formrecord2->customform_deleteinfoscheckboxuser = 0; // Force NOT delete (should be provided explicitly).
        $customformstore2 = new customformstore($student2->id, $settings2->id);
        $customformstore2->set_customform_data($formrecord2);
        customform::add_json_to_booking_answer($answer2, $student2->id);
        // Create option2/student3 answer custom form data record.
        $answer3 = array_shift($answers2);
        $formrecord3 = new stdClass();
        $formrecord3->id = $option2->id;
        $formrecord3->userid = $student3->id;
        $formrecord3->customform_shorttext_1 = 'butter (o2s3)';
        $formrecord3->customform_deleteinfoscheckboxuser = 1; // Force delete (should be provided explicitly).
        $customformstore2 = new customformstore($student3->id, $settings2->id);
        $customformstore2->set_customform_data($formrecord3);
        customform::add_json_to_booking_answer($answer3, $student3->id);

        sleep(5);
        // Verify presence of json strings in the answers.
        singleton_service::destroy_booking_option_singleton($option1->id);
        singleton_service::destroy_booking_option_singleton($option2->id);
        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $settings2 = singleton_service::get_instance_of_booking_option_settings($option2->id);

        $answer11 = singleton_service::get_instance_of_booking_answers($settings1)->answers;
        $this->assertIsArray($answer11);
        $this->assertCount(1, $answer11);
        $answer11 = array_shift($answer11);
        $this->assertStringContainsString($formrecord1->customform_shorttext_1, $answer11->json);

        $answers2 = singleton_service::get_instance_of_booking_answers($settings2)->answers;
        $this->assertIsArray($answers2);
        $this->assertCount(2, $answers2);
        $answer22 = array_shift($answers2);
        $this->assertStringContainsString($formrecord2->customform_shorttext_1, $answer22->json);
        $answer23 = array_shift($answers2);
        $this->assertStringContainsString($formrecord3->customform_shorttext_1, $answer23->json);

        // Trigger cron tasks.
        $tsk = \core\task\manager::get_adhoc_tasks('\mod_booking\task\delete_conditions_from_bookinganswer_by_rule_adhoc');
        ob_start();
        $this->runAdhocTasks();
        $res = ob_get_clean();

        // Verify no json string in the answer for option1.
        $answer11 = singleton_service::get_instance_of_booking_answers($settings1)->answers;
        $answer11 = array_shift($answer11);
        $this->assertStringNotContainsString($formrecord1->customform_shorttext_1, $answer11->json);

        // Verify json strings in the answers for option2.
        $answers2 = singleton_service::get_instance_of_booking_answers($settings2)->answers;
        // String must be present for student2.
        $answer22 = array_shift($answers2);
        $this->assertStringContainsString($formrecord2->customform_shorttext_1, $answer22->json);
        // String must NOT be present for student3.
        $answer23 = array_shift($answers2);
        $this->assertStringNotContainsString($formrecord3->customform_shorttext_1, $answer23->json);
    }

    /**
     * Test rule on answer and option being cancelled.
     *
     * @covers \mod_booking\event\bookinganswer_cancelled
     * @covers \mod_booking\event\bookingoption_cancelled
     * @covers \mod_booking\booking_option::user_delete_response
     * @covers \mod_booking\booking_option::cancelbookingoption
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event::execute
     * @covers \mod_booking\booking_rules\conditions\select_student_in_bo::execute
     * @covers \mod_booking\booking_rules\conditions\select_teacher_in_bo::execute
     * @covers \mod_booking\booking_rules\actions\send_mail::execute
     *
     * @param array $bdata
     * @throws \coding_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_rule_on_answer_cancelled_supervisor_receives_mail(array $bdata): void {

        singleton_service::destroy_instance();

        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

        // Allow optioncacellation.
        $bdata['cancancelbook'] = 1;

        // Add a user profile field of text type.
        $fieldid1 = $this->getDataGenerator()->create_custom_profile_field([
            'shortname' => 'supervisor', 'name' => 'Supervisor', 'datatype' => 'text',
        ])->id;

        // Setup test data.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $supervisor = $this->getDataGenerator()->create_user();
        $user1 = $this->getDataGenerator()->create_user(['profile_field_supervisor' => (string)$supervisor->id]);
        $user2 = $this->getDataGenerator()->create_user();
        $user3 = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $user1->username;

        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($user1->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($user2->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($user3->id, $course->id, 'student');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create booking rule - "bookinganswer_cancelled".
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"subject":"answcancsubj","template":"answcancmsg","templateformat":"1"}';
        $ruledata1 = [
            'name' => 'notifystudents',
            'conditionname' => 'select_users_from_userfield_of_eventuser',
            'contextid' => 1,
            'conditiondata' => '{"fieldofuserfromevent":"supervisor", "userfromeventtype":"relateduserid"}',
            'actionname' => 'send_mail',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' =>
                '{"boevent":"\\\\mod_booking\\\\event\\\\bookinganswer_cancelled",' .
                '"aftercompletion":0,"condition":"0","cancelrules":[]}',
        ];
        $rule1 = $plugingenerator->create_rule($ruledata1);

        // Create booking option 1.
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Option-2050';
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

        // Create a booking option answer.
        $result = $plugingenerator->create_answer(['optionid' => $option1->id, 'userid' => $user1->id]);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $result);
        $result = $plugingenerator->create_answer(['optionid' => $option1->id, 'userid' => $user3->id]);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $result);
        singleton_service::destroy_booking_answers($option1->id);

        // Cancel booking option answer for user1.
        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $option->user_delete_response($user1->id);
        // Cancel entire booking option.
        booking_option::cancelbookingoption($option1->id);

        // Run adhock tasks.
        $sink = $this->redirectMessages();
        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        ob_start();
        $this->runAdhocTasks();
        $messages = $sink->get_messages();
        $res = ob_get_clean();
        $sink->close();
        $this->assertEquals($supervisor->id, $messages[0]->useridto);
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
