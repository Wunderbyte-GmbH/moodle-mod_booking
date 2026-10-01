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
 * Tests for the previouslybooked availability condition with several required options.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\bo_availability\bo_info;
use mod_booking\bo_availability\conditions\previouslybooked;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use stdClass;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Several booking options as prerequisite (AND / OR), legacy single-option JSON, no extra DB reads.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\bo_availability\conditions\previouslybooked
 */
final class condition_previouslybooked_multiple_test extends booking_advanced_testcase {
    /** @var stdClass booking course */
    private stdClass $course;

    /** @var stdClass booking instance */
    private stdClass $booking;

    /** @var stdClass student who books */
    private stdClass $student;

    /** @var mod_booking_generator */
    private mod_booking_generator $plugingenerator;

    /**
     * Setup: one course, one booking instance, one student.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();

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
     * Creates a plain option (no availability) in the test instance.
     *
     * @param string $text
     * @param array $extra additional form values
     * @return stdClass
     */
    private function create_option(string $text, array $extra = []): stdClass {
        $record = new stdClass();
        $record->bookingid = $this->booking->id;
        $record->text = $text;
        $record->chooseorcreatecourse = 1;
        $record->courseid = $this->course->id;
        $record->maxanswers = 10;
        foreach ($extra as $key => $value) {
            $record->$key = $value;
        }
        return $this->plugingenerator->create_option($record);
    }

    /**
     * Writes an availability JSON straight into the DB, as a site saved before this feature would hold it.
     *
     * @param int $optionid
     * @param array $condition the previouslybooked condition entry
     * @return void
     */
    private function write_availability_json(int $optionid, array $condition): void {
        global $DB;
        $entry = array_merge([
            'id' => MOD_BOOKING_BO_COND_JSON_PREVIOUSLYBOOKED,
            'name' => 'previouslybooked',
            'class' => previouslybooked::class,
        ], $condition);
        $DB->set_field('booking_options', 'availability', json_encode([$entry]), ['id' => $optionid]);
        booking_option::purge_cache_for_option($optionid);
    }

