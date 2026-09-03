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
 * Tests for the bulk operations tab on the booking instance view.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use context_module;
use local_wunderbyte_table\wunderbyte_table;
use mod_booking\output\view;
use mod_booking_generator;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests for the bulk operations tab on the booking instance view.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
final class view_test extends advanced_testcase {
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
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }

    /**
     * Test the bulk operations tab: capability gating in module context and instance-scoped table.
     *
     * @covers \mod_booking\output\view
     * @covers \mod_booking\table\bulkoperations_table::create_table
     */
    public function test_bulkoperations_tab(): void {
        global $PAGE;

        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        $bdata = [
            'name' => 'Test Booking Bulkoperations',
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
            'showviews' => ['showall,bulkoperations'],
            'course' => $course->id,
        ];
        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);
        $bdata['name'] = 'Test Booking Bulkoperations 2';
        $booking2 = $this->getDataGenerator()->create_module('booking', $bdata);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Three options in the first instance, two in the second one.
        $cmids = [];
        foreach ([[$booking1, 3], [$booking2, 2]] as [$booking, $numberofoptions]) {
            for ($i = 1; $i <= $numberofoptions; $i++) {
                $record = (object) [
                    'text' => "Option {$i} of booking {$booking->id}",
                    'description' => 'Test Booking Option',
                    'identifier' => "bulkop{$booking->id}-{$i}",
                    'maxanswers' => 1,
                    'bookingid' => $booking->id,
                ];
                $option = $plugingenerator->create_option($record);
                $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
                $cmids[$booking->id] = $settings->cmid;
            }
        }
        $cmid = $cmids[$booking1->id];

        $student = $this->getDataGenerator()->create_user();
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id);
        $this->getDataGenerator()->enrol_user($manager->id, $course->id);

        // Grant the capability in module context only.
        $context = context_module::instance($cmid);
        $roleid = $this->getDataGenerator()->create_role(['shortname' => 'bulkoperator']);
        assign_capability('mod/booking:executebulkoperations', CAP_ALLOW, $roleid, $context->id);
        role_assign($roleid, $manager->id, $context->id);

        $PAGE->set_url(new \moodle_url('/mod/booking/view.php', ['id' => $cmid]));
        // The all options table reads the cmid from the request via optional_param.
        $_GET['id'] = (string) $cmid;
        $output = $PAGE->get_renderer('mod_booking');

        // User with the capability in module context sees the tab with an instance-scoped table.
        $this->setUser($manager);
        singleton_service::destroy_instance();
        $view = new view($cmid, 'bulkoperations');
        $data = $view->export_for_template($output);
        $this->assertTrue($data['bulkoperations']);
        $this->assertNotEmpty($data['bulkoperationstable']);
        $this->assertStringContainsString('bulkoperationstable', $data['bulkoperationstable']);

        // The table only contains the options of this booking instance.
        $pregmatch = preg_match(
            '/<div[^>]*\sdata-encodedtable=["\']?([^"\'>\s]+)["\']?/i',
            $data['bulkoperationstable'],
            $matches
        );
        $this->assertEquals(1, $pregmatch);
        $table = wunderbyte_table::instantiate_from_tablecache_hash($matches[1]);
        $table->printtable($table->pagesize, $table->useinitialsbar, $table->downloadhelpbutton);
        $this->assertEquals(3, $table->totalrows);

        // A user without the capability must not get the table.
        $this->setUser($student);
        singleton_service::destroy_instance();
        $view = new view($cmid, 'bulkoperations');
        $data = $view->export_for_template($output);
        $this->assertEmpty($data['bulkoperationstable']);
    }

    /**
     * Test the fulltextsearchcolumns instance setting: chosen columns are added to the full text search.
     *
     * @covers \mod_booking\output\view::apply_standard_params_for_bookingtable
     */
    public function test_fulltextsearchcolumns_instance_setting(): void {
        global $PAGE;

        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create a booking custom field.
        $bookingcat = $this->getDataGenerator()->create_custom_field_category([
            'name' => 'BookCustomCat1',
            'component' => 'mod_booking',
            'area' => 'booking',
            'itemid' => 0,
            'contextid' => \context_system::instance()->id,
        ]);
        $bookingcat->save();
        $bookingfield = $this->getDataGenerator()->create_custom_field([
            'categoryid' => $bookingcat->get('id'),
            'name' => 'Textfield',
            'shortname' => 'customcat',
            'type' => 'text',
            'configdata' => '',
        ]);
        $bookingfield->save();
        // A select field stores the option index, the wbt_field_controller resolves it to the label.
        $bookingfield = $this->getDataGenerator()->create_custom_field([
            'categoryid' => $bookingcat->get('id'),
            'name' => 'Language',
            'shortname' => 'customsel',
            'type' => 'select',
            'configdata' => '{"required":"0","uniquevalues":"0","options":"Latein\r\nGriechisch",'
                . '"defaultvalue":"","locked":"0","visibility":"0"}',
        ]);
        $bookingfield->save();

        $bdata = [
            'name' => 'Test Booking Fulltextsearch',
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
            'course' => $course->id,
            // The instance setting under test: add the custom fields to the full text search.
            'fulltextsearchcolumns' => ['customcat', 'customsel'],
        ];
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Value pairs: text customfield value, select customfield index (1 => Latein, 2 => Griechisch).
        $customfieldvalues = [
            ['Green Apple', '1'],
            ['Green Apple', '2'],
            ['Blue Banana', '2'],
        ];
        $cmid = 0;
        foreach ($customfieldvalues as $i => [$customfieldvalue, $selectindex]) {
            $record = (object) [
                'text' => "Option {$i} of booking {$booking->id}",
                'description' => 'Test Booking Option',
                'identifier' => "fts{$booking->id}-{$i}",
                'maxanswers' => 1,
                'bookingid' => $booking->id,
                'customfield_customcat' => $customfieldvalue,
                'customfield_customsel' => $selectindex,
                'coursestarttime_0' => strtotime('now + 3 day', time()),
                'courseendtime_0' => strtotime('now + 4 day', time()),
                'daystonotify_0' => '0',
                'optiondateid_0' => '0',
            ];
            $option = $plugingenerator->create_option($record);
            $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
            $cmid = $settings->cmid;
        }

        // The setting must be stored in the json column of the booking instance.
        singleton_service::destroy_instance();
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);
        $this->assertEquals(['customcat', 'customsel'], (array)$bookingsettings->fulltextsearchcolumns);

        $PAGE->set_url(new \moodle_url('/mod/booking/view.php', ['id' => $cmid]));
        // The all options table reads the cmid from the request via optional_param.
        $_GET['id'] = (string) $cmid;

        $view = new view($cmid, 'showall');
        $rendered = $view->get_rendered_all_options_table();
        $this->assertNotEmpty($rendered);
        $pregmatch = preg_match(
            '/<div[^>]*\sdata-encodedtable=["\']?([^"\'>\s]+)["\']?/i',
            $rendered,
            $matches
        );
        $this->assertEquals(1, $pregmatch);

        // The custom fields are part of the full text search columns. For the select field, the
        // resolved display value is searched as well - resolved internally by wunderbyte table.
        $table = wunderbyte_table::instantiate_from_tablecache_hash($matches[1]);
        $this->assertContains('customcat', $table->fulltextsearchcolumns);
        $this->assertContains('customsel', $table->fulltextsearchcolumns);
        $table->printtable($table->pagesize, $table->useinitialsbar, $table->downloadhelpbutton);
        $this->assertEquals(3, $table->totalrows);

        // Searching for a custom field value only returns the matching options. The select field
        // stores the index ('1'/'2'), so a hit for 'Latein' proves the resolved value is searched.
        $searchresults = [
            'Green Apple' => 2,
            'Latein' => 1,
            'Griechisch' => 2,
        ];
        foreach ($searchresults as $searchtext => $numberofrecords) {
            $searchtable = wunderbyte_table::instantiate_from_tablecache_hash($matches[1]);
            $searchtable->apply_searchtext($searchtext);
            $searchtable->printtable($searchtable->pagesize, $searchtable->useinitialsbar, $searchtable->downloadhelpbutton);
            $this->assertEquals(
                $numberofrecords,
                $searchtable->totalrows,
                "Unexpected number of records when searching for '$searchtext'"
            );
        }
    }

    /**
     * Test fulltextsearchcolumns with a dynamicformat customfield: the search must find the
     * display values resolved by the configured SQL, not only the stored keys.
     *
     * @covers \mod_booking\output\view::apply_standard_params_for_bookingtable
     * @covers \local_wunderbyte_table\local\customfield\wbt_field_controller_info::get_resolved_value_mapping
     */
    public function test_fulltextsearchcolumns_dynamicformat(): void {
        global $DB, $PAGE;

        if (!class_exists('customfield_dynamicformat\field_controller')) {
            $this->markTestSkipped('customfield_dynamicformat is not installed.');
        }

        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        $bookingcat = $this->getDataGenerator()->create_custom_field_category([
            'name' => 'BookCustomCat1',
            'component' => 'mod_booking',
            'area' => 'booking',
            'itemid' => 0,
            'contextid' => \context_system::instance()->id,
        ]);
        $bookingcat->save();
        $dynamicsql = "SELECT s1.id, s1.data FROM ("
            . " SELECT 'BER' AS id, 'Beratung (BER)' AS data UNION"
            . " SELECT 'SDM' AS id, 'Digitale Medien / Barcamp (SDM)' AS data"
            . " ) as s1";
        $bookingfield = $this->getDataGenerator()->create_custom_field([
            'categoryid' => $bookingcat->get('id'),
            'name' => 'ZLB',
            'shortname' => 'zlb',
            'type' => 'dynamicformat',
            'configdata' => json_encode([
                'required' => '0',
                'uniquevalues' => '0',
                'dynamicsql' => $dynamicsql,
                'defaultvalue' => '',
                'multiselect' => '1',
                'locked' => '0',
                'visibility' => '2',
            ]),
        ]);
        $bookingfield->save();
        $fieldid = $bookingfield->get('id');

        $bdata = [
            'name' => 'Test Booking Dynamicformat',
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
            'course' => $course->id,
            'fulltextsearchcolumns' => ['zlb'],
        ];
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // One option with a single key, one with a multiselect (comma separated) value, one without.
        $storedvalues = ['SDM', 'BER,SDM', ''];
        $cmid = 0;
        $optionids = [];
        foreach ($storedvalues as $i => $storedvalue) {
            $record = (object) [
                'text' => "Option {$i} of booking {$booking->id}",
                'description' => 'Test Booking Option',
                'identifier' => "dyn{$booking->id}-{$i}",
                'maxanswers' => 1,
                'bookingid' => $booking->id,
                'coursestarttime_0' => strtotime('now + 3 day', time()),
                'courseendtime_0' => strtotime('now + 4 day', time()),
                'daystonotify_0' => '0',
                'optiondateid_0' => '0',
            ];
            $option = $plugingenerator->create_option($record);
            $optionids[] = $option->id;
            $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
            $cmid = $settings->cmid;
            if ($storedvalue !== '') {
                // Write the value the same way the dynamicformat data controller stores it.
                $DB->insert_record('customfield_data', (object) [
                    'fieldid' => $fieldid,
                    'instanceid' => $option->id,
                    'value' => $storedvalue,
                    'valueformat' => 0,
                    'contextid' => \context_system::instance()->id,
                    'timecreated' => time(),
                    'timemodified' => time(),
                ]);
            }
        }

        singleton_service::destroy_instance();

        $PAGE->set_url(new \moodle_url('/mod/booking/view.php', ['id' => $cmid]));
        $_GET['id'] = (string) $cmid;

        $view = new view($cmid, 'showall');
        $rendered = $view->get_rendered_all_options_table();
        preg_match('/<div[^>]*\sdata-encodedtable=["\']?([^"\'>\s]+)["\']?/i', $rendered, $matches);
        $this->assertNotEmpty($matches, 'Could not extract the encoded table from the rendered view.');

        $table = wunderbyte_table::instantiate_from_tablecache_hash($matches[1]);
        $this->assertContains('zlb', $table->fulltextsearchcolumns);

        // Note: 'Digitale Medien' is only part of the resolved value of the key 'SDM'.
        $searchresults = [
            'Digitale Medien' => 2,
            'Beratung' => 1,
            'SDM' => 2,
        ];
        foreach ($searchresults as $searchtext => $numberofrecords) {
            $searchtable = wunderbyte_table::instantiate_from_tablecache_hash($matches[1]);
            $searchtable->apply_searchtext($searchtext);
            $searchtable->printtable($searchtable->pagesize, $searchtable->useinitialsbar, $searchtable->downloadhelpbutton);
            $this->assertEquals(
                $numberofrecords,
                $searchtable->totalrows,
                "Unexpected number of records when searching for '$searchtext'"
            );
        }
    }
}
