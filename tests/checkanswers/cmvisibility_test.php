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
        [$course, $option, $cmid] = $this->create_booking_option_cm();

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

    /**
     * check_answer() must judge visibility from the answer user's perspective, not the task user's.
     *
     * The activity stays generally visible (visible = 1) but a future "available from" date hides it
     * from ordinary users, while admins/managers bypass it via
     * moodle/course:ignoreavailabilityrestrictions. So the very same module, checked in the very same
     * admin-run task, must report "no access" for a restricted student and "access" for the admin -
     * proving the verdict follows the answer user and not the session user. Evaluating as the task's
     * admin user (the pre-fix trap) would wrongly grant access to the restricted student.
     */
    public function test_check_answer_resolves_visibility_for_answer_user(): void {
        global $DB;

        set_config('enableavailability', 1);
        $this->setAdminUser();

        [$course, $option, $cmid] = $this->create_booking_option_cm();

        // A normal student, who must not be able to bypass availability restrictions.
        $target = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($target->id, $course->id, 'student');

        // Keep the activity visible but make it unavailable to ordinary users via a future date.
        $restriction = json_encode((object)[
            'op' => '&',
            'c' => [(object)['type' => 'date', 'd' => '>=', 't' => strtotime('+1 year')]],
            'showc' => [false],
        ]);
        $DB->set_field('course_modules', 'availability', $restriction, ['id' => $cmid]);
        rebuild_course_cache($course->id, true);

        // The trap: the module is still visible and the admin running the task can see it, so
        // resolving visibility as the session user would wrongly report access.
        $admin = get_admin();
        $admincm = get_fast_modinfo($course->id, $admin->id)->get_cm($cmid);
        $this->assertEquals(1, (int) $admincm->visible, 'The activity must stay generally visible.');
        $this->assertTrue($admincm->get_user_visible(), 'The admin must still be able to see the activity.');

        // The restricted student, by contrast, cannot see it.
        $targetcm = get_fast_modinfo($course->id, $target->id)->get_cm($cmid);
        $this->assertFalse($targetcm->get_user_visible(), 'The restricted student must not see the activity.');

        // So the check must deny access for the student, even though the task runs as admin...
        $studentanswer = (object)['optionid' => (int) $option->id, 'userid' => (int) $target->id];
        $this->assertFalse(
            cmvisibility::check_answer($studentanswer),
            'check_answer must resolve visibility as the answer user, not the admin running the task.'
        );

        // ...and grant access for a user who can see it, in the same task run.
        $adminanswer = (object)['optionid' => (int) $option->id, 'userid' => (int) $admin->id];
        $this->assertTrue(
            cmvisibility::check_answer($adminanswer),
            'check_answer must grant access when the answer user can see the activity.'
        );
    }

    /**
     * Creates a booking instance with a single option and returns the pieces the checks need.
     *
     * @return array [stdClass $course, stdClass $option, int $cmid] where $cmid is the booking
     *               activity's course-module id - the cm whose visibility the check evaluates.
     */
    private function create_booking_option_cm(): array {
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

        /** @var \mod_booking_generator $plugingenerator */
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

        return [$course, $option, (int) $settings->cmid];
    }
}
