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
 * Hard-enforcing tests for the sqlfilter marker semantics of the SQL availability filter.
 *
 * The booking_options.sqlfilter column is a single int marker (0 inactive,
 * 1 = MOD_BOOKING_SQL_FILTER_ACTIVE_JSON_BO, 2 = MOD_BOOKING_SQL_FILTER_ACTIVE_BO_TIME).
 * When a JSON availability condition (e.g. enrolledincourse) AND the booking-time
 * SQL filter are both active on the same option, the column can only hold one
 * value (booking_time wins -> 2). The JSON conditions, however, read their own
 * active-marker from inside the availability JSON, so they must KEEP filtering
 * even when the column is 2.
 *
 * test_course_filter_still_discriminates_when_marker_is_time pins exactly that:
 * with the column at 2 and the time window OPEN (so time does not hide anything),
 * a user who passes time but fails the course requirement must still be hidden.
 * The existing combined test never proves this, because there the time is in the
 * past so every user is hidden by time regardless of the course filter.
 *
 * test_timeonly_sqlfilter_with_null_availability_stays_visible pins the
 * NULL-availability edge: JSON condition clauses are guarded by
 * "availability IS NOT NULL", so a legacy/imported option that has only the
 * booking-time SQL filter and a SQL-NULL availability must not be wrongly hidden.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\booking_advanced_testcase;
