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

use mod_booking\tests\booking_advanced_testcase;
use cache;
use mod_booking\local\slotbooking\slot_update_service;
use mod_booking\local\slotbooking\slot_answer;
use mod_booking\local\slotbooking\slot_dto;
use mod_booking\local\slotbooking\slot_move_store;
use mod_booking\local\slotbooking\target_price_policy;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests price-delta routing of self-service slot updates (MP-G).
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\slotbooking\slot_update_service::apply
 * @covers     \mod_booking\local\slotbooking\target_price_policy::calculate_move_delta
 */
final class slot_move_payment_routing_test extends booking_advanced_testcase {
    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * The price delta is the difference of summed slot prices; a +5 rule on 10:00-12:00 makes a
     * 09:00 -> 10:00 move an upgrade (+5), the reverse a downgrade (-5) and 09:00 -> 09:00 neutral.
     *
     * @return void
     */
    public function test_calculate_move_delta(): void {
        [$optionid, , $userid] = $this->create_priced_move_option(5.0);
        [$nine, $ten, $ninealt] = $this->three_slots($optionid, $userid);

        $cur = static fn(array $s): array => [['start' => (int)$s['start'], 'end' => (int)$s['end']]];

        $this->assertEqualsWithDelta(
            5.0,
            target_price_policy::calculate_move_delta($optionid, $userid, $cur($nine), $cur($ten)),
            0.001
        );
        $this->assertEqualsWithDelta(
            -5.0,
            target_price_policy::calculate_move_delta($optionid, $userid, $cur($ten), $cur($nine)),
            0.001
        );
        $this->assertEqualsWithDelta(
            0.0,
            target_price_policy::calculate_move_delta($optionid, $userid, $cur($nine), $cur($ninealt)),
            0.001
        );
    }

    /**
     * A price-neutral self-service move is committed directly (no cart, no pending move).
     *
     * @return void
     */
    public function test_ws_equal_price_is_direct(): void {
        global $DB;

        [$optionid, $bookingid, $userid] = $this->create_priced_move_option(5.0);
        [$nine, , $ninealt] = $this->three_slots($optionid, $userid);
        $baid = $this->book($optionid, $bookingid, $userid, $nine);

        $this->setUser($userid);
        $result = slot_update_service::apply($optionid, $baid, $userid, [$ninealt['key']], '');

        $this->assertSame('direct', $result['mode']);
        $this->assertEqualsWithDelta(0.0, (float)$result['pricedelta'], 0.001);
        $this->assertNull(slot_move_store::get_pending_for_answer($baid), 'No pending move for a direct move.');

        $answer = $DB->get_record('booking_answers', ['id' => $baid], '*', MUST_EXIST);
        $slotdata = slot_answer::get_slot_data($answer);
        $this->assertSame((int)$ninealt['start'], (int)$slotdata['slots'][0]['start'], 'Answer should be moved.');
    }

    /**
     * An upgrade is NOT committed immediately: a pending move is created and the difference goes
     * into the cart; the booked answer keeps its original slot until checkout.
     *
     * @return void
     */
    public function test_ws_upgrade_routes_to_cart(): void {
        global $DB;

        [$optionid, $bookingid, $userid] = $this->create_priced_move_option(5.0);
        [$nine, $ten] = $this->three_slots($optionid, $userid);
        $baid = $this->book($optionid, $bookingid, $userid, $nine);

        $this->setUser($userid);
        $result = slot_update_service::apply($optionid, $baid, $userid, [$ten['key']], '');

        $this->assertSame('cart', $result['mode']);
        $this->assertEqualsWithDelta(5.0, (float)$result['pricedelta'], 0.001);
        $this->assertGreaterThan(0, (int)$result['moveid']);

        $pending = slot_move_store::get_pending_for_answer($baid);
        $this->assertNotNull($pending, 'A pending move should hold the target slot.');
        $this->assertEquals((int)$result['moveid'], (int)$pending->id);

        // The answer is unchanged until the upgrade is paid.
        $answer = $DB->get_record('booking_answers', ['id' => $baid], '*', MUST_EXIST);
        $slotdata = slot_answer::get_slot_data($answer);
        $this->assertSame((int)$nine['start'], (int)$slotdata['slots'][0]['start'], 'Answer must not move before payment.');
    }

