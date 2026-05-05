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
use context_system;
use local_wunderbyte_table\filters\types\customfieldfilter;
use mod_booking\table\bookingoptions_wbtable;
use tool_mocktesttime\time_mock;

/**
 * Class handling tests for bookinghistory.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Mahdi Poustini
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 *
 */
final class bookingoption_filter_test extends advanced_testcase {
    /**
     * text1
     * @var string
     */
    protected static $text1 = 'Text 1';

    /**
     * label1
     * @var string
     */
    protected static $label1 = 'Label 1';

    /**
     * text2
     * @var string
     */
    protected static $text2 = 'Text 2';

    /**
     * label2
     * @var string
     */
    protected static $label2 = 'Label 2';

    /**
     * Seutp.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
        // Clear before each test.
        $_GET = [];
        $_POST = [];
        // We require version higher or equal to 2025101500 of wunderbyte_table.
        // Uncomment this line if you need to check the versin: $this->require_wunderbyte_table_version(2025101500);.
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }

    /**
     * Tests the application of custom field filters on booking options using the Wunderbyte table system.
     *
     * This test verifies that the {@see customfieldfilter} filters correctly apply
     * to booking options in the {@see bookingoptions_wbtable} based on the values
     * provided in the `wbtfilter` URL parameter. It covers scenarios where:
     *  - Only one custom field (e.g., "customcat") is filtered.
     *  - Multiple custom fields (e.g., "customcat" and "customlabel") are filtered simultaneously.
     *
     * The test creates a booking activity with multiple booking options,
     * each having custom field values. It then:
     *  1. Validates the total number of booking options before filtering.
     *  2. Applies the filter(s) using JSON passed in `$_GET['wbtfilter']`.
     *  3. Asserts that the resulting filtered data count matches the expected count.
     *
     * @param string $wbtfilter JSON string representing the filters (e.g. '{"customcat":["Text 2"]}').
     * @param int $counttotaloptions Expected total number of booking options before filters are applied.
     * @param int $countfilteredoptions Expected number of booking options after filters are applied.
     * @param bool $usecustomsql
     *
     * @covers \local_wunderbyte_table\filters\types\customfieldfilter
     * @covers \local_wunderbyte_table\wunderbyte_table::apply_filter
     * @covers \local_wunderbyte_table\wunderbyte_table::apply_filter_and_search_from_url
     *
     * @dataProvider options_provider
     *
     * @return void
     */
    public function test_customfieldfilter_on_booking_options(
        string $wbtfilter,
        int $counttotaloptions,
        int $countfilteredoptions,
        bool $usecustomsql
    ): void {

        $this->resetAfterTest();

        $this->preventResetByRollback();

        $this->setAdminUser();

        // Get provided data.
        $bdata = self::provide_bookingdata();

        // Create custom field category.
        $categorydata = new \stdClass();
        $categorydata->name = 'BookCustomCat1';
        $categorydata->component = 'mod_booking';
        $categorydata->area = 'booking';
        $categorydata->itemid = 0;
        $categorydata->contextid = context_system::instance()->id;

        $bookingcat = $this->getDataGenerator()->create_custom_field_category((array) $categorydata);
        $bookingcat->save();

        // Create custom field(s).
        // Custom filed 1 (customcat).
        $fielddata = new \stdClass();
        $fielddata->categoryid = $bookingcat->get('id');
        $fielddata->name = 'Textfield';
        $fielddata->shortname = 'customcat';
        $fielddata->type = 'text';
        $fielddata->configdata = "";
        $bookingfield = $this->getDataGenerator()->create_custom_field((array) $fielddata);
        $bookingfield->save();
        $fieldidcustomcat = $bookingfield->get('id');

        // Custom filed 2 (customlabel).
        $fielddata = new \stdClass();
        $fielddata->categoryid = $bookingcat->get('id');
        $fielddata->name = 'Textfield';
        $fielddata->shortname = 'customlabel';
        $fielddata->type = 'text';
        $fielddata->configdata = "";
        $bookingfield = $this->getDataGenerator()->create_custom_field((array) $fielddata);
        $bookingfield->save();
        $fieldidcustomlabel = $bookingfield->get('id');

        // Create a course.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create booking manager.
        $bookingmanager = $this->getDataGenerator()->create_user();

        // Create a booking module inside the course.
        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        // Create some booking options with customfileds.
        foreach ($bdata['standardbookingoptions'] as $option) {
            $record = (object) $option;
            $record->bookingid = $booking->id;
            $option1 = $plugingenerator->create_option($record);
            $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
            $cmid = $settings->cmid; // Cmid is same for all the options.
        }

        // Create the table.
        $table = new bookingoptions_wbtable("cmid_{$cmid}_showonetable");

        $booking = singleton_service::get_instance_of_booking_by_cmid($cmid);

        // Get options of a single booking module.
        $wherearray = [
            'bookingid' => (int) $booking->id,
        ];
        $fileds = ($usecustomsql) ? 's1.*, customcat, customlabel' : '';
        [$fields, $from, $where, $params, $filter] =
                booking::get_options_filter_sql(0, 0, '', $fileds, $booking->context, [], $wherearray);
        $table->set_filter_sql($fields, $from, $where, $filter, $params);

        // Create a filter on customcat.
        $customfieldfilter = new customfieldfilter('customcat', 'Custom category');
        if ($usecustomsql) {
            $customfieldfilter->set_sql("id IN (SELECT instanceid
                FROM {customfield_data} cfd
                WHERE cfd.fieldid = {$fieldidcustomcat}
                AND :where)", 'cfd.value');
        } else {
            $customfieldfilter->set_sql_for_fieldid($fieldidcustomcat);
        }
        $table->add_filter($customfieldfilter);

        // Create a filter on customlabel.
        $customfieldfilter = new customfieldfilter('customlabel', 'Custom Label');
        if ($usecustomsql) {
            $customfieldfilter->set_sql("id IN (SELECT instanceid
                FROM {customfield_data} cfd
                WHERE cfd.fieldid = $fieldidcustomlabel
                AND :where)", 'cfd.value');
        } else {
            $customfieldfilter->set_sql_for_fieldid($fieldidcustomlabel);
        }
        $table->add_filter($customfieldfilter);

        // Execute table logic to fetch records.
        $table->printtable(10000, true);
        // We have $counttotaloptions options totally.
        // We expect to see all the booking options before applying any filter.
        $this->assertCount($counttotaloptions, $table->rawdata);

        // Now we filter booking options on custom fields.
        $_GET['wbtfilter'] = $wbtfilter;

        // Execute table logic to fetch records and apply filters.
        $table->printtable(10000, true);
        // We have $counttotaloptions options totally. $countfilteredoptions of them has $wntconditions.
        $this->assertCount($countfilteredoptions, $table->rawdata);
    }

