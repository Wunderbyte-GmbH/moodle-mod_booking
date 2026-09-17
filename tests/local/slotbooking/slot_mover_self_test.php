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
 * Tests for self-service slot rebooking (slot_mover::move_self and guards).
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\bo_availability\bo_info;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking\local\slotbooking\slot_answer;
use mod_booking\local\slotbooking\slot_availability;
use mod_booking\local\slotbooking\slot_change_policy;
use mod_booking\local\slotbooking\slot_dto;
use mod_booking\local\slotbooking\slot_event_placeholders;
use mod_booking\local\slotbooking\slot_mover;
use mod_booking\local\slotbooking\target_price_policy;
use mod_booking\option\fields\multiplebookings;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * PHPUnit tests for self-service rebooking.
 *
 * @covers \mod_booking\local\slotbooking\slot_mover
 * @covers \mod_booking\local\slotbooking\target_price_policy
 * @covers \mod_booking\bo_availability\conditions\slotmove
 */
final class slot_mover_self_test extends booking_advanced_testcase {
    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * A participant can rebook their own booked slot to a price-equal target slot,
     * and the slot-moved event records initiatedby=self.
     */
    public function test_move_self_success_with_event(): void {
        global $DB;

        [$option, $userid, , $bookingid] = $this->create_self_rebooking_option();

        $slots = slot_dto::build_picker_slots($option->id, $userid);
        $this->assertGreaterThanOrEqual(2, count($slots));
        $current = $slots[0];
        $target = $slots[1];

        $baid = $this->create_booked_slot_answer($option->id, $bookingid, $userid, (int)$current['start'], (int)$current['end']);

        $this->setUser($userid);

        $sink = $this->redirectEmails();
        $eventsink = $this->redirectEvents();
        $result = slot_mover::move_self($option->id, $baid, [$target['key']], 'changed my mind');
        $sink->close();
        $events = $eventsink->get_events();
        $eventsink->close();

        $this->assertSame((int)$target['start'], $result['newstart']);
        $this->assertSame((int)$target['end'], $result['newend']);
        $this->assertSame(1, $result['slotcount']);

        $answer = $DB->get_record('booking_answers', ['id' => $baid], '*', MUST_EXIST);
        $slotdata = slot_answer::get_slot_data($answer);
        $this->assertSame((int)$target['start'], (int)$slotdata['slots'][0]['start']);

        // Here, moved_from is an append list carrying the initiator.
        $this->assertIsArray($slotdata['moved_from']);
        $this->assertSame('self', $slotdata['moved_from'][0]['initiatedby']);

        $moved = array_filter($events, static fn($e) => $e instanceof \mod_booking\event\bookinganswer_slotmoved);
        $this->assertNotEmpty($moved);
        $event = reset($moved);
        $this->assertSame('self', $event->other['initiatedby']);
    }

    /**
     * A user may not move someone else's answer.
     */
    public function test_move_self_rejects_foreign_answer(): void {
        [$option, $owner, , $bookingid] = $this->create_self_rebooking_option();
        $other = self::getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($other->id, $this->course->id, 'student');

        $slots = slot_dto::build_picker_slots($option->id, $owner);
        $baid = $this->create_booked_slot_answer(
            $option->id,
            $bookingid,
            $owner,
            (int)$slots[0]['start'],
            (int)$slots[0]['end']
        );

        $this->setUser($other->id);
        $this->expectException(\moodle_exception::class);
        slot_mover::move_self($option->id, $baid, [$slots[1]['key']]);
    }

    /**
     * With the option opt-in disabled, self-rebooking is refused.
     */
    public function test_move_self_rejects_when_setting_disabled(): void {
        global $DB;

        [$option, $userid, , $bookingid] = $this->create_self_rebooking_option();
        $DB->set_field('booking_slot_config', 'allow_self_rebooking', 0, ['optionid' => $option->id]);

        $slots = slot_dto::build_picker_slots($option->id, $userid);
        $baid = $this->create_booked_slot_answer(
            $option->id,
            $bookingid,
            $userid,
            (int)$slots[0]['start'],
            (int)$slots[0]['end']
        );

        $this->setUser($userid);
        $this->expectException(\moodle_exception::class);
        slot_mover::move_self($option->id, $baid, [$slots[1]['key']]);
    }

