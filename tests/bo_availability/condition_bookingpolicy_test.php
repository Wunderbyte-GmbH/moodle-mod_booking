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
use mod_booking\bo_availability\bo_info;
use mod_booking\bo_availability\conditions\customform;
use mod_booking\local\mobile\customformstore;
// phpcs:ignore
//use core\cron;
use mod_booking\booking_rules\booking_rules;
use mod_booking\booking_rules\rules_info;
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
final class condition_bookingpolicy_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        // Mandatory clean-up.
        singleton_service::destroy_instance();
    }

    /**
     * Test booking option availability: \condition\bookingpolicy.
     *
     * @covers \mod_booking\bo_availability\conditions\bookingpolicy::is_available
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_settings_provider
     */
    public function test_booking_policy(array $bdata): void {

        // Set test objective setting(s).
        $bdata['bookingpolicy'] = 'policy';

        // Setup test data.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $admin = $this->getDataGenerator()->create_user();
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);
        // Mandatory to solve potential cache issues.
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_bookingid($booking1->id);
        singleton_service::destroy_booking_singleton_by_cmid($bookingsettings->cmid);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($admin->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student1->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id);
        $this->getDataGenerator()->enrol_user($teacher->id, $course1->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course1->id);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1';
        $record->courseid = $course1->id;

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $boinfo = new bo_info($settings);

        // Book the student1.
        $this->setUser($student1);
        // Not allowed until policy agreed.
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        list($id, $isavailable, $description) = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKINGPOLICY, $id);

        $this->setAdminUser();
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        // In this test, we book the user directly (user don't confirm policy).
        $option->user_submit_response($student1, 0, 0, 0, MOD_BOOKING_VERIFIED);

        // Verify that user already booked.
        list($id, $isavailable, $description) = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);
    }

    /**
     * Test booking option availability: \condition\customform.
     *
     * @covers \mod_booking\bo_availability\conditions\customform::is_available
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_settings_provider
     */
    public function test_booking_customform(array $bdata): void {

        // Setup test data.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $admin = $this->getDataGenerator()->create_user();
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);
        // Mandatory to solve potential cache issues.
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_bookingid($booking1->id);
        singleton_service::destroy_booking_singleton_by_cmid($bookingsettings->cmid);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($admin->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student1->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id);
        $this->getDataGenerator()->enrol_user($teacher->id, $course1->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course1->id);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course1->id;
        // Set test objective setting(s).
        $record->bo_cond_customform_restrict = 1;
        $record->bo_cond_customform_select_1_1 = 'static';
        $record->bo_cond_customform_label_1_1 = 'Static: label';
        $record->bo_cond_customform_value_1_1 = 'Static: confirm';
        $record->bo_cond_customform_select_1_2 = 'advcheckbox';
        $record->bo_cond_customform_label_1_2 = 'agree';
        $record->bo_cond_customform_select_1_3 = 'url';
        $record->bo_cond_customform_label_1_3 = 'Provide: URL';
        $record->bo_cond_customform_value_1_1 = 'Provide a valid URL';
        $record->bo_cond_customform_notempty_1_3 = 1;
        $record->bo_cond_customform_select_1_4 = 'mail';
        $record->bo_cond_customform_label_1_4 = 'Provide: email';
        $record->bo_cond_customform_value_1_1 = 'Provide a valid email';
        $record->bo_cond_customform_notempty_1_4 = 1;

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $boinfo = new bo_info($settings);

        // Book the student1.
        $this->setUser($student1);

        // Not allowed until policy agreed.
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        list($id, $isavailable, $description) = $boinfo->is_available($settings->id, $student1->id, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_JSON_CUSTOMFORM, $id);

        $customformdata = (object) [
            'id' => $settings->id,
            'userid' => $student1->id,
            'customform_select_2' => 1,
            'customform_url_3' => 'https://test.com',
            'customform_mail_4' => 'test@test.com',
        ];
        $customformstore = new customformstore($student1->id, $settings->id);
        $customformstore->set_customform_data($customformdata);

        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        list($id, $isavailable, $description) = $boinfo->is_available($settings->id, $student1->id, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        $this->setAdminUser();
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        // Validate student1's response.
        $bookedusers = $option->get_all_users_booked();
        $this->assertCount(1, $bookedusers);
        $bookeduser = reset($bookedusers);
        $this->assertEquals($student1->id, $bookeduser->userid);
        $this->assertEquals($customformdata->customform_select_2, $bookeduser->customform_select_2);
        $this->assertEquals($customformdata->customform_url_3, $bookeduser->customform_url_3);
        $this->assertEquals($customformdata->customform_mail_4, $bookeduser->customform_mail_4);
    }

    /**
     * Test booking option availability: \condition\max_number_of_bookings.
     *
     * @covers \mod_booking\bo_availability\conditions\max_number_of_bookings::is_available
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_settings_provider
     */
    public function test_booking_maxperuser(array $bdata): void {

        // Set test objective setting(s).
        $bdata['maxperuser'] = 1;

        // Setup test data.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_bookingid($booking1->id);
        singleton_service::destroy_booking_singleton_by_cmid($bookingsettings->cmid);
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_bookingid($booking1->id);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id);
        $this->getDataGenerator()->enrol_user($teacher->id, $course1->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course1->id);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course1->id;

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);

        // Book the first user without any problem.
        $boinfo = new bo_info($settings);

        // Book the student1.
        $this->setUser($student1);

        // We are allowed to book.
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        list($id, $isavailable, $description) = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMBOOKIT, $id);

        // Now we can actually book.
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        list($id, $isavailable, $description) = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        $this->setAdminUser();

        // Create 2nd option.
        $record->text = 'Test option2';
        $option2 = $plugingenerator->create_option($record);
        $settings = singleton_service::get_instance_of_booking_option_settings($option2->id);

        // Book the student1.
        $this->setUser($student1);

        // We are not allowed to book 2nd option - maxperuser exceeded.
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        list($id, $isavailable, $description) = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_MAX_NUMBER_OF_BOOKINGS, $id);
    }

    /**
     * Test booking option availability: \condition\selectusers.
     *
     * @covers \mod_booking\bo_availability\conditions\selectusers::is_available
     * @covers \mod_booking\bo_availability\conditions\previouslybooked::is_available
     * @covers \mod_booking\bo_availability\conditions\enrolledincourse::is_available
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_settings_provider
     */
    public function test_booking_jsonconditions(array $bdata): void {

        $this->resetAfterTest();
        // Setup test data.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $course2 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_bookingid($booking1->id);
        singleton_service::destroy_booking_singleton_by_cmid($bookingsettings->cmid);
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_bookingid($booking1->id);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student2->id, $course2->id);
        $this->getDataGenerator()->enrol_user($teacher->id, $course1->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course1->id);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course1->id;
        // Set test availability setting(s).
        $record->bo_cond_selectusers_restrict = 1;
        $record->bo_cond_selectusers_userids = [$student2->id];

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($record);
        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $boinfo1 = new bo_info($settings1);

        // The 2nd option in the course1.
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option2';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course1->id;
        // Set test availability setting(s).
        $record->bo_cond_previouslybooked_restrict = 1;
        $record->bo_cond_previouslybooked_optionid = $option1->id;
        $option2 = $plugingenerator->create_option($record);
        $settings2 = singleton_service::get_instance_of_booking_option_settings($option2->id);
        $boinfo2 = new bo_info($settings2);

        // Book the student right away.
        $this->setUser($student1);

        // Student1 not allowed to book option1 in course1.
        $result = booking_bookit::bookit('option', $settings1->id, $student1->id);
        list($id, $isavailable, $description) = $boinfo1->is_available($settings1->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_JSON_SELECTUSERS, $id);

        // Student1 not allowed to book option2 in course1.
        $result = booking_bookit::bookit('option', $settings2->id, $student1->id);
        list($id, $isavailable, $description) = $boinfo2->is_available($settings2->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_JSON_PREVIOUSLYBOOKED, $id);

        $this->setUser($student2);

        // Student2 has not allowed to book option2 in course1 yet.
        $result = booking_bookit::bookit('option', $settings2->id, $student2->id);
        list($id, $isavailable, $description) = $boinfo2->is_available($settings2->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_JSON_PREVIOUSLYBOOKED, $id);

        // Student2 is allowed to book option1 in course1.
        $result = booking_bookit::bookit('option', $settings1->id, $student2->id);
        list($id, $isavailable, $description) = $boinfo1->is_available($settings1->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMBOOKIT, $id);

        // Student2 is actually book option1 in the course1.
        $result = booking_bookit::bookit('option', $settings1->id, $student2->id);
        list($id, $isavailable, $description) = $boinfo1->is_available($settings1->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Student2 now is allowed to book option2 in course1.
        $result = booking_bookit::bookit('option', $settings2->id, $student2->id);
        list($id, $isavailable, $description) = $boinfo1->is_available($settings2->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMBOOKIT, $id);

        // Student2 is actually book option2 in the course1.
        $result = booking_bookit::bookit('option', $settings2->id, $student2->id);
        list($id, $isavailable, $description) = $boinfo1->is_available($settings2->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        $this->setAdminUser();

        // The 3nd option in the course1.
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option3';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course1->id;
        // Set test availability setting(s).
        $record->bo_cond_enrolledincourse_restrict = 1;
        $record->bo_cond_enrolledincourse_courseids = [$course2->id];
        $record->bo_cond_enrolledincourse_courseids_operator = 'AND';
        $option3 = $plugingenerator->create_option($record);
        $settings3 = singleton_service::get_instance_of_booking_option_settings($option3->id);
        $boinfo3 = new bo_info($settings3);

        // Book the student1.
        $this->setUser($student1);

        // Student1 not allowed to book option3 in course1.
        $result = booking_bookit::bookit('option', $settings3->id, $student1->id);
        list($id, $isavailable, $description) = $boinfo3->is_available($settings3->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_JSON_ENROLLEDINCOURSE, $id);

        $this->setUser($student2);

        // But student2 allowed to book option3 in course1.
        $result = booking_bookit::bookit('option', $settings3->id, $student2->id);
        list($id, $isavailable, $description) = $boinfo3->is_available($settings3->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMBOOKIT, $id);

        $result = booking_bookit::bookit('option', $settings3->id, $student2->id);
        list($id, $isavailable, $description) = $boinfo3->is_available($settings3->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);
    }


    /**
     * Test booking option availability: \condition\jsonuserfields.
     *
     * @covers \mod_booking\bo_availability\conditions\userprofilefield_1_default::is_available
     * @covers \mod_booking\bo_availability\conditions\userprofilefield_2_custom::is_available
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_settings_provider
     */
    public function test_booking_jsonuserfields(array $bdata): void {
        global $CFG;

        $this->resetAfterTest();

        // Setup test data.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create custom profile field.
        $this->getDataGenerator()->create_custom_profile_field(['datatype' => 'text', 'shortname' => 'sport', 'name' => 'Sport',
        'visible' => PROFILE_VISIBLE_ALL]);
        $this->getDataGenerator()->create_custom_profile_field(['datatype' => 'text', 'shortname' => 'credit', 'name' => 'Credit',
        'visible' => PROFILE_VISIBLE_ALL]);
        set_config('showuseridentity', 'username,email,profile_field_sport,profile_field_credit');
        // Create users.
        $users = [
            ['username' => 'teacher', 'email' => 'teacher@sample.com', 'profile_field_sport' => 'yoga'],
            ['username' => 'student1', 'email' => 'student1@example.com', 'profile_field_sport' => 'football'],
            ['username' => 'student2', 'email' => 'student2@sample.com', 'profile_field_sport' => 'tennis'],
            [
                'username' => 'student3',
                'email' => 'student3@example.com',
                'profile_field_sport' => 'football',
                'profile_field_credit' => '100',
            ],
        ];
        $teacher = $this->getDataGenerator()->create_user($users[0]);
        $student1 = $this->getDataGenerator()->create_user($users[1]);
        $student2 = $this->getDataGenerator()->create_user($users[2]);
        $student3 = $this->getDataGenerator()->create_user($users[3]);
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_bookingid($booking1->id);
        singleton_service::destroy_booking_singleton_by_cmid($bookingsettings->cmid);
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_bookingid($booking1->id);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($student1->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($student3->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher->id, $course1->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course1->id, 'editingteacher');

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course1->id;
        // Set test availability setting(s).
        $record->bo_cond_userprofilefield_1_default_restrict = 1;
        $record->bo_cond_userprofilefield_field = 'email';
        $record->bo_cond_userprofilefield_operator = '~';
        $record->bo_cond_userprofilefield_value = 'student2';

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($record);
        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $boinfo1 = new bo_info($settings1);

        // The 2nd option in the course1.
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option2';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course1->id;
        // Set test availability setting(s).
        $record->bo_cond_userprofilefield_2_custom_restrict = 1;
        $record->bo_cond_customuserprofilefield_field = 'sport';
        $record->bo_cond_customuserprofilefield_operator = '=';
        $record->bo_cond_customuserprofilefield_value = 'football';

        $option2 = $plugingenerator->create_option($record);
        $settings2 = singleton_service::get_instance_of_booking_option_settings($option2->id);
        $boinfo2 = new bo_info($settings2);

        // The 3nd option in the course1.
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option3';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course1->id;
        // Set test availability setting(s).
        $record->bo_cond_userprofilefield_2_custom_restrict = 1;
        $record->bo_cond_customuserprofilefield_field = 'sport';
        $record->bo_cond_customuserprofilefield_operator = '=';
        $record->bo_cond_customuserprofilefield_value = 'football';
        $record->bo_cond_customuserprofilefield_connectsecondfield = '&&';
        $record->bo_cond_customuserprofilefield_field2 = 'credit';
        $record->bo_cond_customuserprofilefield_operator2 = '>';
        $record->bo_cond_customuserprofilefield_value2 = '50';

        $option3 = $plugingenerator->create_option($record);
        $settings3 = singleton_service::get_instance_of_booking_option_settings($option3->id);
        $boinfo3 = new bo_info($settings3);

        // Book the student1.
        $this->setUser($student1);
        singleton_service::destroy_user($student1->id);

        // Student1 does not allowed to book option1 in course1.
        $result = booking_bookit::bookit('option', $settings1->id, $student1->id);
        list($id, $isavailable, $description) = $boinfo1->is_available($settings1->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_JSON_USERPROFILEFIELD, $id);

        // Student1 does allowed to book option2 in course1.
        $result = booking_bookit::bookit('option', $settings2->id, $student1->id);
        list($id, $isavailable, $description) = $boinfo2->is_available($settings2->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMBOOKIT, $id);

        $result = booking_bookit::bookit('option', $settings2->id, $student1->id);
        list($id, $isavailable, $description) = $boinfo2->is_available($settings2->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Student1 does not allowed to book option3 in course1.
        $result = booking_bookit::bookit('option', $settings3->id, $student1->id);
        list($id, $isavailable, $description) = $boinfo3->is_available($settings3->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_JSON_CUSTOMUSERPROFILEFIELD, $id);

        // Book the student2.
        $this->setUser($student2);
        singleton_service::destroy_user($student2->id);

        // Student2 does not allowed to book option2 in course1.
        $result = booking_bookit::bookit('option', $settings2->id, $student2->id);
        list($id, $isavailable, $description) = $boinfo2->is_available($settings2->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_JSON_CUSTOMUSERPROFILEFIELD, $id);

        // Student2 does allowed to book option1 in course1.
        $result = booking_bookit::bookit('option', $settings1->id, $student2->id);
        list($id, $isavailable, $description) = $boinfo1->is_available($settings1->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMBOOKIT, $id);

        $result = booking_bookit::bookit('option', $settings1->id, $student2->id);
        list($id, $isavailable, $description) = $boinfo1->is_available($settings1->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Student2 does not allowed to book option3 in course1.
        $result = booking_bookit::bookit('option', $settings3->id, $student2->id);
        list($id, $isavailable, $description) = $boinfo3->is_available($settings3->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_JSON_CUSTOMUSERPROFILEFIELD, $id);

        // Book the student3.
        $this->setUser($student3);
        singleton_service::destroy_user($student3->id);

        // Student3 does allowed to book option3 in course1.
        $result = booking_bookit::bookit('option', $settings3->id, $student3->id);
        list($id, $isavailable, $description) = $boinfo3->is_available($settings3->id, $student3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMBOOKIT, $id);

        $result = booking_bookit::bookit('option', $settings3->id, $student3->id);
        list($id, $isavailable, $description) = $boinfo1->is_available($settings3->id, $student3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);
    }

    /**
     * Data provider for condition_bookingpolicy_test
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    public static function booking_settings_provider(): array {
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
}
