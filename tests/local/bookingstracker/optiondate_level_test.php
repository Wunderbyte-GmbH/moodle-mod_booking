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

namespace mod_booking\local\bookingstracker;

use mod_booking\booking_answers\booking_answers;
use mod_booking\booking_bookit;
use mod_booking\singleton_service;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use moodle_exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->dirroot/mod/booking/lib.php");

/**
 * The site setting booking/bookingstrackerdisableoptiondatelevel switches off the level of a single date
 * (optiondate) in the Bookings tracker. The presence counter counts the presences of the dates and is
 * switched off with it.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class optiondate_level_test extends booking_advanced_testcase {
    /**
     * Without the setting (new installation, upgrade) the Bookings tracker keeps the level of a date.
     *
     * @covers \mod_booking\local\bookingstracker\optiondate_level::is_enabled
     */
    public function test_level_is_enabled_by_default(): void {
        $this->assertEmpty(get_config('booking', optiondate_level::SETTING));
        $this->assertTrue(optiondate_level::is_enabled());

        set_config(optiondate_level::SETTING, 1, 'booking');
        $this->assertFalse(optiondate_level::is_enabled());

        set_config(optiondate_level::SETTING, 0, 'booking');
        $this->assertTrue(optiondate_level::is_enabled());
    }

    /**
     * The presence counter needs its own setting and the level of a date.
     *
     * @covers \mod_booking\local\bookingstracker\optiondate_level::presence_counter_enabled
     * @dataProvider presence_counter_provider
     * @param int $counter value of booking/bookingstrackerpresencecounter
     * @param int $leveldisabled value of booking/bookingstrackerdisableoptiondatelevel
     * @param bool $expected
     */
    public function test_presence_counter_needs_the_level(int $counter, int $leveldisabled, bool $expected): void {
        set_config('bookingstrackerpresencecounter', $counter, 'booking');
        set_config(optiondate_level::SETTING, $leveldisabled, 'booking');
        $this->assertSame($expected, optiondate_level::presence_counter_enabled());
    }

    /**
     * Data provider for test_presence_counter_needs_the_level.
     *
     * @return array
     */
    public static function presence_counter_provider(): array {
        return [
            'counter on, level on' => [1, 0, true],
            'counter on, level off' => [1, 1, false],
            'counter off, level on' => [0, 0, false],
            'counter off, level off' => [0, 1, false],
        ];
    }

    /**
     * Only requests in the scope of a date are stopped, and only while the level is switched off.
     *
     * @covers \mod_booking\local\bookingstracker\optiondate_level::require_scope_enabled
     */
    public function test_require_scope_enabled(): void {
        // Level on: every scope passes.
        optiondate_level::require_scope_enabled('optiondate');
        optiondate_level::require_scope_enabled('option');

        set_config(optiondate_level::SETTING, 1, 'booking');
        // All other scopes still pass.
        foreach (['', 'option', 'instance', 'course', 'system', 'systemanswers'] as $scope) {
            optiondate_level::require_scope_enabled($scope);
        }

        try {
            optiondate_level::require_scope_enabled('optiondate');
            $this->fail('The scope optiondate must be rejected while the level is switched off.');
        } catch (moodle_exception $e) {
            $this->assertSame('bookingstrackeroptiondateleveldisabled', $e->errorcode);
        }
    }

    /**
     * Links to a date lead to the booking option of the date.
     *
     * @covers \mod_booking\local\bookingstracker\optiondate_level::fallback_url
     */
    public function test_fallback_url_is_the_option_scope(): void {
        $url = optiondate_level::fallback_url(42);
        $this->assertSame('/mod/booking/report2.php?optionid=42', $url->out_as_local_url(false));
    }

    /**
     * Option scope: the column of the presence counter and its SQL part disappear with the level of a date
     * and come back - with the stored presences - when the level is switched on again.
     *
     * @covers \mod_booking\booking_answers\scopes\option::return_cols_for_tables
     * @covers \mod_booking\booking_answers\scopes\option::return_sql_for_booked_users
     */
    public function test_option_scope_hides_the_presence_counter(): void {
        global $DB;

        $this->setAdminUser();
        [$settings, $optiondateid, $student] = $this->create_booked_option_with_session();

        // The student was present on the date.
        $status = MOD_BOOKING_PRESENCE_STATUS_ATTENDING;
        set_config('bookingstrackerpresencecounter', 1, 'booking');
        set_config('bookingstrackerpresencecountervaluetocount', $status, 'booking');
        $DB->insert_record('booking_optiondates_answers', (object)[
            'optionid' => $settings->id,
            'optiondateid' => $optiondateid,
            'userid' => $student->id,
            'status' => $status,
            'usermodified' => $student->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $scope = (new booking_answers())->return_class_for_scope('option');

        // Level on: the counter is shown and counts the presence.
        $cols = $scope->return_cols_for_tables(MOD_BOOKING_STATUSPARAM_BOOKED, (int)$settings->id);
        $this->assertArrayHasKey('presencecount', $cols);
        $row = $this->booked_row($scope, (int)$settings->id);
        $this->assertEquals(1, (int)$row->presencecount);

        // Level off: no column, and the SQL neither selects nor joins the counter.
        set_config(optiondate_level::SETTING, 1, 'booking');
        $cols = $scope->return_cols_for_tables(MOD_BOOKING_STATUSPARAM_BOOKED, (int)$settings->id);
        $this->assertArrayNotHasKey('presencecount', $cols);
        $row = $this->booked_row($scope, (int)$settings->id);
        $this->assertObjectNotHasProperty('presencecount', $row);

        // The presence of the date is kept, so the counter shows it again after switching the level on.
        set_config(optiondate_level::SETTING, 0, 'booking');
        $this->assertTrue($DB->record_exists('booking_optiondates_answers', ['optiondateid' => $optiondateid]));
        $row = $this->booked_row($scope, (int)$settings->id);
        $this->assertEquals(1, (int)$row->presencecount);
    }

    /**
     * Aggregated scopes (system, course, instance): the column of the presence counter disappears as well.
     *
     * @covers \mod_booking\booking_answers\scope_base_options::return_cols_for_tables
     */
    public function test_aggregated_scopes_hide_the_presence_counter(): void {
        $this->setAdminUser();
        set_config('bookingstrackerpresencecounter', 1, 'booking');
        $ba = new booking_answers();

        foreach (['system', 'course', 'instance'] as $scopename) {
            $scope = $ba->return_class_for_scope($scopename);

            set_config(optiondate_level::SETTING, 0, 'booking');
            $this->assertArrayHasKey(
                'presencecount',
                $scope->return_cols_for_tables(MOD_BOOKING_STATUSPARAM_BOOKED),
                "Scope $scopename shows the counter with the level of a date."
            );

            set_config(optiondate_level::SETTING, 1, 'booking');
            $this->assertArrayNotHasKey(
                'presencecount',
                $scope->return_cols_for_tables(MOD_BOOKING_STATUSPARAM_BOOKED),
                "Scope $scopename hides the counter without the level of a date."
            );
        }
    }

    /**
     * Helper: the single row of the booked users table of an option.
     *
     * @param \mod_booking\booking_answers\scope_base $scope
     * @param int $optionid
     * @return stdClass
     */
    private function booked_row($scope, int $optionid): stdClass {
        global $DB;

        [$fields, $from, $where, $params] = $scope->return_sql_for_booked_users(
            'option',
            $optionid,
            MOD_BOOKING_STATUSPARAM_BOOKED
        );
        $rows = $DB->get_records_sql("SELECT $fields FROM $from WHERE $where", $params);
        $this->assertCount(1, $rows);
        return reset($rows);
    }

    /**
     * Helper: booking option with one date (optiondate) and one booked student.
     *
     * @return array{0: \mod_booking\booking_option_settings, 1: int, 2: stdClass}
     */
    private function create_booked_option_with_session(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $bookingmanager = $this->getDataGenerator()->create_user();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'name' => 'Optiondate level test booking',
            'course' => $course->id,
            'bookingmanager' => $bookingmanager->username,
        ]);

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $record = new stdClass();
        $record->importing = 1;
        $record->bookingid = $booking->id;
        $record->text = 'Option for the optiondate level';
        $record->useprice = 0;
        $record->maxanswers = 5;
        $record->optiondateid_1 = "0";
        $record->daystonotify_1 = "0";
        $record->coursestarttime_1 = strtotime('20 May 2050 15:00');
        $record->courseendtime_1 = strtotime('20 May 2050 16:00');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        booking_bookit::bookit('option', $settings->id, $student->id);
        booking_bookit::bookit('option', $settings->id, $student->id);

        $optiondateid = (int)$DB->get_field('booking_optiondates', 'id', ['optionid' => $option->id], MUST_EXIST);

        return [$settings, $optiondateid, $student];
    }
}
