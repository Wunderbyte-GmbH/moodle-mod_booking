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
 * Tests for the cancellation date of booking options without dates.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use local_shopping_cart\local\cartstore;
use local_shopping_cart\shopping_cart;
use local_shopping_cart\shopping_cart_history;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * A fixed cancellation date of the booking instance ("Cancelling is only possible until certain date")
 * does not depend on the dates of a booking option. It has to be applied to booking options without
 * dates (e.g. subscriptions) as well, otherwise their purchases can be cancelled forever.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class cancel_until_date_without_dates_test extends booking_advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        singleton_service::destroy_instance();
    }

    /**
     * The cancellation date of an option for the different cancel modes of the instance.
     *
     * @covers \mod_booking\booking_option::return_cancel_until_date
     * @dataProvider cancel_until_date_provider
     * @param array $instance cancel settings of the booking instance, 'FIXED' stands for the fixed date
     * @param array $option fields of the booking option, 'FIXED' / 'OWN' stand for the dates of the test
     * @param string $expected NONE, FIXED or OWN
     */
    public function test_return_cancel_until_date(array $instance, array $option, string $expected): void {
        $dates = [
            'NONE' => 0,
            'FIXED' => strtotime('+ 10 days'),
            'OWN' => strtotime('+ 20 days'),
            'START' => strtotime('+ 30 days'),
            'END' => strtotime('+ 31 days'),
        ];
        $replace = fn($value) => is_string($value) && isset($dates[$value]) ? $dates[$value] : $value;

        $optionid = $this->create_option(array_map($replace, $instance), array_map($replace, $option));

        $this->assertSame($dates[$expected], (int)booking_option::return_cancel_until_date($optionid));
    }

    /**
     * Data provider for test_return_cancel_until_date.
     *
     * @return array
     */
    public static function cancel_until_date_provider(): array {
        $fixed = ['cancelrelativedate' => MOD_BOOKING_CANCANCELBOOK_ABSOLUTE, 'allowupdatetimestamp' => 'FIXED'];
        $relative = ['cancelrelativedate' => MOD_BOOKING_CANCANCELBOOK_RELATIVE, 'allowupdatedays' => 0];
        $unlimited = ['cancelrelativedate' => MOD_BOOKING_CANCANCELBOOK_UNLIMITED];
        $withdates = [
            'optiondateid_0' => '0',
            'daystonotify_0' => '0',
            'coursestarttime_0' => 'START',
            'courseendtime_0' => 'END',
        ];
        $owndate = ['canceluntilcheckbox' => 1, 'canceluntil' => 'OWN'];

        return [
            'fixed date, option without dates (the bug)' => [$fixed, [], 'FIXED'],
            'fixed date, option with dates' => [$fixed, $withdates, 'FIXED'],
            'fixed date, self-learning course' => [$fixed, ['selflearningcourse' => 1, 'duration' => 86400], 'FIXED'],
            'fixed date, own date of the option wins' => [$fixed, $owndate, 'OWN'],
            'relative date, option without dates has no start to count from' => [$relative, [], 'NONE'],
            'relative date, option with dates' => [$relative, $withdates, 'START'],
            'unlimited, option without dates' => [$unlimited, [], 'NONE'],
        ];
    }

    /**
     * The fixed cancellation date of the instance is stored with the purchase of an option without dates,
     * so shopping cart refuses the cancellation once the date has passed.
     *
     * @covers \mod_booking\booking_option::return_cancel_until_date
     * @covers \mod_booking\booking_option_settings::return_booking_option_information
     * @covers \local_shopping_cart\shopping_cart::allowed_to_cancel
     */
    public function test_fixed_date_is_stored_with_purchase_of_option_without_dates(): void {
        set_config('cancelationfee', 0, 'local_shopping_cart');

        $fixeddate = strtotime('+ 10 days');
        $student = $this->getDataGenerator()->create_user();

        $optionid = $this->create_option(
            ['cancelrelativedate' => MOD_BOOKING_CANCANCELBOOK_ABSOLUTE, 'allowupdatetimestamp' => $fixeddate],
            ['useprice' => 1]
        );

        $historyitem = $this->buy_option($optionid, $student->id);
        $this->assertSame($fixeddate, (int)$historyitem->canceluntil);

        $this->setUser($student);
        $this->assertTrue(
            shopping_cart::allowed_to_cancel((int)$historyitem->id, $optionid, 'option', (int)$student->id),
            'Before the fixed date the user can cancel.'
        );

        // The date passes: we move it into the past for this purchase, like time going by.
        global $DB;
        $DB->set_field('local_shopping_cart_history', 'canceluntil', time() - 10, ['id' => $historyitem->id]);
        $this->assertFalse(
            shopping_cart::allowed_to_cancel((int)$historyitem->id, $optionid, 'option', (int)$student->id),
            'After the fixed date the user cannot cancel anymore.'
        );
    }

    /**
     * Creates a booking instance with the given cancel settings and one booking option in it.
     *
     * @param array $instancedata cancel settings of the booking instance
     * @param array $optiondata fields of the booking option
     * @return int id of the booking option
     */
    private function create_option(array $instancedata, array $optiondata): int {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $bookingmanager = $this->getDataGenerator()->create_user();

        $bdata = array_merge([
            'name' => 'Test Booking cancel until',
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
            'showviews' => ['mybooking,myoptions,showall,showactive,myinstitution'],
            'course' => $course->id,
            'bookingmanager' => $bookingmanager->username,
            'cancancelbook' => 1,
        ], $instancedata);
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        if (!empty($optiondata['useprice'])) {
            $plugingenerator->create_pricecategory((object)[
                'ordernum' => 1,
                'name' => 'default',
                'identifier' => 'default',
                'defaultvalue' => 100,
                'pricecatsortorder' => 1,
            ]);
        }

        $record = (object)array_merge([
            'bookingid' => $booking->id,
            'text' => 'Option for cancel until',
            'courseid' => 0,
            'maxanswers' => 5,
            'importing' => 1,
        ], $optiondata);
        $option = $plugingenerator->create_option($record);

        // The settings have to be read freshly from the database, like in a new request.
        singleton_service::destroy_instance();

        return (int)$option->id;
    }

    /**
     * The cashier buys the option on behalf of the user via shopping cart.
     *
     * @param int $optionid
     * @param int $userid
     * @return stdClass the shopping cart history record
     */
    private function buy_option(int $optionid, int $userid): stdClass {
        $this->setAdminUser();
        shopping_cart::delete_all_items_from_cart($userid);
        shopping_cart::buy_for_user($userid);
        cartstore::instance($userid);
        shopping_cart::add_item_to_cart('mod_booking', 'option', $optionid, -1);
        shopping_cart::confirm_payment($userid, LOCAL_SHOPPING_CART_PAYMENT_METHOD_CASHIER_CASH);

        singleton_service::destroy_booking_answers($optionid);

        $historyitem = shopping_cart_history::get_most_recent_historyitem('mod_booking', 'option', $optionid, $userid);
        $this->assertNotEmpty($historyitem, 'The purchase is stored in shopping cart history.');
        return $historyitem;
    }
}
