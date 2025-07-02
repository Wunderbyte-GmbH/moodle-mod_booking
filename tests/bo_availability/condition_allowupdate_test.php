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
use tool_mocktesttime\time_mock;

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
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
    }

    /**
     * Test booking, cancelation, option has started etc.
     *
     * @covers \mod_booking\bo_availability\conditions\iscancelled::is_available
     * @covers \mod_booking\bo_availability\conditions\optionhasstarted::is_available
     *
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
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ISCANCELLED, $id);

        // Now we undo cancel of the booking option.
        booking_option::cancelbookingoption($settings->id, '', true);

        // Try to book again with user1.
        $this->setUser($student1);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_OPTIONHASSTARTED, $id);
    }

    /**
     * Test cancelation with all cancelrelativedate options.
     *
     * @covers \mod_booking\bo_availability\conditions\iscancelled::is_available
     * @covers \mod_booking\bo_availability\conditions\optionhasstarted::is_available
     *
     * @param array $bdata
     *
     * @return void
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_booking_bookit_cancelrelativedate(array $bdata): void {
        global $DB, $CFG;

        singleton_service::destroy_instance();

        $bdata['cancancelbook'] = 0;
        $bdata['allowupdate'] = 1;

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

        $bdata['cancancelbook'] = 0;

        $bookings = [];
        // Booking option where cancel is not allowed.
        $bookings[0] = $this->getDataGenerator()->create_module('booking', $bdata);

        // Booking option where cancel is allowed.
        $bdata['cancancelbook'] = 1;
        $bookings[1] = $this->getDataGenerator()->create_module('booking', $bdata);

        // Booking option where cancel is allowed until precise date (5 days from now).
        // So allowed now.
        $bdata['cancancelbook'] = 1;
        $bdata['allowupdatetimestamp'] = strtotime('now + 5 days');
        $bdata['cancelrelativedate'] = 0;
        $bookings[2] = $this->getDataGenerator()->create_module('booking', $bdata);

        // Booking option where cancel is allowed until precise date (5 days from now).
        // So not allowed now.
        $bdata['cancancelbook'] = 1;
        $bdata['allowupdatetimestamp'] = strtotime('now - 5 days');
        $bdata['cancelrelativedate'] = 0;
        $bookings[3] = $this->getDataGenerator()->create_module('booking', $bdata);

        // Booking option where cancel is allowed only 20 days before coursestart.
        // So, not allowed now.
        $bdata['cancancelbook'] = 1;
        $bdata['cancelrelativedate'] = 1;
        $bdata['allowupdatedays'] = 20;
        $bookings[4] = $this->getDataGenerator()->create_module('booking', $bdata);

        // Booking option where cancel is allowed only 2 days before coursestart.
        // So, allowed now.
        $bdata['cancancelbook'] = 1;
        $bdata['cancelrelativedate'] = 1;
        $bdata['allowupdatedays'] = 2;
        $bookings[5] = $this->getDataGenerator()->create_module('booking', $bdata);

        // Booking option where cancel is without limit.
        // So, allowed now.
        $bdata['cancancelbook'] = 1;
        $bdata['cancelrelativedate'] = 2;
        $bookings[6] = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($admin->id, $course->id);
        $this->getDataGenerator()->enrol_user($student1->id, $course->id);
        $this->getDataGenerator()->enrol_user($student2->id, $course->id);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id);

        $record1 = new stdClass();
        $record1->text = 'Test option1';
        $record1->courseid = 0;
        $record1->maxanswers = 2;
        $record1->coursestarttime = strtotime('now + 10 days');
        $record1->courseendtime = strtotime('now + 12 days');

        $record2 = new stdClass();
        $record2->text = 'Test option1';
        $record2->courseid = 0;
        $record2->maxanswers = 2;
        $record2->coursestarttime = strtotime('now - 10 days');
        $record2->courseendtime = strtotime('now + 12 days');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $expectedresults1 = [
            0 => MOD_BOOKING_BO_COND_ALREADYBOOKED,
            1 => MOD_BOOKING_BO_COND_CONFIRMCANCEL,
            2 => MOD_BOOKING_BO_COND_CONFIRMCANCEL,
            3 => MOD_BOOKING_BO_COND_ALREADYBOOKED,
            4 => MOD_BOOKING_BO_COND_ALREADYBOOKED,
            5 => MOD_BOOKING_BO_COND_CONFIRMCANCEL,
            6 => MOD_BOOKING_BO_COND_CONFIRMCANCEL,
        ];

        $expectedresults2 = [
            0 => MOD_BOOKING_BO_COND_ALREADYBOOKED,
            1 => MOD_BOOKING_BO_COND_CONFIRMCANCEL,
            2 => MOD_BOOKING_BO_COND_CONFIRMCANCEL,
            3 => MOD_BOOKING_BO_COND_ALREADYBOOKED,
            4 => MOD_BOOKING_BO_COND_ALREADYBOOKED,
            5 => MOD_BOOKING_BO_COND_ALREADYBOOKED,
            6 => MOD_BOOKING_BO_COND_CONFIRMCANCEL,
        ];

        foreach ($bookings as $key => $booking) {
            $this->setAdminUser();
            $record1->bookingid = $booking->id;
            $record2->bookingid = $booking->id;
            $option1 = $plugingenerator->create_option($record1);
            $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);
            $boinfo1 = new bo_info($settings1);

            $option2 = $plugingenerator->create_option($record2);
            $settings2 = singleton_service::get_instance_of_booking_option_settings($option2->id);
            $boinfo2 = new bo_info($settings2);

            // Try to book again with user1.
            $this->setUser($student1);
            $result = booking_bookit::bookit('option', $settings1->id, $student1->id);
            $result = booking_bookit::bookit('option', $settings1->id, $student1->id);

            $result = booking_bookit::bookit('option', $settings1->id, $student1->id);
            [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student1->id, true);
            $this->assertEquals($expectedresults1[$key], $id);

            $result = booking_bookit::bookit('option', $settings2->id, $student1->id);
            $result = booking_bookit::bookit('option', $settings2->id, $student1->id);

            $result = booking_bookit::bookit('option', $settings2->id, $student1->id);
            [$id, $isavailable, $description] = $boinfo1->is_available($settings2->id, $student1->id, true);
            $this->assertEquals($expectedresults2[$key], $id);
        }
    }

    /**
     * Test isbookable, bookitbutton, alreadybooked.
     *
     * @covers \mod_booking\bo_availability\conditions\isbookable::is_available
     * @covers \mod_booking\bo_availability\conditions\isbookableinstance::is_available
     * @covers \mod_booking\bo_availability\conditions\bookitbutton::is_available
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_booking_bookit_isbookable(array $bdata): void {
        global $DB, $CFG;

        // Setup test data.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1], ['createsections' => true]);

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

        // Setup booking option with disabled booking.
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

        // Try to book the student1 and validate that booking is disabled for the option1.
        $this->setUser($student1);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ISBOOKABLE, $id);

        // Now we enable option1 booking permission back.
        $this->setAdminUser();
        $record->id = $option1->id;
        $record->cmid = $settings1->cmid;
        $record->disablebookingusers = 0;
        booking_option::update($record);

        // Try to book again with user1 to confirm that it is accessible.
        $this->setUser($student1);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);

        // Update entire booking instance to block any bookigs.
        $this->setAdminUser();
        // Get booking as coursemodule info.
        $cm = get_coursemodule_from_instance('booking', $settings1->bookingid);
        [$cm, $context, $module, $bookingdata, $cw] = get_moduleinfo_data($cm, $course);
        // Add json settings and block booking.
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_bookingid($settings1->bookingid);
        $bookingdata->json = $bookingsettings->json;
        $bookingdata->disablebooking = 1;
        booking::add_data_to_json($bookingdata, "disablebooking", 1);
        // Update booking instance and validate new setting.
        booking::purge_cache_for_booking_instance_by_cmid($cm->id);
        $DB->update_record('booking', $bookingdata);
        singleton_service::destroy_booking_singleton_by_cmid($settings1->cmid);
        $this->assertEquals(1, booking::get_value_of_json_by_key((int) $settings1->bookingid, "disablebooking"));

        $this->setUser($student1);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ISBOOKABLEINSTANCE, $id);
    }

    /**
     * Test subbookings - person.
     *
     * @covers \mod_booking\bo_availability\conditions\subbooking_blocks::is_available
     * @covers \mod_booking\bo_availability\conditions\subbooking::is_available
     * @covers \mod_booking\subbookings\subbookings_info
     * @covers \mod_booking\subbookings\sb_types\subbooking_additionalperson
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
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('now + 3 day');
        $record->courseendtime_0 = strtotime('now + 6 day');

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
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student1->id, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_SUBBOOKING, $id);

        // Book option1 by student1.
        $result = booking_bookit::bookit('option', $settings1->id, $student1->id);
        $result = booking_bookit::bookit('option', $settings1->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student1->id, true);
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
        [$id, $isavailable, $description] = $boinfo2->is_available($settings2->id, $student2->id, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_SUBBOOKINGBLOCKS, $id);

        // Book option2 by student2.
        $result = booking_bookit::bookit('option', $settings2->id, $student2->id);
        $result = booking_bookit::bookit('option', $settings2->id, $student2->id);
        [$id, $isavailable, $description] = $boinfo2->is_available($settings2->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

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
            'showviews' => ['mybooking,myoptions,optionsiamresponsiblefor,showall,showactive,myinstitution'],
        ];
        return ['bdata' => [$bdata]];
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
