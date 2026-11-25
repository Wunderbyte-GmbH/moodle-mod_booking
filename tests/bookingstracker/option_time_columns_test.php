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
 * Tests for the start and the end of the booking option in the Bookings tracker.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use cache_helper;
use local_wunderbyte_table\wunderbyte_table;
use mod_booking\booking_answers\booking_answers;
use mod_booking\table\manageusers_table;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->dirroot/mod/booking/lib.php");

/**
 * The scopes that list several booking options (system, course and instance scope, in the
 * aggregated view and in "View all bookings separately") show the start of the first date and
 * the end of the last date of the booking option of every row. The option scope does not.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\booking_answers\scope_base::option_time_columns
 * @covers \mod_booking\booking_answers\scope_base_options
 * @covers \mod_booking\booking_answers\scope_base_answers
 * @covers \mod_booking\table\manageusers_table::col_coursestarttime
 * @covers \mod_booking\table\manageusers_table::col_courseendtime
 */
final class option_time_columns_test extends booking_advanced_testcase {
    /** @var int Start of the first date: 2026-12-01 09:00:00 UTC. */
    private const FIRSTSTART = 1796115600;

    /**
     * The six scopes that show start and end of the booking option.
     *
     * @return array
     */
    public static function scope_provider(): array {
        return [
            'system' => ['system'],
            'course' => ['course'],
            'instance' => ['instance'],
            'system, answers view' => ['systemanswers'],
            'course, answers view' => ['courseanswers'],
            'instance, answers view' => ['instanceanswers'],
        ];
    }

    /**
     * Every scope shows both columns after the booking option (and its instance), offers them for
     * sorting and delivers the start of the first date and the end of the last date of the option.
     * A booking option without dates has neither.
     *
     * @dataProvider scope_provider
     * @param string $scope
     */
    public function test_columns_and_values(string $scope): void {
        global $DB;

        $this->setAdminUser();
        [$course, $booking, $student] = $this->create_instance();

        // Three dates, entered out of order: the second one starts first, the first one ends last.
        $withdates = $this->create_option($booking, 'Option with dates', $student, [
            [self::FIRSTSTART + 7 * DAYSECS, self::FIRSTSTART + 7 * DAYSECS + 3 * HOURSECS],
            [self::FIRSTSTART, self::FIRSTSTART + 2 * HOURSECS],
            [self::FIRSTSTART + DAYSECS, self::FIRSTSTART + DAYSECS + HOURSECS],
        ]);
        $withoutdates = $this->create_option($booking, 'Option without dates', $student, []);
        $expectedstart = self::FIRSTSTART;
        $expectedend = self::FIRSTSTART + 7 * DAYSECS + 3 * HOURSECS;

        // What we expect is what the dates of the option say, not just what is stored for the option.
        $dates = $DB->get_records('booking_optiondates', ['optionid' => $withdates->id]);
        $this->assertCount(3, $dates);
        $this->assertSame($expectedstart, min(array_map(fn($date) => (int)$date->coursestarttime, $dates)));
        $this->assertSame($expectedend, max(array_map(fn($date) => (int)$date->courseendtime, $dates)));

        $scopeid = match ($scope) {
            'system', 'systemanswers' => 0,
            'course', 'courseanswers' => (int)$course->id,
            'instance', 'instanceanswers' => (int)$withdates->cmid,
        };
        $scopeclass = (new booking_answers())->return_class_for_scope($scope);

        // Columns: right after the booking option and, in system and course scope, its instance.
        $columns = array_keys($scopeclass->return_cols_for_tables(MOD_BOOKING_STATUSPARAM_BOOKED, $scopeid));
        $position = array_search('coursestarttime', $columns);
        $this->assertNotFalse($position, 'The start of the booking option is missing.');
        $this->assertSame('courseendtime', $columns[$position + 1]);
        $before = in_array($scope, ['instance', 'instanceanswers']) ? 'text' : 'instancename';
        $this->assertSame($before, $columns[$position - 1]);

        $table = $this->build_table($scope, $scopeid);
        $this->assertArrayHasKey('coursestarttime', $table->sortablecolumns);
        $this->assertArrayHasKey('courseendtime', $table->sortablecolumns);

        // Values.
        $table->return_encoded_table();
        $table->setup();
        $table->query_db_cached(100, true);
        $this->assertSame('', $table->errormessage);
        $rows = [];
        foreach ($table->rawdata as $row) {
            $rows[(int)$row->optionid] = $row;
        }
        $this->assertArrayHasKey((int)$withdates->id, $rows);
        $this->assertArrayHasKey((int)$withoutdates->id, $rows);

        $this->assertSame($expectedstart, (int)$rows[$withdates->id]->coursestarttime);
        $this->assertSame($expectedend, (int)$rows[$withdates->id]->courseendtime);
        $this->assertEmpty($rows[$withoutdates->id]->coursestarttime);
        $this->assertEmpty($rows[$withoutdates->id]->courseendtime);

        // The cell of an option without dates stays empty.
        $this->assertSame('', $table->col_coursestarttime($rows[$withoutdates->id]));
        $this->assertSame('', $table->col_courseendtime($rows[$withoutdates->id]));
        $this->assertNotSame('', $table->col_coursestarttime($rows[$withdates->id]));
    }

