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

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

class mod_booking_lib_testcase extends advanced_testcase {

    public function setUp():void {

    }

    public function tearDown():void {

    }

    // Test adding teacher to event and group.
    public function test_subscribe_teacher_to_booking_option() {

        global $DB;

        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();

        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $bdata = array('name' => 'Test Booking', 'eventtype' => 'Test event',
                        'bookedtext' => array('text' => 'text'), 'waitingtext' => array('text' => 'text'),
                        'notifyemail' => array('text' => 'text'), 'statuschangetext' => array('text' => 'text'),
                        'deletedtext' => array('text' => 'text'), 'pollurltext' => array('text' => 'text'),
                        'pollurlteacherstext' => array('text' => 'text'),
                        'notificationtext' => array('text' => 'text'), 'userleave' => array('text' => 'text'),
                        'bookingpolicy' => 'bookingpolicy', 'tags' => '', 'course' => $course->id,
                        'bookingmanager' => $user->username, 'showviews' => ['mybooking, myoptions, showall, showactive, myinstitution']);

        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $cm = get_coursemodule_from_instance('booking', $booking->id);

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Test option';
        $record->courseid = $course->id;
        $record->description = 'Test description';

        $option = self::getDataGenerator()->get_plugin_generator('mod_booking')->create_option(
                $record);

        $group = $this->getDataGenerator()->create_group(array('courseid' => $course->id));

        subscribe_teacher_to_booking_option($user->id, $option->id, $cm, $group->id);

        $this->assertEquals(1, $DB->count_records('booking_teachers', array('userid' => $user->id, 'optionid' => $option->id)));

        $this->assertEquals(true, groups_is_member($group->id, $user->id));

    }

}