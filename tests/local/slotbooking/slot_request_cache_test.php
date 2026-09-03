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
use mod_booking\local\slotbooking\slot_availability;
use mod_booking\local\mobile\slotbookingstore;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Regression guard for the reported bug where a slot option's booked-count/occupancy stayed
 * stale for the rest of the SAME request after a booking, whenever an availability check had
 * already read (and cached) the booked ranges before the write happened - e.g. the calendar
 * rendering that runs just before a "Book now" click. slot_availability keeps its own per-request
 * static cache of booked ranges (see its $bookedslotrangecache docblock) that is not invalidated
 * by singleton_service::destroy_booking_answers() alone; booking_option::refresh_answers_for_option()
 * (the central "answers changed" hook called by every book/cancel/waitlist-promote code path) must
 * also clear it.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\booking_option::refresh_answers_for_option
 */
final class slot_request_cache_test extends booking_advanced_testcase {
    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Reading slot occupancy BEFORE a booking (as the calendar view does), then booking, then
     * reading again WITHOUT any destroy_instance()/request boundary in between (as a single
     * "book and return the updated row" request would) must reflect the new booking immediately -
     * not the pre-booking snapshot.
     *
     * @return void
     */
    public function test_open_slot_count_reflects_booking_within_the_same_request(): void {
        $course = self::getDataGenerator()->create_course();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance(['course' => $course->id]);
        $student = self::getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $record = [
            'bookingid' => $booking->id,
            'text' => 'Request cache test ' . uniqid('', true),
            'course' => $course->id,
            'optiontype' => MOD_BOOKING_OPTIONTYPE_SLOTBOOKING,
            'maxanswers' => 20,
            'slot_enabled' => 1,
            'slot_type' => 'rolling',
            'slot_duration_minutes' => 40,
            'slot_interval_minutes' => 20,
            'slot_custom_max_duration' => 60 * MINSECS,
            'slot_custom_min_duration' => 30 * MINSECS,
            'slot_custom_max_days' => DAYSECS,
            'slot_custom_start_interval_minutes' => 30,
            'slot_opening_time' => '09:00',
            'slot_closing_time' => '11:00',
            'slot_valid_from' => strtotime('2050-01-07 00:00:00 UTC'),
            'slot_valid_until' => strtotime('2050-01-10 23:59:59 UTC'),
            'slot_max_participants_per_slot' => 5,
            'slot_max_slots_per_user' => 2,
            'slot_booking_view_mode' => 'calendar',
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
        $optionid = (int)$option->id;
        $userid = (int)$student->id;

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        // Step 1: read occupancy BEFORE booking - this is what populates
        // slot_availability's per-request static cache (mimics the calendar rendering that runs
        // when the user clicks "Book now", before anything is actually booked).
        $before = slot_availability::get_slots_with_status($optionid, $userid);
        $openbefore = $this->count_open($before);
        $openslot = null;
        foreach ($before as $slot) {
            if (($slot['status'] ?? '') === 'open') {
                $openslot = $slot;
                break;
            }
        }
        $this->assertNotNull($openslot);

        // Step 2: book that slot through the real commit path - deliberately WITHOUT calling
        // singleton_service::destroy_instance() afterwards, to reproduce a single request that
        // first checks availability (populating the cache) and then commits the booking.
        $bookingoption = singleton_service::get_instance_of_booking_option($settings->cmid, $optionid);
        (new slotbookingstore($userid, $optionid))->set_slotbooking_data((object)[
            'slot_selection' => $openslot['start'] . ':' . $openslot['end'],
            'slot_teacher_selection' => '{}',
        ]);
        $user = singleton_service::get_instance_of_user($userid);
        $result = $bookingoption->user_submit_response($user, 0, 0, 0, MOD_BOOKING_VERIFIED);
        $this->assertNotFalse($result);

        // Step 3: read occupancy again, in the SAME request - must already reflect the booking,
        // i.e. match a guaranteed-fresh read (one that explicitly clears the request cache
        // first) exactly. Comparing against that ground truth - rather than hardcoding an
        // assumed drop - is what actually catches the bug: rolling slots can overlap, so one
        // booking can remove more than one candidate from the open list, which a hardcoded
        // "$openbefore - 1" would get wrong even on correct code.
        $openafter = $this->count_open(slot_availability::get_slots_with_status($optionid, $userid));

        slot_availability::clear_request_cache($optionid);
        $openaftercleared = $this->count_open(slot_availability::get_slots_with_status($optionid, $userid));

        $this->assertLessThan($openbefore, $openaftercleared, 'Sanity check: the booking must actually reduce open capacity.');
        $this->assertSame(
            $openaftercleared,
            $openafter,
            'Open slot count must already reflect the booking within the same request, without' .
                ' needing an explicit clear_request_cache() call.'
        );
    }

    /**
     * Count slots whose status is 'open' or 'warning' (still bookable), matching
     * bookingoptions_wbtable::col_bookings()'s 'availableforuser' display mode.
     *
     * @param array $slots
     * @return int
     */
    private function count_open(array $slots): int {
        $open = 0;
        foreach ($slots as $slot) {
            if (in_array((string)($slot['status'] ?? ''), ['open', 'warning'], true)) {
                $open++;
            }
        }
        return $open;
    }
}
