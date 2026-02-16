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
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2026 David Ala-Flucher
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use coding_exception;
use context_module;
use mod_booking_generator;
use mod_booking\bo_availability\bo_info;
use stdClass;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Class handling tests for skippable conditions.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class condition_skipableconditions_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
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
     * Tests if the settings workes as intended and skipped conditions are checked during booking process.
     *
     * @covers \mod_booking\bo_availability\conditions\userprofilefield_2_custom::is_available
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_settings_provider
     */
    public function test_usercustomfield(array $bdata): void {
        $this->resetAfterTest();
        set_config('skippableconditions', MOD_BOOKING_BO_COND_JSON_CUSTOMUSERPROFILEFIELD, 'booking');
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
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_JSON_USERPROFILEFIELD, $id);

        // Student1 does allowed to book option2 in course1.
        $result = booking_bookit::bookit('option', $settings2->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo2->is_available($settings2->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMBOOKIT, $id);

        $result = booking_bookit::bookit('option', $settings2->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo2->is_available($settings2->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        /* Student1 does not allowed to book option3 in course1, because of the customfield.
        Since the condition is skipped the user should get the confirm bookit message.*/
        $result = booking_bookit::bookit('option', $settings3->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo3->is_available($settings3->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMBOOKIT, $id);

        // Book the student2.
        $this->setUser($student2);
        singleton_service::destroy_user($student2->id);

        /* Student2 does not allowed to book option2 in course1, because of the customfield.
        Since the condition is skipped the user should get the confirm bookit message.*/
        $result = booking_bookit::bookit('option', $settings2->id, $student2->id);
        [$id, $isavailable, $description] = $boinfo2->is_available($settings2->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMBOOKIT, $id);

        // Student2 does allowed to book option1 in course1.
        $result = booking_bookit::bookit('option', $settings1->id, $student2->id);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMBOOKIT, $id);

        $result = booking_bookit::bookit('option', $settings1->id, $student2->id);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings1->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        /*Student2 does not allowed to book option3 in course1, because of the customfield.
        Since the condition is skipped the user should get the confirm bookit message.*/
        $result = booking_bookit::bookit('option', $settings3->id, $student2->id);
        [$id, $isavailable, $description] = $boinfo3->is_available($settings3->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMBOOKIT, $id);

        // Book the student3.
        $this->setUser($student3);
        singleton_service::destroy_user($student3->id);

        // Student3 does allowed to book option3 in course1.
        $result = booking_bookit::bookit('option', $settings3->id, $student3->id);
        [$id, $isavailable, $description] = $boinfo3->is_available($settings3->id, $student3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMBOOKIT, $id);

        $result = booking_bookit::bookit('option', $settings3->id, $student3->id);
        [$id, $isavailable, $description] = $boinfo1->is_available($settings3->id, $student3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);
    }

    /**
     * Tests if the settings workes as intended and skipped conditions are checked during booking process.
     *
     * @covers \mod_booking\bo_availability\conditions\enrolledincohorts::is_available
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_settings_provider
     */
    public function test_enrolled_in_corhorts(array $bdata): void {
        global $DB;

        $this->resetAfterTest();
        set_config('skippableconditions', MOD_BOOKING_BO_COND_JSON_ENROLLEDINCOHORTS, 'booking');

        $bdata['cancancelbook'] = 1;

        // Setup test data.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $course2 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $admin = $this->getDataGenerator()->create_user();
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $student3 = $this->getDataGenerator()->create_user();
        $student4 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        // Create 2 cohorts.
        $contextsystem = \context_system::instance();
        $cohort1 = $this->getDataGenerator()->create_cohort([
            'contextid' => $contextsystem->id,
            'name' => 'System booking cohort 1',
            'idnumber' => 'SBC1',
        ]);
        $cohort2 = $this->getDataGenerator()->create_cohort([
            'contextid' => $contextsystem->id,
            'name' => 'System booking cohort 2',
            'idnumber' => 'SBC2',
        ]);

        $this->setAdminUser();

        cohort_add_member($cohort1->id, $student1->id);
        cohort_add_member($cohort1->id, $student2->id);
        cohort_add_member($cohort2->id, $student2->id);

        $this->getDataGenerator()->enrol_user($student1->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student3->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student4->id, $course1->id);
        $this->getDataGenerator()->enrol_user($teacher->id, $course1->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course1->id);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1 (availability by cohort and time)';
        $record->chooseorcreatecourse = 1; // Reqiured.
        $record->courseid = $course1->id;

        // Set test availability setting(s).
        $record->bo_cond_enrolledincohorts_restrict = 1;
        $record->bo_cond_enrolledincohorts_cohortids = [$cohort1->id, $cohort2->id];
        $record->bo_cond_enrolledincohorts_cohortids_operator = 'AND';
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $boinfo = new bo_info($settings);

        // Try to book student2 - allowed.
        $this->setUser($student2);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);

        $result = booking_bookit::bookit('option', $settings->id, $student2->id);
        $result = booking_bookit::bookit('option', $settings->id, $student2->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Try to book student3 - NOT allowed, but condition is disabled.
        $this->setUser($student3);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student3->id);
        $result = booking_bookit::bookit('option', $settings->id, $student3->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);
    }

     /**
      * Tests if the settings workes as intended and skipped conditions are checked during booking process.
      * @covers \mod_booking\bo_availability\conditions\enrolledincourse::is_available
      * @param array $bdata
      * @throws \coding_exception
      * @throws \dml_exception
      * @dataProvider booking_settings_provider
      * @return void
      *
      */
    public function test_enrolled_in_course(array $bdata): void {
        global $DB, $PAGE;
        $this->resetAfterTest();
        set_config('skippableconditions', MOD_BOOKING_BO_COND_JSON_ENROLLEDINCOURSE, 'booking');
        $bdata['cancancelbook'] = 1;

        singleton_service::destroy_instance();

        // Setup test data.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $course2 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $course3 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        // Enroll students in different courses.
        // Student1 only in course2.
        $this->getDataGenerator()->enrol_user($student1->id, $course2->id);
        // Student2 in both course2 and course3.
        $this->getDataGenerator()->enrol_user($student2->id, $course2->id);
        $this->getDataGenerator()->enrol_user($student2->id, $course3->id);
        // Student3 not enrolled in course2 or course3.
        // Student4 only in course3.
        // All users enrolled in course1 (the booking course).
        $this->getDataGenerator()->enrol_user($student1->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course1->id);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1 (availability by enrolled courses)';
        $record->chooseorcreatecourse = 1; // Required.
        $record->courseid = $course1->id;

        // Set test availability setting(s) - require enrollment in both course2 AND course3.
        $record->bo_cond_enrolledincourse_restrict = 1;
        $record->bo_cond_enrolledincourse_courseids = [$course2->id, $course3->id];
        $record->bo_cond_enrolledincourse_courseids_operator = 'AND';
        $record->bo_cond_enrolledincourse_sqlfiltercheck = 1;

        [$course, $cm] = get_course_and_cm_from_cmid($booking1->cmid);
        // Before the creation, we need to fix the Page context.
        $PAGE->set_cm($cm, $course);
        $PAGE->set_context(context_module::instance($booking1->cmid));

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);

        $boinfo = new bo_info($settings);

        // Try to book student1 NOT - allowed (only in course2, not in course3), but condition is skipped in setting.
        $this->setUser($student1);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);

        // Try to book student2 - allowed (enrolled in both course2 AND course3).
        $this->setUser($student2);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);

        $result = booking_bookit::bookit('option', $settings->id, $student2->id);
        $result = booking_bookit::bookit('option', $settings->id, $student2->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
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
