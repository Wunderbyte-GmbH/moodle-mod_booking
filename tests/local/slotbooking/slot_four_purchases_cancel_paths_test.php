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
use mod_booking\local\slotbooking\slot_update_service;
use mod_booking\tests\booking_advanced_testcase;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Four purchased slots, given up through all three cancellation routes.
 *
 * A user who bought several slots separately can end them in three different ways: the trash button
 * on the booked slots list, the move/cancel editor by clearing the selection, and "Undo my booking",
 * which ends every purchase at once. All three route the money through the shopping cart, and the
 * sum of what comes back has to equal the sum of what was paid - no matter how the four are mixed.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\external\release_slots::execute
 * @covers     \mod_booking\local\slotbooking\slot_update_service::apply
 * @covers     \local_shopping_cart\shopping_cart::cancel_all_purchases_for_item
 */
final class slot_four_purchases_cancel_paths_test extends booking_advanced_testcase {
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
     * Four purchases, cancelled one by trash, one by the editor, two by undo: the money adds up.
     *
     * @return void
     */
    public function test_all_three_cancellation_routes_return_exactly_what_was_paid(): void {
        global $DB;

        if (!class_exists('local_shopping_cart\shopping_cart')) {
            $this->markTestSkipped('local_shopping_cart is not installed.');
        }

        [$optionid, $userid] = $this->create_priced_slot_option();
        $baids = $this->buy_slots($optionid, $userid, 4);

        $this->assertCount(4, $baids, 'The fixture must produce four separate purchases.');
        $this->assertSame(
            4,
            $DB->count_records('booking_answers', [
                'optionid' => $optionid,
                'userid' => $userid,
                'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
            ]),
            'All four bookings must be active before anything is cancelled.'
        );
        $this->assertEqualsWithDelta(0.0, $this->current_credit($userid), 0.001, 'Nothing refunded yet.');

        $this->setUser($userid);

        // Route one, the trash button on the booked slots list: this booking holds a single slot,
        // so giving it up ends the whole booking and hands the purchase to the cart.
        $first = $this->slot_key_of($baids[0]);
        $result = release_slots::execute($optionid, $baids[0], json_encode([$first]), '');
        $this->assertTrue((bool)$result['cancelled'], 'Releasing the only slot must cancel the booking.');
        $this->assertEqualsWithDelta(self::SLOT_PRICE, $this->current_credit($userid), 0.011);

        // Route two, the move/cancel editor with an empty selection.
        slot_update_service::apply($optionid, $baids[1], $userid, []);
        $this->assertEqualsWithDelta(2 * self::SLOT_PRICE, $this->current_credit($userid), 0.011);

        // Route three, "Undo my booking": both remaining purchases at once.
        $cancelled = shopping_cart::cancel_all_purchases_for_item('mod_booking', 'option', $optionid, $userid);
        $this->assertTrue((bool)$cancelled['success'], 'Undo must cancel what is left.');
        $this->assertSame(2, (int)$cancelled['cancelled'], 'Both remaining purchases must go in one step.');

        // Everything is gone, and the credit equals the four prices - not more, not less.
        $this->assertSame(
            0,
            $DB->count_records('booking_answers', [
                'optionid' => $optionid,
                'userid' => $userid,
                'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
            ]),
            'No booking may survive the three cancellation routes.'
        );
        $this->assertEqualsWithDelta(
            4 * self::SLOT_PRICE,
            $this->current_credit($userid),
            0.011,
            'The refunds together must equal exactly what the four purchases cost.'
        );

        // Every purchase is marked cancelled, none left standing as successful.
        $this->assertSame(
            0,
            $DB->count_records('local_shopping_cart_history', [
                'itemid' => $optionid,
                'userid' => $userid,
                'paymentstatus' => LOCAL_SHOPPING_CART_PAYMENT_SUCCESS,
            ]),
            'No purchase may stay successful once its booking is cancelled.'
        );
    }

    /**
     * Buy a number of single-slot bookings, one purchase each.
     *
     * @param int $optionid
     * @param int $userid
     * @param int $count how many slots to buy
     * @return array<int, int> the booking answer ids, in purchase order
     */
    private function buy_slots(int $optionid, int $userid, int $count): array {
        global $DB;

        $slots = slot_dto::build_picker_slots($optionid, $userid);
        $baids = [];

        for ($i = 0; $i < $count; $i++) {
            // The checkout itself, without the generator's create_user_purchase() - that helper
            // adds a second answer of its own on top of the one the checkout produces, which would
            // leave every purchase with a stray unpaid twin.
            save_slot_selection::execute($optionid, $userid, json_encode([(string)$slots[$i]['key']]));
            shopping_cart::delete_all_items_from_cart($userid);
            shopping_cart::buy_for_user($userid);
            shopping_cart::add_item_to_cart('mod_booking', 'option', $optionid, -1);
            shopping_cart::confirm_payment($userid, LOCAL_SHOPPING_CART_PAYMENT_METHOD_CASHIER_CASH);

            booking_option::purge_cache_for_answers($optionid);
            singleton_service::destroy_instance();

            // The answer this purchase produced: the booked one carrying a purchase link we have
            // not collected yet.
            $current = $DB->get_records_select(
                'booking_answers',
                'optionid = :optionid AND userid = :userid AND waitinglist = :booked AND purchaseidentifier > 0',
                [
                    'optionid' => $optionid,
                    'userid' => $userid,
                    'booked' => MOD_BOOKING_STATUSPARAM_BOOKED,
                ],
                'id ASC',
                'id'
            );
            foreach (array_keys($current) as $id) {
                if (!in_array((int)$id, $baids, true)) {
                    $baids[] = (int)$id;
                    break;
                }
            }
        }

        return $baids;
    }

    /**
     * The single slot key an answer holds.
     *
     * @param int $baid
     * @return string
     */
    private function slot_key_of(int $baid): string {
        global $DB;

        $answer = $DB->get_record('booking_answers', ['id' => $baid], '*', MUST_EXIST);
        $slots = \mod_booking\local\slotbooking\slot_answer::get_slot_data($answer)['slots'] ?? [];
        $slot = reset($slots);

        return $slot['start'] . ':' . $slot['end'];
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
     * A priced slot option that allows several separate bookings of one slot each.
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
            'text' => 'Four purchases option ' . uniqid('', true),
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
            // Four slots in total, bought one at a time: the cap counts the slots a user holds,
            // and each of the four purchases below adds exactly one.
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

        // Book again with no waiting time, so a second, third and fourth booking are allowed
        // right away - otherwise the option is exhausted after the first slot.
        $json = json_decode($DB->get_field('booking_options', 'json', ['id' => $optionid]) ?: '{}');
        $json->multiplebookings = 1;
        $json->allowtobookagainafter = 0;
        $DB->set_field('booking_options', 'json', json_encode($json), ['id' => $optionid]);
        // The option settings are cached; without dropping the entry the fresh json is never seen
        // and "book again" stays disabled, so only the first purchase would go through.
        cache::make('mod_booking', 'bookingoptionsettings')->delete($optionid);

        // Self-cancellation must be allowed on the instance; the settings are cached, so the cache
        // entry has to go with the field.
        $DB->set_field('booking', 'cancancelbook', 1, ['id' => $booking->id]);
        cache::make('mod_booking', 'cachedbookinginstances')->delete((int)$settings->cmid);

        // The cart forbids user cancellation by default (cancelationfee = -1).
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
