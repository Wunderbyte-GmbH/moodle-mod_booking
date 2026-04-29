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
 * Tests for the enrollinkskipconditions admin setting.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use mod_booking\bo_availability\bo_info;
use mod_booking\booking_option;
use mod_booking\enrollink;
use mod_booking_generator;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests for the enrollinkskipconditions admin setting.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class enrollink_skipconditions_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
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
        enrollink::destroy_instances();
    }

    /**
     * Test that booking via enrollink succeeds when booking_time blocks but is in enrollinkskipconditions (default).
     *
     * With the default configuration, booking_time is pre-selected in enrollinkskipconditions.
     * A booking attempt via enrollink after the booking closing time must therefore succeed.
     *
     * @covers \mod_booking\booking_option::option_allows_booking_for_user
     * @covers \mod_booking\bo_availability\bo_info::set_enrollink_context
     *
     * @param array $bdata
     * @dataProvider booking_settings_provider
     */
    public function test_enrollink_bypasses_booking_time_by_default(array $bdata): void {
        // Configure enrollinkskipconditions with booking_time (default behaviour).
        set_config('enrollinkskipconditions', MOD_BOOKING_BO_COND_BOOKING_TIME, 'booking');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($student->id, $course->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id, 'editingteacher');

        // Option with booking time already closed (opening and closing in the past).
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Test option (closed booking time)';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->bookingopeningtime = strtotime('now - 10 days');
        $record->bookingclosingtime = strtotime('now - 1 day');
        $record->restrictanswerperiodopening = 1;
        $record->restrictanswerperiodclosing = 1;

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        $this->setUser($student);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boinfo = new bo_info($settings);

        // Without enrollink context: booking_time blocks.
        bo_info::set_enrollink_context(false);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student->id, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKING_TIME, $id, 'Booking time should block without enrollink context.');

        // With enrollink context: booking_time is bypassed.
        bo_info::set_enrollink_context(true);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student->id, false);
        bo_info::set_enrollink_context(false);
        $this->assertNotEquals(
            MOD_BOOKING_BO_COND_BOOKING_TIME,
            $id,
            'Booking time should be bypassed with enrollink context when booking_time is in enrollinkskipconditions.'
        );
    }

    /**
     * Test that booking via enrollink is blocked when enrollinkskipconditions is empty.
     *
     * If no conditions are selected in enrollinkskipconditions, the enrollink context must not
     * bypass any conditions. A booking attempt after the booking closing time must therefore fail.
     *
     * @covers \mod_booking\booking_option::option_allows_booking_for_user
     * @covers \mod_booking\bo_availability\bo_info::set_enrollink_context
     *
     * @param array $bdata
     * @dataProvider booking_settings_provider
     */
    public function test_enrollink_does_not_bypass_when_no_conditions_configured(array $bdata): void {
        // Configure enrollinkskipconditions with no conditions selected.
        set_config('enrollinkskipconditions', '', 'booking');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($student->id, $course->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id, 'editingteacher');

        // Option with booking time already closed.
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Test option (closed booking time, no bypass)';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->bookingopeningtime = strtotime('now - 10 days');
        $record->bookingclosingtime = strtotime('now - 1 day');
        $record->restrictanswerperiodopening = 1;
        $record->restrictanswerperiodclosing = 1;

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        $this->setUser($student);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boinfo = new bo_info($settings);

        // Even with enrollink context: booking_time is NOT bypassed because config is empty.
        bo_info::set_enrollink_context(true);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student->id, false);
        bo_info::set_enrollink_context(false);
        $this->assertEquals(
            MOD_BOOKING_BO_COND_BOOKING_TIME,
            $id,
            'Booking time should still block with enrollink context when enrollinkskipconditions is empty.'
        );
    }

    /**
     * Test that booking via enrollink bypasses the selectusers condition when configured.
     *
     * @covers \mod_booking\booking_option::option_allows_booking_for_user
     * @covers \mod_booking\bo_availability\bo_info::set_enrollink_context
     *
     * @param array $bdata
     * @dataProvider booking_settings_provider
     */
    public function test_enrollink_bypasses_selectusers_when_configured(array $bdata): void {
        set_config('enrollinkskipconditions', MOD_BOOKING_BO_COND_JSON_SELECTUSERS, 'booking');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->getDataGenerator()->create_user();
        $anotheruser = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($student->id, $course->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id, 'editingteacher');

        // Option restricted to anotheruser only — student is not allowed.
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Test option (selectusers restriction)';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->bo_cond_selectusers_restrict = 1;
        $record->bo_cond_selectusers_userids = [$anotheruser->id];

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        $this->setUser($student);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boinfo = new bo_info($settings);

        // Without enrollink context: selectusers blocks.
        bo_info::set_enrollink_context(false);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student->id, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_JSON_SELECTUSERS, $id, 'Selectusers should block without enrollink context.');

        // With enrollink context: selectusers is bypassed.
        bo_info::set_enrollink_context(true);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student->id, false);
        bo_info::set_enrollink_context(false);
        $this->assertNotEquals(
            MOD_BOOKING_BO_COND_JSON_SELECTUSERS,
            $id,
            'Selectusers should be bypassed with enrollink context when it is in enrollinkskipconditions.'
        );
    }

    /**
     * Test that booking via enrollink bypasses the enrolledincohorts condition when configured.
     *
     * @covers \mod_booking\booking_option::option_allows_booking_for_user
     * @covers \mod_booking\bo_availability\bo_info::set_enrollink_context
     *
     * @param array $bdata
     * @dataProvider booking_settings_provider
     */
    public function test_enrollink_bypasses_enrolledincohorts_when_configured(array $bdata): void {
        set_config('enrollinkskipconditions', MOD_BOOKING_BO_COND_JSON_ENROLLEDINCOHORTS, 'booking');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($student->id, $course->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id, 'editingteacher');

        // Create a cohort the student does NOT belong to.
        $cohort = $this->getDataGenerator()->create_cohort(['contextid' => \context_system::instance()->id]);

        // Option restricted to cohort members only.
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Test option (enrolledincohorts restriction)';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->bo_cond_enrolledincohorts_restrict = 1;
        $record->bo_cond_enrolledincohorts_cohortids = [$cohort->id];
        $record->bo_cond_enrolledincohorts_cohortids_operator = 'OR';

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        $this->setUser($student);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boinfo = new bo_info($settings);

        // Without enrollink context: enrolledincohorts blocks.
        bo_info::set_enrollink_context(false);
        $withoutlink = booking_option::option_allows_booking_for_user($option->id, $student->id);
        $this->assertFalse($withoutlink, 'Booking should be blocked without enrollink context.');

        // With enrollink context: enrolledincohorts is bypassed.
        bo_info::set_enrollink_context(true);
        $withlink = booking_option::option_allows_booking_for_user($option->id, $student->id);
        bo_info::set_enrollink_context(false);
        $this->assertTrue(
            $withlink,
            'Booking should be allowed with enrollink context when enrolledincohorts is in enrollinkskipconditions.'
        );
    }

    /**
     * Test that the enrollink bypass is selective — only conditions listed in enrollinkskipconditions are bypassed.
     *
     * enrollinkskipconditions contains only booking_time. A selectusers restriction must still block,
     * even when the enrollink context is active.
     *
     * @covers \mod_booking\booking_option::option_allows_booking_for_user
     * @covers \mod_booking\bo_availability\bo_info::set_enrollink_context
     *
     * @param array $bdata
     * @dataProvider booking_settings_provider
     */
    public function test_enrollink_only_bypasses_configured_conditions(array $bdata): void {
        // Only booking_time is configured — selectusers must still block.
        set_config('enrollinkskipconditions', MOD_BOOKING_BO_COND_BOOKING_TIME, 'booking');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->getDataGenerator()->create_user();
        $anotheruser = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($student->id, $course->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id, 'editingteacher');

        // Option restricted to anotheruser only — student is not allowed.
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Test option (selectusers, selective bypass)';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->bo_cond_selectusers_restrict = 1;
        $record->bo_cond_selectusers_userids = [$anotheruser->id];

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        $this->setUser($student);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boinfo = new bo_info($settings);

        // With enrollink context: selectusers is still NOT bypassed because it is not in enrollinkskipconditions.
        bo_info::set_enrollink_context(true);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student->id, false);
        bo_info::set_enrollink_context(false);
        $this->assertEquals(
            MOD_BOOKING_BO_COND_JSON_SELECTUSERS,
            $id,
            'Selectusers should still block even with enrollink context when it is not in enrollinkskipconditions.'
        );
    }

    /**
     * Test that enrol_user() returns BLOCKED_BY_CONDITION and get_condition_block_description() returns
     * a non-empty reason when a hard-blocking condition (selectusers) is active and not in enrollinkskipconditions.
     *
     * @covers \mod_booking\enrollink::enrol_user
     * @covers \mod_booking\enrollink::get_condition_block_description
     *
     * @param array $bdata
     * @dataProvider booking_settings_provider
     */
    public function test_enrol_user_returns_blocked_status_and_description(array $bdata): void {
        // Only booking_time is in the skip list — selectusers is NOT skipped.
        set_config('enrollinkskipconditions', MOD_BOOKING_BO_COND_BOOKING_TIME, 'booking');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->getDataGenerator()->create_user();
        $anotheruser = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($student->id, $course->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id, 'editingteacher');

        // Option restricted to anotheruser only — student is blocked by selectusers.
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Test option (selectusers blocks, not skipped)';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->bo_cond_selectusers_restrict = 1;
        $record->bo_cond_selectusers_userids = [$anotheruser->id];

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        $erlid = $this->create_test_enrollink_bundle($option->id, $bookingmanager->id);
        $enrollinkobj = enrollink::get_instance($erlid);

        $this->setUser($student);

        $result = $enrollinkobj->enrol_user($student->id);
        $this->assertEquals(
            MOD_BOOKING_AUTOENROL_STATUS_BLOCKED_BY_CONDITION,
            $result,
            'enrol_user() must return BLOCKED_BY_CONDITION when selectusers blocks and is not in skipconditions.'
        );

        $description = $enrollinkobj->get_condition_block_description($student->id);
        $this->assertNotEmpty(
            $description,
            'get_condition_block_description() must return a non-empty reason string when enrollment is blocked.'
        );
    }

    /**
     * Test that enrol_user() returns SUCCESS when the blocking condition (selectusers) is listed
     * in enrollinkskipconditions and is therefore bypassed during enrollink enrollment.
     *
     * @covers \mod_booking\enrollink::enrol_user
     *
     * @param array $bdata
     * @dataProvider booking_settings_provider
     */
    public function test_enrol_user_returns_success_when_selectusers_in_skipconditions(array $bdata): void {
        // Condition "selectusers" is in the skip list — it must be bypassed.
        set_config('enrollinkskipconditions', MOD_BOOKING_BO_COND_JSON_SELECTUSERS, 'booking');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->getDataGenerator()->create_user();
        $anotheruser = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($student->id, $course->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id, 'editingteacher');

        // Option restricted to anotheruser — student would normally be blocked.
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Test option (selectusers skipped via config)';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->bo_cond_selectusers_restrict = 1;
        $record->bo_cond_selectusers_userids = [$anotheruser->id];

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        $erlid = $this->create_test_enrollink_bundle($option->id, $bookingmanager->id);
        $enrollinkobj = enrollink::get_instance($erlid);

        $this->setUser($student);

        // Condition "selectusers" is bypassed — enrollment must succeed.
        $result = $enrollinkobj->enrol_user($student->id);
        $this->assertEquals(
            MOD_BOOKING_AUTOENROL_STATUS_SUCCESS,
            $result,
            'enrol_user() must return SUCCESS when selectusers blocks but is listed in enrollinkskipconditions.'
        );

        $infostring = $enrollinkobj->get_readable_info($result);
        $this->assertNotEmpty($infostring, 'get_readable_info() must return a non-empty success string.');
    }

    /**
     * Test that enrol_user() returns SUCCESS for an option with expired booking time
     * when booking_time is listed in enrollinkskipconditions (the default configuration).
     *
     * This is an end-to-end verification of the full enrol_user() flow with the default setting.
     *
     * @covers \mod_booking\enrollink::enrol_user
     *
     * @param array $bdata
     * @dataProvider booking_settings_provider
     */
    public function test_enrol_user_returns_success_with_expired_booking_time_in_skipconditions(array $bdata): void {
        // Condition "booking_time" is in the skip list — it must be bypassed.
        set_config('enrollinkskipconditions', MOD_BOOKING_BO_COND_BOOKING_TIME, 'booking');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($student->id, $course->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id, 'editingteacher');

        // Option with booking time already closed.
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Test option (expired booking time)';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->bookingopeningtime = strtotime('now - 10 days');
        $record->bookingclosingtime = strtotime('now - 1 day');
        $record->restrictanswerperiodopening = 1;
        $record->restrictanswerperiodclosing = 1;

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        $erlid = $this->create_test_enrollink_bundle($option->id, $bookingmanager->id);
        $enrollinkobj = enrollink::get_instance($erlid);

        $this->setUser($student);

        // Condition "booking_time" is skipped — enrollment must succeed despite expired booking time.
        $result = $enrollinkobj->enrol_user($student->id);
        $this->assertEquals(
            MOD_BOOKING_AUTOENROL_STATUS_SUCCESS,
            $result,
            'enrol_user() must return SUCCESS when booking_time is in enrollinkskipconditions, even if expired.'
        );

        $infostring = $enrollinkobj->get_readable_info($result);
        $this->assertNotEmpty($infostring);
    }

    /**
     * Creates a minimal enrollink bundle DB record for testing.
     *
     * @param int $optionid The booking option to link to.
     * @param int $userid The user who owns the bundle.
     * @param int $places Number of available places in the bundle.
     * @return string The generated erlid.
     */
    private function create_test_enrollink_bundle(int $optionid, int $userid, int $places = 5): string {
        global $DB;
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $erlid = md5(uniqid('test_enrollink_', true));
        $DB->insert_record('booking_enrollink_bundles', [
            'erlid'        => $erlid,
            'courseid'     => $settings->courseid ?? 0,
            'userid'       => $userid,
            'usermodified' => $userid,
            'timecreated'  => time(),
            'timemodified' => time(),
            'places'       => $places,
            'baid'         => 0,
            'optionid'     => $optionid,
        ]);
        return $erlid;
    }

    /**
     * Data provider with minimal booking instance settings.
     *
     * @return array
     */
    public static function booking_settings_provider(): array {
        $bdata = [
            'name' => 'Test Booking 1',
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
