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
use stdClass;
use mod_booking\importer\bookingoptionsimporter;
use tool_mocktesttime\time_mock;

/**
 * Class handling tests for booking importer.
 *
 * @package mod_booking
 * @category test
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class booking_importer_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
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
     * Test process_data of CSV import.
     *
     * @covers \mod_booking\importer\bookingoptionsimporter::execute_bookingoptions_csv_import
     *
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function test_csv_import_process_data(): void {
        $this->resetAfterTest();
        // It is important to set timezone to have all dates correct!
        $this->setTimezone('Europe/London');

        // Setup course.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create user(s).
        $teachers = [
            [
                'username' => 'teacher1',
                'firstname' => 'Teacher',
                'lastname' => '1',
                'email' => 'teacher1@example.com',
                'timezone' => 'Europe/London',
            ],
            [
                'username' => 'teacher2',
                'firstname' => 'Teacher',
                'lastname' => '2',
                'email' => 'teacher2@example.com',
                'timezone' => 'Europe/London',
            ],
        ];
        $teacher1 = $this->getDataGenerator()->create_user($teachers[0]);
        $teacher2 = $this->getDataGenerator()->create_user($teachers[1]);
        $teacherids = [$teacher1->id, $teacher2->id];
        $users = [
            [
                'username' => 'student1',
                'firstname' => 'Student',
                'lastname' => '1',
                'email' => 'user1@example.com',
                'timezone' => 'Europe/London',
            ],
        ];
        $user1 = $this->getDataGenerator()->create_user($users[0]);
        $userids = [$user1->id];

        $rcps = [
            [
                'username' => 'rcp1',
                'firstname' => 'RCP',
                'lastname' => '1',
                'email' => 'rcp1@example.com',
                'timezone' => 'Europe/London',
            ],
            [
                'username' => 'rcp2',
                'firstname' => 'RCP',
                'lastname' => '2',
                'email' => 'rcp2@example.com',
                'timezone' => 'Europe/London',
            ],
            [
                'username' => 'rcp3',
                'firstname' => 'RCP',
                'lastname' => '2',
                'email' => 'rcp3@example.com',
                'timezone' => 'Europe/London',
            ],
        ];
        $rcp1 = $this->getDataGenerator()->create_user($rcps[0]);
        $rcp2 = $this->getDataGenerator()->create_user($rcps[1]);
        $rcp3 = $this->getDataGenerator()->create_user($rcps[2]);
        $rcpids = [$rcp1->id, $rcp2->id, $rcp3->id];

        // Create booking settings prior create booking module in course: price categories and semester.
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $pricecat1 = $plugingenerator->create_pricecategory(
            ['ordernum' => '1', 'identifier' => 'default', 'name' => 'Price', 'defaultvalue' => '12', 'pricecatsortorder' => 1]
        );
        $pricecat2 = $plugingenerator->create_pricecategory(
            ['ordernum' => '2', 'identifier' => 'intern', 'name' => 'Intern', 'defaultvalue' => '13', 'pricecatsortorder' => 2]
        );
        $testsemester = $plugingenerator->create_semester(
            ['identifier' => 'fall2023', 'name' => 'Fall 2023', 'startdate' => '1695168000', 'enddate' => '1704067140']
        );
        // For tests startdate = bookingopeningtime = 20.09.2023 00:00 and enddate = bookingclosingtime = 31.12.2023 23:59 GMT.

        // Setup booking defaults and create booking course module.
        $bdata = ['name' => 'Test CSV Import of option', 'eventtype' => 'Test event', 'enablecompletion' => 1,
            'bookedtext' => ['text' => 'text'], 'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'], 'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'], 'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'],
            'notificationtext' => ['text' => 'text'], 'userleave' => ['text' => 'text'],
            'bookingpolicy' => 'bookingpolicy', 'tags' => '', 'completion' => 2,
            'showviews' => ['showall,showactive,mybooking,myoptions,optionsiamresponsiblefor,myinstitution'],
            'optionsfields' =>
            ['description', 'statusdescription', 'teacher', 'showdates', 'dayofweektime', 'location', 'institution', 'minanswers'],
            'semesterid' => $testsemester->id,
            'mergeparam' => 2,
        ];
        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $teacher1->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        // Finish to configure users (must be done from admin).
        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($teacher1->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($teacher2->id, $course->id, 'teacher');
        $this->getDataGenerator()->enrol_user($user1->id, $course->id, 'student');

        // Get coursemodule of bookjng instance.
        $cmb1 = get_coursemodule_from_instance('booking', $booking1->id);

        // Get booking instance.
        singleton_service::destroy_booking_singleton_by_cmid($cmb1->id);
        $bookingobj1 = singleton_service::get_instance_of_booking_by_bookingid($booking1->id);

        // Prepare import (should work in behalf of teacher).
        $this->setUser($teacher1);

        // Prepare import options.
        $formdata = new stdClass();
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
            file_get_contents($this->get_full_path_of_csv_file('options_coma_new', '02')),
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

        // This might fail when local_entities is installed.
        if (!class_exists('local_entities\entitiesrelation_handler')) {
            $this->assertEquals("TNMU", $option1->location);
        }

        // Check if the user is subscribed.
        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $ba = singleton_service::get_instance_of_booking_answers($settings);
        $this->assertEquals(MOD_BOOKING_STATUSPARAM_BOOKED, $ba->user_status($user1->id));

        // Create booking option object to get extra detsils.
        singleton_service::destroy_booking_option_singleton($option1->id);
        $bookingoptionobj = singleton_service::get_instance_of_booking_option($cmb1->id, $option1->id);

        // Verify teacher for 1st option.
        $opt1teachers = $bookingoptionobj->get_teachers();
        $this->assertEquals(2, count($settings->teacherids));
        $this->assertEmpty(array_diff($settings->teacherids, $teacherids));

        // Verify responsible contacts for 1st option.
        $this->assertEquals(1, count($settings->responsiblecontact));
        $this->assertEmpty(array_diff($settings->responsiblecontact, $rcpids));

        // Booking option must have sessions.
        $this->assertEquals(true, booking_utils::booking_option_has_optiondates($option1->id));
        // phpcs:ignore
        //$dates1 = $bookingoptionobj->return_array_of_sessions()); // Also works.
        $dates = dates_handler::return_array_of_sessions_datestrings($option1->id);
        $this->assertEquals("25 September 2023, 5:15 PM - 7:30 PM", $dates[0]);
        $this->assertEquals("25 December 2023, 5:15 PM - 7:30 PM", $dates[13]);
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

        // This might fail when local_entities is installed.
        if (!class_exists('local_entities\entitiesrelation_handler')) {
            $this->assertEquals("TNMU", $option3->location);
        }

        // Check if the user is subscribed.
        $settings = singleton_service::get_instance_of_booking_option_settings($option3->id);
        $ba = singleton_service::get_instance_of_booking_answers($settings);
        $this->assertEquals(MOD_BOOKING_STATUSPARAM_BOOKED, $ba->user_status($user1->id));

        // Create booking option object to get extra detsils.
        $bookingoptionobj = new booking_option($cmb1->id, $option3->id);
        // Verify teacher for 3rd option.
        $teacher3 = $bookingoptionobj->get_teachers();
        $this->assertEmpty($teacher3);

        // Verify responsible contacts for 3rd option.
        $this->assertEquals(2, count($settings->responsiblecontact));
        $this->assertEmpty(array_diff($settings->responsiblecontact, $rcpids));

        // Bookimg option must have sessions.
        $this->assertEquals(true, booking_utils::booking_option_has_optiondates($option3->id));
        $dates = dates_handler::return_array_of_sessions_datestrings($option3->id);
        $this->assertEquals("20 September 2023, 6:10 PM - 7:40 PM", $dates[0]);
        $this->assertEquals("27 December 2023, 6:10 PM - 7:40 PM", $dates[14]);
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
        return  __DIR__ . "/../fixtures/{$setname}{$test}.csv";
    }
}
