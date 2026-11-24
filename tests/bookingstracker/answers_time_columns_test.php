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
 * Tests for the time columns and their date filters in the answers views of the Bookings tracker.
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
use mod_booking\booking_answers\scope_base_answers;
use mod_booking\table\manageusers_table;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->dirroot/mod/booking/lib.php");

/**
 * The answers views (system, course and instance scope, "View all bookings separately") show the
 * creation date, the booking date and the time modified of every booking answer, with the values
 * of the booking answer, and can be narrowed down to a period for each of them.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\booking_answers\scope_base_answers
 * @covers \mod_booking\booking_answers\scopes\systemanswers
 * @covers \mod_booking\booking_answers\scopes\courseanswers
 * @covers \mod_booking\booking_answers\scopes\instanceanswers
 * @covers \mod_booking\table\manageusers_table::col_timemodified
 */
final class answers_time_columns_test extends booking_advanced_testcase {
    /** @var string[] The three time columns, in the order they are shown. */
    private const TIMECOLUMNS = ['timecreated', 'timebooked', 'timemodified'];

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        $_GET = [];
        $_POST = [];
    }

    /**
     * The three answers scopes.
     *
     * @return array
     */
    public static function scope_provider(): array {
        return [
            'system' => ['systemanswers'],
            'course' => ['courseanswers'],
            'instance' => ['instanceanswers'],
        ];
    }

    /**
     * Every answers scope shows the three time columns, offers them for sorting, includes them in
     * the download of the system and course scope and has a date filter for each of them.
     *
     * @dataProvider scope_provider
     * @param string $scope
     */
    public function test_columns_sorting_and_filters(string $scope): void {
        $this->setAdminUser();
        [$settings, $course] = $this->create_option_with_answers();
        $scopeid = $this->scopeid($scope, $settings, $course);
        $scopeclass = (new booking_answers())->return_class_for_scope($scope);

        $columns = $scopeclass->return_cols_for_tables(MOD_BOOKING_STATUSPARAM_BOOKED, $scopeid);
        $this->assertSame(self::TIMECOLUMNS, array_slice(array_keys($columns), -3));
        $this->assertSame(get_string('timecreated', 'mod_booking'), $columns['timecreated']);
        $this->assertSame(get_string('bookingdate', 'mod_booking'), $columns['timebooked']);
        $this->assertSame(get_string('timemodified', 'mod_booking'), $columns['timemodified']);

        $table = $this->build_table($scope, $scopeid, MOD_BOOKING_STATUSPARAM_BOOKED);
        foreach (self::TIMECOLUMNS as $column) {
            $this->assertArrayHasKey($column, $table->sortablecolumns, "$column is not sortable.");

            // A "from - to" filter on the column itself.
            $filter = $table->subcolumns['datafields'][$column] ?? null;
            $this->assertNotNull($filter, "$column has no filter.");
            $options = reset($filter['datepicker']);
            $this->assertSame($column, $options['columntimestart']);
            $this->assertSame($column, $options['columntimeend']);
            $this->assertSame(['within'], $options['possibleoperations']);
        }
        // The filters are shown: the id entry of the filter definition is what switches the filter panel on.
        $this->assertSame(1, $table->subcolumns['datafields']['id']['id_wb_checked']);

        if ($scope !== 'instanceanswers') {
            // In instance scope, the download columns are configured in the instance settings.
            $download = $scopeclass->return_cols_for_download(MOD_BOOKING_STATUSPARAM_BOOKED, $scopeid);
            $this->assertSame(self::TIMECOLUMNS, array_slice(array_keys($download), -3));
        }
    }

    /**
     * The columns show the values of the booking answer, through the life of two answers:
     *
     * - A user books directly: all three times are the moment of the booking.
     * - A second user (and a third one) lands on the waiting list: created and modified, but no booking date.
     * - The first user cancels, the second moves up: the creation date stays, the booking date is the
     *   moment of moving up, and so is the time modified. The cancelled answer keeps its creation and
     *   booking date, only its time modified changes.
     *
     * To tell "then" from "now", the times of the first steps are moved 1000 seconds into the past.
     *
     * @dataProvider scope_provider
     * @param string $scope
     */
    public function test_values_through_the_life_of_an_answer(string $scope): void {
        global $DB;

        $this->setAdminUser();
        [$settings, $course, $users] = $this->create_option_with_answers(1);
        [$first, $second, $third] = $users;
        $scopeid = $this->scopeid($scope, $settings, $course);
        $start = time();

        // The first user is booked, the others are on the waiting list.
        $booked = $this->rows($scope, $scopeid, MOD_BOOKING_STATUSPARAM_BOOKED);
        $waiting = $this->rows($scope, $scopeid, MOD_BOOKING_STATUSPARAM_WAITINGLIST);
        $this->assertSame([(int)$first->id], array_keys($booked));
        $this->assertEqualsCanonicalizing([(int)$second->id, (int)$third->id], array_keys($waiting));

        $this->assertGreaterThanOrEqual($start - 5, (int)$booked[$first->id]->timecreated);
        $this->assertSame((int)$booked[$first->id]->timecreated, (int)$booked[$first->id]->timebooked);
        $this->assertSame((int)$booked[$first->id]->timecreated, (int)$booked[$first->id]->timemodified);

        $this->assertGreaterThanOrEqual($start - 5, (int)$waiting[$second->id]->timecreated);
        $this->assertEmpty($waiting[$second->id]->timebooked, 'An answer on the waiting list has no booking date.');

        // All this happened 1000 seconds ago.
        $DB->execute(
            "UPDATE {booking_answers}
                SET timecreated = timecreated - 1000,
                    timemodified = timemodified - 1000,
                    timebooked = CASE WHEN timebooked > 0 THEN timebooked - 1000 ELSE timebooked END
              WHERE optionid = ?",
            [$settings->id]
        );
        $this->purge();
        $then = $DB->get_records('booking_answers', ['optionid' => $settings->id], '', 'userid, timecreated, timebooked');

        // The first user cancels, the second one moves up.
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $option->user_delete_response($first->id);
        $this->purge();
        $now = time();

        $booked = $this->rows($scope, $scopeid, MOD_BOOKING_STATUSPARAM_BOOKED);
        $deleted = $this->rows($scope, $scopeid, MOD_BOOKING_STATUSPARAM_DELETED);
        $this->assertSame([(int)$second->id], array_keys($booked), 'The user who waited longest moved up.');
        $this->assertSame([(int)$first->id], array_keys($deleted));

        // Moved up: created then, booked and modified now.
        $movedup = $booked[$second->id];
        $this->assertSame((int)$then[$second->id]->timecreated, (int)$movedup->timecreated);
        $this->assertGreaterThanOrEqual($start, (int)$movedup->timebooked);
        $this->assertLessThanOrEqual($now, (int)$movedup->timebooked);
        $this->assertGreaterThanOrEqual($start, (int)$movedup->timemodified);
        $this->assertGreaterThan((int)$movedup->timecreated, (int)$movedup->timebooked);

        // Cancelled: created and booked then, modified now.
        $cancelled = $deleted[$first->id];
        $this->assertSame((int)$then[$first->id]->timecreated, (int)$cancelled->timecreated);
        $this->assertSame((int)$then[$first->id]->timebooked, (int)$cancelled->timebooked);
        $this->assertGreaterThanOrEqual($start, (int)$cancelled->timemodified);

        // The table shows exactly what is stored for the booking answer.
        $stored = $DB->get_record('booking_answers', ['optionid' => $settings->id, 'userid' => $second->id]);
        foreach (self::TIMECOLUMNS as $column) {
            $this->assertSame((int)$stored->{$column}, (int)$movedup->{$column}, $column);
        }
    }

    /**
     * All three times are displayed in the timezone of the user, in the same format.
     * The time modified used the timezone of the server before.
     */
    public function test_times_are_displayed_in_the_timezone_of_the_user(): void {
        global $USER;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->setTimezone('Europe/Vienna');
        $USER->timezone = 'Asia/Tokyo';

        // 2026-11-24 10:00:00 UTC, which is 19:00 in Tokyo and 11:00 in Vienna.
        $time = 1795514400;
        $values = (object)['timecreated' => $time, 'timebooked' => $time, 'timemodified' => $time];
        $table = new manageusers_table('timezonetest');

        $expected = userdate($time);
        $this->assertSame(userdate($time, '', 'Asia/Tokyo'), $expected);
        $this->assertNotSame(userdate($time, '', 'Europe/Vienna'), $expected);
        $this->assertSame($expected, $table->col_timecreated($values));
        $this->assertSame($expected, $table->col_timebooked($values));
        $this->assertSame($expected, $table->col_timemodified($values));

        // Without a value, the cell stays empty (e.g. no booking date on the waiting list).
        $empty = (object)['timecreated' => 0, 'timebooked' => null, 'timemodified' => 0];
        $this->assertSame('', $table->col_timecreated($empty));
        $this->assertSame('', $table->col_timebooked($empty));
        $this->assertSame('', $table->col_timemodified($empty));
    }

    /**
     * Each date filter narrows the table down to the answers whose time lies in the period (both ends
     * included), independently of the other two times, and the filters can be combined.
     *
     * Three answers, each with its own day for every time:
     *            created   booked   modified
     * answer A   day 1     day 1    day 5
     * answer B   day 1     day 2    day 6
     * answer C   day 3     day 4    day 6
     *
     * @dataProvider scope_provider
     * @param string $scope
     */
    public function test_date_filters(string $scope): void {
        global $DB;

        $this->setAdminUser();
        [$settings, $course, $users] = $this->create_option_with_answers(3);
        $scopeid = $this->scopeid($scope, $settings, $course);

        $day = fn(int $number) => 1790000000 + $number * DAYSECS;
        $times = [
            'A' => ['timecreated' => $day(1), 'timebooked' => $day(1), 'timemodified' => $day(5)],
            'B' => ['timecreated' => $day(1), 'timebooked' => $day(2), 'timemodified' => $day(6)],
            'C' => ['timecreated' => $day(3), 'timebooked' => $day(4), 'timemodified' => $day(6)],
        ];
        $userids = [];
        foreach (array_keys($times) as $index => $name) {
            $userids[$name] = (int)$users[$index]->id;
            $answer = $DB->get_record('booking_answers', ['optionid' => $settings->id, 'userid' => $userids[$name]]);
            $DB->update_record('booking_answers', (object)(['id' => $answer->id] + $times[$name]));
        }
        $this->purge();

        // A period around a day: from one hour before to one hour after.
        $around = fn(int $number) => ['>=' => $day($number) - HOURSECS, '<=' => $day($number) + HOURSECS];
        $labels = scope_base_answers::time_columns();

        $cases = [
            'created on day 1' => [['timecreated' => $around(1)], ['A', 'B']],
            'created on day 3' => [['timecreated' => $around(3)], ['C']],
            'booked on day 1' => [['timebooked' => $around(1)], ['A']],
            'booked on day 2' => [['timebooked' => $around(2)], ['B']],
            'modified on day 6' => [['timemodified' => $around(6)], ['B', 'C']],
            'modified on day 1 (nobody)' => [['timemodified' => $around(1)], []],
            'both ends are included' => [['timecreated' => ['>=' => $day(1), '<=' => $day(3)]], ['A', 'B', 'C']],
            'just outside the period' => [['timecreated' => ['>=' => $day(1) + 1, '<=' => $day(3) - 1]], []],
            'created on day 1 and booked on day 2' => [
                ['timecreated' => $around(1), 'timebooked' => $around(2)],
                ['B'],
            ],
            'created on day 1 and modified on day 6' => [
                ['timecreated' => $around(1), 'timemodified' => $around(6)],
                ['B'],
            ],
            'all three filters' => [
                ['timecreated' => $around(3), 'timebooked' => $around(4), 'timemodified' => $around(6)],
                ['C'],
            ],
        ];

        foreach ($cases as $name => [$periods, $expectednames]) {
            // The filter as the browser sends it: {column: {name of the filter: {operator: timestamp}}}.
            $filter = [];
            foreach ($periods as $column => $period) {
                $filter[$column] = [$labels[$column] => $period];
            }
            $rows = $this->rows($scope, $scopeid, MOD_BOOKING_STATUSPARAM_BOOKED, json_encode($filter));

            $expected = array_map(fn($expectedname) => $userids[$expectedname], $expectednames);
            $actual = array_keys($rows);
            sort($expected);
            sort($actual);
            $this->assertSame($expected, $actual, $name);
        }
    }

    /**
     * Helper: the rows of the table of a scope, keyed by the id of the user.
     *
     * @param string $scope
     * @param int $scopeid
     * @param int $statusparam
     * @param string $filter the filter as json, as sent by the browser
     * @return stdClass[]
     */
    private function rows(string $scope, int $scopeid, int $statusparam, string $filter = ''): array {
        static $counter = 0;

        // The table copies the filter to $_POST when it applies it, so both have to be reset.
        $_POST = [];
        $_GET = empty($filter) ? [] : ['wbtfilter' => $filter];
        // Every query gets a table of its own, so nothing is taken from the caches of the table.
        $table = $this->build_table($scope, $scopeid, $statusparam, 'rows' . $counter++);
        $table->return_encoded_table();
        $table->setup();
        $table->query_db_cached(100, true);
        $_GET = [];
        $_POST = [];

        $this->assertSame('', $table->errormessage);
        $rows = [];
        foreach ($table->rawdata as $row) {
            $rows[(int)$row->userid] = $row;
        }
        return $rows;
    }

    /**
     * Helper: the table of a scope with its default columns.
     *
     * @param string $scope
     * @param int $scopeid
     * @param int $statusparam
     * @param string $prefix unique table name prefix
     * @return wunderbyte_table
     */
    private function build_table(string $scope, int $scopeid, int $statusparam, string $prefix = 'timecolumns'): wunderbyte_table {
        $scopeclass = (new booking_answers())->return_class_for_scope($scope);
        $columns = $scopeclass->return_cols_for_tables($statusparam, $scopeid);
        return $scopeclass->return_users_table(
            $scope,
            $scopeid,
            $statusparam,
            $prefix,
            array_keys($columns),
            array_values($columns)
        );
    }

    /**
     * Helper: the id a scope refers to.
     *
     * @param string $scope
     * @param \mod_booking\booking_option_settings $settings
     * @param stdClass $course
     * @return int
     */
    private function scopeid(string $scope, $settings, stdClass $course): int {
        return match ($scope) {
            'systemanswers' => 0,
            'courseanswers' => (int)$course->id,
            'instanceanswers' => (int)$settings->cmid,
        };
    }

    /**
     * Helper: forget everything that is cached about the booking answers.
     *
     * @return void
     */
    private function purge(): void {
        cache_helper::purge_by_event('setbackoptionsanswers');
        cache_helper::purge_by_event('setbackbookedusertable');
        singleton_service::destroy_instance();
    }

    /**
     * Helper: a booking option with three users who book it one after the other.
     * With one place, the first user is booked and the others are on the waiting list.
     *
     * @param int $maxanswers number of places
     * @return array{0: \mod_booking\booking_option_settings, 1: stdClass, 2: stdClass[]}
     */
    private function create_option_with_answers(int $maxanswers = 3): array {
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'name' => 'Time columns test booking',
            'course' => $course->id,
            'bookingmanager' => 'admin',
        ]);

        $record = new stdClass();
        $record->importing = 1;
        $record->bookingid = $booking->id;
        $record->text = 'Option with time columns';
        $record->useprice = 0;
        $record->maxanswers = $maxanswers;
        $record->maxoverbooking = 5;

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        $users = [];
        for ($i = 0; $i < 3; $i++) {
            $user = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
            $this->setUser($user);
            booking_bookit::bookit('option', $settings->id, $user->id);
            booking_bookit::bookit('option', $settings->id, $user->id);
            $users[] = $user;
        }
        $this->setAdminUser();
        $this->purge();

        return [$settings, $course, $users];
    }
}
