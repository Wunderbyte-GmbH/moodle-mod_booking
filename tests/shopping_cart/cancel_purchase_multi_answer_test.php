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

namespace mod_booking;

use local_shopping_cart\shopping_cart;
use local_shopping_cart\shopping_cart_credits;
use mod_booking\tests\booking_advanced_testcase;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Cancelling one of several purchases must leave the user's other bookings on that option alone.
 *
 * With "book again" a user can hold more than one booking on the same option, each paid for
 * separately. The cart's cancel callback only learns the option, so without the purchase link
 * stamped onto the answer it cancelled every booking the user held and refunded a single purchase.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\shopping_cart\service_provider::cancel_purchase
 * @covers     \mod_booking\shopping_cart\service_provider::successful_checkout
 */
final class cancel_purchase_multi_answer_test extends booking_advanced_testcase {
    /** @var float price of the option */
    private const PRICE = 45.0;

    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Cleanup.
     */
    public function tearDown(): void {
        parent::tearDown();
        if (class_exists('local_shopping_cart\local\cartstore')) {
            \local_shopping_cart\local\cartstore::reset();
        }
    }

    /**
     * Cancelling the first of two purchases keeps the second booking and refunds only one price.
     *
     * @runInSeparateProcess
     * @return void
     */
    public function test_cancelling_one_purchase_keeps_the_other_booking(): void {
        global $DB;

        if (!class_exists('local_shopping_cart\shopping_cart')) {
            $this->markTestSkipped('local_shopping_cart is not installed.');
        }

        [$optionid, $userid] = $this->create_priced_option();

        $firsthistoryid = $this->purchase($optionid, $userid);
        $secondhistoryid = $this->purchase($optionid, $userid);
        $this->assertNotSame($firsthistoryid, $secondhistoryid, 'Two separate purchases are needed.');

        $answers = $this->booked_answers($optionid, $userid);
        $this->assertCount(2, $answers, 'Book again must have produced two booked answers.');
        $identifiers = array_map(static fn(\stdClass $a): int => (int)$a->purchaseidentifier, $answers);
        $this->assertNotContains(0, $identifiers, 'Every purchased answer must carry its purchase reference.');
        $this->assertSame($identifiers, array_unique($identifiers), 'The two answers must reference different purchases.');

        // Cancel the FIRST purchase, as the user themselves.
        $firstidentifier = (int)$DB->get_field('local_shopping_cart_history', 'identifier', ['id' => $firsthistoryid]);
        $cancelledbaid = (int)array_search($firstidentifier, $identifiers, true);
        $this->setUser($userid);
        $result = shopping_cart::cancel_purchase($optionid, 'option', $userid, 'mod_booking', $firsthistoryid);
        $this->assertEquals(1, $result['success'], 'The cancellation should succeed.');

        // Exactly the cancelled purchase's booking is gone; the other one survives.
        $remaining = $this->booked_answers($optionid, $userid);
        $this->assertCount(1, $remaining, 'Cancelling one purchase must not delete the other booking.');
        $survivor = reset($remaining);
        $this->assertNotEquals($cancelledbaid, (int)$survivor->id);
        $this->assertNotEquals($firstidentifier, (int)$survivor->purchaseidentifier);

        // And only one price came back.
        $this->assertEqualsWithDelta(
            self::PRICE,
            (float) shopping_cart_credits::get_balance($userid)[0],
            0.011,
            'Only the cancelled purchase may be refunded.'
        );
    }

    /**
     * The user's booked answers on this option.
     *
     * @param int $optionid
     * @param int $userid
     * @return array<int, \stdClass>
     */
    private function booked_answers(int $optionid, int $userid): array {
        global $DB;

        booking_option::purge_cache_for_answers($optionid);
        singleton_service::destroy_instance();

        return $DB->get_records('booking_answers', [
            'optionid' => $optionid,
            'userid' => $userid,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
        ], 'id');
    }

    /**
     * Buy the option once (cash at the cashier's office) and return the history id.
     *
     * @param int $optionid
     * @param int $userid
     * @return int local_shopping_cart_history id
     */
    private function purchase(int $optionid, int $userid): int {
        global $DB;

        $this->setAdminUser();
        shopping_cart::delete_all_items_from_cart($userid);
        shopping_cart::buy_for_user($userid);
        $added = shopping_cart::add_item_to_cart('mod_booking', 'option', $optionid, -1);
        $this->assertEquals(1, (int)$added['success'], 'The option must be addable to the cart.');
        $payment = shopping_cart::confirm_payment($userid, LOCAL_SHOPPING_CART_PAYMENT_METHOD_CASHIER_CASH);
        $this->assertEquals(1, (int)$payment['status'], 'The payment must be confirmed.');

        booking_option::purge_cache_for_answers($optionid);
        singleton_service::destroy_instance();

        $history = $DB->get_records('local_shopping_cart_history', [
            'itemid' => $optionid,
            'userid' => $userid,
            'paymentstatus' => LOCAL_SHOPPING_CART_PAYMENT_SUCCESS,
        ], 'id DESC', 'id', 0, 1);
        $this->assertNotEmpty($history, 'The purchase must have been recorded.');

        return (int)reset($history)->id;
    }

    /**
     * A priced option that may be booked more than once by the same user.
     *
     * @return array{0:int, 1:int} optionid, userid
     */
    private function create_priced_option(): array {
        global $DB;

        // The cart forbids user cancellation by default (cancelationfee = -1).
        set_config('cancelationfee', 0, 'local_shopping_cart');

        $course = self::getDataGenerator()->create_course();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance(['course' => $course->id]);
        $student = self::getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $plugingenerator->create_pricecategory([
            'ordernum' => 1,
            'identifier' => 'default',
            'name' => 'Default',
            'defaultvalue' => self::PRICE,
            'pricecatsortorder' => 1,
            'disabled' => 0,
        ]);

        $option = $plugingenerator->create_option((object)[
            'bookingid' => $booking->id,
            'text' => 'Twice purchasable option',
            'course' => $course->id,
            'maxanswers' => 20,
            'useprice' => 1,
            // Book again - without it the second purchase is blocked as already booked.
            'multiplebookings' => 1,
            'importing' => 1,
        ]);
        $optionid = (int)$option->id;

        $DB->insert_record('booking_prices', (object)[
            'itemid' => $optionid,
            'area' => 'option',
            'pricecategoryidentifier' => 'default',
            'price' => self::PRICE,
            'currency' => 'EUR',
        ]);

        purge_all_caches();

        return [$optionid, (int)$student->id];
    }
}
