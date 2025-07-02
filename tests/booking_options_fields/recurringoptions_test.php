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
use tool_mocktesttime\time_mock;

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
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
    }

    /**
     * Test creation and update of recurring options.
     *
     * @covers \mod_booking\bo_availability\conditions\bookitbutton::is_available
     * @covers \mod_booking\bo_availability\conditions\alreadybooked::is_available
     * @covers \mod_booking\bo_availability\conditions\fullybooked::is_available
     * @covers \mod_booking\bo_availability\conditions\confirmation::render_page
     * @covers \mod_booking\option\fields\recurringoptions::save_data
     * @covers \mod_booking\option\fields\recurringoptions::definition_after_data
     * @covers \mod_booking\option\fields\recurringoptions::update_options
     * @covers \mod_booking\option\fields\recurringoptions::update_records
     * @covers \mod_booking\booking_option::update
     *
     * @param array $data
     * @param array $expected
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_create_recurringoptions(array $data, array $expected): void {
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
        $record->coursestarttime = strtotime('2025-01-01 10:00:00');
        $record->courseendtime = strtotime('2025-01-01 12:00:00');
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

        // Check for children if data is applied correctly.
        $priceoriginal = price::get_price('option', $record->id);
        foreach ($createdoptions as $id => $option) {
            if ($id == $option1->id) {
                // Skip the parent.
                continue;
            }
            // Data is stored correctly in json of child.
            $optionjson = json_decode($option->json);
            $this->assertEquals($data['howoftentorepeat'], $optionjson->recurringchilddata->delta);

            // Title.
            $this->assertEquals($record->text, $option->text);

            // Starttime and Endtime correspond to delta and index as defined in json.
            $i = $optionjson->recurringchilddata->index;
            $d = $optionjson->recurringchilddata->delta;
            $expectedstartingtime = strtotime("+ $i $d", (int) $record->coursestarttime);
            $this->assertEquals($expectedstartingtime, $option->coursestarttime);
            $expectedendtime = strtotime("+ $i $d", (int) $record->courseendtime);
            $this->assertEquals($expectedendtime, $option->courseendtime);

            // Price.
            $optionprice = price::get_price('option', $option->id);
            $this->assertEquals($priceoriginal['price'], $optionprice['price']);

            // Parentid is stored.
            $this->assertEquals($record->id, $option->parentid);
        }

        // A first small update of the parent record, that's not applied to children.
        $firstupdate = [
            'beforebookedtext' => $data['updatedbeforebookedtext'],
            'cmid' => $settings->cmid,
            'id' => $record->id,
            'importing' => 1,
        ];
        booking_option::update($firstupdate);

        // This change should not be applied to children.
        $children = $DB->get_records('booking_options', ['bookingid' => $booking1->id, 'parentid' => $record->id]);
        foreach ($children as $child) {
            $this->assertNotEquals($firstupdate['beforebookedtext'], $child->beforebookedtext ?? '');
        }

        // Update the parent option with new values and apply to children.
        $record = (object) [
            'cmid' => $settings->cmid,
            'id' => $record->id,
        ];
        fields_info::set_data($record);
        $record->maxanswers = 20;
        $record->maxoverbooking = 10;
        $record->text = 'Test Parent';
        $record->description = 'Test Booking Description';
        $record->coursestarttime = strtotime('now');
        $record->courseendtime = strtotime('now + 10 day');
        $record->daystonotify_1 = "0";
        $record->coursestarttime_1 = strtotime('now');
        $record->courseendtime_1 = strtotime('now + 2 day');
        $record->daystonotify_2 = "0";
        $record->coursestarttime_2 = strtotime('now + 1 day');
        $record->courseendtime_2 = strtotime('now + 10 day');
        $record->restrictanswerperiodopening = 1;
        $record->restrictanswerperiodclosing = 1;
        $record->bookingopeningtime = strtotime('now - 1 day');
        $record->bookingclosingtime = strtotime('now + 1 day');
        $record->apply_to_children = $data['apply_to_children'];
        booking_option::update($record);

        // Parent now containing two sessions.
        $settings = singleton_service::get_instance_of_booking_option_settings($record->id);
        $this->assertCount(2, $settings->sessions);

        $children = $DB->get_records('booking_options', ['bookingid' => $booking1->id, 'parentid' => $record->id], 'id ASC');

        foreach ($children as $index => $child) {
            // Here is a difference in overwriting.
            $this->assertEquals(
                $expected['beforebookedtext'],
                $child->beforebookedtext,
                "Overwrite children not working correctly."
            );

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

            $json = json_decode($child->json);
            $index = $json->recurringchilddata->index;
            $delta = $json->recurringchilddata->delta;
            // Bookingopening- and -closingtime.
            $expectedopeningtime = strtotime("+ $i $d", (int) $record->bookingopeningtime);
            $expectedclosingtime = strtotime("+ $i $d", (int) $record->bookingclosingtime);

            $this->assertEquals($expectedopeningtime, $child->bookingopeningtime);
            $this->assertEquals($expectedclosingtime, $child->bookingclosingtime);

            // Verify if all sessions were updated correctly.
            $childdata = (object)[
                'id' => $child->id,
                'cmid' => $record->cmid,
            ];
            fields_info::set_data($childdata);
            [$childdates, $highestindexchild] = dates::get_list_of_submitted_dates((array)$childdata);
            $this->assertCount(2, $childdates);
            foreach ($childdates as $optiondate) {
                // This is each session in the child.
                if (
                    empty($optiondate['coursestarttime'])
                    && empty($optiondate['courseendtime'])
                ) {
                    continue;
                }

                $childjson = json_decode($child->json);
                $index = $childjson->recurringchilddata->index;
                $delta = $childjson->recurringchilddata->delta;

                $startkey = MOD_BOOKING_FORM_COURSESTARTTIME . $optiondate['index'];
                $endkey = MOD_BOOKING_FORM_COURSEENDTIME . $optiondate['index'];
                $expectedstarttime = $record->$startkey + ($delta * $index);
                $expectedendtime = $record->$endkey + ($delta * $index);

                $this->assertEquals($expectedstarttime, $optiondate['coursestarttime']);
                $this->assertEquals($expectedendtime, $optiondate['courseendtime']);
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
        // Update siblings.
        $firstchild = reset($children);

        // First an update, that is only saved but not applied to the siblings.
        // This is to test the overwrite_siblings function. We create a change in titleprefix previous to update.
        $firstupdate = [
            'titleprefix' => $data['updatedprefix'],
            'cmid' => $settings->cmid,
            'id' => $firstchild->id,
            'importing' => 1,
        ];
        booking_option::update($firstupdate);

        $firstchild = (object) [
            'cmid' => $settings->cmid,
            'id' => $firstchild->id,
        ];
        fields_info::set_data($firstchild);
        // Now update with siblings included.
        $firstchild->cmid = $settings->cmid;
        $firstchild->maxanswers = 30;
        $firstchild->maxoverbooking = 30;
        $firstchild->text = 'Test Sibling';
        $firstchild->description = 'Test Booking Description Sibling Change';
        $firstchild->coursestarttime = strtotime('now + 1 day');
        $firstchild->courseendtime = strtotime('now + 4 day');
        $firstchild->daystonotify_1 = "0";
        $firstchild->coursestarttime_1 = strtotime('now + 1 day');
        $firstchild->courseendtime_1 = strtotime('now + 2 day');
        $firstchild->daystonotify_2 = "0";
        $firstchild->coursestarttime_2 = strtotime('now + 1 day');
        $firstchild->courseendtime_2 = strtotime('now + 4 day');
        $firstchild->apply_to_siblings = $data['apply_to_siblings'];
        booking_option::update($firstchild);

        $record = $DB->get_record('booking_options', ['id' => $firstchild->id]);

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
                $expected['titleprefix'],
                $child->titleprefix,
                "Overwrite siblings not working correctly."
            );

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
        }
    }

    /**
     * Test allchildrenaction to see if unlinking and deletion of children is working as expected.
     *
     * @covers \mod_booking\bo_availability\conditions\bookitbutton::is_available
     * @covers \mod_booking\bo_availability\conditions\alreadybooked::is_available
     * @covers \mod_booking\bo_availability\conditions\fullybooked::is_available
     * @covers \mod_booking\bo_availability\conditions\confirmation::render_page
     * @covers \mod_booking\option\fields\recurringoptions::save_data
     * @covers \mod_booking\option\fields\recurringoptions::definition_after_data
     * @covers \mod_booking\option\fields\recurringoptions::update_options
     * @covers \mod_booking\option\fields\recurringoptions::update_records
     * @covers \mod_booking\booking_option::update
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

        // Now proceed with testing the allchildrenfunction.
        // Update option to trigger recurrence.
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
     * Data provider for test_create_recurringoptions
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    public static function booking_common_settings_provider(): array {

        return [
            'one_week_delta_with_previouslybooked_overwriting' => [
                [
                    'repeatthisbooking' => 1,
                    'howmanytimestorepeat' => 2, // Repeat twice.
                    'howoftentorepeat' => 7 * 24 * 60 * 60, // 1 week in seconds.
                    'requirepreviousoptionstobebooked' => "1",
                    'apply_to_siblings' => MOD_BOOKING_RECURRING_OVERWRITE_SIBLINGS,
                    'updatedprefix' => "pre",
                    'updatedbeforebookedtext' => "before booked",
                    'apply_to_children' => MOD_BOOKING_RECURRING_OVERWRITE_CHILDREN,
                ],
                [
                    'totaloptions' => 3,
                    'delta1' => '1 week',
                    'delta2' => '2 weeks',
                    'previouslybooked' => true,
                    'titleprefix' => "pre",
                    'beforebookedtext' => "before booked",
                ],
            ],
            'one_day_delta_simple' => [
                [
                    'repeatthisbooking' => 1,
                    'howmanytimestorepeat' => 3, // Repeat three times.
                    'howoftentorepeat' => 24 * 60 * 60, // 1 day in seconds.
                    'requirepreviousoptionstobebooked' => "0",
                    'apply_to_siblings' => MOD_BOOKING_RECURRING_APPLY_TO_SIBLINGS,
                    'updatedprefix' => "pre",
                    'updatedbeforebookedtext' => "before booked",
                    'apply_to_children' => MOD_BOOKING_RECURRING_APPLY_TO_CHILDREN,
                ],
                [
                    'totaloptions' => 4,
                    'delta1' => '1 day',
                    'delta2' => '2 days',
                    'previouslybooked' => false,
                    'titleprefix' => "",
                    'beforebookedtext' => "",
                ],
            ],
        ];
    }

    /**
     * Data provider for test_create_recurringoptions
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
