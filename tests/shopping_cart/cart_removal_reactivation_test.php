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
use mod_booking\tests\booking_advanced_testcase;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Removing an option from the cart restores exactly the answers its reservation demoted (Wunderbyte-GmbH/Wunderbyte-GmbH#2544).
 *
 * With "book again" putting the option into the cart demotes the user's booked answers to "previously booked".
 * Removing it again must bring back exactly those answers - and nothing at all when no reservation was removed
 * (e.g. a cart cleanup after a successful checkout). Before, every removal reactivated the previously booked
 * answer modified last, so an old, replaced license came back next to the paid renewal (showroom, 2026-10-01/02).
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @author     Georg Maißer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\booking_option::user_delete_response
 * @covers     \mod_booking\booking_option::user_submit_response
 * @covers     \mod_booking\shopping_cart\service_provider::unload_cartitem
 */
final class cart_removal_reactivation_test extends booking_advanced_testcase {
    /** @var float price of the option */
    private const PRICE = 280.0;

    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        if (!class_exists('local_shopping_cart\shopping_cart')) {
            $this->markTestSkipped('local_shopping_cart is not installed.');
        }
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
     * Trigger case: after a paid renewal the cart item is removed once more without any reservation (cart cleanup).
     * The old license must stay previously booked; the renewal stays the only booked answer.
     */
    public function test_cart_removal_without_reservation_after_renewal_changes_nothing(): void {
        [$optionid, $userid] = $this->create_priced_option();
        $old = $this->purchase($optionid, $userid);
        $renewal = $this->purchase($optionid, $userid);
        $this->assertSame([$old => 6, $renewal => 0], $this->states($optionid, $userid));

        $this->remove_from_cart($optionid, $userid);

        $this->assertSame([$old => 6, $renewal => 0], $this->states($optionid, $userid));
    }

    /**
     * Book again, then remove the option from the cart: the answer that was booked before comes back - even if
     * the old, previously booked license was modified more recently (showroom: the old answer had been touched
     * after the renewal and was reactivated instead of the paid one).
     */
    public function test_removing_a_rebooking_from_the_cart_restores_the_demoted_answer(): void {
        global $DB;
        [$optionid, $userid] = $this->create_priced_option();
        $old = $this->purchase($optionid, $userid);
        $renewal = $this->purchase($optionid, $userid);
        // The old answer was modified after the renewal (newest timemodified of all answers).
        $DB->set_field('booking_answers', 'timemodified', time() + 3600, ['id' => $old]);

        $reserved = $this->add_to_cart($optionid, $userid);
        $this->assertSame([$old => 6, $renewal => 6, $reserved => 2], $this->states($optionid, $userid));

        $this->remove_from_cart($optionid, $userid);

        $this->assertSame([$old => 6, $renewal => 0], $this->states($optionid, $userid));
    }

    /**
     * Two booked answers (state left behind by the old behaviour) are both demoted by a reservation, and both come
     * back when it is removed - the removal restores the state before the reservation, nothing else.
     */
    public function test_removing_a_reservation_restores_every_answer_it_demoted(): void {
        global $DB;
        [$optionid, $userid] = $this->create_priced_option();
        $old = $this->purchase($optionid, $userid);
        $renewal = $this->purchase($optionid, $userid);
        $DB->set_field('booking_answers', 'waitinglist', MOD_BOOKING_STATUSPARAM_BOOKED, ['id' => $old]);

        $reserved = $this->add_to_cart($optionid, $userid);
        $this->assertSame([$old => 6, $renewal => 6, $reserved => 2], $this->states($optionid, $userid));

        $this->remove_from_cart($optionid, $userid);

        $this->assertSame([$old => 0, $renewal => 0], $this->states($optionid, $userid));
    }

    /**
     * A reservation made before this change carries no list of demoted answers: removing it keeps the old
     * behaviour and reactivates the previously booked answer modified last (original reason of the reactivation).
     */
    public function test_reservation_without_demoted_list_falls_back_to_the_latest_previous_answer(): void {
        global $DB;
        [$optionid, $userid] = $this->create_priced_option();
        $old = $this->purchase($optionid, $userid);
        $reserved = $this->add_to_cart($optionid, $userid);
        $this->assertSame([$old => 6, $reserved => 2], $this->states($optionid, $userid));
        // Shape of a reservation written before the change: no demotedanswerids in its json.
        $json = json_decode((string) $DB->get_field('booking_answers', 'json', ['id' => $reserved]) ?: '{}');
        unset($json->demotedanswerids);
        $DB->set_field('booking_answers', 'json', json_encode($json), ['id' => $reserved]);

        $this->remove_from_cart($optionid, $userid);

        $this->assertSame([$old => 0], $this->states($optionid, $userid));
    }

    /**
     * First purchase put into the cart and removed again: no answer is left and nothing is reactivated.
     */
    public function test_removing_a_first_reservation_leaves_no_answer(): void {
        [$optionid, $userid] = $this->create_priced_option();
        $this->add_to_cart($optionid, $userid);

        $this->remove_from_cart($optionid, $userid);

        $this->assertSame([], $this->states($optionid, $userid));
    }

    /**
     * A single booking without any rebooking is not touched by a cart removal.
     */
    public function test_cart_removal_keeps_a_single_booking(): void {
        [$optionid, $userid] = $this->create_priced_option();
        $booked = $this->purchase($optionid, $userid);

        $this->remove_from_cart($optionid, $userid);

        $this->assertSame([$booked => 0], $this->states($optionid, $userid));
    }

    /**
     * The completed book-again flow still ends with exactly one booked answer.
     */
    public function test_checkout_after_rebooking_leaves_one_booked_answer(): void {
        [$optionid, $userid] = $this->create_priced_option();
        $old = $this->purchase($optionid, $userid);
        $renewal = $this->purchase($optionid, $userid);
        $third = $this->purchase($optionid, $userid);

        $this->assertSame([$old => 6, $renewal => 6, $third => 0], $this->states($optionid, $userid));
    }

    /**
     * Waitinglist status of every non-deleted answer of the user on the option, by answer id.
     *
     * @param int $optionid
     * @param int $userid
     * @return array<int, int>
     */
    private function states(int $optionid, int $userid): array {
        global $DB;
        booking_option::purge_cache_for_answers($optionid);
        singleton_service::destroy_instance();
        $records = $DB->get_records_select(
            'booking_answers',
            'optionid = :optionid AND userid = :userid AND waitinglist <> :deleted',
            ['optionid' => $optionid, 'userid' => $userid, 'deleted' => MOD_BOOKING_STATUSPARAM_DELETED],
            'id',
            'id, waitinglist'
        );
        $states = [];
        foreach ($records as $record) {
            $states[(int) $record->id] = (int) $record->waitinglist;
        }
        return $states;
    }

    /**
     * Put the option into the user's cart (reservation) and return the reserved answer id.
     *
     * @param int $optionid
     * @param int $userid
     * @return int
     */
    private function add_to_cart(int $optionid, int $userid): int {
        global $DB;
        $this->setAdminUser();
        shopping_cart::buy_for_user($userid);
        $added = shopping_cart::add_item_to_cart('mod_booking', 'option', $optionid, -1);
        $this->assertEquals(1, (int) $added['success'], 'The option must be addable to the cart.');
        booking_option::purge_cache_for_answers($optionid);
        singleton_service::destroy_instance();
        return (int) $DB->get_field('booking_answers', 'id', [
            'optionid' => $optionid,
            'userid' => $userid,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_RESERVED,
        ], MUST_EXIST);
    }

    /**
     * Remove the option from the user's cart (whether it is in there or not).
     *
     * @param int $optionid
     * @param int $userid
     */
    private function remove_from_cart(int $optionid, int $userid): void {
        $this->setAdminUser();
        shopping_cart::buy_for_user($userid);
        shopping_cart::delete_item_from_cart('mod_booking', 'option', $optionid, $userid);
        booking_option::purge_cache_for_answers($optionid);
        singleton_service::destroy_instance();
    }

    /**
     * Buy the option once (cash at the cashier's office) and return the booked answer id.
     *
     * @param int $optionid
     * @param int $userid
     * @return int
     */
    private function purchase(int $optionid, int $userid): int {
        global $DB;
        $this->setAdminUser();
        shopping_cart::delete_all_items_from_cart($userid);
        shopping_cart::buy_for_user($userid);
        $added = shopping_cart::add_item_to_cart('mod_booking', 'option', $optionid, -1);
        $this->assertEquals(1, (int) $added['success'], 'The option must be addable to the cart.');
        $payment = shopping_cart::confirm_payment($userid, LOCAL_SHOPPING_CART_PAYMENT_METHOD_CASHIER_CASH);
        $this->assertEquals(1, (int) $payment['status'], 'The payment must be confirmed.');
        booking_option::purge_cache_for_answers($optionid);
        singleton_service::destroy_instance();
        return (int) $DB->get_field('booking_answers', 'id', [
            'optionid' => $optionid,
            'userid' => $userid,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
        ], MUST_EXIST);
    }

    /**
     * A priced option with "book again" and a student.
     *
     * @return array [optionid, userid]
     */
    private function create_priced_option(): array {
        global $DB;

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

        $option = $plugingenerator->create_option((object) [
            'bookingid' => $booking->id,
            'text' => 'Booking Pro License - 1 year',
            'course' => $course->id,
            'maxanswers' => 20,
            'useprice' => 1,
            'multiplebookings' => 1,
            'importing' => 1,
        ]);
        $optionid = (int) $option->id;

        $DB->insert_record('booking_prices', (object) [
            'itemid' => $optionid,
            'area' => 'option',
            'pricecategoryidentifier' => 'default',
            'price' => self::PRICE,
            'currency' => 'EUR',
        ]);

        purge_all_caches();

        return [$optionid, (int) $student->id];
    }
}
