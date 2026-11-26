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

use cache;
use local_shopping_cart\shopping_cart;
use local_shopping_cart\shopping_cart_credits;
use mod_booking\external\release_slots;
use mod_booking\external\save_slot_selection;
use mod_booking\local\slotbooking\slot_dto;
use mod_booking\tests\booking_advanced_testcase;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Full cancellation of a slot purchase from the purchase history must respect the per-slot deadline.
 *
 * The cancel button on the option already hides the full cancellation while a slot is locked. The
 * button in the purchase history and the cancel web service asked the booking only about the option,
 * so a client could cancel (and get refunded) a purchase with a slot inside the move/cancel deadline.
 * The check now runs per purchase, via the purchase identifier (GH-1686).
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\shopping_cart\service_provider::allowed_to_cancel
 * @covers     \local_shopping_cart\shopping_cart::allowed_to_cancel_for_item
 */
final class slot_full_cancel_deadline_test extends booking_advanced_testcase {
    /** @var float Price of a single slot. */
    private const SLOT_PRICE = 30.0;

    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * A purchase with a locked slot cannot be cancelled as a whole by the client. Another purchase
     * of the same option without a locked slot still can, the free slot of the locked purchase can
     * still be released on its own, and the cashier can still cancel everything.
     *
     * @return void
     */
    public function test_full_cancel_of_a_purchase_respects_the_slot_deadline(): void {
        global $DB;

        if (!class_exists('local_shopping_cart\shopping_cart')) {
            $this->markTestSkipped('local_shopping_cart is not installed.');
        }

        [$optionid, $userid] = $this->create_priced_slot_option();

        $slots = slot_dto::build_picker_slots($optionid, $userid);
        usort($slots, fn($a, $b) => (int)$a['start'] <=> (int)$b['start']);
        $first = $slots[0];
        $last = $slots[count($slots) - 1];
        $secondlast = $slots[count($slots) - 2];

        // Purchase A holds the first and the last slot, purchase B one slot near the end.
        $historya = $this->buy_slots_in_one_purchase($optionid, $userid, [$first['key'], $last['key']]);
        $historyb = $this->buy_slots_in_one_purchase($optionid, $userid, [$secondlast['key']]);
        $this->assertNotEquals($historya->identifier, $historyb->identifier, 'Two separate purchases are needed.');

        // Lock only the first slot: the deadline reaches one day past its start. The slots at the end
        // are three days later, so they stay free.
        $deadlineminutes = intdiv((int)$first['start'] - time(), MINSECS) + 24 * 60;
        $DB->set_field('booking_slot_config', 'change_deadline_minutes', $deadlineminutes, ['optionid' => $optionid]);

        $this->setUser($userid);

        // Purchase A has a locked slot: no full cancellation for the client.
        $this->assertFalse(
            shopping_cart::allowed_to_cancel((int)$historya->id, $optionid, 'option', $userid),
            'A purchase with a locked slot must not be cancellable by the client.'
        );
        $result = shopping_cart::cancel_purchase($optionid, 'option', $userid, 'mod_booking', (int)$historya->id);
        $this->assertEquals(0, $result['success'], 'The cancel web service must refuse it as well.');
        $answera = $this->answer_of_purchase($optionid, $userid, $historya);
        $this->assertEquals(MOD_BOOKING_STATUSPARAM_BOOKED, (int)$answera->waitinglist, 'The booking must stay.');
        $this->assertEqualsWithDelta(0.0, $this->current_credit($userid), 0.001, 'Nothing may be refunded.');

        // Purchase B on the same option has only free slots: it is checked on its own and stays cancellable.
        $this->assertTrue(
            shopping_cart::allowed_to_cancel((int)$historyb->id, $optionid, 'option', $userid),
            'A purchase without a locked slot must stay cancellable, even if another purchase is locked.'
        );

        // The free slot of purchase A can still be released on its own.
        $released = release_slots::execute($optionid, (int)$answera->id, json_encode([$last['key']]), '');
        $this->assertSame(1, (int)$released['released'], 'The free slot must be released.');
        $this->assertSame(1, (int)$released['remaining'], 'The locked slot must stay booked.');
        $this->assertEqualsWithDelta(self::SLOT_PRICE, $this->current_credit($userid), 0.011, 'Only the free slot is refunded.');

        // The cashier is not bound to the deadline.
        $this->setAdminUser();
        $result = shopping_cart::cancel_purchase($optionid, 'option', $userid, 'mod_booking', (int)$historya->id);
        $this->assertEquals(1, $result['success'], 'The cashier must still be able to cancel the purchase.');
    }