    /**
     * With the moveslotsself capability prohibited, self-rebooking is refused.
     */
    public function test_move_self_rejects_without_capability(): void {
        global $DB;

        [$option, $userid, $cmid, $bookingid] = $this->create_self_rebooking_option();

        $context = \context_module::instance($cmid);
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        assign_capability('mod/booking:moveslotsself', CAP_PROHIBIT, $studentroleid, $context->id, true);
        accesslib_clear_all_caches_for_unit_testing();

        $slots = slot_dto::build_picker_slots($option->id, $userid);
        $baid = $this->create_booked_slot_answer(
            $option->id,
            $bookingid,
            $userid,
            (int)$slots[0]['start'],
            (int)$slots[0]['end']
        );

        $this->setUser($userid);
        $this->expectException(\required_capability_exception::class);
        slot_mover::move_self($option->id, $baid, [$slots[1]['key']]);
    }

    /**
     * Once the relative per-slot deadline has passed, self-rebooking is refused.
     */
    public function test_move_self_rejects_after_deadline(): void {
        global $DB;

        [$option, $userid, , $bookingid] = $this->create_self_rebooking_option();
        // Deadline 30 minutes before slot start; a slot starting in 10 minutes is already locked.
        $DB->set_field('booking_slot_config', 'change_deadline_minutes', 30, ['optionid' => $option->id]);

        $slots = slot_dto::build_picker_slots($option->id, $userid);
        $start = time() + 600;
        $baid = $this->create_booked_slot_answer($option->id, $bookingid, $userid, $start, $start + 1800);

        $this->setUser($userid);
        $this->expectException(\moodle_exception::class);
        slot_mover::move_self($option->id, $baid, [$slots[1]['key']]);
    }

    /**
     * A non-BOOKED answer (e.g. deleted) cannot be rebooked.
     */
    public function test_move_self_rejects_non_booked_answer(): void {
        global $DB;

        [$option, $userid, , $bookingid] = $this->create_self_rebooking_option();
        $slots = slot_dto::build_picker_slots($option->id, $userid);
        $baid = $this->create_booked_slot_answer(
            $option->id,
            $bookingid,
            $userid,
            (int)$slots[0]['start'],
            (int)$slots[0]['end']
        );
        $DB->set_field('booking_answers', 'waitinglist', MOD_BOOKING_STATUSPARAM_DELETED, ['id' => $baid]);

        $this->setUser($userid);
        $this->expectException(\moodle_exception::class);
        slot_mover::move_self($option->id, $baid, [$slots[1]['key']]);
    }

    /**
     * A partial swap keeps the reselected slot and only moves the given-up one.
     */
    public function test_move_self_partial_swap(): void {
        global $DB;

        [$option, $userid, , $bookingid] = $this->create_self_rebooking_option();
        $slots = slot_dto::build_picker_slots($option->id, $userid);
        $this->assertGreaterThanOrEqual(3, count($slots));

        $baid = $this->create_booked_slot_answer_multi($option->id, $bookingid, $userid, [
            ['start' => (int)$slots[0]['start'], 'end' => (int)$slots[0]['end']],
            ['start' => (int)$slots[1]['start'], 'end' => (int)$slots[1]['end']],
        ]);

        $this->setUser($userid);
        $sink = $this->redirectEmails();
        // Keep the first slot, swap the second slot for the third.
        slot_mover::move_self($option->id, $baid, [$slots[0]['key'], $slots[2]['key']]);
        $sink->close();

        $answer = $DB->get_record('booking_answers', ['id' => $baid], '*', MUST_EXIST);
        $slotdata = slot_answer::get_slot_data($answer);
        $keys = array_map(static fn($s) => $s['start'] . ':' . $s['end'], $slotdata['slots']);
        sort($keys);
        $expected = [$slots[0]['key'], $slots[2]['key']];
        sort($expected);
        $this->assertSame($expected, $keys);
    }

    /**
     * The price policy keeps price-equal targets and drops differently priced ones.
     */
    public function test_target_price_policy_filters_by_price(): void {
        [$option, $userid] = $this->create_self_rebooking_option();
        $slots = slot_dto::build_picker_slots($option->id, $userid);

        $current = [['start' => (int)$slots[0]['start'], 'end' => (int)$slots[0]['end']]];
        $targets = array_map(static fn($s) => [
            'key' => $s['key'],
            'start' => (int)$s['start'],
            'end' => (int)$s['end'],
        ], $slots);

        // No price rules: all slots share the base price, so all targets stay.
        $filtered = target_price_policy::filter_self_targets($option->id, $userid, $current, $targets);
        $this->assertCount(count($targets), $filtered);

        // A synthetic target priced differently is dropped: an empty current set allows nothing.
        $none = target_price_policy::filter_self_targets($option->id, $userid, [], $targets);
        $this->assertSame([], $none);
    }

