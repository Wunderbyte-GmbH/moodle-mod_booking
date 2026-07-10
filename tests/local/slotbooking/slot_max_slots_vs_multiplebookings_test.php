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
 * Isolation tests for slot booking's own "Max slots per user" capacity setting versus the
 * generic instance-level "Allow to book again" (multiplebookings) setting. The two are
 * independent mechanisms:
 * - "Max slots per user" (slot_max_slots_per_user) caps how many separate slots a user may hold
 *   for this option at once (additive purchases, e.g. buying several slots over time).
 * - "Allow to book again" (multiplebookings) is a time-based re-booking gate: once due, it lets
 *   a user book again after their previous booking ended/a wait time passed, demoting the old
 *   answer to "previously booked".
 * Neither should block or interfere with the other.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\booking_option::user_submit_response
 * @covers     \mod_booking\bo_availability\conditions\alreadybooked
 * @covers     \mod_booking\local\slotbooking\slot_availability::has_remaining_slot_capacity
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use mod_booking\bo_availability\conditions\alreadybooked;
use mod_booking\local\mobile\slotbookingstore;
use mod_booking\local\slotbooking\slot_answer;
use mod_booking\option\fields\multiplebookings;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once($CFG->dirroot . '/mod/booking/classes/bo_availability/bo_info.php');

/**
 * PHPUnit tests proving slot_max_slots_per_user and multiplebookings do not interfere.
 */
final class slot_max_slots_vs_multiplebookings_test extends booking_advanced_testcase {
    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Buying an additional slot must work purely from remaining slot capacity, with
     * "Allow to book again" left at its default (disabled) - capacity alone is sufficient, no
     * multiplebookings configuration is required for the slot feature's own multi-purchase use
     * case.
     *
     * @return void
     */
    public function test_slot_capacity_alone_allows_second_slot_with_multiplebookings_disabled(): void {
        [$optionid, $userid] = $this->create_slot_option_and_user([
            'slot_max_slots_per_user' => 3,
            'multiplebookings' => multiplebookings::MODE_DISABLED,
        ]);

        $firststart = strtotime('2050-01-07 09:00:00 UTC');
        $secondstart = strtotime('2050-01-07 10:00:00 UTC');

        $this->select_and_book_slot($optionid, $userid, $firststart);
        $this->select_and_book_slot($optionid, $userid, $secondstart);

        global $DB;
        $answers = $DB->get_records('booking_answers', ['optionid' => $optionid, 'userid' => $userid]);
        $this->assertCount(2, $answers);
        foreach ($answers as $answer) {
            $this->assertSame((int) MOD_BOOKING_STATUSPARAM_BOOKED, (int) $answer->waitinglist);
        }
    }

    /**
     * Buying an additional slot must succeed purely from remaining slot capacity even while
     * "Allow to book again" is enabled but its own time-based gate is NOT yet due - the un-due
     * multiplebookings gate must not block a genuinely additional slot purchase, since the two
     * settings are independent axes (capacity vs. time-gated re-booking).
     *
     * @return void
     */
    public function test_slot_capacity_allows_second_slot_even_when_multiplebookings_gate_not_due(): void {
        [$optionid, $userid] = $this->create_slot_option_and_user([
            'slot_max_slots_per_user' => 3,
            'multiplebookings' => multiplebookings::MODE_AFTER_DURATION,
            'allowtobookagainafter' => DAYSECS, // Due only 24h after the first booking.
        ]);

        $firststart = strtotime('2050-01-07 09:00:00 UTC');
        $secondstart = strtotime('2050-01-07 10:00:00 UTC');

        $this->select_and_book_slot($optionid, $userid, $firststart);

        // The multiplebookings gate is nowhere near due (booked seconds ago, needs 24h) - confirm
        // that a plain re-book attempt (same style as multiplebookings' own flow) is genuinely
        // blocked by it, so this test is meaningful.
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $this->assertFalse((new alreadybooked())->is_available($settings, $userid));

        // Buying a second, DIFFERENT slot must still succeed via capacity, unblocked by the gate.
        $this->select_and_book_slot($optionid, $userid, $secondstart);

        global $DB;
        $answers = $DB->get_records('booking_answers', ['optionid' => $optionid, 'userid' => $userid]);
        $this->assertCount(
            2,
            $answers,
            'Buying an additional slot must not be blocked by an un-due multiplebookings gate.'
        );
        foreach ($answers as $answer) {
            $this->assertSame((int) MOD_BOOKING_STATUSPARAM_BOOKED, (int) $answer->waitinglist);
        }
    }