    /**
     * Provides the data that's constant for the test.
     *
     * @return array
     *
     */
    private static function provide_bookingdata(): array {
        return [
            'name' => 'My booking module 1',
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
            'completion' => 2,
            'showviews' => ['mybooking,myoptions,showall,showactive,myinstitution'],
            'standardbookingoptions' => [
                [
                    'text' => 'Test Booking Option without price',
                    'description' => 'option 1 description',
                    'identifier' => 'noprice',
                    'maxanswers' => 1,
                    'customfield_customcat' => self::$text1,
                    'customfield_customlabel' => self::$label1,
                ],
                [
                    'text' => 'Test Booking Option with price',
                    'description' => 'option 2 description',
                    'identifier' => 'withprice',
                    'maxanswers' => 1,
                    'customfield_customcat' => self::$text1,
                    'customfield_customlabel' => self::$label1,
                ],
                [
                    'text' => 'Disalbed Test Booking Option',
                    'description' => 'option 3 description',
                    'identifier' => 'disabledoption',
                    'maxanswers' => 1,
                    'disablebookingusers' => 1,
                    'customfield_customcat' => self::$text1,
                    'customfield_customlabel' => self::$label1,
                ],
                [
                    'text' => 'Wait for confirmation Booking Option, no price',
                    'description' => 'option 4 description',
                    'identifier' => 'waitforconfirmationnoprice',
                    'maxanswers' => 1,
                    'waitforconfirmation' => 1,
                    'customfield_customcat' => self::$text2,
                    'customfield_customlabel' => self::$label1,
                ],
                [
                    'text' => 'Wait for confirmation Booking Option, no price',
                    'description' => 'option 5 description',
                    'identifier' => 'waitforconfirmationnoprice',
                    'maxanswers' => 1,
                    'waitforconfirmation' => 1,
                    'customfield_customcat' => self::$text2,
                    'customfield_customlabel' => self::$label2,
                ],
            ],
        ];
    }

