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
 * Tests for two regressions reported after a booking was placed via the slot picker.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use mod_booking\bo_availability\conditions\slotbooking;
use mod_booking\local\mobile\slotbookingstore;
use mod_booking\local\slotbooking\slot_answer;
use mod_booking\local\slotbooking\slot_availability;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests for two regressions reported after a booking was placed via the slot picker:
 * (1) two overlapping slots selected in the same submission were both accepted, and
 * (2) re-validating the user's own already-booked/held slot(s) reported them as
 * "no longer available" because the user's own answer was not excluded from the
 * conflict check.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\slotbooking\slot_availability::ranges_overlap_internally
 * @covers     \mod_booking\local\slotbooking\slot_availability::get_active_answer_ids_for_user
 * @covers     \mod_booking\bo_availability\conditions\slotbooking::add_json_to_booking_answer
 */
final class slot_overlap_and_ownanswer_test extends booking_advanced_testcase {
    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Two ranges that overlap in time must be detected regardless of order.
     *
     * @return void
     */
    public function test_ranges_overlap_internally_detects_overlap(): void {
        $ranges = [[1000, 1030], [1015, 1045]];
        $this->assertTrue(slot_availability::ranges_overlap_internally($ranges));

        $reversed = [[1015, 1045], [1000, 1030]];
        $this->assertTrue(slot_availability::ranges_overlap_internally($reversed));
    }

    /**
     * Back-to-back ranges (one ends exactly when the other starts) and non-adjacent ranges
     * are not overlaps.
     *
     * @return void
     */
    public function test_ranges_overlap_internally_allows_back_to_back_and_disjoint(): void {
        $backtoback = [[1000, 1030], [1030, 1060]];
        $this->assertFalse(slot_availability::ranges_overlap_internally($backtoback));

        $disjoint = [[1000, 1030], [2000, 2030], [3000, 3030]];
        $this->assertFalse(slot_availability::ranges_overlap_internally($disjoint));

        $this->assertFalse(slot_availability::ranges_overlap_internally([]));
        $this->assertFalse(slot_availability::ranges_overlap_internally([[1000, 1030]]));
    }

    /**
     * A three-way selection where only the last pair overlaps must still be caught.
     *
     * @return void
     */
    public function test_ranges_overlap_internally_catches_non_adjacent_pair(): void {
        $ranges = [[1000, 1030], [2000, 2030], [2015, 2045]];
        $this->assertTrue(slot_availability::ranges_overlap_internally($ranges));
    }

    /**
     * get_active_answer_ids_for_user() returns the id of the user's own active (booked or
     * reserved/cart-held) answer for the option.
     *
     * @return void
     */
    public function test_get_active_answer_ids_for_user_returns_own_answer(): void {
        [$optionid, $userid] = $this->create_slot_option_and_user();

        $start = strtotime('2050-01-07 09:00:00 UTC');
        $baid = $this->create_slot_answer($optionid, $userid, $start, MOD_BOOKING_STATUSPARAM_RESERVED);
        singleton_service::destroy_instance();

        $this->assertSame([$baid], slot_availability::get_active_answer_ids_for_user($optionid, $userid));
    }

    /**
     * A user can hold more than one active answer for the same option at once ("book again" /
     * multiplebookings, e.g. several separately purchased "phases") - all of them must be
     * returned, not just the first.
     *
     * @return void
     */
    public function test_get_active_answer_ids_for_user_returns_all_own_answers(): void {
        [$optionid, $userid] = $this->create_slot_option_and_user(['slot_max_slots_per_user' => 5]);

        $firstday = strtotime('2050-01-07 09:00:00 UTC');
        $secondday = strtotime('2050-01-08 09:00:00 UTC');
        $baid1 = $this->create_slot_answer($optionid, $userid, $firstday, MOD_BOOKING_STATUSPARAM_BOOKED);
        $baid2 = $this->create_slot_answer($optionid, $userid, $secondday, MOD_BOOKING_STATUSPARAM_RESERVED);
        singleton_service::destroy_instance();

        $answerids = slot_availability::get_active_answer_ids_for_user($optionid, $userid);
        sort($answerids);
        $expected = [$baid1, $baid2];
        sort($expected);
        $this->assertSame($expected, $answerids);
    }

    /**
     * With no active answer for the user, an empty array is returned - and another user's
     * answer for the same option must not be picked up by mistake.
     *
     * @return void
     */
    public function test_get_active_answer_ids_for_user_returns_empty_when_none(): void {
        [$optionid, $userid] = $this->create_slot_option_and_user();
        $otheruser = self::getDataGenerator()->create_user();

        $this->assertSame([], slot_availability::get_active_answer_ids_for_user($optionid, $userid));

        $start = strtotime('2050-01-07 09:00:00 UTC');
        $this->create_slot_answer($optionid, $otheruser->id, $start, MOD_BOOKING_STATUSPARAM_BOOKED);
        singleton_service::destroy_instance();

        $this->assertSame([], slot_availability::get_active_answer_ids_for_user($optionid, $userid));
    }