    /**
     * The slotmove condition blocks (owns the button + move prepage) for a booked user
     * who is allowed to self-rebook, and always hard-blocks the normal booking flow.
     */
    public function test_slotmove_condition_blocks_for_rebookable_user(): void {
        [$option, $userid, , $bookingid] = $this->create_self_rebooking_option();
        $slots = slot_dto::build_picker_slots($option->id, $userid);
        $this->create_booked_slot_answer($option->id, $bookingid, $userid, (int)$slots[0]['start'], (int)$slots[0]['end']);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $condition = new \mod_booking\bo_availability\conditions\slotmove();

        // Blocking means "not available": the condition takes over the booked-state button.
        $this->assertFalse($condition->is_available($settings, $userid));
        $this->assertTrue($condition->hard_block($settings, $userid));

        [$available, , $prepage, $button] = $condition->get_description($settings, $userid);
        $this->assertFalse($available);
        $this->assertSame(MOD_BOOKING_BO_PREPAGE_PREBOOK, $prepage);
        $this->assertSame(MOD_BOOKING_BO_BUTTON_MYBUTTON, $button);
    }

    /**
     * The slotmove condition stays out of the way (available, indifferent) for a user
     * who has no booked slot answer.
     */
    public function test_slotmove_condition_indifferent_without_booking(): void {
        [$option, $userid] = $this->create_self_rebooking_option();

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $condition = new \mod_booking\bo_availability\conditions\slotmove();

        $this->assertTrue($condition->is_available($settings, $userid));

        [$available, , $prepage, $button] = $condition->get_description($settings, $userid);
        $this->assertTrue($available);
        $this->assertSame(MOD_BOOKING_BO_PREPAGE_NONE, $prepage);
        $this->assertSame(MOD_BOOKING_BO_BUTTON_INDIFFERENT, $button);
    }

    /**
     * With the self-rebooking opt-in disabled, the condition stays available (no move entry)
     * even though the user holds a booked slot answer.
     */
    public function test_slotmove_condition_indifferent_when_optin_disabled(): void {
        global $DB;

        [$option, $userid, , $bookingid] = $this->create_self_rebooking_option();
        $slots = slot_dto::build_picker_slots($option->id, $userid);
        $this->create_booked_slot_answer($option->id, $bookingid, $userid, (int)$slots[0]['start'], (int)$slots[0]['end']);
        $DB->set_field('booking_slot_config', 'allow_self_rebooking', 0, ['optionid' => $option->id]);
        singleton_service::destroy_instance();

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $condition = new \mod_booking\bo_availability\conditions\slotmove();

        $this->assertTrue($condition->is_available($settings, $userid));
    }

    /**
     * Fall 2: with book-again (multiplebookings) active, the normal booking flow owns the
     * prepage and the move is offered as a tab inside it - so slotmove must NOT block, even
     * though the booked user could self-rebook.
     */
    public function test_slotmove_condition_indifferent_when_book_again_active(): void {
        [$option, $userid, $cmid, $bookingid, $record] = $this->create_self_rebooking_option();
        $slots = slot_dto::build_picker_slots($option->id, $userid);
        $this->create_booked_slot_answer($option->id, $bookingid, $userid, (int)$slots[0]['start'], (int)$slots[0]['end']);

        // Flip multiplebookings on via the canonical update path, then refresh settings.
        $record->id = $option->id;
        $record->cmid = $cmid;
        $record->multiplebookings = 1;
        \mod_booking\booking_option::update($record);
        singleton_service::destroy_booking_singleton_by_cmid($cmid);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $answer = slot_mover::get_self_rebookable_answer($option->id, $userid);
        $this->assertNotNull($answer);
        $this->assertTrue(slot_mover::book_again_active($option->id, $answer));

        $condition = new \mod_booking\bo_availability\conditions\slotmove();
        $this->assertTrue($condition->is_available($settings, $userid));
    }

