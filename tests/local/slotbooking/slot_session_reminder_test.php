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
 * Regression tests: session reminders of a session slot option only reach users who booked that session.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\booking_rules\rules_info;
use mod_booking\local\slotbooking\slot_answer;
use mod_booking\tests\booking_advanced_testcase;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Session reminders for slot booking options with slot type "session".
 *
 * Setup: three sessions (slots) in June 2050, the student books only slot 2.
 *
 * @covers \mod_booking\booking_rules\rules\rule_daysbefore::check_if_rule_still_applies
 * @covers \mod_booking\booking_rules\rules\rule_specifictime::check_if_rule_still_applies
 * @covers \mod_booking\booking_option::sendmessage_notification
 */
final class slot_session_reminder_test extends booking_advanced_testcase {
    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Rules with datefield optiondatestarttime.
     *
     * @return array
     */
    public static function session_rule_provider(): array {
        return [
            'days before' => ['rule_daysbefore', '{"days":"1","datefield":"optiondatestarttime"}'],
            'specific time' => ['rule_specifictime', '{"seconds":86400,"datefield":"optiondatestarttime"}'],
        ];
    }

    /**
     * A reminder rule on optiondatestarttime sends the student a reminder for slot 2 only.
     *
     * @dataProvider session_rule_provider
     * @param string $rulename
     * @param string $ruledata
     */
    public function test_rule_reminder_only_for_booked_session(string $rulename, string $ruledata): void {
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->create_rule([
            'conditionname' => 'select_student_in_bo',
            'name' => 'Session reminder',
            'contextid' => 1,
            'conditiondata' => '{"borole":"0"}',
            'actionname' => 'send_mail',
            'actiondata' => '{"sendical":0,"sendicalcreateorcancel":"",'
                . '"subject":"Session reminder","template":"Your session starts soon","templateformat":"1"}',
            'rulename' => $rulename,
            'ruledata' => $ruledata,
        ]);

        [, , $studentid] = $this->create_session_slot_option(1);

        rules_info::execute_booking_rules();

        time_mock::set_mock_time(strtotime('30 June 2050 16:00'));
        unset_config('noemailever');
        ob_start();
        $sink = $this->redirectMessages();
        $plugingenerator->runtaskswithintime(time_mock::get_mock_time());
        $messages = $sink->get_messages();
        $sink->close();
        ob_end_clean();

        $tostudent = array_filter($messages, static fn($m) => (int)$m->useridto === $studentid);
        $this->assertCount(1, $tostudent, 'The student must get the reminder of the booked slot 2 only.');
    }

    /**
     * Legacy session reminder (daystonotify on the optiondate) is only sent for the booked session.
     */
    public function test_legacy_session_reminder_only_for_booked_session(): void {
        [$cmid, $optionid, $studentid, $sessionids] = $this->create_session_slot_option(1);
        $option = singleton_service::get_instance_of_booking_option($cmid, $optionid);

        unset_config('noemailever');
        $sink = $this->redirectMessages();
        $option->sendmessage_notification(MOD_BOOKING_MSGPARAM_SESSIONREMINDER, [], $sessionids[0]);
        $unbooked = $sink->get_messages();
        $sink->clear();
        $option->sendmessage_notification(MOD_BOOKING_MSGPARAM_SESSIONREMINDER, [], $sessionids[1]);
        $booked = $sink->get_messages();
        $sink->close();

        $countfor = static fn(array $messages): int =>
            count(array_filter($messages, static fn($m) => (int)$m->useridto === $studentid));
        $this->assertSame(0, $countfor($unbooked), 'No reminder for the unbooked slot 1.');
        $this->assertSame(1, $countfor($booked), 'Reminder for the booked slot 2.');
    }

    /**
     * Creates a session slot option with three sessions in June 2050 and books a student on one slot.
     *
     * @param int $bookedindex index (0-2) of the only slot the student books
     * @return array{0:int, 1:int, 2:int, 3:int[]} cmid, optionid, student id, session ids ordered by start
     */
    private function create_session_slot_option(int $bookedindex): array {
        global $DB;

        $course = self::getDataGenerator()->create_course();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance(['course' => $course->id]);
        $student = self::getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $slots = [
            [strtotime('10 June 2050 15:00'), strtotime('10 June 2050 16:00')],
            [strtotime('11 June 2050 15:00'), strtotime('11 June 2050 16:00')],
            [strtotime('12 June 2050 15:00'), strtotime('12 June 2050 16:00')],
        ];
        $record = [
            'bookingid' => $booking->id,
            'text' => 'Session slots with reminder',
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
            'slot_valid_from' => strtotime('1 June 2050'),
            'slot_valid_until' => strtotime('30 June 2050'),
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
        $this->assertCount(3, $sessionids);

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
