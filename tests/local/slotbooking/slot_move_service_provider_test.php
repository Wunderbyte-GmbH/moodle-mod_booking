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

use mod_booking\local\slotbooking\slot_answer;
use mod_booking\local\slotbooking\slot_dto;
use mod_booking\local\slotbooking\slot_move_store;
use mod_booking\shopping_cart\service_provider;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests the shopping_cart service_provider 'moveslot' area (MP-C, upgrade path).
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\shopping_cart\service_provider
 * @covers     \mod_booking\local\slotbooking\slot_mover::commit_pending_move
 */
final class slot_move_service_provider_test extends booking_advanced_testcase {
    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * load_cartitem builds a moveslot cart item with the price delta; a successful checkout
     * commits the held move onto the booking answer and marks the move committed.
     *
     * @return void
     */
    public function test_moveslot_load_and_checkout_commits_move(): void {
        global $DB;

        [$optionid, $bookingid, $userid] = $this->create_slot_option();
        $slots = slot_dto::build_picker_slots($optionid, $userid);
        $this->assertGreaterThanOrEqual(2, count($slots));
        $current = $slots[0];
        $target = $slots[1];

        $baid = $this->create_booked_slot_answer($optionid, $bookingid, $userid, (int)$current['start'], (int)$current['end']);

        $moveid = slot_move_store::create_pending(
            $optionid,
            $baid,
            $userid,
            [['start' => (int)$target['start'], 'end' => (int)$target['end']]],
            [['start' => (int)$current['start'], 'end' => (int)$current['end']]],
            12.50,
            time() + HOURSECS
        );

        // Here, load_cartitem returns a moveslot cart item keyed by the OPTION id (ledger-traceable),
        // carrying the price delta.
        $loaded = service_provider::load_cartitem('moveslot', $optionid, $userid);
        $this->assertArrayHasKey('cartitem', $loaded);
        $data = $loaded['cartitem']->as_array();
        $this->assertEquals('moveslot', $data['area']);
        $this->assertEquals($optionid, (int)$data['itemid']);
        $this->assertEqualsWithDelta(12.50, (float)$data['price'], 0.001);

        // Successful checkout commits the move onto the answer and marks the row committed.
        $this->assertTrue(
            service_provider::successful_checkout('moveslot', $optionid, LOCAL_SHOPPING_CART_PAYMENT_METHOD_CASHIER, $userid)
        );

        $answer = $DB->get_record('booking_answers', ['id' => $baid], '*', MUST_EXIST);
        $slotdata = slot_answer::get_slot_data($answer);
        $this->assertSame((int)$target['start'], (int)$slotdata['slots'][0]['start'], 'Answer should now hold the target slot.');

        $this->assertEquals(slot_move_store::STATUS_COMMITTED, (int) slot_move_store::get($moveid)->status);
    }

    /**
     * Unloading a moveslot cart item (abort/expiry) cancels the pending move; the answer is
     * untouched.
     *
     * @return void
     */
    public function test_moveslot_unload_cancels_move(): void {
        global $DB;

        [$optionid, $bookingid, $userid] = $this->create_slot_option();
        $slots = slot_dto::build_picker_slots($optionid, $userid);
        $current = $slots[0];
        $target = $slots[1];
        $baid = $this->create_booked_slot_answer($optionid, $bookingid, $userid, (int)$current['start'], (int)$current['end']);

        $moveid = slot_move_store::create_pending(
            $optionid,
            $baid,
            $userid,
            [['start' => (int)$target['start'], 'end' => (int)$target['end']]],
            [['start' => (int)$current['start'], 'end' => (int)$current['end']]],
            12.50,
            time() + HOURSECS
        );

        $result = service_provider::unload_cartitem('moveslot', $optionid, $userid);
        $this->assertEquals(1, $result['success']);
        $this->assertEquals(slot_move_store::STATUS_CANCELLED, (int) slot_move_store::get($moveid)->status);

        // The booked answer still holds the original slot.
        $answer = $DB->get_record('booking_answers', ['id' => $baid], '*', MUST_EXIST);
        $slotdata = slot_answer::get_slot_data($answer);
        $this->assertSame((int)$current['start'], (int)$slotdata['slots'][0]['start'], 'Answer must keep the original slot.');
    }

