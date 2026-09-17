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

namespace mod_booking;

use advanced_testcase;

/**
 * Tests for booking::apply_wherearray().
 *
 * The where fragments built here end up inside a subselect, so a malformed
 * fragment (for example an empty bracket) only surfaces as a database syntax
 * error. Every case is therefore also executed against the database - a pure
 * string comparison would not catch that class of defect.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\booking::apply_wherearray
 */
final class apply_wherearray_test extends advanced_testcase {
    /**
     * Runs the fragment through the database the same way production does.
     *
     * @param string $where
     * @param array $params
     * @return void
     */
    private function assert_sql_runs(string $where, array $params): void {
        global $DB;
        $sql = "SELECT COUNT(1)
                  FROM (SELECT bo.id FROM {booking_options} bo WHERE $where) s1";
        $count = $DB->get_field_sql($sql, $params);
        $this->assertIsNumeric($count, 'The generated where clause is not valid SQL: ' . $where);
    }

    /**
     * An empty array must not contribute anything at all.
     *
     * Before the fix this produced "AND (  )", which every supported database
     * rejects as a syntax error.
     *
     * @return void
     */
    public function test_empty_array_adds_no_condition(): void {
        $this->resetAfterTest();

        $where = 'invisible = 0 ';
        $params = [];
        $wherearray = ['sport' => []];
        booking::apply_wherearray($where, $wherearray, $params, 1);

        $this->assertDoesNotMatchRegularExpression('/AND\s*\(\s*\)/', $where);
        $this->assertSame('invisible = 0 ', $where);
        $this->assert_sql_runs($where, $params);
    }

    /**
     * Several empty arrays must not accumulate empty brackets either.
     *
     * @return void
     */
    public function test_several_empty_arrays(): void {
        $this->resetAfterTest();

        $where = 'invisible = 0 ';
        $params = [];
        $wherearray = ['sport' => [], 'sportsdivision' => [], 'botags' => []];
        booking::apply_wherearray($where, $wherearray, $params, 1);

        $this->assertDoesNotMatchRegularExpression('/AND\s*\(\s*\)/', $where);
        $this->assert_sql_runs($where, $params);
    }

    /**
     * A numeric array value produces a bracketed comparison.
     *
     * @return void
     */
    public function test_numeric_array(): void {
        $this->resetAfterTest();

        $where = 'invisible = 0 ';
        $params = [];
        $wherearray = ['bookingid' => [11]];
        booking::apply_wherearray($where, $wherearray, $params, 1);

        $this->assertMatchesRegularExpression('/AND\s*\(.*bookingid\s*=\s*11/', $where);
        $this->assert_sql_runs($where, $params);
    }

    /**
     * Several values of one key are combined with OR inside one bracket.
     *
     * @return void
     */
    public function test_multiple_values_are_or_combined(): void {
        $this->resetAfterTest();

        $where = 'invisible = 0 ';
        $params = [];
        $wherearray = ['bookingid' => [11, 12, 13]];
        booking::apply_wherearray($where, $wherearray, $params, 1);

        $this->assertSame(2, substr_count($where, ' OR '));
        $this->assert_sql_runs($where, $params);
    }

    /**
     * Text values are turned into LIKE comparisons with bound parameters.
     *
     * @return void
     */
    public function test_text_array_uses_bound_params(): void {
        $this->resetAfterTest();

        $where = 'invisible = 0 ';
        $params = [];
        $wherearray = ['text' => ['Tennis', 'Yoga']];
        booking::apply_wherearray($where, $wherearray, $params, 1);

        $this->assertCount(2, $params);
        $this->assert_sql_runs($where, $params);
    }

    /**
     * Numeric values may be given as integers and as numeric strings in the same array.
     *
     * Numeric values are written into the query as numbers, so they are only valid on numeric columns.
     * A number on a text column fails on PostgreSQL ("character varying = integer") and no caller
     * builds such an array.
     *
     * @return void
     */
    public function test_mixed_array(): void {
        $this->resetAfterTest();

        $where = 'invisible = 0 ';
        $params = [];
        $wherearray = ['bookingid' => [11, '12']];
        booking::apply_wherearray($where, $wherearray, $params, 1);

        $this->assertSame(1, substr_count($where, ' OR '));
        $this->assertSame([], $params);
        $this->assert_sql_runs($where, $params);
    }

    /**
     * Scalar values keep working unchanged.
     *
     * @return void
     */
    public function test_scalar_values(): void {
        $this->resetAfterTest();

        $where = 'invisible = 0 ';
        $params = [];
        $wherearray = ['bookingid' => 11, 'text' => 'Tennis'];
        booking::apply_wherearray($where, $wherearray, $params, 1);

        $this->assert_sql_runs($where, $params);
    }

    /**
     * An empty array next to a filled one must not break the filled condition.
     *
     * @return void
     */
    public function test_empty_and_filled_mixed(): void {
        $this->resetAfterTest();

        $where = 'invisible = 0 ';
        $params = [];
        $wherearray = ['sport' => [], 'bookingid' => [11]];
        booking::apply_wherearray($where, $wherearray, $params, 1);

        $this->assertDoesNotMatchRegularExpression('/AND\s*\(\s*\)/', $where);
        $this->assertMatchesRegularExpression('/bookingid\s*=\s*11/', $where);
        $this->assert_sql_runs($where, $params);
    }
}
