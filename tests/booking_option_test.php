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
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2017 Andraž Prinčič
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use coding_exception;
use mod_booking\option\dates_handler;
use mod_booking\price;
use mod_booking_generator;
use context_course;
use stdClass;
use mod_booking\utils\csv_import;
use mod_booking\importer\bookingoptionsimporter;

/**
 * Class handling tests for booking options.
 *
 * @package mod_booking
 * @category test
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_option_test extends advanced_testcase {

    /**
     * Tests set up.
     */
    public function setUp():void {
        $this->resetAfterTest();
    }

    /**
     * Tear Down.
     *
     * @return void
     *
     */
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

        $bdata = ['name' => 'Test Booking 1', 'eventtype' => 'Test event', 'enablecompletion' => 1,
            'bookedtext' => ['text' => 'text'], 'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'], 'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'], 'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'],
            'notificationtext' => ['text' => 'text'], 'userleave' => ['text' => 'text'],
            'bookingpolicy' => 'bookingpolicy', 'tags' => '', 'completion' => 2,
            'showviews' => ['mybooking,myoptions,showall,showactive,myinstitution'],
        ];
        // Setup test data.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

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
                AND cm.completion > 0 LIMIT 1', [$course->id]);

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
        $record->text = 'Test option1';
        $record->courseid = $course->id;
        $record->description = 'Test description';
        $record->optiondateid_1 = "0";
        $record->daystonotify_1 = "0";
        $record->coursestarttime_1 = 1703171160;
        $record->courseendtime_1 = 1734793560;
        $record->optiondateid_2 = "0";
        $record->daystonotify_2 = "0";
        $record->coursestarttime_2 = 1734793560;
        $record->courseendtime_2 = 1735793560;

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
        $this->resetAfterTest();
        // It is important to set timezone to have all dates correct!
        $this->setTimezone('Europe/London');

        // Setup course.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create user(s).
        $useremails = ['reinhold.brunhoelzl@univie.ac.at', 'ulrike.schultes@univie.ac.at'];
        $userdata = new stdClass;
        $userdata->email = $useremails[0];
        $userdata->timezone = 'Europe/London';
        $user1 = $this->getDataGenerator()->create_user($userdata); // Booking manager and teacher.
        $userdata->email = $useremails[1];
        $user2 = $this->getDataGenerator()->create_user($userdata); // Teacher.

        // Create booking settings prior create booking module in course: price categories and semester.
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $pricecat1 = $plugingenerator->create_pricecategory(
                ['ordernum' => '1', 'identifier' => 'default', 'name' => 'Price', 'defaultvalue' => '12']);
        $pricecat2 = $plugingenerator->create_pricecategory(
                ['ordernum' => '2', 'identifier' => 'intern', 'name' => 'Intern', 'defaultvalue' => '13']);
        $testsemester = $plugingenerator->create_semester(
                ['identifier' => 'fall2023', 'name' => 'Fall 2023', 'startdate' => '1695168000', 'enddate' => '1704067140']);
        // For tests startdate = bookingopeningtime = 20.09.2023 00:00 and enddate = bookingclosingtime = 31.12.2023 23:59 GMT.

        // Setup booking defaults and create booking course module.
        $bdata = ['name' => 'Test CSV Import of option', 'eventtype' => 'Test event', 'enablecompletion' => 1,
            'bookedtext' => ['text' => 'text'], 'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'], 'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'], 'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'],
            'notificationtext' => ['text' => 'text'], 'userleave' => ['text' => 'text'],
            'bookingpolicy' => 'bookingpolicy', 'tags' => '', 'completion' => 2,
            'showviews' => ['mybooking,myoptions,showall,showactive,myinstitution'],
            'optionsfields' =>
            ['description', 'statusdescription', 'teacher', 'showdates', 'dayofweektime', 'location', 'institution', 'minanswers'],
            'semesterid' => $testsemester->id,
            'mergeparam' => 2,
        ];
        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $user1->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        // Finish to configure users.
        $this->setUser($user1);

        $this->getDataGenerator()->enrol_user($user1->id, $course->id);
        $this->getDataGenerator()->enrol_user($user2->id, $course->id);

        $coursectx = context_course::instance($course->id);

        // Get coursemodule of bookjng instance.
        $cmb1 = get_coursemodule_from_instance('booking', $booking1->id);

        // Get booking instance.
        $bookingobj1 = new booking($cmb1->id);

        // Prepare import options.
        $formdata = new stdClass;
        $formdata->delimiter_name = 'comma';
        $formdata->enclosure = '"';
        $formdata->encoding = 'utf-8';
        $formdata->updateexisting = true;
        $formdata->dateparseformat = 'j.n.Y H:i:s';
        $formdata->cmid = $cmb1->id;
        // Create instance of csv_import class.
        $bookingcsvimport1 = new bookingoptionsimporter();

        // Perform import of CSV: 3 new booking options have to be created.
        $res = $bookingcsvimport1->execute_bookingoptions_csv_import(
                                    $formdata,
                                    file_get_contents($this->get_full_path_of_csv_file('options_coma_new', '01')),
        );
        // Check success of import process.
        $this->assertIsArray($res);
        $this->assertEmpty($res['errors']);
        $this->assertEquals(1, $res['success']);
        $this->assertEquals(3, $res['numberofsuccessfullyupdatedrecords']);
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
        $this->assertEquals("Spitalgasse 14 1090 Wien", $option1->institution);
        $this->assertEquals("MO 17:15 - 19:30", $option1->dayofweektime);
        $this->assertEquals(35, $option1->maxanswers);
        $this->assertEquals("monday", $option1->dayofweek);
        // phpcs:ignore
        //$this->assertEquals("TNMU", $option1->location);

        // Create booking option object to get extra detsils.
        $bookingoptionobj = new booking_option($cmb1->id, $option1->id);

        // Verify teacher for 1st option.
        $teacher1 = $bookingoptionobj->get_teachers();
        $teacher1 = array_shift($teacher1);
        $this->assertEquals($useremails[0], $teacher1->email);

        // Bookimg option must have sessions.
        $this->assertEquals(true, booking_utils::booking_option_has_optiondates($option1->id));
        // phpcs:ignore
        //$dates1 = $bookingoptionobj->return_array_of_sessions()); // Also works.
        $dates = dates_handler::return_array_of_sessions_datestrings($option1->id);
        $this->assertEquals("25 September 2023, 5:15 PM - 7:30 PM", $dates[0]);
        $this->assertEquals("25 December 2023, 5:15 PM - 7:30 PM", $dates[13]);
        $this->assertArrayNotHasKey(14, $dates);

        // Check prices.
        $optionprices = price::get_prices_from_cache_or_db('option', $option1->id);
        // The 'default' price.
        $priceoption = array_shift($optionprices);
        $this->assertEquals($pricecat1->identifier, $priceoption->pricecategoryidentifier);
        $this->assertEquals('79.00', $priceoption->price);
        // The 'intern' price.
        $priceoption = array_shift($optionprices);
        $this->assertEquals($pricecat2->identifier, $priceoption->pricecategoryidentifier);
        $this->assertEquals('89.00', $priceoption->price);

        // Get 3rd option.
        $option3 = $bookingobj1->get_all_options(0, 0, "0-Kondition Mit Musik");
        $this->assertEquals(1, count($option3));
        // Verify data of 1st option.
        $option3 = array_shift($option3);
        $this->assertEquals("pftr54", $option3->identifier);
        $this->assertEquals($bookingobj1->id, $option3->bookingid);
        $this->assertEmpty($option3->description);
        $this->assertEquals("Spitalgasse 14 1090 Wien", $option3->institution);
        $this->assertEquals("We 18:10 - 19:40", $option3->dayofweektime);
        $this->assertEquals(60, $option3->maxanswers);
        $this->assertEquals("wednesday", $option3->dayofweek);
        // phpcs:ignore
        //$this->assertEquals("TNMU", $option3->location);

        // Create booking option object to get extra detsils.
        $bookingoptionobj = new booking_option($cmb1->id, $option3->id);
        // Verify teacher for 1st option.
        $teacher3 = $bookingoptionobj->get_teachers();
        $this->assertEmpty($teacher3);

        // Bookimg option must have sessions.
        $this->assertEquals(true, booking_utils::booking_option_has_optiondates($option3->id));
        $dates = dates_handler::return_array_of_sessions_datestrings($option3->id);
        $this->assertEquals("20 September 2023, 6:10 PM - 7:40 PM", $dates[0]);
        $this->assertEquals("27 December 2023, 6:10 PM - 7:40 PM", $dates[14]);
        $this->assertArrayNotHasKey(15, $dates);

        // Check prices.
        $optionprices = price::get_prices_from_cache_or_db('option', $option3->id);
        // The 'default' price.
        $priceoption = array_shift($optionprices);
        $this->assertEquals($pricecat1->identifier, $priceoption->pricecategoryidentifier);
        $this->assertEquals('79.00', $priceoption->price);
        // The 'intern' price.
        $priceoption = array_shift($optionprices);
        $this->assertEquals($pricecat2->identifier, $priceoption->pricecategoryidentifier);
        $this->assertEquals('89.00', $priceoption->price);
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
