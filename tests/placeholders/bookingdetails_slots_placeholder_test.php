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
 * Tests for the {bookingdetails} placeholder of slot booking options in mails.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\local\slotbooking\slot_answer;
use mod_booking\local\slotbooking\slot_dto;
use mod_booking\placeholders\placeholders_info;
use mod_booking\tests\booking_advanced_testcase;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests for the {bookingdetails} placeholder of slot booking options in mails.
 *
 * A slot option has no sessions and often no teachers. The mail must not show the empty
 * "Session(s)" / "Teachers" headings, but the slots the recipient booked. Mails are sent by a task,
 * so the recipient is not the logged in user.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\placeholders\placeholders\bookingdetails
 * @covers     \mod_booking\output\bookingoption_description
 */
final class bookingdetails_slots_placeholder_test extends booking_advanced_testcase {
    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        placeholders_info::$placeholders = [];
    }

    /**
     * Tear down.
     */
    public function tearDown(): void {
        placeholders_info::$placeholders = [];
        parent::tearDown();
    }

    /**
     * The mail lists the booked slots of the recipient, not the ones of the user running the task.
     *
     * @return void
     */
    public function test_mail_lists_booked_slots_of_recipient(): void {
        [$cmid, $optionid, $student] = $this->create_slot_option_and_user(0);

        $studentslots = [
            [strtotime('2050-01-07 09:00:00 UTC'), strtotime('2050-01-07 10:00:00 UTC')],
            [strtotime('2050-01-09 11:00:00 UTC'), strtotime('2050-01-09 12:00:00 UTC')],
        ];
        $adminslot = [strtotime('2050-01-10 15:00:00 UTC'), strtotime('2050-01-10 16:00:00 UTC')];
        $this->insert_slot_answer($optionid, (int) $student->id, $studentslots);
        $this->insert_slot_answer($optionid, (int) get_admin()->id, [$adminslot]);

        $html = $this->render($cmid, $optionid, (int) $student->id);

        $this->assertStringContainsString(get_string('slot_report_numslots', 'mod_booking'), $html);
        foreach ($studentslots as [$start, $end]) {
            $this->assertStringContainsString(s(slot_dto::day_label($start)), $html);
            $this->assertStringContainsString(s(slot_dto::time_range_label($start, $end)), $html);
        }
        $this->assertStringNotContainsString(s(slot_dto::day_label($adminslot[0])), $html);
    }

    /**
     * A slot option without sessions and teachers shows neither heading in the mail.
     *
     * @return void
     */
    public function test_mail_hides_empty_sessions_and_teachers_headings(): void {
        [$cmid, $optionid, $student] = $this->create_slot_option_and_user(0);
        $this->insert_slot_answer($optionid, (int) $student->id, [
            [strtotime('2050-01-07 09:00:00 UTC'), strtotime('2050-01-07 10:00:00 UTC')],
        ]);

        $html = $this->render($cmid, $optionid, (int) $student->id);

        $this->assertStringNotContainsString(get_string('sessions', 'mod_booking'), $html);
        $this->assertStringNotContainsString(get_string('teachers', 'mod_booking'), $html);
    }

    /**
     * Mails never contain the per-slot cancel buttons, even if self-service rebooking is enabled.
     *
     * @return void
     */
    public function test_mail_contains_no_slot_release_buttons(): void {
        [$cmid, $optionid, $student] = $this->create_slot_option_and_user(1);
        $this->insert_slot_answer($optionid, (int) $student->id, [
            [strtotime('2050-01-07 09:00:00 UTC'), strtotime('2050-01-07 10:00:00 UTC')],
        ]);

        $this->setUser($student);
        $html = $this->render($cmid, $optionid, (int) $student->id);

        $this->assertStringContainsString(get_string('slot_report_numslots', 'mod_booking'), $html);
        $this->assertStringNotContainsString('booking-slot-release', $html);
    }

    /**
     * Regression: a normal option with a session still shows the sessions heading and the date.
     *
     * @return void
     */
    public function test_mail_still_shows_sessions_of_normal_option(): void {
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

        $html = $this->render((int) $booking->cmid, (int) $option->id, (int) $student->id);

        $this->assertStringContainsString(get_string('sessions', 'mod_booking'), $html);
        $this->assertStringNotContainsString(get_string('slot_report_numslots', 'mod_booking'), $html);
    }

    /**
     * Render "{bookingdetails}" for a mail, the way message_controller does.
     *
     * @param int $cmid
     * @param int $optionid
     * @param int $userid recipient
     * @return string
     */
    private function render(int $cmid, int $optionid, int $userid): string {
        singleton_service::destroy_instance();
        placeholders_info::$placeholders = [];
        return placeholders_info::render_text(
            '{bookingdetails}',
            $cmid,
            $optionid,
            $userid,
            0,
            0,
            0,
            MOD_BOOKING_DESCRIPTION_MAIL
        );
    }

    /**
     * Create a fixed slotbooking option and an enrolled student.
     *
     * @param int $selfrebooking slot_allow_self_rebooking
     * @return array{0:int,1:int,2:\stdClass} [cmid, optionid, student]
     */
    private function create_slot_option_and_user(int $selfrebooking): array {
        $course = $this->getDataGenerator()->create_course();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance(['course' => $course->id]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $record = [
            'bookingid' => $booking->id,
            'text' => 'Bookingdetails slot option',
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
            'slot_allow_self_rebooking' => $selfrebooking,
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
     * @return void
     */
    private function insert_slot_answer(int $optionid, int $userid, array $ranges): void {
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

        $DB->insert_record('booking_answers', $answer);
        \cache::make('mod_booking', 'bookingoptionsanswers')->delete($optionid);
        singleton_service::destroy_instance();
    }
}
