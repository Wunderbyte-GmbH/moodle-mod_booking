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
 * Tests for booking option events.
 *
 * @package mod_booking
 * @category test
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2017 Andraž Prinčič
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use mod_booking\local\optiondates\optiondate_answer;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * PHPUnit test case for the class.
 */
final class optiondate_answer_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        global $DB;

        parent::tearDown();
        // Mandatory clean-up.
        singleton_service::destroy_instance();
    }

    /**
     * Data provider for the test.
     *
     * @return array Test data.
     */
    public static function data_provider(): array {
        return [
            [1, 1, 1, 1, 'Test notes 1', 'Test JSON 1'],
            [2, 2, 2, 0, 'Test notes 2', 'Test JSON 2'],
            [3, 3, 3, 2, null, null],
        ];
    }

    /**
     * Test save_record and get_record methods.
     *
     * @covers \mod_booking\local\optiondates\optiondate_answer
     *
     * @dataProvider data_provider
     * @param int $userid
     * @param int $optiondateid
     * @param int $optionid
     * @param int $status
     * @param string|null $notes
     * @param string|null $json
     */
    public function test_save_and_get_record(
        $userid,
        $optiondateid,
        $optionid,
        $status,
        $notes,
        $json
    ): void {
        $this->resetAfterTest(true);

        $manager = new optiondate_answer($userid, $optiondateid, $optionid);
        $manager->save_record($status, $notes, $json);

        $record = $manager->get_record();

        $this->assertNotEmpty($record);
        $this->assertEquals($userid, $record->userid);
        $this->assertEquals($optiondateid, $record->optiondateid);
        $this->assertEquals($optionid, $record->optionid);
        $this->assertEquals($status, $record->status);
        $this->assertEquals($notes, $record->notes);
        $this->assertEquals($json, $record->json);
    }

    /**
     * Test delete_record method.
     *
     * @covers \mod_booking\local\optiondates\optiondate_answer
     *
     * @dataProvider data_provider
     * @param int $userid
     * @param int $optiondateid
     * @param int $optionid
     * @param int $status
     * @param string|null $notes
     * @param string|null $json
     */
    public function test_delete_record(
        $userid,
        $optiondateid,
        $optionid,
        $status,
        $notes,
        $json
    ): void {
        $this->resetAfterTest(true);

        $manager = new optiondate_answer($userid, $optiondateid, $optionid);
        $manager->save_record($status, $notes, $json);

        $this->assertNotEmpty($manager->get_record());

        $manager->delete_record();
        $this->assertEmpty($manager->get_record());
    }

    /**
     * Test delete_record method.
     *
     * @covers \mod_booking\local\optiondates\optiondate_answer
     *
     * @dataProvider data_provider
     * @param int $userid
     * @param int $optiondateid
     * @param int $optionid
     * @param int $status
     * @param string|null $notes
     * @param string|null $json
     */
    public function test_update_record(
        $userid,
        $optiondateid,
        $optionid,
        $status,
        $notes,
        $json
    ): void {
        $this->resetAfterTest(true);

        $manager = new optiondate_answer($userid, $optiondateid, $optionid);
        $manager->add_or_update_status($status);

        $this->assertNotEmpty($manager->get_record());

        $newstatus = $status + 1;
        $manager->add_or_update_status($newstatus);

        $record = $manager->get_record();
        $this->assertEquals($newstatus, $record->status);
        $this->assertEmpty($record->notes);

        $manager->add_or_update_notes($notes);
        $record = $manager->get_record();
        $this->assertEquals($notes, $record->notes);

        $newnotes = "$notes x";
        $manager->add_or_update_notes($newnotes);

        $record = $manager->get_record();
        // Status is still the same.
        $this->assertEquals($newstatus, $record->status);

        $this->assertEquals($newnotes, $record->notes);
    }
}
