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
use mod_booking\local\slotbooking\slot_update_service;
use mod_booking\tests\booking_advanced_testcase;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * The cancellation policy applies to the "Move/Cancel your slot(s)" tab too.
 *
 * The per-slot cancel buttons are only shown when the cancellation policy allows giving slots up
 * (slot_mover::per_slot_release_available). The move tab used to offer a second, ungated route to
 * the same thing: deselecting slots there went through slot_update_service, which only checked the
 * rebooking opt-in. Moving stays unaffected - giving up a slot and picking another one in return
 * cancels nothing on balance.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\slotbooking\slot_update_service::plan
 * @covers     \mod_booking\local\slotbooking\slot_update_service::apply
 */
final class slot_cancel_policy_gate_test extends booking_advanced_testcase {
    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * With cancelling allowed, shrinking the selection is planned without complaint.
     *
     * @return void
     */
    public function test_giving_up_a_slot_is_allowed_when_the_option_allows_cancelling(): void {
        [$optionid, $userid, $baid, $keys] = $this->create_booked_option(true);

        $plan = slot_update_service::plan($optionid, $baid, $userid, [$keys[0]]);

        $this->assertNotContains(
            'slot_release_policy_blocked',
            $plan['errors'],
            'Cancelling is allowed here, so dropping one of two slots must plan cleanly.'
        );
    }

    /**
     * With cancelling switched off, shrinking the selection is refused - before any confirmation.
     *
     * @return void
     */
    public function test_giving_up_a_slot_is_refused_when_cancelling_is_switched_off(): void {
        [$optionid, $userid, $baid, $keys] = $this->create_booked_option(false);

        $plan = slot_update_service::plan($optionid, $baid, $userid, [$keys[0]]);

        $this->assertContains(
            'slot_release_policy_blocked',
            $plan['errors'],
            'Dropping a slot is a cancellation and must be refused when cancelling is switched off.'
        );
    }

    /**
     * Cancelling the whole booking is refused by apply() as well, not just by the plan.
     *
     * plan() drives the confirmation summary; apply() is what actually commits. A paid booking
     * takes a branch in apply_reduction() that hands the cancellation straight to the payment
     * component and never reaches release_self(), so the gate has to sit in apply_reduction()
     * itself - the shopping cart knows nothing about mod_booking's own cancellation settings.
     *
     * @return void
     */
    public function test_apply_refuses_the_full_cancellation_when_cancelling_is_switched_off(): void {
        [$optionid, $userid, $baid] = $this->create_booked_option(false);

        $this->expectException(moodle_exception::class);
        slot_update_service::apply($optionid, $baid, $userid, []);
    }

    /**
     * A purchased booking cannot be cancelled past the option's own settings either.
     *
     * This is the case the gate in apply_reduction() exists for. An answer that knows which
     * purchase paid for it takes a branch that hands the cancellation to the payment component and
     * returns before release_self() - whose own policy check therefore never runs. The shopping
     * cart applies its own rules but knows nothing about "Allow users to cancel", so without the
     * gate a paid booking was cancellable while a free one was not.
     *
     * @return void
     */
    public function test_a_purchased_booking_cannot_be_cancelled_past_the_option_settings(): void {
        global $DB;

        if (!class_exists('local_shopping_cart\\shopping_cart')) {
            $this->markTestSkipped('local_shopping_cart is not installed.');
        }

        [$optionid, $userid, $baid] = $this->create_booked_option(false);

        // Link the answer to a purchase, which is what sends apply_reduction() to the cart.
        $DB->set_field('booking_answers', 'purchaseidentifier', 1234567, ['id' => $baid]);
        booking_option::purge_cache_for_answers($optionid);
        singleton_service::destroy_instance();

        try {
            slot_update_service::apply($optionid, $baid, $userid, []);
            $this->fail('Cancelling the whole booking had to be refused.');
        } catch (moodle_exception $e) {
            // Refused by the cancellation policy, NOT merely by the cart failing to find the
            // purchase - that distinction is the whole point of the gate.
            $this->assertSame('slot_release_policy_blocked', $e->errorcode);
        }
    }

