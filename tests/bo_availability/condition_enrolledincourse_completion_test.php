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
 * Tests for the enrolledincourse availability condition with course completion and the user course state cache.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use cache;
use completion_completion;
use mod_booking\bo_availability\bo_info;
use mod_booking\bo_availability\conditions\enrolledincourse;
use mod_booking\local\user_course_state;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use stdClass;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once($CFG->libdir . '/completionlib.php');

/**
 * Course completion as prerequisite, legacy JSON, cache invalidation and no extra DB reads.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\bo_availability\conditions\enrolledincourse
 * @covers \mod_booking\local\user_course_state
 */
final class condition_enrolledincourse_completion_test extends booking_advanced_testcase {
    /** @var stdClass booking course */
    private stdClass $course;

    /** @var stdClass booking instance */
    private stdClass $booking;

    /** @var stdClass student who books */
    private stdClass $student;

    /** @var mod_booking_generator */
    private mod_booking_generator $plugingenerator;

    /**
     * Setup: completion enabled site-wide, one booking instance, one student.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
        set_config('enablecompletion', 1);

        $this->course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->student = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $this->booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $this->course->id,
            'bookingmanager' => $teacher->username,
            'cancancelbook' => 1,
        ]);
        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $this->plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
    }

    /**
     * Creates an option restricted to the given courses.
     *
     * @param array $courseids
     * @param string $operator AND|OR
     * @param bool $requirecompletion
     * @param array $extra additional form values
     * @return stdClass
     */
    private function create_restricted_option(
        array $courseids,
        string $operator = 'AND',
        bool $requirecompletion = true,
        array $extra = []
    ): stdClass {
        $record = new stdClass();
        $record->bookingid = $this->booking->id;
        $record->text = 'Target ' . implode('-', $courseids);
        $record->chooseorcreatecourse = 1;
        $record->courseid = $this->course->id;
        $record->maxanswers = 10;
        $record->bo_cond_enrolledincourse_restrict = 1;
        $record->bo_cond_enrolledincourse_courseids = $courseids;
        $record->bo_cond_enrolledincourse_courseids_operator = $operator;
        $record->bo_cond_enrolledincourse_requirecompletion = $requirecompletion ? 1 : 0;
        foreach ($extra as $key => $value) {
            $record->$key = $value;
        }
        return $this->plugingenerator->create_option($record);
    }

    /**
     * Marks the course completed for the student through the completion API (fires course_completed).
     *
     * @param int $courseid
     * @return void
     */
    private function complete_course(int $courseid): void {
        $completion = new completion_completion(['course' => $courseid, 'userid' => $this->student->id]);
        $completion->mark_complete(time());
    }

    /**
     * Resolves the blocking condition id for the student on the given option.
     *
     * @param int $optionid
     * @return int
     */
    private function blocking_condition(int $optionid): int {
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $boinfo = new bo_info($settings);
        [$id] = $boinfo->is_available($settings->id, $this->student->id, true);
        return (int)$id;
    }

    /**
     * Enrolled but not completed is blocked; completion (via the completion API and its event) unblocks.
     */
    public function test_requires_completion_and_or(): void {
        $coursea = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $courseb = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->getDataGenerator()->enrol_user($this->student->id, $coursea->id, 'student');
        $this->getDataGenerator()->enrol_user($this->student->id, $courseb->id, 'student');
        $and = $this->create_restricted_option([$coursea->id, $courseb->id], 'AND');
        $or = $this->create_restricted_option([$coursea->id, $courseb->id], 'OR');

        $this->setUser($this->student);
        $this->assertSame(MOD_BOOKING_BO_COND_JSON_ENROLLEDINCOURSE, $this->blocking_condition($and->id));
        $this->assertSame(MOD_BOOKING_BO_COND_JSON_ENROLLEDINCOURSE, $this->blocking_condition($or->id));

        // No manual cache purge: the course_completed observer drops the student's entry.
        $this->complete_course($coursea->id);
        $this->assertSame(MOD_BOOKING_BO_COND_JSON_ENROLLEDINCOURSE, $this->blocking_condition($and->id));
        $this->assertSame(MOD_BOOKING_BO_COND_BOOKITBUTTON, $this->blocking_condition($or->id));

        $this->complete_course($courseb->id);
        $this->assertSame(MOD_BOOKING_BO_COND_BOOKITBUTTON, $this->blocking_condition($and->id));
    }

