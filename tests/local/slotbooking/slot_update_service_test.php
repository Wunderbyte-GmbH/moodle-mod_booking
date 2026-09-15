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
use mod_booking\local\slotbooking\slot_answer;
use mod_booking\local\slotbooking\slot_dto;
use mod_booking\local\slotbooking\slot_move_store;
use mod_booking\local\slotbooking\slot_mover;
use mod_booking\local\slotbooking\slot_update_service;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests the dry-run plan() of slot_update_service (diff + net-delta routing of an "Update booking").
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\slotbooking\slot_update_service
 */
final class slot_update_service_test extends booking_advanced_testcase {
    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * plan() classifies the new selection against the current slots: keep -> direct, drop a priced
     * slot -> refund, empty -> cancel, swap (count preserved) -> move, and growing -> rejected.
     *
     * @return void
     */
    public function test_plan_routes_by_diff(): void {
        [$optionid, $bookingid, $userid] = $this->create_priced_move_option(5.0);
        [$nine, $ten, $ninealt] = $this->three_slots($optionid, $userid);
        // Book two slots: a free 09:00 and a +5 10:00.
        $baid = $this->book($optionid, $bookingid, $userid, [$nine, $ten]);

        $this->setUser($userid);

        // No change -> direct, nothing removed/added.
        $plan = slot_update_service::plan($optionid, $baid, $userid, [$nine['key'], $ten['key']]);
        $this->assertSame('direct', $plan['route']);
        $this->assertSame([], $plan['removed']);
        $this->assertSame([], $plan['added']);
        $this->assertEqualsWithDelta(0.0, $plan['netdelta'], 0.001);
        $this->assertSame([], $plan['errors']);
        $this->assertFalse($plan['ismove']);

        // Drop the +5 slot -> refund (net -5), exactly one removed.
        $plan = slot_update_service::plan($optionid, $baid, $userid, [$nine['key']]);
        $this->assertSame('refund', $plan['route']);
        $this->assertSame([$ten['key']], $plan['removed']);
        $this->assertEqualsWithDelta(-5.0, $plan['netdelta'], 0.001);
        $this->assertSame([], $plan['errors']);

        // Empty selection -> full cancellation route; net delta gives back both slots' price.
        $plan = slot_update_service::plan($optionid, $baid, $userid, []);
        $this->assertSame('cancel', $plan['route']);
        $this->assertEqualsWithDelta(-5.0, $plan['netdelta'], 0.001);

        // Swap 09:00 -> 09:00alt (count preserved, one removed + one added) -> move, price-neutral.
        $plan = slot_update_service::plan($optionid, $baid, $userid, [$ninealt['key'], $ten['key']]);
        $this->assertTrue($plan['ismove']);
        $this->assertSame('direct', $plan['route']);
        $this->assertEqualsWithDelta(0.0, $plan['netdelta'], 0.001);
        $this->assertSame([$nine['key']], $plan['removed']);
        $this->assertSame([$ninealt['key']], $plan['added']);

        // Growing the booking is rejected (edit-only; adding is "Book another slot").
        $plan = slot_update_service::plan($optionid, $baid, $userid, [$nine['key'], $ten['key'], $ninealt['key']]);
        $this->assertContains('slot_update_no_add', $plan['errors']);
    }