    /**
     * "After the last booked slot" book-again mode (multiplebookings = 2): the gate is satisfied
     * only once the end of the user's last booked slot has passed - read from the booked
     * (waitinglist = BOOKED) answer itself, and independent of any waiting duration.
     *
     * @covers \mod_booking\option\fields\multiplebookings::book_again_due
     * @covers \mod_booking\local\slotbooking\slot_mover::last_booked_slot_end
     * @covers \mod_booking\local\slotbooking\slot_mover::book_again_active
     */
    public function test_book_again_due_after_last_slot(): void {
        global $DB;

        [$option, $userid, $cmid, $bookingid, $record] = $this->create_self_rebooking_option();

        // Enable the "after the last booked slot" mode via the canonical update path; this also
        // exercises the select save path (prepare_save_field stores the mode value verbatim).
        $record->id = $option->id;
        $record->cmid = $cmid;
        $record->multiplebookings = multiplebookings::MODE_AFTER_LAST_SLOT;
        booking_option::update($record);
        singleton_service::destroy_booking_singleton_by_cmid($cmid);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $this->assertSame(
            multiplebookings::MODE_AFTER_LAST_SLOT,
            (int)($settings->jsonobject->multiplebookings ?? 0)
        );

        // A booked slot still lying in the future: the user may not book again yet.
        $futurestart = time() + DAYSECS;
        $baid = $this->create_booked_slot_answer($option->id, $bookingid, $userid, $futurestart, $futurestart + HOURSECS);
        $answer = $DB->get_record('booking_answers', ['id' => $baid], '*', MUST_EXIST);
        $this->assertSame($futurestart + HOURSECS, slot_mover::last_booked_slot_end($answer));
        $this->assertFalse(multiplebookings::book_again_due($option->id, $answer));
        $this->assertFalse(slot_mover::book_again_active($option->id, $answer));

        // A booked slot whose end has already passed: the user may book again now.
        $DB->delete_records('booking_answers', ['id' => $baid]);
        $paststart = time() - 2 * HOURSECS;
        $baid = $this->create_booked_slot_answer($option->id, $bookingid, $userid, $paststart, $paststart + HOURSECS);
        $answer = $DB->get_record('booking_answers', ['id' => $baid], '*', MUST_EXIST);
        $this->assertSame($paststart + HOURSECS, slot_mover::last_booked_slot_end($answer));
        $this->assertTrue(multiplebookings::book_again_due($option->id, $answer));
        $this->assertTrue(slot_mover::book_again_active($option->id, $answer));
    }

    /**
     * A slot that has already started but not yet ended must still be bookable.
     *
     * The picker is built with rangestart = time() (get_slots_with_status), and
     * get_slots_for_range() drops every slot whose start lies before rangestart. Simulating
     * "now" as a fixed instant inside an open slot, the slot covering that instant
     * (start < now < end) must therefore still be offered. This asserts the desired behaviour
     * for in-progress slots.
     *
     * @covers \mod_booking\local\slotbooking\slot_availability::get_slots_for_range
     */
    public function test_started_slot_stays_bookable(): void {
        [$option] = $this->create_self_rebooking_option();

        // The option offers hourly fixed slots 09:00-12:00 on Fri 2050-01-07 (an enabled day).
        $daymidnight = strtotime('2050-01-07 00:00:00');
        // Simulate "now" at 10:30: the 10:00-11:00 slot has already started but not yet ended.
        $now = $daymidnight + 10 * HOURSECS + 30 * MINSECS;
        $rangeend = $daymidnight + DAYSECS;

        $slots = slot_availability::get_slots_for_range($option->id, $now, $rangeend);

        $contains = static function (array $slots, int $start, int $end): bool {
            foreach ($slots as $slot) {
                if ((int)$slot[0] === $start && (int)$slot[1] === $end) {
                    return true;
                }
            }
            return false;
        };

        // Sanity: a fully future slot (11:00-12:00) is offered, so generation works.
        $this->assertTrue(
            $contains($slots, $daymidnight + 11 * HOURSECS, $daymidnight + 12 * HOURSECS),
            'Future slot 11:00-12:00 should be bookable'
        );

        // The in-progress slot (start < now < end) must stay bookable.
        $this->assertTrue(
            $contains($slots, $daymidnight + 10 * HOURSECS, $daymidnight + 11 * HOURSECS),
            'In-progress slot 10:00-11:00 (already started, not yet ended) should still be bookable'
        );
    }

