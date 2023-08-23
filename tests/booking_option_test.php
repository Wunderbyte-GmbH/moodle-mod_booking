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
 * @copyright 2017 Andraž Prinčič <atletek@gmail.com>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use coding_exception;
use mod_booking\option\dates_handler;
use mod_booking_generator;
use context_course;
use stdClass;
use mod_booking\utils\csv_import;

class booking_option_test extends advanced_testcase {

    /**
     * Tests set up.
     */
    public function setUp():void {
        $this->resetAfterTest();
    }

    public function tearDown():void {
    }

    /**
     * Test delete responses.
     *
     * @covers ::delete_responses_activitycompletion
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function test_delete_responses_activitycompletion() {
        global $DB, $CFG;

        $CFG->enablecompletion = 1;

        $bdata = array('name' => 'Test Booking 1', 'eventtype' => 'Test event', 'enablecompletion' => 1,
            'bookedtext' => array('text' => 'text'), 'waitingtext' => array('text' => 'text'),
            'notifyemail' => array('text' => 'text'), 'statuschangetext' => array('text' => 'text'),
            'deletedtext' => array('text' => 'text'), 'pollurltext' => array('text' => 'text'),
            'pollurlteacherstext' => array('text' => 'text'),
            'notificationtext' => array('text' => 'text'), 'userleave' => array('text' => 'text'),
            'bookingpolicy' => 'bookingpolicy', 'tags' => '', 'completion' => 2,
            'showviews' => ['mybooking,myoptions,showall,showactive,myinstitution']);
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

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($record);
        $record->bookingid = $booking2->id;

        $cmb1 = get_coursemodule_from_instance('booking', $booking1->id);

        $bookingoption1 = singleton_service::get_instance_of_booking_option($cmb1->id, $option1->id);

        $this->setUser($user1);
        $this->assertEquals(false, $bookingoption1->can_rate());
    }

    /**
     * Test process_data of CSV import.
     *
     * @covers \csv_import->process_data
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function test_csv_import_process_data() {
        $bdata = array('name' => 'Test Booking 1', 'eventtype' => 'Test event', 'enablecompletion' => 1,
            'bookedtext' => array('text' => 'text'), 'waitingtext' => array('text' => 'text'),
            'notifyemail' => array('text' => 'text'), 'statuschangetext' => array('text' => 'text'),
            'deletedtext' => array('text' => 'text'), 'pollurltext' => array('text' => 'text'),
            'pollurlteacherstext' => array('text' => 'text'),
            'notificationtext' => array('text' => 'text'), 'userleave' => array('text' => 'text'),
            'bookingpolicy' => 'bookingpolicy', 'tags' => '', 'completion' => 2,
            'showviews' => ['mybooking,myoptions,showall,showactive,myinstitution']);
        // Setup test data.
        $course = $this->getDataGenerator()->create_course(array('enablecompletion' => 1));

        // Create user(s).
        $useremails = array('reinhold.brunhoelzl@univie.ac.at', 'ulrike.schultes@univie.ac.at');
        $userdata = new stdClass;
        $userdata->email = $useremails[0];
        $user1 = $this->getDataGenerator()->create_user($userdata); // Booking manager and teacher.
        $userdata->email = $useremails[1];
        $user2 = $this->getDataGenerator()->create_user($userdata); // Teacher.

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $user1->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);
        $this->setUser($user1);
        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($user1->id, $course->id);

        $cmb1 = get_coursemodule_from_instance('booking', $booking1->id);

        // Get booking instance.
        $bookingobj1 = singleton_service::get_instance_of_booking_by_cmid($cmb1->id);

        // Prepare import options.
        $formdata = new stdClass;
        $formdata->delimiter_name = 'comma';
        $formdata->enclosure = '"';
        $formdata->encoding = 'utf-8';
        $formdata->updateexisting = true;
        $formdata->dateparseformat = 'j.n.Y H:i:s';
        // Create instance of csv_import class.
        $bookingcsvimport1 = new csv_import($bookingobj1);
        // Perform import.
        // Such as no identifiers in sample - 3 new booking options have to be created.
        $res = $bookingcsvimport1->process_data(
                                    file_get_contents($this->get_full_path_of_csv_file('options_coma_new', '00')),
                                    $formdata
                                );
        // Check success of import process.
        $this->assertEquals(true, $res);
        // Check actual records count.
        $this->assertEquals(3, $bookingobj1->get_all_options_count());
        // Get 1st option.
        $option1 = $bookingobj1->get_all_options(0, 0, "0-Allgemeines Turnen");
        $this->assertEquals(1, count($option1));
        // Verify general data of 1st option.
        $option1 = array_shift($option1);
        $this->assertEquals("pftr52", $option1->identifier);
        $this->assertEquals($bookingobj1->id, $option1->bookingid);
        $this->assertEquals("Vorwiegend Outdoor", $option1->description);
        $this->assertEquals("TNMU", $option1->location);
        $this->assertEquals("Spitalgasse 14 1090 Wien", $option1->institution);
        $this->assertEquals("MO 17:15 - 19:30", $option1->dayofweektime);
        $this->assertEquals(1, $option1->limitanswers);
        $this->assertEquals(10800, $option1->duration);
        $this->assertEquals("monday", $option1->dayofweek);
        $this->assertEquals(35, $option1->maxanswers);

        // Create booking option object to get extra detsils.
        $bookingoptionobj = new booking_option($cmb1->id, $option1->id);

        // Verify teacher for 1st option.
        $teacher1 = $bookingoptionobj->get_teachers();
        $teacher1 = array_shift($teacher1);
        $this->assertEquals($useremails[0], $teacher1->email);

        // Bookimg option must have sessions.
        //$this->assertEquals(true, booking_utils::booking_option_has_optiondates($option1->id));
        //var_dump($bookingoptionobj->return_array_of_sessions());
        $dates1 = dates_handler::return_array_of_sessions_datestrings($option1->id);
        //var_dump($dates1);

        // Get 3rd option.
        $option3 = $bookingobj1->get_all_options(0, 0, "0-Kondition Mit Musik");
        $this->assertEquals(1, count($option3));
        // Verify data of 1st option.
        $option3 = array_shift($option3);
        $this->assertEquals("pftr54", $option3->identifier);
        $this->assertEquals($bookingobj1->id, $option3->bookingid);
        $this->assertEmpty($option3->description);
        $this->assertEquals("TNMU", $option3->location);
        $this->assertEquals("Spitalgasse 14 1090 Wien", $option3->institution);
        $this->assertEquals("We 18:10 - 19:40", $option3->dayofweektime);
        $this->assertEquals(1, $option3->limitanswers);
        $this->assertEquals(7200, $option3->duration);
        $this->assertEquals("wednesday", $option3->dayofweek);
        $this->assertEquals(60, $option3->maxanswers);

        // Create booking option object to get extra detsils.
        $bookingoptionobj = new booking_option($cmb1->id, $option3->id);
        // Verify teacher for 1st option.
        $teacher3 = $bookingoptionobj->get_teachers();
        $this->assertEmpty($teacher3);

        // Bookimg option must have sessions.
        //$this->assertEquals(true, booking_utils::booking_option_has_optiondates($option3->id));
        //var_dump($bookingoptionobj->optiontimes);
        //var_dump($bookingoptionobj->return_array_of_sessions());
    }

    /**
     * Get full path of CSV file.
     *
     * @param string $setname
     * @param string $test
     * @return string full path of file.
     */
    protected function get_full_path_of_csv_file(string $setname, string $test): string {
        return  __DIR__."/fixtures/{$setname}{$test}.csv";
    }
}
