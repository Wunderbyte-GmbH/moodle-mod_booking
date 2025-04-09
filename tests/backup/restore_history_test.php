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

defined('MOODLE_INTERNAL') || die();

use core_backup\tests\restore_date_testcase;

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Test restoring of bookkings with options into another course.
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Magdalena Holczik
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \backup_booking_activity_structure_step
 * @covers \restore_booking_activity_structure_step
 */
class mod_booking_restore_history_testcase extends restore_date_testcase {

    public function test_booking_history_restores(): void {
        global $DB, $USER;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        // Step 1: Create course + booking instance.
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id]);

        // Step 2: Add booking_option (you might have a generator for this).
        $option = $DB->insert_record('booking_options', (object)[
            'bookingid' => $booking->id,
            'text' => 'Option A',
            'courseid' => $course->id,
        ], true);

        // Step 3: Add booking_history entry manually.
        $originalhistory = (object)[
            'bookingid' => $booking->id,
            'optionid' => $option,
            'answerid' => null,
            'userid' => $USER->id,
            'status' => 0,
            'usermodified' => $USER->id,
            'timecreated' => time(),
            'json' => '{"info":"test"}',
        ];
        $originalhistory->id = $DB->insert_record('booking_history', $originalhistory);

        // Step 4: Backup course.
        $backupid = $this->backup_course($course);

        // Step 5: Restore course.
        $newcourseid = $this->restore_course($backupid);
        $this->assertNotEquals($course->id, $newcourseid);

        // Step 6: Confirm restored history.
        $newbooking = $DB->get_record('booking', ['course' => $newcourseid]);
        $this->assertNotEmpty($newbooking);

        $newhistory = $DB->get_records('booking_history', ['bookingid' => $newbooking->id]);
        $this->assertCount(1, $newhistory);

        $restored = reset($newhistory);
        $this->assertEquals($originalhistory->status, $restored->status);
        $this->assertEquals($originalhistory->json, $restored->json);
        $this->assertEquals($originalhistory->userid, $this->get_new_userid($restored->userid));
    }

    /**
     * Get new userid.
     *
     * @param mixed $oldid
     *
     * @return int
     *
     */
    private function get_new_userid($oldid) {
        // global $DB;
        // Map old user ID to expected user in new course (for simple restore tests usually same user).
        // You can customize this if you're testing across user contexts.
        return $oldid;
    }
}
