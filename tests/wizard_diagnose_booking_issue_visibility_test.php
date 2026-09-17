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

namespace mod_booking;

use advanced_testcase;
use stdClass;
use mod_booking\local\wizard\options\skills\diagnose_booking_issue_skill;

/**
 * Tests the booking-activity visibility signal added to diagnose_booking_issue's instance_checks.
 *
 * @package    mod_booking
 * @category   test
 * @covers     \mod_booking\local\wizard\options\skills\diagnose_booking_issue_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class wizard_diagnose_booking_issue_visibility_test extends advanced_testcase {
    use \mod_booking\tests\agent_extension_test_trait;

    /**
     * Skip the entire case when the optional bookingextension_agent subplugin is absent.
     */
    public function setUp(): void {
        parent::setUp();
        $this->skip_without_agent_extension();
    }

    /**
     * Build a course + booking instance + one option and an enrolled student.
     *
     * @return array{0:\stdClass,1:\stdClass,2:\stdClass} [booking module, option, student]
     */
    private function setup_booking(): array {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        $bdata = [
            'name' => 'Diag Booking',
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
            'course' => $course->id,
            'bookingmanager' => $bookingmanager->username,
        ];
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($student->id, $course->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id, 'editingteacher');

        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Diag option';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->useprice = 0;
        $record->maxanswers = 4;
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('now + 3 days');
        $record->courseendtime_0 = strtotime('now + 6 days');
        $option = $plugingenerator->create_option($record);

        return [$booking, $option, $student];
    }

    /**
     * A visible booking activity reports activityuservisible = true.
     */
    public function test_visible_activity_signal(): void {
        $this->resetAfterTest();
        [$booking, $option, $student] = $this->setup_booking();
        $this->setUser($student);

        $result = (new diagnose_booking_issue_skill())->execute(
            ['optionid' => (int)$option->id],
            (int)\context_module::instance($booking->cmid)->id,
            (int)$student->id
        );

        $this->assertSame('executed', $result['status']);
        $checks = $result['diagnosis']['instance_checks'];
        $this->assertArrayHasKey('activityuservisible', $checks);
        $this->assertArrayHasKey('activityavailableinfo', $checks);
        $this->assertTrue($checks['activityuservisible']);
    }

    /**
     * A hidden booking activity reports activityuservisible = false for the student.
     */
    public function test_hidden_activity_signal(): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        $this->resetAfterTest();
        [$booking, $option, $student] = $this->setup_booking();

        set_coursemodule_visible($booking->cmid, 0);
        rebuild_course_cache((int)$booking->course, true);

        $this->setUser($student);
        $result = (new diagnose_booking_issue_skill())->execute(
            ['optionid' => (int)$option->id],
            (int)\context_module::instance($booking->cmid)->id,
            (int)$student->id
        );

        $this->assertSame('executed', $result['status']);
        $this->assertFalse($result['diagnosis']['instance_checks']['activityuservisible']);
    }
}
