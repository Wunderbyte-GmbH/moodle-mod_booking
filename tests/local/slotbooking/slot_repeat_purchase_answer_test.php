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
 * Reproduces the reported bug: after buying one slot, buying a second, different slot made the
 * first one's data disappear - "only the last slot remains". Root cause was
 * booking_option::user_submit_response() resolving "does this user already have an answer for
 * this option" purely via the single-answer-per-user lookup (booking_answers::get_users()) and
 * only treating a second purchase as an INSERT (instead of an UPDATE of the existing answer) when
 * the generic multiplebookings setting was enabled - slot booking's own max_slots_per_user
 * capacity was never consulted, so buying an additional slot silently overwrote the first
 * answer's slot data (and demoted its status) instead of creating a second, independent answer.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\booking_option::user_submit_response
 */

namespace mod_booking;

use mod_booking\bo_availability\bo_info;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking\local\mobile\slotbookingstore;
use mod_booking\local\slotbooking\slot_answer;
use mod_booking\local\slotbooking\slot_availability;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * PHPUnit tests for buying a second, independent slot without losing the first one.
 *
 * @covers \mod_booking\booking_option
 */
final class slot_repeat_purchase_answer_test extends booking_advanced_testcase {
    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Direct/free booking flow: booking a second, different slot (both calls targeting BOOKED
     * directly, e.g. a free option with no shopping cart) must create a second, independent
     * answer - not silently no-op, and not overwrite the first answer.
     *
     * @return void
     */
    public function test_direct_booking_of_second_slot_creates_separate_answer(): void {
        [$optionid, $userid, $cmid] = $this->create_slot_option(['slot_max_slots_per_user' => 10]);

        $firststart = strtotime('2050-01-07 09:00:00 UTC');
        $secondstart = strtotime('2050-01-07 10:00:00 UTC');
        $firstend = $firststart + (60 * MINSECS);
        $secondend = $secondstart + (60 * MINSECS);

        $user = \core_user::get_user($userid);

        $this->select_slot($optionid, $userid, $firststart, $firstend);
        $option = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $this->assertTrue($option->user_submit_response($user, 0, 0, 0, MOD_BOOKING_VERIFIED));
        singleton_service::destroy_instance();

        $this->select_slot($optionid, $userid, $secondstart, $secondend);
        $option = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $this->assertTrue($option->user_submit_response($user, 0, 0, 0, MOD_BOOKING_VERIFIED));
        singleton_service::destroy_instance();

        global $DB;
        $answers = array_values($DB->get_records(
            'booking_answers',
            ['optionid' => $optionid, 'userid' => $userid],
            'id ASC'
        ));
        $this->assertCount(2, $answers, 'Buying a second slot must create a new answer, not update the first.');
        [$answer1, $answer2] = $answers;

        $this->assertSame((int) MOD_BOOKING_STATUSPARAM_BOOKED, (int) $answer1->waitinglist);
        $this->assertSame((int) MOD_BOOKING_STATUSPARAM_BOOKED, (int) $answer2->waitinglist);

        $slots1 = slot_answer::get_slot_data($answer1)['slots'];
        $slots2 = slot_answer::get_slot_data($answer2)['slots'];
        $this->assertSame([['start' => $firststart, 'end' => $firstend]], $slots1);
        $this->assertSame([['start' => $secondstart, 'end' => $secondend]], $slots2);
    }

    /**
     * Shopping-cart flow: adding a second slot to the cart (target status RESERVED) while the
     * first slot is already BOOKED must create a new, independent RESERVED answer - not update
     * the existing BOOKED answer in place, which would both overwrite its slot data and demote
     * it from BOOKED back to RESERVED. This is the exact shape of the reported bug.
     *
     * @return void
     */
    public function test_adding_second_slot_to_cart_does_not_overwrite_first_booked_answer(): void {
        [$optionid, $userid, $cmid] = $this->create_slot_option(['slot_max_slots_per_user' => 10]);

        $firststart = strtotime('2050-01-07 09:00:00 UTC');
        $secondstart = strtotime('2050-01-07 10:00:00 UTC');
        $firstend = $firststart + (60 * MINSECS);
        $secondend = $secondstart + (60 * MINSECS);

        $user = \core_user::get_user($userid);

        $this->select_slot($optionid, $userid, $firststart, $firstend);
        $option = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $this->assertTrue($option->user_submit_response($user, 0, 0, 0, MOD_BOOKING_VERIFIED));
        singleton_service::destroy_instance();

        $this->select_slot($optionid, $userid, $secondstart, $secondend);
        $option = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $this->assertTrue($option->user_submit_response(
            $user,
            0,
            0,
            MOD_BOOKING_BO_SUBMIT_STATUS_ADDED_TO_CART,
            MOD_BOOKING_VERIFIED
        ));
        singleton_service::destroy_instance();

        global $DB;
        $answers = array_values($DB->get_records(
            'booking_answers',
            ['optionid' => $optionid, 'userid' => $userid],
            'id ASC'
        ));
        $this->assertCount(
            2,
            $answers,
            'Adding a second slot to the cart must create a new answer, not update the first (booked) one.'
        );
        [$answer1, $answer2] = $answers;

        // The first, already-booked answer must be untouched: still BOOKED, still holding slot 1.
        $this->assertSame((int) MOD_BOOKING_STATUSPARAM_BOOKED, (int) $answer1->waitinglist);
        $slots1 = slot_answer::get_slot_data($answer1)['slots'];
        $this->assertSame([['start' => $firststart, 'end' => $firstend]], $slots1);

        // The second answer is the new cart reservation, holding slot 2.
        $this->assertSame((int) MOD_BOOKING_STATUSPARAM_RESERVED, (int) $answer2->waitinglist);
        $slots2 = slot_answer::get_slot_data($answer2)['slots'];
        $this->assertSame([['start' => $secondstart, 'end' => $secondend]], $slots2);
    }

