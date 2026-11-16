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
 * Tests for the bulk table actions of the bookings tracker (manageusers_table).
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\table\manageusers_table;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use moodle_exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->dirroot/mod/booking/lib.php");

/**
 * Covers the capability gates and effects of the checked-rows actions:
 * toggling completion (managebookedusers), deleting answers (deleteresponses)
 * and triggering certificates (tool/certificate:manage).
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class manageusers_actions_test extends booking_advanced_testcase {
    /**
     * Toggling flips booking_answers.completed (and sets/clears completeddate);
     * running it twice restores the initial state.
     *
     * @covers \mod_booking\table\manageusers_table::action_toggle_completion_booking_answers
     */
    public function test_toggle_completion_flips_completed(): void {
        global $DB;

        $this->setAdminUser();
        [, $answerids] = $this->create_booked_option();

        $table = new manageusers_table('actionstest');
        $result = $table->action_toggle_completion_booking_answers(-1, json_encode(['checkedids' => $answerids]));
        $this->assertEquals(1, $result['success']);

        foreach ($answerids as $answerid) {
            $answer = $DB->get_record('booking_answers', ['id' => $answerid], '*', MUST_EXIST);
            $this->assertEquals(1, (int)$answer->completed, "Answer $answerid must be completed after the toggle.");
            $this->assertNotEmpty($answer->completeddate, "Answer $answerid must have a completion date.");
        }

        $table->action_toggle_completion_booking_answers(-1, json_encode(['checkedids' => $answerids]));
        foreach ($answerids as $answerid) {
            $this->assertEquals(
                0,
                (int)$DB->get_field('booking_answers', 'completed', ['id' => $answerid]),
                "Answer $answerid must be incomplete again after the second toggle."
            );
        }
    }

    /**
     * The completion toggle is gated by managebookedusers: non-editing
     * teachers (read-only in the tracker) are rejected server-side.
     *
     * @covers \mod_booking\table\manageusers_table::action_toggle_completion_booking_answers
     */
    public function test_toggle_completion_requires_managebookedusers(): void {
        $this->setAdminUser();
        [, $answerids, , $course] = $this->create_booked_option();

        $courseteacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($courseteacher->id, $course->id, 'teacher');
        $this->setUser($courseteacher);

        $table = new manageusers_table('actionstest');
        $this->expectException(moodle_exception::class);
        $table->action_toggle_completion_booking_answers(-1, json_encode(['checkedids' => $answerids]));
    }

    /**
     * Deleting checked answers removes the active bookings of the users.
     *
     * @covers \mod_booking\table\manageusers_table::action_delete_checked_booking_answers
     */
    public function test_delete_checked_removes_active_answers(): void {
        global $DB;

        $this->setAdminUser();
        [$settings, $answerids] = $this->create_booked_option();

        $table = new manageusers_table('actionstest');
        $result = $table->action_delete_checked_booking_answers(-1, json_encode(['checkedids' => $answerids]));
        $this->assertEquals(1, $result['success']);

        // No active answers (booked or waiting list) remain for the option.
        $this->assertEquals(
            0,
            $DB->count_records_select(
                'booking_answers',
                'optionid = :optionid AND waitinglist < 2',
                ['optionid' => $settings->id]
            ),
            'All active answers of the option must be gone after the bulk delete.'
        );
    }

    /**
     * The delete action is gated by deleteresponses server-side - non-editing
     * teachers do not carry it by default on new installations.
     *
     * @covers \mod_booking\table\manageusers_table::action_delete_checked_booking_answers
     */
    public function test_delete_checked_requires_deleteresponses(): void {
        $this->setAdminUser();
        [, $answerids, , $course] = $this->create_booked_option();

        $courseteacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($courseteacher->id, $course->id, 'teacher');
        $this->setUser($courseteacher);

        $table = new manageusers_table('actionstest');
        $this->expectException(moodle_exception::class);
        $table->action_delete_checked_booking_answers(-1, json_encode(['checkedids' => $answerids]));
    }

    /**
     * Unsubscribing a booked user with the bulk action "Unsubscribe" frees the
     * place for the waiting list, exactly like a cancellation by the user: the
     * user who waits moves up.
     *
     * @covers \mod_booking\table\manageusers_table::action_delete_checked_booking_answers
     */
    public function test_delete_checked_moves_up_user_from_waitinglist(): void {
        global $DB;

        $this->setAdminUser();
        [$optionid, $bookeduser, $waitinguser] = $this->create_full_option_with_waitinglist();

        $answerid = $DB->get_field(
            'booking_answers',
            'id',
            ['optionid' => $optionid, 'userid' => $bookeduser->id, 'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED],
            MUST_EXIST
        );

        $table = new manageusers_table('actionstest');
        $result = $table->action_delete_checked_booking_answers(-1, json_encode(['checkedids' => [$answerid]]));
        $this->assertEquals(1, $result['success']);

        $this->assert_user_moved_up($optionid, $bookeduser, $waitinguser);
    }

    /**
     * The trash button of a single row behaves the same way: the place of the
     * unsubscribed user goes to the user on the waiting list.
     *
     * @covers \mod_booking\table\manageusers_table::action_deletebooking
     */
    public function test_deletebooking_moves_up_user_from_waitinglist(): void {
        $this->setAdminUser();
        [$optionid, $bookeduser, $waitinguser] = $this->create_full_option_with_waitinglist();

        $table = new manageusers_table('actionstest');
        $result = $table->action_deletebooking(
            -1,
            json_encode(['userid' => $bookeduser->id, 'optionid' => $optionid])
        );
        $this->assertEquals(1, $result['success']);

        $this->assert_user_moved_up($optionid, $bookeduser, $waitinguser);
    }

    /**
     * Triggering certificates requires tool/certificate:manage server-side
     * (the button gate alone is not enough, the report is readable by more
     * users than may issue certificates); with certificates disabled the
     * action reports a failure without touching anything.
     *
     * @covers \mod_booking\table\manageusers_table::action_trigger_certificate_booking_answers
     */
    public function test_trigger_certificate_gates(): void {
        $this->setAdminUser();
        [, $answerids, $students, $course] = $this->create_booked_option();

        // Certificates disabled: failure result, no exception.
        set_config('certificateon', 0, 'booking');
        $table = new manageusers_table('actionstest');
        $result = $table->action_trigger_certificate_booking_answers(-1, json_encode(['checkedids' => $answerids]));
        $this->assertEquals(0, $result['success']);

        // Certificates enabled, but the user has no tool/certificate:manage.
        set_config('certificateon', 1, 'booking');
        $courseteacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($courseteacher->id, $course->id, 'teacher');
        $this->setUser($courseteacher);

        $this->expectException(moodle_exception::class);
        $table->action_trigger_certificate_booking_answers(-1, json_encode(['checkedids' => $answerids]));
    }

    /**
     * Helper: booking option with two booked students.
     *
     * @return array{0: \mod_booking\booking_option_settings, 1: int[], 2: stdClass[], 3: stdClass}
     */
    private function create_booked_option(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $bookingmanager = $this->getDataGenerator()->create_user();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'name' => 'Table actions test booking',
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
        $record->text = 'Option for table actions';
        $record->useprice = 0;
        $record->maxanswers = 5;

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

        return [$settings, $answerids, [$student1, $student2], $course];
    }

    /**
     * Helper: free option with a single place, one booked user and one user on
     * the waiting list (automatic moving up, no confirmation).
     *
     * @return array{0: int, 1: stdClass, 2: stdClass} option id, booked user, user on the waiting list
     */
    private function create_full_option_with_waitinglist(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Table actions waiting list booking',
        ]);

        $bookeduser = $this->getDataGenerator()->create_user();
        $waitinguser = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($bookeduser->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($waitinguser->id, $course->id, 'student');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option((object)[
            'bookingid' => $booking->id,
            'courseid' => $course->id,
            'text' => 'Single place option',
            'description' => 'Single place option',
            'chooseorcreatecourse' => 0,
            'coursestarttime_0' => strtotime('now + 1 day'),
            'courseendtime_0' => strtotime('now + 2 day'),
            'limitanswers' => 1,
            'maxanswers' => 1,
            'maxoverbooking' => 5,
            'waitforconfirmation' => 0,
        ]);

        $optionobj = singleton_service::get_instance_of_booking_option((int)$booking->cmid, (int)$option->id);
        $optionobj->user_submit_response($bookeduser, 0, 0, 0, MOD_BOOKING_VERIFIED);
        $optionobj->user_submit_response($waitinguser, 0, 0, 0, MOD_BOOKING_VERIFIED);

        $this->assertTrue(
            $DB->record_exists('booking_answers', [
                'optionid' => $option->id,
                'userid' => $waitinguser->id,
                'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            ]),
            'Precondition: the second user must be on the waiting list.'
        );

        return [(int)$option->id, $bookeduser, $waitinguser];
    }

    /**
     * Asserts that the unsubscribed user is deleted and the user who waited is booked.
     *
     * @param int $optionid
     * @param stdClass $unsubscribeduser
     * @param stdClass $waitinguser
     */
    private function assert_user_moved_up(int $optionid, stdClass $unsubscribeduser, stdClass $waitinguser): void {
        global $DB;

        $this->assertFalse(
            $DB->record_exists('booking_answers', [
                'optionid' => $optionid,
                'userid' => $unsubscribeduser->id,
                'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
            ]),
            'The unsubscribed user must not be booked any more.'
        );
        $this->assertTrue(
            $DB->record_exists('booking_answers', [
                'optionid' => $optionid,
                'userid' => $waitinguser->id,
                'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
            ]),
            'The user on the waiting list must move up to the freed place.'
        );
        $this->assertFalse(
            $DB->record_exists('booking_answers', [
                'optionid' => $optionid,
                'userid' => $waitinguser->id,
                'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            ]),
            'The user who moved up must not be on the waiting list any more.'
        );
    }
}