    /**
     * apply() with a reduced selection cancels the given-up slot and refunds the net difference;
     * giving up the last slot is a full cancellation (the booking is no longer active).
     *
     * @return void
     */
    public function test_apply_reduction_and_full_cancellation(): void {
        global $DB;

        [$optionid, $bookingid, $userid] = $this->create_priced_move_option(5.0);
        [$nine, $ten] = $this->three_slots($optionid, $userid);

        // Partial reduction: drop the +5 slot, keep the free one -> refund, answer shrinks to 1.
        $baid = $this->book($optionid, $bookingid, $userid, [$nine, $ten]);
        $this->setUser($userid);

        $outcome = slot_update_service::apply($optionid, $baid, $userid, [$nine['key']]);
        $this->assertSame('refund', $outcome['mode']);
        $this->assertEqualsWithDelta(-5.0, (float)$outcome['pricedelta'], 0.001);
        $this->assertSame(1, (int)$outcome['slotcount']);

        $answer = $DB->get_record('booking_answers', ['id' => $baid], '*', MUST_EXIST);
        $slotdata = slot_answer::get_slot_data($answer);
        $this->assertCount(1, $slotdata['slots'], 'Only the kept slot remains.');
        $this->assertSame((int)$nine['start'], (int)$slotdata['slots'][0]['start']);

        // Full cancellation: an empty selection releases the last slot -> booking no longer active.
        $baid2 = $this->book($optionid, $bookingid, $userid, [$nine]);
        $outcome = slot_update_service::apply($optionid, $baid2, $userid, []);
        $this->assertSame(0, (int)$outcome['slotcount']);
        $this->assertFalse(
            $DB->record_exists('booking_answers', ['id' => $baid2, 'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED]),
            'The booking should no longer be active after a full cancellation.'
        );
    }

    /**
     * A mixed shrink (drop + swap in one update) routes by net delta: a cheaper result commits and
     * refunds; a more expensive result holds the reduced selection in the cart and commits on checkout.
     *
     * @return void
     */
    public function test_apply_mixed_shrink_cheaper_and_upgrade(): void {
        global $DB;

        [$optionid, $bookingid, $userid] = $this->create_priced_move_option(5.0);
        [$nine, $ten, $ninealt] = $this->three_slots($optionid, $userid);
        $this->setUser($userid);

        // Cheaper mixed shrink: [09(0), 10(+5)] -> [09alt(0)] (drop both, add one) = net -5 -> refund.
        $baid = $this->book($optionid, $bookingid, $userid, [$nine, $ten]);
        $outcome = slot_update_service::apply($optionid, $baid, $userid, [$ninealt['key']]);
        $this->assertSame('refund', $outcome['mode']);
        $this->assertEqualsWithDelta(-5.0, (float)$outcome['pricedelta'], 0.001);
        $answer = $DB->get_record('booking_answers', ['id' => $baid], '*', MUST_EXIST);
        $slotdata = slot_answer::get_slot_data($answer);
        $this->assertCount(1, $slotdata['slots']);
        $this->assertSame((int)$ninealt['start'], (int)$slotdata['slots'][0]['start']);

        // Upgrading mixed shrink: [09(0), 09alt(0)] -> [10(+5)] = net +5 -> cart (held, not committed).
        $baid2 = $this->book($optionid, $bookingid, $userid, [$nine, $ninealt]);
        $outcome = slot_update_service::apply($optionid, $baid2, $userid, [$ten['key']]);
        $this->assertSame('cart', $outcome['mode']);
        $this->assertEqualsWithDelta(5.0, (float)$outcome['pricedelta'], 0.001);
        $this->assertGreaterThan(0, (int)$outcome['moveid']);

        // The booking is unchanged until the upgrade is paid.
        $answer = $DB->get_record('booking_answers', ['id' => $baid2], '*', MUST_EXIST);
        $slotdata = slot_answer::get_slot_data($answer);
        $this->assertCount(2, $slotdata['slots'], 'Answer must not change before checkout.');

        // On checkout the pending move commits the reduced selection (2 slots -> 1).
        slot_mover::commit_pending_move((int)$outcome['moveid']);
        $answer = $DB->get_record('booking_answers', ['id' => $baid2], '*', MUST_EXIST);
        $slotdata = slot_answer::get_slot_data($answer);
        $this->assertCount(1, $slotdata['slots'], 'Reduced selection committed on checkout.');
        $this->assertSame((int)$ten['start'], (int)$slotdata['slots'][0]['start']);
    }

    /**
     * A manager update (actor='manager') is capability-gated and price-agnostic: a price-changing
     * swap and a reduction both commit directly (never refund/cart), an empty selection is a full
     * cancellation, and growing the booking is rejected — same edit-only rule as self-service.
     *
     * @return void
     */
    public function test_manager_update_is_priceagnostic_and_direct(): void {
        global $DB;

        [$optionid, $bookingid, $userid] = $this->create_priced_move_option(5.0);
        [$nine, $ten, $ninealt] = $this->three_slots($optionid, $userid);
        // The acting user stays the admin from setUp() = a manager with moveslots/updatebooking.

        // Plan(manager): a price-changing change still routes 'direct' (managers never pay/refund).
        $baid = $this->book($optionid, $bookingid, $userid, [$nine, $ten]);
        $plan = slot_update_service::plan($optionid, $baid, $userid, [$ninealt['key']], 'manager');
        $this->assertSame('direct', $plan['route']);
        $this->assertCount(2, $plan['removed']);

        // No-add is rejected for managers too.
        $plan = slot_update_service::plan(
            $optionid,
            $baid,
            $userid,
            [$nine['key'], $ten['key'], $ninealt['key']],
            'manager'
        );
        $this->assertContains('slot_update_no_add', $plan['errors']);

        // Apply(manager) swap: commits directly, no price effect.
        $outcome = slot_update_service::apply($optionid, $baid, $userid, [$ninealt['key'], $ten['key']], '', 'manager');
        $this->assertSame('direct', $outcome['mode']);
        $this->assertEqualsWithDelta(0.0, (float)$outcome['pricedelta'], 0.001);
        $answer = $DB->get_record('booking_answers', ['id' => $baid], '*', MUST_EXIST);
        $slotdata = slot_answer::get_slot_data($answer);
        $this->assertCount(2, $slotdata['slots']);
        $starts = array_map(static fn($s) => (int) $s['start'], $slotdata['slots']);
        $this->assertContains((int) $ninealt['start'], $starts);

        // Apply(manager) reduction: drops a slot, commits directly (no refund mode).
        $baid2 = $this->book($optionid, $bookingid, $userid, [$nine, $ten]);
        $outcome = slot_update_service::apply($optionid, $baid2, $userid, [$nine['key']], '', 'manager');
        $this->assertSame('direct', $outcome['mode']);
        $this->assertSame(1, (int) $outcome['slotcount']);

        // Apply(manager) empty: full cancellation through the deletion path.
        $baid3 = $this->book($optionid, $bookingid, $userid, [$nine]);
        $outcome = slot_update_service::apply($optionid, $baid3, $userid, [], '', 'manager');
        $this->assertSame('cancel', $outcome['mode']);
        $this->assertFalse(
            $DB->record_exists('booking_answers', ['id' => $baid3, 'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED])
        );

        // Growing via apply(manager) is rejected (edit-only).
        $baid4 = $this->book($optionid, $bookingid, $userid, [$nine]);
        $this->expectException(\moodle_exception::class);
        slot_update_service::apply($optionid, $baid4, $userid, [$nine['key'], $ten['key']], '', 'manager');
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
     * Book the given slots for the user (one BOOKED answer).
     *
     * @param int $optionid
     * @param int $bookingid
     * @param int $userid
     * @param array $slots list of picker slots (each with start/end)
     * @return int baid
     */
    private function book(int $optionid, int $bookingid, int $userid, array $slots): int {
        global $DB;
        usort($slots, static fn(array $a, array $b): int => (int)$a['start'] <=> (int)$b['start']);
        $payload = array_map(static fn(array $s): array => ['start' => (int)$s['start'], 'end' => (int)$s['end']], $slots);
        $answer = (object) [
            'bookingid' => $bookingid,
            'optionid' => $optionid,
            'userid' => $userid,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
            'places' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
            'startdate' => (int)$slots[0]['start'],
            'enddate' => (int)$slots[count($slots) - 1]['end'],
            'json' => '',
        ];
        slot_answer::set_slot_data($answer, ['slots' => $payload, 'teachers' => []]);
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
            'text' => 'Update option ' . uniqid('', true),
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
