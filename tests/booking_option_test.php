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
 * Tests for booking events.
 *
 * @package mod_booking
 * @category test
 * @copyright 2017 Andraž Prinčič <atletek@gmail.com>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

/**
 * Tests for forum events.
 *
 * @package mod_forum
 * @category test
 * @copyright 2014 Dan Poltawski <dan@moodle.com>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_booking_booking_option_testcase extends advanced_testcase {

    /**
     * Tests set up.
     */
    public function setUp():void {
        $this->resetAfterTest();
    }

    public function tearDown():void {
    }

    public function test_delete_responses_activitycompletion() {
        global $DB, $CFG;

        $CFG->enablecompletion = 1;

        $bdata = array('name' => 'Test Booking 1', 'eventtype' => 'Test event', 'enablecompletion' => 1,
            'bookedtext' => array('text' => 'text'), 'waitingtext' => array('text' => 'text'),
            'notifyemail' => array('text' => 'text'), 'statuschangetext' => array('text' => 'text'),
            'deletedtext' => array('text' => 'text'), 'pollurltext' => array('text' => 'text'),
            'pollurlteacherstext' => array('text' => 'text'),
            'notificationtext' => array('text' => 'text'), 'userleave' => array('text' => 'text'),
                        'bookingpolicy' => 'bookingpolicy', 'tags' => '', 'completion' => 2, 'showviews' => ['mybooking,myoptions,showall,showactive,myinstitution']);
        // Setup test data.
        $course = $this->getDataGenerator()->create_course(array('enablecompletion' => 1));

        // Create users.
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $user3 = $this->getDataGenerator()->create_user(); // Booking manager.

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $user3->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $result = $DB->get_record_sql(
                'SELECT cm.id, cm.course, cm.module, cm.instance, m.name
                FROM {course_modules} cm LEFT JOIN {modules} m ON m.id = cm.module WHERE cm.course = ?
                AND cm.completion > 0 LIMIT 1', array($course->id));

        $bdata['name'] = 'Test Booking 2';
        unset($bdata['completion']);
        unset($bdata['enablecompletion']);
        $bdata['completionmodule'] = $result->id;
        $booking2 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setUser($user3);
        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($user1->id, $course->id);
        $this->getDataGenerator()->enrol_user($user2->id, $course->id);
        $this->getDataGenerator()->enrol_user($user3->id, $course->id);

        $coursectx = context_course::instance($course->id);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option';
        $record->courseid = $course->id;
        $record->description = 'Test description';

        $option1 = self::getDataGenerator()->get_plugin_generator('mod_booking')->create_option(
                $record);
        $record->bookingid = $booking2->id;
        $option2 = self::getDataGenerator()->get_plugin_generator('mod_booking')->create_option(
                $record);

        $cmb1 = get_coursemodule_from_instance('booking', $booking1->id);
        $cmb2 = get_coursemodule_from_instance('booking', $booking2->id);

        $bookingopttion1 = new \mod_booking\booking_option($cmb1->id, $option1->id);
        $bookingopttion2 = new \mod_booking\booking_option($cmb2->id, $option2->id);

        $this->setUser($user1);
        $this->assertEquals(false, $bookingopttion1->can_rate());

        $bo1 = $DB->get_record('booking', array('id' => $booking1->id));
        $bo1->ratings = 1;
        $DB->update_record('booking', $bo1);
        $bookingopttion1 = new \mod_booking\booking_option($cmb1->id, $option1->id);
        $this->assertEquals(true, $bookingopttion1->can_rate());

        $bo1->ratings = 2;
        $DB->update_record('booking', $bo1);
        $bookingopttion1 = new \mod_booking\booking_option($cmb1->id, $option1->id);
        $this->assertEquals(false, $bookingopttion1->can_rate());

        $this->assertEquals(true, empty($bookingopttion1->option->shorturl));

        $bookingopttion1->user_submit_response($user1);
        $bookingopttion2->user_submit_response($user1);
        $bookingopttion2->user_submit_response($user2);

        $this->assertEquals(true, $bookingopttion1->can_rate());

        $bo1->ratings = 3;
        $DB->update_record('booking', $bo1);
        $bookingopttion1 = new \mod_booking\booking_option($cmb1->id, $option1->id);

        $this->assertEquals(false, $bookingopttion1->can_rate());

        $sink = $this->redirectEvents();
        $this->assertEquals(0, $bookingopttion1->is_activity_completed($user1->id));
        booking_activitycompletion(array($user1->id), $booking1, $cmb1->id, $option1->id);

        $events = $sink->get_events();

        $completion = new completion_info($course);
        $completiondata = $completion->get_data($cmb1);
        $this->assertEquals(1, $bookingopttion1->is_activity_completed($user1->id));
        $this->assertEquals(true, $bookingopttion1->can_rate());

        $bookingopttion2->delete_responses_activitycompletion();

        $this->assertEquals(1, $DB->count_records('booking_answers', array('optionid' => $option2->id)));
    }

}