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

use mod_booking\external\save_slot_selection;
use mod_booking\form\condition\slotbooking_form;
use mod_booking\local\slotbooking\slot_answer;
use mod_booking\local\slotbooking\slot_availability;
use mod_booking\local\slotbooking\slot_dto;
use mod_booking\tests\booking_advanced_testcase;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * T6 - picking an examiner per slot.
 *
 * An option can require an examiner for every booked slot, chosen from the option's examiner pool.
 * The picker has to announce how many are required and who is available, booking without one has
 * to be refused, and a booking made with one has to carry the examiner into the slot overview.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\slotbooking\slot_availability::get_available_teachers_for_slot
 * @covers     \mod_booking\form\condition\slotbooking_form::validation
 * @covers     \mod_booking\local\slotbooking\slot_dto::build_meta
 */
final class slot_examiner_selection_test extends booking_advanced_testcase {
    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * The picker announces the examiner requirement and offers the option's pool.
     *
     * This is what makes the "Examiners per slot: 1" box with its selection list appear under the
     * calendar - without the count the box is not rendered at all, without the pool it is empty.
     *
     * @return void
     */
    public function test_picker_announces_the_examiner_requirement_and_the_pool(): void {
        [$optionid, $studentid, $examiners] = $this->create_option_with_examiners();

        $meta = slot_dto::build_meta($optionid, $studentid);
        $this->assertSame(1, (int)$meta['teachersrequired'], 'One examiner per slot must be required.');

        $slots = slot_dto::build_picker_slots($optionid, $studentid);
        $this->assertNotEmpty($slots, 'The option must offer bookable slots.');

        $available = slot_availability::get_available_teachers_for_slot(
            $optionid,
            (int)$slots[0]['start'],
            (int)$slots[0]['end']
        );
        $this->assertCount(2, $available, 'Both examiners of the pool must be offered.');
        $this->assertEqualsCanonicalizing(
            $examiners,
            array_map(static fn(array $t): int => (int)$t['id'], $available),
            'The offered examiners must be exactly the option pool.'
        );
        $this->assertNotEmpty($available[0]['fullname'], 'Examiners are offered by name, not by id alone.');
    }

    /**
     * Booking without picking an examiner is refused.
     *
     * @return void
     */
    public function test_booking_without_an_examiner_is_rejected(): void {
        [$optionid, $studentid] = $this->create_option_with_examiners();
        $slots = slot_dto::build_picker_slots($optionid, $studentid);
        $key = (string)$slots[0]['key'];

        $errors = $this->validate($optionid, $studentid, $key, []);

        $this->assertArrayHasKey('slot_selection', $errors, 'The submission must be refused.');
        $this->assertSame(get_string('slot_error_teacher_required', 'mod_booking'), $errors['slot_selection']);

        // The live pre-validation behind the picker refuses it as well, so the message shows up
        // the moment the slot is clicked instead of only after a full submit.
        $result = save_slot_selection::execute($optionid, $studentid, json_encode([$key]), json_encode([]));
        $this->assertFalse((bool)$result['valid'], 'The live validation must refuse it too.');
        $liveerrors = json_decode($result['errors'], true);
        $this->assertArrayHasKey('slot_selection', $liveerrors);
        // The slot itself is perfectly bookable here - only the examiner is missing. Reporting
        // "Please select a valid slot." sent the user hunting for a different slot instead, and no
        // slot they could possibly pick ever cleared the message.
        $this->assertSame(
            get_string('slot_error_teacher_required', 'mod_booking'),
            $liveerrors['slot_selection'],
            'The live validation must name the missing examiner instead of blaming the slot.'
        );
    }

