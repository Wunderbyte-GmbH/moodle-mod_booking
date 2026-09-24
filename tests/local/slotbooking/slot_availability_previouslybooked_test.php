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
use mod_booking\local\slotbooking\slot_availability;
use mod_booking\local\slotbooking\slot_dto;
use mod_booking\tests\booking_advanced_testcase;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * A previously booked answer keeps its slot occupied.
 *
 * "Book again" demotes a user's earlier answer to previously booked instead of deleting it. The
 * slots of that answer stay booked: only the deleted states free a seat, so another user must
 * still see the slot as full.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\slotbooking\slot_availability::count_bookings
 * @covers     \mod_booking\local\slotbooking\slot_availability::is_slot_available
 */
final class slot_availability_previouslybooked_test extends booking_advanced_testcase {
    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Cleanup.
     */
    public function tearDown(): void {
        parent::tearDown();
        slot_availability::reset_caches();
    }

    /**
     * A single-seat slot stays full for another user while the holder's answer is previously
     * booked, and only frees up once the answer is deleted.
     *
     * @return void
     */
    public function test_previously_booked_answer_still_blocks_the_slot(): void {
        global $DB;

        [$optionid, $holderid, $otherid] = $this->create_single_seat_slot_option();

        $slots = slot_dto::build_picker_slots($optionid, $otherid);
        $this->assertNotEmpty($slots, 'The option must offer at least one slot.');
        $start = (int)$slots[0]['start'];
        $end = (int)$slots[0]['end'];

        // Nobody booked yet: the seat is free.
        $this->assertSame(0, slot_availability::count_bookings($optionid, $start, $end));
        $this->assertTrue(
            slot_availability::is_slot_available($optionid, $start, $end, $otherid),
            'The slot must be free before anyone books it.'
        );

        // The holder books the only seat.
        $baid = $this->create_slot_answer($optionid, $holderid, $start, $end, MOD_BOOKING_STATUSPARAM_BOOKED);
        $this->assertSame(1, slot_availability::count_bookings($optionid, $start, $end));
        $this->assertFalse(
            slot_availability::is_slot_available($optionid, $start, $end, $otherid),
            'A booked answer must occupy the single seat.'
        );

        // Book again demotes the answer to previously booked - the seat stays taken.
        $this->set_answer_status($optionid, $baid, MOD_BOOKING_STATUSPARAM_PREVIOUSLYBOOKED);
        $this->assertSame(
            MOD_BOOKING_STATUSPARAM_PREVIOUSLYBOOKED,
            (int)$DB->get_field('booking_answers', 'waitinglist', ['id' => $baid])
        );
        $this->assertSame(
            1,
            slot_availability::count_bookings($optionid, $start, $end),
            'A previously booked answer must still count towards the slot capacity.'
        );
        $this->assertFalse(
            slot_availability::is_slot_available($optionid, $start, $end, $otherid),
            'A previously booked answer must keep the slot blocked for other users.'
        );

        // Only a deleted answer frees the seat again.
        $this->set_answer_status($optionid, $baid, MOD_BOOKING_STATUSPARAM_DELETED);
        $this->assertSame(0, slot_availability::count_bookings($optionid, $start, $end));
        $this->assertTrue(
            slot_availability::is_slot_available($optionid, $start, $end, $otherid),
            'A deleted answer must free the slot.'
        );
    }

    /**
     * Change the status of an answer and drop every cache that could still serve the old row.
     *
     * @param int $optionid booking option id
     * @param int $baid booking answer id
     * @param int $status new MOD_BOOKING_STATUSPARAM_* value
     * @return void
     */
    private function set_answer_status(int $optionid, int $baid, int $status): void {
        global $DB;

        $DB->set_field('booking_answers', 'waitinglist', $status, ['id' => $baid]);
        $this->purge_answer_caches($optionid);
    }

    /**
     * Insert a booking answer for one slot directly, bypassing the checkout flow.
     *
     * @param int $optionid booking option id
     * @param int $userid user id
     * @param int $start slot start timestamp
     * @param int $end slot end timestamp
     * @param int $waitinglist booking status (MOD_BOOKING_STATUSPARAM_*)
     * @return int booking answer id
     */
    private function create_slot_answer(int $optionid, int $userid, int $start, int $end, int $waitinglist): int {
        global $DB;

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        $answer = (object)[
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

        $baid = (int)$DB->insert_record('booking_answers', $answer);
        $this->purge_answer_caches($optionid);

        return $baid;
    }

    /**
     * Drop the answer caches of an option so the next availability read hits the DB.
     *
     * @param int $optionid booking option id
     * @return void
     */
    private function purge_answer_caches(int $optionid): void {
        booking_option::purge_cache_for_answers($optionid);
        \cache::make('mod_booking', 'bookingoptionsanswers')->delete($optionid);
        singleton_service::destroy_instance();
        slot_availability::reset_caches();
    }

    /**
     * Create a slotbooking option with a single seat per slot and two enrolled students.
     *
     * @return array{0:int,1:int,2:int} [optionid, holderid, otherid]
     */
    private function create_single_seat_slot_option(): array {
        $course = self::getDataGenerator()->create_course();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance(['course' => $course->id]);
        $holder = self::getDataGenerator()->create_user();
        $other = self::getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($holder->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($other->id, $course->id, 'student');

        $record = [
            'bookingid' => $booking->id,
            'text' => 'Single seat slot option ' . uniqid('', true),
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
            'slot_max_participants_per_slot' => 1,
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

        $option = $plugingenerator->create_option((object)$record);
        singleton_service::destroy_instance();

        return [(int)$option->id, (int)$holder->id, (int)$other->id];
    }
}
