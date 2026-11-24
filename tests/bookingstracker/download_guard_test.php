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
 * Tests for the hint after a failed download of a Bookings tracker table.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use coding_exception;
use dml_read_exception;
use local_wunderbyte_table\local\exception\download_query_exception;
use local_wunderbyte_table\wunderbyte_table;
use mod_booking\local\bookingstracker\download_guard;
use moodle_exception;

/**
 * Which failures of a download count as "too much data", where the user is sent to, and what
 * happens to all other failures.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\bookingstracker\download_guard
 */
final class download_guard_test extends advanced_testcase {
    /**
     * Every table of report2.php leads back to its own page and view, with the hint parameter.
     *
     * @dataProvider return_url_provider
     * @param string $scope
     * @param int $scopeid
     * @param string|null $expected the expected local URL, null if there is no page to return to
     */
    public function test_return_url(string $scope, int $scopeid, ?string $expected): void {
        $url = download_guard::return_url($scope, $scopeid);
        $this->assertSame($expected, $url === null ? null : $url->out_as_local_url(false));
    }

    /**
     * Data provider for test_return_url.
     *
     * @return array
     */
    public static function return_url_provider(): array {
        $base = '/mod/booking/report2.php?';
        return [
            'system' => ['system', 0, $base . 'downloaderror=1'],
            'system, answers view' => ['systemanswers', 0, $base . 'viewtype=answers&downloaderror=1'],
            'course' => ['course', 5, $base . 'courseid=5&downloaderror=1'],
            'course, answers view' => ['courseanswers', 5, $base . 'courseid=5&viewtype=answers&downloaderror=1'],
            'instance' => ['instance', 7, $base . 'cmid=7&downloaderror=1'],
            'instance, answers view' => ['instanceanswers', 7, $base . 'cmid=7&viewtype=answers&downloaderror=1'],
            'option' => ['option', 9, $base . 'optionid=9&downloaderror=1'],
            'optiondate' => ['optiondate', 11, $base . 'optiondateid=11&downloaderror=1'],
            'instance without its id' => ['instanceanswers', 0, null],
            'table that is not on report2.php' => ['optionstoconfirm', 3, null],
        ];
    }

    /**
     * The number of rows is added to the page the user is taken back to, if it is known.
     */
    public function test_return_url_with_rows(): void {
        $url = download_guard::return_url('instanceanswers', 7, 150000);
        $this->assertSame(
            '/mod/booking/report2.php?cmid=7&viewtype=answers&downloaderror=1&downloadrows=150000',
            $url->out_as_local_url(false)
        );
    }

    /**
     * The hint names the number of rows, if it is known.
     */
    public function test_hint(): void {
        $this->assertSame(get_string('bookingstrackerdownloadtoolarge', 'mod_booking'), download_guard::hint());
        $this->assertSame(get_string('bookingstrackerdownloadtoolarge', 'mod_booking'), download_guard::hint(0));
        $this->assertStringContainsString('(150000)', download_guard::hint(150000));
    }

    /**
     * Only the read error of the query of the table can be caused by the amount of data,
     * not the read error of another query (e.g. one a column runs for a single row).
     */
    public function test_is_table_query_error(): void {
        $readerror = new dml_read_exception('canceling statement due to statement timeout');
        $this->assertTrue(download_guard::is_table_query_error(new download_query_exception($readerror)));
        $this->assertFalse(download_guard::is_table_query_error($readerror));
        $this->assertFalse(download_guard::is_table_query_error(new coding_exception('A column cannot be rendered.')));
    }

    /**
     * The amount of data is a plausible cause from 5000 rows on, and when the rows cannot be counted.
     */
    public function test_is_plausible_rowcount(): void {
        $this->assertSame(5000, download_guard::MINROWS);
        $this->assertFalse(download_guard::is_plausible_rowcount(0));
        $this->assertFalse(download_guard::is_plausible_rowcount(4999));
        $this->assertTrue(download_guard::is_plausible_rowcount(5000));
        $this->assertTrue(download_guard::is_plausible_rowcount(150000));
        $this->assertTrue(download_guard::is_plausible_rowcount(null));
    }

