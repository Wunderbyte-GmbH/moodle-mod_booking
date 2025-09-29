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
use mod_booking\booking_answers\booking_answers;
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
 * Test waitinglist.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @runInSeparateProcess
 */
final class sync_waitinglist_test extends advanced_testcase {
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
     * Test rules for "option free to bookagain" and "notification in intervals" events when waitinglist is forced.
     *
     * @covers \mod_booking\bo_availability\conditions\alreadybooked::is_available
     * @covers \mod_booking\bo_availability\conditions\onwaitinglist::is_available
     * @covers \mod_booking\event\bookingoption_freetobookagain
     * @covers \mod_booking\event\bookingoptionwaitinglist_booked
     * @doesNotPerformAssertions
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_rule_on_freeplace_on_intervals_when_waitinglist_forced(array $bdata): void {
        global $DB, $CFG;

        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

        time_mock::set_mock_time(strtotime('-4 days', time()));
        $time = time_mock::get_mock_time();
        $now = time();
        $this->assertEquals($time, $now);

        $bdata['cancancelbook'] = 1;

        $this->preventResetByRollback();

        // Create course.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher1 = null;
        // Create 1000 users.

        $users = [];
        $bookedusers = [];
        $i = 0;
        while ($i < 400) {
            $users[] = $this->getDataGenerator()->create_user();
            $i++;
        }

        $this->setAdminUser();

        // The first user is teacher.
        foreach ($users as $user) {
            if (!$teacher1) {
                $teacher1 = $user;
                $this->getDataGenerator()->enrol_user($user->id, $course1->id, 'teacher');
                continue;
            }
            if (empty($student1)) {
                $student1 = $user;
            } else if (empty($student2)) {
                $student2 = $user;
            } else if (empty($student3)) {
                $student3 = $user;
            } else if (empty($student4)) {
                $student4 = $user;
            } else if (empty($student5)) {
                $student5 = $user;
            }
            $this->getDataGenerator()->enrol_user($user->id, $course1->id, 'student');
        }

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $teacher1->username;
        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create price categories.
        $pricecategorydata1 = (object)[
            'ordernum' => 1,
            'name' => 'default',
            'identifier' => 'default',
            'defaultvalue' => 25,
            'pricecatsortorder' => 1,
        ];
        $pricecategory1 = $plugingenerator->create_pricecategory($pricecategorydata1);

        // Create booking option 1.
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'handball 2';
        $record->chooseorcreatecourse = 1; // Connected existing course.
        $record->courseid = $course1->id;
        $record->maxanswers = 100;
        $record->maxoverbooking = 0; // Don't enable waitinglist.
        $record->description = 'Will start in 2050';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('+2 days', time());
        $record->courseendtime_0 = strtotime('+ 5 days', time());
        $record->teachersforoption = $teacher1->username;
        $record->useprice = 1;
        $option1 = $plugingenerator->create_option($record);

        $cmd = 'php ' . escapeshellarg(
                __DIR__ . '/../fixtures/simulate_ongoing_reservation_and_cancel.php'
            ) . ' > /dev/null 2>&1 &';
        $result = exec($cmd);

        $cmd = 'php ' . escapeshellarg(
                __DIR__ . '/../fixtures/simulate_ongoing_booking.php'
            ) . ' > /dev/null 2>&1 &';
        $result = exec($cmd);

        usleep(900000);

        $events = $DB->get_records('logstore_standard_log');
    }