use context_module;
use mod_booking_generator;
use mod_booking\bo_availability\bo_info;
use stdClass;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Hard-enforcing tests for the sqlfilter marker semantics.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class condition_sqlfilter_marker_test extends booking_advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
    }

    /**
     * With the marker column at 2 (booking-time) the JSON course filter must STILL discriminate.
     *
     * Setup: one option with BOTH the enrolledincourse SQL filter (course2 AND course3)
     * and the booking-time SQL filter, with the time window currently OPEN. The marker
     * column therefore becomes 2. A user enrolled in both courses must see the option;
     * a user enrolled in only one course (passes time, fails course) must NOT see it.
     *
     * @covers \mod_booking\bo_availability\conditions\enrolledincourse::return_sql
     * @covers \mod_booking\bo_availability\conditions\booking_time::return_sql
     * @covers \mod_booking\bo_availability\bo_info::return_sql_from_conditions
     *
     * @param array $bdata
     * @dataProvider booking_common_settings_provider
     */
    public function test_course_filter_still_discriminates_when_marker_is_time(array $bdata): void {
        global $PAGE;

        set_config('usesqlfilteravailability', 1, 'booking');
        $bdata['cancancelbook'] = 1;
        singleton_service::destroy_instance();

        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $course2 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $course3 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        $studentboth = $this->getDataGenerator()->create_user();
        $studentone = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $bookingmanager->username;
        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        // studentboth meets the course requirement, studentone does not.
        $this->getDataGenerator()->enrol_user($studentboth->id, $course2->id);
        $this->getDataGenerator()->enrol_user($studentboth->id, $course3->id);
        $this->getDataGenerator()->enrol_user($studentone->id, $course2->id);

        $this->getDataGenerator()->enrol_user($studentboth->id, $course1->id);
        $this->getDataGenerator()->enrol_user($studentone->id, $course1->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course1->id);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Option restricted by course AND time (time window open)';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course1->id;

        // JSON course filter: must be enrolled in both course2 AND course3.
        $record->bo_cond_enrolledincourse_restrict = 1;
        $record->bo_cond_enrolledincourse_courseids = [$course2->id, $course3->id];
        $record->bo_cond_enrolledincourse_courseids_operator = 'AND';
        $record->bo_cond_enrolledincourse_sqlfiltercheck = 1;

        // Booking-time filter with an OPEN window: opened yesterday, closes tomorrow.
        // Time therefore hides nobody; only the course filter may discriminate.
        $record->restrictanswerperiodopening = 1;
        $record->restrictanswerperiodclosing = 1;
        $record->bookingopeningtime = strtotime('now - 1 day');
        $record->bookingclosingtime = strtotime('now + 1 day');
        $record->bo_cond_booking_time_sqlfiltercheck = 1;

        [$course, $cm] = get_course_and_cm_from_cmid($booking1->cmid);
        $PAGE->set_cm($cm, $course);
        $PAGE->set_context(context_module::instance($booking1->cmid));

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        // Booking-time wins the single marker column.
        $this->assertEquals(
            MOD_BOOKING_SQL_FILTER_ACTIVE_BO_TIME,
            $settings->sqlfilter,
            'With both filters active the marker column must hold the booking-time value (2).'
        );

        $boinfo = new bo_info($settings);

        // studentboth: passes time AND course -> must SEE the option.
        $this->setUser($studentboth);
        $rawdata = $plugingenerator->create_table_for_one_option($settings->id);
        $this->assertEquals(1, count($rawdata), 'User enrolled in both courses must see the (time-open) option.');

        // studentone: passes time, FAILS course -> must NOT see the option.
        // This is the key assertion: the JSON course filter must still apply although marker == 2.
        $this->setUser($studentone);
        $rawdata = $plugingenerator->create_table_for_one_option($settings->id);
        $this->assertEquals(
            0,
            count($rawdata),
            'User who passes the open time window but fails the course requirement must still be hidden '
            . 'by the JSON course filter even though the marker column equals 2 (booking-time).'
        );

        // The PHP path must agree that the course condition is what blocks studentone.
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $studentone->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_JSON_ENROLLEDINCOURSE, $id);
    }

    /**
     * A booking-time-only SQL filter option with SQL-NULL availability must not be wrongly hidden.
     *
     * The JSON condition clauses are guarded by "availability IS NOT NULL". Options created
     * through the form always get '[]', but legacy/imported rows can carry SQL NULL. With the
     * time window OPEN such an option must remain visible to an ordinary enrolled user. If it
     * disappears, the "availability IS NOT NULL" guard is too strict and should use
     * COALESCE(availability, '[]') (or "availability IS NULL OR ...").
     *
     * @covers \mod_booking\bo_availability\conditions\enrolledincourse::return_sql
     * @covers \mod_booking\bo_availability\conditions\booking_time::return_sql
     * @covers \mod_booking\bo_availability\bo_info::return_sql_from_conditions
     *
     * @param array $bdata
     * @dataProvider booking_common_settings_provider
     */
    public function test_timeonly_sqlfilter_with_null_availability_stays_visible(array $bdata): void {
        global $DB, $PAGE;

        set_config('usesqlfilteravailability', 1, 'booking');
        $bdata['cancancelbook'] = 1;
        singleton_service::destroy_instance();

        $course1 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $course2 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        $student1 = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course1->id;
        $bdata['bookingmanager'] = $bookingmanager->username;
        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($student1->id, $course1->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course1->id);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Time-only SQL filter, open window';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course2->id;
        // OPEN time window: opened yesterday, closes tomorrow -> time hides nobody.
        $record->restrictanswerperiodopening = 1;
        $record->restrictanswerperiodclosing = 1;
        $record->bookingopeningtime = strtotime('now - 1 day');
        $record->bookingclosingtime = strtotime('now + 1 day');
        $record->bo_cond_booking_time_sqlfiltercheck = 1;

        [$course, $cm] = get_course_and_cm_from_cmid($booking1->cmid);
        $PAGE->set_cm($cm, $course);
        $PAGE->set_context(context_module::instance($booking1->cmid));

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $this->assertEquals(MOD_BOOKING_SQL_FILTER_ACTIVE_BO_TIME, $settings->sqlfilter);

        // Simulate a legacy/imported row: availability is SQL NULL instead of '[]'.
        $DB->set_field('booking_options', 'availability', null, ['id' => $option1->id]);
        singleton_service::destroy_instance();

        // Sanity: the column really is NULL now.
        $this->assertNull($DB->get_field('booking_options', 'availability', ['id' => $option1->id]));

        // The option is time-open and has no JSON availability conditions, so the student must SEE it.
        $this->setUser($student1);
        $rawdata = $plugingenerator->create_table_for_one_option($settings->id);
        $this->assertEquals(
            1,
            count($rawdata),
            'A time-open option with only the booking-time SQL filter and NULL availability must stay '
            . 'visible. If it is hidden, the "availability IS NOT NULL" guard in the JSON conditions is '
            . 'too strict for legacy/imported rows (use COALESCE(availability, \'[]\')).'
        );
    }

    /**
     * Data provider.
     *
     * @return array
     */
    public static function booking_common_settings_provider(): array {
        $bdata = [
            'name' => 'Test SQL filter marker semantics',
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
