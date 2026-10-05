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
use mod_booking\bo_availability\conditions\priceisset;
use mod_booking\local\slotbooking\slot_price;
use mod_booking\tests\booking_advanced_testcase;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * The list/cards price of a slot option with price rules: "from <cheapest price>" when the user
 * can book slots at different prices, unchanged when they only face one price.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\slotbooking\slot_price::get_bookable_price_range
 * @covers     \mod_booking\bo_availability\conditions\priceisset::render_button
 */
final class slot_price_from_test extends booking_advanced_testcase {
    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Without price rules every slot costs the base price - no range, display unchanged.
     */
    public function test_no_price_rules_keeps_base_price(): void {
        [$optionid, $userid] = $this->create_priced_slot_option();

        $this->assertNull(slot_price::get_bookable_price_range($optionid, $userid));

        $data = $this->render_price_button($optionid, $userid);
        $this->assertEquals(10, $data['price']);
        $this->assertArrayNotHasKey('priceformatted', $data);
    }

    /**
     * A rule making some slots more expensive: the user can book at 10 and 20, so the button shows
     * "from 10.00" (cheapest), not the base price alone.
     */
    public function test_different_slot_prices_show_from_cheapest(): void {
        [$optionid, $userid] = $this->create_priced_slot_option();
        $this->add_price_rule($optionid, 'default', 'delta', 10.0);

        $this->assertSame(['min' => 10.0, 'max' => 20.0], slot_price::get_bookable_price_range($optionid, $userid));

        $data = $this->render_price_button($optionid, $userid);
        $this->assertSame(get_string('slot_price_from', 'mod_booking', '10.00'), $data['priceformatted']);
    }

    /**
     * A rule that only applies to ANOTHER price category leaves this user with a single price -
     * the display must stay as before.
     */
    public function test_rule_for_other_category_keeps_single_price(): void {
        [$optionid, $userid] = $this->create_priced_slot_option();
        $this->add_price_rule($optionid, 'student', 'delta', 10.0);

        $range = slot_price::get_bookable_price_range($optionid, $userid);
        $this->assertSame($range['min'], $range['max']);

        $data = $this->render_price_button($optionid, $userid);
        $this->assertArrayNotHasKey('priceformatted', $data);
    }

    /**
     * Render the priceisset button data for the given user.
     *
     * @param int $optionid
     * @param int $userid
     * @return array
     */
    private function render_price_button(int $optionid, int $userid): array {
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        [, $data] = (new priceisset())->render_button($settings, $userid);
        return $data;
    }

    /**
     * Add a price rule for slots within 10:00-12:00.
     *
     * @param int $optionid
     * @param string $category price category identifier the rule applies to
     * @param string $mode absolute|delta|factor
     * @param float $value
     */
    private function add_price_rule(int $optionid, string $category, string $mode, float $value): void {
        global $DB;

        $ruleid = (int) $DB->insert_record('booking_slot_rule', (object) [
            'optionid' => $optionid,
            'ruletype' => 'price',
            'priority' => 1,
            'activefrom' => 0,
            'activeuntil' => 0,
            'weekdays' => '',
            'timerangestart' => '10:00',
            'timerangeend' => '12:00',
            'timecreated' => time(),
        ]);
        $DB->insert_record('booking_slot_rule_price', (object) [
            'ruleid' => $ruleid,
            'pricecategoryidentifier' => $category,
            'mode' => $mode,
            'value' => $value,
            'currency' => 'EUR',
            'timecreated' => time(),
        ]);
        cache::make('mod_booking', 'slotrulepricesbyoption')->purge();
        \mod_booking\local\slotbooking\slot_rules::reset_caches();
        singleton_service::destroy_instance();
    }

    /**
     * Fixed 60-minute slots 09:00-12:00 with a default price of 10.
     *
     * @return array{0:int,1:int} option id, student user id
     */
    private function create_priced_slot_option(): array {
        $course = self::getDataGenerator()->create_course();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance(['course' => $course->id]);
        $student = self::getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $plugingenerator->create_pricecategory((object)[
            'ordernum' => 1,
            'name' => 'default',
            'identifier' => 'default',
            'defaultvalue' => 10.0,
            'pricecatsortorder' => 1,
        ]);
        $plugingenerator->create_pricecategory((object)[
            'ordernum' => 2,
            'name' => 'student',
            'identifier' => 'student',
            'defaultvalue' => 10.0,
            'pricecatsortorder' => 2,
        ]);

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
            'slot_booking_view_mode' => 'calendar',
            'slot_add_examiners' => 0,
            'slot_teachers_required' => 0,
        ];
        for ($day = 1; $day <= 7; $day++) {
            $record['slot_day_' . $day] = 1;
        }

        $option = $plugingenerator->create_option((object)$record);
        singleton_service::destroy_instance();

        return [(int)$option->id, (int)$student->id];
    }
}
