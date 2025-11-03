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
use stdClass;
use mod_booking\booking_rules\booking_rules;
use mod_booking\booking_rules\rules_info;
use mod_booking\bo_availability\bo_info;
use local_shopping_cart\shopping_cart;
use local_shopping_cart\shopping_cart_history;
use local_shopping_cart\local\cartstore;
use local_shopping_cart\output\shoppingcart_history_list;
use tool_mocktesttime\time_mock;
use mod_booking_generator;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests for booking rules.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @runInSeparateProcess
 */
final class rules_waitinglist_notification_test extends advanced_testcase {
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
        // Mandatory clean-up.
        singleton_service::destroy_instance();
        // Mandatory to deal with static variable in the booking_rules.
        rules_info::destroy_singletons();
        booking_rules::$rules = [];
        cartstore::reset();
        time_mock::reset_mock_time();
        // phpcs:ignore
        // mtrace(date('Y/m/d H:i:s', time_mock::get_mock_time())); // Debugging output.
    }

    /**
     * Create booking with bookingoption that contains price for some users, depending on profilefield.
     * Option is fully booked with waitinglist enabled. Some users on waitinglist need to pay, others don't.
     * Create rule to send interval messages.
     * One booked user cancels, 1 seat is free again.
     * Check that mail is send.
     * Check that new user NOT on waitinglist can not book.
     * Make sure, only user next on waitinglist can book.
     * If this user has the right value in the field, he will be enrolled automatically.
     * In this case, freetobookagain message should not be send (or scheduled).
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
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function test_waitinglist_notification_enable_booking(): void {
        global $DB;

        $bdata = self::booking_common_settings_provider();
        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

        $time = time_mock::get_mock_time();

        $bdata['cancancelbook'] = 1;
        set_config('cancelationfee', 0, 'local_shopping_cart');

        // Create course.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users, some of them with second price category.
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user($testdata['student2settings'] ?? []);
        $student3 = $this->getDataGenerator()->create_user($testdata['student3settings'] ?? []);
        $student4 = $this->getDataGenerator()->create_user($testdata['student4settings'] ?? []);
        $student5 = $this->getDataGenerator()->create_user($testdata['student5settings'] ?? []);
        $student6 = $this->getDataGenerator()->create_user($testdata['student6settings'] ?? []);
        $student7 = $this->getDataGenerator()->create_user($testdata['student7settings'] ?? []);
        $teacher1 = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $teacher1->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student3->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student4->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student5->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student6->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student7->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher1->id, $course1->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create booking rule 1 - "bookingoption_freetobookagain" with delays.
        $boevent1 = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_freetobookagain"';
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"interval":1440,"subject":"freeplacedelaysubj","template":"freeplacedelaymsg","templateformat":"1"}';
        $ruledata1 = [
            'name' => 'intervlqs',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail_interval',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent1 . ',"aftercompletion":0,"cancelrules":[],"condition":"2"}',
        ];
        $rule1 = $plugingenerator->create_rule($ruledata1);

        // Create booking option 1.
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'football';
        $record->maxanswers = 1;
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course1->id;
        $record->maxoverbooking = 10; // Enable waitinglist.
        $record->waitforconfirmation = 2;
        $record->confirmationonnotification = 1;
        $record->description = 'Will start in 2050';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00', time());
        $record->courseendtime_0 = strtotime('20 July 2050 14:00', time());
        $record->teachersforoption = $teacher1->username;
        $record->useprice = 0;
        $record->importing = 1;
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);
        singleton_service::destroy_booking_singleton_by_cmid($settings1->cmid); // Require to avoid caching issues.
        $boinfo1 = new bo_info($settings1);

        // Create a booking option answer - book student1.
        $this->setUser($student1);
        singleton_service::destroy_user($student1->id);
        $result = booking_bookit::bookit('option', $settings1->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMBOOKIT, $id);
        $result = booking_bookit::bookit('option', $settings1->id, $student1->id);

        $answer = $DB->get_record('booking_answers', ['userid' => $student1->id]);
        // User student1 should be booked now.
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Book the student2 on waitinglist.
        $this->setUser($student2);
        singleton_service::destroy_user($student2->id);
        $result = booking_bookit::bookit('option', $settings1->id, $student2->id);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Book the student3 via waitinglist.
        time_mock::set_mock_time(strtotime('+1 days', time()));
        $time = time_mock::get_mock_time();
        $this->setUser($student3);
        singleton_service::destroy_user($student3->id);
        $result = booking_bookit::bookit('option', $settings1->id, $student3->id);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Book the student4 via waitinglist.
        time_mock::set_mock_time(strtotime('+1 day', time()));
        $time = time_mock::get_mock_time();
        $this->setUser($student4);
        singleton_service::destroy_user($student4->id);
        // Bookitbutton should NOT block if there are places on waitinglist.
        $result = booking_bookit::bookit('option', $settings1->id, $student4->id);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student4->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // First user cancels.
        $this->setUser($student1);
        singleton_service::destroy_user($student1->id);
        // Render to see if "Undo my booking" present.
        $buttons = booking_bookit::render_bookit_button($settings1, $student1->id);
        $this->assertStringContainsString('Undo my booking', $buttons);
        $result = booking_bookit::bookit('option', $settings1->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMCANCEL, $id);
        $result = booking_bookit::bookit('option', $settings1->id, $student1->id);

        // Even if there is a free spot, a "new" user can not book with setting waitforconfirmation = 2.
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ASKFORCONFIRMATION, $id);

        // Try to book EXTERNAL user - not yet on waitinglist.
        // Result depends on waitforconfirmation setting.
        $this->setUser($student6);
        singleton_service::destroy_user($student6->id);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student6->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ASKFORCONFIRMATION, $id);

        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Check for proper number of tasks.
        $tasks = \core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc');
        $this->assertCount(2, $tasks);

        // In the future we run tasks.
        // No free seats available, so no messages should be send.
        time_mock::set_mock_time(strtotime('+3 day', time()));
        $sink = $this->redirectMessages();
        ob_start();
        $this->runAdhocTasks();
        $messages = $sink->get_messages();
        $res = ob_get_clean();
        $sink->close();

        // Validate console output.
        $expected = "send_mail_by_rule_adhoc task: Rule does not apply anymore. Mail was NOT SENT for option "
            . $option1->id . " and user " . $student2->id;
        $this->assertStringContainsString($expected, $res);
        $expected = "confirm_bookinganswer_by_rule_adhoc task: Rule does not apply anymore. NO execution for option "
            . $option1->id . " and user " . $student3->id;
        $this->assertStringContainsString($expected, $res);
        $expected = "send_mail_by_rule_adhoc task: Rule does not apply anymore. Mail was NOT SENT for option "
            . $option1->id . " and user " . $student3->id;
        $this->assertStringContainsString($expected, $res);

        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);
    }

    /**
     * This test verifies that an answer is automatically booked for the first user on the waiting list.
     *
     * The booking option has a price of 25 euros for the default category and 0 euros for the student category.
     * Both the "maxanswers" and "users on the waiting list" settings are set to 2.
     *
     * The option requires confirmation only for users on the waiting list.
     * Notifications are configured to be sent only to the first person on the waiting list.
     *
     * A booking rule is also required, which should be triggered when the "freetobookagain" event is fired.
     *
     * We will check the availability of the booking option for five students:
     * - Students 1, 2, 4, and 5 have the default price category.
     * - Student 3 has the student price category.
     *
     * We book the option for students 1 and 2, and place students 3 and 4 on the waiting list.
     * Then, we remove the booking answer of student 1.
     *
     * After running the cron, we expect that the option will be automatically booked for student 3,
     * as this student is the first person on the waiting list and has the student price category.
     *
     * @covers \mod_booking\bo_availability\conditions\alreadybooked::is_available
     * @covers \mod_booking\bo_availability\conditions\onwaitinglist::is_available
     * @covers \mod_booking\event\bookingoption_freetobookagain
     * @covers \mod_booking\event\bookingoptionwaitinglist_booked
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event
     * @covers \mod_booking\booking_rules\actions\send_mail
     *
     * @return void
     */
    public function test_auto_booking_of_priced_option_for_first_waitinglist_user_who_has_price_cateroy_free(): void {
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

        // Create booking rule 1 - "bookingoption_freetobookagain" with delays.
        $boevent1 = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_freetobookagain"';
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"",';
        $actstr .= '"interval":1440,"subject":"freeplacedelaysubj","template":"freeplacedelaymsg","templateformat":"1"}';
        $ruledata1 = [
            'name' => 'intervlqs',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail_interval',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent1 . ',"aftercompletion":0,"cancelrules":[],"condition":"2"}',
        ];
        $rule1 = $plugingenerator->create_rule($ruledata1);

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
        $record->confirmationonnotification = 2; // Notified user set to only for one user at a time.
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

        // Get adhoc tasks to see if expected ones are created.
        $tasks = \core\task\manager::get_adhoc_tasks(\mod_booking\task\confirm_bookinganswer_by_rule_adhoc::class);
        $this->assertNotEmpty($tasks, 'Expected confirm_bookinganswer_by_rule_adhoc adhoc tasks to be created.');

        $tasks = \core\task\manager::get_adhoc_tasks(\mod_booking\task\send_mail_by_rule_adhoc::class);
        $this->assertNotEmpty($tasks, 'Expected send_mail_by_rule_adhoc adhoc tasks to be created.');

        ob_start();
        $this->runAdhocTasks();
        $res = ob_get_clean();

        // Both tasks logged their results, so we check for the string twice.
        $this->assertTrue(substr_count($res, '_by_rule_adhoc') >= 2);

        // Check if student 3 is answer is confirmed.
        $answers = singleton_service::get_instance_of_booking_answers($settings);
        $bookedusers = $answers->get_usersonlist();

        $userids = array_map(fn($o) => $o->userid, $bookedusers);
        $allowed = ["Student 2" => $student[2]->id, "Student 3" => $student[3]->id];
        foreach ($allowed as $std => $expectedid) {
            $this->assertContains(
                $expectedid,
                $userids,
                "{$std} not found in booked users."
            );
        }

        // Chekc if 5th student see the fully booked.
        $this->setUser($student[3]);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student[3]->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        $this->assertCount(2, $bookedusers, 'Expected exactly 2 booked students.');
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
