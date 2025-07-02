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
 * Module booking tests common fuctions
 *
 * @package mod_booking
 * @category test
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Andraž Prinčič {@link https://www.princic.net}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();

use advanced_testcase;
use stdClass;

global $CFG;

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Class to handle module booking tests common fuctions
 *
 * @package mod_booking
 * @category test
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Andraž Prinčič {@link https://www.princic.net}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class lib_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
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
     * Test adding teacher to event and group.
     *
     * @covers \mod_booking\teachers_handler::subscribe_teacher_to_booking_option
     *
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function test_subscribe_teacher_to_booking_option(): void {

        global $DB;

        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();

        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $bdata = ['name' => 'Test Booking', 'eventtype' => 'Test event',
                    'bookedtext' => ['text' => 'text'], 'waitingtext' => ['text' => 'text'],
                    'notifyemail' => ['text' => 'text'], 'statuschangetext' => ['text' => 'text'],
                    'deletedtext' => ['text' => 'text'], 'pollurltext' => ['text' => 'text'],
                    'pollurlteacherstext' => ['text' => 'text'], 'notificationtext' => ['text' => 'text'],
                    'userleave' => ['text' => 'text'], 'bookingpolicy' => 'bookingpolicy',
                    'tags' => '', 'course' => $course->id, 'bookingmanager' => $user->username,
                    'showviews' => ['mybooking,myoptions,optionsiamresponsiblefor,showall,showactive,myinstitution'],
        ];

        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $cm = get_coursemodule_from_instance('booking', $booking->id);

        $this->setAdminUser();

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Test option';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course->id;
        $record->description = 'Test description';

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);

        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);

        $teacherhandler = new teachers_handler($option->id);
        $teacherhandler->subscribe_teacher_to_booking_option($user->id, $option->id, $cm->id, $group->id);

        $this->assertEquals(1, $DB->count_records('booking_teachers', ['userid' => $user->id, 'optionid' => $option->id]));

        $this->assertEquals(true, groups_is_member($group->id, $user->id));
    }
}
