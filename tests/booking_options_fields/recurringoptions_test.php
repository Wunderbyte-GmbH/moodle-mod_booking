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
use mod_booking\option\fields_info;
use mod_booking\price;
use mod_booking_generator;
use mod_booking\bo_availability\bo_info;
use local_shopping_cart\shopping_cart;
use local_shopping_cart\shopping_cart_history;
use local_shopping_cart\local\cartstore;
use local_shopping_cart\output\shoppingcart_history_list;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once($CFG->dirroot . '/mod/booking/classes/price.php');

/**
 * Class handling tests for booking options.
 *
 * @package mod_booking
 * @category test
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
final class recurringoptions_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Test creation and update of recurring options.
     *
     * @covers \condition\bookitbutton::is_available
     * @covers \condition\alreadybooked::is_available
     * @covers \condition\fullybooked::is_available
     * @covers \condition\confirmation::render_page
     * @covers \option\fields\recurringoptions::save_data
     * @covers \option\fields\recurringoptions::definition_after_data
     * @covers \option\fields\recurringoptions::update_options
     * @covers \option\fields\recurringoptions::update_records
     * @covers \option\fields\recurringoptions::find_constant_delta
     * @covers \booking_option::update
     *
     * @param array $data
     * @param array $expected
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_create_recurrignoptions(array $data, array $expected): void {
        global $DB, $CFG;
        $bdata = self::provide_bdata();

        // Setup test data.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $admin = $this->getDataGenerator()->create_user();
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course->id);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id);

        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $pricecategorydata1 = (object) [
            'ordernum' => 1,
            'name' => 'default',
            'identifier' => 'default',
            'defaultvalue' => 30,
            'pricecatsortorder' => 1,
        ];
        $plugingenerator->create_pricecategory($pricecategorydata1);
        $encodedkey = bin2hex($pricecategorydata1->identifier);

        // Create an initial booking option.
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1';
        $record->courseid = $course->id;
        $record->importing = 1;
        $record->coursestarttime = '2025-01-01 10:00:00';
        $record->courseendtime = '2025-01-01 12:00:00';
        $record->useprice = 1;
        $record->default = 50;

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        // To avoid retrieving the singleton with the wrong settings, we destroy it.
        singleton_service::destroy_booking_singleton_by_cmid($settings->cmid);

        // Update option to trigger recurrence.
        $record->id = $option1->id;
        $record->cmid = $settings->cmid;
        $record->importing = 1;

        $record->repeatthisbooking = $data['repeatthisbooking'];
        $record->howmanytimestorepeat = $data['howmanytimestorepeat'];
        $record->howoftentorepeat = $data['howoftentorepeat'];
        $record->requirepreviousoptionstobebooked = $data['requirepreviousoptionstobebooked'];

        booking_option::update($record);

        // Check that 3 options exist (original + 2 recurrences).
        $createdoptions = $DB->get_records('booking_options', ['bookingid' => $booking1->id]);
        $this->assertCount($expected['totaloptions'], $createdoptions, 'Recurring options were not created correctly.');

        // Fetch created options and check naming.
        $optiondates = $DB->get_records_sql(
            "SELECT id, text, coursestarttime, courseendtime FROM {booking_options} WHERE bookingid = ? ORDER BY id ASC",
            [$booking1->id]
        );

        $optionarray = array_values($optiondates);
        $this->assertEquals('Test option1', $optionarray[0]->text);
        $this->assertEquals('Test option1', $optionarray[1]->text);
        $this->assertEquals('Test option1', $optionarray[2]->text);

        $expectedstarttime = strtotime($record->coursestarttime);

        $this->assertEquals($expectedstarttime, $optionarray[0]->coursestarttime);
        $this->assertEquals(strtotime($expected['delta1'], $expectedstarttime), $optionarray[1]->coursestarttime);
        $this->assertEquals(strtotime($expected['delta2'], $expectedstarttime), $optionarray[2]->coursestarttime);

        $expectedendttime = strtotime($record->courseendtime);

        $this->assertEquals($expectedendttime, $optionarray[0]->courseendtime);
        $this->assertEquals(strtotime($expected['delta1'], $expectedendttime), $optionarray[1]->courseendtime);
        $this->assertEquals(strtotime($expected['delta2'], $expectedendttime), $optionarray[2]->courseendtime);

        $price = price::get_price('option', $optionarray[0]->id);
        $priceoriginal = price::get_price('option', $record->id);

        $this->assertEquals($price['price'], $priceoriginal['price']);

        // Logic regarding changes made on parent and reflecting on children.
        // Unset keys regarding repeating, coursestarttime and courseendtime.
        unset($record->repeatthisbooking, $record->howmanytimestorepeat, $record->howoftentorepeat);
        unset($record->coursestarttime);
        unset($record->courseendtime);

        // Update the parent option with new values and apply to children.
        $record->maxanswers = 20;
        $record->maxoverbooking = 10;
        $record->text = 'Test Parent';
        $record->description = 'Test Booking Description';
        $record->coursestarttime = strtotime('2025-01-03 10:00:00');
        $record->courseendtime = strtotime('2025-30-03 10:00:00');
        $record->daystonotify_1 = "0";
        $record->coursestarttime_1 = strtotime('2025-01-03 10:00:00');
        $record->courseendtime_1 = strtotime('2025-15-03 10:00:00');
        $record->daystonotify_2 = "0";
        $record->coursestarttime_2 = strtotime('2025-29-03 10:00:00');
        $record->courseendtime_2 = strtotime('2025-30-03 10:00:00');
        $record->apply_to_children = 1;
        booking_option::update($record);

        // Fetch updated options.
        $updatedoptions = $DB->get_records_sql(
            "SELECT id, parentid, maxanswers, maxoverbooking, text, description, coursestarttime, courseendtime
            FROM {booking_options}
            WHERE bookingid = ? ORDER BY id ASC",
            [$booking1->id]
        );

        $parentid = $optionarray[0]->id;

        $updatedarray = array_values($updatedoptions);

        foreach ($updatedarray as $index => $child) {
            if ($child->parentid == $parentid) {
                $this->assertEquals(
                    $record->maxanswers,
                    $child->maxanswers,
                    "Child {$child->id} maxanswers not updated correctly."
                );
                $this->assertEquals(
                    $record->maxoverbooking,
                    $child->maxoverbooking,
                    "Child {$child->id} maxoverbooking not updated correctly."
                );
                $this->assertEquals(
                    $record->text,
                    $child->text,
                    "Child {$child->id} title not updated correctly."
                );
                $this->assertEquals(
                    $record->description,
                    $child->description,
                    "Child {$child->id} description not updated correctly."
                );

                // Verify if all sessions were updated correctly.
                $childdata = (object)[
                    'id' => $child->id,
                    'cmid' => $record->cmid,
                ];
                fields_info::set_data($childdata);
                [$childdates, $highesindexchild] = dates::get_list_of_submitted_dates((array)$childdata);
                foreach ($childdates as $optiondate) {
                    if (
                        empty($optiondate->coursestarttime)
                        && empty($optiondate->courseendtime)
                    ) {
                        continue;
                    }
                    $this->assertTrue(in_array($optiondate->coursestarttime, (array)$record));
                    $this->assertTrue(in_array($optiondate->courseendtime, (array)$record));
                }
                // Verify that previouslybooked condition was applied.
                if ($expected['previouslybooked']) {
                    $this->assertNotEmpty($childdata->bo_cond_previouslybooked_restrict);
                    // This could be extended to make sure, it's really the right optionids here.
                    $this->assertIsNumeric($childdata->bo_cond_previouslybooked_optionid);
                } else {
                    $this->assertFalse(property_exists($childdata, 'bo_cond_previouslybooked_restrict'));
                }
            }
        }
        // Update siblings.
        $children = array_filter($updatedoptions, fn ($r) => !empty($r->parentid));
        $firstchild = reset($children);

        $firstchild->cmid = $settings->cmid;
        $firstchild->maxanswers = 30;
        $firstchild->maxoverbooking = 30;
        $firstchild->text = 'Test Sibling';
        $firstchild->description = 'Test Booking Description Sibling Change';
        $firstchild->coursestarttime = strtotime('2025-01-05 10:00:00');
        $firstchild->courseendtime = strtotime('2025-30-05 10:00:00');
        $firstchild->daystonotify_1 = "0";
        $firstchild->coursestarttime_1 = strtotime('2025-01-05 10:00:00');
        $firstchild->courseendtime_1 = strtotime('2025-15-05 10:00:00');
        $firstchild->daystonotify_2 = "0";
        $firstchild->coursestarttime_2 = strtotime('2025-29-05 10:00:00');
        $firstchild->courseendtime_2 = strtotime('2025-30-05 10:00:00');
        $firstchild->apply_to_siblings = 1;
        booking_option::update($firstchild);

        $updated = array_filter($children, fn($c) => $c->id !== $firstchild->id);
        $select = "";
        $conditions = [];
        $counter = 1;
        foreach ($updated as $key => $record) {
            if (!empty($select)) {
                $select .= " OR ";
            }
            $select .= "id = :id$counter";
            $conditions["id$counter"] = $key;
            $counter++;
        }
        $updatedoptions = $DB->get_records_select('booking_options', $select, $conditions);
        foreach ($updatedoptions as $index => $child) {
            $this->assertEquals(
                $firstchild->maxanswers,
                $child->maxanswers,
                "Child {$child->id} maxanswers not updated correctly."
            );
            $this->assertEquals(
                $firstchild->maxoverbooking,
                $child->maxoverbooking,
                "Child {$child->id} maxoverbooking not updated correctly."
            );
            $this->assertEquals(
                $firstchild->text,
                $child->text,
                "Child {$child->id} title not updated correctly."
            );
            $this->assertEquals(
                $firstchild->description,
                $child->description,
                "Child {$child->id} description not updated correctly."
            );

            // Verify if all sessions were updated correctly.
            $updateddata = (object)[
                'id' => $firstchild->id,
                'cmid' => $settings->cmid,
            ];
            fields_info::set_data($updateddata);
            [$childdates, $highesindexchild] = dates::get_list_of_submitted_dates((array)$updateddata);
            foreach ($childdates as $optiondate) {
                if (
                    empty($optiondate->coursestarttime)
                    && empty($optiondate->courseendtime)
                ) {
                    continue;
                }
                $this->assertTrue(in_array($optiondate->coursestarttime, (array)$firstchild));
                $this->assertTrue(in_array($optiondate->courseendtime, (array)$firstchild));
            }
        }
    }

    /**
     * Test allchildrenaction to see if unlinking and deletion of children is working as expected.
     *
     * @covers \condition\bookitbutton::is_available
     * @covers \condition\alreadybooked::is_available
     * @covers \condition\fullybooked::is_available
     * @covers \condition\confirmation::render_page
     * @covers \option\fields\recurringoptions::save_data
     * @covers \option\fields\recurringoptions::definition_after_data
     * @covers \option\fields\recurringoptions::update_options
     * @covers \option\fields\recurringoptions::update_records
     * @covers \option\fields\recurringoptions::find_constant_delta
     * @covers \option\fields\recurringoptions::allchildrenaction
     * @covers \booking_option::update
     *
     * @param mixed $data
     * @param mixed $expected
     *
     * @dataProvider booking_allchildrenaction_settings_provider
     *
     * @return void
     *
     */
    public function test_allchildrenaction($data, $expected): void {
        global $DB, $CFG;
        $bdata = self::provide_bdata();

        // Setup test data.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $admin = $this->getDataGenerator()->create_user();
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course->id);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id);

        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $pricecategorydata1 = (object) [
            'ordernum' => 1,
            'name' => 'default',
            'identifier' => 'default',
            'defaultvalue' => 30,
            'pricecatsortorder' => 1,
        ];
        $plugingenerator->create_pricecategory($pricecategorydata1);
        $encodedkey = bin2hex($pricecategorydata1->identifier);

        // Create an initial booking option.
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1';
        $record->courseid = $course->id;
        $record->importing = 1;
        $record->coursestarttime = '2025-01-01 10:00:00';
        $record->courseendtime = '2025-01-01 12:00:00';
        $record->useprice = 1;
        $record->default = 50;

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($record);

        // One bookingoption was created.
        $bookingoptions = $DB->get_records('booking_options');
        $this->assertEquals(1, count($bookingoptions));

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        // To avoid retrieving the singleton with the wrong settings, we destroy it.
        singleton_service::destroy_booking_singleton_by_cmid($settings->cmid);

        // Update option to trigger recurrence.
        $record->id = $option1->id;
        $record->cmid = $settings->cmid;
        $record->importing = 1;

        // Standard data is sufficent for this test.
        $record->repeatthisbooking = 1;
        $record->howmanytimestorepeat = 2;
        $record->howoftentorepeat = 7 * 24 * 60 * 60; // 1 week in seconds;
        $record->requirepreviousoptionstobebooked = "0";

        booking_option::update($record);

        // Two children were created, which makes a total of three booking options.
        $bookingoptions = $DB->get_records('booking_options');
        $this->assertEquals(3, count($bookingoptions));

        // One bookingoption and 2 children.
        $children = array_filter($bookingoptions, fn($bo) => $bo->parentid == $option1->id);
        $this->assertEquals(2, count($children));

        // Now proceed with the actual testing of the allchildrenfunction.
        // Update option to trigger recurrence.
        // We can reuse the record from before.
        $record = new stdClass();
        $record->id = $option1->id;
        $record->cmid = $settings->cmid;
        $record->importing = 1;
        $record->repeatthisbooking = "0";
        $actiontype = $data['actiontype'];
        $record->$actiontype = "1";

        booking_option::update($record);
        $bookingoptions = $DB->get_records('booking_options');
        $children = array_filter($bookingoptions, fn($bo) => $bo->parentid == $option1->id);
        $this->assertEquals($expected['numberofoptionsafteraction'], count($bookingoptions));
        if (!empty($children)) {
            $this->assertEquals($expected['numberofchildrenafteraction'], count($children));
        }
    }

    /**
     * Data provider for test_create_recurrignoptions
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    public static function booking_common_settings_provider(): array {

        return [
            'one_week_delta_with_previouslybooked' => [
                [
                    'repeatthisbooking' => 1,
                    'howmanytimestorepeat' => 2, // Repeat twice.
                    'howoftentorepeat' => 7 * 24 * 60 * 60, // 1 week in seconds.
                    'requirepreviousoptionstobebooked' => "1",
                ],
                [
                    'totaloptions' => 3,
                    'delta1' => '1 week',
                    'delta2' => '2 weeks',
                    'previouslybooked' => true,
                ],
            ],
            'one_day_delta_simple' => [
                [
                    'repeatthisbooking' => 1,
                    'howmanytimestorepeat' => 3, // Repeat three times.
                    'howoftentorepeat' => 24 * 60 * 60, // 1 day in seconds.
                    'requirepreviousoptionstobebooked' => "0",
                ],
                [
                    'totaloptions' => 4,
                    'delta1' => '1 day',
                    'delta2' => '2 days',
                    'previouslybooked' => false,
                ],
            ],
        ];
    }

    /**
     * Data provider for test_create_recurrignoptions
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    public static function booking_allchildrenaction_settings_provider(): array {

        return [
            'deleteallchildren' => [
                [
                    'actiontype' => 'deleteallchildren',
                ],
                [
                    'numberofoptionsafteraction' => 1,
                    'numberofchildrenafteraction' => 0,
                ],
            ],
            'unlinkallchildred' => [
                [
                    'actiontype' => 'unlinkallchildren',
                ],
                [
                    'numberofoptionsafteraction' => 3,
                    'numberofchildrenafteraction' => 0,
                    'numberoflinkedchildren' => 0,
                ],
            ],
        ];
    }

    /**
     * Provides the data that's constant for the test.
     *
     * @return array
     *
     */
    private static function provide_bdata(): array {
        return [
            'name' => 'Test Booking Policy 1',
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
            'showviews' => ['mybooking,myoptions,showall,showactive,myinstitution'],
        ];
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        // Mandatory clean-up.
        singleton_service::destroy_instance();
    }
}
