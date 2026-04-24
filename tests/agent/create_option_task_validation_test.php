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
 * Validation tests for create_option_task.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use mod_booking\local\wbagent\booking\tasks\create_option_task;

/**
 * Task-level tests for explicit override behavior.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class create_option_task_validation_test extends advanced_testcase {
    /** @var int */
    private int $cmid;

    /**
     * Set up course module context.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Task Validation Booking',
        ]);
        $this->cmid = (int)$booking->cmid;
    }

    /**
     * Missing location should be allowed when explicit location/address override is present.
     */
    public function test_allows_missing_location_with_override(): void {
        global $USER;

        $task = new create_option_task();

        $result = $task->validate([
            'text' => 'Meine Veranstaltung um 12',
            'maxanswers' => 20,
            'coursestarttime' => '2036-06-04T12:00:00',
            'duration' => 3600,
            'teacheremail' => (string)$USER->email,
            'override' => ['location', 'address'],
        ], $this->cmid);

        $this->assertTrue($result['valid'], implode(' | ', $result['errors'] ?? []));
        $this->assertEmpty($result['errors']);
    }

    /**
     * Missing location without override should produce a confirmable issue.
     */
    public function test_missing_location_without_override_returns_confirmable_issue(): void {
        global $USER;

        $task = new create_option_task();

        $result = $task->validate([
            'text' => 'Meine Veranstaltung um 12',
            'maxanswers' => 20,
            'coursestarttime' => '2036-06-04T12:00:00',
            'duration' => 3600,
            'teacheremail' => (string)$USER->email,
        ], $this->cmid);

        $this->assertTrue($result['valid']);
        $issues = $result['issues'] ?? [];
        $codes = array_values(array_filter(array_map(static fn(array $issue): string => (string)($issue['code'] ?? ''), $issues)));
        $this->assertContains('MISSING_LOCATION_CONFIRM_REQUIRED', $codes);
    }

    /**
     * Missing schedule details must fail already in first validation.
     */
    public function test_missing_schedule_fails_in_first_validation(): void {
        $task = new create_option_task();

        $result = $task->validate([
            'text' => 'Nur Titel',
        ], $this->cmid);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }
}