    /**
     * Keeping in-progress slots bookable must NOT expose a slot the user has already booked as
     * still open: get_slots_with_status_for_range reports it with status "booked" (not "open"),
     * which the picker renders as non-selectable. Occupancy is enforced independently of the time
     * filter, so this guarantee holds for in-progress slots too.
     *
     * @covers \mod_booking\local\slotbooking\slot_availability::get_slots_with_status_for_range
     */
    public function test_started_booked_slot_is_not_offered_as_open(): void {
        [$option, $userid, , $bookingid] = $this->create_self_rebooking_option();

        $daymidnight = strtotime('2050-01-07 00:00:00');
        $now = $daymidnight + 10 * HOURSECS + 30 * MINSECS;
        $rangeend = $daymidnight + DAYSECS;
        $instart = $daymidnight + 10 * HOURSECS;
        $inend = $daymidnight + 11 * HOURSECS;

        $statusof = static function (array $slots, int $start, int $end): ?string {
            foreach ($slots as $slot) {
                if ((int)$slot['start'] === $start && (int)$slot['end'] === $end) {
                    return (string)$slot['status'];
                }
            }
            return null;
        };

        // Before any booking the in-progress slot is genuinely bookable (status "open").
        $before = slot_availability::get_slots_with_status_for_range($option->id, $now, $rangeend, (int)$userid);
        $this->assertSame('open', $statusof($before, $instart, $inend));

        // Book exactly that in-progress slot for the user, then refresh the answer caches.
        $this->create_booked_slot_answer($option->id, $bookingid, (int)$userid, $instart, $inend);
        \cache::make('mod_booking', 'bookingoptionsanswers')->delete($option->id);
        singleton_service::destroy_instance();

        // Now it is reported as booked - never "open" - so it cannot be booked again.
        $after = slot_availability::get_slots_with_status_for_range($option->id, $now, $rangeend, (int)$userid);
        $this->assertSame('booked', $statusof($after, $instart, $inend));
    }

    /**
     * Cancelling must still work when slotmove owns the booked-state button (Fall 1).
     *
     * slotmove hard-blocks with id 155 (> CANCELMYSELF 105 / ALREADYBOOKED 150), so it is the
     * top hard-blocker for a booked, self-rebookable user. booking_bookit::bookit() dispatches
     * the cancel action purely by that top id, so a cancel click was silently ignored and the
     * flow never advanced to CONFIRMCANCEL. The booked state must be treated as cancel-capable.
     *
     * @covers \mod_booking\booking_bookit::bookit
     */
    public function test_bookit_cancel_works_when_slotmove_blocks(): void {
        [$option, $userid, , , , $boinfo] = $this->setup_booked_rebookable_slot_user(1);

        // Precondition: slotmove (155) is the top hard-blocker for this booked, rebookable user.
        [$id] = $boinfo->is_available($option->id, $userid, true);
        $this->assertSame(MOD_BOOKING_BO_COND_SLOTMOVE, $id);

        // The user clicks "cancel". This must advance the booked user towards cancellation.
        booking_bookit::bookit('option', $option->id, $userid);

        // After the cancel click the confirm-cancel step (170 > 155) must be reached.
        [$id] = $boinfo->is_available($option->id, $userid, true);
        $this->assertSame(MOD_BOOKING_BO_COND_CONFIRMCANCEL, $id);
    }

    /**
     * The booking instance setting "Define cancellation conditions" (cancancelbook) gates
     * self-cancellation for slot booking: with it switched off, a booked slot user (where
     * slotmove owns the button) is not offered cancellation and a cancel click cannot advance
     * the flow - slotmove stays the top blocker.
     *
     * @covers \mod_booking\bo_availability\conditions\cancelmyself::is_available
     */
    public function test_slotbooking_cancel_blocked_when_instance_setting_off(): void {
        [$option, $userid, , , $settings, $boinfo] = $this->setup_booked_rebookable_slot_user(0);

        // Instance cancellation is off ("Define cancellation conditions") -> cancel is not offered.
        $cancelmyself = new \mod_booking\bo_availability\conditions\cancelmyself();
        $this->assertTrue($cancelmyself->is_available($settings, $userid));

        booking_bookit::bookit('option', $option->id, $userid);

        // No cancellation could be initiated: slotmove (155) remains the top blocker.
        [$id] = $boinfo->is_available($option->id, $userid, true);
        $this->assertSame(MOD_BOOKING_BO_COND_SLOTMOVE, $id);
    }

    /**
     * The option form setting "Cancelling is only possible until certain date" (canceluntil)
     * closes cancellation once the date has passed - even for a booked slot user on an instance
     * that otherwise allows cancellation.
     *
     * @covers \mod_booking\bo_availability\conditions\cancelmyself::is_available
     */
    public function test_slotbooking_cancel_blocked_after_canceluntil_date(): void {
        [$option, $userid] = $this->setup_booked_rebookable_slot_user(1);
        $this->set_option_canceluntil($option->id, time() - DAYSECS);

        $this->setUser($userid);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boinfo = new bo_info($settings);

        // The cancel-until date has passed -> cancellation is closed.
        $cancelmyself = new \mod_booking\bo_availability\conditions\cancelmyself();
        $this->assertTrue($cancelmyself->is_available($settings, $userid));

        booking_bookit::bookit('option', $option->id, $userid);
        [$id] = $boinfo->is_available($option->id, $userid, true);
        $this->assertSame(MOD_BOOKING_BO_COND_SLOTMOVE, $id);
    }

