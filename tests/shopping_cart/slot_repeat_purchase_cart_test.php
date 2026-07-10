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
 * Reproduces the reported bug: a user who already bought and paid for one slot (out of several
 * allowed via slot_max_slots_per_user) selects a second, different slot and tries to add it to
 * the shopping cart. In the browser this returned a generic "You cannot add this item to your
 * shopping cart because there was an error." - this test exercises the exact entry point the
 * cart's "Add to cart" click calls, service_provider::allow_add_item_to_cart(), to find out which
 * bo_condition is (still) blocking it.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\shopping_cart\service_provider::allow_add_item_to_cart
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use mod_booking\local\mobile\slotbookingstore;
use mod_booking\local\slotbooking\slot_answer;
use mod_booking\shopping_cart\service_provider;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * PHPUnit test reproducing the shopping-cart "cannot add" error for a repeat slot purchase.
 */
final class slot_repeat_purchase_cart_test extends booking_advanced_testcase {
    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Buying a second, additional slot must be allowed through the shopping cart's own
     * pre-check, the same way it is now allowed through alreadybooked's hard_block().
     *
     * @return void
     */
    public function test_allow_add_item_to_cart_allows_second_slot_purchase(): void {
        [$optionid, $userid] = $this->create_priced_slot_option(['default' => 10.0], 10);

        $firststart = strtotime('2050-01-07 09:00:00 UTC');
        $secondstart = strtotime('2050-01-07 10:00:00 UTC');
        $secondend = $secondstart + (60 * MINSECS);

        // Slot 1: already paid for.
        $this->book_slot($optionid, $userid, $firststart, MOD_BOOKING_STATUSPARAM_BOOKED);
        singleton_service::destroy_instance();

        // Slot 2: freshly selected, only in the cache (not persisted yet) - the real shape of
        // "buy an additional slot".
        $store = new slotbookingstore($userid, $optionid);
        $store->set_slotbooking_data((object)[
            'slot_selection' => $secondstart . ':' . $secondend,
            'slot_teacher_selection' => json_encode([]),
        ]);

        $result = service_provider::allow_add_item_to_cart('option', $optionid, $userid);

        $this->assertNotSame('cannotbebooked', $result['info'] ?? null);
        $this->assertNotSame('alreadybooked', $result['info'] ?? null);
        $this->assertNotSame('fullybooked', $result['info'] ?? null);
    }

    /**
     * Once slot capacity is exhausted, adding a further item must still be rejected as
     * "alreadybooked" (not a generic/unclassified error).
     *
     * @return void
     */
    public function test_allow_add_item_to_cart_rejects_once_capacity_exhausted(): void {
        [$optionid, $userid] = $this->create_priced_slot_option(['default' => 10.0], 1);

        $firststart = strtotime('2050-01-07 09:00:00 UTC');
        $this->book_slot($optionid, $userid, $firststart, MOD_BOOKING_STATUSPARAM_BOOKED);
        singleton_service::destroy_instance();

        $result = service_provider::allow_add_item_to_cart('option', $optionid, $userid);

        $this->assertSame('alreadybooked', $result['info'] ?? null);
    }

    /**
     * Create a fixed-slot booking option with a resolvable price and the given slot capacity.
     *
     * @param array $categoryprices map of price category identifier to default value
     * @param int $maxslotsperuser slot_max_slots_per_user
     * @return array{0:int,1:int} [optionid, userid]
     */
    private function create_priced_slot_option(array $categoryprices, int $maxslotsperuser): array {
        $course = self::getDataGenerator()->create_course();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance(['course' => $course->id]);
        $student = self::getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

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
            'text' => 'Repeat purchase test option ' . uniqid('', true),
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
            'slot_opening_time' => '08:00',
            'slot_closing_time' => '18:00',
            'slot_valid_from' => strtotime('2050-01-07 00:00:00 UTC'),
            'slot_valid_until' => strtotime('2050-01-10 23:59:59 UTC'),
            'slot_max_participants_per_slot' => 5,
            'slot_max_slots_per_user' => $maxslotsperuser,
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

        return [(int) $option->id, (int) $student->id];
    }

    /**
     * Insert a booking answer directly (bypassing the full checkout flow), and purge the
     * option's cached answers so the direct insert is visible to the next read.
     *
     * @param int $optionid booking option id
     * @param int $userid user id
     * @param int $start slot start timestamp
     * @param int $waitinglist booking status (MOD_BOOKING_STATUSPARAM_*)
     * @return int booking answer id
     */
    private function book_slot(int $optionid, int $userid, int $start, int $waitinglist): int {
        global $DB;

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $end = $start + (60 * MINSECS);

        $answer = (object) [
            'bookingid' => (int)$settings->bookingid,
            'optionid' => $optionid,
            'userid' => $userid,
            'waitinglist' => $waitinglist,
            'places' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
            'startdate' => $start,
            'enddate' => $end,
            'json' => '',
        ];
        slot_answer::set_slot_data($answer, ['slots' => [['start' => $start, 'end' => $end]], 'teachers' => []]);

        $baid = (int) $DB->insert_record('booking_answers', $answer);
        \cache::make('mod_booking', 'bookingoptionsanswers')->delete($optionid);

        return $baid;
    }
}
