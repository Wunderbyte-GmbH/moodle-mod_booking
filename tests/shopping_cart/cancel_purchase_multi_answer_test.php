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
 * With "book again" a user can buy the same option more than once, each time paid for
 * separately. Booking again does not add a second active booking: the earlier answer is
 * demoted to "previously booked" and the new purchase becomes the one booked answer. Every
 * answer still carries the purchase that paid for it, so the cart's cancel callback - which only
 * learns the option - can address exactly the purchase being cancelled instead of dropping all
 * of the user's answers and refunding a single purchase.
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
     * Cancelling the first of two purchases keeps the current booking and refunds only one price.
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
        $firstidentifier = (int)$DB->get_field('local_shopping_cart_history', 'identifier', ['id' => $firsthistoryid]);
        $secondidentifier = (int)$DB->get_field('local_shopping_cart_history', 'identifier', ['id' => $secondhistoryid]);
        $this->assertNotEmpty($firstidentifier, 'The first purchase must carry an identifier.');
        $this->assertNotEmpty($secondidentifier, 'The second purchase must carry an identifier.');
        $this->assertNotSame($firstidentifier, $secondidentifier, 'The two purchases must have different identifiers.');

        // Booking again demotes the first answer: one booked, one previously booked.
        $booked = $this->answers_with_status($optionid, $userid, MOD_BOOKING_STATUSPARAM_BOOKED);
        $this->assertCount(1, $booked, 'Book again must leave exactly one booked answer.');
        $previouslybooked = $this->answers_with_status($optionid, $userid, MOD_BOOKING_STATUSPARAM_PREVIOUSLYBOOKED);
        $this->assertCount(1, $previouslybooked, 'Book again must demote the first answer to previously booked.');

        // Each answer references the purchase that paid for it.
        $current = reset($booked);
        $previous = reset($previouslybooked);
        $this->assertNotEquals((int)$previous->id, (int)$current->id);
        $this->assertSame($firstidentifier, (int)$previous->purchaseidentifier, 'The first answer belongs to the first purchase.');
        $this->assertSame(
            $secondidentifier,
            (int)$current->purchaseidentifier,
            'The booked answer belongs to the second purchase.'
        );

        // Cancel the FIRST purchase, as the user themselves.
        $this->setUser($userid);
        $result = shopping_cart::cancel_purchase($optionid, 'option', $userid, 'mod_booking', $firsthistoryid);
        $this->assertEquals(1, $result['success'], 'The cancellation should succeed.');

        // The current booking is untouched; the first answer is still not an active booking.
        $remaining = $this->answers_with_status($optionid, $userid, MOD_BOOKING_STATUSPARAM_BOOKED);
        $this->assertCount(1, $remaining, 'Cancelling one purchase must not delete the other booking.');
        $survivor = reset($remaining);
        $this->assertEquals((int)$current->id, (int)$survivor->id, 'The booked answer must be the same row as before.');
        $this->assertSame($secondidentifier, (int)$survivor->purchaseidentifier);
        $this->assertNotEquals(
            MOD_BOOKING_STATUSPARAM_BOOKED,
            (int)$DB->get_field('booking_answers', 'waitinglist', ['id' => (int)$previous->id]),
            'The cancelled purchase must not become an active booking.'
        );

        // And only one price came back.
        $this->assertEqualsWithDelta(
            self::PRICE,
            (float) shopping_cart_credits::get_balance($userid)[0],
            0.011,
            'Only the cancelled purchase may be refunded.'
        );
    }

    /**
     * The user's answers on this option with the given waitinglist status.
     *
     * @param int $optionid
     * @param int $userid
     * @param int $status a MOD_BOOKING_STATUSPARAM_* value
     * @return array<int, \stdClass>
     */
    private function answers_with_status(int $optionid, int $userid, int $status): array {
        global $DB;

        booking_option::purge_cache_for_answers($optionid);
        singleton_service::destroy_instance();

        return $DB->get_records('booking_answers', [
            'optionid' => $optionid,
            'userid' => $userid,
            'waitinglist' => $status,
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