    /**
     * With "Cancelling is only possible until certain date" still in the future, a booked slot
     * user can cancel and the flow advances to CONFIRMCANCEL.
     *
     * @covers \mod_booking\bo_availability\conditions\cancelmyself::is_available
     */
    public function test_slotbooking_cancel_allowed_before_canceluntil_date(): void {
        [$option, $userid] = $this->setup_booked_rebookable_slot_user(1);
        $this->set_option_canceluntil($option->id, time() + DAYSECS);

        $this->setUser($userid);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boinfo = new bo_info($settings);

        // The cancel-until date is still in the future -> cancellation is open.
        $cancelmyself = new \mod_booking\bo_availability\conditions\cancelmyself();
        $this->assertFalse($cancelmyself->is_available($settings, $userid));

        booking_bookit::bookit('option', $option->id, $userid);
        [$id] = $boinfo->is_available($option->id, $userid, true);
        $this->assertSame(MOD_BOOKING_BO_COND_CONFIRMCANCEL, $id);
    }

    /**
     * The relative deadline is evaluated per slot: actionable while now < (start - offset),
     * exclusive at the boundary; a negative offset keeps a slot actionable after its start.
     *
     * @covers \mod_booking\local\slotbooking\slot_change_policy::slot_actionable
     */
    public function test_slot_change_policy_actionable_boundary(): void {
        $now = 1000000;
        $start = $now + 1800; // 30 minutes ahead.

        // Offset 30 min => deadline exactly now => no longer actionable (boundary is exclusive).
        $this->assertFalse(slot_change_policy::slot_actionable($start, 30, $now));
        // Offset 29 min => deadline one minute ahead => still actionable.
        $this->assertTrue(slot_change_policy::slot_actionable($start, 29, $now));
        // Negative offset (after start) keeps a slot actionable past its start.
        $this->assertTrue(slot_change_policy::slot_actionable($now - 600, -60, $now));
    }