    /**
     * load_cartitem rejects a move that is not pending or belongs to a different user.
     *
     * @return void
     */
    public function test_moveslot_load_rejects_foreign_or_committed(): void {
        [$optionid, $bookingid, $userid] = $this->create_slot_option();
        $slots = slot_dto::build_picker_slots($optionid, $userid);
        $target = $slots[1];

        $moveid = slot_move_store::create_pending(
            $optionid,
            0,
            $userid,
            [['start' => (int)$target['start'], 'end' => (int)$target['end']]],
            [],
            12.50,
            time() + HOURSECS
        );

        // Different user has no pending move for this option.
        $this->assertArrayHasKey('error', service_provider::load_cartitem('moveslot', $optionid, $userid + 999));

        // Committed move is no longer pending, so not loadable.
        slot_move_store::commit($moveid);
        $this->assertArrayHasKey('error', service_provider::load_cartitem('moveslot', $optionid, $userid));
    }

    /**
     * The cart item description shows only the slots that actually changed: on a partial move
     * (keep one slot, swap another) the kept slot is excluded — only "given up -> taken" appears.
     *
     * @return void
     */
    public function test_moveslot_description_shows_only_changed_slots(): void {
        [$optionid, , $userid] = $this->create_slot_option();
        $slots = slot_dto::build_picker_slots($optionid, $userid);
        $this->assertGreaterThanOrEqual(3, count($slots));
        $kept = $slots[0];
        $given = $slots[1];
        $taken = $slots[2];

        // Keep $kept, swap $given -> $taken.
        slot_move_store::create_pending(
            $optionid,
            0,
            $userid,
            [
                ['start' => (int)$kept['start'], 'end' => (int)$kept['end']],
                ['start' => (int)$taken['start'], 'end' => (int)$taken['end']],
            ],
            [
                ['start' => (int)$kept['start'], 'end' => (int)$kept['end']],
                ['start' => (int)$given['start'], 'end' => (int)$given['end']],
            ],
            5.0,
            time() + HOURSECS
        );

        $loaded = service_provider::load_cartitem('moveslot', $optionid, $userid);
        $this->assertArrayHasKey('cartitem', $loaded);
        $description = $loaded['cartitem']->as_array()['description'];

        $pretty = static fn(array $s): string => \mod_booking\option\dates_handler::prettify_optiondates_start_end(
            (int)$s['start'],
            (int)$s['end'],
            current_language()
        );

        $this->assertStringContainsString($pretty($given), $description, 'The given-up slot must be shown.');
        $this->assertStringContainsString($pretty($taken), $description, 'The taken slot must be shown.');
        $this->assertStringNotContainsString($pretty($kept), $description, 'The kept slot must NOT be shown.');
    }

    /**
     * Create a slotbooking option with capacity for moves.
     *
     * @return array{0:int,1:int,2:int} [optionid, bookingid, studentid]
     */
    private function create_slot_option(): array {
        $course = self::getDataGenerator()->create_course();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance(['course' => $course->id]);
        $student = self::getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $record = [
            'bookingid' => $booking->id,
            'text' => 'Move slot option ' . uniqid('', true),
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
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $bookingid = (int) $settings->bookingid;
        singleton_service::destroy_instance();

        return [(int) $option->id, $bookingid, (int) $student->id];
    }

    /**
     * Insert a booked slot answer for one slot.
     *
     * @param int $optionid
     * @param int $bookingid
     * @param int $userid
     * @param int $start
     * @param int $end
     * @return int booking answer id
     */
    private function create_booked_slot_answer(int $optionid, int $bookingid, int $userid, int $start, int $end): int {
        global $DB;

        $answer = (object) [
            'bookingid' => $bookingid,
            'optionid' => $optionid,
            'userid' => $userid,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
            'places' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
            'startdate' => $start,
            'enddate' => $end,
            'json' => '',
        ];
        slot_answer::set_slot_data($answer, ['slots' => [['start' => $start, 'end' => $end]], 'teachers' => []]);

        return (int) $DB->insert_record('booking_answers', $answer);
    }
}