    /**
     * Data proivider.
     * @return array
     */
    public static function options_provider(): array {

        // Get provided data.
        $bdata = self::provide_bookingdata();

        // Total number of options.
        $counttotaloptions = count($bdata['standardbookingoptions']);

        // Count options having customcat with value 'Text 2'.
        $countfilteredoptions1 = count(array_filter($bdata['standardbookingoptions'], function ($item) {
            return $item['customfield_customcat'] === self::$text2;
        }));

        // Count options having both customcat with value 'Text 2' & customlabel with value 'Label 2'.
        $countfilteredoptions2 = count(array_filter($bdata['standardbookingoptions'], function ($item) {
            return (
                $item['customfield_customcat'] === self::$text2
                && $item['customfield_customlabel'] === self::$label2
            );
        }));

        return [
            'filter on customcat - default SQL' => [
                'wbtfilter' => '{"customcat":["Text 2"]}',
                'counttotaloptions' => $counttotaloptions,
                'countfilteredoptions' => $countfilteredoptions1,
                'usecustomsql' => false,
            ],
            'filter on customcat - custom SQL' => [
                'wbtfilter' => '{"customcat":["Text 2"]}',
                'counttotaloptions' => $counttotaloptions,
                'countfilteredoptions' => $countfilteredoptions1,
                'usecustomsql' => true,
            ],
            'filter on customcat & customlabel - default SQL' => [
                'wbtfilter' => '{"customcat":["Text 2"],"customlabel":["Label 2"]}',
                'counttotaloptions' => $counttotaloptions,
                'countfilteredoptions' => $countfilteredoptions2,
                'usecustomsql' => false,
            ],
            'filter on customcat & customlabel - custom SQL' => [
                'wbtfilter' => '{"customcat":["Text 2"],"customlabel":["Label 2"]}',
                'counttotaloptions' => $counttotaloptions,
                'countfilteredoptions' => $countfilteredoptions2,
                'usecustomsql' => true,
            ],
        ];
    }

    /**
     * Ensures that the local plugin "wunderbyte_table" is installed and meets the required version.
     *
     * If the plugin is not installed or its version is lower than the required one,
     * the current test is skipped using {@see markTestSkipped()} to avoid false failures.
     *
     * This will skip the test if the plugin version is missing or lower than `2024100100`.
     *
     * @param int $requiredversion The minimum required plugin version (typically a Moodle-style timestamp version number).
     *
     * @return void
     */
    protected function require_wunderbyte_table_version(int $requiredversion): void {
        // Try core_plugin_manager (preferred).
        $foundversion = null;
        if (class_exists('\core_plugin_manager')) {
            try {
                $pm = \core_plugin_manager::instance();
                $plugin = $pm->get_plugin_info('local_wunderbyte_table');
                if ($plugin !== null) {
                    // Function get_version() returns integer version (e.g. 2024111500) for most plugins.
                    $foundversion = (int) $plugin->versiondb;
                }
            } catch (\Exception $e) {
                // Ignore and fallback.
                $foundversion = null;
            }
        }

        // Fallback: read from config_plugins.
        if ($foundversion === null) {
            $cfg = get_config('local_wunderbyte_table', 'version');
            if ($cfg !== false && $cfg !== null) {
                $foundversion = (int) $cfg;
            }
        }

        // If not installed or version too low -> skip the test.
        if ($foundversion === null || $foundversion < $requiredversion) {
            $found = $foundversion === null ? 'not installed' : (string)$foundversion;
            $this->markTestSkipped(
                "Skipping test: local_wunderbyte_table required version >= {$requiredversion}. Found: {$found}."
            );
        }
    }

    /**
     * Tests teacher page visibility modes for booking options.
     *
     * @covers \mod_booking\booking::get_options_filter_sql
     * @return void
     */
    public function test_teacher_page_visibility_modes(): void {
        global $DB;

        $this->resetAfterTest();
        $this->preventResetByRollback();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher = $this->getDataGenerator()->create_user();
        $editingteacherroleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, $editingteacherroleid);
        $this->setUser($teacher);

        $bdata = [
            'course' => $course->id,
            'name' => 'Test booking',
        ];
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $cmid = $booking->cmid;
        $bookingobj = singleton_service::get_instance_of_booking_by_cmid($cmid);
        $context = $bookingobj->context;

        $wherearray = [
            'bookingid' => (int)$booking->id,
        ];

