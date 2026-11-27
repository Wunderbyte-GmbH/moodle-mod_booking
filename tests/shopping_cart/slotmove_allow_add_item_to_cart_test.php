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
 * Tests that a booked user of a priced slot option can open the move dialog.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use local_shopping_cart\local\cartstore;
use local_shopping_cart\shopping_cart;
use mod_booking\bo_availability\bo_info;
use mod_booking\external\allow_add_item_to_cart;
use mod_booking\local\mobile\slotbookingstore;
use mod_booking\local\slotbooking\slot_availability;
use mod_booking\tests\booking_advanced_testcase;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Before opening the booking dialog, the browser asks the shopping cart whether the option may be
 * added (mod_booking_allow_add_item_to_cart) and stops on an error. For slot options the condition
 * alreadybooked does not block (more slots may be booked), so for a booked user the first blocking
 * condition was cancelmyself and the answer "cannot be booked": the "Booked - Move or cancel slot"
 * button of a priced slot option showed a shopping cart error instead of the dialog.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \mod_booking\shopping_cart\service_provider::allow_add_item_to_cart
 * @covers \mod_booking\external\allow_add_item_to_cart
 */
final class slotmove_allow_add_item_to_cart_test extends booking_advanced_testcase {
    /**
     * How the slot was booked.
     *
     * @return array
     */
    public static function booking_provider(): array {
        return [
            'paid in the shopping cart' => ['cart'],
            'paid with credits' => ['credits'],
            'booked by staff' => ['staff'],
        ];
    }

    /**
     * The booked user gets "already booked", so the dialog opens the move page and adds nothing.
     *
     * @dataProvider booking_provider
     * @param string $how
     */
    public function test_booked_user_can_open_the_move_dialog(string $how): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'credit',
            'name' => 'Credit',
        ]);
        set_config('bookwithcreditsactive', 1, 'booking');
        set_config('bookwithcreditsprofilefield', 'credit', 'booking');

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id, 'cancancelbook' => 1]);
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->create_pricecategory((object)[
            'ordernum' => 1,
            'name' => 'default',
            'identifier' => 'default',
            'defaultvalue' => 10.0,
            'pricecatsortorder' => 1,
        ]);
        $option = $plugingenerator->create_option((object)[
            'bookingid' => $booking->id,
            'text' => 'Priced slot option',
            'chooseorcreatecourse' => 1,
            'courseid' => $course->id,
            'maxanswers' => 10,
            'useprice' => 1,
            'credits' => $how === 'credits' ? 2 : 0,
            'optiontype' => MOD_BOOKING_OPTIONTYPE_SLOTBOOKING,
            'slot_enabled' => 1,
            'slot_type' => 'fixed',
            'slot_booking_view_mode' => 'calendar',
            'slot_duration_minutes' => 20,
            'slot_interval_minutes' => 20,
            'slot_opening_time' => '09:00',
            'slot_closing_time' => '11:00',
            'slot_valid_from' => 2409195600,
            'slot_valid_until' => 2409627000,
            'slot_day_1' => 1,
            'slot_max_participants_per_slot' => 3,
            'slot_max_slots_per_user' => 3,
            'slot_allow_self_rebooking' => 1,
            'slot_change_deadline_minutes' => '',
        ]);
        $optionid = (int)$option->id;
        singleton_service::destroy_instance();

        $slots = array_values(slot_availability::get_slots_for_range($optionid, 2409195600, 2409627000));
        [$start, $end] = array_values((array)$slots[0]);
        $student = $this->getDataGenerator()->create_user(['profile_field_credit' => '5']);
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        // Book one slot.
        $this->setUser($student);
        (new slotbookingstore((int)$student->id, $optionid))->set_slotbooking_data((object)['slot_selection' => "$start:$end"]);
        singleton_service::destroy_instance();
        if ($how === 'staff') {
            $this->setAdminUser();
            booking_bookit::answer_booking_option('option', $optionid, MOD_BOOKING_STATUSPARAM_BOOKED, (int)$student->id);
        } else {
            // Credits: booked right away. Cart: put into the cart, then paid at the cashier.
            bo_info::load_pre_booking_page($optionid, 1, (int)$student->id);
            if ($how === 'cart') {
                $this->setAdminUser();
                shopping_cart::confirm_payment((int)$student->id, LOCAL_SHOPPING_CART_PAYMENT_METHOD_CASHIER_CASH);
            }
        }
        $this->setUser($student);
        singleton_service::destroy_instance();
        booking_option::purge_cache_for_answers($optionid);

        $answersbefore = $DB->get_records('booking_answers', ['optionid' => $optionid, 'userid' => $student->id]);
        $this->assertCount(1, $answersbefore);
        $this->assertSame(MOD_BOOKING_STATUSPARAM_BOOKED, (int)reset($answersbefore)->waitinglist);

        // The user is booked and may move the slot.
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        [$id] = (new bo_info($settings))->is_available($optionid, (int)$student->id, true);
        $this->assertSame(MOD_BOOKING_BO_COND_SLOTMOVE, $id);

        // The check before the dialog: "already booked" (the browser goes on), not an error.
        $result = allow_add_item_to_cart::execute($optionid, (int)$student->id);
        $this->assertSame(LOCAL_SHOPPING_CART_CARTPARAM_ALREADYBOOKED, (int)$result['success']);

        // The dialog then shows the move page - and books or adds nothing.
        $page = bo_info::load_pre_booking_page($optionid, 0, (int)$student->id);
        $this->assertStringContainsString('mod_booking/condition/slotmove', $page['template']);
        $answersafter = $DB->get_records('booking_answers', ['optionid' => $optionid, 'userid' => $student->id]);
        $this->assertEquals($answersbefore, $answersafter);
        $this->assertSame([], cartstore::instance((int)$student->id)->get_items() ?? []);
    }
}