    /**
     * Buy the given slots in one purchase, paid by the cashier.
     *
     * @param int $optionid
     * @param int $userid
     * @param array $slotkeys keys of the slots to buy
     * @return stdClass the history record of the purchase
     */
    private function buy_slots_in_one_purchase(int $optionid, int $userid, array $slotkeys): stdClass {
        global $DB;

        save_slot_selection::execute($optionid, $userid, json_encode(array_values($slotkeys)));
        shopping_cart::delete_all_items_from_cart($userid);
        shopping_cart::buy_for_user($userid);
        shopping_cart::add_item_to_cart('mod_booking', 'option', $optionid, -1);
        shopping_cart::confirm_payment($userid, LOCAL_SHOPPING_CART_PAYMENT_METHOD_CASHIER_CASH);

        booking_option::purge_cache_for_answers($optionid);
        singleton_service::destroy_instance();

        $records = $DB->get_records('local_shopping_cart_history', [
            'componentname' => 'mod_booking',
            'area' => 'option',
            'itemid' => $optionid,
            'userid' => $userid,
            'paymentstatus' => LOCAL_SHOPPING_CART_PAYMENT_SUCCESS,
        ], 'id DESC', '*', 0, 1);
        $this->assertNotEmpty($records, 'The purchase must be in the history.');

        return reset($records);
    }

    /**
     * The booking answer that belongs to a purchase.
     *
     * @param int $optionid
     * @param int $userid
     * @param stdClass $history the history record of the purchase
     * @return stdClass
     */
    private function answer_of_purchase(int $optionid, int $userid, stdClass $history): stdClass {
        global $DB;

        return $DB->get_record('booking_answers', [
            'optionid' => $optionid,
            'userid' => $userid,
            'purchaseidentifier' => $history->identifier,
        ], '*', MUST_EXIST);
    }

    /**
     * Current shopping cart credit balance of a user.
     *
     * @param int $userid
     * @return float
     */
    private function current_credit(int $userid): float {
        [$credit] = shopping_cart_credits::get_balance($userid);
        return (float)$credit;
    }

    /**
     * A priced slot option with "Allow rebooking", self-cancellation and several purchases per user.
     *
     * @return array{0:int, 1:int} optionid, userid
     */
    private function create_priced_slot_option(): array {
        global $DB;

        $course = self::getDataGenerator()->create_course();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance(['course' => $course->id]);
        $student = self::getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $record = [
            'bookingid' => $booking->id,
            'text' => 'Slot full cancel deadline ' . uniqid('', true),
            'course' => $course->id,
            'optiontype' => MOD_BOOKING_OPTIONTYPE_SLOTBOOKING,
            'maxanswers' => 20,
            'useprice' => 1,
            'slot_enabled' => 1,
            'slot_type' => 'fixed',
            'slot_duration_minutes' => 60,
            'slot_interval_minutes' => 60,
            'slot_opening_time' => '09:00',
            'slot_closing_time' => '13:00',
            'slot_valid_from' => strtotime('2050-01-07 00:00:00 UTC'),
            'slot_valid_until' => strtotime('2050-01-10 23:59:59 UTC'),
            'slot_max_participants_per_slot' => 3,
            'slot_max_slots_per_user' => 4,
            'slot_booking_view_mode' => 'list',
            'slot_allow_self_rebooking' => 1,
            'slot_change_deadline_minutes' => '',
        ];
        for ($day = 1; $day <= 7; $day++) {
            $record['slot_day_' . $day] = 1;
        }

        $option = $plugingenerator->create_option((object)$record);
        $optionid = (int)$option->id;
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        // Allow a second purchase of the same option right away.
        $json = json_decode($DB->get_field('booking_options', 'json', ['id' => $optionid]) ?: '{}');
        $json->multiplebookings = 1;
        $json->allowtobookagainafter = 0;
        $DB->set_field('booking_options', 'json', json_encode($json), ['id' => $optionid]);
        cache::make('mod_booking', 'bookingoptionsettings')->delete($optionid);

        // Self-cancellation on the instance, and the cart allows users to cancel (no fee).
        $DB->set_field('booking', 'cancancelbook', 1, ['id' => $booking->id]);
        cache::make('mod_booking', 'cachedbookinginstances')->delete((int)$settings->cmid);
        set_config('cancelationfee', 0, 'local_shopping_cart');

        $plugingenerator->create_pricecategory([
            'ordernum' => 1,
            'identifier' => 'default',
            'name' => 'Default',
            'defaultvalue' => 0,
            'pricecatsortorder' => 1,
            'disabled' => 0,
        ]);
        $DB->insert_record('booking_prices', (object)[
            'itemid' => $optionid,
            'area' => 'option',
            'pricecategoryidentifier' => 'default',
            'price' => 0,
            'currency' => 'EUR',
        ]);

        // Every slot costs SLOT_PRICE.
        $ruleid = (int)$DB->insert_record('booking_slot_rule', (object)[
            'optionid' => $optionid,
            'ruletype' => 'price',
            'priority' => 1,
            'activefrom' => 0,
            'activeuntil' => 0,
            'weekdays' => '',
            'timerangestart' => '09:00',
            'timerangeend' => '13:00',
            'timecreated' => time(),
        ]);
        $DB->insert_record('booking_slot_rule_price', (object)[
            'ruleid' => $ruleid,
            'pricecategoryidentifier' => 'default',
            'mode' => 'delta',
            'value' => self::SLOT_PRICE,
            'currency' => 'EUR',
        ]);

        booking_option::purge_cache_for_answers($optionid);
        singleton_service::destroy_instance();

        return [$optionid, (int)$student->id];
    }
}