    /**
     * hard_block() re-validates the cached slot_selection on every render of the prepage - once a
     * selection was already persisted as the user's own answer(s), re-validating it must not
     * block the flow. Covers the same class of bug as the form validation tests, but for the
     * actual booking gate (which controls whether "Continue" proceeds), and with slots split
     * across two separate own answers ("book again" / multiplebookings "phases").
     *
     * @return void
     */
    public function test_hard_block_does_not_reblock_selection_across_own_answers(): void {
        [$optionid, $userid] = $this->create_slot_option_and_user(['slot_max_slots_per_user' => 10]);

        $firststart = strtotime('2050-01-07 09:00:00 UTC');
        $secondstart = strtotime('2050-01-07 10:00:00 UTC');
        $firstend = $firststart + (30 * MINSECS);
        $secondend = $secondstart + (30 * MINSECS);

        $this->create_slot_answer($optionid, $userid, $firststart, MOD_BOOKING_STATUSPARAM_BOOKED);
        $this->create_slot_answer($optionid, $userid, $secondstart, MOD_BOOKING_STATUSPARAM_RESERVED);
        singleton_service::destroy_instance();

        $store = new slotbookingstore($userid, $optionid);
        $store->set_slotbooking_data((object)[
            'slot_selection' => $firststart . ':' . $firstend . ',' . $secondstart . ':' . $secondend,
            'slot_teacher_selection' => json_encode([]),
        ]);

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $condition = new slotbooking();

        $this->assertFalse($condition->hard_block($settings, $userid));
    }

    /**
     * Buying a genuinely new, additional slot: the user already has one paid/booked answer, and
     * the cache holds ONLY the freshly selected second slot (not the first, already-committed
     * one) - this is the real shape of "buy an additional slot" once the shopping cart add-to-cart
     * pre-check re-runs hard_block(), as opposed to re-validating a selection that still spans the
     * old slot too.
     *
     * @return void
     */
    public function test_hard_block_allows_buying_additional_new_slot(): void {
        [$optionid, $userid] = $this->create_slot_option_and_user(['slot_max_slots_per_user' => 10]);

        $firststart = strtotime('2050-01-07 09:00:00 UTC');
        $secondstart = strtotime('2050-01-07 10:00:00 UTC');
        $secondend = $secondstart + (30 * MINSECS);

        $this->create_slot_answer($optionid, $userid, $firststart, MOD_BOOKING_STATUSPARAM_BOOKED);
        singleton_service::destroy_instance();

        $store = new slotbookingstore($userid, $optionid);
        $store->set_slotbooking_data((object)[
            'slot_selection' => $secondstart . ':' . $secondend,
            'slot_teacher_selection' => json_encode([]),
        ]);

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $condition = new slotbooking();

        $this->assertFalse($condition->hard_block($settings, $userid));
    }

    /**
     * The direct-persistence path (used outside the dynamic form, e.g. by a webservice) must
     * reject an overlapping multi-slot selection too, as a defense-in-depth safety net.
     *
     * @return void
     */
    public function test_add_json_to_booking_answer_rejects_overlapping_selection(): void {
        [$optionid, $userid] = $this->create_slot_option_and_user(['slot_max_slots_per_user' => 5]);

        $slota = strtotime('2050-01-07 09:00:00 UTC');
        $slotb = $slota + (15 * MINSECS);
        $selection = $slota . ':' . ($slota + (30 * MINSECS)) . ',' . $slotb . ':' . ($slotb + (30 * MINSECS));

        $store = new slotbookingstore($userid, $optionid);
        $store->set_slotbooking_data((object)[
            'slot_selection' => $selection,
            'slot_teacher_selection' => json_encode([]),
        ]);

        $newanswer = (object) [
            'optionid' => $optionid,
            'userid' => $userid,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_RESERVED,
        ];

        $expectedmessage = get_string('slot_error_selection_overlap', 'mod_booking');
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expectedmessage, '/') . '/');
        slotbooking::add_json_to_booking_answer($newanswer, $userid);
    }

    /**
     * Create a simple fixed slotbooking option and a student.
     *
     * @param array $overrides option-form field overrides
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
            'text' => 'Overlap test option ' . uniqid('', true),
            'course' => $course->id,
            'optiontype' => MOD_BOOKING_OPTIONTYPE_SLOTBOOKING,
            'maxanswers' => 20,
            'slot_enabled' => 1,
            'slot_type' => 'fixed',
            'slot_duration_minutes' => 30,
            'slot_interval_minutes' => 30,
            'slot_custom_max_duration' => 60 * MINSECS,
            'slot_custom_min_duration' => 30 * MINSECS,
            'slot_custom_max_days' => DAYSECS,
            'slot_custom_start_interval_minutes' => 15,
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
        ], $overrides);
        for ($day = 1; $day <= 7; $day++) {
            $record['slot_day_' . $day] = 1;
        }

        $option = $plugingenerator->create_option((object) $record);
        singleton_service::destroy_instance();

        return [(int) $option->id, (int) $student->id];
    }

    /**
     * Insert a booking answer directly (bypassing the full checkout flow), matching the pattern
     * used by the other slotbooking tests (e.g. slot_move_service_provider_test), and purge the
     * option's cached answers so the direct insert is visible to the next read.
     *
     * @param int $optionid booking option id
     * @param int $userid user id
     * @param int $start slot start timestamp
     * @param int $waitinglist booking status (MOD_BOOKING_STATUSPARAM_*)
     * @return int booking answer id
     */
    private function create_slot_answer(int $optionid, int $userid, int $start, int $waitinglist): int {
        global $DB;

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $end = $start + (30 * MINSECS);

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