    /**
     * A pure move is not a cancellation and stays possible with cancelling switched off.
     *
     * @return void
     */
    public function test_moving_a_slot_stays_possible_when_cancelling_is_switched_off(): void {
        [$optionid, $userid, $baid, $keys, $freekey] = $this->create_booked_option(false);

        // Same number of slots as before: one given up, one picked in return.
        $plan = slot_update_service::plan($optionid, $baid, $userid, [$keys[0], $freekey]);

        $this->assertNotContains(
            'slot_release_policy_blocked',
            $plan['errors'],
            'Swapping one slot for another gives up nothing on balance and must stay allowed.'
        );
    }

    /**
     * An option with two booked slots for one user, plus a free slot to move to.
     *
     * @param bool $cancelallowed whether the booking instance allows users to cancel
     * @return array{0:int, 1:int, 2:int, 3:array<int, string>, 4:string} optionid, userid, baid,
     *  booked slot keys, key of a still free slot
     */
    private function create_booked_option(bool $cancelallowed): array {
        global $DB;

        $course = self::getDataGenerator()->create_course();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance([
            'course' => $course->id,
            'cancancelbook' => $cancelallowed ? 1 : 0,
        ]);

        $student = self::getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $record = [
            'bookingid' => $booking->id,
            'text' => 'Cancel policy option ' . uniqid('', true),
            'course' => $course->id,
            'optiontype' => MOD_BOOKING_OPTIONTYPE_SLOTBOOKING,
            'maxanswers' => 20,
            'slot_enabled' => 1,
            'slot_type' => 'fixed',
            'slot_duration_minutes' => 30,
            'slot_interval_minutes' => 30,
            'slot_opening_time' => '10:00',
            'slot_closing_time' => '12:00',
            'slot_valid_from' => strtotime('2050-01-07 00:00:00 UTC'),
            'slot_valid_until' => strtotime('2050-01-10 23:59:59 UTC'),
            'slot_max_participants_per_slot' => 1,
            'slot_max_slots_per_user' => 2,
            'slot_booking_view_mode' => 'list',
            'slot_allow_self_rebooking' => 1,
        ];
        for ($day = 1; $day <= 7; $day++) {
            $record['slot_day_' . $day] = 1;
        }

        $option = $plugingenerator->create_option((object)$record);
        $optionid = (int)$option->id;

        // The instance setting is what self_release_policy_blocked() reads, and create_instance
        // does not always carry it through - set it explicitly so the two variants really differ.
        $DB->set_field('booking', 'cancancelbook', $cancelallowed ? 1 : 0, ['id' => $booking->id]);
        singleton_service::destroy_instance();

        $slots = slot_dto::build_picker_slots($optionid, (int)$student->id);
        $this->assertGreaterThanOrEqual(3, count($slots), 'The fixture needs at least three slots.');

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $answer = (object)[
            'bookingid' => (int)$settings->bookingid,
            'userid' => (int)$student->id,
            'optionid' => $optionid,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
            'completed' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
            'timebooked' => time(),
            'startdate' => (int)$slots[0]['start'],
            'enddate' => (int)$slots[1]['end'],
            'json' => '{}',
        ];
        $answer->id = $DB->insert_record('booking_answers', $answer);
        slot_answer::set_slot_data($answer, [
            'slots' => [
                ['start' => (int)$slots[0]['start'], 'end' => (int)$slots[0]['end']],
                ['start' => (int)$slots[1]['start'], 'end' => (int)$slots[1]['end']],
            ],
            'num_slots' => 2,
        ]);
        $DB->update_record('booking_answers', $answer);
        booking_option::purge_cache_for_answers($optionid);
        singleton_service::destroy_instance();

        return [
            $optionid,
            (int)$student->id,
            (int)$answer->id,
            [(string)$slots[0]['key'], (string)$slots[1]['key']],
            (string)$slots[2]['key'],
        ];
    }
}
