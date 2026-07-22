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
 * Tests for the presence status bulk modal of the bookings tracker.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\form\optiondates\modal_change_status;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use moodle_exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->dirroot/mod/booking/lib.php");

/**
 * The modal changes the presence status of the checked booked users - in the
 * option scope (stored in booking_answers.status) and per session in the
 * optiondate scope (stored in booking_optiondates_answers). Both are gated by
 * mod/booking:managebookedusers.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class modal_change_status_test extends booking_advanced_testcase {
    /**
     * Option scope: checkedids are booking_answers ids; the status of all
     * checked users is written to booking_answers.status.
     *
     * @covers \mod_booking\form\optiondates\modal_change_status::process_dynamic_submission
     */
    public function test_option_scope_sets_presence_status(): void {
        global $DB;

        $this->setAdminUser();
        [$settings, $answerids] = $this->create_booked_option_with_session();

        $status = (int)array_key_last(booking::get_possible_presences(true));

        $this->submit_modal([
            'cmid' => (int)$settings->cmid,
            'optionid' => (int)$settings->id,
            'scope' => 'option',
            'checkedids' => implode(',', $answerids),
            'status' => $status,
        ]);

        foreach ($answerids as $answerid) {
            $this->assertEquals(
                $status,
                (int)$DB->get_field('booking_answers', 'status', ['id' => $answerid]),
                "The presence status of answer $answerid must be updated."
            );
        }
    }

    /**
     * Optiondate scope: checkedids have the format optionid-optiondateid-userid;
     * the status is stored per session in booking_optiondates_answers.
     *
     * @covers \mod_booking\form\optiondates\modal_change_status::process_dynamic_submission
     */
    public function test_optiondate_scope_sets_presence_status_per_session(): void {
        global $DB;

        $this->setAdminUser();
        [$settings, , $optiondateid, $students] = $this->create_booked_option_with_session();

        $status = (int)array_key_last(booking::get_possible_presences(true));
        $checkedids = [];
        foreach ($students as $student) {
            $checkedids[] = $settings->id . '-' . $optiondateid . '-' . $student->id;
        }

        $this->submit_modal([
            'cmid' => (int)$settings->cmid,
            'optionid' => (int)$settings->id,
            'optiondateid' => $optiondateid,
            'scope' => 'optiondate',
            'checkedids' => implode(',', $checkedids),
            'status' => $status,
        ]);

        foreach ($students as $student) {
            $record = $DB->get_record('booking_optiondates_answers', [
                'optionid' => $settings->id,
                'optiondateid' => $optiondateid,
                'userid' => $student->id,
            ]);
            $this->assertNotEmpty($record, "A session answer for user {$student->id} must exist.");
            $this->assertEquals($status, (int)$record->status);
        }
    }

    /**
     * The modal is gated by managebookedusers: non-editing teachers (read-only
     * in the tracker) and students are rejected on construction already.
     *
     * @covers \mod_booking\form\optiondates\modal_change_status::check_access_for_dynamic_submission
     */
    public function test_check_access_requires_managebookedusers(): void {
        $this->setAdminUser();
        [$settings, $answerids, , , $course] = $this->create_booked_option_with_session();

        $courseteacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($courseteacher->id, $course->id, 'teacher');
        $this->setUser($courseteacher);

        $this->expectException(moodle_exception::class);
        $this->submit_modal([
            'cmid' => (int)$settings->cmid,
            'optionid' => (int)$settings->id,
            'scope' => 'option',
            'checkedids' => implode(',', $answerids),
            'status' => 1,
        ]);
    }

    /**
     * Helper: submit the dynamic form with mocked ajax data.
     *
     * @param array $ajaxargs
     * @return void
     */
    private function submit_modal(array $ajaxargs): void {
        $submitdata = modal_change_status::mock_ajax_submit($ajaxargs);
        $form = new modal_change_status(null, null, 'post', '', [], true, $submitdata, true);
        $this->assertTrue($form->is_validated());
        $form->process_dynamic_submission();
    }

    /**
     * Helper: booking option with one session (optiondate) and two booked students.
     *
     * @return array{0: \mod_booking\booking_option_settings, 1: int[], 2: int, 3: stdClass[], 4: stdClass}
     */
    private function create_booked_option_with_session(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $bookingmanager = $this->getDataGenerator()->create_user();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'name' => 'Presence modal test booking',
            'eventtype' => 'Test event',
            'bookedtext' => ['text' => 'text'],
            'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'],
            'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'],
            'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'],
            'notificationtext' => ['text' => 'text'],
            'userleave' => ['text' => 'text'],
            'course' => $course->id,
            'bookingmanager' => $bookingmanager->username,
        ]);

        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student1->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course->id, 'student');

        $record = new stdClass();
        $record->importing = 1;
        $record->bookingid = $booking->id;
        $record->text = 'Option for presence modal';
        $record->useprice = 0;
        $record->maxanswers = 5;
        $record->optiondateid_1 = "0";
        $record->daystonotify_1 = "0";
        $record->coursestarttime_1 = strtotime('20 May 2050 15:00');
        $record->courseendtime_1 = strtotime('20 May 2050 16:00');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        booking_bookit::bookit('option', $settings->id, $student1->id);
        booking_bookit::bookit('option', $settings->id, $student1->id);
        booking_bookit::bookit('option', $settings->id, $student2->id);
        booking_bookit::bookit('option', $settings->id, $student2->id);

        $answerids = array_keys($DB->get_records('booking_answers', ['optionid' => $option->id], '', 'id'));
        $this->assertCount(2, $answerids, 'Precondition: both students must be booked.');

        $optiondateid = (int)$DB->get_field('booking_optiondates', 'id', ['optionid' => $option->id], MUST_EXIST);

        return [$settings, $answerids, $optiondateid, [$student1, $student2], $course];
    }
}