    /**
     * WS/prepage flow (mod_booking_load_pre_booking_page): the browser's "Continue" ends on the
     * confirmation page, whose load commits the cached slot selection via bookit(). With one slot
     * already BOOKED, the booked-state gate in bo_info::load_pre_booking_page() must not swallow
     * the commit while the user still has remaining slot capacity (it did: the second purchase
     * silently no-oped while the confirmation page reported success) - and must stop booking
     * again once max_slots_per_user is exhausted.
     *
     * @covers \mod_booking\bo_availability\bo_info::load_pre_booking_page
     *
     * @return void
     */
    public function test_prepage_flow_books_second_slot_until_capacity_is_exhausted(): void {
        global $DB;

        [$optionid, $userid, $cmid] = $this->create_slot_option(['slot_max_slots_per_user' => 2]);

        $firststart = strtotime('2050-01-07 09:00:00 UTC');
        $secondstart = strtotime('2050-01-07 10:00:00 UTC');
        $thirdstart = strtotime('2050-01-07 11:00:00 UTC');

        // First purchase through the prepage flow.
        $this->select_slot($optionid, $userid, $firststart, $firststart + (60 * MINSECS));
        $this->load_confirmation_page($optionid, $userid);
        $this->assertCount(1, $DB->get_records('booking_answers', ['optionid' => $optionid, 'userid' => $userid]));

        // Second purchase: the alreadybooked top blocker must not swallow the commit while
        // capacity (max_slots_per_user = 2) remains.
        $this->select_slot($optionid, $userid, $secondstart, $secondstart + (60 * MINSECS));
        $this->load_confirmation_page($optionid, $userid);
        $answers = $DB->get_records('booking_answers', ['optionid' => $optionid, 'userid' => $userid], 'id ASC');
        $this->assertCount(2, $answers, 'The prepage flow must book the additional slot while capacity remains.');
        foreach ($answers as $answer) {
            $this->assertSame((int) MOD_BOOKING_STATUSPARAM_BOOKED, (int) $answer->waitinglist);
        }

        // The user's booked ranges aggregate across both answers (as shown in the options table).
        $ranges = slot_availability::get_booked_slot_ranges_for_user($optionid, $userid);
        $this->assertSame([
            ['start' => $firststart, 'end' => $firststart + (60 * MINSECS)],
            ['start' => $secondstart, 'end' => $secondstart + (60 * MINSECS)],
        ], $ranges);

        // Third attempt: capacity exhausted - the booked-state gate must swallow the commit again.
        $this->select_slot($optionid, $userid, $thirdstart, $thirdstart + (60 * MINSECS));
        $this->load_confirmation_page($optionid, $userid);
        $this->assertCount(
            2,
            $DB->get_records('booking_answers', ['optionid' => $optionid, 'userid' => $userid]),
            'Once max_slots_per_user is exhausted, loading the confirmation page must not book again.'
        );
    }

    /**
     * Run bo_info::load_pre_booking_page() for the confirmation page - the call the browser's
     * "Continue" ends on (mod_booking_load_pre_booking_page WS), where the actual booking happens.
     *
     * @param int $optionid booking option id
     * @param int $userid user id
     * @return void
     */
    private function load_confirmation_page(int $optionid, int $userid): void {
        $results = bo_info::get_condition_results($optionid, $userid);
        usort($results, fn ($a, $b) => $a['id'] < $b['id'] ? 1 : -1);
        $conditions = bo_info::return_sorted_conditions($results);

        $confirmationpage = null;
        foreach ($conditions as $index => $condition) {
            if ((int) $condition['id'] === MOD_BOOKING_BO_COND_CONFIRMATION) {
                $confirmationpage = $index;
            }
        }
        $this->assertNotNull($confirmationpage, 'No confirmation prepage found for the slot option.');

        bo_info::load_pre_booking_page($optionid, $confirmationpage, $userid);
        singleton_service::destroy_instance();
    }

    /**
     * Persist a slot selection into the cache that add_json_to_booking_answer() reads from.
     *
     * @param int $optionid booking option id
     * @param int $userid user id
     * @param int $start slot start timestamp
     * @param int $end slot end timestamp
     * @return void
     */
    private function select_slot(int $optionid, int $userid, int $start, int $end): void {
        $store = new slotbookingstore($userid, $optionid);
        $store->set_slotbooking_data((object)[
            'slot_selection' => $start . ':' . $end,
            'slot_teacher_selection' => json_encode([]),
        ]);
    }

    /**
     * Create a simple fixed slotbooking option and a student.
     *
     * @param array $overrides option-form field overrides
     * @return array{0:int,1:int,2:int} [optionid, userid, cmid]
     */
    private function create_slot_option(array $overrides = []): array {
        $course = self::getDataGenerator()->create_course();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance(['course' => $course->id]);
        $student = self::getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $record = array_merge([
            'bookingid' => $booking->id,
            'text' => 'Repeat purchase test option ' . uniqid('', true),
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
        ], $overrides);
        for ($day = 1; $day <= 7; $day++) {
            $record['slot_day_' . $day] = 1;
        }

        $option = $plugingenerator->create_option((object) $record);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $cmid = (int) $settings->cmid;
        singleton_service::destroy_instance();

        return [(int) $option->id, (int) $student->id, $cmid];
    }
}
