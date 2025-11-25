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

namespace mod_booking;

use advanced_testcase;
use coding_exception;
use context_course;
use context_system;
use local_wunderbyte_table\wunderbyte_table;
use mod_booking_generator;
use stdClass;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Class cfincludeandcustomfield_test
 *
 * This test checks whether the combined usage of `cfinclude` and a `customfieldfilter`
 * breaks any functionality.
 *
 * The `allbookingoptions` shortcode supports both the `cfinclude` argument and
 * the `customfieldfilter` argument, which makes it possible to use both features
 * at the same time. This test class contains three tests:
 *
 * 1. The first test checks only the `cfinclude` functionality.
 * 2. The second test adds a custom field filter for the custom field "sport",
 *    but does not apply any filter value.
 * 3. The third test filters the results by the "sport" column while `cfinclude`
 *    is active and while the custom field "sport" is selected for the `cfinclude`
 *    functionality.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2025 Mahdi Poustini
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
final class cfincludeandcustomfield_test extends advanced_testcase {
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
     * Summary of test_recommendedin_shortcode_cfinclude_and_customfilter
     *
     * @covers \mod_booking\shortcodes::allbookingoptions
     *
     * @param array $data
     * @param array $expected
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_recommendedin_shortcode_cfinclude_and_customfilter(array $data, array $expected): void {
        global $DB, $CFG;
        $bdata = self::provide_bdata();

        // Setup test data.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1, 'shortname' => 'course1']);

        // Create users.
        $admin = $this->getDataGenerator()->create_user();
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        // Two courses will contain identical setup and multiple options in multiple booking.
        $cmids = [];

        // Create custom booking field.
        $categorydata = new stdClass();
        $categorydata->name = 'BookCustomCat1';
        $categorydata->component = 'mod_booking';
        $categorydata->area = 'booking';
        $categorydata->itemid = 0;
        $categorydata->contextid = context_system::instance()->id;

        $bookingcat = $this->getDataGenerator()->create_custom_field_category((array) $categorydata);
        $bookingcat->save();

        // Create custom field Recomendedin.
        $fielddata = new stdClass();
        $fielddata->categoryid = $bookingcat->get('id');
        $fielddata->name = 'Recomendedin';
        $fielddata->shortname = 'recommendedin';
        $fielddata->type = 'text';
        $fielddata->configdata = "";
        $bookingfield = $this->getDataGenerator()->create_custom_field((array) $fielddata);
        $bookingfield->save();

        // Create custom field Sport.
        $fielddata = new stdClass();
        $fielddata->categoryid = $bookingcat->get('id');
        $fielddata->name = 'Sport';
        $fielddata->shortname = 'sport';
        $fielddata->type = 'text';
        $fielddata->configdata = "";
        $bookingfield = $this->getDataGenerator()->create_custom_field((array) $fielddata);
        $bookingfield->save();

        // Create custom field Language.
        $fielddata = new stdClass();
        $fielddata->categoryid = $bookingcat->get('id');
        $fielddata->name = 'Language';
        $fielddata->shortname = 'language';
        $fielddata->type = 'text';
        $fielddata->configdata = "";
        $bookingfield = $this->getDataGenerator()->create_custom_field((array) $fielddata);
        $bookingfield->save();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course->id);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id);

            // Create an initial booking option.
        foreach ($bdata['standardbookingoptions'] as $option) {
            $record = (object) $option;
            $record->bookingid = $booking->id;
            /** @var mod_booking_generator $plugingenerator */
            $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
            $option1 = $plugingenerator->create_option($record);
            $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
            $cmids[$settings->cmid] = $settings->cmid;
        }

        // Apply given settings.
        if (isset($data['settings'])) {
            foreach ($data['settings'] as $key => $value) {
                set_config($key, $value, 'booking');
            }
        }

        // Now we have multiple options in multiple bookings and multiple courses.
        $records = $DB->get_records('booking_options');
        $this->assertCount(6, $records, 'Booking options were not created correctly');

        // Prepare the args.
        $args = $data['args'];

        // Now we can start testing the shortcode.
        $env = new stdClass();
        $next = function () {
        };
        $args['all'] = 1;
        global $PAGE;
        $context = context_course::instance($course->id);

        $PAGE->set_context($context);
        $PAGE->set_course($course);
        if (!empty($data['wbtfilter'])) {
            $PAGE->set_url(
                new \moodle_url('/mod/booking/tests/recommendedin_test.php')
            );
            $_GET['wbtfilter'] = $data['wbtfilter'];
        } else {
            $PAGE->set_url(new \moodle_url('/mod/booking/tests/recommendedin_test.php'));
        }
        $shortcode = shortcodes::allbookingoptions('allbookingoptions', $args, null, $env, $next);
        $this->assertNotEmpty($shortcode);
        $this->assertStringContainsString($expected['tablestringcontains'], $shortcode);
        $pregmatch = preg_match('/<div[^>]*\sdata-encodedtable=["\']?([^"\'>\s]+)["\']?/i', $shortcode, $matches);
        $this->assertEquals($expected['displaytable'], $pregmatch);
        if (!$expected['displaytable']) {
            return;
        }
        $table = wunderbyte_table::instantiate_from_tablecache_hash($matches[1]);
        $tableobject = $table->printtable($table->pagesize, $table->useinitialsbar, $table->downloadhelpbutton);
        $this->assertEquals($expected['numberofrecords'], $table->totalrows);
    }

    /**
     * Data provider for test_courselist_shortcode
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    public static function booking_common_settings_provider(): array {

        return [
            'settingson' => [
                [
                    'args' => [
                        'all' => 1, // Set this to avoid filtering on coursestarttime.
                    ],
                ],
                [
                    'tablestringcontains' => "wunderbyte_table_container",
                    'numberofrecords' => 6,
                    'displaytable' => true,
                ],
            ],
            'settingson - cfinclude' => [
                [
                    'args' => [
                        'all' => 1, // Set this to avoid filtering on coursestarttime.
                        'cfinclude' => true,
                        'sport' => 'soccer',
                        'filter' => 1,
                        'customfieldfilter' => 'sport,language',
                    ],
                ],
                [
                    'tablestringcontains' => "wunderbyte_table_container",
                    'numberofrecords' => 6,
                    'displaytable' => true,
                ],
            ],
            'settingson - cfinclude & search with custom filters' => [
                [
                    'args' => [
                        'all' => 1, // Set this to avoid filtering on coursestarttime.
                        'cfinclude' => true,
                        'sport' => 'soccer',
                        'filter' => 1,
                        'customfieldfilter' => 'sport,language',
                    ],
                    'wbtfilter' => '{"sport":["soccer"]}',
                ],
                [
                'tablestringcontains' => "wunderbyte_table_container",
                'numberofrecords' => 1,
                'displaytable' => true,
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
            'standardbookingoptions' => [
                [
                    'text' => 'Test Booking Option without price',
                    'description' => 'Test Booking Option',
                    'identifier' => 'noprice',
                    'maxanswers' => 1,
                    'customfield_customcat' => 'Text 1',
                    'customfield_recommendedin' => 'course1',
                    'customfield_sport' => 'ski',
                    'customfield_language' => 'en',
                ],
                [
                    'text' => 'Test Booking Option with price',
                    'description' => 'Test Booking Option',
                    'identifier' => 'withprice',
                    'maxanswers' => 1,
                    'customfield_customcat' => 'Text 1',
                    'customfield_recommendedin' => 'course1',
                    'customfield_sport' => 'soccer',
                    'customfield_language' => 'en',
                ],
                [
                    'text' => 'Disalbed Test Booking Option',
                    'description' => 'Test Booking Option',
                    'identifier' => 'disabledoption',
                    'maxanswers' => 1,
                    'disablebookingusers' => 1,
                    'customfield_customcat' => 'Text 1',
                    'customfield_customselect' => "1",
                ],
                [
                    'text' => 'Wait for confirmation Booking Option, no price',
                    'description' => 'Test Booking Option',
                    'identifier' => 'waitforconfirmationnoprice',
                    'maxanswers' => 1,
                    'waitforconfirmation' => 1,
                    'customfield_customcat' => 'Text 2',
                    'customfield_customselect' => "2",
                ],
                [
                    'text' => 'Wait for confirmation Booking Option, price',
                    'description' => 'Test Booking Option',
                    'identifier' => 'waitforconfirmationwithprice',
                    'maxanswers' => 1,
                    'waitforconfirmation' => 1,
                    'customfield_customcat' => 'Text 2',
                ],
                [
                    'text' => 'Blocked by enrolledincohorts',
                    'description' => 'Test enrolledincohorts availability condition',
                    'identifier' => 'enrolledincohorts',
                    'maxanswers' => 1,
                    'boavenrolledincohorts' => 'testcohort',
                    'customfield_customcat' => 'Text 2',
                ],
            ],
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
