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

use mod_booking\booking_rules\conditions\select_user_from_event;
use mod_booking\local\slotbooking\slot_answer;
use mod_booking\local\slotbooking\slot_dto;
use mod_booking\local\slotbooking\slot_mover;
use mod_booking\tests\booking_advanced_testcase;
use MoodleQuickForm;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once($CFG->libdir . '/formslib.php');

/**
 * "Select user from event" offers "User affected by the event" for the slot events (GH-1686).
 *
 * The slot events set the affected user (relateduserid) to the user of the booking. A rule on them
 * could only choose the user who triggered the event, so a mail about a move or cancellation made
 * by staff went to the staff member instead of the participant.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\booking_rules\conditions\select_user_from_event
 */
final class rules_slot_events_affected_user_test extends booking_advanced_testcase {
    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Provider: the slot events.
     *
     * @return array
     */
    public static function slot_event_provider(): array {
        return [
            'slot booked' => ['bookinganswer_slotbooked'],
            'slot moved' => ['bookinganswer_slotmoved'],
            'slot cancelled' => ['bookinganswer_slotcancelled'],
        ];
    }

    /**
     * The condition form offers "User affected by the event" for every slot event.
     *
     * @dataProvider slot_event_provider
     * @param string $eventname short event class name
     * @return void
     */
    public function test_affected_user_is_offered_for_slot_events(string $eventname): void {
        $mform = new MoodleQuickForm('testform', 'post', '');
        select_user_from_event::add_userselect_to_mform($mform, $eventname);

        $options = [];
        foreach ($mform->getElement('condition_select_user_from_event_type')->_options as $option) {
            $options[] = $option['attr']['value'];
        }
        $this->assertContains('relateduserid', $options, 'The affected user must be offered.');
        $this->assertContains('userid', $options, 'The user who triggered the event stays.');
    }

    /**
     * Staff move a participant's slot: a rule with "User affected by the event" mails the participant,
     * not the staff member who moved the slot.
     *
     * @return void
     */
    public function test_rule_on_staff_move_mails_the_participant(): void {
        global $DB;

        [$optionid, $bookingid, $student] = $this->create_slot_option();
        $slots = slot_dto::build_picker_slots($optionid, (int)$student->id);
        usort($slots, static fn($a, $b) => (int)$a['start'] <=> (int)$b['start']);
        $baid = $this->create_booked_slot_answer($optionid, $bookingid, (int)$student->id, $slots[0]);

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->create_rule([
            'name' => 'Slot moved: tell the participant',
            'conditionname' => 'select_user_from_event',
            'contextid' => 1,
            'conditiondata' => '{"userfromeventtype":"relateduserid"}',
            'actionname' => 'send_mail',
            'actiondata' => '{"sendical":0,"sendicalcreateorcancel":"",'
                . '"subject":"GH1686 moved {slotsmovedto}","template":"x","templateformat":"1"}',
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{"boevent":"\\\\mod_booking\\\\event\\\\bookinganswer_slotmoved",'
                . '"condition":"0","aftercompletion":1,"cancelrules":[]}',
        ]);

        // Staff (the admin) move the slot of the participant.
        $sink = $this->redirectEmails();
        $this->setAdminUser();
        slot_mover::move($optionid, $baid, [$slots[1]['key']], 'Room not available');
        $sink->close();

        $recipients = [];
        foreach (\core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc') as $task) {
            if (str_contains(json_encode($task->get_custom_data()), 'GH1686 moved')) {
                $recipients[] = (int)$task->get_userid();
            }
        }
        $this->assertSame([(int)$student->id], $recipients, 'The rule mail must go to the participant only.');
        $this->assertTrue($DB->record_exists('booking_answers', ['id' => $baid]));
    }

    /**
     * A free slot option of type "fixed" in a new course.
     *
     * @return array{0:int, 1:int, 2:\stdClass} optionid, bookingid, student
     */
    private function create_slot_option(): array {
        $generator = self::getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = $generator->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance(['course' => $course->id]);

        $record = [
            'bookingid' => $booking->id,
            'text' => 'Slots for the affected user test',
            'course' => $course->id,
            'optiontype' => MOD_BOOKING_OPTIONTYPE_SLOTBOOKING,
            'maxanswers' => 20,
            'slot_enabled' => 1,
            'slot_type' => 'fixed',
            'slot_duration_minutes' => 60,
            'slot_interval_minutes' => 60,
            'slot_opening_time' => '09:00',
            'slot_closing_time' => '12:00',
            'slot_valid_from' => strtotime('2050-01-07 00:00:00 UTC'),
            'slot_valid_until' => strtotime('2050-01-10 23:59:59 UTC'),
            'slot_max_participants_per_slot' => 3,
            'slot_max_slots_per_user' => 4,
            'slot_booking_view_mode' => 'list',
            'slot_allow_self_rebooking' => 1,
            'slot_change_deadline_minutes' => '',
        ];
        for ($day = 1; $day <= 7; $day++) {
            $record['slot_day_' . $day] = 1;
        }
        $option = $plugingenerator->create_option((object)$record);

        return [(int)$option->id, (int)$booking->id, $student];
    }

    /**
     * Insert a booked answer holding one slot, like the slot booking flow stores it.
     *
     * @param int $optionid
     * @param int $bookingid
     * @param int $userid
     * @param array $slot picker slot with start and end
     * @return int booking answer id
     */
    private function create_booked_slot_answer(int $optionid, int $bookingid, int $userid, array $slot): int {
        global $DB;

        $range = ['start' => (int)$slot['start'], 'end' => (int)$slot['end']];
        $answer = (object)[
            'bookingid' => $bookingid,
            'optionid' => $optionid,
            'userid' => $userid,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
            'places' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
            'startdate' => $range['start'],
            'enddate' => $range['end'],
            'json' => '',
        ];
        slot_answer::set_slot_data($answer, [
            'slots' => [$range],
            'num_slots' => 1,
            'teachers_per_slot' => [$range + ['teachers' => []]],
            'teachers' => [],
        ]);
        $baid = (int)$DB->insert_record('booking_answers', $answer);

        booking_option::purge_cache_for_answers($optionid);
        singleton_service::destroy_instance();

        return $baid;
    }
}
