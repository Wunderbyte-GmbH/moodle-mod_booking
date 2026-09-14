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
 * Tests for the shopping cart item of a slot booking option.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use local_shopping_cart\shopping_cart;
use mod_booking\local\mobile\slotbookingstore;
use mod_booking\local\slotbooking\slot_availability;
use mod_booking\option\dates_handler;
use mod_booking\tests\booking_advanced_testcase;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once($CFG->dirroot . '/local/shopping_cart/lib.php');

/**
 * Tests for the shopping cart item of a slot booking option (cashier books for a student).
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\shopping_cart\service_provider::load_cartitem
 */
final class slot_cartitem_test extends booking_advanced_testcase {
    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        unset($_GET['_buyforuser_']);
    }

    /**
     * Tear down.
     */
    public function tearDown(): void {
        unset($_GET['_buyforuser_']);
        parent::tearDown();
    }

    /**
     * The service period of the cart item spans the selected slots (first slot start to last slot
     * end), instead of the empty course dates of a slot option. It is stored in the purchase history
     * and shown in the cash report.
     *
     * @return void
     */
    public function test_service_period_spans_selected_slots(): void {
        [$optionid, $student] = $this->create_priced_slot_option();
        $slots = $this->pick_slots($optionid);

        $cartitem = $this->add_slots_to_cart($optionid, (int) $student->id, $slots);

        $this->assertEquals(min(array_column($slots, 0)), (int) $cartitem['serviceperiodstart']);
        $this->assertEquals(max(array_column($slots, 1)), (int) $cartitem['serviceperiodend']);
    }

    /**
     * The description lists the selected slots and the number of slots in the current language
     * (no hardcoded German text).
     *
     * @return void
     */
    public function test_description_lists_slots_and_localised_slot_count(): void {
        [$optionid, $student] = $this->create_priced_slot_option();
        $slots = $this->pick_slots($optionid);

        $cartitem = $this->add_slots_to_cart($optionid, (int) $student->id, $slots);
        $description = (string) $cartitem['description'];

        foreach ($slots as [$start, $end]) {
            $this->assertStringContainsString(
                s(dates_handler::prettify_optiondates_start_end($start, $end, current_language())),
                $description
            );
        }
        $this->assertStringContainsString(s(get_string('slot_cart_numslots', 'mod_booking', count($slots))), $description);
        $this->assertStringNotContainsString('Anzahl der Slots', $description);
    }

    /**
     * Numeric slot placeholders of the configurable cart description (booking | sccartdescription)
     * keep their value instead of being replaced by today's date.
     *
     * @return void
     */
    public function test_numeric_cart_description_placeholders_keep_their_value(): void {
        set_config('sccartdescription', 'Slots: {slot_num_slots} / Price: {slot_price}', 'booking');
        [$optionid, $student] = $this->create_priced_slot_option();
        $slots = $this->pick_slots($optionid);

        $cartitem = $this->add_slots_to_cart($optionid, (int) $student->id, $slots);
        $description = (string) $cartitem['description'];

        $this->assertStringContainsString('Slots: 3 / Price: 30', $description);
        $this->assertStringNotContainsString(
            userdate(time(), get_string('strftimedaydate', 'core_langconfig')),
            $description
        );
    }

    /**
     * Create a fixed slot option with a price of 10 per slot and an enrolled student.
     *
     * @return array{0:int,1:\stdClass} [optionid, student]
     */
    private function create_priced_slot_option(): array {
        $course = $this->getDataGenerator()->create_course();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance(['course' => $course->id]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $plugingenerator->create_pricecategory((object) [
            'ordernum' => 1,
            'name' => 'default',
            'identifier' => 'default',
            'defaultvalue' => 10.0,
            'pricecatsortorder' => 1,
        ]);

        $record = [
            'bookingid' => $booking->id,
            'text' => 'Cart slot option',
            'course' => $course->id,
            'optiontype' => MOD_BOOKING_OPTIONTYPE_SLOTBOOKING,
            'maxanswers' => 20,
            'useprice' => 1,
            'slot_enabled' => 1,
            'slot_type' => 'fixed',
            'slot_duration_minutes' => 60,
            'slot_interval_minutes' => 60,
            'slot_custom_max_duration' => 60 * MINSECS,
            'slot_custom_min_duration' => 60 * MINSECS,
            'slot_custom_max_days' => DAYSECS,
            'slot_custom_start_interval_minutes' => 60,
            'slot_opening_time' => '08:00',
            'slot_closing_time' => '18:00',
            'slot_valid_from' => strtotime('2050-01-07 00:00:00 UTC'),
            'slot_valid_until' => strtotime('2050-01-14 23:59:59 UTC'),
            'slot_max_participants_per_slot' => 5,
            'slot_max_slots_per_user' => 5,
            'slot_booking_view_mode' => 'list',
            'slot_add_examiners' => 0,
            'slot_teachers_required' => 0,
            'slot_allow_self_rebooking' => 0,
            'slot_change_deadline_minutes' => '',
        ];
        for ($day = 1; $day <= 7; $day++) {
            $record['slot_day_' . $day] = 1;
        }

        $option = $plugingenerator->create_option((object) $record);
        singleton_service::destroy_instance();

        return [(int) $option->id, $student];
    }

    /**
     * Pick three valid, non-overlapping slots on two different days from the option's slot grid.
     *
     * @param int $optionid
     * @return array list of [start, end]
     */
    private function pick_slots(int $optionid): array {
        $all = array_values(slot_availability::get_slots_for_range(
            $optionid,
            strtotime('2050-01-08 00:00:00 UTC'),
            strtotime('2050-01-12 00:00:00 UTC')
        ));
        $this->assertGreaterThan(30, count($all), 'fixture: not enough slots generated');

        $slots = [];
        foreach ([0, 2, 20] as $index) {
            $slots[] = array_map('intval', array_values((array) $all[$index]));
        }

        return $slots;
    }

    /**
     * Put the given slots into the student's cart as cashier and return the cart item.
     *
     * @param int $optionid
     * @param int $userid
     * @param array $slots list of [start, end]
     * @return array
     */
    private function add_slots_to_cart(int $optionid, int $userid, array $slots): array {
        $store = new slotbookingstore($userid, $optionid);
        $store->set_slotbooking_data((object) [
            'slot_selection' => implode(',', array_map(fn($s) => $s[0] . ':' . $s[1], $slots)),
            'slot_teacher_selection' => json_encode([]),
        ]);

        shopping_cart::buy_for_user($userid);
        $cartitem = shopping_cart::add_item_to_cart('mod_booking', 'option', $optionid, $userid);
        $this->assertEquals(LOCAL_SHOPPING_CART_CARTPARAM_SUCCESS, $cartitem['success'] ?? null, json_encode($cartitem));

        return $cartitem;
    }
}
