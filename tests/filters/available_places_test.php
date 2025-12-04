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

namespace mod_booking\filters;

use local_wunderbyte_table\external\load_data;
use local_wunderbyte_table\filters\types\customfieldfilter;
use local_wunderbyte_table\wunderbyte_table;
use mod_booking\booking;
use mod_booking\booking_bookit;
use mod_booking\bo_availability\bo_info;
use mod_booking\singleton_service;
use mod_booking\table\bookingoptions_wbtable;
use mod_booking_generator;
use tool_mocktesttime\time_mock;

/**
 * Tests for Booking
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @runInSeparateProcess
 * @runTestsInSeparateProcesses
 */
final class available_places_test extends \advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
        $_POST = [];
        $_GET = [];
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        // Mandatory clean-up.
        singleton_service::destroy_instance();
        $_POST = [];
        $_GET = [];
    }

    /**
     * We create 10 booking options in a single instance of the booking module.
     *     - 2 options have maxanswers = 1,
     *     - 2 options have maxanswers = 2,
     *     - the others have more available places.
     *
     * Then we book options for the first student.
     * We apply the available places filter and check whether the table returns
     * the expected results: 2 fully booked options and 8 free-to-book options.
     *
     * Next, we book options for the second student. We expect to see 4 items
     * in the table when filtering by the "fully booked" option.
     *
     * Then we add another booking option with maxanswers = 1 and book it for the first student.
     * There should now be one more item (a total of 5) in the table when filtering by
     * the "fully booked" option.
     *
     * We instantiate two tables in order to test both the general functionality
     * and the table functionality with AJAX and pagination.
     *
     * @covers \local_wunderbyte_table\filters\types\customfieldfilter::available_places
     *
     * @return void
     */
    public function test_if_filter_works(): void {
        global $DB;
        $bdata = self::provide_bdata();

        // Setup test data.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1, 'shortname' => 'course1']);
        // Create users.
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);
        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course->id);
        $this->getDataGenerator()->enrol_user($student2->id, $course->id);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id);

        $createdoptions = [];
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create booking options.
        foreach ($bdata['standardbookingoptions'] as $option) {
            $record = (object) $option;
            $record->bookingid = $booking->id;
            $option1 = $plugingenerator->create_option($record);
            $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
            $cmid = $settings->cmid;
            $createdoptions[$option1->id] = $option;
        }

        // We should have 10 options.
        $bookingoptions = $DB->get_records('booking_options');
        $this->assertCount(10, $bookingoptions);

        // We should have 2 options with max answer = 1.
        $bookingoptions = $DB->get_records('booking_options', ['maxanswers' => 1]);
        $this->assertCount(2, $bookingoptions);

        // We should have 2 options with max answer = 2.
        $bookingoptions = $DB->get_records('booking_options', ['maxanswers' => 2]);
        $this->assertCount(2, $bookingoptions);

        // We should have 2 options with max answer = 2.
        $bookingoptions = $DB->get_records('booking_options', ['maxanswers' => 0]);
        $this->assertCount(6, $bookingoptions);

        // We should have no answers yet.
        $bookinganswers = $DB->get_records('booking_answers');
        $this->assertEmpty($bookinganswers);

        $this->setUser($student1);

        // Book all options for student 1.
        foreach ($createdoptions as $optionid => $optiondata) {
            $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
            $boinfo = new bo_info($settings);
            booking_bookit::bookit('option', $settings->id, $student1->id);
            booking_bookit::bookit('option', $settings->id, $student1->id);
            [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
            $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);
        }

        $this->setAdminUser();

        // We should have 10 answers yet.
        $bookinganswers = $DB->get_records('booking_answers');
        $this->assertCount(10, $bookinganswers);

        // Create the table.
        $table1 = new bookingoptions_wbtable("cmid_{$cmid}_showonetable");
        $booking = singleton_service::get_instance_of_booking_by_cmid($cmid);
        // Get options of a single booking module.
        $wherearray = [
            'bookingid' => (int) $booking->id,
        ];

        $availableplacesfilter = available_places::get();
        $this->assertInstanceOf(customfieldfilter::class, $availableplacesfilter);

        // Add available_places filter.
        $table1->add_filter($availableplacesfilter);

        [$fields, $from, $where, $params, $filter] =
                booking::get_options_filter_sql(0, 0, '', '', $booking->context, [], $wherearray);
        $table1->set_filter_sql($fields, $from, $where, $filter, $params);

        // Execute table logic to fetch records.
        // We have 10 options totally. We expect to see all the booking options before applying any filter.
        $renderedtable = $table1->outhtml(10, true);
        $this->assertNotEmpty($renderedtable);
        preg_match('/<div[^>]*\sdata-encodedtable=["\']?([^"\'>\s]+)["\']?/i', $renderedtable, $matches);
        $encodedtablestring = $matches[1];

        // We get table from the cache.
        $table = wunderbyte_table::instantiate_from_tablecache_hash($encodedtablestring);
        $table->printtable($table->pagesize, $table->useinitialsbar, $table->downloadhelpbutton);
        $this->assertEquals(10, $table->totalrows);

        // Now filter the fully booked records.
        // As we have 2 options with maxanswer=1 so we expect to see 2 records.
        $_POST['wbtfilter'] = '{"availableplaces":["0"]}';
        $table = wunderbyte_table::instantiate_from_tablecache_hash($encodedtablestring);
        $table->printtable($table->pagesize, $table->useinitialsbar, $table->downloadhelpbutton);
        $this->assertEquals(2, $table->totalrows);

        // Now filter the free to book records.
        // As we have 8 options with maxanswer <> 1 so we expect to see 8 records.
        $_POST['wbtfilter'] = '{"availableplaces":["1"]}';
        $table = wunderbyte_table::instantiate_from_tablecache_hash($encodedtablestring);
        $table->printtable($table->pagesize, $table->useinitialsbar, $table->downloadhelpbutton);
        $this->assertEquals(8, $table->totalrows);

        // Now we book the 8 other options which have maxanswer > 1 for the student 2.
        $otheroptions = array_filter($createdoptions, fn($option) => ($option['maxanswers'] != 1));
        $this->assertCount(8, $otheroptions); // We have 8 options with maxansweras not equal to 1.
        $this->setUser($student2);
        foreach ($otheroptions as $optionid => $optiondata) {
            $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
            $boinfo = new bo_info($settings);
            booking_bookit::bookit('option', $settings->id, $student2->id);
            booking_bookit::bookit('option', $settings->id, $student2->id);
            [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
            $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);
        }

        // Now filter the fully booked records.
        $_POST['wbtfilter'] = '{"availableplaces":["0"]}';
        $table = wunderbyte_table::instantiate_from_tablecache_hash($encodedtablestring);
        $table->printtable($table->pagesize, $table->useinitialsbar, $table->downloadhelpbutton);
        $this->assertEquals(4, $table->totalrows);

        // Now filter the free to book records.
        $_POST['wbtfilter'] = '{"availableplaces":["1"]}';
        $table = wunderbyte_table::instantiate_from_tablecache_hash($encodedtablestring);
        $table->printtable($table->pagesize, $table->useinitialsbar, $table->downloadhelpbutton);
        $this->assertEquals(6, $table->totalrows);

        $this->setAdminUser();
        // Now we add a new option with max answer = 1.
        $record = [
            'text' => 'Option 11 - maxanswer = 1',
            'description' => 'Test Booking Option',
            'identifier' => 'option11',
            'maxanswers' => 1,
            'maxoverbooking' => 0,
            'bookingid' => $booking->id,
        ];
        $newoption = $plugingenerator->create_option($record);

        $bookingoptions = $DB->get_records('booking_options');
        $this->assertCount(11, $bookingoptions);

        // Now filter the fully booked records.records.
        $_POST['wbtfilter'] = '{"availableplaces":["0"]}';
        $table = wunderbyte_table::instantiate_from_tablecache_hash($encodedtablestring);
        $table->printtable($table->pagesize, $table->useinitialsbar, $table->downloadhelpbutton);
        $this->assertEquals(4, $table->totalrows);

        // Now filter the free to book records.
        $_POST['wbtfilter'] = '{"availableplaces":["1"]}';
        $table = wunderbyte_table::instantiate_from_tablecache_hash($encodedtablestring);
        $table->printtable($table->pagesize, $table->useinitialsbar, $table->downloadhelpbutton);
        $this->assertEquals(7, $table->totalrows);

        $this->setUser($student1);
        // Book the new option for the student 1. Then check again the table.
        $settings = singleton_service::get_instance_of_booking_option_settings($newoption->id);
        $boinfo = new bo_info($settings);
        booking_bookit::bookit('option', $settings->id, $student1->id);
        booking_bookit::bookit('option', $settings->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Now filter the fully booked records.
        $_POST['wbtfilter'] = '{"availableplaces":["0"]}';
        $table = wunderbyte_table::instantiate_from_tablecache_hash($encodedtablestring);
        $table->printtable($table->pagesize, $table->useinitialsbar, $table->downloadhelpbutton);
        $this->assertEquals(5, $table->totalrows);

        // Now filter the free to book records.
        $_POST['wbtfilter'] = '{"availableplaces":["1"]}';
        $table = wunderbyte_table::instantiate_from_tablecache_hash($encodedtablestring);
        $table->printtable($table->pagesize, $table->useinitialsbar, $table->downloadhelpbutton);
        $this->assertEquals(6, $table->totalrows);

        // Now we want to check the pagination and AJAX rendering when the available places filter is applied..
        unset($_POST['wbtfilter']);
        unset($_GET['wbtfilter']);
        // Instantiate a table with a pagesize of 2.
        $table2 = new bookingoptions_wbtable("cmid_{$cmid}_ajaxtest");
        $table2->add_filter($availableplacesfilter);
        [$fields, $from, $where, $params, $filter] =
                booking::get_options_filter_sql(0, 0, '', '', $booking->context, [], $wherearray, null, [MOD_BOOKING_STATUSPARAM_BOOKED], '1=1');
        $table2->set_filter_sql($fields, $from, $where, $filter, $params);
        $table2->pageable(true);
        $table2->outhtml(2, true);
        $encodedtable = $table2->return_encoded_table();

        $maxanswerscolumns = [];

        $_POST['wbtfilter'] = '{"availableplaces":["0"]}';
        $_GET['wbtfilter'] = '{"availableplaces":["0"]}';
        // We fetch 3 pages because we know there are only 5 fully booked records and the page size is 2.
        // Therefore, we expect to retrieve a total of 5 records by calling the external API (load_data) 3 times.
        for ($page = 0; $page < 3; $page++) {
            $result = load_data::execute($encodedtable, $page);
            $content = json_decode($result['content']);

            for ($i = 0; $i < count($content->table->rows); $i++) {
                $row = $content->table->rows[$i];
                // We get the maxanswers column and then check whether the pages contain only fully booked records.
                $filteredcolumns = array_filter($row->cardbody, fn($item): bool => $item->key === 'maxanswers');
                // We are sure that $filteredcolumns has only one record, and that this record is the maxanswers column.
                $maxanswerscolumns[] = reset($filteredcolumns);
            }
        }

        // Since we have 5 fully booked records, we expect to retrieve only 5 records from the external API.
        $this->assertCount(5, $maxanswerscolumns);

        // Here we check the maxanswers values — those 5 records should have maxanswers equal to 1 or 2.
        foreach ($maxanswerscolumns as $column) {
            $this->assertContains($column->value, ["1", "2"]);
        }
    }

    /**
     * Provides the data that's constant for the test.
     *
     * @return array
     *
     */
    private static function provide_bdata(): array {
        return [
            'name' => 'Booking Module 1',
            'eventtype' => 'Test event',
            'enablecompletion' => 1,
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
            'completion' => 2,
            'showviews' => ['showall'],
            'standardbookingoptions' => [
                [
                    'text' => 'Option 1 - maxanswer = 1',
                    'description' => 'Test Booking Option',
                    'identifier' => 'option1',
                    'maxanswers' => 1,
                    'maxoverbooking' => 0,
                ],
                [
                    'text' => 'Option 2 - maxanswer = 1',
                    'description' => 'Test Booking Option',
                    'identifier' => 'option2',
                    'maxanswers' => 1,
                    'maxoverbooking' => 1,
                ],
                [
                    'text' => 'Option 3 - maxanswer = 2',
                    'description' => 'Test Booking Option',
                    'identifier' => 'option3',
                    'maxanswers' => 2,
                    'maxoverbooking' => 10,
                ],
                [
                    'text' => 'Option 4 - maxanswer = 2',
                    'description' => 'Test Booking Option',
                    'identifier' => 'option4',
                    'maxanswers' => 2,
                    'maxoverbooking' => 0,
                ],
                [
                    'text' => 'Option 5 - maxanswer = 0',
                    'description' => 'Test Booking Option',
                    'identifier' => 'option5',
                    'maxanswers' => 0,
                ],
                [
                    'text' => 'Option 6 - maxanswer = 0',
                    'description' => 'Test Booking Option',
                    'identifier' => 'option6',
                    'maxanswers' => 0,
                ],
                [
                    'text' => 'Option 7 - maxanswer = 0',
                    'description' => 'Test Booking Option',
                    'identifier' => 'option7',
                    'maxanswers' => 0,
                ],
                [
                    'text' => 'Option 8 - maxanswer = 0',
                    'description' => 'Test Booking Option',
                    'identifier' => 'option8',
                    'maxanswers' => 0,
                ],
                [
                    'text' => 'Option 9 - maxanswer = 0',
                    'description' => 'Test Booking Option',
                    'identifier' => 'option9',
                    'maxanswers' => 0,
                ],
                [
                    'text' => 'Option 10 - maxanswer = 0',
                    'description' => 'Test Booking Option',
                    'identifier' => 'option10',
                    'maxanswers' => 0,
                ],
            ],
        ];
    }
}