    /**
     * A completion reached earlier still counts after the user was unenrolled.
     */
    public function test_completion_counts_without_enrolment(): void {
        $coursea = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->getDataGenerator()->enrol_user($this->student->id, $coursea->id, 'student');
        $completion = $this->create_restricted_option([$coursea->id], 'AND', true);
        $enrolment = $this->create_restricted_option([$coursea->id], 'AND', false);
        $this->complete_course($coursea->id);

        $this->setUser($this->student);
        $this->assertSame(MOD_BOOKING_BO_COND_BOOKITBUTTON, $this->blocking_condition($completion->id));
        $this->assertSame(MOD_BOOKING_BO_COND_BOOKITBUTTON, $this->blocking_condition($enrolment->id));

        // Unenrol (user_enrolment_deleted observer invalidates): enrolment-only blocks, completion still passes.
        $this->setAdminUser();
        $manual = enrol_get_plugin('manual');
        $instance = $this->get_manual_instance($coursea->id);
        $manual->unenrol_user($instance, $this->student->id);

        $this->setUser($this->student);
        $this->assertSame(MOD_BOOKING_BO_COND_BOOKITBUTTON, $this->blocking_condition($completion->id));
        $this->assertSame(MOD_BOOKING_BO_COND_JSON_ENROLLEDINCOURSE, $this->blocking_condition($enrolment->id));
    }

    /**
     * Conditions saved before the completion option (no "requirecompletion" key) keep enrolment-only semantics,
     * and a suspended enrolment does not count.
     */
    public function test_legacy_json_and_suspended_enrolment(): void {
        global $DB;
        $coursea = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $target = $this->create_restricted_option([$coursea->id], 'AND', false);
        $DB->set_field('booking_options', 'availability', json_encode([[
            'id' => MOD_BOOKING_BO_COND_JSON_ENROLLEDINCOURSE,
            'name' => 'enrolledincourse',
            'class' => enrolledincourse::class,
            'courseids' => [(string)$coursea->id],
            'courseidsoperator' => 'AND',
            'sqlfilter' => '0',
        ]]), ['id' => $target->id]);
        booking_option::purge_cache_for_option($target->id);

        $this->setUser($this->student);
        $this->assertSame(MOD_BOOKING_BO_COND_JSON_ENROLLEDINCOURSE, $this->blocking_condition($target->id));

        // Enrolment created: the observer refreshes the state, no completion needed for the legacy entry.
        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($this->student->id, $coursea->id, 'student');
        $this->setUser($this->student);
        $this->assertSame(MOD_BOOKING_BO_COND_BOOKITBUTTON, $this->blocking_condition($target->id));

        // Suspended enrolment (user_enrolment_updated): not enrolled for the condition.
        $this->setAdminUser();
        $manual = enrol_get_plugin('manual');
        $manual->update_user_enrol($this->get_manual_instance($coursea->id), $this->student->id, ENROL_USER_SUSPENDED);
        $this->setUser($this->student);
        $this->assertSame(MOD_BOOKING_BO_COND_JSON_ENROLLEDINCOURSE, $this->blocking_condition($target->id));

        $entry = json_decode(singleton_service::get_instance_of_booking_option_settings($target->id)->availability)[0];
        $this->assertFalse(enrolledincourse::requires_completion($entry));
        $defaults = new stdClass();
        enrolledincourse::instance()->set_defaults($defaults, $entry);
        $this->assertSame("0", $defaults->bo_cond_enrolledincourse_requirecompletion);
    }

