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
 * Tests for the {slotsbooked} placeholder in booking confirmations.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\local\slotbooking\slot_answer;
use mod_booking\option\dates_handler;
use mod_booking\placeholders\placeholders_info;
use mod_booking\tests\booking_advanced_testcase;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests for the {slotsbooked} placeholder.
 *
 * The placeholder must show booked slots not only inside a "slot booked" rule, but also in the
 * regular booking confirmation: the rule on "bookingoption_booked" (payload carries the booking
 * answer json) and the legacy confirmation mail (no rule data at all).
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\placeholders\placeholders\slotsbooked
 * @covers     \mod_booking\local\slotbooking\slot_event_placeholders
 */
final class slotsbooked_placeholder_test extends booking_advanced_testcase {
    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Existing behaviour: the slots carried by a "slot booked" event payload are rendered.
     *
     * @return void
     */
    public function test_renders_slots_from_slotbooked_event_payload(): void {
        [$cmid, $optionid, $student] = $this->create_slot_option_and_user();
        $eventslot = [strtotime('2050-01-08 14:00:00 UTC'), strtotime('2050-01-08 15:00:00 UTC')];
        // An unrelated booked answer must not leak into the event based output.
        $this->insert_slot_answer($optionid, (int) $student->id, [[strtotime('2050-01-07 09:00:00 UTC'),
            strtotime('2050-01-07 10:00:00 UTC')]]);

        $rulejson = $this->rulejson([
            'optionid' => $optionid,
            'bookedslots' => [['start' => $eventslot[0], 'end' => $eventslot[1]]],
        ]);

        $this->assertSame(
            $this->expected([$eventslot]),
            $this->render($cmid, $optionid, (int) $student->id, $rulejson)
        );
    }

    /**
     * Booking confirmation rule (bookingoption_booked): the payload has no slot keys, but carries the
     * json of the new booking answer - exactly the slots of that booking are rendered, not the slots
     * of an earlier purchase of the same user.
     *
     * @return void
     */
    public function test_renders_slots_of_booking_answer_from_bookingoption_booked_payload(): void {
        [$cmid, $optionid, $student] = $this->create_slot_option_and_user();

        $earlier = [strtotime('2050-01-07 09:00:00 UTC'), strtotime('2050-01-07 10:00:00 UTC')];
        $this->insert_slot_answer($optionid, (int) $student->id, [$earlier]);

        $new = [
            [strtotime('2050-01-08 09:00:00 UTC'), strtotime('2050-01-08 10:00:00 UTC')],
            [strtotime('2050-01-09 11:00:00 UTC'), strtotime('2050-01-09 12:00:00 UTC')],
        ];
        $baid = $this->insert_slot_answer($optionid, (int) $student->id, $new);

        global $DB;
        $rulejson = $this->rulejson([
            'baid' => $baid,
            'json' => $DB->get_field('booking_answers', 'json', ['id' => $baid]),
        ]);

        $this->assertSame(
            $this->expected($new),
            $this->render($cmid, $optionid, (int) $student->id, $rulejson)
        );
    }

    /**
     * Legacy confirmation mail (no rule data): all currently booked slots of the user are rendered,
     * aggregated over all of their active answers and sorted by start.
     *
     * @return void
     */
    public function test_renders_all_booked_slots_of_user_without_rule_data(): void {
        [$cmid, $optionid, $student] = $this->create_slot_option_and_user();

        $first = [strtotime('2050-01-07 09:00:00 UTC'), strtotime('2050-01-07 10:00:00 UTC')];
        $second = [strtotime('2050-01-07 11:00:00 UTC'), strtotime('2050-01-07 12:00:00 UTC')];
        $third = [strtotime('2050-01-09 09:00:00 UTC'), strtotime('2050-01-09 10:00:00 UTC')];
        $this->insert_slot_answer($optionid, (int) $student->id, [$third]);
        $this->insert_slot_answer($optionid, (int) $student->id, [$first, $second]);

        $this->assertSame(
            $this->expected([$first, $second, $third]),
            $this->render($cmid, $optionid, (int) $student->id, '')
        );
    }

    /**
     * Without rule data, slots of other users are never rendered.
     *
     * @return void
     */
    public function test_renders_nothing_for_user_without_slots(): void {
        [$cmid, $optionid, $student] = $this->create_slot_option_and_user();
        $this->insert_slot_answer($optionid, (int) $student->id, [[strtotime('2050-01-07 09:00:00 UTC'),
            strtotime('2050-01-07 10:00:00 UTC')]]);

        $otheruser = $this->getDataGenerator()->create_user();

        $this->assertSame('', $this->render($cmid, $optionid, (int) $otheruser->id, ''));
    }

