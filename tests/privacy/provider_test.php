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

namespace mod_booking\privacy;

use context_module;
use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\types\database_table;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\writer;
use mod_booking\booking;
use mod_booking_generator;

/**
 * Privacy provider tests for mod_booking.
 *
 * Contains a tripwire test that keeps the privacy metadata in sync with install.xml:
 * every table with a userid/teacherid column must be declared, so a new user-related
 * table cannot be shipped undeclared again (moodle.org approval blocker).
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\privacy\provider
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * Build the plugin's metadata collection.
     *
     * @return collection
     */
    protected function get_collection(): collection {
        $collection = new collection('mod_booking');
        return provider::get_metadata($collection);
    }

    /**
     * Every install.xml table holding a userid or teacherid column must be declared in the metadata.
     */
    public function test_metadata_declares_all_user_related_tables(): void {
        $declared = [];
        foreach ($this->get_collection()->get_collection() as $type) {
            if ($type instanceof database_table) {
                $declared[] = $type->get_name();
            }
        }

        $xml = simplexml_load_file(__DIR__ . '/../../db/install.xml');
        $missing = [];
        foreach ($xml->TABLES->TABLE as $table) {
            $tablename = (string)$table['NAME'];
            foreach ($table->FIELDS->FIELD as $field) {
                $fieldname = (string)$field['NAME'];
                if (in_array($fieldname, ['userid', 'teacherid'], true)) {
                    if (!in_array($tablename, $declared, true)) {
                        $missing[] = "$tablename ($fieldname)";
                    }
                    break;
                }
            }
        }

        $this->assertSame(
            [],
            $missing,
            'User-related tables missing in privacy provider metadata (add_database_table): ' . implode(', ', $missing)
        );
    }

    /**
     * Every lang string referenced by the metadata must exist.
     */
    public function test_metadata_lang_strings_exist(): void {
        $stringman = get_string_manager();
        foreach ($this->get_collection()->get_collection() as $type) {
            $identifiers = array_merge(
                array_values($type->get_privacy_fields() ?? []),
                [$type->get_summary()]
            );
            foreach (array_filter($identifiers) as $identifier) {
                $this->assertTrue(
                    $stringman->string_exists($identifier, 'mod_booking'),
                    "Missing lang string '$identifier' referenced by privacy metadata."
                );
            }
        }
    }

    /**
     * Seed one row per slot/sync table for the given users.
     *
     * @param int $optionid
     * @param int $userid the user whose data the rows represent
     * @param int $teacherid a second user acting as assigned/unavailable teacher
     * @return void
     */
    protected function seed_slot_and_sync_rows(int $optionid, int $userid, int $teacherid): void {
        global $DB;
        $now = time();
        $DB->insert_record('booking_slot_moves', (object)[
            'optionid' => $optionid,
            'baid' => 1,
            'userid' => $userid,
            'newslots' => '[]',
            'oldslots' => '[]',
            'pricedelta' => 0,
            'status' => 0,
            'expiry' => 0,
            'identifier' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->insert_record('booking_slot_student_teacher', (object)[
            'optionid' => $optionid,
            'userid' => $userid,
            'teacherid' => $teacherid,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->insert_record('booking_sync_attempts', (object)[
            'syncruleid' => 1,
            'bookingoptionid' => $optionid,
            'userid' => $userid,
            'action' => 'enrol',
            'reasoncode' => 'ok',
            'reasonmessage' => '',
            'timecreated' => $now,
        ]);
        $DB->insert_record('booking_teacher_unavailability', (object)[
            'optionid' => $optionid,
            'teacherid' => $teacherid,
            'unavailable_from' => $now,
            'unavailable_until' => $now + DAYSECS,
            'reason' => '',
            'timecreated' => $now,
        ]);
    }

    /**
     * delete_data_for_users removes the slot/sync rows of the approved users only.
     */
    public function test_delete_data_for_users_covers_slot_and_sync_tables(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id]);
        $context = context_module::instance($booking->cmid);

        $student = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();

        $this->seed_slot_and_sync_rows(1, $student->id, $teacher->id);
        $this->seed_slot_and_sync_rows(1, $other->id, $other->id);

        $userlist = new approved_userlist($context, 'mod_booking', [$student->id, $teacher->id]);
        provider::delete_data_for_users($userlist);

        $this->assertFalse($DB->record_exists('booking_slot_moves', ['userid' => $student->id]));
        $this->assertFalse($DB->record_exists('booking_slot_student_teacher', ['userid' => $student->id]));
        $this->assertFalse($DB->record_exists('booking_sync_attempts', ['userid' => $student->id]));
        $this->assertFalse($DB->record_exists('booking_teacher_unavailability', ['teacherid' => $teacher->id]));
        // No stale reference to the deleted teacher may survive in remaining rows.
        $this->assertFalse($DB->record_exists('booking_slot_student_teacher', ['teacherid' => $teacher->id]));

        // The unrelated user's rows stay untouched.
        $this->assertTrue($DB->record_exists('booking_slot_moves', ['userid' => $other->id]));
        $this->assertTrue($DB->record_exists('booking_slot_student_teacher', ['userid' => $other->id]));
        $this->assertTrue($DB->record_exists('booking_sync_attempts', ['userid' => $other->id]));
        $this->assertTrue($DB->record_exists('booking_teacher_unavailability', ['teacherid' => $other->id]));
    }

    /**
     * delete_data_for_user removes the slot/sync rows of a single user; rows where the
     * user is only the assigned teacher survive with the teacher reference blanked.
     */
    public function test_delete_data_for_user_covers_slot_and_sync_tables(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id]);
        $context = context_module::instance($booking->cmid);

        $student = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();

        $this->seed_slot_and_sync_rows(1, $student->id, $teacher->id);

        // Delete the TEACHER: the student's assignment row must survive without the reference.
        $contextlist = new approved_contextlist($teacher, 'mod_booking', [$context->id]);
        provider::delete_data_for_user($contextlist);

        $this->assertFalse($DB->record_exists('booking_teacher_unavailability', ['teacherid' => $teacher->id]));
        $assignment = $DB->get_record('booking_slot_student_teacher', ['userid' => $student->id], '*', MUST_EXIST);
        $this->assertEquals(0, $assignment->teacherid);

        // Delete the STUDENT: their rows disappear entirely.
        $contextlist = new approved_contextlist($student, 'mod_booking', [$context->id]);
        provider::delete_data_for_user($contextlist);

        $this->assertFalse($DB->record_exists('booking_slot_moves', ['userid' => $student->id]));
        $this->assertFalse($DB->record_exists('booking_slot_student_teacher', ['userid' => $student->id]));
        $this->assertFalse($DB->record_exists('booking_sync_attempts', ['userid' => $student->id]));
    }

    /**
     * export_user_data() must not crash for a user with booking_history rows, and must translate
     * the raw history status code to its human-readable label instead of leaving it untouched.
     *
     * Regression test for GH-1517: the history loop used to access the stdClass rows returned by
     * $DB->get_records() with array syntax ($history['status']), which throws "Cannot use object
     * of type stdClass as array" and aborted the whole export for the booking instance.
     */
    public function test_export_user_data_includes_booking_history_with_readable_status(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id]);
        $context = context_module::instance($booking->cmid);

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option([
            'bookingid' => $booking->id,
            'text' => 'GDPR export option',
            'description' => 'GDPR export option',
        ]);
        $plugingenerator->create_answer(['optionid' => $option->id, 'userid' => $student->id]);

        // Sanity check: booking the option must actually have written a booking_history row,
        // otherwise this test would not exercise the crashing code path at all.
        $rawhistory = $DB->get_records('booking_history', ['userid' => $student->id]);
        $this->assertNotEmpty($rawhistory, 'Fixture did not produce a booking_history row.');

        $possiblestatuses = booking::get_array_of_possible_booking_history_statuses();

        // This used to throw "Cannot use object of type stdClass as array" (GH-1517).
        $this->export_context_data_for_user($student->id, $context, 'mod_booking');
        // Read the exported data.
        $data = writer::with_context($context)->get_data([]);
        $this->assertNotEmpty($data);
        // The crash happened before export_booking() ran, so the whole instance's data
        // (not just history) was lost. Make sure it now survives alongside the history.
        $this->assertNotEmpty($data->bookedoptions ?? null, 'Booking data for the instance was lost.');
        $this->assertNotEmpty($data->historydata ?? null);

        foreach ($data->historydata as $history) {
            $raw = $rawhistory[$history->id];
            $this->assertSame($possiblestatuses[$raw->status] ?? $raw->status, $history->status);
            $this->assertNotSame($raw->status, $history->status, 'Status was not translated to its label.');
        }
    }
}
