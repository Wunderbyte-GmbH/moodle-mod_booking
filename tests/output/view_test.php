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

        // A user without the capability does not get the table and falls back to the "All options" tab.
        $this->setUser($student);
        singleton_service::destroy_instance();
        $view = new view($cmid, 'bulkoperations');
        $data = $view->export_for_template($output);
        $this->assertEmpty($data['bulkoperationstable']);
        $this->assertTrue($data['showall']);
        $this->assertNotEmpty($data['alloptionstable']);
    }
}
