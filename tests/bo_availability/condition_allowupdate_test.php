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
 * Tests for booking option policy.
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
use mod_booking_generator;
use context_system;
use mod_booking\bo_availability\bo_info;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Class handling tests for booking options policy.
 *
 * @package mod_booking
 * @category test
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class condition_allowupdate_test extends advanced_testcase {

    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Test booking, cancelation, option has started etc.
     *
     * @covers \condition\iscancelled::is_available
     * @covers \condition\hasstarted::is_available
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_booking_bookit_allowupdate(array $bdata): void {
        global $DB, $CFG;

        $bdata['cancancelbook'] = 1;
        $bdata['allowupdate'] = 0;

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

        $this->getDataGenerator()->enrol_user($admin->id, $course->id);
        $this->getDataGenerator()->enrol_user($student1->id, $course->id);
        $this->getDataGenerator()->enrol_user($student2->id, $course->id);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1';
        $record->courseid = 0;
        $record->maxanswers = 2;
        $record->coursestarttime = strtotime('now - 2 day');
        $record->courseendtime = strtotime('now + 2 day');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);

        // Book the first user without any problem.
        $boinfo = new bo_info($settings);

         // Now we cancel the whole booking option.
        booking_option::cancelbookingoption($option1->id);

        // Book the student right away.
        $this->setUser($student1);

        // Try to book again with user1.
        list($id, $isavailable, $description) = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ISCANCELLED, $id);

        // Now we undo cancel of the booking option.
        booking_option::cancelbookingoption($settings->id, '', true);

        // Try to book again with user1.
        $this->setUser($student1);
        list($id, $isavailable, $description) = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_OPTIONHASSTARTED, $id);
    }

    /**
     * Test isbookable, bookitbutton, alreadybooked.
     *
     * @covers \condition\isbookable::is_available
     * @covers \condition\bookitbutton::is_available
     * @covers \condition\alreadybooked::is_available
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_booking_bookit_isbookable(array $bdata): void {
        global $DB, $CFG;

        // Setup test data.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course->id);
        $this->getDataGenerator()->enrol_user($student2->id, $course->id);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1';
        $record->courseid = 0;
        $record->maxanswers = 2;
        $record->disablebookingusers = 1;

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($record);
        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $boinfo1 = new bo_info($settings1);

        // Try to book the student1.
        $this->setUser($student1);

        // Try to book again with user1.
        list($id, $isavailable, $description) = $boinfo1->is_available($settings1->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ISBOOKABLE, $id);

        // Now we enable option1 back.
        $this->setAdminUser();
        $record->id = $option1->id;
        $record->cmid = $settings1->cmid;
        $record->disablebookingusers = 0;
        booking_option::update($record);

        // Try to book again with user1.
        $this->setUser($student1);
        list($id, $isavailable, $description) = $boinfo1->is_available($settings1->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);

        // That was just for fun. Now we make sure the student1 will be booked.
        $result = booking_bookit::bookit('option', $settings1->id, $student1->id);
        $result = booking_bookit::bookit('option', $settings1->id, $student1->id);
        list($id, $isavailable, $description) = $boinfo1->is_available($settings1->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);
    }

    /**
     * Test subbookings - person.
     *
     * @covers \condition\subbooking_blocks::is_available
     * @covers \condition\subbooking::is_available
     * @covers \subbookings\booking_subbooking
     * @covers \subbookings\sb_types\subbooking_additionalperson
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_booking_bookit_subbookings(array $bdata): void {
        global $DB, $CFG;

        // Setup test data.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $student3 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course->id);
        $this->getDataGenerator()->enrol_user($student2->id, $course->id);
        $this->getDataGenerator()->enrol_user($student3->id, $course->id);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course->id;
        $record->useprice = 0;
        $record->maxanswers = 3;
        $record->optiondateid_1 = "0";
        $record->daystonotify_1 = "0";
        $record->coursestarttime_1 = strtotime('now + 3 day');
        $record->courseendtime_1 = strtotime('now + 6 day');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id); // Mandatory there.

        // Create subbokingdata.
        $subbokingdata = (object)[
            'name' => 'Partner(s)',
            'type' => 'subbooking_additionalperson',
            'data' => (object)[
                'description' => 'You can invite your partner(s):',
                'descriptionformat' => 1,
            ],
        ];
        $subboking = (object)[
            'name' => 'Partner(s)', 'type' => 'subbooking_additionalperson',
            'block' => 0, 'optionid' => $option1->id,
            'json' => json_encode($subbokingdata),
        ];
        $plugingenerator->create_subbooking($subboking);

        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $boinfo1 = new bo_info($settings1);

        // Validate subbooking presence.
        $phpunitversion = (float)\PHPUnit\Runner\Version::series();
        if ($phpunitversion < 9.6) {
            $this->assertObjectHasAttribute('subbookings', $settings1);
        } else {
            $this->assertObjectHasProperty('subbookings', $settings1);
        }
        $this->assertIsArray($settings1->subbookings);
        $this->assertCount(1, $settings1->subbookings);
        $subbookingobj = $settings1->subbookings[0];
        $this->assertInstanceOf('mod_booking\subbookings\sb_types\subbooking_additionalperson', $subbookingobj);
        $this->assertEquals($subboking->name, $subbookingobj->name);
        $this->assertEquals($subboking->type, $subbookingobj->type);

        $this->setUser($student1);
        // Validate that subboking is available and non-bloking.
        list($id, $isavailable, $description) = $boinfo1->is_available($settings1->id, $student1->id, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_SUBBOOKING, $id);

        // Book option1 by student1.
        $result = booking_bookit::bookit('option', $settings1->id, $student1->id);
        $result = booking_bookit::bookit('option', $settings1->id, $student1->id);
        list($id, $isavailable, $description) = $boinfo1->is_available($settings1->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Set blocking subbooking.
        $this->setAdminUser();
        $record->text = 'Test option2 (bloked)';
        $option2 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option2->id); // Mandatory there.
        // Create blocking subboking.
        $subboking = (object)[
            'name' => 'Partner(s)', 'type' => 'subbooking_additionalperson',
            'block' => 1, 'optionid' => $option2->id,
            'json' => json_encode($subbokingdata),
        ];
        $plugingenerator->create_subbooking($subboking);

        $settings2 = singleton_service::get_instance_of_booking_option_settings($option2->id);
        $boinfo2 = new bo_info($settings2);

        // Validate subbooking presence.
        if ($phpunitversion < 9.6) {
            $this->assertObjectHasAttribute('subbookings', $settings2);
        } else {
            $this->assertObjectHasProperty('subbookings', $settings2);
        }
        $this->assertIsArray($settings2->subbookings);
        $this->assertCount(1, $settings2->subbookings);
        $subbookingobj = $settings2->subbookings[0];
        $this->assertInstanceOf('mod_booking\subbookings\sb_types\subbooking_additionalperson', $subbookingobj);
        $this->assertEquals($subboking->name, $subbookingobj->name);
        $this->assertEquals($subboking->type, $subbookingobj->type);

        // Try to book option2 with student2.
        $this->setUser($student2);
        // Validate that subboking is available and non-bloking.
        list($id, $isavailable, $description) = $boinfo2->is_available($settings2->id, $student2->id, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_SUBBOOKINGBLOCKS, $id);

        // Book option2 by student2.
        $result = booking_bookit::bookit('option', $settings2->id, $student2->id);
        $result = booking_bookit::bookit('option', $settings2->id, $student2->id);
        list($id, $isavailable, $description) = $boinfo2->is_available($settings2->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);
        // TODO: how to make subboking in code?

        // Mandatory to solve potential cache issues.
        singleton_service::destroy_booking_option_singleton($option1->id);
        singleton_service::destroy_booking_option_singleton($option2->id);
    }

    /**
     * Data provider for condition_allowupdate_test
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    public static function booking_common_settings_provider(): array {
        $bdata = [
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
            'notificationtext' => ['text' => 'text'], 'userleave' => ['text' => 'text'],
            'tags' => '',
            'completion' => 2,
            'showviews' => ['mybooking,myoptions,showall,showactive,myinstitution'],
        ];
        return ['bdata' => [$bdata]];
    }
}