    /**
     * Books the student into an option and drops the request-level answer caches.
     *
     * @param int $optionid
     * @return void
     */
    private function book_student(int $optionid): void {
        $this->plugingenerator->create_answer(['optionid' => $optionid, 'userid' => $this->student->id]);
        booking_option::purge_cache_for_answers($optionid);
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
     * A condition saved in the old single-option format ("optionid" only) keeps working unchanged.
     */
    public function test_legacy_single_optionid_json_still_works(): void {
        $optiona = $this->create_option('Prerequisite A');
        $target = $this->create_option('Target');
        $this->write_availability_json($target->id, ['optionid' => (int)$optiona->id]);

        $this->setUser($this->student);
        $this->assertSame(MOD_BOOKING_BO_COND_JSON_PREVIOUSLYBOOKED, $this->blocking_condition($target->id));

        $this->book_student($optiona->id);
        $this->assertSame(MOD_BOOKING_BO_COND_BOOKITBUTTON, $this->blocking_condition($target->id));

        // The legacy entry is read as a one-element list with AND, and the form shows it as such.
        $settings = singleton_service::get_instance_of_booking_option_settings($target->id);
        $entry = json_decode($settings->availability)[0];
        $this->assertSame([(int)$optiona->id], previouslybooked::required_optionids($entry));
        $this->assertSame('AND', previouslybooked::operator($entry));
        $defaults = new stdClass();
        previouslybooked::instance()->set_defaults($defaults, $entry);
        $this->assertSame("1", $defaults->bo_cond_previouslybooked_restrict);
        $this->assertSame([(int)$optiona->id], $defaults->bo_cond_previouslybooked_optionid);
        $this->assertSame('AND', $defaults->bo_cond_previouslybooked_optionidsoperator);
    }

    /**
     * Legacy single-option JSON with requirecompletion blocks until the referenced option is completed.
     */
    public function test_legacy_single_optionid_json_with_requirecompletion(): void {
        $optiona = $this->create_option('Prerequisite A');
        $target = $this->create_option('Target');
        $this->write_availability_json($target->id, ['optionid' => (int)$optiona->id, 'requirecompletion' => 1]);

        $this->setUser($this->student);
        $this->book_student($optiona->id);
        $this->assertSame(MOD_BOOKING_BO_COND_JSON_PREVIOUSLYBOOKED, $this->blocking_condition($target->id));

        $this->setAdminUser();
        $settingsa = singleton_service::get_instance_of_booking_option_settings($optiona->id);
        $optionaobj = singleton_service::get_instance_of_booking_option($settingsa->cmid, $settingsa->id);
        $optionaobj->toggle_user_completion($this->student->id);

        $this->setUser($this->student);
        $this->assertSame(MOD_BOOKING_BO_COND_BOOKITBUTTON, $this->blocking_condition($target->id));
    }

    /**
     * Saving through the option form writes the new list format and keeps "optionid" for old readers.
     */
    public function test_form_save_writes_list_and_legacy_key(): void {
        $optiona = $this->create_option('Prerequisite A');
        $optionb = $this->create_option('Prerequisite B');
        $target = $this->create_option('Target', [
            'bo_cond_previouslybooked_restrict' => 1,
            'bo_cond_previouslybooked_optionid' => [(int)$optiona->id, (int)$optionb->id],
            'bo_cond_previouslybooked_optionidsoperator' => 'OR',
        ]);

        $settings = singleton_service::get_instance_of_booking_option_settings($target->id);
        $entries = array_values(array_filter(
            json_decode($settings->availability),
            fn($e) => (int)$e->id === MOD_BOOKING_BO_COND_JSON_PREVIOUSLYBOOKED
        ));
        $this->assertCount(1, $entries);
        $this->assertSame([(int)$optiona->id, (int)$optionb->id], $entries[0]->optionids);
        $this->assertSame('OR', $entries[0]->optionidsoperator);
        $this->assertSame((int)$optiona->id, $entries[0]->optionid);

        // A single id as sent by legacy callers (wizard skill, recurring options) is accepted as well.
        $single = $this->create_option('Target single', [
            'bo_cond_previouslybooked_restrict' => 1,
            'bo_cond_previouslybooked_optionid' => (int)$optionb->id,
        ]);
        $settings = singleton_service::get_instance_of_booking_option_settings($single->id);
        $entry = json_decode($settings->availability)[0];
        $this->assertSame([(int)$optionb->id], $entry->optionids);
        $this->assertSame('AND', $entry->optionidsoperator);
        $this->assertSame((int)$optionb->id, $entry->optionid);
    }

    /**
     * AND: every listed option must be booked; a booking on the waiting list does not count.
     */
    public function test_and_requires_all_options_booked(): void {
        global $DB;
        $optiona = $this->create_option('Prerequisite A');
        $optionb = $this->create_option('Prerequisite B', ['maxanswers' => 1, 'maxoverbooking' => 5]);
        $target = $this->create_option('Target', [
            'bo_cond_previouslybooked_restrict' => 1,
            'bo_cond_previouslybooked_optionid' => [(int)$optiona->id, (int)$optionb->id],
            'bo_cond_previouslybooked_optionidsoperator' => 'AND',
        ]);

        $this->setUser($this->student);
        $this->assertSame(MOD_BOOKING_BO_COND_JSON_PREVIOUSLYBOOKED, $this->blocking_condition($target->id));

        $this->book_student($optiona->id);
        $this->assertSame(MOD_BOOKING_BO_COND_JSON_PREVIOUSLYBOOKED, $this->blocking_condition($target->id));

        // Fill option B with somebody else so the student lands on the waiting list.
        $other = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($other->id, $this->course->id, 'student');
        $this->plugingenerator->create_answer(['optionid' => $optionb->id, 'userid' => $other->id]);
        $this->plugingenerator->create_answer(['optionid' => $optionb->id, 'userid' => $this->student->id]);
        booking_option::purge_cache_for_answers($optionb->id);
        $this->assertSame(1, $DB->count_records('booking_answers', [
            'optionid' => $optionb->id,
            'userid' => $this->student->id,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
        ]));
        $this->assertSame(MOD_BOOKING_BO_COND_JSON_PREVIOUSLYBOOKED, $this->blocking_condition($target->id));

        // Once the other user cancels, the student moves up and the prerequisite is met.
        $settingsb = singleton_service::get_instance_of_booking_option_settings($optionb->id);
        $optionbobj = singleton_service::get_instance_of_booking_option($settingsb->cmid, $settingsb->id);
        $optionbobj->user_delete_response($other->id);
        booking_option::purge_cache_for_answers($optionb->id);
        $this->assertSame(MOD_BOOKING_BO_COND_BOOKITBUTTON, $this->blocking_condition($target->id));
    }

    /**
     * OR: one booked option out of the list is enough; completion applies to the matched option.
     */
    public function test_or_requires_one_option_and_completion(): void {
        $optiona = $this->create_option('Prerequisite A');
        $optionb = $this->create_option('Prerequisite B');
        $target = $this->create_option('Target', [
            'bo_cond_previouslybooked_restrict' => 1,
            'bo_cond_previouslybooked_optionid' => [(int)$optiona->id, (int)$optionb->id],
            'bo_cond_previouslybooked_optionidsoperator' => 'OR',
            'bo_cond_previouslybooked_requirecompletion' => 1,
        ]);

        $this->setUser($this->student);
        $this->assertSame(MOD_BOOKING_BO_COND_JSON_PREVIOUSLYBOOKED, $this->blocking_condition($target->id));

        // Booked but not completed: still blocked.
        $this->book_student($optionb->id);
        $this->assertSame(MOD_BOOKING_BO_COND_JSON_PREVIOUSLYBOOKED, $this->blocking_condition($target->id));

        $this->setAdminUser();
        $settingsb = singleton_service::get_instance_of_booking_option_settings($optionb->id);
        $optionbobj = singleton_service::get_instance_of_booking_option($settingsb->cmid, $settingsb->id);
        $optionbobj->toggle_user_completion($this->student->id);

        $this->setUser($this->student);
        $this->assertSame(MOD_BOOKING_BO_COND_BOOKITBUTTON, $this->blocking_condition($target->id));
    }

    /**
     * A deleted prerequisite fails AND but is ignored by OR when another option is booked.
     */
    public function test_deleted_prerequisite_option(): void {
        global $DB;
        $optiona = $this->create_option('Prerequisite A');
        $optionb = $this->create_option('Prerequisite B');
        $target = $this->create_option('Target', [
            'bo_cond_previouslybooked_restrict' => 1,
            'bo_cond_previouslybooked_optionid' => [(int)$optiona->id, (int)$optionb->id],
            'bo_cond_previouslybooked_optionidsoperator' => 'AND',
        ]);
        $this->setUser($this->student);
        $this->book_student($optiona->id);
        $this->book_student($optionb->id);
        $this->assertSame(MOD_BOOKING_BO_COND_BOOKITBUTTON, $this->blocking_condition($target->id));

        // Option B disappears together with its answers.
        $this->setAdminUser();
        $DB->delete_records('booking_answers', ['optionid' => $optionb->id]);
        $DB->delete_records('booking_options', ['id' => $optionb->id]);
        booking_option::purge_cache_for_option($optionb->id);
        booking_option::purge_cache_for_answers($optionb->id);

        $this->setUser($this->student);
        $this->assertSame(MOD_BOOKING_BO_COND_JSON_PREVIOUSLYBOOKED, $this->blocking_condition($target->id));

        $this->setAdminUser();
        $this->write_availability_json($target->id, [
            'optionids' => [(int)$optiona->id, (int)$optionb->id],
            'optionidsoperator' => 'OR',
        ]);
        $this->setUser($this->student);
        $this->assertSame(MOD_BOOKING_BO_COND_BOOKITBUTTON, $this->blocking_condition($target->id));

        // The description names the missing option instead of failing.
        $settings = singleton_service::get_instance_of_booking_option_settings($target->id);
        $condition = previouslybooked::instance();
        $condition->customsettings = json_decode($settings->availability)[0];
        $description = $condition->get_description_string(false, true, $settings);
        $this->assertStringContainsString('Prerequisite A', $description);
        $this->assertStringContainsString((string)$optionb->id, $description);
    }

    /**
     * With a warm per-user answers cache the check costs no DB read, however many options are required.
     */
    public function test_no_db_reads_with_warm_user_cache(): void {
        global $DB;
        $prerequisites = [];
        for ($i = 1; $i <= 3; $i++) {
            $prerequisites[] = (int)$this->create_option("Prerequisite $i")->id;
        }
        $target = $this->create_option('Target', [
            'bo_cond_previouslybooked_restrict' => 1,
            'bo_cond_previouslybooked_optionid' => $prerequisites,
            'bo_cond_previouslybooked_optionidsoperator' => 'AND',
        ]);
        $this->setUser($this->student);
        foreach ($prerequisites as $optionid) {
            $this->book_student($optionid);
        }

        // First evaluation warms the caches (option settings, own answers, target answers).
        $settings = singleton_service::get_instance_of_booking_option_settings($target->id);
        $condition = previouslybooked::instance();
        $condition->customsettings = json_decode($settings->availability)[0];
        $this->assertTrue($condition->is_available($settings, $this->student->id));

        $reads = $DB->perf_get_reads();
        $this->assertTrue($condition->is_available($settings, $this->student->id));
        $this->assertFalse($condition->is_available($settings, $this->student->id, true));
        $this->assertSame(0, $DB->perf_get_reads() - $reads, 'previouslybooked must not query the DB per check');
    }
}