    /**
     * A downgrade is committed directly and routed to a refund (no cart). The refund itself is a
     * no-op here because the slot was not purchased through the cart; crediting is covered by
     * the shopping_cart partial-refund tests.
     *
     * @return void
     */
    public function test_ws_downgrade_routes_to_refund(): void {
        global $DB;

        [$optionid, $bookingid, $userid] = $this->create_priced_move_option(5.0);
        [$nine, $ten] = $this->three_slots($optionid, $userid);
        $baid = $this->book($optionid, $bookingid, $userid, $ten);

        $this->setUser($userid);
        $result = slot_update_service::apply($optionid, $baid, $userid, [$nine['key']], '');

        $this->assertSame('refund', $result['mode']);
        $this->assertEqualsWithDelta(-5.0, (float)$result['pricedelta'], 0.001);
        $this->assertNull(slot_move_store::get_pending_for_answer($baid), 'Downgrade commits directly, no hold.');

        $answer = $DB->get_record('booking_answers', ['id' => $baid], '*', MUST_EXIST);
        $slotdata = slot_answer::get_slot_data($answer);
        $this->assertSame((int)$nine['start'], (int)$slotdata['slots'][0]['start'], 'Answer should be moved to the cheaper slot.');
    }

    /**
     * Return a 09:00 slot, a 10:00 slot (price-bumped) and a second distinct 09:00 slot.
     *
     * @param int $optionid
     * @param int $userid
     * @return array{0:array,1:array,2:array}
     */
    private function three_slots(int $optionid, int $userid): array {
        $byhour = ['09' => [], '10' => []];
        foreach (slot_dto::build_picker_slots($optionid, $userid) as $slot) {
            $hour = date('H', (int)$slot['start']);
            if (isset($byhour[$hour])) {
                $byhour[$hour][] = $slot;
            }
        }
        $this->assertGreaterThanOrEqual(2, count($byhour['09']), 'Need two distinct 09:00 slots.');
        $this->assertGreaterThanOrEqual(1, count($byhour['10']), 'Need a 10:00 slot.');
        return [$byhour['09'][0], $byhour['10'][0], $byhour['09'][1]];
    }

    /**
     * Book one slot for the user (BOOKED answer).
     *
     * @param int $optionid
     * @param int $bookingid
     * @param int $userid
     * @param array $slot
     * @return int baid
     */
    private function book(int $optionid, int $bookingid, int $userid, array $slot): int {
        global $DB;
        $answer = (object) [
            'bookingid' => $bookingid,
            'optionid' => $optionid,
            'userid' => $userid,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
            'places' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
            'startdate' => (int)$slot['start'],
            'enddate' => (int)$slot['end'],
            'json' => '',
        ];
        slot_answer::set_slot_data(
            $answer,
            ['slots' => [['start' => (int)$slot['start'], 'end' => (int)$slot['end']]], 'teachers' => []]
        );
        return (int) $DB->insert_record('booking_answers', $answer);
    }

    /**
     * Create a self-rebookable slotbooking option with a +$delta price rule on 10:00-12:00.
     *
     * @param float $delta the per-slot price surcharge for the 10:00-12:00 range
     * @return array{0:int,1:int,2:int} [optionid, bookingid, studentid]
     */
    private function create_priced_move_option(float $delta): array {
        global $DB;

        $course = self::getDataGenerator()->create_course();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance(['course' => $course->id]);
        $student = self::getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $record = [
            'bookingid' => $booking->id,
            'text' => 'Priced move option ' . uniqid('', true),
            'course' => $course->id,
            'optiontype' => MOD_BOOKING_OPTIONTYPE_SLOTBOOKING,
            'maxanswers' => 20,
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
            'slot_allow_self_rebooking' => 1,
            'slot_change_deadline_minutes' => '',
        ];
        for ($day = 1; $day <= 7; $day++) {
            $record['slot_day_' . $day] = in_array($day, [1, 5], true) ? 1 : 0;
        }

        $option = $plugingenerator->create_option((object) $record);
        $optionid = (int) $option->id;
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $bookingid = (int) $settings->bookingid;

        // Price rule: slots within 10:00-12:00 cost +$delta (delta mode on a 0 base is enough for routing).
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
            'pricecategoryidentifier' => 'default',
            'mode' => 'delta',
            'value' => $delta,
            'currency' => 'EUR',
            'timecreated' => time(),
        ]);
        cache::make('mod_booking', 'slotrulepricesbyoption')->purge();
        singleton_service::destroy_instance();

        return [$optionid, $bookingid, (int) $student->id];
    }
}
