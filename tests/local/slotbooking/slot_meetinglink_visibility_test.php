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
 * Regression tests: the online meeting link of a session slot is only shown for slots the user booked.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use mod_booking\local\slotbooking\slot_answer;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Meeting link visibility for slot booking options with slot type "session".
 *
 * Setup in every test: three sessions (slots) with a Teams link each, the student books only one of them.
 *
 * @covers \mod_booking\booking_option::show_conference_link
 * @covers \mod_booking\booking_option::return_array_of_sessions
 */
final class slot_meetinglink_visibility_test extends booking_advanced_testcase {
    /** Field value used as meeting link, suffixed with the slot number. */
    private const LINK = 'https://teams.microsoft.com/l/meetup-join/slot-';

    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Booked on slot 2 only: in the 15-minute window of slot 1 the link of slot 1 stays hidden.
     */
    public function test_link_hidden_in_window_of_unbooked_slot(): void {
        $now = time();
        // Slot 1 starts in 10 minutes (window open), slot 2 tomorrow, slot 3 the day after.
        $slots = [
            [$now + 10 * MINSECS, $now + 70 * MINSECS],
            [$now + DAYSECS, $now + DAYSECS + HOURSECS],
            [$now + 2 * DAYSECS, $now + 2 * DAYSECS + HOURSECS],
        ];
        [$cmid, $optionid, $studentid, $sessionids] = $this->create_session_slot_option($slots, 1);

        $this->setUser($studentid);
        $option = singleton_service::get_instance_of_booking_option($cmid, $optionid);

        $this->assertFalse(
            $option->show_conference_link($sessionids[0]),
            'Link of the unbooked slot 1 must not be shown in its window.'
        );

        $rendered = $this->render_session_fields($option);
        $this->assertStringNotContainsString(self::LINK, $rendered[0], 'Slot 1 must not render the meeting button.');
        $this->assertStringNotContainsString(
            get_string('teamsmeeting', 'mod_booking'),
            $rendered[0],
            'Slot 1 must not render the meeting button.'
        );
        $this->assertSame(get_string('onlineoptiondate', 'mod_booking'), $rendered[0]);
    }

    /**
     * Booked on slot 2 only: in the 15-minute window of slot 2 the link of slot 2 is shown.
     */
    public function test_link_shown_in_window_of_booked_slot(): void {
        $now = time();
        // Slot 1 is over (yesterday), slot 2 starts in 10 minutes, slot 3 tomorrow.
        $slots = [
            [$now - DAYSECS, $now - DAYSECS + HOURSECS],
            [$now + 10 * MINSECS, $now + 70 * MINSECS],
            [$now + DAYSECS, $now + DAYSECS + HOURSECS],
        ];
        [$cmid, $optionid, $studentid, $sessionids] = $this->create_session_slot_option($slots, 1);

        $this->setUser($studentid);
        $option = singleton_service::get_instance_of_booking_option($cmid, $optionid);

        $this->assertTrue(
            $option->show_conference_link($sessionids[1]),
            'Link of the booked slot 2 must be shown in its window.'
        );

        $rendered = $this->render_session_fields($option);
        $this->assertStringContainsString(self::LINK . '2', $rendered[1], 'Slot 2 must render its meeting button.');
    }

    /**
     * Unbooked slots outside their window show the placeholder for unbooked users, not "link not available yet".
     */
    public function test_unbooked_future_slot_shows_unbooked_placeholder(): void {
        $now = time();
        $slots = [
            [$now + 10 * MINSECS, $now + 70 * MINSECS],
            [$now + DAYSECS, $now + DAYSECS + HOURSECS],
            [$now + 2 * DAYSECS, $now + 2 * DAYSECS + HOURSECS],
        ];
        [$cmid, $optionid, $studentid] = $this->create_session_slot_option($slots, 1);

        $this->setUser($studentid);
        $option = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $rendered = $this->render_session_fields($option);

        // Slot 2 is booked but not yet open.
        $this->assertSame(get_string('linknotavailableyet', 'mod_booking'), $rendered[1]);
        // Slot 3 is not booked.
        $this->assertSame(get_string('onlineoptiondate', 'mod_booking'), $rendered[2]);
    }

