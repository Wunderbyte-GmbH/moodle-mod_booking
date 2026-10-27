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
 * Tests for the sync of course enrolments to automatically created booking options.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use context_course;
use core\task\manager;
use mod_booking\local\autocreateoption;
use mod_booking\local\sync\booking_enrolment;
use mod_booking\local\sync\enrolmentsync;
use mod_booking\task\enrolmentsync_instance_adhoc;
use mod_booking\task\sync_course_enrolments_adhoc;
use mod_booking\utils\wb_payment;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once($CFG->dirroot . '/cohort/lib.php');

/**
 * Tests for the sync of course enrolments to automatically created booking options.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\sync\enrolmentsync
 * @covers \mod_booking\task\sync_course_enrolments_adhoc
 * @covers \mod_booking\task\enrolmentsync_instance_adhoc
 */
final class enrolment_sync_course_test extends advanced_testcase {
    /**
     * Tests set up.
     *
     * The observers are not internal, so they are only delivered once the surrounding DB transaction has been
     * committed. PHPUnit wraps every test in a transaction, so the tests must reset by truncation instead.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->preventResetByRollback();
        $this->setAdminUser();
        set_config('enrol_plugins_enabled', 'manual,guest,self,cohort,meta');
        wb_payment::override_pro_version_for_tests(true);
        singleton_service::destroy_instance();
        autocreateoption::purge_map();
        enrolmentsync::reset_request_state();
    }

    /**
     * Tests tear down.
     */
    public function tearDown(): void {
        wb_payment::override_pro_version_for_tests(null);
        enrolmentsync::reset_request_state();
        singleton_service::destroy_instance();
        parent::tearDown();
    }

    /**
     * Creates a booking instance linked to a new category, with the enrolment sync switched on.
     *
     * @param array $extra additional instance settings
     * @return array [booking instance, linked category]
     */
    private function create_synced_instance(array $extra = []): array {
        $category = $this->getDataGenerator()->create_category();
        $instancecategory = $this->getDataGenerator()->create_category();
        $instancecourse = $this->getDataGenerator()->create_course(['category' => $instancecategory->id]);
        $record = array_merge([
            'course' => $instancecourse->id,
            'autocreateoptioncategoryid' => $category->id,
            'autocreateoptionsyncenrolments' => 1,
            'autoenrol' => 1,
            'sendmail' => 1,
        ], $extra);
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        return [$plugingenerator->create_instance($record), $category];
    }