    /**
     * Once slot capacity is exhausted, alreadybooked must still block - even with
     * "Allow to book again" enabled, an UN-DUE gate must not be bypassed just because the
     * capacity-based step-back exists elsewhere; the two checks are independent, not an OR that
     * always favours availability.
     *
     * @return void
     */
    public function test_still_blocked_when_capacity_exhausted_and_multiplebookings_not_due(): void {
        [$optionid, $userid] = $this->create_slot_option_and_user([
            'slot_max_slots_per_user' => 1,
            'multiplebookings' => multiplebookings::MODE_AFTER_DURATION,
            'allowtobookagainafter' => DAYSECS,
        ]);

        $firststart = strtotime('2050-01-07 09:00:00 UTC');
        $this->select_and_book_slot($optionid, $userid, $firststart);

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $condition = new alreadybooked();

        $this->assertFalse($condition->is_available($settings, $userid));
        $this->assertTrue($condition->hard_block($settings, $userid));
    }

    /**
     * Select a slot (via the slotbookingstore cache add_json_to_booking_answer() reads from) and
     * book it directly to BOOKED status.
     *
     * @param int $optionid booking option id
     * @param int $userid user id
     * @param int $start slot start timestamp
     * @return void
     */
    private function select_and_book_slot(int $optionid, int $userid, int $start): void {
        $end = $start + (60 * MINSECS);
        $store = new slotbookingstore($userid, $optionid);
        $store->set_slotbooking_data((object)[
            'slot_selection' => $start . ':' . $end,
            'slot_teacher_selection' => json_encode([]),
        ]);

        $user = \core_user::get_user($userid);
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $optionid);
        $this->assertTrue($option->user_submit_response($user, 0, 0, 0, MOD_BOOKING_VERIFIED));
        singleton_service::destroy_instance();
    }

    /**
     * Create a simple fixed slotbooking option and a student.
     *
     * @param array $overrides option-form field overrides (both slot_* and instance-level keys,
     *                         e.g. multiplebookings/allowtobookagainafter)
     * @return array{0:int,1:int} [optionid, userid]
     */
    private function create_slot_option_and_user(array $overrides = []): array {
        $course = self::getDataGenerator()->create_course();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance(['course' => $course->id]);
        $student = self::getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $record = array_merge([
            'bookingid' => $booking->id,
            'text' => 'Capacity vs multiplebookings test option ' . uniqid('', true),
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
            'slot_opening_time' => '08:00',
            'slot_closing_time' => '18:00',
            'slot_valid_from' => strtotime('2050-01-07 00:00:00 UTC'),
            'slot_valid_until' => strtotime('2050-01-10 23:59:59 UTC'),
            'slot_max_participants_per_slot' => 5,
            'slot_max_slots_per_user' => 1,
            'slot_booking_view_mode' => 'list',
            'slot_add_examiners' => 0,
            'slot_teachers_required' => 0,
            'slot_allow_self_rebooking' => 0,
            'slot_change_deadline_minutes' => '',
            'multiplebookings' => multiplebookings::MODE_DISABLED,
            'allowtobookagainafter' => 0,
        ], $overrides);
        for ($day = 1; $day <= 7; $day++) {
            $record['slot_day_' . $day] = 1;
        }

        $option = $plugingenerator->create_option((object) $record);
        singleton_service::destroy_instance();

        return [(int) $option->id, (int) $student->id];
    }
}
