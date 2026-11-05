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
 * Tests that session slots (slot type "From option dates") which have ended are not bookable.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use mod_booking\local\slotbooking\slot_answer;
use mod_booking\local\slotbooking\slot_availability;
use mod_booking\local\slotbooking\slot_dto;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Session slots whose end is in the past must not be offered or booked (issue #2770).
 *
 * @covers \mod_booking\local\slotbooking\slot_availability
 * @covers \mod_booking\local\slotbooking\slot_dto
 */
final class slot_session_ended_test extends booking_advanced_testcase {
    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Only the running and the future session are offered; the ended one is not.
     */
    public function test_ended_session_is_not_offered(): void {
        [$ended, $running, $future] = $this->session_ranges();
        [$option, $userid] = $this->create_session_option([$ended, $running, $future]);

        $offered = [];
        foreach (slot_availability::get_slots_with_status($option->id, $userid) as $slot) {
            $offered[$slot['start'] . ':' . $slot['end']] = $slot['status'];
        }
        $this->assertSame([$this->key($running) => 'open', $this->key($future) => 'open'], $offered);

        // The picker the student sees is built from the same list.
        $pickerkeys = array_column(slot_dto::build_picker_slots($option->id, $userid), 'key');
        $this->assertSame([$this->key($running), $this->key($future)], $pickerkeys);
    }

    /**
     * When every session has ended, nothing is offered any more.
     */
    public function test_nothing_offered_when_all_sessions_ended(): void {
        $now = time();
        $first = [$now - 3 * DAYSECS, $now - 3 * DAYSECS + HOURSECS];
        $second = [$now - 2 * DAYSECS, $now - 2 * DAYSECS + HOURSECS];
        [$option, $userid] = $this->create_session_option([$first, $second]);

        $this->assertSame([], slot_availability::get_slots_with_status($option->id, $userid));
    }

    /**
     * The server rejects an ended session slot even if it is sent directly; a running and a
     * future session stay bookable.
     */
    public function test_ended_session_is_rejected_on_server(): void {
        [$ended, $running, $future] = $this->session_ranges();
        [$option, $userid] = $this->create_session_option([$ended, $running, $future]);

        $evaluation = slot_availability::evaluate_slot_for_user($option->id, $ended[0], $ended[1], $userid);
        $this->assertFalse($evaluation['bookable']);
        $this->assertSame(get_string('slot_error_selected_unavailable', 'mod_booking'), $evaluation['errormessage']);

        $this->assertTrue(slot_availability::is_slot_available($option->id, $running[0], $running[1], $userid));
        $this->assertTrue(slot_availability::is_slot_available($option->id, $future[0], $future[1], $userid));
    }

    /**
     * An existing booking on an ended session stays stored and is still shown in the
     * booked-slots overview, together with the option date itself.
     */
    public function test_booking_on_ended_session_stays_in_overview(): void {
        global $DB;

        [$ended, $running, $future] = $this->session_ranges();
        [$option, $userid, $bookingid, $cmid] = $this->create_session_option([$ended, $running, $future]);

        $baid = $this->book_session($option->id, $bookingid, $userid, $ended);

        $this->assertTrue($DB->record_exists('booking_answers', ['id' => $baid]));
        $this->assertSame(3, $DB->count_records('booking_optiondates', ['optionid' => $option->id]));

        $report = slot_dto::build_report_slots($option->id, $cmid);
        $bookings = array_column($report['slots'], 'bookings', 'key');
        $this->assertArrayHasKey($this->key($ended), $bookings);
        $this->assertSame(1, $bookings[$this->key($ended)]);
        $this->assertArrayHasKey($this->key($ended), $report['details']);
    }

    /**
     * One ended, one running and one future session, relative to now.
     *
     * @return array<int, array{0:int, 1:int}>
     */
    private function session_ranges(): array {
        $now = time();
        return [
            [$now - 2 * DAYSECS, $now - 2 * DAYSECS + HOURSECS],
            [$now - HOURSECS, $now + HOURSECS],
            [$now + 2 * DAYSECS, $now + 2 * DAYSECS + HOURSECS],
        ];
    }

    /**
     * Slot key in the "start:end" format.
     *
     * @param array $range [start, end]
     * @return string
     */
    private function key(array $range): string {
        return $range[0] . ':' . $range[1];
    }

    /**
     * Create a slot booking option of type "session" with the given option dates.
     *
     * @param array $ranges list of [start, end]
     * @return array{0:\stdClass, 1:int, 2:int, 3:int} option, student id, booking id, cmid
     */
    private function create_session_option(array $ranges): array {
        $course = self::getDataGenerator()->create_course();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance(['course' => $course->id]);
        $student = self::getDataGenerator()->create_user();

        $record = [
            'bookingid' => $booking->id,
            'text' => 'Session slot option ' . uniqid('', true),
            'course' => $course->id,
            'optiontype' => MOD_BOOKING_OPTIONTYPE_SLOTBOOKING,
            'maxanswers' => 20,
            'slot_enabled' => 1,
            'slot_type' => 'session',
            'slot_duration_minutes' => 60,
            'slot_interval_minutes' => 60,
            'slot_custom_max_duration' => HOURSECS,
            'slot_custom_min_duration' => HOURSECS,
            'slot_custom_max_days' => DAYSECS,
            'slot_custom_start_interval_minutes' => 30,
            'slot_opening_time' => '00:00',
            'slot_closing_time' => '23:59',
            'slot_valid_from' => 0,
            'slot_valid_until' => 0,
            'slot_max_participants_per_slot' => 3,
            'slot_max_slots_per_user' => 1,
            'slot_booking_view_mode' => 'list',
            'slot_add_examiners' => 0,
            'slot_teachers_required' => 0,
        ];
        for ($day = 1; $day <= 7; $day++) {
            $record['slot_day_' . $day] = 1;
        }
        foreach (array_values($ranges) as $index => $range) {
            $record['optiondateid_' . $index] = 0;
            $record['daystonotify_' . $index] = 0;
            $record['coursestarttime_' . $index] = $range[0];
            $record['courseendtime_' . $index] = $range[1];
        }

        $option = $plugingenerator->create_option((object)$record);

        singleton_service::destroy_instance();
        $cmid = (int)singleton_service::get_instance_of_booking_option_settings($option->id)->cmid;
        return [$option, (int)$student->id, (int)$booking->id, $cmid];
    }

    /**
     * Insert one BOOKED answer for a single session slot.
     *
     * @param int $optionid
     * @param int $bookingid
     * @param int $userid
     * @param array $range [start, end]
     * @return int booking answer id
     */
    private function book_session(int $optionid, int $bookingid, int $userid, array $range): int {
        global $DB;

        $answer = (object)[
            'bookingid' => $bookingid,
            'optionid' => $optionid,
            'userid' => $userid,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
            'places' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
            'startdate' => $range[0],
            'enddate' => $range[1],
            'json' => '',
        ];
        slot_answer::set_slot_data($answer, [
            'slots' => [['start' => $range[0], 'end' => $range[1]]],
            'teachers' => [],
        ]);
        $baid = (int)$DB->insert_record('booking_answers', $answer);

        slot_availability::clear_request_cache($optionid);
        singleton_service::destroy_instance();
        return $baid;
    }
}