    /**
     * The rows of a table are counted with its sql; a table that cannot be counted has no number.
     */
    public function test_count_rows(): void {
        $this->resetAfterTest();
        $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_course();

        $this->assertSame(2, download_guard::count_rows($this->small_table()));
        $this->assertGreaterThanOrEqual(download_guard::MINROWS, download_guard::count_rows($this->big_table()));

        $broken = new wunderbyte_table('downloadguardbroken');
        $broken->set_sql('*', '{wbtable_does_not_exist}', '1=1');
        $this->assertNull(download_guard::count_rows($broken));
    }

    /**
     * Only PHP running out of memory or time counts as a fatal error caused by the amount of data.
     */
    public function test_is_size_related_fatal(): void {
        $memory = 'Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)';
        $this->assertTrue(download_guard::is_size_related_fatal(['type' => E_ERROR, 'message' => $memory]));
        $this->assertTrue(download_guard::is_size_related_fatal(
            ['type' => E_ERROR, 'message' => 'Maximum execution time of 30 seconds exceeded']
        ));
        // No error at all: the download ended normally.
        $this->assertFalse(download_guard::is_size_related_fatal(null));
        // Another fatal error.
        $this->assertFalse(download_guard::is_size_related_fatal(
            ['type' => E_ERROR, 'message' => 'Call to undefined function foo()']
        ));
        // A warning that happens to mention memory is not the end of the script.
        $this->assertFalse(download_guard::is_size_related_fatal(['type' => E_WARNING, 'message' => $memory]));
    }

    /**
     * An exception that is not the read error of the query of the table is thrown again unchanged,
     * even for a big table.
     */
    public function test_other_exceptions_are_thrown_again(): void {
        $this->resetAfterTest();
        $exceptions = [
            new coding_exception('A column cannot be rendered.'),
            new dml_read_exception('The query of a column failed.'),
        ];
        foreach ($exceptions as $exception) {
            try {
                download_guard::handle_exception($exception, 'optionstoconfirm', 3, $this->big_table());
                $this->fail('The exception was swallowed.');
            } catch (\Throwable $e) {
                $this->assertSame($exception, $e);
            }
        }
    }

    /**
     * The query of a small table failed: the amount of data is no plausible cause,
     * so the error is thrown again unchanged and keeps its regular error page.
     */
    public function test_failed_query_of_a_small_table_is_thrown_again(): void {
        $this->resetAfterTest();
        $this->getDataGenerator()->create_course();

        $exception = new download_query_exception(new dml_read_exception('syntax error'));
        try {
            download_guard::handle_exception($exception, 'optionstoconfirm', 3, $this->small_table());
            $this->fail('The exception was swallowed.');
        } catch (\Throwable $e) {
            $this->assertSame($exception, $e);
        }
    }

    /**
     * The query of a big table failed. A table that is not shown on report2.php has no page to return
     * to: the hint with the number of rows is shown on the error page, the cause is kept as debug info.
     */
    public function test_failed_query_of_a_big_table_shows_the_hint(): void {
        $this->resetAfterTest();
        $table = $this->big_table();
        $rows = download_guard::count_rows($table);

        // The cause is written to the PHP error log, which PHPUnit would print.
        $errorlog = ini_set('error_log', '/dev/null');
        try {
            $exception = new download_query_exception(new dml_read_exception('statement timeout'));
            download_guard::handle_exception($exception, 'optionstoconfirm', 3, $table);
            $this->fail('No exception was thrown.');
        } catch (moodle_exception $e) {
            $this->assertSame('bookingstrackerdownloadtoolargerows', $e->errorcode);
            $this->assertSame('mod_booking', $e->module);
            $this->assertStringContainsString("($rows)", $e->getMessage());
            $this->assertStringContainsString('statement timeout', $e->debuginfo);
        } finally {
            ini_set('error_log', $errorlog);
        }
    }

    /**
     * A table with the courses of the site (without the site course).
     *
     * @return wunderbyte_table
     */
    private function small_table(): wunderbyte_table {
        $table = new wunderbyte_table('downloadguardsmall');
        $table->set_sql('*', '{course}', 'id > :siteid', ['siteid' => SITEID]);
        return $table;
    }

    /**
     * A table with more rows than the threshold, without creating them: every capability
     * combined with every capability.
     *
     * @return wunderbyte_table
     */
    private function big_table(): wunderbyte_table {
        $table = new wunderbyte_table('downloadguardbig');
        $table->set_sql('*', '(SELECT c1.id FROM {capabilities} c1, {capabilities} c2) s1', '1=1');
        return $table;
    }
}
