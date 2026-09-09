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
use mod_booking\bo_availability\conditions\confirmcancel;
use mod_booking\external\save_slot_selection;
use mod_booking\local\slotbooking\slot_dto;
use mod_booking\tests\booking_advanced_testcase;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Cancelling a purchased slot must ask before it takes the booking away.
 *
 * A purchased booking is cancelled from the option itself, and money moves when it is. The user has
 * to be asked first - either by the shopping cart dialog naming the refund, or by the "click again
 * to confirm" step. This pins down which of the two appears, because a purchase that vanishes on a
 * single click with no dialog at all is what was reported from the T8 option.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\bo_availability\conditions\cancelmyself::render_button
 * @covers     \mod_booking\bo_availability\conditions\confirmcancel::is_available
 */
final class slot_cancel_confirmation_button_test extends booking_advanced_testcase {
    /** @var float Price of the option. */
    private const PRICE = 30.0;

    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * With the site setting of the reported instance, the cancel button asks before it cancels.
     *
     * @return void
     */
    public function test_cancel_button_of_a_purchased_slot_asks_first(): void {
        if (!class_exists('local_shopping_cart\shopping_cart')) {
            $this->markTestSkipped('local_shopping_cart is not installed.');
        }

        // The reported instance has this on, which is why it is set here explicitly.
        set_config('displayemptyprice', 1, 'booking');

        [$optionid, $userid] = $this->create_priced_slot_option();
        for ($i = 0; $i < 4; $i++) {
            $this->buy_slot($optionid, $userid, $i);
        }

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $this->setUser($userid);
        $html = booking_bookit::render_bookit_button($settings, $userid);

        $this->assertStringContainsString('bo-cancel-button', $html, 'A cancel button must be offered at all.');

        // One of the two has to be true: either the button opens the shopping cart dialog naming
        // the refund, or the cancellation is behind the "click again to confirm" step.
        $opensdialog = str_contains($html, 'shopping-cart-cancel-button');
        // A condition that is "available" passes, so no confirmation step is inserted here;
        // only a blocking confirmcancel makes the first click ask instead of cancel.
        $asksforconfirmation = !(new confirmcancel())->is_available($settings, $userid);

        $this->assertTrue(
            $opensdialog || $asksforconfirmation,
            'A purchase must never be cancellable with a single unconfirmed click.'
        );
    }

    /**
     * Buy one slot for the user, straight through the cart.
     *
     * @param int $optionid
     * @param int $userid
     * @param int $index index of the picker slot to buy
     * @return void
     */
    private function buy_slot(int $optionid, int $userid, int $index = 0): void {
        $slots = slot_dto::build_picker_slots($optionid, $userid);

        save_slot_selection::execute($optionid, $userid, json_encode([(string)$slots[$index]['key']]));
        shopping_cart::delete_all_items_from_cart($userid);
        shopping_cart::buy_for_user($userid);
        shopping_cart::add_item_to_cart('mod_booking', 'option', $optionid, -1);
        shopping_cart::confirm_payment($userid, LOCAL_SHOPPING_CART_PAYMENT_METHOD_CASHIER_CASH);

        booking_option::purge_cache_for_answers($optionid);
        singleton_service::destroy_instance();
    }

    /**
     * A slot option with a flat price, bookable and cancellable by the user.
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
            'text' => 'Cancel confirmation option ' . uniqid('', true),
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

        $json = json_decode($DB->get_field('booking_options', 'json', ['id' => $optionid]) ?: '{}');
        $json->multiplebookings = 1;
        $json->allowtobookagainafter = 0;
        $DB->set_field('booking_options', 'json', json_encode($json), ['id' => $optionid]);
        cache::make('mod_booking', 'bookingoptionsettings')->delete($optionid);

        $DB->set_field('booking', 'cancancelbook', 1, ['id' => $booking->id]);
        cache::make('mod_booking', 'cachedbookinginstances')->delete((int)$settings->cmid);

        set_config('cancelationfee', 0, 'local_shopping_cart');

        $plugingenerator->create_pricecategory([
            'ordernum' => 1,
            'identifier' => 'default',
            'name' => 'Default',
            'defaultvalue' => self::PRICE,
            'pricecatsortorder' => 1,
            'disabled' => 0,
        ]);
        $DB->insert_record('booking_prices', (object)[
            'itemid' => $optionid,
            'area' => 'option',
            'pricecategoryidentifier' => 'default',
            'price' => self::PRICE,
            'currency' => 'EUR',
        ]);

        booking_option::purge_cache_for_answers($optionid);
        singleton_service::destroy_instance();

        return [$optionid, (int)$student->id];
    }
}