    /**
     * Data provider for test waitinglist with price.
     *
     * @return array
     *
     */
    public static function waitinglist_price_provider(): array {
        return [
            'second_user_no_price_no_confirmationlist' => [
                [
                    'secondprice' => 0,
                    'student2settings' => ['profile_field_pricecat' => 'secondprice'],
                    'bookseconduser' => true,
                    'waitforconfirmation' => 0,
                    'student5settings' => [],
                    'confirmationonnotification' => 0, // It can not be any other value when waitforconfirmation is equal to zero.
                ],
                [
                    // After the first cancellation, with these settings, we expect...
                    // The student2 (next on waitinglist) to be on the list, because he doesn't need to pay.
                    'usersonlist1' => 1,
                    'usersonwaitinglist1' => 3,
                    // So no tasks expected.
                    'taskcount1' => 0,
                    // Therefore (student2 already took the place), the external user can only book on the list.
                    'newuserresponse' => MOD_BOOKING_BO_COND_ONWAITINGLIST,
                    // So no tasks expected.
                    'messagecount' => 0,
                    // Student 2 booking answer waitinglist expected value.
                    'student2waitinglistvalue' => MOD_BOOKING_STATUSPARAM_BOOKED,
                    // Student 2 booking answer json expected value after rule execution.
                    'student2bajsonvalue' => null,
                    // Student 2 booking condition after rule execution.
                    'student2condtionvalue' => MOD_BOOKING_BO_COND_ALREADYBOOKED,
                    'student2bajsonvalue2' => null,
                    'student3bajsonvalue2' => null,
                ],
            ],
            'second_user_with_price_no_confirmationlist' => [
                [
                    'secondprice' => 10,
                    'student2settings' => ['profile_field_pricecat' => 'secondprice'],
                    'bookseconduser' => false,
                    'waitforconfirmation' => 0,
                    'student5settings' => [],
                    'confirmationonnotification' => 0, // It can not be any other value when waitforconfirmation is equal to zero.
                ],
                [
                    // Since user has to pay, we expect no one booked and user still on waitinglist.
                    'usersonlist1' => 0,
                    'usersonwaitinglist1' => 3,
                    'taskcount1' => 2, // Tasks expected.
                    // Therefore new user can book with price.
                    'newuserresponse' => MOD_BOOKING_BO_COND_PRICEISSET,
                    // Tasks expected.
                    'messagecount' => 1,
                    // Student 2 booking answer waitinglist expected value.
                    'student2waitinglistvalue' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
                    // Student 2 booking answer json expected value after rule execution.
                    'student2bajsonvalue' => null,
                    // Student 2 booking condition after rule execution.
                    'student2condtionvalue' => MOD_BOOKING_BO_COND_PRICEISSET,
                    'student2bajsonvalue2' => null,
                    'student3bajsonvalue2' => null,
                ],
            ],
            'second_user_with_price_and_confirmationlist_for_waitinglist' => [
                [
                    'secondprice' => 10,
                    'student2settings' => ['profile_field_pricecat' => 'secondprice'],
                    'bookseconduser' => false,
                    'waitforconfirmation' => 2,
                    'student5settings' => [],
                    'confirmationonnotification' => 0, // Users will not be notified.
                ],
                [
                    // Since user has to pay, we expect no one booked and user still on waitinglist.
                    'usersonlist1' => 0,
                    'usersonwaitinglist1' => 4,
                    // Tasks expected.
                    'taskcount1' => 2,
                    // With confirmation only on waitinglist, new user is blocked from booking and put on waitinglist.
                    'newuserresponse' => MOD_BOOKING_BO_COND_ONWAITINGLIST,
                    // Tasks expected.
                    'messagecount' => 1,
                    // Student 2 booking answer waitinglist expected value.
                    'student2waitinglistvalue' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
                    // Student 2 booking answer json value after rule execution.
                    'student2bajsonvalue' => null,
                    // Student 2 booking condition after rule execution.
                    'student2condtionvalue' => MOD_BOOKING_BO_COND_ONWAITINGLIST,
                    'student2bajsonvalue2' => null,
                    'student3bajsonvalue2' => null,

                ],
            ],
            'second_user_with_price_and_confirmationlist_for_waitinglist_and_with_confirmationonnotification1' => [
                [
                    'secondprice' => 10,
                    'student2settings' => ['profile_field_pricecat' => 'secondprice'],
                    'bookseconduser' => false,
                    'waitforconfirmation' => 2,
                    'student5settings' => [],
                    // Users will be notified and json value for the first prson on waiting list will be null.
                    'confirmationonnotification' => 1,
                ],
                [
                    // Since user has to pay, we expect no one booked and user still on waitinglist.
                    'usersonlist1' => 0,
                    'usersonwaitinglist1' => 4,
                    // Tasks expected.
                    'taskcount1' => 2,
                    // With confirmation only on waitinglist, new user is blocked from booking and put on waitinglist.
                    'newuserresponse' => MOD_BOOKING_BO_COND_ONWAITINGLIST,
                    // Tasks expected.
                    'messagecount' => 1,
                    // Student 2 booking answer waitinglist expected value.
                    'student2waitinglistvalue' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
                    // Student 2 booking answer json value after rule execution.
                    'student2bajsonvalue' => 'json',
                    // Student 2 booking condition after rule execution.
                    'student2condtionvalue' => MOD_BOOKING_BO_COND_PRICEISSET,
                    'student2bajsonvalue2' => 'json',
                    'student3bajsonvalue2' => 'json',
                ],

            ],
            'second_user_with_price_and_confirmationlist_for_waitinglist_and_with_confirmationonnotification2' => [
                [
                    'secondprice' => 10,
                    'student2settings' => ['profile_field_pricecat' => 'secondprice'],
                    'bookseconduser' => false,
                    'waitforconfirmation' => 2,
                    'student5settings' => [],
                    // Users will be notified and json value for the first prson on waiting list will be null.
                    'confirmationonnotification' => 2,
                ],
                [
                    // Since user has to pay, we expect no one booked and user still on waitinglist.
                    'usersonlist1' => 0,
                    'usersonwaitinglist1' => 4,
                    // Tasks expected.
                    'taskcount1' => 2,
                    // With confirmation only on waitinglist, new user is blocked from booking and put on waitinglist.
                    'newuserresponse' => MOD_BOOKING_BO_COND_ONWAITINGLIST,
                    // Tasks expected.
                    'messagecount' => 1,
                    // Student 2 booking answer waitinglist expected value.
                    'student2waitinglistvalue' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
                    // Student 2 booking answer json value after rule execution.
                    'student2bajsonvalue' => 'json',
                    // Student 2 booking condition after rule execution.
                    'student2condtionvalue' => MOD_BOOKING_BO_COND_ONWAITINGLIST,
                    'student2bajsonvalue2' => null,
                    'student3bajsonvalue2' => 'json',
                ],
            ],
        ];
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
            'allowupdate' => 1,
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
     * This function will run in a separate process and simulate ongoing booking...
     * Simultaneously to the running test.
     *
     * @param int $optionid
     *
     * @return void
     *
     */
    public static function ongoing_booking(int $optionid) {

    }
}
