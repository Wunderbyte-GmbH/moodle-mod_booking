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
 * Tests for the transfer users modal (form layer) of the bookings tracker.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\form\modal_transfer_users;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use moodle_exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->dirroot/mod/booking/lib.php");

/**
 * The modal resolves the checked booking_answers ids to user ids and moves
 * them through booking_option::transfer_users_to_otheroption (the backend is
 * covered by transfer_users_test); gated by mod/booking:subscribeusers.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class modal_transfer_users_test extends booking_advanced_testcase {
    /**
     * The checked users are moved to the target option.
     *
     * @covers \mod_booking\form\modal_transfer_users::process_dynamic_submission
     */
    public function test_process_transfers_checked_users(): void {
        global $DB;

        $this->setAdminUser();
        [$source, $target, $answerids, $students] = $this->create_two_booked_options();

        $submitdata = modal_transfer_users::mock_ajax_submit([
            'cmid' => (int)$source->cmid,
            'optionid' => (int)$source->id,
            'checkedids' => implode(',', $answerids),
            'targetoptionid' => (int)$target->id,
        ]);
        $form = new modal_transfer_users(null, null, 'post', '', [], true, $submitdata, true);
        $this->assertTrue($form->is_validated());
        $result = $form->process_dynamic_submission();

        $this->assertEquals(1, $result->success, 'The transfer must report success.');
        foreach ($students as $student) {
            $this->assertTrue(
                $DB->record_exists_select(
                    'booking_answers',
                    'optionid = :optionid AND userid = :userid AND waitinglist < 2',
                    ['optionid' => $target->id, 'userid' => $student->id]
                ),
                "User {$student->id} must have an active answer on the target option."
            );
            $this->assertFalse(
                $DB->record_exists_select(
                    'booking_answers',
                    'optionid = :optionid AND userid = :userid AND waitinglist < 2',
                    ['optionid' => $source->id, 'userid' => $student->id]
                ),
                "User {$student->id} must have no active answer on the source option anymore."
            );
        }
    }

    /**
     * The modal is gated by subscribeusers - non-editing teachers do not
     * carry it by default on new installations and are rejected on
     * construction already.
     *
     * @covers \mod_booking\form\modal_transfer_users::check_access_for_dynamic_submission
     */
    public function test_check_access_requires_subscribeusers(): void {
        $this->setAdminUser();
        [$source, $target, $answerids, , $course] = $this->create_two_booked_options();

        $courseteacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($courseteacher->id, $course->id, 'teacher');
        $this->setUser($courseteacher);

        $submitdata = modal_transfer_users::mock_ajax_submit([
            'cmid' => (int)$source->cmid,
            'optionid' => (int)$source->id,
            'checkedids' => implode(',', $answerids),
            'targetoptionid' => (int)$target->id,
        ]);

        $this->expectException(moodle_exception::class);
        new modal_transfer_users(null, null, 'post', '', [], true, $submitdata, true);
    }

    /**
     * Helper: two options in one instance, both students booked on the source.
     *
     * @return array{0: \mod_booking\booking_option_settings, 1: \mod_booking\booking_option_settings,
     *               2: int[], 3: stdClass[], 4: stdClass}
     */
    private function create_two_booked_options(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $bookingmanager = $this->getDataGenerator()->create_user();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'name' => 'Transfer modal test booking',
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
        $record->text = 'Transfer source option';
        $record->useprice = 0;
        $record->maxanswers = 5;

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $sourceoption = $plugingenerator->create_option($record);

        $record->text = 'Transfer target option';
        $targetoption = $plugingenerator->create_option($record);

        $source = singleton_service::get_instance_of_booking_option_settings($sourceoption->id);
        $target = singleton_service::get_instance_of_booking_option_settings($targetoption->id);

        booking_bookit::bookit('option', $source->id, $student1->id);
        booking_bookit::bookit('option', $source->id, $student1->id);
        booking_bookit::bookit('option', $source->id, $student2->id);
        booking_bookit::bookit('option', $source->id, $student2->id);

        $answerids = array_keys($DB->get_records('booking_answers', ['optionid' => $source->id], '', 'id'));
        $this->assertCount(2, $answerids, 'Precondition: both students must be booked on the source.');

        return [$source, $target, $answerids, [$student1, $student2], $course];
    }
}
