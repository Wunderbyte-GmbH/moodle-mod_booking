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
 * Tests for booking option field class teachers.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2025 Bernhard Fischer-Sengseis
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use coding_exception;
use mod_booking\bo_availability\bo_info;
use mod_booking\option\fields_info;
use mod_booking_generator;
use stdClass;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once("$CFG->dirroot/mod/booking/lib.php");
require_once("$CFG->dirroot/mod/booking/classes/price.php");

/**
 * Tests for booking option field class teachers.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2025 Bernhard Fischer-Sengseis
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class sharedplaces_test extends advanced_testcase {
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
     * Test shared places functionalitiy
     *
     * @covers \mod_booking\option\fields\sharedplaces
     *
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function test_sharedplaces(): void {
        global $DB;
        $bdata = self::provide_bdata();

        // Course is needed for module generator.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $student3 = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        // Create an initial booking option.
        // The option has 2 optiondates and 1 teacher.
        $record = new stdClass();
        $record->importing = 1;
        $record->bookingid = $booking->id;
        $record->text = 'Option A';
        $record->description = 'Test shared places';
        $record->useprice = 0;
        $record->maxanswers = 1;
        $record->maxoverbooking = 2;
        $record->default = 0;
        $record->optiondateid_1 = "0";
        $record->daystonotify_1 = "0";
        $record->coursestarttime_1 = strtotime('20 May 2050 15:00');
        $record->courseendtime_1 = strtotime('20 May 2050 16:00');

        // Create the booking option.
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $optiona = $plugingenerator->create_option($record);

        $this->getDataGenerator()->enrol_user($student1->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student3->id, $course->id, 'student');

        $record->text = 'Option B';
        $record->coursestarttime_1 = strtotime('21 May 2050 15:00');
        $record->courseendtime_1 = strtotime('21 May 2050 16:00');
        $optionb = $plugingenerator->create_option($record);

        $record->text = 'Option C';
        $record->coursestarttime_1 = strtotime('20 May 2050 15:00');
        $record->courseendtime_1 = strtotime('20 May 2050 16:00');
        $record->optiondateid_2 = "0";
        $record->daystonotify_2 = "0";
        $record->coursestarttime_2 = strtotime('21 May 2050 15:00');
        $record->courseendtime_2 = strtotime('21 May 2050 16:00');
        $record->sharedplaceswithoptions = [$optiona->id, $optionb->id];
        $optionc = $plugingenerator->create_option($record);

        $record = [
            'id' => $optiona->id,
            'cmid' => $optiona->cmid,
            'sharedplaceswithoptions' => ["$optionc->id"],
        ];
        booking_option::update($record);

        $record = [
            'id' => $optionb->id,
            'cmid' => $optionb->cmid,
            'sharedplaceswithoptions' => ["$optionc->id"],
        ];
        booking_option::update($record);

        // Now book the first user.
        // We have two free places.
        $settingsa = singleton_service::get_instance_of_booking_option_settings($optiona->id);
        $baa = singleton_service::get_instance_of_booking_answers($settingsa);
        $bookinginformation = $baa->return_all_booking_information($student1->id);

        $this->assertEquals(1, $bookinginformation["notbooked"]["freeonlist"]);

        $boinfo = new bo_info($settingsa);
        $result = booking_bookit::bookit('option', $settingsa->id, $student1->id);
        $result = booking_bookit::bookit('option', $settingsa->id, $student1->id);

        $baa = singleton_service::get_instance_of_booking_answers($settingsa);
        $bookinginformation = $baa->return_all_booking_information($student2->id);
        // User 1 is successfully booked.
        // Not booked because we fetch info for user 2.
        $this->assertEquals(0, $bookinginformation["notbooked"]["freeonlist"]);

        // Now book user 2 in option B.
        $settingsb = singleton_service::get_instance_of_booking_option_settings($optionb->id);

        $boinfo = new bo_info($settingsa);
        $result = booking_bookit::bookit('option', $settingsb->id, $student2->id);
        $result = booking_bookit::bookit('option', $settingsb->id, $student2->id);

        $bab = singleton_service::get_instance_of_booking_answers($settingsb);
        $bookinginformation = $bab->return_all_booking_information($student1->id);

        // We used student 1 booking informatioon, so not booked.
        $this->assertEquals(2, $bookinginformation["notbooked"]["freeonwaitinglist"]);

        // Now look at option c.
        $settingsc = singleton_service::get_instance_of_booking_option_settings($optionc->id);
        $bac = singleton_service::get_instance_of_booking_answers($settingsc);
        $bookinginformation = $bac->return_all_booking_information($student1->id);

        $this->assertEquals(0, $bookinginformation["notbooked"]["freeonlist"]);
        $this->assertEquals(2, $bookinginformation["notbooked"]["freeonwaitinglist"]);

        // Now we queue students on option A and on option C.
        $result = booking_bookit::bookit('option', $settingsa->id, $student2->id);
        $result = booking_bookit::bookit('option', $settingsa->id, $student2->id);

        $baa = singleton_service::get_instance_of_booking_answers($settingsa);
        $bookinginformation = $baa->return_all_booking_information($student2->id);

        $this->assertEquals(0, $bookinginformation["onwaitinglist"]["freeonlist"]);
        $this->assertEquals(1, $bookinginformation["onwaitinglist"]["freeonwaitinglist"]);

        // Now we queue a user on Option C.
        $result = booking_bookit::bookit('option', $settingsc->id, $student3->id);
        $result = booking_bookit::bookit('option', $settingsc->id, $student3->id);

        $bac = singleton_service::get_instance_of_booking_answers($settingsc);
        $bookinginformation = $bac->return_all_booking_information($student1->id);

        $this->assertEquals(0, $bookinginformation["notbooked"]["freeonlist"]);
        $this->assertEquals(0, $bookinginformation["notbooked"]["freeonwaitinglist"]);

        // Now delete student A from Booking option A.
        $option = singleton_service::get_instance_of_booking_option($settingsa->cmid, $settingsa->id);
        $option->user_delete_response($student1->id);

        $baa = singleton_service::get_instance_of_booking_answers($settingsa);
        $bookinginformation = $baa->return_all_booking_information($student2->id);
        $this->assertEquals(1, $bookinginformation["iambooked"]["freeonwaitinglist"]);

        $bac = singleton_service::get_instance_of_booking_answers($settingsc);
        $bookinginformation = $bac->return_all_booking_information($student3->id);
        $this->assertEquals(1, $bookinginformation["onwaitinglist"]["freeonwaitinglist"]);

        // Now we update with the priority checkbox.
        $record = [
            'id' => $optionc->id,
            'cmid' => $optionc->cmid,
            'sharedplaceswithoptions' => ["$optiona->id", "$optionb->id"],
            'sharedplacespriority' => "1",
        ];
        booking_option::update($record);

        // We book student one again on option A.
        $boinfo = new bo_info($settingsa);
        $result = booking_bookit::bookit('option', $settingsa->id, $student1->id);
        $result = booking_bookit::bookit('option', $settingsa->id, $student1->id);

        // We take student two off option A.
        $option->user_delete_response($student2->id);

        // Because student B is still blocking the queue of option C, it's student 1 coming in.
        $baa = singleton_service::get_instance_of_booking_answers($settingsa);
        $bookinginformation = $baa->return_all_booking_information($student1->id);
        $this->assertEquals(1, $bookinginformation["iambooked"]["freeonwaitinglist"]);

        // We book student 2 again on the waitinglist of option A.
        $result = booking_bookit::bookit('option', $settingsa->id, $student2->id);
        $result = booking_bookit::bookit('option', $settingsa->id, $student2->id);

        // Now we delete first student 2 from Option B.
        $option = singleton_service::get_instance_of_booking_option($settingsb->cmid, $settingsb->id);
        $option->user_delete_response($student2->id);

        // Option B has no one booked and no waitnglist.
        // But because it's linked with C, we get the C information.
        $bab = singleton_service::get_instance_of_booking_answers($settingsb);
        $bookinginformation = $bac->return_all_booking_information($student2->id);
        $this->assertEquals(0, $bookinginformation["notbooked"]["freeonwaitinglist"]);
        $this->assertEquals(0, $bookinginformation["notbooked"]["freeonlist"]);

        // Now we delete first student 2 from Option A.
        $option = singleton_service::get_instance_of_booking_option($settingsa->cmid, $settingsa->id);
        $option->user_delete_response($student1->id);

        // Now it's student 3 coming in from Option C to be booked before, because of priority.
        $bac = singleton_service::get_instance_of_booking_answers($settingsc);
        $bookinginformation = $bac->return_all_booking_information($student3->id);
        $this->assertEquals(1, $bookinginformation["iambooked"]["freeonwaitinglist"]);

        // Student 1 is still on the waitinglist.
        $baa = singleton_service::get_instance_of_booking_answers($settingsa);
        $bookinginformation = $baa->return_all_booking_information($student2->id);
        $this->assertEquals(1, $bookinginformation["onwaitinglist"]["freeonwaitinglist"]);

        // TearDown at the very end.
        self::tearDown();
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