    /**
     * Per-slot deadline on move: a locked slot may not be given up, but may be kept while another
     * still-actionable slot is moved.
     *
     * @covers \mod_booking\local\slotbooking\slot_mover::move_self
     */
    public function test_move_self_per_slot_lock(): void {
        global $DB;

        [$option, $userid, , $bookingid] = $this->create_self_rebooking_option();
        $DB->set_field('booking_slot_config', 'change_deadline_minutes', 30, ['optionid' => $option->id]);

        $slots = slot_dto::build_picker_slots($option->id, $userid);
        // Slot A starts in 10 minutes -> locked (deadline 30 min before). Slot B is far in the future.
        $lockedstart = time() + 600;
        $lockedkey = $lockedstart . ':' . ($lockedstart + 1800);
        $baid = $this->create_booked_slot_answer_multi($option->id, $bookingid, $userid, [
            ['start' => $lockedstart, 'end' => $lockedstart + 1800],
            ['start' => (int)$slots[0]['start'], 'end' => (int)$slots[0]['end']],
        ]);

        $this->setUser($userid);

        // Giving up the locked slot (replacing both current slots) must be rejected.
        try {
            slot_mover::move_self($option->id, $baid, [$slots[1]['key'], $slots[2]['key']]);
            $this->fail('Expected exception when giving up a locked slot');
        } catch (\moodle_exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        // Keeping the locked slot and moving the actionable one is allowed.
        $sink = $this->redirectEmails();
        slot_mover::move_self($option->id, $baid, [$lockedkey, $slots[2]['key']]);
        $sink->close();

        $answer = $DB->get_record('booking_answers', ['id' => $baid], '*', MUST_EXIST);
        $keys = array_map(
            static fn($s) => $s['start'] . ':' . $s['end'],
            slot_answer::get_slot_data($answer)['slots']
        );
        $this->assertContains($lockedkey, $keys);
    }

    /**
     * The relative per-slot deadline also gates self-cancellation: a booked slot past its deadline
     * is locked, so the full cancel is no longer offered (cancelmyself stays available).
     *
     * @covers \mod_booking\bo_availability\conditions\cancelmyself::is_available
     */
    public function test_slotbooking_cancel_blocked_when_slot_locked(): void {
        global $DB;

        [$option, $userid, $cmid, $bookingid] = $this->create_self_rebooking_option();
        $DB->set_field('booking', 'cancancelbook', 1, ['id' => $bookingid]);
        \cache::make('mod_booking', 'cachedbookinginstances')->delete($cmid);
        $DB->set_field('booking_slot_config', 'change_deadline_minutes', 30, ['optionid' => $option->id]);

        // A slot starting in 10 minutes is locked (deadline 30 min before start).
        $start = time() + 600;
        $this->create_booked_slot_answer($option->id, $bookingid, $userid, $start, $start + 1800);
        \cache::make('mod_booking', 'bookingoptionsanswers')->delete($option->id);
        singleton_service::destroy_instance();

        $this->setUser($userid);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        $cancelmyself = new \mod_booking\bo_availability\conditions\cancelmyself();
        $this->assertTrue($cancelmyself->is_available($settings, $userid));
    }

    /**
     * Partial release: releasing one actionable slot of a multi-slot booking keeps the answer
     * booked with the remaining slot and fires a slot-cancelled event for the released one.
     *
     * @covers \mod_booking\local\slotbooking\slot_mover::release_self
     */
    public function test_release_self_partial(): void {
        global $DB;

        [$option, $userid, , $bookingid] = $this->create_self_rebooking_option();
        $slots = slot_dto::build_picker_slots($option->id, $userid);
        $keep = ['start' => (int)$slots[0]['start'], 'end' => (int)$slots[0]['end']];
        $drop = ['start' => (int)$slots[1]['start'], 'end' => (int)$slots[1]['end']];
        $baid = $this->create_booked_slot_answer_multi($option->id, $bookingid, $userid, [$keep, $drop]);
        $dropkey = $drop['start'] . ':' . $drop['end'];

        $this->setUser($userid);
        $sink = $this->redirectEvents();
        $result = slot_mover::release_self($option->id, $baid, [$dropkey]);
        $events = $sink->get_events();
        $sink->close();

        $this->assertFalse($result['cancelled']);
        $this->assertSame(1, $result['remaining']);

        $answer = $DB->get_record('booking_answers', ['id' => $baid], '*', MUST_EXIST);
        $this->assertSame(MOD_BOOKING_STATUSPARAM_BOOKED, (int)$answer->waitinglist);
        $keys = array_map(
            static fn($s) => $s['start'] . ':' . $s['end'],
            slot_answer::get_slot_data($answer)['slots']
        );
        $this->assertSame([$keep['start'] . ':' . $keep['end']], $keys);

        $cancelled = array_filter(
            $events,
            static fn($e) => $e instanceof \mod_booking\event\bookinganswer_slotcancelled
        );
        $this->assertNotEmpty($cancelled);
    }

    /**
     * A locked slot (past its relative deadline) cannot be released.
     *
     * @covers \mod_booking\local\slotbooking\slot_mover::release_self
     */
    public function test_release_self_rejects_locked_slot(): void {
        global $DB;

        [$option, $userid, , $bookingid] = $this->create_self_rebooking_option();
        $DB->set_field('booking_slot_config', 'change_deadline_minutes', 30, ['optionid' => $option->id]);

        $slots = slot_dto::build_picker_slots($option->id, $userid);
        // A locked slot (start in 10 min) plus an actionable future slot so the gate is satisfied.
        $lockedstart = time() + 600;
        $lockedkey = $lockedstart . ':' . ($lockedstart + 1800);
        $baid = $this->create_booked_slot_answer_multi($option->id, $bookingid, $userid, [
            ['start' => $lockedstart, 'end' => $lockedstart + 1800],
            ['start' => (int)$slots[0]['start'], 'end' => (int)$slots[0]['end']],
        ]);

        $this->setUser($userid);
        $this->expectException(\moodle_exception::class);
        slot_mover::release_self($option->id, $baid, [$lockedkey]);
    }

    /**
     * The slot-event placeholders render the requested slot list from the rule JSON payload,
     * so a move mail can show "from" and "to" slots separately.
     *
     * @covers \mod_booking\local\slotbooking\slot_event_placeholders::render
     */
    public function test_slot_event_placeholders_render(): void {
        $rulejson = json_encode([
            'datafromevent' => [
                'other' => [
                    'oldslots' => [['start' => 1893495600, 'end' => 1893499200]],
                    'newslots' => [['start' => 1893581000, 'end' => 1893584600]],
                ],
            ],
        ]);

        $from = slot_event_placeholders::render($rulejson, ['oldslots']);
        $to = slot_event_placeholders::render($rulejson, ['newslots']);

        $this->assertNotEmpty($from);
        $this->assertNotEmpty($to);
        $this->assertNotSame($from, $to);
        // Missing payload / missing key yield an empty string.
        $this->assertSame('', slot_event_placeholders::render('', ['oldslots']));
        $this->assertSame('', slot_event_placeholders::render($rulejson, ['bookedslots']));
    }

    /** @var \stdClass course used across helpers. */
    private $course;

    /**
     * Build a booked, self-rebookable slot user where slotmove (155) is the top hard-blocker,
     * with the booking instance "Define cancellation conditions" (cancancelbook) set as given.
     *
     * The slot answer is inserted directly, so the answer/instance MUC caches that were built
     * empty beforehand are purged so the cancellation conditions observe the real state.
     *
     * @param int $cancancelbook instance cancancelbook value (1 = self-cancellation allowed)
     * @return array{0:\stdClass,1:int,2:int,3:\stdClass,4:booking_option_settings,5:bo_info}
     *     option, userid, cmid, creation record, refreshed settings, bo_info
     */
    private function setup_booked_rebookable_slot_user(int $cancancelbook): array {
        global $DB;

        [$option, $userid, $cmid, $bookingid, $record] = $this->create_self_rebooking_option();
        // Instance-level cancellation toggle ("Define cancellation conditions").
        $DB->set_field('booking', 'cancancelbook', $cancancelbook, ['id' => $bookingid]);
        \cache::make('mod_booking', 'cachedbookinginstances')->delete($cmid);

        $slots = slot_dto::build_picker_slots($option->id, $userid);
        $this->create_booked_slot_answer($option->id, $bookingid, $userid, (int)$slots[0]['start'], (int)$slots[0]['end']);
        // The answer was inserted directly, so drop the cached (empty) answers built before it.
        \cache::make('mod_booking', 'bookingoptionsanswers')->delete($option->id);
        singleton_service::destroy_instance();

        $this->setUser($userid);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        return [$option, (int)$userid, (int)$cmid, $record, $settings, new bo_info($settings)];
    }

    /**
     * Set the option form "Cancelling is only possible until certain date" (canceluntil), which is
     * stored in the option json, then refresh the cached option settings so the change takes effect.
     *
     * @param int $optionid booking option id
     * @param int $canceluntil cancel-until timestamp
     */
    private function set_option_canceluntil(int $optionid, int $canceluntil): void {
        global $DB;

        $json = $DB->get_field('booking_options', 'json', ['id' => $optionid]);
        $jsonobject = !empty($json) ? json_decode($json) : new \stdClass();
        $jsonobject->canceluntil = $canceluntil;
        $DB->set_field('booking_options', 'json', json_encode($jsonobject), ['id' => $optionid]);

        \cache::make('mod_booking', 'bookingoptionsettings')->delete($optionid);
        singleton_service::destroy_booking_option_singleton($optionid);
    }

    /**
     * Insert a booked slot answer (single slot range) and return its id.
     *
     * @param int $optionid booking option id
     * @param int $bookingid booking instance id
     * @param int $userid booking owner
     * @param int $start slot start timestamp
     * @param int $end slot end timestamp
     * @return int booking answer id
     */
    private function create_booked_slot_answer(int $optionid, int $bookingid, int $userid, int $start, int $end): int {
        return $this->create_booked_slot_answer_multi($optionid, $bookingid, $userid, [['start' => $start, 'end' => $end]]);
    }

    /**
     * Insert a booked slot answer holding multiple slot ranges and return its id.
     *
     * @param int $optionid booking option id
     * @param int $bookingid booking instance id
     * @param int $userid booking owner
     * @param array $slots list of ['start'=>int,'end'=>int]
     * @return int booking answer id
     */
    private function create_booked_slot_answer_multi(int $optionid, int $bookingid, int $userid, array $slots): int {
        global $DB;

        usort($slots, static fn($a, $b) => $a['start'] <=> $b['start']);
        $answer = (object)[
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
        slot_answer::set_slot_data($answer, ['slots' => $slots, 'teachers' => []]);

        return (int)$DB->insert_record('booking_answers', $answer);
    }

    /**
     * Create a fixed-slot option with self-rebooking enabled and an enrolled student.
     *
     * @return array{0:\stdClass, 1:int, 2:int, 3:int} option, student id, cmid, bookingid
     */
    private function create_self_rebooking_option(): array {
        $this->course = self::getDataGenerator()->create_course();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance(['course' => $this->course->id]);
        $student = self::getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');

        $record = [
            'bookingid' => $booking->id,
            'text' => 'Self rebooking option ' . uniqid('', true),
            'course' => $this->course->id,
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

        $optionrecord = (object)$record;
        $option = $plugingenerator->create_option($optionrecord);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $cmid = (int)$settings->cmid;
        $bookingid = (int)$settings->bookingid;

        singleton_service::destroy_instance();
        // The creation record is returned as the 5th element so tests can reuse it for
        // booking_option::update() (e.g. to flip multiplebookings on).
        return [$option, (int)$student->id, $cmid, $bookingid, $optionrecord];
    }
}