    /**
     * Saving with "require completion" stores the flag and forces the sql filter off.
     */
    public function test_form_save_disables_sqlfilter_with_completion(): void {
        set_config('usesqlfilteravailability', 1, 'booking');
        $coursea = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $target = $this->create_restricted_option([$coursea->id], 'AND', true, [
            'bo_cond_enrolledincourse_sqlfiltercheck' => 1,
        ]);
        $settings = singleton_service::get_instance_of_booking_option_settings($target->id);
        $entry = json_decode($settings->availability)[0];
        $this->assertSame(1, $entry->requirecompletion);
        $this->assertSame("0", $entry->sqlfilter);
        $defaults = new stdClass();
        enrolledincourse::instance()->set_defaults($defaults, $entry);
        $this->assertSame("1", $defaults->bo_cond_enrolledincourse_requirecompletion);

        $plain = $this->create_restricted_option([$coursea->id], 'AND', false, [
            'bo_cond_enrolledincourse_sqlfiltercheck' => 1,
        ]);
        $entry = json_decode(singleton_service::get_instance_of_booking_option_settings($plain->id)->availability)[0];
        $this->assertSame(0, $entry->requirecompletion);
        $this->assertSame("1", $entry->sqlfilter);
    }

    /**
     * With a warm cache the condition costs no DB read, however many options and courses are checked.
     */
    public function test_no_db_reads_with_warm_cache(): void {
        global $DB;
        $courseids = [];
        for ($i = 1; $i <= 3; $i++) {
            $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
            $this->getDataGenerator()->enrol_user($this->student->id, $course->id, 'student');
            $courseids[] = (int)$course->id;
        }
        $options = [];
        for ($i = 1; $i <= 5; $i++) {
            $options[] = $this->create_restricted_option($courseids, $i % 2 ? 'AND' : 'OR', $i > 2);
        }
        $this->complete_course($courseids[0]);
        $this->setUser($this->student);

        // Warm-up: option settings, answers and the course state.
        $conditions = [];
        foreach ($options as $option) {
            $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
            $condition = enrolledincourse::instance();
            $condition->customsettings = json_decode($settings->availability)[0];
            $condition->is_available($settings, $this->student->id);
            $condition->return_sql($this->student->id);
            $conditions[] = [$settings, clone $condition->customsettings];
        }

        $reads = $DB->perf_get_reads();
        $expected = [true, true, false, true, false];
        foreach ($conditions as $i => [$settings, $customsettings]) {
            $condition = enrolledincourse::instance();
            $condition->customsettings = $customsettings;
            $this->assertSame($expected[$i], $condition->is_available($settings, $this->student->id), "option $i");
            $condition->return_sql($this->student->id);
        }
        $this->assertSame(0, $DB->perf_get_reads() - $reads, 'enrolledincourse must not query the DB per check');
    }

    /**
     * Checking another user (booking for others) neither reads nor writes the session cache.
     */
    public function test_session_cache_only_for_session_user(): void {
        $coursea = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $other = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($other->id, $coursea->id, 'student');

        $this->setUser($this->student);
        $cache = cache::make('mod_booking', 'usercoursestate');
        $this->assertTrue(user_course_state::is_enrolled($other->id, $coursea->id));
        $this->assertFalse($cache->get($other->id));

        $this->assertFalse(user_course_state::is_enrolled($this->student->id, $coursea->id));
        $this->assertIsArray($cache->get($this->student->id));

        // A stale session entry is not trusted after the invalidation event.
        $cache->set($this->student->id, ['enrolled' => [$coursea->id => 1], 'completed' => []]);
        user_course_state::invalidate($this->student->id);
        $this->assertFalse(user_course_state::is_enrolled($this->student->id, $coursea->id));
    }

    /**
     * Manual enrolment instance of a course.
     *
     * @param int $courseid
     * @return stdClass
     */
    private function get_manual_instance(int $courseid): stdClass {
        global $DB;
        return $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => 'manual'], '*', MUST_EXIST);
    }
}
