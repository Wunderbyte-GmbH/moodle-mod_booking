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
 * Class handling tests for booking options.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
final class condition_enrolledincohorts_test extends advanced_testcase {
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
     * Test of booking option availability by cohorts and bookingtime.
     *
     * @covers \mod_booking\bo_availability\conditions\enrolledincohorts::is_available
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_booking_bookit_cohorts(array $bdata): void {
        global $DB, $CFG, $PAGE;

        $bdata['cancancelbook'] = 1;

        singleton_service::destroy_instance();

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
        $record->bo_cond_enrolledincohorts_sqlfiltercheck = 1;

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

        // Try to book student1 NOT - allowed.
        $this->setUser($student1);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_JSON_ENROLLEDINCOHORTS, $id);

        // Try to book student2 - allowed.
        $this->setUser($student2);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);

        $result = booking_bookit::bookit('option', $settings->id, $student2->id);
        $result = booking_bookit::bookit('option', $settings->id, $student2->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Try to book student3 - NOT allowed.
        $this->setUser($student3);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_JSON_ENROLLEDINCOHORTS, $id);

        // Now we  update test availability setting(s).
        $this->setAdminUser();
        $record->id = $option1->id;
        $record->bo_cond_enrolledincohorts_cohortids_operator = 'OR';
        $record->cmid = $settings->cmid;
        booking_option::update($record);

        // Try to book student1 - allowed.
        $this->setUser($student1);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);

        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        $result = booking_bookit::bookit('option', $settings->id, $student1->id);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // Try to book student3 - NOT allowed.
        $this->setUser($student3);

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student3->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_JSON_ENROLLEDINCOHORTS, $id);
    }

    /**
     * Test add to group.
     * @covers \mod_booking\bo_availability\conditions\enrolledincohorts::is_available
     *
     * @param array $bdata
     * @throws \coding_exception
     * @throws \dml_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_booking_enrolledincohorts_with_bookit_bookingtime(array $bdata): void {
        global $DB, $CFG, $PAGE;

        $bdata['cancancelbook'] = 1;

        singleton_service::destroy_instance();

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
        $record->bo_cond_enrolledincohorts_sqlfiltercheck = 1;

        $record->restrictanswerperiodopening = 1;
        $record->restrictanswerperiodclosing = 1;
        $record->bookingopeningtime = strtotime('now - 3 day');
        $record->bookingclosingtime = strtotime('now - 2 day');

        [$course, $cm] = get_course_and_cm_from_cmid($booking1->cmid);
        // Before the creation, we need to fix the Page context.
        $PAGE->set_cm($cm, $course);
        $PAGE->set_context(context_module::instance($booking1->cmid));

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        // Option one has both restriction, but the time restrictioin should not prevent visiblity.
        $option1 = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        // Make sure sql filter active indicator is set correctly.
        $this->assertEquals(MOD_BOOKING_SQL_FILTER_ACTIVE_JSON_BO, $settings->sqlfilter);

        $boinfo = new bo_info($settings);

        // Try to book student1 NOT - allowed.
        $this->setUser($student1);

        // This user should not see the booking option.
        $rawdata = $plugingenerator->create_table_for_one_option($settings->id);
        $this->assertEquals(0, count($rawdata)); // We expect that the student cant see the option.

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKING_TIME, $id);

        cohort_add_member($cohort2->id, $student1->id);

        // Cohort affiliations are saved in singletons, we need to destroy them.
        singleton_service::destroy_instance();

        // This user should not see the booking option.
        $rawdata = $plugingenerator->create_table_for_one_option($settings->id);
        $this->assertEquals(1, count($rawdata)); // We expect that the student cant see the option.

        // Try to book student2 - allowed.
        $this->setUser($student2);

        // This user should see the booking option, but can't book.
        $rawdata = $plugingenerator->create_table_for_one_option($settings->id);
        $this->assertEquals(1, count($rawdata)); // We expect that the student2 can see the option.

        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student2->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKING_TIME, $id);

        // Now we  update test availability setting(s).
        $this->setAdminUser();
        $record->id = $option1->id;
        $record->bo_cond_booking_time_sqlfiltercheck = 1;
        $record->cmid = $settings->cmid;
        booking_option::update($record);

        $this->setUser($student2);

        // This user should see the booking option, but can't book.
        $rawdata = $plugingenerator->create_table_for_one_option($settings->id);
        $this->assertEquals(0, count($rawdata)); // We expect that the student2 can see the option.
    }

    /**
     * Data provider for condition_bookingpolicy_test
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
            'notificationtext' => ['text' => 'text'],
            'userleave' => ['text' => 'text'],
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
