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
        // Tasks are tested in depth in other tests of this class.
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
