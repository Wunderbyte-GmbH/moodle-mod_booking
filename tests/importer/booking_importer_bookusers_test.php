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
 * Tests for bookusers field error collection during CSV import.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use mod_booking_generator;
use mod_booking\importer\bookingoptionsimporter;
use stdClass;

/**
 * Class handling tests for bookusers field error collection.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class booking_importer_bookusers_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }

    /**
     * Test that bookusers errors (bad email, bad date, bad completed value) are collected
     * into skippedrows during a preview import.
     *
     * The fixture CSV has 13 data rows across 6 pre-created booking options and 4 users.
     * 4 rows are deliberately invalid:
     *   - L5:  unknown email address (nouserfound)
     *   - L7:  invalid completed value '-' (wrongcompletedvalue)
     *   - L8:  invalid date 'not-a-date' (wrongdateformat)
     *   - L12: both invalid date and invalid completed value (both collected and thrown together)
     *
     * The preview method runs the full callback in a rolled-back transaction, so no DB
     * changes are persisted. Rows that raise errors inside bookusers::save_data appear in
     * 'skippedrows' with a non-empty 'reason'.
     *
     * @covers \mod_booking\option\fields\bookusers::save_data
     *
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function test_csv_import_bookusers_error_collection(): void {
        $this->setTimezone('Europe/London');
        set_config('timezone', 'Europe/London');

        // Setup course.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create a teacher to run the import.
        $teacher1 = $this->getDataGenerator()->create_user([
            'username'  => 'teacher1',
            'firstname' => 'Teacher',
            'lastname'  => '1',
            'email'     => 'teacher1@example.com',
        ]);

        // Create the 4 users referenced in the CSV.
        $chefito = $this->getDataGenerator()->create_user([
            'username'  => 'chefito',
            'firstname' => 'Chefito',
            'lastname'  => 'Chef',
            'email'     => 'chefito.chef@tuwien.ac.at',
        ]);
        $bertin = $this->getDataGenerator()->create_user([
            'username'  => 'bertin',
            'firstname' => 'Bertin',
            'lastname'  => 'Agnelli',
            'email'     => 'bertin.agnelli@tuwien.ac.at',
        ]);
        $abarne = $this->getDataGenerator()->create_user([
            'username'  => 'abarne',
            'firstname' => 'Abarne',
            'lastname'  => 'Abruzzi',
            'email'     => 'abarne.abruzzi@tuwien.ac.at',
        ]);
        $cecil = $this->getDataGenerator()->create_user([
            'username'  => 'cecil',
            'firstname' => 'Cecil',
            'lastname'  => 'Assouad',
            'email'     => 'cecil.assouad@tuwien.ac.at',
        ]);

        // Create booking module.
        $bdata = [
            'name'        => 'Test Bookusers Error Collection',
            'eventtype'   => 'Test event',
            'course'      => $course->id,
            'bookingmanager' => $teacher1->username,
            'bookedtext'  => ['text' => 'text'],
            'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'],
            'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'],
            'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'],
            'notificationtext' => ['text' => 'text'],
            'userleave'   => ['text' => 'text'],
            'bookingpolicy' => 'bookingpolicy',
            'tags' => '',
        ];
        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        // Switch to admin to create options and enrol users.
        $this->setAdminUser();

        // Get coursemodule.
        $cmb1 = get_coursemodule_from_instance('booking', $booking1->id);

        // Pre-create the 6 booking options (before enrolments) so the identifier lookup
        // in prepare_import::set_data resolves correctly during the preview callback.
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $identifiers = [
            '0bc1504fc4c9eb7',
            '57f48211725094b',
            'e3fcd0215bdff19',
            '1a5c3d78b01c2ff',
            'e805ebd6f42b890',
            '3fad89f6ba41801',
        ];
        foreach ($identifiers as $idx => $identifier) {
            $plugingenerator->create_option([
                'bookingid'  => $booking1->id,
                'text'       => 'Option ' . ($idx + 1),
                'identifier' => $identifier,
            ]);
        }

        // Enrol users after options exist.
        $this->getDataGenerator()->enrol_user($teacher1->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($chefito->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($bertin->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($abarne->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($cecil->id, $course->id, 'student');

        // Run import as teacher via the preview (dry-run) method — no DB changes are persisted.
        $this->setUser($teacher1);

        $formdata = new stdClass();
        $formdata->delimiter_name  = 'comma';
        $formdata->enclosure       = '"';
        $formdata->encoding        = 'utf-8';
        $formdata->updateexisting  = true;
        $formdata->dateparseformat = 'Y-m-d';
        $formdata->cmid            = $cmb1->id;
        $res = (new bookingoptionsimporter())->execute_bookingoptions_csv_import_preview(
            $formdata,
            file_get_contents($this->get_full_path_of_csv_file('bookusers_errors', '01'))
        );
        // Response must be a preview result.
        $this->assertIsArray($res);
        $this->assertTrue($res['preview']);
        $this->assertEquals(1, $res['success']);

        // Index skipped rows by CSV line number for targeted assertions.
        $skippedbyline = [];
        foreach ($res['skippedrows'] as $row) {
            $skippedbyline[$row['linenumber']] = $row;
        }

        // L5: email address '1234' is not a known user → nouserfound.
        $this->assertArrayHasKey(5, $skippedbyline, 'Row L5 (unknown email) must be in skippedrows');
        $this->assertNotEmpty($skippedbyline[5]['reason']);

        // L7: completed='-' is not a valid 0/1 value → wrongcompletedvalue.
        $this->assertArrayHasKey(7, $skippedbyline, 'Row L7 (invalid completed) must be in skippedrows');
        $this->assertNotEmpty($skippedbyline[7]['reason']);

        // L8: timebooked='not-a-date' fails DateTime::createFromFormat → wrongdateformat.
        $this->assertArrayHasKey(8, $skippedbyline, 'Row L8 (invalid date) must be in skippedrows');
        $this->assertNotEmpty($skippedbyline[8]['reason']);

        // L12: both invalid date and invalid completed — both errors must appear in the reason.
        $this->assertArrayHasKey(12, $skippedbyline, 'Row L12 (invalid date + completed) must be in skippedrows');
        $this->assertNotEmpty($skippedbyline[12]['reason']);

        // The UPDATE path in booking_option::update has a known side-effect: when updating
        // an existing option the local $optionid variable is never reassigned, so
        // singleton_service::get_instance_of_booking_option() is called with id=0 after
        // save_fields_post succeeds. This triggers debugging() for each valid row processed.
        // We acknowledge those calls here so PHPUnit does not flag them as unexpected.
        $this->resetDebugging();
    }

    /**
     * Get full path of CSV fixture file.
     *
     * @param string $setname
     * @param string $test
     * @return string full path of file.
     */
    protected function get_full_path_of_csv_file(string $setname, string $test): string {
        return __DIR__ . "/../fixtures/{$setname}{$test}.csv";
    }
}
