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
 * Characterization tests for slot price calculation.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\booking_advanced_testcase;
use mod_booking\local\slotbooking\slot_price;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * PHPUnit tests pinning the slot price behaviour, including the load-bearing base-price fallback.
 *
 * @covers \mod_booking\local\slotbooking\slot_price
 */
final class slot_price_test extends booking_advanced_testcase {
    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * With a default price category and the standard resolver path, the per-slot base price is
     * the default value and calculate_price multiplies it by the slot count.
     */
    public function test_default_price_via_resolver(): void {
        [$option, $userid] = $this->create_priced_slot_option(['default' => 10.0]);

        $this->assertSame(10.0, slot_price::calculate_price($option->id, 1, $userid));
        $this->assertSame(30.0, slot_price::calculate_price($option->id, 3, $userid));

        $data = slot_price::calculate_slot_price_data($option->id, 0, 0, $userid);
        $this->assertSame(10.0, (float)$data['price']);
        $this->assertNotSame('', (string)$data['pricecategoryidentifier']);
    }

    /**
     * Load-bearing fallback: when pricecategoryfallback is 2 the core resolver returns no price for
     * the user's (empty) category, yet slot_price must still yield the option's base price. Removing
     * slot_price's own price-record fallback would zero out slot prices here -> it must stay.
     */
    public function test_fallback_provides_base_price_when_resolver_empty(): void {
        set_config('pricecategoryfallback', 2, 'booking');

        [$option, $userid] = $this->create_priced_slot_option(['default' => 10.0]);

        // The resolver yields nothing for the empty category, but the fallback still returns 10.0.
        $this->assertSame(10.0, slot_price::calculate_price($option->id, 1, $userid));
        $data = slot_price::calculate_slot_price_data($option->id, 0, 0, $userid);
        $this->assertSame(10.0, (float)$data['price']);
    }

    /**
     * Zero slots cost nothing regardless of the configured price.
     */
    public function test_zero_slots_cost_nothing(): void {
        [$option, $userid] = $this->create_priced_slot_option(['default' => 10.0]);

        $this->assertSame(0.0, slot_price::calculate_price($option->id, 0, $userid));
    }

    /**
     * Create a fixed-slot booking option with the given price categories (identifier => default value).
     *
     * @param array $categoryprices map of price category identifier to default value
     * @return array{0:\stdClass, 1:int} option, student user id
     */
    private function create_priced_slot_option(array $categoryprices): array {
        $course = self::getDataGenerator()->create_course();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance(['course' => $course->id]);
        $student = self::getDataGenerator()->create_user();

        $ordernum = 1;
        foreach ($categoryprices as $identifier => $value) {
            $plugingenerator->create_pricecategory((object)[
                'ordernum' => $ordernum,
                'name' => $identifier,
                'identifier' => $identifier,
                'defaultvalue' => $value,
                'pricecatsortorder' => $ordernum,
            ]);
            $ordernum++;
        }

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
            'slot_custom_max_duration' => 60 * MINSECS,
            'slot_custom_min_duration' => 60 * MINSECS,
            'slot_custom_max_days' => DAYSECS,
            'slot_custom_start_interval_minutes' => 30,
            'slot_opening_time' => '09:00',
            'slot_closing_time' => '12:00',
            'slot_valid_from' => strtotime('2050-01-07 00:00:00 UTC'),
            'slot_valid_until' => strtotime('2050-01-10 23:59:59 UTC'),
            'slot_max_participants_per_slot' => 3,
            'slot_max_slots_per_user' => 3,
            'slot_booking_view_mode' => 'list',
            'slot_add_examiners' => 0,
            'slot_teachers_required' => 0,
        ];
        for ($day = 1; $day <= 7; $day++) {
            $record['slot_day_' . $day] = in_array($day, [1, 5], true) ? 1 : 0;
        }

        $option = $plugingenerator->create_option((object)$record);
        singleton_service::destroy_instance();

        return [$option, (int)$student->id];
    }
}
