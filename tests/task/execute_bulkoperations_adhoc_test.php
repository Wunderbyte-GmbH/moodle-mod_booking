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
 * Tests for the execute_bulkoperations_adhoc task.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\task\execute_bulkoperations_adhoc;
use mod_booking_generator;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once(__DIR__ . '/../booking_advanced_testcase.php');

/**
 * Tests for the execute_bulkoperations_adhoc task.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class execute_bulkoperations_adhoc_test extends booking_advanced_testcase {
    /**
     * Bulk operations are applied to all given booking options when the adhoc task runs.
     *
     * @covers \mod_booking\task\execute_bulkoperations_adhoc::execute
     * @covers \mod_booking\form\option_form_bulk::save_options
     */
    public function test_bulk_operations_are_applied_by_adhoc_task(): void {
        global $DB;

        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $bookingmanager = $this->getDataGenerator()->create_user();

        $bdata = [
            'name' => 'Test Booking',
            'eventtype' => 'Test event',
            'course' => $course->id,
            'bookingmanager' => $bookingmanager->username,
            'bookedtext' => ['text' => 'text'],
            'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'],
            'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'],
            'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'],
            'notificationtext' => ['text' => 'text'],
            'userleave' => ['text' => 'text'],
            'tags' => '',
            'showviews' => ['mybooking,myoptions,showall,showactive,myinstitution'],
        ];
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $optionids = [];
        foreach (['option1', 'option2'] as $identifier) {
            $record = (object) [
                'text' => 'Test option ' . $identifier,
                'description' => 'Test option',
                'identifier' => $identifier,
                'bookingid' => $booking->id,
                'maxanswers' => 1,
            ];
            $option = $plugingenerator->create_option($record);
            $optionids[] = (int) $option->id;
        }

        // Queue the task exactly like option_form_bulk::process_dynamic_submission does.
        $task = new execute_bulkoperations_adhoc();
        $task->set_custom_data([
            'formdata' => [
                'checkedids' => implode(',', $optionids),
                'maxanswers' => 42,
            ],
            'optionids' => $optionids,
        ]);
        $task->set_userid(get_admin()->id);
        \core\task\manager::queue_adhoc_task($task);

        // Nothing has changed yet: the update only happens when the task runs.
        foreach ($optionids as $optionid) {
            $this->assertEquals(1, $DB->get_field('booking_options', 'maxanswers', ['id' => $optionid]));
        }

        // The task reports its progress via mtrace.
        $this->expectOutputRegex('/finished, 2 updated, 0 failed/');
        $this->runAdhocTasks(execute_bulkoperations_adhoc::class);

        singleton_service::destroy_instance();
        foreach ($optionids as $optionid) {
            $this->assertEquals(42, $DB->get_field('booking_options', 'maxanswers', ['id' => $optionid]));
        }
    }
}