    /**
     * The option scope lists the users of one booking option, so start and end would be the same
     * in every row: they are not part of its default columns.
     */
    public function test_option_scope_has_no_start_and_end(): void {
        $this->setAdminUser();
        [, $booking, $student] = $this->create_instance();
        $option = $this->create_option($booking, 'Single option', $student, [
            [self::FIRSTSTART, self::FIRSTSTART + HOURSECS],
        ]);

        $scopeclass = (new booking_answers())->return_class_for_scope('option');
        $columns = $scopeclass->return_cols_for_tables(MOD_BOOKING_STATUSPARAM_BOOKED, (int)$option->id);

        $this->assertArrayNotHasKey('coursestarttime', $columns);
        $this->assertArrayNotHasKey('courseendtime', $columns);
    }

    /**
     * Start and end are displayed with date and time in the timezone of the user (they used the
     * timezone of the server and had no time). Downloads use the sortable format of all time columns.
     */
    public function test_display_and_download_format(): void {
        global $USER;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->setTimezone('Europe/Vienna');
        $USER->timezone = 'Asia/Tokyo';

        // 2026-12-01 09:00 UTC is 18:00 in Tokyo; 2026-12-01 16:30 UTC is already the next day there.
        $values = (object)[
            'coursestarttime' => self::FIRSTSTART,
            'courseendtime' => self::FIRSTSTART + 7 * HOURSECS + 30 * MINSECS,
        ];
        $format = get_string('strftimedatetimeshort', 'langconfig');

        $table = new manageusers_table('optiontimedisplay');
        // The day keeps its leading zero in this numeric format.
        $this->assertSame('01/12/26, 18:00', userdate($values->coursestarttime, $format, 'Asia/Tokyo', false));
        $this->assertSame('01/12/26, 18:00', $table->col_coursestarttime($values));
        $this->assertSame('02/12/26, 01:30', $table->col_courseendtime($values));
        $this->assertStringContainsString('18:00', $table->col_coursestarttime($values));
        $this->assertStringContainsString('01:30', $table->col_courseendtime($values));
        $this->assertNotSame(
            userdate($values->coursestarttime, $format, 'Europe/Vienna', false),
            $table->col_coursestarttime($values)
        );

        $download = new manageusers_table('optiontimedownload');
        // Mark the table as downloading without starting an export.
        $download->download = 'csv';
        $downloadformat = '%Y-%m-%d %H:%M';
        $this->assertSame(
            userdate($values->coursestarttime, $downloadformat, 'Asia/Tokyo'),
            $download->col_coursestarttime($values)
        );
        $this->assertSame(
            userdate($values->courseendtime, $downloadformat, 'Asia/Tokyo'),
            $download->col_courseendtime($values)
        );
        $this->assertStringStartsWith('2026-12-', $download->col_coursestarttime($values));
        $this->assertStringEndsWith(' 18:00', $download->col_coursestarttime($values));
        $this->assertStringEndsWith(' 01:30', $download->col_courseendtime($values));

        $empty = (object)['coursestarttime' => 0, 'courseendtime' => null];
        $this->assertSame('', $table->col_coursestarttime($empty));
        $this->assertSame('', $table->col_courseendtime($empty));
        $this->assertSame('', $download->col_coursestarttime($empty));
    }

    /**
     * Helper: the table of a scope with its default columns, for the booked users.
     *
     * @param string $scope
     * @param int $scopeid
     * @return wunderbyte_table
     */
    private function build_table(string $scope, int $scopeid): wunderbyte_table {
        $scopeclass = (new booking_answers())->return_class_for_scope($scope);
        $columns = $scopeclass->return_cols_for_tables(MOD_BOOKING_STATUSPARAM_BOOKED, $scopeid);
        return $scopeclass->return_users_table(
            $scope,
            $scopeid,
            MOD_BOOKING_STATUSPARAM_BOOKED,
            'optiontimes',
            array_keys($columns),
            array_values($columns)
        );
    }

    /**
     * Helper: a course with a booking instance and an enrolled student.
     *
     * @return array{0: stdClass, 1: stdClass, 2: stdClass}
     */
    private function create_instance(): array {
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'name' => 'Option times test booking',
            'course' => $course->id,
            'bookingmanager' => 'admin',
        ]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        return [$course, $booking, $student];
    }

    /**
     * Helper: a booking option with the given dates, booked by the student.
     *
     * @param stdClass $booking
     * @param string $title
     * @param stdClass $student
     * @param array $dates list of [start, end]
     * @return \mod_booking\booking_option_settings
     */
    private function create_option(stdClass $booking, string $title, stdClass $student, array $dates) {
        $record = new stdClass();
        $record->importing = 1;
        $record->bookingid = $booking->id;
        $record->text = $title;
        $record->useprice = 0;
        $record->maxanswers = 5;
        foreach ($dates as $index => [$start, $end]) {
            $record->{'optiondateid_' . $index} = '0';
            $record->{'daystonotify_' . $index} = '0';
            $record->{'coursestarttime_' . $index} = $start;
            $record->{'courseendtime_' . $index} = $end;
        }

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        $this->setUser($student);
        booking_bookit::bookit('option', $settings->id, $student->id);
        booking_bookit::bookit('option', $settings->id, $student->id);
        $this->setAdminUser();

        cache_helper::purge_by_event('setbackoptionsanswers');
        cache_helper::purge_by_event('setbackbookedusertable');
        singleton_service::destroy_instance();

        return singleton_service::get_instance_of_booking_option_settings($option->id);
    }
}