    /**
     * Creates a course in the linked category and runs the option creation.
     *
     * @param stdClass $booking
     * @param \core_course_category $category
     * @return array [course, option record]
     */
    private function create_course_with_option(stdClass $booking, \core_course_category $category): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['category' => $category->id]);
        $this->run_tasks_silently();
        $option = $DB->get_record('booking_options', ['bookingid' => $booking->id, 'courseid' => $course->id], '*', MUST_EXIST);
        return [$course, $option];
    }

    /**
     * Runs all queued adhoc tasks, also those that wait for their delay, swallowing their output.
     *
     * @return void
     */
    private function run_tasks_silently(): void {
        ob_start();
        try {
            while ($task = manager::get_next_adhoc_task(time() + HOURSECS)) {
                $task->execute();
                manager::adhoc_task_complete($task);
            }
        } finally {
            ob_end_clean();
        }
        singleton_service::destroy_instance();
    }

    /**
     * The course sync rule of an option.
     *
     * @param int $optionid
     * @return stdClass|false
     */
    private function get_rule(int $optionid) {
        global $DB;
        return $DB->get_record('booking_sync_rules', [
            'bookingoptionid' => $optionid,
            'sourcetype' => enrolmentsync::SOURCETYPE,
        ]);
    }

    /**
     * The status of the answer of a user in an option, null if the user has none.
     *
     * @param int $optionid
     * @param int $userid
     * @return stdClass|null
     */
    private function get_answer(int $optionid, int $userid): ?stdClass {
        global $DB;
        $answers = $DB->get_records(
            'booking_answers',
            ['optionid' => $optionid, 'userid' => $userid],
            'id DESC',
            '*',
            0,
            1
        );
        return empty($answers) ? null : reset($answers);
    }

    /**
     * Number of enrolments (of any method) of a user in a course.
     *
     * @param int $courseid
     * @param int $userid
     * @return int
     */
    private function count_enrolments(int $courseid, int $userid): int {
        global $DB;
        $sql = "SELECT COUNT(1)
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE e.courseid = :courseid AND ue.userid = :userid";
        return (int)$DB->count_records_sql($sql, ['courseid' => $courseid, 'userid' => $userid]);
    }

    /**
     * Adds a cohort sync enrol instance for a new cohort to the course.
     *
     * @param stdClass $course
     * @return stdClass the cohort
     */
    private function add_cohort_sync(stdClass $course): stdClass {
        global $DB;
        $cohort = $this->getDataGenerator()->create_cohort();
        $studentrole = $DB->get_field('role', 'id', ['shortname' => 'student']);
        enrol_get_plugin('cohort')->add_instance($course, ['customint1' => $cohort->id, 'roleid' => $studentrole]);
        return $cohort;
    }

    /**
     * Users of every enrolment method are booked into the option, once, owned by the course rule, without a
     * second (manual) enrolment by the autoenrol of the instance.
     *
     * @return void
     */
    public function test_users_of_all_enrolment_methods_are_booked(): void {
        global $DB;

        [$booking, $category] = $this->create_synced_instance();
        [$course, $option] = $this->create_course_with_option($booking, $category);
        $rule = $this->get_rule((int)$option->id);
        $this->assertNotEmpty($rule);
        $this->assertSame(1, (int)$rule->syncenrol);
        $this->assertSame(1, (int)$rule->syncunenrol);
        $this->assertSame(booking_enrolment::CONDITION_POLICY_OVERRIDE, (int)$rule->conditionpolicy);

        // Manual.
        $manualuser = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($manualuser->id, $course->id, 'student');

        // Self enrolment.
        $selfuser = $this->getDataGenerator()->create_user();
        $selfplugin = enrol_get_plugin('self');
        $selfinstance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'self'], '*', MUST_EXIST);
        $selfinstance->status = ENROL_INSTANCE_ENABLED;
        $DB->update_record('enrol', $selfinstance);
        $selfplugin->enrol_user($selfinstance, $selfuser->id, $selfinstance->roleid);

        // Cohort sync.
        $cohortuser = $this->getDataGenerator()->create_user();
        $cohort = $this->add_cohort_sync($course);
        cohort_add_member($cohort->id, $cohortuser->id);

        // Meta link.
        $metauser = $this->getDataGenerator()->create_user();
        $parentcourse = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($metauser->id, $parentcourse->id, 'student');
        enrol_get_plugin('meta')->add_instance($course, ['customint1' => $parentcourse->id]);

        // All enrolments of the course were collapsed into one task.
        $this->assertCount(1, manager::get_adhoc_tasks(sync_course_enrolments_adhoc::class));
        $this->run_tasks_silently();

        foreach ([$manualuser, $selfuser, $cohortuser, $metauser] as $user) {
            $answer = $this->get_answer((int)$option->id, (int)$user->id);
            $this->assertNotNull($answer, "User {$user->username} was not booked.");
            $this->assertSame(MOD_BOOKING_STATUSPARAM_BOOKED, (int)$answer->waitinglist);
            $this->assertSame((int)$rule->id, (int)$answer->syncruleid);
            // Autoenrol of the instance did not add a manual enrolment on top.
            $this->assertSame(1, $this->count_enrolments((int)$course->id, (int)$user->id));
        }
        $this->assertSame(4, $DB->count_records('booking_answers', ['optionid' => $option->id]));
        $this->assertSame(4, $DB->count_records('booking_sync_attempts', [
            'syncruleid' => $rule->id,
            'reasoncode' => booking_enrolment::REASON_OK,
        ]));

        // A second run changes nothing.
        enrolmentsync::sync_course((int)$course->id);
        $this->assertSame(4, $DB->count_records('booking_answers', ['optionid' => $option->id]));
    }

    /**
     * Users enrolled before the option existed (e.g. restored or copied with users) are booked by the backfill.
     *
     * @return void
     */
    public function test_existing_enrolments_are_backfilled(): void {
        [$booking, $category] = $this->create_synced_instance();
        $course = $this->getDataGenerator()->create_course(['category' => $category->id]);
        $user = $this->getDataGenerator()->create_user();
        // Enrolled while only the option creation task is queued, no rule exists yet.
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->assertCount(0, manager::get_adhoc_tasks(sync_course_enrolments_adhoc::class));

        $this->run_tasks_silently();

        global $DB;
        $option = $DB->get_record('booking_options', ['bookingid' => $booking->id, 'courseid' => $course->id]);
        $answer = $this->get_answer((int)$option->id, (int)$user->id);
        $this->assertNotNull($answer);
        $this->assertSame(MOD_BOOKING_STATUSPARAM_BOOKED, (int)$answer->waitinglist);
    }

    /**
     * Course editors and teachers of the option are not booked, a full option does not stop the sync, and no
     * confirmation mail is sent while the booking events are triggered.
     *
     * @return void
     */
    public function test_forced_booking_without_mail_and_without_teachers(): void {
        global $DB;

        [$booking, $category] = $this->create_synced_instance();
        [$course, $option] = $this->create_course_with_option($booking, $category);

        // The option is full.
        $DB->update_record('booking_options', (object)['id' => $option->id, 'limitanswers' => 1, 'maxanswers' => 1]);
        booking_option::purge_cache_for_answers((int)$option->id);
        \cache_helper::purge_by_event('setbackoptionsettings');
        singleton_service::destroy_instance();

        $editor = $this->getDataGenerator()->create_user();
        $optionteacher = $this->getDataGenerator()->create_user();
        $students = [];
        for ($i = 0; $i < 3; $i++) {
            $students[] = $this->getDataGenerator()->create_user();
        }
        $this->getDataGenerator()->enrol_user($editor->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($optionteacher->id, $course->id, 'student');
        $DB->insert_record('booking_teachers', (object)[
            'bookingid' => $booking->id,
            'optionid' => $option->id,
            'userid' => $optionteacher->id,
        ]);
        foreach ($students as $student) {
            $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        }

        $messagesink = $this->redirectMessages();
        $eventsink = $this->redirectEvents();
        $result = enrolmentsync::sync_course((int)$course->id);
        $events = $eventsink->get_events();
        $eventsink->close();

        $this->assertSame(3, $result['enrolled']);
        foreach ($students as $student) {
            $answer = $this->get_answer((int)$option->id, (int)$student->id);
            $this->assertSame(MOD_BOOKING_STATUSPARAM_BOOKED, (int)$answer->waitinglist);
        }
        $this->assertNull($this->get_answer((int)$option->id, (int)$editor->id));
        $this->assertNull($this->get_answer((int)$option->id, (int)$optionteacher->id));

        $booked = array_filter($events, fn($e) => $e instanceof \mod_booking\event\bookingoption_booked);
        $this->assertCount(3, $booked);

        // Queued mails would be sent by adhoc tasks.
        $this->run_tasks_silently();
        $this->assertSame(0, $messagesink->count());
        $messagesink->close();
    }

    /**
     * A cancellation newer than the enrolment wins, a re-enrolment newer than the cancellation wins.
     *
     * @return void
     */
    public function test_cancellation_and_reenrolment(): void {
        [$booking, $category] = $this->create_synced_instance(['autoenrol' => 0]);
        [$course, $option] = $this->create_course_with_option($booking, $category);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->run_tasks_silently();
        $this->assertSame(MOD_BOOKING_STATUSPARAM_BOOKED, (int)$this->get_answer((int)$option->id, (int)$user->id)->waitinglist);

        // The user cancels and stays enrolled: not booked again.
        $bo = singleton_service::get_instance_of_booking_option((int)$booking->cmid, (int)$option->id);
        $bo->user_delete_response((int)$user->id);
        enrolmentsync::sync_course((int)$course->id);
        $this->assertSame(MOD_BOOKING_STATUSPARAM_DELETED, (int)$this->get_answer((int)$option->id, (int)$user->id)->waitinglist);

        // Unenrolled and enrolled again later: booked again.
        global $DB;
        $answer = $this->get_answer((int)$option->id, (int)$user->id);
        $DB->set_field('booking_answers', 'timemodified', time() - 100, ['id' => $answer->id]);
        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        enrol_get_plugin('manual')->unenrol_user($instance, $user->id);
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->run_tasks_silently();
        $this->assertSame(MOD_BOOKING_STATUSPARAM_BOOKED, (int)$this->get_answer((int)$option->id, (int)$user->id)->waitinglist);
    }

    /**
     * Unenrolment removes the synced booking only when no enrolment is left, never self-made bookings.
     *
     * @return void
     */
    public function test_unenrolment(): void {
        global $DB;

        [$booking, $category] = $this->create_synced_instance();
        [$course, $option] = $this->create_course_with_option($booking, $category);
        $cohort = $this->add_cohort_sync($course);

        $cohortonly = $this->getDataGenerator()->create_user();
        $cohortandmanual = $this->getDataGenerator()->create_user();
        $suspended = $this->getDataGenerator()->create_user();
        $selfbooked = $this->getDataGenerator()->create_user();

        // The self-booked user books before being enrolled, the answer is not owned by the rule.
        $bo = singleton_service::get_instance_of_booking_option((int)$booking->cmid, (int)$option->id);
        $bo->user_submit_response($selfbooked, 0, 0, MOD_BOOKING_BO_SUBMIT_STATUS_BOOKOTHEROPTION_FORCE, MOD_BOOKING_VERIFIED);
        $this->assertSame(0, (int)$this->get_answer((int)$option->id, (int)$selfbooked->id)->syncruleid);

        foreach ([$cohortonly, $cohortandmanual, $suspended, $selfbooked] as $user) {
            cohort_add_member($cohort->id, $user->id);
        }
        $this->getDataGenerator()->enrol_user($cohortandmanual->id, $course->id, 'student');
        $this->run_tasks_silently();
        foreach ([$cohortonly, $cohortandmanual, $suspended] as $user) {
            $this->assertSame(
                (int)$this->get_rule((int)$option->id)->id,
                (int)$this->get_answer((int)$option->id, (int)$user->id)->syncruleid
            );
        }

        $cohortinstance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'cohort'], '*', MUST_EXIST);
        enrol_get_plugin('cohort')->update_user_enrol($cohortinstance, $suspended->id, ENROL_USER_SUSPENDED);
        foreach ([$cohortonly, $cohortandmanual, $selfbooked] as $user) {
            cohort_remove_member($cohort->id, $user->id);
        }

        $messagesink = $this->redirectMessages();
        $this->run_tasks_silently();

        $this->assertSame(0, $this->count_enrolments((int)$course->id, (int)$cohortonly->id));
        $this->assertSame(
            MOD_BOOKING_STATUSPARAM_DELETED,
            (int)$this->get_answer((int)$option->id, (int)$cohortonly->id)->waitinglist
        );
        $this->assertSame(
            MOD_BOOKING_STATUSPARAM_BOOKED,
            (int)$this->get_answer((int)$option->id, (int)$cohortandmanual->id)->waitinglist
        );
        $this->assertSame(
            MOD_BOOKING_STATUSPARAM_BOOKED,
            (int)$this->get_answer((int)$option->id, (int)$suspended->id)->waitinglist
        );
        $this->assertSame(
            MOD_BOOKING_STATUSPARAM_BOOKED,
            (int)$this->get_answer((int)$option->id, (int)$selfbooked->id)->waitinglist
        );
        $this->assertSame(0, $messagesink->count());
        $messagesink->close();
    }

    /**
     * With autoenrol, a later enrolment run of booking adds no manual enrolment, so removing the user from the
     * cohort removes the course enrolment and the booking, and the sync comes to rest.
     *
     * @return void
     */
    public function test_autoenrol_does_not_loop(): void {
        [$booking, $category] = $this->create_synced_instance(['autoenrol' => 1]);
        [$course, $option] = $this->create_course_with_option($booking, $category);
        $cohort = $this->add_cohort_sync($course);
        $user = $this->getDataGenerator()->create_user();
        cohort_add_member($cohort->id, $user->id);
        $this->run_tasks_silently();
        $this->assertSame(1, $this->count_enrolments((int)$course->id, (int)$user->id));

        // What the scheduled task enrol_bookedusers_tocourse does at the course start.
        $bo = singleton_service::get_instance_of_booking_option((int)$booking->cmid, (int)$option->id);
        $bo->enrol_user((int)$user->id);
        $this->assertSame(1, $this->count_enrolments((int)$course->id, (int)$user->id));
        $this->assertCount(0, manager::get_adhoc_tasks(sync_course_enrolments_adhoc::class));

        cohort_remove_member($cohort->id, $user->id);
        $this->run_tasks_silently();
        $this->assertSame(0, $this->count_enrolments((int)$course->id, (int)$user->id));
        $this->assertSame(MOD_BOOKING_STATUSPARAM_DELETED, (int)$this->get_answer((int)$option->id, (int)$user->id)->waitinglist);
        $this->assertCount(0, manager::get_adhoc_tasks(sync_course_enrolments_adhoc::class));

        // A booking by the user (not synced) still enrols manually, and does not queue a sync.
        $other = $this->getDataGenerator()->create_user();
        $bo->user_submit_response($other, 0, 0, MOD_BOOKING_BO_SUBMIT_STATUS_BOOKOTHEROPTION_FORCE, MOD_BOOKING_VERIFIED);
        $this->assertSame(1, $this->count_enrolments((int)$course->id, (int)$other->id));
        $this->assertCount(0, manager::get_adhoc_tasks(sync_course_enrolments_adhoc::class));
    }

    /**
     * The observer costs no DB read for courses without a rule, and a burst of enrolments queues one task.
     *
     * @return void
     */
    public function test_observer_is_cheap(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        enrolmentsync::get_synced_courses();
        $reads = $DB->perf_get_reads();
        enrolmentsync::handle_enrolment_event((int)$course->id);
        $this->assertSame($reads, $DB->perf_get_reads());

        [$booking, $category] = $this->create_synced_instance(['autoenrol' => 0]);
        [$syncedcourse, $option] = $this->create_course_with_option($booking, $category);
        $cohort = $this->add_cohort_sync($syncedcourse);
        for ($i = 0; $i < 50; $i++) {
            cohort_add_member($cohort->id, $this->getDataGenerator()->create_user()->id);
        }
        $this->assertCount(1, manager::get_adhoc_tasks(sync_course_enrolments_adhoc::class));
        $this->run_tasks_silently();
        $this->assertSame(50, $DB->count_records('booking_answers', ['optionid' => $option->id]));
    }

    /**
     * Switching the instance setting on creates the rules of existing options and books their users, switching it
     * off disables the rules. Deleting the option deletes its rule.
     *
     * @return void
     */
    public function test_instance_toggle_and_option_deletion(): void {
        global $DB;

        [$booking, $category] = $this->create_synced_instance(['autocreateoptionsyncenrolments' => 0]);
        [$course, $option] = $this->create_course_with_option($booking, $category);
        $this->assertFalse($this->get_rule((int)$option->id));
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->assertCount(0, manager::get_adhoc_tasks(sync_course_enrolments_adhoc::class));

        // Switched on.
        $this->set_sync_setting($booking, true);
        $this->run_tasks_silently();
        $this->assertSame(1, (int)$this->get_rule((int)$option->id)->isenabled);
        $this->assertSame(MOD_BOOKING_STATUSPARAM_BOOKED, (int)$this->get_answer((int)$option->id, (int)$user->id)->waitinglist);

        // Switched off: the rule is disabled, the booking stays.
        $this->set_sync_setting($booking, false);
        $this->run_tasks_silently();
        $this->assertSame(0, (int)$this->get_rule((int)$option->id)->isenabled);
        $this->assertSame([], enrolmentsync::get_synced_courses());
        $this->assertSame(MOD_BOOKING_STATUSPARAM_BOOKED, (int)$this->get_answer((int)$option->id, (int)$user->id)->waitinglist);

        // Deleting the option deletes the rule.
        $bo = singleton_service::get_instance_of_booking_option((int)$booking->cmid, (int)$option->id);
        $bo->delete_booking_option();
        $this->assertFalse($this->get_rule((int)$option->id));
    }

    /**
     * A group sync rule must not close a cycle: synced option -> group of the course -> option with a group rule
     * that is connected to the same course -> manual enrolment -> synced option.
     *
     * Once the user leaves the course by the method that caused the booking, nothing may keep the user in it.
     *
     * @return void
     */
    public function test_group_sync_rule_does_not_keep_synced_booking_alive(): void {
        global $DB;

        [$booking, $category] = $this->create_synced_instance(['autoenrol' => 1, 'addtogroup' => 1]);
        [$course, $option] = $this->create_course_with_option($booking, $category);

        // The synced option adds its users to a group of the course and enrols right away.
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $DB->update_record('booking_options', (object)[
            'id' => $option->id,
            'groupid' => $group->id,
            'enrolmentstatus' => 2,
        ]);
        \cache_helper::purge_by_event('setbackoptionsettings');
        singleton_service::destroy_instance();

        // A second option, connected to the same course, books the members of that group.
        $othercourse = $this->getDataGenerator()->create_course();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $otherbooking = $plugingenerator->create_instance(['course' => $othercourse->id, 'autoenrol' => 1]);
        $groupoption = $plugingenerator->create_option([
            'bookingid' => $otherbooking->id,
            'text' => 'Group synced option',
            'chooseorcreatecourse' => 1,
            'courseid' => $course->id,
            'enrolmentstatus' => 2,
        ]);
        booking_enrolment::save_single_rule((int)$groupoption->id, (object)[
            'sourcetype' => 'group',
            'sourceid' => $group->id,
            'syncenrolaction' => 1,
            'syncunenrolaction' => 1,
            'syncconditionpolicy' => booking_enrolment::CONDITION_POLICY_OVERRIDE,
        ]);
        singleton_service::destroy_instance();

        // The user enrols himself.
        $user = $this->getDataGenerator()->create_user();
        $selfinstance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'self'], '*', MUST_EXIST);
        $selfinstance->status = ENROL_INSTANCE_ENABLED;
        $DB->update_record('enrol', $selfinstance);
        $selfplugin = enrol_get_plugin('self');
        $selfplugin->enrol_user($selfinstance, $user->id, $selfinstance->roleid);
        $this->run_tasks_silently();

        // The chain ran: synced booking, group membership, booking of the group rule.
        $this->assertSame(MOD_BOOKING_STATUSPARAM_BOOKED, (int)$this->get_answer((int)$option->id, (int)$user->id)->waitinglist);
        $this->assertTrue(groups_is_member($group->id, $user->id));
        $this->assertSame(
            MOD_BOOKING_STATUSPARAM_BOOKED,
            (int)$this->get_answer((int)$groupoption->id, (int)$user->id)->waitinglist
        );

        // The user leaves the course.
        $selfplugin->unenrol_user($selfinstance, $user->id);
        $this->run_tasks_silently();
        // The nightly reconcile changes nothing either.
        enrolmentsync::sync_course((int)$course->id);
        $this->run_tasks_silently();

        $this->assertSame(
            0,
            $this->count_enrolments((int)$course->id, (int)$user->id),
            'A booking caused by the course sync keeps the user enrolled in the source course.'
        );
        $this->assertSame(
            MOD_BOOKING_STATUSPARAM_DELETED,
            (int)$this->get_answer((int)$option->id, (int)$user->id)->waitinglist,
            'The synced booking is kept alive by an enrolment it caused itself.'
        );
    }

    /**
     * Switching the sync off and on again must not turn synced bookings into permanent ones.
     *
     * While the rule is disabled, the enrolment run of booking (scheduled task at course start, every save of the
     * option) adds a manual enrolment on top of the real one. After switching the sync on again, removing the real
     * enrolment must still remove the user from the course and the booking.
     *
     * @return void
     */
    public function test_sync_toggle_leaves_no_manual_enrolment_behind(): void {
        [$booking, $category] = $this->create_synced_instance(['autoenrol' => 1]);
        [$course, $option] = $this->create_course_with_option($booking, $category);
        $cohort = $this->add_cohort_sync($course);
        $user = $this->getDataGenerator()->create_user();
        cohort_add_member($cohort->id, $user->id);
        $this->run_tasks_silently();
        $this->assertSame(MOD_BOOKING_STATUSPARAM_BOOKED, (int)$this->get_answer((int)$option->id, (int)$user->id)->waitinglist);
        $this->assertSame(1, $this->count_enrolments((int)$course->id, (int)$user->id));

        // Switched off.
        $this->set_sync_setting($booking, false);
        $this->run_tasks_silently();
        $this->assertSame(0, (int)$this->get_rule((int)$option->id)->isenabled);

        // What enrol_bookedusers_tocourse and every save of the option do for all booked users.
        $bo = singleton_service::get_instance_of_booking_option((int)$booking->cmid, (int)$option->id);
        $bo->enrol_user((int)$user->id);

        // Switched on again.
        $this->set_sync_setting($booking, true);
        $this->run_tasks_silently();
        $this->assertSame(1, (int)$this->get_rule((int)$option->id)->isenabled);

        // The user leaves the cohort, the only enrolment that is not booking's own.
        cohort_remove_member($cohort->id, $user->id);
        $this->run_tasks_silently();
        enrolmentsync::sync_course((int)$course->id);
        $this->run_tasks_silently();

        $this->assertSame(
            0,
            $this->count_enrolments((int)$course->id, (int)$user->id),
            'The manual enrolment added while the sync was off keeps the user in the course.'
        );
        $this->assertSame(
            MOD_BOOKING_STATUSPARAM_DELETED,
            (int)$this->get_answer((int)$option->id, (int)$user->id)->waitinglist,
            'The synced booking can no longer be removed by unenrolment.'
        );
    }

    /**
     * A booking the user made in another option connected to the course still enrols the user, so it keeps the user
     * in the course (and booked) when the synced booking is removed.
     *
     * @return void
     */
    public function test_own_booking_of_other_option_keeps_its_enrolment(): void {
        global $DB;

        [$booking, $category] = $this->create_synced_instance(['autoenrol' => 1]);
        [$course, $option] = $this->create_course_with_option($booking, $category);
        $cohort = $this->add_cohort_sync($course);
        $user = $this->getDataGenerator()->create_user();
        cohort_add_member($cohort->id, $user->id);
        $this->run_tasks_silently();
        $this->assertSame(MOD_BOOKING_STATUSPARAM_BOOKED, (int)$this->get_answer((int)$option->id, (int)$user->id)->waitinglist);

        // The user books another option connected to the same course.
        $othercourse = $this->getDataGenerator()->create_course();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $otherbooking = $plugingenerator->create_instance(['course' => $othercourse->id, 'autoenrol' => 1]);
        $otheroption = $plugingenerator->create_option([
            'bookingid' => $otherbooking->id,
            'text' => 'Other option',
            'chooseorcreatecourse' => 1,
            'courseid' => $course->id,
            'enrolmentstatus' => 2,
        ]);
        singleton_service::destroy_instance();
        $bo = singleton_service::get_instance_of_booking_option((int)$otherbooking->cmid, (int)$otheroption->id);
        $bo->user_submit_response($user, 0, 0, MOD_BOOKING_BO_SUBMIT_STATUS_BOOKOTHEROPTION_FORCE, MOD_BOOKING_VERIFIED);
        $this->assertSame(2, $this->count_enrolments((int)$course->id, (int)$user->id));

        // The user leaves the cohort: the own booking keeps its enrolment.
        cohort_remove_member($cohort->id, $user->id);
        $this->run_tasks_silently();

        $this->assertSame(
            MOD_BOOKING_STATUSPARAM_BOOKED,
            (int)$this->get_answer((int)$otheroption->id, (int)$user->id)->waitinglist
        );
        $manual = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        $this->assertTrue($DB->record_exists('user_enrolments', ['enrolid' => $manual->id, 'userid' => $user->id]));
    }

    /**
     * Calls a private method of enrolmentsync, as a parallel task would reach it.
     *
     * @param string $method
     * @param array $args
     * @return mixed
     */
    private function call_enrolmentsync(string $method, array $args) {
        $reflection = new \ReflectionMethod(enrolmentsync::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs(null, $args);
    }

    /**
     * Number of sync attempts of a rule and user with the given action and reason.
     *
     * @param int $ruleid
     * @param int $userid
     * @param string $action
     * @param string $reasoncode
     * @return int
     */
    private function count_attempts(int $ruleid, int $userid, string $action, string $reasoncode): int {
        global $DB;
        return $DB->count_records('booking_sync_attempts', [
            'syncruleid' => $ruleid,
            'userid' => $userid,
            'action' => $action,
            'reasoncode' => $reasoncode,
        ]);
    }

    /**
     * Two syncs of the same course work on the same user (the second computed its list before the first booked):
     * the user is booked, logged and written to the history once, and removed once.
     *
     * @return void
     */
    public function test_parallel_syncs_book_and_remove_each_user_once(): void {
        global $DB;

        [$booking, $category] = $this->create_synced_instance(['autoenrol' => 0]);
        [$course, $option] = $this->create_course_with_option($booking, $category);
        $rule = $this->get_rule((int)$option->id);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        // The tasks of the enrolment are not run: both "parallel" syncs below have the user on their list.
        $bo = singleton_service::get_instance_of_booking_option((int)$booking->cmid, (int)$option->id);

        $sink = $this->redirectEvents();
        $this->assertSame(1, $this->call_enrolmentsync('enrol_users', [$rule, $bo, [(int)$user->id]]));
        $historyafterfirst = $DB->count_records('booking_history', ['optionid' => $option->id, 'userid' => $user->id]);
        $this->assertSame(0, $this->call_enrolmentsync('enrol_users', [$rule, $bo, [(int)$user->id]]));
        $events = $sink->get_events();
        $sink->close();

        $this->assertSame(1, $DB->count_records('booking_answers', ['optionid' => $option->id, 'userid' => $user->id]));
        $booked = array_filter($events, fn($e) => $e instanceof \mod_booking\event\bookingoption_booked);
        $this->assertCount(1, $booked);
        $this->assertSame(1, $this->count_attempts((int)$rule->id, (int)$user->id, 'enrol', booking_enrolment::REASON_OK));
        $this->assertSame(1, $this->count_attempts(
            (int)$rule->id,
            (int)$user->id,
            'enrol',
            booking_enrolment::REASON_ALREADY_ENROLLED
        ));
        $this->assertSame(
            $historyafterfirst,
            $DB->count_records('booking_history', ['optionid' => $option->id, 'userid' => $user->id])
        );

        // Both syncs see the user without an enrolment.
        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        enrol_get_plugin('manual')->unenrol_user($instance, $user->id);
        singleton_service::destroy_instance();
        $bo = singleton_service::get_instance_of_booking_option((int)$booking->cmid, (int)$option->id);

        $sink = $this->redirectEvents();
        $this->assertSame(1, $this->call_enrolmentsync('unenrol_users', [$rule, $bo, [(int)$user->id]]));
        $historyafterfirst = $DB->count_records('booking_history', ['optionid' => $option->id, 'userid' => $user->id]);
        $this->assertSame(0, $this->call_enrolmentsync('unenrol_users', [$rule, $bo, [(int)$user->id]]));
        $events = $sink->get_events();
        $sink->close();

        $this->assertSame(MOD_BOOKING_STATUSPARAM_DELETED, (int)$this->get_answer((int)$option->id, (int)$user->id)->waitinglist);
        $cancelled = array_filter($events, fn($e) => $e instanceof \mod_booking\event\bookinganswer_cancelled);
        $this->assertCount(1, $cancelled);
        $this->assertSame(1, $this->count_attempts((int)$rule->id, (int)$user->id, 'unenrol', booking_enrolment::REASON_OK));
        $this->assertSame(1, $this->count_attempts(
            (int)$rule->id,
            (int)$user->id,
            'unenrol',
            booking_enrolment::REASON_ALREADY_REMOVED
        ));
        $this->assertSame(
            $historyafterfirst,
            $DB->count_records('booking_history', ['optionid' => $option->id, 'userid' => $user->id])
        );
    }

    /**
     * A cohort rule removes a booking once: a second removal of the same (already deleted) answer cancels nothing.
     *
     * @return void
     */
    public function test_cohort_rule_removes_each_user_once(): void {
        global $DB;

        [$booking, $category] = $this->create_synced_instance(['autoenrol' => 0, 'autocreateoptionsyncenrolments' => 0]);
        [, $option] = $this->create_course_with_option($booking, $category);
        $cohort = $this->getDataGenerator()->create_cohort();
        $ruleid = booking_enrolment::save_single_rule((int)$option->id, (object)[
            'sourcetype' => 'cohort',
            'sourceid' => $cohort->id,
            'syncenrolaction' => 1,
            'syncunenrolaction' => 1,
            'syncconditionpolicy' => booking_enrolment::CONDITION_POLICY_OVERRIDE,
        ]);
        $rule = $DB->get_record('booking_sync_rules', ['id' => $ruleid], '*', MUST_EXIST);
        $user = $this->getDataGenerator()->create_user();
        booking_enrolment::enrol_user_by_rule($rule, (int)$user->id);
        $this->assertSame(MOD_BOOKING_STATUSPARAM_BOOKED, (int)$this->get_answer((int)$option->id, (int)$user->id)->waitinglist);

        $sink = $this->redirectEvents();
        booking_enrolment::unenrol_user_by_rule($rule, (int)$user->id);
        $historyafterfirst = $DB->count_records('booking_history', ['optionid' => $option->id, 'userid' => $user->id]);
        booking_enrolment::unenrol_user_by_rule($rule, (int)$user->id);
        $events = $sink->get_events();
        $sink->close();

        $cancelled = array_filter($events, fn($e) => $e instanceof \mod_booking\event\bookinganswer_cancelled);
        $this->assertCount(1, $cancelled);
        $this->assertSame(1, $this->count_attempts((int)$rule->id, (int)$user->id, 'unenrol', booking_enrolment::REASON_OK));
        $this->assertSame(1, $this->count_attempts(
            (int)$rule->id,
            (int)$user->id,
            'unenrol',
            booking_enrolment::REASON_ALREADY_REMOVED
        ));
        $this->assertSame(
            $historyafterfirst,
            $DB->count_records('booking_history', ['optionid' => $option->id, 'userid' => $user->id])
        );
    }

    /**
     * Answers that are stale in this process (another process removed the booking) do not make the sync skip the
     * user: it notices that no answer was written and books with fresh answers.
     *
     * @return void
     */
    public function test_booking_with_stale_answers_is_retried(): void {
        global $DB;

        [$booking, $category] = $this->create_synced_instance(['autoenrol' => 0]);
        [, $option] = $this->create_course_with_option($booking, $category);
        $rule = $this->get_rule((int)$option->id);
        $user = $this->getDataGenerator()->create_user();
        $bo = singleton_service::get_instance_of_booking_option((int)$booking->cmid, (int)$option->id);
        $this->assertSame(
            booking_enrolment::REASON_OK,
            booking_enrolment::book_under_lock($bo, $user, MOD_BOOKING_BO_SUBMIT_STATUS_BOOKOTHEROPTION_FORCE, (int)$rule->id)
        );

        // Another process removed the booking, this process still has the answers in its cache.
        $answer = $this->get_answer((int)$option->id, (int)$user->id);
        $DB->set_field('booking_answers', 'waitinglist', MOD_BOOKING_STATUSPARAM_DELETED, ['id' => $answer->id]);

        $this->assertSame(
            booking_enrolment::REASON_OK,
            booking_enrolment::book_under_lock($bo, $user, MOD_BOOKING_BO_SUBMIT_STATUS_BOOKOTHEROPTION_FORCE, (int)$rule->id)
        );
        $this->assertSame(MOD_BOOKING_STATUSPARAM_BOOKED, (int)$this->get_answer((int)$option->id, (int)$user->id)->waitinglist);
    }

    /**
     * The end of a user's enrolment in a course, 0 if none.
     *
     * @param int $courseid
     * @param int $userid
     * @param string $enrol the enrolment method
     * @return int
     */
    private function enrolment_end(int $courseid, int $userid, string $enrol): int {
        global $DB;
        $instance = $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => $enrol], '*', MUST_EXIST);
        return (int)$DB->get_field('user_enrolments', 'timeend', ['enrolid' => $instance->id, 'userid' => $userid], MUST_EXIST);
    }

    /**
     * A self-learning option limits the access of synced users to its duration: the end is set on the enrolment the
     * user has, where the method allows it. No second enrolment is added, an earlier end is kept, and cohort
     * enrolments are left alone.
     *
     * @return void
     */
    public function test_selflearning_duration_limits_synced_enrolment(): void {
        global $DB;

        [$booking, $category] = $this->create_synced_instance(['autoenrol' => 1]);
        [$course, $option] = $this->create_course_with_option($booking, $category);
        $duration = 3 * HOURSECS;
        $DB->update_record('booking_options', (object)[
            'id' => $option->id,
            'type' => 1, // Self-learning course.
            'duration' => $duration,
            'enrolmentstatus' => 2,
        ]);
        \cache_helper::purge_by_event('setbackoptionsettings');
        singleton_service::destroy_instance();
        $this->assertSame(1, (int)singleton_service::get_instance_of_booking_option_settings((int)$option->id)->selflearningcourse);

        $manualuser = $this->getDataGenerator()->create_user();
        $shorteruser = $this->getDataGenerator()->create_user();
        $cohortuser = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($manualuser->id, $course->id, 'student');
        $shorterend = time() + HOURSECS;
        $this->getDataGenerator()->enrol_user($shorteruser->id, $course->id, 'student', 'manual', 0, $shorterend);
        $cohort = $this->add_cohort_sync($course);
        cohort_add_member($cohort->id, $cohortuser->id);
        $this->run_tasks_silently();

        // Manual enrolment without an end: ends after the duration, counted from the booking.
        $answer = $this->get_answer((int)$option->id, (int)$manualuser->id);
        $this->assertSame(MOD_BOOKING_STATUSPARAM_BOOKED, (int)$answer->waitinglist);
        $this->assertSame(
            (int)$answer->timecreated + $duration,
            $this->enrolment_end((int)$course->id, (int)$manualuser->id, 'manual')
        );
        $this->assertSame(1, $this->count_enrolments((int)$course->id, (int)$manualuser->id));

        // An earlier end is kept.
        $this->assertSame($shorterend, $this->enrolment_end((int)$course->id, (int)$shorteruser->id, 'manual'));

        // A cohort enrolment can not be edited by booking.
        $this->assertSame(0, $this->enrolment_end((int)$course->id, (int)$cohortuser->id, 'cohort'));
        $this->assertSame(1, $this->count_enrolments((int)$course->id, (int)$cohortuser->id));

        // A later enrolment run (course start task, option save) changes nothing.
        $bo = singleton_service::get_instance_of_booking_option((int)$booking->cmid, (int)$option->id);
        $bo->enrol_user((int)$manualuser->id);
        $this->assertSame(
            (int)$answer->timecreated + $duration,
            $this->enrolment_end((int)$course->id, (int)$manualuser->id, 'manual')
        );
        $this->assertSame(1, $this->count_enrolments((int)$course->id, (int)$manualuser->id));
        $this->assertCount(0, manager::get_adhoc_tasks(sync_course_enrolments_adhoc::class));
    }

    /**
     * get_enrolled_user_ids() (the member count of the rule activation form) lists active enrolments of active
     * accounts only.
     *
     * @return void
     */
    public function test_get_enrolled_user_ids(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $active = $this->getDataGenerator()->create_user();
        $suspendedaccount = $this->getDataGenerator()->create_user(['suspended' => 1]);
        $suspendedenrolment = $this->getDataGenerator()->create_user();
        $expired = $this->getDataGenerator()->create_user();
        $notenrolled = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($active->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($suspendedaccount->id, $course->id, 'student');
        $this->getDataGenerator()
            ->enrol_user($suspendedenrolment->id, $course->id, 'student', 'manual', 0, 0, ENROL_USER_SUSPENDED);
        $this->getDataGenerator()->enrol_user($expired->id, $course->id, 'student', 'manual', 0, time() - DAYSECS);

        $this->assertSame([(int)$active->id], enrolmentsync::get_enrolled_user_ids((int)$course->id));
        $this->assertSame([], enrolmentsync::get_enrolled_user_ids(0));
        $this->assertSame(
            [(int)$active->id],
            booking_enrolment::get_source_member_ids(enrolmentsync::SOURCETYPE, (int)$course->id)
        );
    }

    /**
     * Switches the enrolment sync of an instance, like booking_update_instance() does when the setting changed.
     *
     * @param stdClass $booking
     * @param bool $on
     * @return void
     */
    private function set_sync_setting(stdClass $booking, bool $on): void {
        global $DB;

        $record = $DB->get_record('booking', ['id' => $booking->id], 'id, json', MUST_EXIST);
        $before = booking_autocreateoption_syncs_enrolments($record->json);
        $json = empty($record->json) ? new stdClass() : json_decode($record->json);
        $json->{autocreateoption::JSONKEY_SYNCENROLMENTS} = $on ? 1 : 0;
        $record->json = json_encode($json);
        $DB->update_record('booking', $record);
        $this->assertNotSame($before, booking_autocreateoption_syncs_enrolments($record->json));
        booking::purge_cache_for_booking_instance_by_cmid((int)$booking->cmid);
        singleton_service::destroy_instance();

        $task = new enrolmentsync_instance_adhoc();
        $task->set_custom_data(['bookingid' => (int)$booking->id]);
        manager::queue_adhoc_task($task, true);
    }
}
