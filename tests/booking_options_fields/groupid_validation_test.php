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
 * Tests for the option form validation of the manually selected connected-course groups.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use mod_booking\option\fields\groupid;

/**
 * Tests that saving the option form with a manually selected group that does not
 * exist in the connected course produces a validation error instead of silently
 * discarding the group, while all cases in which the selection is not applied
 * (automatic group mode, no course connection) stay free of errors.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\option\fields\groupid::validation
 */
final class groupid_validation_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
    }

    /**
     * Create a booking instance with the given addtogroup setting.
     *
     * @param int $courseid id of the course the instance is created in
     * @param int $addtogroup value for the addtogroup instance setting
     * @return \stdClass the created booking instance incl. cmid
     */
    private function create_booking_instance(int $courseid, int $addtogroup): \stdClass {
        $bdata = [
            'name' => 'Booking instance',
            'course' => $courseid,
            'autoenrol' => 1,
            'addtogroup' => $addtogroup,
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
            'tags' => '',
            'showviews' => ['mybooking,myoptions,optionsiamresponsiblefor,showall,showactive,myinstitution'],
        ];
        return $this->getDataGenerator()->create_module('booking', $bdata);
    }

    /**
     * A group of the connected course passes, a group of another course and a
     * deleted group produce a validation error naming the missing groups.
     */
    public function test_validation_of_manually_selected_groups(): void {
        $this->setAdminUser();

        $course1 = $this->getDataGenerator()->create_course();
        $connectedcourse = $this->getDataGenerator()->create_course();
        $othercourse = $this->getDataGenerator()->create_course();

        $booking = $this->create_booking_instance($course1->id, 0);

        $connectedgroup = $this->getDataGenerator()->create_group([
            'courseid' => $connectedcourse->id,
            'name' => 'Group in connected course',
        ]);
        $foreigngroup = $this->getDataGenerator()->create_group([
            'courseid' => $othercourse->id,
            'name' => 'Group in other course',
        ]);

        $data = [
            'cmid' => $booking->cmid,
            'chooseorcreatecourse' => 1,
            'courseid' => $connectedcourse->id,
            'addtogroupsofconnectedcourse' => [$connectedgroup->id],
        ];

        // A group of the connected course is valid.
        $errors = [];
        groupid::validation($data, [], $errors);
        $this->assertArrayNotHasKey('addtogroupsofconnectedcourse', $errors);

        // A group of another course must produce an error naming the group.
        $data['addtogroupsofconnectedcourse'] = [$connectedgroup->id, $foreigngroup->id];
        $errors = [];
        groupid::validation($data, [], $errors);
        $this->assertArrayHasKey('addtogroupsofconnectedcourse', $errors);
        $this->assertStringContainsString('Group in other course', $errors['addtogroupsofconnectedcourse']);
        $this->assertStringNotContainsString('Group in connected course', $errors['addtogroupsofconnectedcourse']);

        // A group that does not exist at all (e.g. deleted meanwhile) is named by its id.
        $data['addtogroupsofconnectedcourse'] = [$connectedgroup->id, 9999999];
        $errors = [];
        groupid::validation($data, [], $errors);
        $this->assertArrayHasKey('addtogroupsofconnectedcourse', $errors);
        $this->assertStringContainsString('[ID: 9999999]', $errors['addtogroupsofconnectedcourse']);

        // The courseid may arrive as an array from the autocomplete.
        $data['addtogroupsofconnectedcourse'] = [$connectedgroup->id];
        $data['courseid'] = [$connectedcourse->id];
        $errors = [];
        groupid::validation($data, [], $errors);
        $this->assertArrayNotHasKey('addtogroupsofconnectedcourse', $errors);
    }

    /**
     * While the selection is not applied on save, no error may be produced:
     * with the automatic group mode active, without course connection and
     * without any selection.
     */
    public function test_validation_skipped_while_selection_is_not_applied(): void {
        $this->setAdminUser();

        $course1 = $this->getDataGenerator()->create_course();
        $connectedcourse = $this->getDataGenerator()->create_course();

        // Instance with the automatic group mode active: selection is never applied.
        $automaticinstance = $this->create_booking_instance($course1->id, 1);
        $data = [
            'cmid' => $automaticinstance->cmid,
            'chooseorcreatecourse' => 1,
            'courseid' => $connectedcourse->id,
            'addtogroupsofconnectedcourse' => [9999999],
        ];
        $errors = [];
        groupid::validation($data, [], $errors);
        $this->assertArrayNotHasKey('addtogroupsofconnectedcourse', $errors);

        // Manual mode, but no course connection: the hidden selection is discarded on save.
        $manualinstance = $this->create_booking_instance($course1->id, 0);
        $data = [
            'cmid' => $manualinstance->cmid,
            'chooseorcreatecourse' => 0,
            'courseid' => 0,
            'addtogroupsofconnectedcourse' => [9999999],
        ];
        $errors = [];
        groupid::validation($data, [], $errors);
        $this->assertArrayNotHasKey('addtogroupsofconnectedcourse', $errors);

        // No selection at all.
        $data = [
            'cmid' => $manualinstance->cmid,
            'chooseorcreatecourse' => 1,
            'courseid' => $connectedcourse->id,
            'addtogroupsofconnectedcourse' => [],
        ];
        $errors = [];
        groupid::validation($data, [], $errors);
        $this->assertArrayNotHasKey('addtogroupsofconnectedcourse', $errors);
    }
}