    /**
     * Renders the meeting customfield of each session as the option view does, for the current user.
     *
     * @param booking_option $option
     * @return string[] one rendered value per session, ordered by start time
     */
    private function render_session_fields(booking_option $option): array {
        $sessions = $option->return_array_of_sessions(null, MOD_BOOKING_DESCRIPTION_OPTIONVIEW, true, true);
        $this->assertCount(3, $sessions);
        return array_map(static function (array $session): string {
            $values = array_column($session['customfields'] ?? [], 'value');
            return implode('', $values);
        }, $sessions);
    }

    /**
     * Creates a session slot option with a Teams link per session and books a student on one slot.
     *
     * @param array $slots list of [start, end], ordered by start
     * @param int $bookedindex index in $slots of the only slot the student books
     * @return array{0:int, 1:int, 2:int, 3:int[]} cmid, optionid, student id, session ids ordered by start
     */
    private function create_session_slot_option(array $slots, int $bookedindex): array {
        global $DB;

        $course = self::getDataGenerator()->create_course();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance(['course' => $course->id]);
        $student = self::getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $record = [
            'bookingid' => $booking->id,
            'text' => 'Session slots with meeting link',
            'course' => $course->id,
            'optiontype' => MOD_BOOKING_OPTIONTYPE_SLOTBOOKING,
            'maxanswers' => 20,
            'slot_enabled' => 1,
            'slot_type' => 'session',
            'slot_duration_minutes' => 60,
            'slot_interval_minutes' => 60,
            'slot_custom_max_duration' => 60 * MINSECS,
            'slot_custom_min_duration' => 60 * MINSECS,
            'slot_custom_max_days' => DAYSECS,
            'slot_custom_start_interval_minutes' => 30,
            'slot_opening_time' => '00:00',
            'slot_closing_time' => '23:59',
            'slot_valid_from' => time() - 2 * DAYSECS,
            'slot_valid_until' => time() + 7 * DAYSECS,
            'slot_max_participants_per_slot' => 5,
            'slot_max_slots_per_user' => 3,
            'slot_booking_view_mode' => 'list',
            'slot_add_examiners' => 0,
            'slot_teachers_required' => 0,
            'slot_allow_self_rebooking' => 0,
            'slot_change_deadline_minutes' => '',
        ];
        for ($day = 1; $day <= 7; $day++) {
            $record['slot_day_' . $day] = 1;
        }
        foreach ($slots as $i => [$start, $end]) {
            $record['coursestarttime_' . $i] = $start;
            $record['courseendtime_' . $i] = $end;
            $record['daystonotify_' . $i] = 0;
        }
        $option = $plugingenerator->create_option((object)$record);

        $sessionids = array_keys($DB->get_records(
            'booking_optiondates',
            ['optionid' => $option->id],
            'coursestarttime ASC',
            'id'
        ));
        $this->assertCount(count($slots), $sessionids);

        // One Teams link per session, as stored by the optiondate customfield form.
        foreach ($sessionids as $i => $sessionid) {
            $DB->insert_record('booking_customfields', (object)[
                'bookingid' => $booking->id,
                'optionid' => $option->id,
                'optiondateid' => $sessionid,
                'cfgname' => 'teamsmeeting',
                'value' => self::LINK . ($i + 1),
            ]);
        }

        // The student books only one slot (same payload shape as the slot booking flow).
        [$start, $end] = $slots[$bookedindex];
        $answer = (object)[
            'bookingid' => $booking->id,
            'optionid' => $option->id,
            'userid' => $student->id,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
            'places' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
            'startdate' => $start,
            'enddate' => $end,
            'json' => '',
        ];
        slot_answer::set_slot_data($answer, [
            'slots' => [['start' => $start, 'end' => $end]],
            'num_slots' => 1,
            'teachers_per_slot' => [['start' => $start, 'end' => $end, 'teachers' => []]],
            'teachers' => [],
        ]);
        $DB->insert_record('booking_answers', $answer);

        booking_option::purge_cache_for_option($option->id);
        singleton_service::destroy_instance();

        $cmid = (int)singleton_service::get_instance_of_booking_option_settings($option->id)->cmid;
        return [$cmid, (int)$option->id, (int)$student->id, $sessionids];
    }
}
