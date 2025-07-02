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
final class moveoption_test extends advanced_testcase {
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
     * Test move option
     *
     * @covers \mod_booking\option\fields\moveoption
     *
     * @param array $data
     * @param array $expected
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_moveoption(array $data, array $expected): void {
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
        $booking2 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course->id);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id);

        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create an initial booking option.
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1';
        $record->courseid = $course->id;
        $record->importing = 1;
        $record->coursestarttime = strtotime('2025-01-01 10:00:00');
        $record->courseendtime = strtotime('2025-01-01 12:00:00');
        $record->useprice = 0;
        $record->default = 50;
        $record->teacheremail = $teacher->email;

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($record);

        // We store this option id for later.
        $optionid = $option1->id;

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        // To avoid retrieving the singleton with the wrong settings, we destroy it.
        singleton_service::destroy_booking_singleton_by_cmid($settings->cmid);

        $boinfo = new bo_info($settings);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id);
        // The user sees now either the payment button or the noshoppingcart message.
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);

        // Book student2 and verify it.
        $result = booking_bookit::bookit('option', $settings->id, $student2->id);
        $result = booking_bookit::bookit('option', $settings->id, $student2->id);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id);
        // The user sees now either the payment button or the noshoppingcart message.
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        $ba = singleton_service::get_instance_of_booking_answers($settings);
        $this->assertCount(1, $ba->usersonlist);

        $this->assertEquals($booking1->id, $settings->bookingid);

        // Make sure we only have the right records in the old instance.
        $records = $DB->get_records('booking_answers', ['bookingid' => $booking1->id]);
        $this->assertCount(1, $records);

        $records = $DB->get_records('booking_teachers', ['bookingid' => $booking1->id]);
        $this->assertCount(1, $records);

        $records = $DB->get_records('booking_optiondates', ['bookingid' => $booking1->id]);
        $this->assertCount(1, $records);

        // Make sure we only have now records in the new instance.
        $records = $DB->get_records('booking_answers', ['bookingid' => $booking2->id]);
        $this->assertCount(0, $records);

        $records = $DB->get_records('booking_teachers', ['bookingid' => $booking2->id]);
        $this->assertCount(0, $records);

        $records = $DB->get_records('booking_optiondates', ['bookingid' => $booking2->id]);
        $this->assertCount(0, $records);

        $cm = get_coursemodule_from_instance('booking', $booking2->id);
        // Now we update with move option.
        booking_option::update((object)[
            'id' => $optionid,
            'cmid' => $settings->cmid,
            'bookingid' => $booking1->id,
            'moveoption' => $cm->id,
            'importing' => 1,
        ]);

        singleton_service::destroy_instance();

        // We still have the same option id.
        $newsettings = singleton_service::get_instance_of_booking_option_settings($optionid);

        // We expect that the option is now in the new booking instance.
        $this->assertEquals($booking2->id, $newsettings->bookingid);

        $records = $DB->get_records('booking_answers', ['bookingid' => $booking2->id]);
        $this->assertCount(1, $records);

        $records = $DB->get_records('booking_teachers', ['bookingid' => $booking2->id]);
        $this->assertCount(1, $records);

        $records = $DB->get_records('booking_optiondates', ['bookingid' => $booking2->id]);
        $this->assertCount(1, $records);

        // Make sure we only now records in the old instance.
        $records = $DB->get_records('booking_answers', ['bookingid' => $booking1->id]);
        $this->assertCount(0, $records);

        $records = $DB->get_records('booking_teachers', ['bookingid' => $booking1->id]);
        $this->assertCount(0, $records);

        $records = $DB->get_records('booking_optiondates', ['bookingid' => $booking1->id]);
        $this->assertCount(0, $records);

        // Check if a user is booked correctly.
        $ba = singleton_service::get_instance_of_booking_answers($newsettings);
        $this->assertCount(1, $ba->usersonlist);
    }
    /**
     * Data provider for condition_bookingpolicy_test
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    public static function booking_common_settings_provider(): array {

        return [
            'move_option' => [
                [],
                [
                    'totaloptions' => 3,
                    'delta1' => '1 week',
                    'delta2' => '2 weeks',
                    'previouslybooked' => true,
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
