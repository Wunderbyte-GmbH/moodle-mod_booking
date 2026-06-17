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
 * Tests for the course-module visibility check.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use mod_booking\local\checkanswers\checks\cmvisibility;
use stdClass;

/**
 * Tests for \mod_booking\local\checkanswers\checks\cmvisibility.
 *
 * The check evaluates a course module's visibility from the answer user's perspective. It used to
 * do that by temporarily swapping the global $USER, which is bound to the session by reference, so
 * an early return or exception before restoring it persisted the wrong user into the session. These
 * tests pin down that the check now resolves visibility via the userid and never mutates $USER.
 *
 * @package mod_booking
 * @category test
 * @covers \mod_booking\local\checkanswers\checks\cmvisibility::check_answer
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class cmvisibility_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
    }

    /**
     * check_answer() must not mutate the global $USER, neither on success nor when it throws.
     *
     * The failure case is the regression guard: against the pre-fix code the swapped $USER was only
     * restored on the success path, so an exception left the session switched to the answer user.
     */
    public function test_check_answer_never_mutates_global_user(): void {
        global $DB, $USER;

        $this->setAdminUser();

        // Minimal booking instance with one option (the option's cm is what gets checked).
        $course = $this->getDataGenerator()->create_course();
        $bookingmanager = $this->getDataGenerator()->create_user();
        $bdata = [
            'name' => 'Visibility booking',
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
            'tags' => '',
            'course' => $course->id,
            'bookingmanager' => $bookingmanager->username,
        ];
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Opt';
        $record->description = 'Test description';
        $record->chooseorcreatecourse = 0;
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('now + 3 days');
        $record->courseendtime_0 = strtotime('now + 6 days');
        $option = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $cmid = (int) $settings->cmid;

        // The answer belongs to a different user than the current $USER.
        $target = $this->getDataGenerator()->create_user();
        $answer = (object)['optionid' => (int) $option->id, 'userid' => (int) $target->id];

        // Sentinel "current" user, which must differ from the answer user.
        $sentinel = $this->getDataGenerator()->create_user();
        $this->setUser($sentinel);
        $this->assertNotEquals((int) $target->id, (int) $USER->id);
        $beforeuserid = (int) $USER->id;

        // Success path: returns a bool and leaves the global user untouched.
        $result = cmvisibility::check_answer($answer);
        $this->assertIsBool($result);
        $this->assertEquals($beforeuserid, (int) $USER->id, 'check_answer must not mutate $USER on success.');

        // Failure path: prime the cached settings, then remove the module so get_cm() throws while the
        // cached cmid/course stay stale. The pre-fix code would have leaked the swapped $USER here.
        singleton_service::get_instance_of_booking_settings_by_cmid($cmid);
        $DB->delete_records('course_modules', ['id' => $cmid]);
        rebuild_course_cache($course->id, true);

        $beforeuserid = (int) $USER->id;
        $threw = false;
        try {
            cmvisibility::check_answer($answer);
        } catch (\Throwable $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'A stale course module id must make check_answer throw.');
        $this->assertEquals($beforeuserid, (int) $USER->id, 'check_answer must not leak $USER even when it throws.');
        $this->assertNotEquals((int) $target->id, (int) $USER->id, 'The session must not be left as the answer user.');
    }
}