    /**
     * Booking with an examiner passes and the examiner is carried into the slot overview.
     *
     * @return void
     */
    public function test_booking_with_an_examiner_keeps_the_assignment(): void {
        global $DB;

        [$optionid, $studentid, $examiners] = $this->create_option_with_examiners();
        $slots = slot_dto::build_picker_slots($optionid, $studentid);
        $slot = $slots[0];
        $key = (string)$slot['key'];
        $examiner = (int)$examiners[0];

        // Validation accepts the same submission once an examiner is picked.
        $this->assertSame([], $this->validate($optionid, $studentid, $key, [$key => [$examiner]]));

        // Book the slot the way the booking flow does, with the examiner on the slot.
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $answer = (object)[
            'bookingid' => (int)$settings->bookingid,
            'userid' => $studentid,
            'optionid' => $optionid,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
            'completed' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
            'timebooked' => time(),
            'startdate' => (int)$slot['start'],
            'enddate' => (int)$slot['end'],
            'json' => '{}',
        ];
        $answer->id = $DB->insert_record('booking_answers', $answer);
        slot_answer::set_slot_data($answer, [
            'slots' => [['start' => (int)$slot['start'], 'end' => (int)$slot['end']]],
            'num_slots' => 1,
            'teachers_per_slot' => [[
                'start' => (int)$slot['start'],
                'end' => (int)$slot['end'],
                'teachers' => [$examiner],
            ]],
        ]);
        $DB->update_record('booking_answers', $answer);
        booking_option::purge_cache_for_answers($optionid);
        singleton_service::destroy_instance();

        // The slot overview lists the booked slot and names the examiner on it.
        $report = slot_dto::build_report_slots($optionid, (int)$settings->cmid);
        $keys = array_map(static fn(array $r): string => (string)$r['key'], $report['slots']);
        $this->assertContains($key, $keys, 'The booked slot must appear in the overview.');

        $this->assertArrayHasKey($key, $report['details'], 'The slot must have a detail entry.');
        $examinername = fullname(singleton_service::get_instance_of_user($examiner));
        $this->assertSame(
            [$examinername],
            array_values($report['details'][$key]['teachers']),
            'The overview must name exactly the examiner chosen for this slot.'
        );

        // What the student themselves sees on the option page and in the options table: the booked
        // slot row has to name the examiner too, otherwise the booking says WHEN but never WITH
        // WHOM. Matched by answer row (baid), so a second booking's examiner can never leak onto
        // this slot.
        $bookedrows = slot_dto::build_booked_slot_rows($optionid, $studentid);
        $this->assertCount(1, $bookedrows, 'The student holds exactly one booked slot.');
        $this->assertSame($key, (string)$bookedrows[0]['key']);
        $this->assertSame((int)$answer->id, (int)$bookedrows[0]['baid']);
        $this->assertTrue((bool)$bookedrows[0]['hasteachers'], 'The booked slot must carry an examiner.');
        $this->assertSame([$examinername], $bookedrows[0]['teachers']);
        $this->assertSame($examinername, (string)$bookedrows[0]['teacherlabel']);
    }

    /**
     * Run the booking form's validation for one slot and a teacher selection.
     *
     * @param int $optionid
     * @param int $userid
     * @param string $slotkey
     * @param array $teacherselection map of slot key to examiner ids
     * @return array validation errors
     */
    private function validate(int $optionid, int $userid, string $slotkey, array $teacherselection): array {
        $form = new slotbooking_form(null, [], 'post', '', [], true, [
            'id' => $optionid,
            'userid' => $userid,
        ], true);

        return $form->validation([
            'id' => $optionid,
            'userid' => $userid,
            'slot_validation_error_target' => 'slot_selection',
            'slot_max_selection' => 1,
            'slot_teachers_required_count' => 1,
            'slot_teacher_selection' => json_encode($teacherselection),
            'slot_selection' => $slotkey,
        ], []);
    }

    /**
     * An option that requires one examiner per slot, with two of them in its pool.
     *
     * @return array{0:int, 1:int, 2:array<int, int>} optionid, student id, examiner ids
     */
    private function create_option_with_examiners(): array {
        $course = self::getDataGenerator()->create_course();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance(['course' => $course->id]);

        $student = self::getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $examiners = [];
        foreach (['Petra', 'Paul'] as $firstname) {
            $examiner = self::getDataGenerator()->create_user(['firstname' => $firstname, 'lastname' => 'Examiner']);
            $this->getDataGenerator()->enrol_user($examiner->id, $course->id, 'editingteacher');
            $examiners[] = (int)$examiner->id;
        }

        $record = [
            'bookingid' => $booking->id,
            'text' => 'Examiner option ' . uniqid('', true),
            'course' => $course->id,
            'optiontype' => MOD_BOOKING_OPTIONTYPE_SLOTBOOKING,
            'maxanswers' => 20,
            'slot_enabled' => 1,
            'slot_type' => 'fixed',
            'slot_duration_minutes' => 30,
            'slot_interval_minutes' => 30,
            'slot_opening_time' => '10:00',
            'slot_closing_time' => '12:00',
            'slot_valid_from' => strtotime('2050-01-07 00:00:00 UTC'),
            'slot_valid_until' => strtotime('2050-01-10 23:59:59 UTC'),
            'slot_max_participants_per_slot' => 1,
            'slot_max_slots_per_user' => 1,
            'slot_booking_view_mode' => 'list',
            'slot_add_examiners' => 1,
            'slot_teacher_pool' => $examiners,
            'slot_teachers_required' => 1,
        ];
        for ($day = 1; $day <= 7; $day++) {
            $record['slot_day_' . $day] = 1;
        }

        $option = $plugingenerator->create_option((object)$record);
        $optionid = (int)$option->id;

        singleton_service::destroy_instance();

        return [$optionid, (int)$student->id, $examiners];
    }
}
