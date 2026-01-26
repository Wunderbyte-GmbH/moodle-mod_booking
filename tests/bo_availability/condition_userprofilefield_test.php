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
 * Tests for booking option availability conditions - custom user profile fields.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
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
 * Class handling tests for booking options availability - custom user profile fields.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
final class condition_userprofilefield_test extends advanced_testcase {
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
     * Test of booking option availability by custom user profile field with equals operator.
     *
     * @covers \mod_booking\bo_availability\conditions\userprofilefield_2_custom::is_available
     * @covers \mod_booking\bo_availability\conditions\userprofilefield_2_custom::return_sql
     * @covers \mod_booking\local\sql\operator_builder::build_profile_field_check
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_booking_bookit_userprofilefield_equals(array $bdata): void {
        global $DB, $CFG, $PAGE;

        // Make sure SQL filter for availability is enabled for this test.
        set_config('usesqlfilteravailability', 1, 'booking');

        $bdata['cancancelbook'] = 1;

        singleton_service::destroy_instance();

        // Setup test data.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

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

        $this->setAdminUser();

        // Create a custom profile field.
        $profilefield = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'companydepartment',
            'name' => 'Department',
        ]);

        // Set profile field values for users.
        $DB->insert_record('user_info_data', [
            'userid' => $student1->id,
            'fieldid' => $profilefield->id,
            'data' => 'IT',
        ]);
        $DB->insert_record('user_info_data', [
            'userid' => $student2->id,
            'fieldid' => $profilefield->id,
            'data' => 'IT',
        ]);
        $DB->insert_record('user_info_data', [
            'userid' => $student3->id,
            'fieldid' => $profilefield->id,
            'data' => 'HR',
        ]);
        // Student4 has no value set (empty).

        // Moodle way: load all profile fields for student1.
        require_once($CFG->dirroot . '/user/profile/lib.php');
        profile_load_data($student1);

        // All users enrolled in course1 (the booking course).
        $this->getDataGenerator()->enrol_user($student1->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student3->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student4->id, $course1->id);
        $this->getDataGenerator()->enrol_user($teacher->id, $course1->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course1->id);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1 (availability by custom profile field)';
        $record->chooseorcreatecourse = 1; // Required.
        $record->courseid = $course1->id;

        // Set test availability setting(s) - require companydepartment = IT.
        $record->bo_cond_userprofilefield_2_custom_restrict = 1;
        $record->bo_cond_customuserprofilefield_field = 'companydepartment';
        $record->bo_cond_customuserprofilefield_operator = '=';
        $record->bo_cond_customuserprofilefield_value = 'IT';
        $record->bo_cond_customuserprofilefield_sqlfiltercheck = 1;

        [$course, $cm] = get_course_and_cm_from_cmid($booking1->cmid);
        // Before the creation, we need to fix the Page context.
        $PAGE->set_cm($cm, $course);
        $PAGE->set_context(context_module::instance($booking1->cmid));

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        // Make sure sql filter active indicator is set correctly.
        $this->assertEquals(MOD_BOOKING_SQL_FILTER_ACTIVE_JSON_BO, $settings->sqlfilter);

        $boinfo = new bo_info($settings);

        // Try to book student1 - allowed (companydepartment = IT).
        $this->setUser($student1);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);

        // This user should see the booking option.
        $rawdata = $plugingenerator->create_table_for_one_option($settings->id);
        $this->assertEquals(1, count($rawdata)); // We expect that the student can see the option.

        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Try to book student2 - allowed (companydepartment = IT).
        $this->setUser($student2);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);

        // This user should see the booking option.
        $rawdata = $plugingenerator->create_table_for_one_option($settings->id);
        $this->assertEquals(1, count($rawdata)); // We expect that the student can see the option.

        // Try to book student3 - NOT allowed (companydepartment = HR).
        $this->setUser($student3);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_JSON_CUSTOMUSERPROFILEFIELD, $id);

        // This user should not see the booking option.
        $rawdata = $plugingenerator->create_table_for_one_option($settings->id);
        $this->assertEquals(0, count($rawdata)); // We expect that the student cant see the option.

        // Try to book student4 - NOT allowed (no value set).
        $this->setUser($student4);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student4->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_JSON_CUSTOMUSERPROFILEFIELD, $id);

        // This user should not see the booking option.
        $rawdata = $plugingenerator->create_table_for_one_option($settings->id);
        $this->assertEquals(0, count($rawdata)); // We expect that the student cant see the option.
    }

    /**
     * Test of booking option availability by custom user profile field with contains operator.
     *
     * @covers \mod_booking\bo_availability\conditions\userprofilefield_2_custom::is_available
     * @covers \mod_booking\bo_availability\conditions\userprofilefield_2_custom::return_sql
     * @covers \mod_booking\local\sql\operator_builder::build_profile_field_check
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_booking_bookit_userprofilefield_contains(array $bdata): void {
        global $DB, $CFG, $PAGE;

        // Make sure SQL filter for availability is enabled for this test.
        set_config('usesqlfilteravailability', 1, 'booking');

        $bdata['cancancelbook'] = 1;

        singleton_service::destroy_instance();

        // Setup test data.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

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

        $this->setAdminUser();

        // Create a custom profile field.
        $profilefield = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'skills',
            'name' => 'Skills',
        ]);

        // Set profile field values for users.
        $DB->insert_record('user_info_data', [
            'userid' => $student1->id,
            'fieldid' => $profilefield->id,
            'data' => 'PHP, JavaScript, SQL',
        ]);
        $DB->insert_record('user_info_data', [
            'userid' => $student2->id,
            'fieldid' => $profilefield->id,
            'data' => 'Python, Java',
        ]);
        $DB->insert_record('user_info_data', [
            'userid' => $student3->id,
            'fieldid' => $profilefield->id,
            'data' => 'PHP, Python',
        ]);
        // Student4 has no value set (empty).

        // All users enrolled in course1 (the booking course).
        $this->getDataGenerator()->enrol_user($student1->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student3->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student4->id, $course1->id);
        $this->getDataGenerator()->enrol_user($teacher->id, $course1->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course1->id);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1 (availability by custom profile field contains)';
        $record->chooseorcreatecourse = 1; // Required.
        $record->courseid = $course1->id;

        // Set test availability setting(s) - require skills contains PHP.
        $record->bo_cond_userprofilefield_2_custom_restrict = 1;
        $record->bo_cond_customuserprofilefield_field = 'skills';
        $record->bo_cond_customuserprofilefield_operator = '~';
        $record->bo_cond_customuserprofilefield_value = 'PHP';
        $record->bo_cond_customuserprofilefield_sqlfiltercheck = 1;

        [$course, $cm] = get_course_and_cm_from_cmid($booking1->cmid);
        // Before the creation, we need to fix the Page context.
        $PAGE->set_cm($cm, $course);
        $PAGE->set_context(context_module::instance($booking1->cmid));

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        // Make sure sql filter active indicator is set correctly.
        $this->assertEquals(MOD_BOOKING_SQL_FILTER_ACTIVE_JSON_BO, $settings->sqlfilter);

        $boinfo = new bo_info($settings);

        // Try to book student1 - allowed (skills contains PHP).
        $this->setUser($student1);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);

        // This user should see the booking option.
        $rawdata = $plugingenerator->create_table_for_one_option($settings->id);
        $this->assertEquals(1, count($rawdata)); // We expect that the student can see the option.

        // Try to book student2 - NOT allowed (skills does not contain PHP).
        $this->setUser($student2);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_JSON_CUSTOMUSERPROFILEFIELD, $id);

        // This user should not see the booking option.
        $rawdata = $plugingenerator->create_table_for_one_option($settings->id);
        $this->assertEquals(0, count($rawdata)); // We expect that the student cant see the option.

        // Try to book student3 - allowed (skills contains PHP).
        $this->setUser($student3);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);

        // This user should see the booking option.
        $rawdata = $plugingenerator->create_table_for_one_option($settings->id);
        $this->assertEquals(1, count($rawdata)); // We expect that the student can see the option.

        // Try to book student4 - NOT allowed (no value set).
        $this->setUser($student4);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student4->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_JSON_CUSTOMUSERPROFILEFIELD, $id);

        // This user should not see the booking option.
        $rawdata = $plugingenerator->create_table_for_one_option($settings->id);
        $this->assertEquals(0, count($rawdata)); // We expect that the student cant see the option.
    }

    /**
     * Test of booking option availability by custom user profile field with not equals operator.
     *
     * @covers \mod_booking\bo_availability\conditions\userprofilefield_2_custom::is_available
     * @covers \mod_booking\bo_availability\conditions\userprofilefield_2_custom::return_sql
     * @covers \mod_booking\local\sql\operator_builder::build_profile_field_check
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_booking_bookit_userprofilefield_not_equals(array $bdata): void {
        global $DB, $CFG, $PAGE;

        // Make sure SQL filter for availability is enabled for this test.
        set_config('usesqlfilteravailability', 1, 'booking');

        $bdata['cancancelbook'] = 1;

        singleton_service::destroy_instance();

        // Setup test data.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

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

        $this->setAdminUser();

        // Create a custom profile field.
        $profilefield = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'status',
            'name' => 'Status',
        ]);

        // Set profile field values for users.
        $DB->insert_record('user_info_data', [
            'userid' => $student1->id,
            'fieldid' => $profilefield->id,
            'data' => 'active',
        ]);
        $DB->insert_record('user_info_data', [
            'userid' => $student2->id,
            'fieldid' => $profilefield->id,
            'data' => 'inactive',
        ]);
        $DB->insert_record('user_info_data', [
            'userid' => $student3->id,
            'fieldid' => $profilefield->id,
            'data' => 'pending',
        ]);
        // Student4 has no value set (empty).

        // All users enrolled in course1 (the booking course).
        $this->getDataGenerator()->enrol_user($student1->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student3->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student4->id, $course1->id);
        $this->getDataGenerator()->enrol_user($teacher->id, $course1->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course1->id);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1 (availability by custom profile field not equals)';
        $record->chooseorcreatecourse = 1; // Required.
        $record->courseid = $course1->id;

        // Set test availability setting(s) - require status != inactive.
        $record->bo_cond_userprofilefield_2_custom_restrict = 1;
        $record->bo_cond_customuserprofilefield_field = 'status';
        $record->bo_cond_customuserprofilefield_operator = '!=';
        $record->bo_cond_customuserprofilefield_value = 'inactive';
        $record->bo_cond_customuserprofilefield_sqlfiltercheck = 1;

        [$course, $cm] = get_course_and_cm_from_cmid($booking1->cmid);
        // Before the creation, we need to fix the Page context.
        $PAGE->set_cm($cm, $course);
        $PAGE->set_context(context_module::instance($booking1->cmid));

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        // Make sure sql filter active indicator is set correctly.
        $this->assertEquals(MOD_BOOKING_SQL_FILTER_ACTIVE_JSON_BO, $settings->sqlfilter);

        $boinfo = new bo_info($settings);

        // Try to book student1 - allowed (status != inactive).
        $this->setUser($student1);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);

        // This user should see the booking option.
        $rawdata = $plugingenerator->create_table_for_one_option($settings->id);
        $this->assertEquals(1, count($rawdata)); // We expect that the student can see the option.

        // Try to book student2 - NOT allowed (status = inactive).
        $this->setUser($student2);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_JSON_CUSTOMUSERPROFILEFIELD, $id);

        // This user should not see the booking option.
        $rawdata = $plugingenerator->create_table_for_one_option($settings->id);
        $this->assertEquals(0, count($rawdata)); // We expect that the student cant see the option.

        // Try to book student3 - allowed (status != inactive).
        $this->setUser($student3);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);

        // This user should see the booking option.
        $rawdata = $plugingenerator->create_table_for_one_option($settings->id);
        $this->assertEquals(1, count($rawdata)); // We expect that the student can see the option.

        // Try to book student4 - allowed, since no value set (empty) is not equal to inactive.
        $this->setUser($student4);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student4->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);

        // This user should not see the booking option.
        $rawdata = $plugingenerator->create_table_for_one_option($settings->id);
        $this->assertEquals(1, count($rawdata)); // We expect that the student cant see the option.
    }

    /**
     * Test equals operator with empty condition value: users with empty profile field should match.
     *
     * @covers \mod_booking\bo_availability\conditions\userprofilefield_2_custom::is_available
     * @covers \mod_booking\bo_availability\conditions\userprofilefield_2_custom::return_sql
     * @covers \mod_booking\local\sql\operator_builder::build_profile_field_check
     *
     * @param array $bdata
     * @return void
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_booking_bookit_userprofilefield_equals_empty(array $bdata): void {
        global $DB, $PAGE;

        set_config('usesqlfilteravailability', 1, 'booking');
        $bdata['cancancelbook'] = 1;
        singleton_service::destroy_instance();

        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        $admin = $this->getDataGenerator()->create_user();
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $student3 = $this->getDataGenerator()->create_user();
        $student4 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $bookingmanager->username;
        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);
        $this->setAdminUser();

        // Custom field.
        $profilefield = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'dept_empty',
            'name' => 'DeptEmpty',
        ]);

        // Set values: student1 non-empty, student4 empty.
        $DB->insert_record('user_info_data', [
            'userid' => $student1->id,
            'fieldid' => $profilefield->id,
            'data' => 'IT',
        ]);
        $DB->insert_record('user_info_data', [
            'userid' => $student2->id,
            'fieldid' => $profilefield->id,
            'data' => 'HR',
        ]);

        // Enrol users.
        foreach ([$student1, $student2, $student3, $student4, $teacher, $bookingmanager] as $u) {
            $this->getDataGenerator()->enrol_user($u->id, $course1->id);
        }

        // Condition: equals empty string.
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Equals empty string';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course1->id;
        $record->bo_cond_userprofilefield_2_custom_restrict = 1;
        $record->bo_cond_customuserprofilefield_field = 'dept_empty';
        $record->bo_cond_customuserprofilefield_operator = '=';
        $record->bo_cond_customuserprofilefield_value = '';
        $record->bo_cond_customuserprofilefield_sqlfiltercheck = 1;

        [$course, $cm] = get_course_and_cm_from_cmid($booking1->cmid);
        $PAGE->set_cm($cm, $course);
        $PAGE->set_context(context_module::instance($booking1->cmid));

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        $boinfo = new bo_info($settings);

        // Non-empty value should NOT match '=' empty.
        $this->setUser($student1);
        [$id] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_JSON_CUSTOMUSERPROFILEFIELD, $id);

        // Empty value should match '=' empty.
        $this->setUser($student4);
        [$id] = $boinfo->is_available($settings->id, $student4->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);
    }

    /**
     * Test NOT IN ('[!]') returns true when user value is empty.
     *
     * @covers \mod_booking\bo_availability\conditions\userprofilefield_2_custom::is_available
     * @covers \mod_booking\bo_availability\conditions\userprofilefield_2_custom::return_sql
     * @covers \mod_booking\local\sql\operator_builder::build_profile_field_check
     * @param array $bdata
     *
     * @return void
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_booking_bookit_userprofilefield_not_in_empty_user(array $bdata): void {
        global $DB, $PAGE;

        set_config('usesqlfilteravailability', 1, 'booking');
        $bdata['cancancelbook'] = 1;
        singleton_service::destroy_instance();

        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        $admin = $this->getDataGenerator()->create_user();
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $student3 = $this->getDataGenerator()->create_user();
        $student4 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $bookingmanager->username;
        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);
        $this->setAdminUser();

        // Custom field.
        $profilefield = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'status_notin',
            'name' => 'StatusNotIn',
        ]);

        // Values: student1 'active' (not in), student2 'inactive' (in), student3 'pending' (in), student4 empty.
        $DB->insert_record('user_info_data', [
            'userid' => $student1->id,
            'fieldid' => $profilefield->id,
            'data' => 'active',
        ]);
        $DB->insert_record('user_info_data', [
            'userid' => $student2->id,
            'fieldid' => $profilefield->id,
            'data' => 'inactive',
        ]);
        $DB->insert_record('user_info_data', [
            'userid' => $student3->id,
            'fieldid' => $profilefield->id,
            'data' => 'pending',
        ]);

        foreach ([$student1, $student2, $student3, $student4, $teacher, $bookingmanager] as $u) {
            $this->getDataGenerator()->enrol_user($u->id, $course1->id);
        }

        // Condition: NOT IN inactive,pending.
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'NOT IN test';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course1->id;
        $record->bo_cond_userprofilefield_2_custom_restrict = 1;
        $record->bo_cond_customuserprofilefield_field = 'status_notin';
        $record->bo_cond_customuserprofilefield_operator = '[!]';
        $record->bo_cond_customuserprofilefield_value = 'inactive,pending';
        $record->bo_cond_customuserprofilefield_sqlfiltercheck = 1;

        [$course, $cm] = get_course_and_cm_from_cmid($booking1->cmid);
        $PAGE->set_cm($cm, $course);
        $PAGE->set_context(context_module::instance($booking1->cmid));

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        $boinfo = new bo_info($settings);

        // Student1 'active' should be allowed.
        $this->setUser($student1);
        [$id] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);

        // Student2 'inactive' should be blocked.
        $this->setUser($student2);
        [$id] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_JSON_CUSTOMUSERPROFILEFIELD, $id);

        // Student3 'pending' should be blocked.
        $this->setUser($student3);
        [$id] = $boinfo->is_available($settings->id, $student3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_JSON_CUSTOMUSERPROFILEFIELD, $id);

        // Student4 empty should be allowed due to empty handling in NOT IN.
        $this->setUser($student4);
        [$id] = $boinfo->is_available($settings->id, $student4->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);
    }

    /**
     * Test combinations of two custom fields with AND (&&).
     * Ensures both PHP is_available and SQL filtering match.
     *
     * @covers \mod_booking\bo_availability\conditions\userprofilefield_2_custom::is_available
     * @covers \mod_booking\bo_availability\conditions\userprofilefield_2_custom::return_sql
     * @covers \mod_booking\local\sql\operator_builder::build_profile_field_check
     *
     * @param array $bdata
     *
     * @return void
     * @dataProvider booking_common_settings_provider
     *
     */
    public function test_booking_bookit_userprofilefield_two_fields_and(array $bdata): void {
        global $DB, $PAGE;

        set_config('usesqlfilteravailability', 1, 'booking');
        $bdata['cancancelbook'] = 1;
        singleton_service::destroy_instance();

        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        $admin = $this->getDataGenerator()->create_user();
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $student3 = $this->getDataGenerator()->create_user();
        $student4 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $bookingmanager->username;
        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);
        $this->setAdminUser();

        // Two custom fields: department and status.
        $dept = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'dept_two',
            'name' => 'DepartmentTwo',
        ]);
        $status = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'status_two',
            'name' => 'StatusTwo',
        ]);

        // Values: s1 dept=IT, status=active; s2 dept=IT, status=inactive; s3 dept=HR, status=active; s4 empty both.
        $DB->insert_record('user_info_data', ['userid' => $student1->id, 'fieldid' => $dept->id, 'data' => 'IT']);
        $DB->insert_record('user_info_data', ['userid' => $student1->id, 'fieldid' => $status->id, 'data' => 'active']);
        $DB->insert_record('user_info_data', ['userid' => $student2->id, 'fieldid' => $dept->id, 'data' => 'IT']);
        $DB->insert_record('user_info_data', ['userid' => $student2->id, 'fieldid' => $status->id, 'data' => 'inactive']);
        $DB->insert_record('user_info_data', ['userid' => $student3->id, 'fieldid' => $dept->id, 'data' => 'HR']);
        $DB->insert_record('user_info_data', ['userid' => $student3->id, 'fieldid' => $status->id, 'data' => 'active']);
        // Student4: no entries => empty values.

        foreach ([$student1, $student2, $student3, $student4, $teacher, $bookingmanager] as $u) {
            $this->getDataGenerator()->enrol_user($u->id, $course1->id);
        }

        // Condition: dept_two = IT AND status_two != inactive.
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Two fields AND';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course1->id;
        $record->bo_cond_userprofilefield_2_custom_restrict = 1;
        $record->bo_cond_customuserprofilefield_field = 'dept_two';
        $record->bo_cond_customuserprofilefield_operator = '=';
        $record->bo_cond_customuserprofilefield_value = 'IT';
        $record->bo_cond_customuserprofilefield_connectsecondfield = '&&';
        $record->bo_cond_customuserprofilefield_field2 = 'status_two';
        $record->bo_cond_customuserprofilefield_operator2 = '!=';
        $record->bo_cond_customuserprofilefield_value2 = 'inactive';
        $record->bo_cond_customuserprofilefield_sqlfiltercheck = 1;

        [$course, $cm] = get_course_and_cm_from_cmid($booking1->cmid);
        $PAGE->set_cm($cm, $course);
        $PAGE->set_context(context_module::instance($booking1->cmid));

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boinfo = new bo_info($settings);

        // S1: IT & active => allowed.
        $this->setUser($student1);
        [$id] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);
        $raw = $plugingenerator->create_table_for_one_option($settings->id);
        $this->assertEquals(1, count($raw));

        // S2: IT & inactive => blocked by status.
        $this->setUser($student2);
        [$id] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_JSON_CUSTOMUSERPROFILEFIELD, $id);
        $raw = $plugingenerator->create_table_for_one_option($settings->id);
        $this->assertEquals(0, count($raw));

        // S3: HR & active => blocked by dept.
        $this->setUser($student3);
        [$id] = $boinfo->is_available($settings->id, $student3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_JSON_CUSTOMUSERPROFILEFIELD, $id);
        $raw = $plugingenerator->create_table_for_one_option($settings->id);
        $this->assertEquals(0, count($raw));

        // S4: empty & empty => dept fails '=' IT; status passes '!=' inactive due to empty handling; overall AND => blocked.
        $this->setUser($student4);
        [$id] = $boinfo->is_available($settings->id, $student4->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_JSON_CUSTOMUSERPROFILEFIELD, $id);
        $raw = $plugingenerator->create_table_for_one_option($settings->id);
        $this->assertEquals(0, count($raw));
    }

    /**
     * Test combinations of two custom fields with OR (||).
     * Ensures both PHP is_available and SQL filtering match.
     *
     * @covers \mod_booking\bo_availability\conditions\userprofilefield_2_custom::is_available
     * @covers \mod_booking\bo_availability\conditions\userprofilefield_2_custom::return_sql
     * @covers \mod_booking\local\sql\operator_builder::build_profile_field_check
     *
     * @param array $bdata
     *
     * @return void
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_booking_bookit_userprofilefield_two_fields_or(array $bdata): void {
        global $DB, $PAGE;

        set_config('usesqlfilteravailability', 1, 'booking');
        $bdata['cancancelbook'] = 1;
        singleton_service::destroy_instance();

        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        $admin = $this->getDataGenerator()->create_user();
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $student3 = $this->getDataGenerator()->create_user();
        $student4 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $bookingmanager->username;
        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);
        $this->setAdminUser();

        // Two custom fields: department and status.
        $dept = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'dept_two_or',
            'name' => 'DepartmentTwoOR',
        ]);
        $status = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'status_two_or',
            'name' => 'StatusTwoOR',
        ]);

        // Values: s1 dept=IT, status=active; s2 dept=IT, status=inactive; s3 dept=HR, status=active; s4 empty both.
        $DB->insert_record('user_info_data', ['userid' => $student1->id, 'fieldid' => $dept->id, 'data' => 'IT']);
        $DB->insert_record('user_info_data', ['userid' => $student1->id, 'fieldid' => $status->id, 'data' => 'active']);
        $DB->insert_record('user_info_data', ['userid' => $student2->id, 'fieldid' => $dept->id, 'data' => 'IT']);
        $DB->insert_record('user_info_data', ['userid' => $student2->id, 'fieldid' => $status->id, 'data' => 'inactive']);
        $DB->insert_record('user_info_data', ['userid' => $student3->id, 'fieldid' => $dept->id, 'data' => 'HR']);
        $DB->insert_record('user_info_data', ['userid' => $student3->id, 'fieldid' => $status->id, 'data' => 'active']);

        foreach ([$student1, $student2, $student3, $student4, $teacher, $bookingmanager] as $u) {
            $this->getDataGenerator()->enrol_user($u->id, $course1->id);
        }

        // Condition: dept_two_or = IT OR status_two_or != inactive.
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Two fields OR';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course1->id;
        $record->bo_cond_userprofilefield_2_custom_restrict = 1;
        $record->bo_cond_customuserprofilefield_field = 'dept_two_or';
        $record->bo_cond_customuserprofilefield_operator = '=';
        $record->bo_cond_customuserprofilefield_value = 'IT';
        $record->bo_cond_customuserprofilefield_connectsecondfield = '||';
        $record->bo_cond_customuserprofilefield_field2 = 'status_two_or';
        $record->bo_cond_customuserprofilefield_operator2 = '!=';
        $record->bo_cond_customuserprofilefield_value2 = 'inactive';
        $record->bo_cond_customuserprofilefield_sqlfiltercheck = 1;

        [$course, $cm] = get_course_and_cm_from_cmid($booking1->cmid);
        $PAGE->set_cm($cm, $course);
        $PAGE->set_context(context_module::instance($booking1->cmid));

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boinfo = new bo_info($settings);

        // S2: IT & inactive => allowed (dept matches) despite inactive.
        $this->setUser($student2);
        [$id] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);
        $raw = $plugingenerator->create_table_for_one_option($settings->id);
        $this->assertEquals(1, count($raw));

        // S3: HR & active => allowed (status != inactive).
        $this->setUser($student3);
        [$id] = $boinfo->is_available($settings->id, $student3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);
        $raw = $plugingenerator->create_table_for_one_option($settings->id);
        $this->assertEquals(1, count($raw));

        // S4: empty & empty => status passes '!=' inactive via empty; OR => allowed.
        $this->setUser($student4);
        [$id] = $boinfo->is_available($settings->id, $student4->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);
        $raw = $plugingenerator->create_table_for_one_option($settings->id);
        $this->assertEquals(1, count($raw));
    }

    /**
     * Test of booking option availability by custom user profile field with empty value check.
     *
     * @covers \mod_booking\bo_availability\conditions\userprofilefield_2_custom::is_available
     * @covers \mod_booking\bo_availability\conditions\userprofilefield_2_custom::return_sql
     * @covers \mod_booking\local\sql\operator_builder::build_profile_field_check
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_booking_bookit_userprofilefield_empty(array $bdata): void {
        global $DB, $CFG, $PAGE;

        // Make sure SQL filter for availability is enabled for this test.
        set_config('usesqlfilteravailability', 1, 'booking');

        $bdata['cancancelbook'] = 1;

        singleton_service::destroy_instance();

        // Setup test data.
        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $admin = $this->getDataGenerator()->create_user();
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $student3 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user(); // Booking manager.

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        // Create a custom profile field.
        $profilefield = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'company',
            'name' => 'Company',
        ]);

        // Set profile field values for users.
        $DB->insert_record('user_info_data', [
            'userid' => $student1->id,
            'fieldid' => $profilefield->id,
            'data' => 'Acme Corp',
        ]);
        // Student2 and student3 have no value set (empty).

        // All users enrolled in course1 (the booking course).
        $this->getDataGenerator()->enrol_user($student1->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student2->id, $course1->id);
        $this->getDataGenerator()->enrol_user($student3->id, $course1->id);
        $this->getDataGenerator()->enrol_user($teacher->id, $course1->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course1->id);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1 (availability by custom profile field is empty)';
        $record->chooseorcreatecourse = 1; // Required.
        $record->courseid = $course1->id;

        // Set test availability setting(s) - require company is empty (operator ()).
        $record->bo_cond_userprofilefield_2_custom_restrict = 1;
        $record->bo_cond_customuserprofilefield_field = 'company';
        $record->bo_cond_customuserprofilefield_operator = '()';
        $record->bo_cond_customuserprofilefield_value = '';
        $record->bo_cond_customuserprofilefield_sqlfiltercheck = 1;

        [$course, $cm] = get_course_and_cm_from_cmid($booking1->cmid);
        // Before the creation, we need to fix the Page context.
        $PAGE->set_cm($cm, $course);
        $PAGE->set_context(context_module::instance($booking1->cmid));

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        // Make sure sql filter active indicator is set correctly.
        $this->assertEquals(MOD_BOOKING_SQL_FILTER_ACTIVE_JSON_BO, $settings->sqlfilter);

        $boinfo = new bo_info($settings);

        // Try to book student1 - NOT allowed (company is not empty).
        $this->setUser($student1);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_JSON_CUSTOMUSERPROFILEFIELD, $id);

        // This user should not see the booking option.
        $rawdata = $plugingenerator->create_table_for_one_option($settings->id);
        $this->assertEquals(0, count($rawdata)); // We expect that the student cant see the option.

        // Try to book student2 - allowed (company is empty).
        $this->setUser($student2);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);

        // This user should see the booking option.
        $rawdata = $plugingenerator->create_table_for_one_option($settings->id);
        $this->assertEquals(1, count($rawdata)); // We expect that the student can see the option.

        // Try to book student3 - allowed (company is empty).
        $this->setUser($student3);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);

        // This user should see the booking option.
        $rawdata = $plugingenerator->create_table_for_one_option($settings->id);
        $this->assertEquals(1, count($rawdata)); // We expect that the student can see the option.

        // Now test the opposite - not empty operator (!).
        $this->setAdminUser();
        $record->id = $option1->id;
        $record->bo_cond_customuserprofilefield_operator = '(!)';
        $record->cmid = $settings->cmid;
        booking_option::update($record);

        // Recreate boinfo to get updated settings.
        singleton_service::destroy_instance();
        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $boinfo = new bo_info($settings);

        // Try to book student1 - allowed (company is not empty).
        $this->setUser($student1);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);

        // This user should see the booking option.
        $rawdata = $plugingenerator->create_table_for_one_option($settings->id);
        $this->assertEquals(1, count($rawdata)); // We expect that the student can see the option.

        // Try to book student2 - NOT allowed (company is empty).
        $this->setUser($student2);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_JSON_CUSTOMUSERPROFILEFIELD, $id);

        // This user should not see the booking option.
        $rawdata = $plugingenerator->create_table_for_one_option($settings->id);
        $this->assertEquals(0, count($rawdata)); // We expect that the student cant see the option.
    }

    /**
     * Data provider for condition_userprofilefield_test
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    public static function booking_common_settings_provider(): array {
        $bdata = [
            'name' => 'Test Booking User Profile Field',
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
            'showviews' => ['mybooking,myoptions,optionsiamresponsiblefor,showall,showactive,myinstitution'],
        ];
        return ['bdata' => [$bdata]];
    }
}
