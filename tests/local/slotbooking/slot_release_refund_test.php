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
use local_shopping_cart\shopping_cart_credits;
use local_shopping_cart\shopping_cart_history;
use mod_booking\external\release_slots;
use mod_booking\external\save_slot_selection;
use mod_booking\local\slotbooking\slot_answer;
use mod_booking\local\slotbooking\slot_dto;
use mod_booking\tests\booking_advanced_testcase;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Money side of the per-slot cancellation (the delete button on the booked slots list).
 *
 * Cancelling a paid slot one by one is a partial cancellation and therefore owes the user a
 * partial refund - the same one slot_update_service::apply() books when the move editor shrinks
 * a booking. This test buys three slots at 45.00 each, drops two of them through the
 * release_slots webservice the delete button calls, and asserts the user is credited 90.00.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\external\release_slots::execute
 * @covers     \mod_booking\local\slotbooking\slot_mover::release_self
 */
final class slot_release_refund_test extends booking_advanced_testcase {
    /** @var float price of a single slot */
    private const SLOT_PRICE = 45.0;

    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Dropping two of three purchased slots credits the user with both slot prices.
     *
     * @return void
     */
    public function test_releasing_two_of_three_paid_slots_credits_the_user(): void {
        global $DB;

        if (!class_exists('local_shopping_cart\shopping_cart')) {
            $this->markTestSkipped('local_shopping_cart is not installed.');
        }

        [$optionid, $userid] = $this->create_priced_slot_option();

        // Three slots of the same day, all at the same price.
        $slots = array_slice(slot_dto::build_picker_slots($optionid, $userid), 0, 3);
        $this->assertCount(3, $slots, 'Fixture must offer at least three bookable slots.');
        $this->assertEqualsWithDelta(
            self::SLOT_PRICE,
            (float)$slots[0]['price'],
            0.001,
            'Each slot must carry the configured price.'
        );

        // Book the three slots the way the booking form does: stage the selection, then buy. The
        // cart item price is taken from the reserved answer's slot data, so only this order writes
        // a purchase over the real slot total - which is what a partial refund is measured against.
        $keys = array_map(static fn(array $s): string => $s['key'], $slots);
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        save_slot_selection::execute($optionid, $userid, json_encode($keys));
        $plugingenerator->create_user_purchase(['optionid' => $optionid, 'userid' => $userid]);

        $baid = $this->booked_answer_id($optionid, $userid);
        $this->assertEqualsWithDelta(
            3 * self::SLOT_PRICE,
            $this->purchased_price($optionid, $userid),
            0.001,
            'The purchase must cover all three slots - a partial refund is capped at the price paid.'
        );

        $creditbefore = $this->current_credit($userid);

        // What the delete button on the booked slots list does: release two slots, keep one.
        $this->setUser($userid);
        $result = release_slots::execute(
            $optionid,
            $baid,
            json_encode([$slots[0]['key'], $slots[1]['key']]),
            ''
        );

        $this->assertSame(2, (int)$result['released']);
        $this->assertSame(1, (int)$result['remaining']);
        $this->assertFalse((bool)$result['cancelled'], 'One slot remains, so the booking stays.');
        $this->assertEqualsWithDelta(-2 * self::SLOT_PRICE, (float)$result['pricedelta'], 0.001);

        // The answer keeps exactly the third slot.
        $answer = $DB->get_record('booking_answers', ['id' => $baid], '*', MUST_EXIST);
        $remaining = array_map(
            static fn(array $s): string => $s['start'] . ':' . $s['end'],
            slot_answer::get_slot_data($answer)['slots']
        );
        $this->assertSame([$slots[2]['key']], $remaining);

        // The money side: two slots at 45.00 were given back.
        $this->assertEqualsWithDelta(
            $creditbefore + (2 * self::SLOT_PRICE),
            $this->current_credit($userid),
            0.001,
            'Releasing two paid slots must credit the user with both slot prices.'
        );
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
     * Id of the user's booked answer on this option.
     *
     * @param int $optionid
     * @param int $userid
     * @return int
     */
    private function booked_answer_id(int $optionid, int $userid): int {
        global $DB;

        $answer = $DB->get_record('booking_answers', [
            'optionid' => $optionid,
            'userid' => $userid,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
        ], '*', IGNORE_MULTIPLE);
        $this->assertNotEmpty($answer, 'The purchase must have produced a booked answer.');

        return (int)$answer->id;
    }

    /**
     * Price actually paid for this option according to the shopping cart history.
     *
     * @param int $optionid
     * @param int $userid
     * @return float
     */
    private function purchased_price(int $optionid, int $userid): float {
        $item = shopping_cart_history::get_most_recent_historyitem('mod_booking', 'option', $optionid, $userid);
        $this->assertNotEmpty($item->id ?? 0, 'The option must have been purchased through the cart.');
        return (float)$item->price;
    }

    /**
     * A slot option whose every slot costs SLOT_PRICE, with self-rebooking enabled.
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
            'text' => 'Priced slot option ' . uniqid('', true),
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
            'slot_max_slots_per_user' => 3,
            'slot_booking_view_mode' => 'list',
            'slot_add_examiners' => 0,
            'slot_teachers_required' => 0,
            'slot_allow_self_rebooking' => 1,
            'slot_change_deadline_minutes' => '',
        ];
        for ($day = 1; $day <= 7; $day++) {
            $record['slot_day_' . $day] = 1;
        }

        $option = $plugingenerator->create_option((object)$record);
        $optionid = (int)$option->id;
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        // Self-cancellation must be allowed on the instance: releasing slots is a partial
        // cancellation and slot_mover::self_release_policy_blocked() enforces that policy.
        // The instance settings are cached, so the cache entry has to go with the field.
        $DB->set_field('booking', 'cancancelbook', 1, ['id' => $booking->id]);
        cache::make('mod_booking', 'cachedbookinginstances')->delete((int)$settings->cmid);

        // The shopping cart forbids user cancellation by default (cancelationfee = -1), and
        // slot_mover::self_release_policy_blocked() honours that: without this the per-slot
        // release is refused, exactly like the regular cancel button.
        set_config('cancelationfee', 0, 'local_shopping_cart');

        // The cart needs a price on the option itself before it accepts the item; the slot rule
        // below then adds the per-slot amount on top of this base. Without it load_cartitem()
        // rejects the option and no purchase - hence nothing refundable - is ever recorded.
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

        // Flat price rule covering the whole opening window: every slot costs SLOT_PRICE.
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
            'timecreated' => time(),
        ]);
        cache::make('mod_booking', 'slotrulepricesbyoption')->purge();
        singleton_service::destroy_instance();

        return [$optionid, (int)$student->id];
    }
}