        [$fields, $from, $where, $params, $filter] = booking::get_options_filter_sql(
            0,
            0,
            '',
            null,
            $context,
            [],
            $wherearray,
            null,
            [MOD_BOOKING_STATUSPARAM_BOOKED],
            '',
            '',
            null,
            0
        );
        $this->assertStringContainsString('invisible = 0', $where);
        $this->assertStringContainsString('bookingid =', $where);

        [$fields, $from, $where, $params, $filter] = booking::get_options_filter_sql(
            0,
            0,
            '',
            null,
            $context,
            [],
            $wherearray,
            null,
            [MOD_BOOKING_STATUSPARAM_BOOKED],
            '',
            '',
            null,
            1
        );
        $this->assertStringContainsString('invisible IN (0, 1)', $where);

        [$fields, $from, $where, $params, $filter] = booking::get_options_filter_sql(
            0,
            0,
            '',
            null,
            $context,
            [],
            $wherearray,
            null,
            [MOD_BOOKING_STATUSPARAM_BOOKED],
            '',
            '',
            null,
            2
        );
        $this->assertStringContainsString('invisible IN (0, 2)', $where);

        [$fields, $from, $where, $params, $filter] = booking::get_options_filter_sql(
            0,
            0,
            '',
            null,
            $context,
            [],
            $wherearray,
            null,
            [MOD_BOOKING_STATUSPARAM_BOOKED],
            '',
            '',
            null,
            3
        );
        $this->assertStringContainsString('1 = 1', $where);
    }

    /**
     * Tests teacher page visibility modes with multiple teachers assigned to the same option.
     * Verifies that the teacherobjects LIKE filter correctly selects options for each teacher
     * and that each teacher independently sees the correct set of options per visibility mode.
     *
     * @covers \mod_booking\booking::get_options_filter_sql
     * @return void
     */
    public function test_teacher_page_visibility_modes_multiteacher(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher1 = $this->getDataGenerator()->create_user();
        $teacher2 = $this->getDataGenerator()->create_user();
        $editingteacherroleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        $this->getDataGenerator()->enrol_user($teacher1->id, $course->id, $editingteacherroleid);
        $this->getDataGenerator()->enrol_user($teacher2->id, $course->id, $editingteacherroleid);

        $bdata = self::provide_bookingdata();
        $bdata['course'] = $course->id;
        $bdata['name'] = 'Multiteacher booking';
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);
        $cmid = $booking->cmid;

        $optiona = (object)['id' => $DB->insert_record('booking_options', (object)[
            'bookingid' => $booking->id,
            'text' => 'Visible teacher1 only',
            'description' => 'Visible teacher1 only',
            'invisible' => 0,
        ])];
        $optionb = (object)['id' => $DB->insert_record('booking_options', (object)[
            'bookingid' => $booking->id,
            'text' => 'Invisible teacher1 only',
            'description' => 'Invisible teacher1 only',
            'invisible' => 1,
        ])];
        $optionc = (object)['id' => $DB->insert_record('booking_options', (object)[
            'bookingid' => $booking->id,
            'text' => 'Invisible multi-teacher',
            'description' => 'Invisible multi-teacher',
            'invisible' => 1,
        ])];
        $optiond = (object)['id' => $DB->insert_record('booking_options', (object)[
            'bookingid' => $booking->id,
            'text' => 'Invisible teacher2 only',
            'description' => 'Invisible teacher2 only',
            'invisible' => 1,
        ])];

        // Assign teachers to options using booking_teachers relation.
        $DB->insert_record('booking_teachers', (object)[
            'bookingid' => $booking->id,
            'userid' => $teacher1->id,
            'optionid' => $optiona->id,
            'completed' => 0,
            'calendarid' => 0,
        ]);
        $DB->insert_record('booking_teachers', (object)[
            'bookingid' => $booking->id,
            'userid' => $teacher1->id,
            'optionid' => $optionb->id,
            'completed' => 0,
            'calendarid' => 0,
        ]);
        $DB->insert_record('booking_teachers', (object)[
            'bookingid' => $booking->id,
            'userid' => $teacher1->id,
            'optionid' => $optionc->id,
            'completed' => 0,
            'calendarid' => 0,
        ]);
        $DB->insert_record('booking_teachers', (object)[
            'bookingid' => $booking->id,
            'userid' => $teacher2->id,
            'optionid' => $optionc->id,
            'completed' => 0,
            'calendarid' => 0,
        ]);
        $DB->insert_record('booking_teachers', (object)[
            'bookingid' => $booking->id,
            'userid' => $teacher2->id,
            'optionid' => $optiond->id,
            'completed' => 0,
            'calendarid' => 0,
        ]);

        // Confirm invisible flag was persisted for options B, C and D.
        $this->assertEquals(1, $DB->get_field('booking_options', 'invisible', ['id' => $optionb->id]));
        $this->assertEquals(1, $DB->get_field('booking_options', 'invisible', ['id' => $optionc->id]));
        $this->assertEquals(1, $DB->get_field('booking_options', 'invisible', ['id' => $optiond->id]));

        $bookingobj = singleton_service::get_instance_of_booking_by_cmid($cmid);
        $context = $bookingobj->context;

        // Mode 1, teacher1's page: must see A, B, C but NOT D.
        $this->setUser($teacher1);
        $wherearray1 = [
            'bookingid' => (int)$booking->id,
            'teacherobjects' => '%"id":' . $teacher1->id . ',%',
        ];
        $table1 = new bookingoptions_wbtable("cmid_{$cmid}_t1_mode1");
        [$fields, $from, $where, $params, $filter] = booking::get_options_filter_sql(
            0,
            0,
            '',
            null,
            $context,
            [],
            $wherearray1,
            null,
            [MOD_BOOKING_STATUSPARAM_BOOKED],
            '',
            '',
            $table1,
            1
        );
        $table1->set_filter_sql($fields, $from, $where, $filter, $params);
        $table1->printtable(10000, true);
        $ids1 = array_keys((array)$table1->rawdata);
        $this->assertContains((int)$optiona->id, $ids1, 'Mode 1, teacher1: visible option must appear');
        $this->assertContains((int)$optionb->id, $ids1, 'Mode 1, teacher1: solo-teacher invisible option must appear');
        $this->assertContains((int)$optionc->id, $ids1, 'Mode 1, teacher1: multi-teacher invisible option must appear');
        $this->assertNotContains((int)$optiond->id, $ids1, 'Mode 1, teacher1: other teacher\'s option must not appear');

        // Mode 1, teacher2's page: must see C, D but NOT A or B.
        $this->setUser($teacher2);
        $wherearray2 = [
            'bookingid' => (int)$booking->id,
            'teacherobjects' => '%"id":' . $teacher2->id . ',%',
        ];
        $table2 = new bookingoptions_wbtable("cmid_{$cmid}_t2_mode1");
        [$fields, $from, $where, $params, $filter] = booking::get_options_filter_sql(
            0,
            0,
            '',
            null,
            $context,
            [],
            $wherearray2,
            null,
            [MOD_BOOKING_STATUSPARAM_BOOKED],
            '',
            '',
            $table2,
            1
        );
        $table2->set_filter_sql($fields, $from, $where, $filter, $params);
        $table2->printtable(10000, true);
        $ids2 = array_keys((array)$table2->rawdata);
        $this->assertContains((int)$optionc->id, $ids2, 'Mode 1, teacher2: multi-teacher invisible option must appear');
        $this->assertContains((int)$optiond->id, $ids2, 'Mode 1, teacher2: solo-teacher2 invisible option must appear');
        $this->assertNotContains((int)$optiona->id, $ids2, 'Mode 1, teacher2: teacher1-only visible option must not appear');
        $this->assertNotContains((int)$optionb->id, $ids2, 'Mode 1, teacher2: teacher1-only invisible option must not appear');

        // Mode 0 (default), teacher1's page: invisible options must be hidden.
        $this->setUser($teacher1);
        $table3 = new bookingoptions_wbtable("cmid_{$cmid}_t1_mode0");
        [$fields, $from, $where, $params, $filter] = booking::get_options_filter_sql(
            0,
            0,
            '',
            null,
            $context,
            [],
            $wherearray1,
            null,
            [MOD_BOOKING_STATUSPARAM_BOOKED],
            '',
            '',
            $table3,
            0
        );
        $table3->set_filter_sql($fields, $from, $where, $filter, $params);
        $table3->printtable(10000, true);
        $ids3 = array_keys((array)$table3->rawdata);
        $this->assertContains((int)$optiona->id, $ids3, 'Mode 0, teacher1: visible option must appear');
        $this->assertNotContains((int)$optionb->id, $ids3, 'Mode 0, teacher1: invisible option must not appear');
        $this->assertNotContains((int)$optionc->id, $ids3, 'Mode 0, teacher1: invisible multi-teacher option must not appear');
    }
}