    /**
     * A non-slot option renders nothing, with or without rule data.
     *
     * @return void
     */
    public function test_renders_nothing_for_normal_option(): void {
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id]);
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option((object) [
            'bookingid' => $booking->id,
            'text' => 'Normal option',
            'course' => $course->id,
            'coursestarttime_0' => strtotime('2050-02-01 10:00:00 UTC'),
            'courseendtime_0' => strtotime('2050-02-01 12:00:00 UTC'),
        ]);
        $student = $this->getDataGenerator()->create_user();
        singleton_service::destroy_instance();

        $this->assertSame('', $this->render((int) $booking->cmid, (int) $option->id, (int) $student->id, ''));
        $this->assertSame('', $this->render(
            (int) $booking->cmid,
            (int) $option->id,
            (int) $student->id,
            $this->rulejson(['baid' => 1, 'json' => ''])
        ));
    }

    /**
     * Render "{slotsbooked}" through the placeholder engine, the way message_controller does.
     *
     * @param int $cmid
     * @param int $optionid
     * @param int $userid
     * @param string $rulejson
     * @return string
     */
    private function render(int $cmid, int $optionid, int $userid, string $rulejson): string {
        singleton_service::destroy_instance();
        return placeholders_info::render_text(
            '{slotsbooked}',
            $cmid,
            $optionid,
            $userid,
            0,
            0,
            0,
            MOD_BOOKING_DESCRIPTION_MAIL,
            $rulejson
        );
    }

    /**
     * Expected placeholder output for the given ranges (same format as the other slot placeholders).
     *
     * @param array $ranges list of [start, end]
     * @return string
     */
    private function expected(array $ranges): string {
        $rows = [];
        foreach ($ranges as [$start, $end]) {
            $rows[] = dates_handler::prettify_optiondates_start_end($start, $end, current_language());
        }
        return implode('; ', $rows);
    }

    /**
     * Build a rule json carrying the given event "other" payload, like rule_react_on_event does.
     *
     * @param array $other
     * @return string
     */
    private function rulejson(array $other): string {
        return json_encode([
            'datafromevent' => [
                'other' => $other,
            ],
        ]);
    }

    /**
     * Create a fixed slotbooking option and an enrolled student.
     *
     * @return array{0:int,1:int,2:\stdClass} [cmid, optionid, student]
     */
    private function create_slot_option_and_user(): array {
        $course = $this->getDataGenerator()->create_course();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance(['course' => $course->id]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $record = [
            'bookingid' => $booking->id,
            'text' => 'Slotsbooked placeholder option',
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
            'slot_custom_start_interval_minutes' => 60,
            'slot_opening_time' => '08:00',
            'slot_closing_time' => '18:00',
            'slot_valid_from' => strtotime('2050-01-07 00:00:00 UTC'),
            'slot_valid_until' => strtotime('2050-01-10 23:59:59 UTC'),
            'slot_max_participants_per_slot' => 5,
            'slot_max_slots_per_user' => 5,
            'slot_booking_view_mode' => 'list',
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

        return [(int) $booking->cmid, (int) $option->id, $student];
    }

    /**
     * Insert a booked slot answer directly (bypassing checkout), like the other slotbooking tests do.
     *
     * @param int $optionid
     * @param int $userid
     * @param array $ranges list of [start, end]
     * @return int booking answer id
     */
    private function insert_slot_answer(int $optionid, int $userid, array $ranges): int {
        global $DB;

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $slots = array_map(fn($r) => ['start' => $r[0], 'end' => $r[1]], $ranges);

        $answer = (object) [
            'bookingid' => (int) $settings->bookingid,
            'optionid' => $optionid,
            'userid' => $userid,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
            'places' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
            'startdate' => $ranges[0][0],
            'enddate' => end($ranges)[1],
            'json' => '',
        ];
        slot_answer::set_slot_data($answer, ['slots' => $slots, 'teachers' => []]);

        $baid = (int) $DB->insert_record('booking_answers', $answer);
        \cache::make('mod_booking', 'bookingoptionsanswers')->delete($optionid);
        singleton_service::destroy_instance();

        return $baid;
    }
}
