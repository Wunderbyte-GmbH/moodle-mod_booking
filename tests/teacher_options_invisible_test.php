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
 * Tests that get_all_options_of_teacher_sql respects mod/booking:canseeinvisibleoptions.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use stdClass;

/**
 * Tests for the teacher options SQL and invisible options.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\booking::get_all_options_of_teacher_sql
 */
final class teacher_options_invisible_test extends booking_advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
    }

    /**
     * A teacher with the capability sees their invisible options, a teacher without it does not.
     *
     * @return void
     */
    public function test_teacher_sql_respects_canseeinvisibleoptions(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        // Editingteacher has mod/booking:canseeinvisibleoptions by archetype, student does not.
        $teacherwithcap = $this->getDataGenerator()->create_user();
        $teacherwithoutcap = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacherwithcap->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($teacherwithoutcap->id, $course->id, 'student');

        $bdata = [
            'name' => 'Booking',
            'course' => $course->id,
            'bookingmanager' => $teacherwithcap->username,
        ];
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        foreach ([$teacherwithcap, $teacherwithoutcap] as $teacher) {
            foreach ([0, 1] as $invisible) {
                $record = new stdClass();
                $record->bookingid = $booking->id;
                $record->text = "Option teacher {$teacher->id} invisible {$invisible}";
                $record->chooseorcreatecourse = 1;
                $record->courseid = $course->id;
                $record->teachersforoption = $teacher->username;
                $record->invisible = $invisible;
                $plugingenerator->create_option($record);
            }
        }

        // Teacher with capability: both own options are returned.
        $this->setUser($teacherwithcap);
        $texts = $this->fetch_option_texts($teacherwithcap->id, (int)$booking->id);
        $this->assertCount(2, $texts);
        $this->assertContains("Option teacher {$teacherwithcap->id} invisible 1", $texts);

        // Teacher without capability: only the visible option is returned.
        $this->setUser($teacherwithoutcap);
        $texts = $this->fetch_option_texts($teacherwithoutcap->id, (int)$booking->id);
        $this->assertSame(["Option teacher {$teacherwithoutcap->id} invisible 0"], $texts);
    }

    /**
     * Run the teacher SQL and return the option texts.
     *
     * @param int $teacherid
     * @param int $bookingid
     * @return string[]
     */
    private function fetch_option_texts(int $teacherid, int $bookingid): array {
        global $DB;
        [$fields, $from, $where, $params] = booking::get_all_options_of_teacher_sql($teacherid, $bookingid);
        $records = $DB->get_records_sql("SELECT $fields FROM $from WHERE $where", $params);
        $texts = array_map(fn($r) => $r->text, $records);
        sort($texts);
        return array_values($texts);
    }
}
